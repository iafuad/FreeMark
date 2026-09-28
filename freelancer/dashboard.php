<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

require_role('freelancer');

$user_id = current_user_id();
$profile_id = get_profile_id($conn, $user_id, 'freelancer');

// Earnings
$q_earnings = $conn->query("SELECT COALESCE(SUM(paid_to_date), 0) as total FROM contracts WHERE freelancer_id = '$profile_id'");
$total_earnings = ($q_earnings && $row = $q_earnings->fetch_assoc()) ? (float)$row['total'] : 0.00;

// Active Contracts
$q_contracts = $conn->query("SELECT COUNT(*) as count FROM contracts WHERE freelancer_id = '$profile_id' AND status = 'active'");
$active_contracts = ($q_contracts && $row = $q_contracts->fetch_assoc()) ? (int)$row['count'] : 0;

// Rating
$q_rating = $conn->query("SELECT AVG(stars) as avg_stars, COUNT(*) as rev_count FROM reviews WHERE freelancer_id = '$profile_id'");
$rev_data = $q_rating ? $q_rating->fetch_assoc() : [];
$avg_rating = !empty($rev_data['avg_stars']) ? number_format((float)$rev_data['avg_stars'], 1) : '5.0';

// Tests passed (distinct verified quizzes)
$q_tests = $conn->query("SELECT COUNT(DISTINCT quiz_id) as passed_count FROM test_results WHERE freelancer_id = '$profile_id' AND passed = 1");
$tests_passed = ($q_tests && $row = $q_tests->fetch_assoc()) ? (int)$row['passed_count'] : 0;

// Handle "Mark all read"
if (isset($_GET['mark_read'])) {
    $stmt = $conn->prepare("UPDATE messages SET is_read = 1 WHERE receiver_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    header("Location: dashboard.php");
    exit;
}

// 1. Pending invitations
$invitation_notifs = [];
$inv_stmt = $conn->prepare("SELECT ji.*, p.title as project_title, cp.company_name, u.full_name as client_name 
    FROM job_invitations ji 
    LEFT JOIN projects p ON ji.project_id = p.id
    JOIN client_profiles cp ON ji.client_id = cp.id 
    JOIN users u ON cp.user_id = u.id 
    WHERE ji.freelancer_id = ? AND ji.status = 'pending' 
    ORDER BY ji.created_at DESC");
$inv_stmt->bind_param("i", $profile_id);
$inv_stmt->execute();
$inv_res = $inv_stmt->get_result();
while ($inv = $inv_res->fetch_assoc()) {
    $client_label = $inv['company_name'] ?: $inv['client_name'];
    $snippet = $inv['message'] ? ' — <em>"' . htmlspecialchars(strlen($inv['message']) > 90 ? substr($inv['message'], 0, 87) . '...' : $inv['message']) . '"</em>' : '';
    $invitation_notifs[] = [
        'category' => 'invitations',
        'tag' => 'Project Offer',
        'tag_class' => 'tag-invitations',
        'icon' => 'mail',
        'icon_box' => 'icon-yellow',
        'title' => 'New Offer from ' . htmlspecialchars($client_label),
        'desc' => 'Project: <strong>' . htmlspecialchars($inv['project_title'] ?? 'Direct Job') . '</strong>' . $snippet,
        'meta' => time_ago($inv['created_at']) . ' • <span class="text-accent">Action Required</span>',
        'action_url' => 'invitation.php?id=' . $inv['id'],
        'action_text' => 'Review Offer',
        'action_class' => 'btn btn-primary btn-sm',
        'unread' => true,
        'timestamp' => $inv['created_at'] ? strtotime($inv['created_at']) : time()
    ];
}

// 2. Active milestones needing submission or revision
$milestone_notifs = [];
$m_stmt = $conn->prepare("SELECT m.*, p.title as project_title, cp.company_name, u.full_name as client_name
    FROM milestones m
    JOIN contracts c ON m.contract_id = c.id
    JOIN projects p ON c.project_id = p.id
    JOIN client_profiles cp ON c.client_id = cp.id
    JOIN users u ON cp.user_id = u.id
    WHERE c.freelancer_id = ? AND m.status IN ('in_progress', 'changes_requested')
    ORDER BY m.created_at ASC");
$m_stmt->bind_param("i", $profile_id);
$m_stmt->execute();
$m_res = $m_stmt->get_result();
while ($am = $m_res->fetch_assoc()) {
    $is_changes = ($am['status'] === 'changes_requested');
    $milestone_notifs[] = [
        'category' => 'milestones',
        'tag' => $is_changes ? 'Changes Requested' : 'Milestone In Progress',
        'tag_class' => $is_changes ? 'tag-fraud' : 'tag-contracts',
        'icon' => $is_changes ? 'alert-circle' : 'upload-cloud',
        'icon_box' => $is_changes ? 'icon-yellow' : 'icon-blue',
        'title' => htmlspecialchars($am['title']) . ' (' . format_currency($am['amount']) . ')',
        'desc' => 'Project: <strong>' . htmlspecialchars($am['project_title']) . '</strong> • Client: ' . htmlspecialchars($am['company_name'] ?: $am['client_name']),
        'meta' => time_ago($am['created_at']) . ' • Status: ' . get_status_badge($am['status']),
        'action_url' => 'submit-milestone.php?id=' . $am['id'],
        'action_text' => $is_changes ? 'Revise Work' : 'Submit Work',
        'action_class' => 'btn btn-primary btn-sm',
        'unread' => true,
        'timestamp' => $am['created_at'] ? strtotime($am['created_at']) : time()
    ];
}

// Approved / Paid milestones
$paid_stmt = $conn->prepare("SELECT m.*, p.title as project_title, cp.company_name, u.full_name as client_name
    FROM milestones m
    JOIN contracts c ON m.contract_id = c.id
    JOIN projects p ON c.project_id = p.id
    JOIN client_profiles cp ON c.client_id = cp.id
    JOIN users u ON cp.user_id = u.id
    WHERE c.freelancer_id = ? AND m.status = 'approved'
    ORDER BY m.id DESC LIMIT 3");
$paid_stmt->bind_param("i", $profile_id);
$paid_stmt->execute();
$paid_res = $paid_stmt->get_result();
while ($pm = $paid_res->fetch_assoc()) {
    $milestone_notifs[] = [
        'category' => 'milestones',
        'tag' => 'Payment Cleared',
        'tag_class' => 'tag-tests',
        'icon' => 'dollar-sign',
        'icon_box' => 'icon-green',
        'title' => format_currency($pm['amount']) . ' Released for ' . htmlspecialchars($pm['title']),
        'desc' => 'Client <strong>' . htmlspecialchars($pm['company_name'] ?: $pm['client_name']) . '</strong> approved deliverable for <em>' . htmlspecialchars($pm['project_title']) . '</em>.',
        'meta' => 'Completed',
        'action_url' => 'work.php',
        'action_text' => 'View Work',
        'action_class' => 'details-link',
        'unread' => false,
        'timestamp' => 0
    ];
}

// 3. Unread messages
$message_notifs = [];
$msg_stmt = $conn->prepare("SELECT m.id as message_id, m.sender_id, m.content, m.created_at, u.full_name as sender_name
    FROM messages m
    JOIN users u ON m.sender_id = u.id
    WHERE m.receiver_id = ? AND m.is_read = 0
    ORDER BY m.created_at DESC
    LIMIT 5");
$msg_stmt->bind_param("i", $user_id);
$msg_stmt->execute();
$msg_res = $msg_stmt->get_result();
while ($row = $msg_res->fetch_assoc()) {
    $snippet = strlen($row['content']) > 80 ? substr($row['content'], 0, 77) . '...' : $row['content'];
    $message_notifs[] = [
        'category' => 'messages',
        'tag' => 'New Message',
        'tag_class' => 'tag-messages',
        'icon' => 'message-square',
        'icon_box' => 'icon-purple',
        'title' => 'New Message from ' . htmlspecialchars($row['sender_name']),
        'desc' => '<em>"' . htmlspecialchars($snippet) . '"</em>',
        'meta' => time_ago($row['created_at']) . ' • <span class="text-accent">Unread</span>',
        'action_url' => 'chat.php?with=' . $row['sender_id'],
        'action_text' => 'Reply',
        'action_class' => 'btn btn-outline btn-sm',
        'unread' => true,
        'timestamp' => $row['created_at'] ? strtotime($row['created_at']) : time()
    ];
}

// 4. Available tests to take
$test_notifs = [];
$test_stmt = $conn->prepare("SELECT * FROM quizzes WHERE id NOT IN (SELECT quiz_id FROM test_results WHERE freelancer_id = ? AND passed = 1) LIMIT 2");
$test_stmt->bind_param("i", $profile_id);
$test_stmt->execute();
$test_res = $test_stmt->get_result();
while ($st = $test_res->fetch_assoc()) {
    $test_notifs[] = [
        'category' => 'tests',
        'tag' => 'Skill Boost',
        'tag_class' => 'tag-messages',
        'icon' => 'award',
        'icon_box' => 'icon-purple',
        'title' => 'Verify Skill: ' . htmlspecialchars($st['title']),
        'desc' => htmlspecialchars($st['description'] ?: 'Pass this benchmark assessment to earn a verified skill badge on your profile.'),
        'meta' => 'Passing requirement: ' . (int)$st['passing_score'] . '%',
        'action_url' => 'take-quiz.php?id=' . $st['id'],
        'action_text' => 'Take Test',
        'action_class' => 'btn btn-outline btn-sm',
        'unread' => false,
        'timestamp' => 0
    ];
}

// Merge & Sort: unread items first, then by timestamp
$all_notifs = array_merge($invitation_notifs, $milestone_notifs, $message_notifs, $test_notifs);
usort($all_notifs, function($a, $b) {
    if ($a['unread'] !== $b['unread']) {
        return $a['unread'] ? -1 : 1;
    }
    return $b['timestamp'] <=> $a['timestamp'];
});

$action_count = 0;
foreach ($all_notifs as $n) {
    if ($n['unread']) $action_count++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Freelancer Dashboard - FreeMark</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/freelancer/layout.css">
    <link rel="stylesheet" href="../css/freelancer/components.css">
    <link rel="stylesheet" href="../css/freelancer/inline-helpers.css">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
    <aside class="freelancer-sidebar">
        <div class="sidebar-header">
            <a href="../index.php" class="logo">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>
                </svg>
                FreeMark
            </a>
        </div>
        <nav class="sidebar-nav">
            <ul>
                <li><a href="dashboard.php" class="active"><i data-lucide="layout-dashboard"></i> Overview</a></li>
                <li><a href="profile.php"><i data-lucide="user"></i> My Profile</a></li>
                <li><a href="jobs.php"><i data-lucide="briefcase"></i> Find Jobs</a></li>
                <li><a href="tests.php"><i data-lucide="check-square"></i> Skill Tests</a></li>
                <li><a href="work.php"><i data-lucide="upload-cloud"></i> My Contracts</a></li>
                <li><a href="chat.php"><i data-lucide="message-square"></i> Messages</a></li>
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
                <h2>Welcome back, <?= htmlspecialchars(current_user_name() ?? 'Freelancer') ?></h2>
                <p>Here is what's happening with your projects today.</p>
            </div>
            <a href="jobs.php" class="btn btn-primary">Find New Jobs</a>
        </header>

        <div class="freelancer-content">
            <!-- Stats Grid -->
            <div class="sleek-stats-grid">
                <div class="sleek-stat-card">
                    <div class="sleek-stat-header">
                        <span class="sleek-stat-title">Total Earnings</span>
                        <div class="sleek-stat-icon icon-yellow"><i data-lucide="dollar-sign" class="icon-base"></i></div>
                    </div>
                    <div class="sleek-stat-value"><?= format_currency($total_earnings) ?></div>
                    <div class="sleek-stat-trend trend-up"><i data-lucide="trending-up" class="icon-sm"></i> Paid out</div>
                </div>
                
                <div class="sleek-stat-card">
                    <div class="sleek-stat-header">
                        <span class="sleek-stat-title">Active Contracts</span>
                        <div class="sleek-stat-icon icon-blue"><i data-lucide="briefcase" class="icon-base"></i></div>
                    </div>
                    <div class="sleek-stat-value"><?= $active_contracts ?></div>
                    <div class="sleek-stat-trend trend-neutral"><i data-lucide="minus" class="icon-sm"></i> In progress</div>
                </div>
                
                <div class="sleek-stat-card">
                    <div class="sleek-stat-header">
                        <span class="sleek-stat-title">Client Rating</span>
                        <div class="sleek-stat-icon icon-green"><i data-lucide="star" class="icon-base"></i></div>
                    </div>
                    <div class="sleek-stat-value"><?= $avg_rating ?> ★</div>
                    <div class="sleek-stat-trend trend-up"><i data-lucide="check-circle" class="icon-sm"></i> Top Talent</div>
                </div>

                <div class="sleek-stat-card">
                    <div class="sleek-stat-header">
                        <span class="sleek-stat-title">Verified Badges</span>
                        <div class="sleek-stat-icon icon-purple"><i data-lucide="award" class="icon-base"></i></div>
                    </div>
                    <div class="sleek-stat-value"><?= $tests_passed ?></div>
                    <div class="sleek-stat-trend trend-up"><i data-lucide="check-square" class="icon-sm"></i> Tests Passed</div>
                </div>
            </div>

            <!-- Action Center & Notifications -->
            <div class="card">
                <div class="card-header">
                    <div style="display: flex; align-items: center; justify-content: space-between; width: 100%; flex-wrap: wrap; gap: 10px;">
                        <div style="display: flex; align-items: center;">
                            <h3>Action Center & Notifications</h3>
                            <?php if ($action_count > 0): ?>
                                <span class="notif-badge"><?= $action_count ?> Action Required</span>
                            <?php else: ?>
                                <span class="notif-badge badge-success">All Caught Up</span>
                            <?php endif; ?>
                        </div>
                        <div class="notif-header-actions" style="display: flex; align-items: center; gap: 10px;">
                            <div class="notif-filter-pills">
                                <button type="button" class="notif-pill active" onclick="filterNotifs('all', this)">All (<?= count($all_notifs) ?>)</button>
                                <?php if (!empty($milestone_notifs)): ?>
                                    <button type="button" class="notif-pill" onclick="filterNotifs('milestones', this)">Milestones (<?= count($milestone_notifs) ?>)</button>
                                <?php endif; ?>
                                <?php if (!empty($invitation_notifs)): ?>
                                    <button type="button" class="notif-pill" onclick="filterNotifs('invitations', this)">Offers (<?= count($invitation_notifs) ?>)</button>
                                <?php endif; ?>
                                <?php if (!empty($message_notifs)): ?>
                                    <button type="button" class="notif-pill" onclick="filterNotifs('messages', this)">Messages (<?= count($message_notifs) ?>)</button>
                                <?php endif; ?>
                                <?php if (!empty($test_notifs)): ?>
                                    <button type="button" class="notif-pill" onclick="filterNotifs('tests', this)">Skill Tests (<?= count($test_notifs) ?>)</button>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($message_notifs)): ?>
                                <a href="dashboard.php?mark_read=1" style="color: var(--text-secondary); font-size: 0.8rem; text-decoration: none;">Mark all read</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="card-body" style="padding: 0;">
                    <div class="notif-list">
                        <?php if (empty($all_notifs)): ?>
                            <div style="padding: 40px; text-align: center; color: var(--text-secondary);">
                                <i data-lucide="check-circle" style="width: 40px; height: 40px; color: #22c55e; margin-bottom: 10px;"></i>
                                <p style="margin: 0; font-size: 1rem; color: var(--text-primary); font-weight: 600;">All caught up!</p>
                                <p style="margin: 5px 0 0 0; font-size: 0.85rem;">No active milestone submissions, pending offers, or unread messages right now.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($all_notifs as $n): ?>
                                <div class="notif-item <?= $n['unread'] ? 'unread' : '' ?>" data-category="<?= $n['category'] ?>">
                                    <div class="notif-main">
                                        <div class="notif-icon-box <?= $n['icon_box'] ?>">
                                            <i data-lucide="<?= $n['icon'] ?>" style="width: 20px;"></i>
                                        </div>
                                        <div class="notif-content">
                                            <div class="notif-top">
                                                <span class="notif-tag <?= $n['tag_class'] ?>"><?= $n['tag'] ?></span>
                                                <h4 class="notif-title"><?= $n['title'] ?></h4>
                                            </div>
                                            <p class="notif-desc"><?= $n['desc'] ?></p>
                                            <div class="notif-meta">
                                                <i data-lucide="clock" style="width: 12px;"></i> <?= $n['meta'] ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="notif-actions">
                                        <?php if ($n['action_class'] === 'details-link'): ?>
                                            <a href="<?= $n['action_url'] ?>" style="color: var(--text-secondary); text-decoration: none; font-size: 0.85rem; display: flex; align-items: center; gap: 4px;"><?= $n['action_text'] ?> <i data-lucide="chevron-right" style="width: 14px;"></i></a>
                                        <?php else: ?>
                                            <a href="<?= $n['action_url'] ?>" class="<?= $n['action_class'] ?>"><?= $n['action_text'] ?></a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <script>
        lucide.createIcons();

        function filterNotifs(category, btn) {
            document.querySelectorAll('.notif-pill').forEach(el => el.classList.remove('active'));
            btn.classList.add('active');
            document.querySelectorAll('.notif-item').forEach(item => {
                if (category === 'all' || item.dataset.category === category) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>
