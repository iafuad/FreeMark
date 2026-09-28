<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

require_role('freelancer');
$user_id = current_user_id();
$profile_id = get_profile_id($conn, $user_id, 'freelancer');

// Handle Sending Message
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $receiver_id = (int)($_POST['receiver_id'] ?? 0);
    $content = trim($_POST['content'] ?? '');
    $msg_id = 0;

    if ($receiver_id > 0 && !empty($content)) {
        $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, content) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $user_id, $receiver_id, $content);
        $stmt->execute();
        $msg_id = (int)$stmt->insert_id;
    }

    if (isset($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => $msg_id > 0 ? 'success' : 'error',
            'message_id' => $msg_id,
            'content' => $content,
            'time' => date('g:i A')
        ]);
        exit;
    }

    header("Location: chat.php?with=" . $receiver_id);
    exit;
}

// Find conversation contacts (clients from contracts, proposals, invitations, or past messages)
$contacts_query = "
    SELECT DISTINCT u.id as user_id, u.full_name, cp.id as profile_id, cp.company_name,
        (SELECT content FROM messages 
         WHERE (sender_id = u.id AND receiver_id = ?) OR (sender_id = ? AND receiver_id = u.id) 
         ORDER BY created_at DESC LIMIT 1) as last_message,
        (SELECT created_at FROM messages 
         WHERE (sender_id = u.id AND receiver_id = ?) OR (sender_id = ? AND receiver_id = u.id) 
         ORDER BY created_at DESC LIMIT 1) as last_time,
        (SELECT COUNT(*) FROM messages 
         WHERE sender_id = u.id AND receiver_id = ? AND is_read = 0) as unread_count
    FROM users u
    JOIN client_profiles cp ON u.id = cp.user_id
    WHERE u.id IN (
        SELECT cp2.user_id FROM contracts c JOIN client_profiles cp2 ON c.client_id = cp2.id WHERE c.freelancer_id = ?
        UNION
        SELECT cp3.user_id FROM proposals p JOIN projects pr ON p.project_id = pr.id JOIN client_profiles cp3 ON pr.client_id = cp3.id WHERE p.freelancer_id = ?
        UNION
        SELECT cp4.user_id FROM job_invitations ji JOIN client_profiles cp4 ON ji.client_id = cp4.id WHERE ji.freelancer_id = ?
        UNION
        SELECT sender_id FROM messages WHERE receiver_id = ?
        UNION
        SELECT receiver_id FROM messages WHERE sender_id = ?
    )
    ORDER BY (last_time IS NULL) ASC, last_time DESC
";
$c_stmt = $conn->prepare($contacts_query);
$c_stmt->bind_param("iiiiiiiiii", $user_id, $user_id, $user_id, $user_id, $user_id, $profile_id, $profile_id, $profile_id, $user_id, $user_id);
$c_stmt->execute();
$contacts = $c_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// If no contact selected, default to the first contact or 0
$selected_with = (int)($_GET['with'] ?? ($contacts[0]['user_id'] ?? 0));

$active_contact = null;
$context = null;
$messages = [];

if ($selected_with > 0) {
    // Fetch contact details
    $u_stmt = $conn->prepare("SELECT u.id, u.full_name, cp.id as profile_id, cp.company_name FROM users u JOIN client_profiles cp ON u.id = cp.user_id WHERE u.id = ?");
    $u_stmt->bind_param("i", $selected_with);
    $u_stmt->execute();
    $active_contact = $u_stmt->get_result()->fetch_assoc();

    if ($active_contact) {
        $client_pid = (int)($active_contact['profile_id'] ?? 0);

        // Fetch context: active contract or recent proposal
        $ctx_stmt = $conn->prepare("
            SELECT c.id as contract_id, p.id as project_id, p.title as project_title, 'Contract' as context_type
            FROM contracts c
            JOIN projects p ON c.project_id = p.id
            WHERE c.freelancer_id = ? AND c.client_id = ?
            ORDER BY c.created_at DESC LIMIT 1
        ");
        $ctx_stmt->bind_param("ii", $profile_id, $client_pid);
        $ctx_stmt->execute();
        $context = $ctx_stmt->get_result()->fetch_assoc();

        if (!$context) {
            $prop_stmt = $conn->prepare("
                SELECT pr.id as proposal_id, p.id as project_id, p.title as project_title, 'Proposal' as context_type
                FROM proposals pr
                JOIN projects p ON pr.project_id = p.id
                WHERE pr.freelancer_id = ? AND p.client_id = ?
                ORDER BY pr.created_at DESC LIMIT 1
            ");
            $prop_stmt->bind_param("ii", $profile_id, $client_pid);
            $prop_stmt->execute();
            $context = $prop_stmt->get_result()->fetch_assoc();
        }

        // Fetch conversation messages
        $m_stmt = $conn->prepare("SELECT * FROM messages 
            WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?) 
            ORDER BY created_at ASC");
        $m_stmt->bind_param("iiii", $user_id, $selected_with, $selected_with, $user_id);
        $m_stmt->execute();
        $messages = $m_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Mark messages as read
        $read_stmt = $conn->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ?");
        $read_stmt->bind_param("ii", $selected_with, $user_id);
        $read_stmt->execute();

        // Ensure active contact appears in sidebar
        $found = false;
        foreach ($contacts as &$c) {
            if ($c['user_id'] == $active_contact['id']) {
                $found = true;
                $c['unread_count'] = 0;
                break;
            }
        }
        unset($c);

        if (!$found) {
            array_unshift($contacts, [
                'user_id' => $active_contact['id'],
                'full_name' => $active_contact['full_name'],
                'profile_id' => $active_contact['profile_id'],
                'company_name' => $active_contact['company_name'],
                'last_message' => 'New conversation',
                'last_time' => null,
                'unread_count' => 0
            ]);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - FreeMark</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/freelancer/layout.css">
    <link rel="stylesheet" href="../css/freelancer/components.css">
    <link rel="stylesheet" href="../css/freelancer/inline-helpers.css">
    <link rel="stylesheet" href="../css/chat.css">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
    <aside class="freelancer-sidebar">
        <div class="sidebar-header">
            <a href="../index.php" class="logo">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>
                FreeMark
            </a>
        </div>
        <nav class="sidebar-nav">
            <ul>
                <li><a href="dashboard.php"><i data-lucide="layout-dashboard"></i> Overview</a></li>
                <li><a href="profile.php"><i data-lucide="user"></i> My Profile</a></li>
                <li><a href="jobs.php"><i data-lucide="briefcase"></i> Find Jobs</a></li>
                <li><a href="tests.php"><i data-lucide="check-square"></i> Skill Tests</a></li>
                <li><a href="work.php"><i data-lucide="upload-cloud"></i> My Contracts</a></li>
                <li><a href="chat.php" class="active"><i data-lucide="message-square"></i> Messages</a></li>
            </ul>
        </nav>
        <div class="sidebar-footer">
            <a href="../logout.php" class="link-unstyled">
                <i data-lucide="log-out"></i> Logout
            </a>
        </div>
    </aside>

    <main class="freelancer-main">
        <header class="freelancer-header">
            <div class="header-title">
                <h2>Messages</h2>
                <p>Direct communication with your clients</p>
            </div>
        </header>

        <div class="freelancer-content">
            <div class="chat-layout <?= $active_contact ? 'conversation-open' : '' ?>">
                <!-- Sidebar Contacts -->
                <div class="chat-sidebar">
                    <div class="chat-search-box">
                        <i data-lucide="search" class="chat-search-icon"></i>
                        <input type="text" id="chat-contact-search" placeholder="Search conversations..." autocomplete="off">
                    </div>
                    <div class="chat-contacts-list" id="chat-contacts-list">
                        <?php if (empty($contacts)): ?>
                            <div style="padding: 30px 20px; text-align: center; color: var(--text-secondary); font-size: 0.88rem; line-height: 1.5;">
                                No conversations yet.<br>Apply to jobs or accept invitations to chat with clients.
                            </div>
                        <?php else: ?>
                            <?php foreach ($contacts as $c): ?>
                                <?php 
                                    $display_name = $c['company_name'] ?: $c['full_name'];
                                    $initials = strtoupper(substr($display_name, 0, 2));
                                ?>
                                <a href="chat.php?with=<?= $c['user_id'] ?>" 
                                   class="chat-contact <?= ($selected_with == $c['user_id']) ? 'active' : '' ?>"
                                   data-name="<?= htmlspecialchars(strtolower($display_name)) ?>"
                                   data-preview="<?= htmlspecialchars(strtolower($c['last_message'] ?? '')) ?>">
                                    <div class="chat-avatar purple">
                                        <?= $initials ?>
                                    </div>
                                    <div class="chat-contact-info">
                                        <div class="chat-contact-top">
                                            <h4 class="chat-contact-name"><?= htmlspecialchars($display_name) ?></h4>
                                            <span class="chat-contact-time"><?= $c['last_time'] ? time_ago($c['last_time']) : '' ?></span>
                                        </div>
                                        <div class="chat-contact-bottom">
                                            <p class="chat-contact-preview"><?= htmlspecialchars($c['last_message'] ?: 'Click to start conversation') ?></p>
                                            <?php if (!empty($c['unread_count']) && $c['unread_count'] > 0 && $selected_with != $c['user_id']): ?>
                                                <span class="chat-unread-badge"><?= (int)$c['unread_count'] ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Main Chat Window -->
                <div class="chat-main">
                    <?php if ($active_contact): ?>
                        <?php 
                            $c_display_name = $active_contact['company_name'] ?: $active_contact['full_name'];
                            $c_initials = strtoupper(substr($c_display_name, 0, 2));
                        ?>
                        <div class="chat-header">
                            <div class="chat-header-user">
                                <button type="button" class="chat-back-btn" id="chat-back-btn" aria-label="Back to conversations">
                                    <i data-lucide="arrow-left"></i>
                                </button>
                                <div class="chat-avatar purple">
                                    <?= $c_initials ?>
                                </div>
                                <div class="chat-header-meta">
                                    <h3>
                                        <?= htmlspecialchars($c_display_name) ?>
                                        <?php if (!empty($active_contact['profile_id'])): ?>
                                            <a href="../guest/client-profile.php?id=<?= $active_contact['profile_id'] ?>" target="_blank" title="View Full Company Profile" style="color: var(--text-secondary); display: inline-flex; align-items: center;">
                                                <i data-lucide="external-link" style="width: 15px; height: 15px;"></i>
                                            </a>
                                        <?php endif; ?>
                                    </h3>
                                    <p class="chat-header-subtitle">
                                        Client Employer <?= (!empty($active_contact['company_name']) && $active_contact['company_name'] !== $active_contact['full_name']) ? '• ' . htmlspecialchars($active_contact['full_name']) : '' ?>
                                    </p>
                                </div>
                            </div>
                            <div class="chat-header-actions">
                                <?php if ($context): ?>
                                    <?php 
                                        $ctx_url = ($context['context_type'] === 'Contract') ? 'work.php' : 'jobs.php';
                                    ?>
                                    <a href="<?= $ctx_url ?>" class="chat-context-badge" title="<?= htmlspecialchars($context['project_title']) ?> (<?= htmlspecialchars($context['context_type']) ?>)">
                                        <i data-lucide="briefcase" style="width: 14px; height: 14px;"></i>
                                        <span><?= htmlspecialchars($context['project_title']) ?> (<?= htmlspecialchars($context['context_type']) ?>)</span>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="chat-messages" id="chat-messages">
                            <?php if (empty($messages)): ?>
                                <div class="chat-empty-state">
                                    <i data-lucide="message-square"></i>
                                    <h3>Start Conversation</h3>
                                    <p>Send a message to <?= htmlspecialchars($c_display_name) ?> to discuss project timelines, milestones, or technical details.</p>
                                </div>
                            <?php else: ?>
                                <?php
                                $last_date = '';
                                foreach ($messages as $msg):
                                    $msg_date = date('Y-m-d', strtotime($msg['created_at']));
                                    if ($msg_date !== $last_date) {
                                        $last_date = $msg_date;
                                        $today = date('Y-m-d');
                                        $yesterday = date('Y-m-d', strtotime('-1 day'));
                                        if ($msg_date === $today) {
                                            $date_label = 'Today';
                                        } elseif ($msg_date === $yesterday) {
                                            $date_label = 'Yesterday';
                                        } else {
                                            $date_label = date('M j, Y', strtotime($msg['created_at']));
                                        }
                                        echo '<div class="chat-date-divider"><span>' . htmlspecialchars($date_label) . '</span></div>';
                                    }
                                    $is_me = ($msg['sender_id'] == $user_id);
                                ?>
                                    <div class="chat-bubble <?= $is_me ? 'sent' : 'received' ?>">
                                        <?= nl2br(htmlspecialchars($msg['content'])) ?>
                                        <div class="chat-msg-time">
                                            <?= date('g:i A', strtotime($msg['created_at'])) ?>
                                            <?php if ($is_me): ?>
                                                <i data-lucide="<?= $msg['is_read'] ? 'check-check' : 'check' ?>" style="width: 12px; height: 12px; display: inline;"></i>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div class="chat-composer">
                            <form method="POST" action="chat.php" id="chat-form">
                                <input type="hidden" name="receiver_id" value="<?= $selected_with ?>">
                                <input type="text" name="content" id="chat-input" placeholder="Type a message to <?= htmlspecialchars($c_display_name) ?>..." required autofocus autocomplete="off">
                                <button type="submit" class="btn btn-primary">
                                    <i data-lucide="send"></i>
                                </button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="chat-empty-state">
                            <i data-lucide="messages-square"></i>
                            <h3>Select a Conversation</h3>
                            <p>Choose an employer conversation from the left to read and send messages.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script src="../js/chat.js"></script>
    <script>
        lucide.createIcons();
    </script>
</body>
</html>
