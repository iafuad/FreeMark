<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

require_role('client');
$user_id = current_user_id();
$client_id = get_profile_id($conn, $user_id, 'client');

// Handle Sending Message
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $receiver_id = (int)($_POST['receiver_id'] ?? 0);
    $content = trim($_POST['content'] ?? '');

    if ($receiver_id > 0 && !empty($content)) {
        $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, content) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $user_id, $receiver_id, $content);
        $stmt->execute();
    }
    header("Location: chat.php?with=" . $receiver_id);
    exit;
}

// Find conversation contacts (freelancers from contracts, proposals, invitations, or past messages)
$contacts_query = "
    SELECT DISTINCT u.id as user_id, u.full_name, fp.title as freelancer_title,
        (SELECT content FROM messages 
         WHERE (sender_id = u.id AND receiver_id = ?) OR (sender_id = ? AND receiver_id = u.id) 
         ORDER BY created_at DESC LIMIT 1) as last_message,
        (SELECT created_at FROM messages 
         WHERE (sender_id = u.id AND receiver_id = ?) OR (sender_id = ? AND receiver_id = u.id) 
         ORDER BY created_at DESC LIMIT 1) as last_time
    FROM users u
    JOIN freelancer_profiles fp ON u.id = fp.user_id
    WHERE u.id IN (
        SELECT fp2.user_id FROM contracts c JOIN freelancer_profiles fp2 ON c.freelancer_id = fp2.id WHERE c.client_id = ?
        UNION
        SELECT fp3.user_id FROM proposals p JOIN projects pr ON p.project_id = pr.id JOIN freelancer_profiles fp3 ON p.freelancer_id = fp3.id WHERE pr.client_id = ?
        UNION
        SELECT fp4.user_id FROM job_invitations ji JOIN freelancer_profiles fp4 ON ji.freelancer_id = fp4.id WHERE ji.client_id = ?
        UNION
        SELECT sender_id FROM messages WHERE receiver_id = ?
        UNION
        SELECT receiver_id FROM messages WHERE sender_id = ?
    )
";
$c_stmt = $conn->prepare($contacts_query);
$c_stmt->bind_param("iiiiiiiii", $user_id, $user_id, $user_id, $user_id, $client_id, $client_id, $client_id, $user_id, $user_id);
$c_stmt->execute();
$contacts = $c_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Selected contact
$selected_with = (int)($_GET['with'] ?? ($contacts[0]['user_id'] ?? 0));

$active_contact = null;
$messages = [];

if ($selected_with > 0) {
    $u_stmt = $conn->prepare("SELECT u.id, u.full_name, fp.title as freelancer_title FROM users u JOIN freelancer_profiles fp ON u.id = fp.user_id WHERE u.id = ?");
    $u_stmt->bind_param("i", $selected_with);
    $u_stmt->execute();
    $active_contact = $u_stmt->get_result()->fetch_assoc();

    $m_stmt = $conn->prepare("SELECT * FROM messages 
        WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?) 
        ORDER BY created_at ASC");
    $m_stmt->bind_param("iiii", $user_id, $selected_with, $selected_with, $user_id);
    $m_stmt->execute();
    $messages = $m_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Mark as read
    $read_stmt = $conn->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ?");
    $read_stmt->bind_param("ii", $selected_with, $user_id);
    $read_stmt->execute();

    // Ensure active contact appears in sidebar
    if ($active_contact) {
        $found = false;
        foreach ($contacts as $c) {
            if ($c['user_id'] == $active_contact['id']) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            array_unshift($contacts, [
                'user_id' => $active_contact['id'],
                'full_name' => $active_contact['full_name'],
                'freelancer_title' => $active_contact['freelancer_title'],
                'last_message' => 'New conversation',
                'last_time' => null
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
    <link rel="stylesheet" href="../css/client/layout.css">
    <link rel="stylesheet" href="../css/client/components.css">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        .chat-main { display: flex; flex-direction: column; height: 550px; }
        .chat-messages { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 12px; }
        .message { max-width: 70%; padding: 12px 16px; border-radius: 12px; font-size: 0.95rem; line-height: 1.4; }
        .message.sent { align-self: flex-end; background: var(--accent, #6366f1); color: white; border-bottom-right-radius: 2px; }
        .message.received { align-self: flex-start; background: rgba(255,255,255,0.06); border: 1px solid var(--border-color); color: var(--text-primary); border-bottom-left-radius: 2px; }
        .msg-time { font-size: 0.7rem; opacity: 0.7; margin-top: 4px; text-align: right; }
        .chat-input form { display: flex; gap: 10px; padding: 15px 20px; border-top: 1px solid var(--border-color); }
        .chat-input input { flex: 1; padding: 10px 15px; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-primary); color: var(--text-primary); }
    </style>
</head>
<body>
    <aside class="client-sidebar">
        <div class="sidebar-header">
            <a href="../index.php" class="logo">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>
                FreeMark
            </a>
        </div>
        <nav class="sidebar-nav">
            <ul>
                <li><a href="dashboard.php"><i data-lucide="layout-dashboard"></i> Overview</a></li>
                <li><a href="profile.php"><i data-lucide="building"></i> Company Profile</a></li>
                <li><a href="post-project.php"><i data-lucide="plus-circle"></i> Post Project</a></li>
                <li><a href="freelancers.php"><i data-lucide="users"></i> Freelancers</a></li>
                <li><a href="proposals.php"><i data-lucide="file-text"></i> Proposals</a></li>
                <li><a href="work-approval.php"><i data-lucide="check-square"></i> Work & Ratings</a></li>
                <li><a href="chat.php" class="active"><i data-lucide="message-square"></i> Messages</a></li>
            </ul>
        </nav>
        <div class="sidebar-footer">
            <a href="../logout.php" style="color: var(--text-secondary); text-decoration: none; display: flex; align-items: center; gap: 10px;">
                <i data-lucide="log-out"></i> Logout
            </a>
        </div>
    </aside>

    <main class="client-main">
        <header class="client-header">
            <div class="header-title">
                <h2>Messages</h2>
                <p>Direct communication with your applicants and hired freelancers</p>
            </div>
        </header>

        <div class="client-content">
            <div class="chat-layout">
                <div class="chat-sidebar">
                    <?php if (empty($contacts)): ?>
                        <div style="padding: 20px; text-align: center; color: var(--text-secondary); font-size: 0.9rem;">
                            No message conversations yet. Review proposals or invite candidates to chat.
                        </div>
                    <?php else: ?>
                        <?php foreach ($contacts as $c): ?>
                            <a href="chat.php?with=<?= $c['user_id'] ?>" class="chat-contact <?= ($selected_with == $c['user_id']) ? 'active' : '' ?>" style="text-decoration: none; color: inherit; display: flex; align-items: center; gap: 12px; padding: 15px; border-bottom: 1px solid var(--border-color);">
                                <div class="chat-avatar" style="width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; background: rgba(99, 102, 241, 0.2); color: #818cf8;">
                                    <?= strtoupper(substr($c['full_name'], 0, 2)) ?>
                                </div>
                                <div style="flex: 1; overflow: hidden;">
                                    <h4 style="margin: 0; font-size: 0.95rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                        <?= htmlspecialchars($c['full_name']) ?>
                                    </h4>
                                    <p style="margin: 3px 0 0 0; font-size: 0.8rem; color: var(--text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                        <?= htmlspecialchars($c['last_message'] ?: ($c['freelancer_title'] ?? 'Click to chat')) ?>
                                    </p>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <div class="chat-main">
                    <?php if ($active_contact): ?>
                        <div class="chat-header" style="padding: 15px 20px; border-bottom: 1px solid var(--border-color); font-weight: 600; font-size: 1.05rem;">
                            <?= htmlspecialchars($active_contact['full_name']) ?> <span style="font-size: 0.85rem; font-weight: normal; color: var(--text-secondary);">• <?= htmlspecialchars($active_contact['freelancer_title'] ?? 'Freelancer') ?></span>
                        </div>
                        <div class="chat-messages" id="chat-messages">
                            <?php if (empty($messages)): ?>
                                <p style="text-align: center; color: var(--text-secondary); margin: auto;">No messages exchanged yet. Send a greeting to start chatting!</p>
                            <?php else: ?>
                                <?php foreach ($messages as $msg): ?>
                                    <div class="message <?= ($msg['sender_id'] == $user_id) ? 'sent' : 'received' ?>">
                                        <?= nl2br(htmlspecialchars($msg['content'])) ?>
                                        <div class="msg-time"><?= date('g:i A', strtotime($msg['created_at'])) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="chat-input">
                            <form method="POST" action="chat.php">
                                <input type="hidden" name="receiver_id" value="<?= $selected_with ?>">
                                <input type="text" name="content" placeholder="Type your message here..." required autofocus autocomplete="off">
                                <button type="submit" class="btn btn-primary" style="padding: 10px 18px;"><i data-lucide="send" class="icon-send"></i></button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div style="display: flex; justify-content: center; align-items: center; height: 100%; color: var(--text-secondary);">
                            Select a freelancer conversation from the left to start messaging.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
    <script>
        lucide.createIcons();
        const chatBox = document.getElementById('chat-messages');
        if (chatBox) {
            chatBox.scrollTop = chatBox.scrollHeight;
        }
    </script>
</body>
</html>
