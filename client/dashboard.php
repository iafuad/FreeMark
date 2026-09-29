<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';
require_role('client');

$user_id = current_user_id();
$client_id = get_profile_id($conn, $user_id, 'client');

// Handle "Mark all read"
if (isset($_GET['mark_read'])) {
    $stmt = $conn->prepare("UPDATE messages SET is_read = 1 WHERE receiver_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    header("Location: dashboard.php");
    exit;
}

// 1. Stats Grid
$stmt = $conn->prepare("SELECT COUNT(*) FROM projects WHERE client_id = ? AND status = 'open'");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$active_projects = $stmt->get_result()->fetch_row()[0];

$stmt = $conn->prepare("SELECT COUNT(*) FROM proposals p JOIN projects pr ON p.project_id = pr.id WHERE pr.client_id = ? AND p.status = 'pending'");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$pending_proposals = $stmt->get_result()->fetch_row()[0];

$stmt = $conn->prepare("SELECT COALESCE(SUM(paid_to_date), 0) FROM contracts WHERE client_id = ?");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$total_spent = $stmt->get_result()->fetch_row()[0];

// 2. Fetch Dynamic Notifications
$milestone_notifs = [];
$m_stmt = $conn->prepare("
    SELECT 
        m.id AS milestone_id,
        m.title AS milestone_title,
        m.amount,
        m.submitted_at,
        p.title AS project_title,
        u.full_name AS freelancer_name
    FROM milestones m
    JOIN contracts c ON m.contract_id = c.id
    JOIN projects p ON c.project_id = p.id
    JOIN freelancer_profiles fp ON c.freelancer_id = fp.id
    JOIN users u ON fp.user_id = u.id
    WHERE c.client_id = ? AND m.status = 'submitted'
    ORDER BY m.submitted_at DESC
    LIMIT 5
");
$m_stmt->bind_param("i", $client_id);
$m_stmt->execute();
$m_res = $m_stmt->get_result();
while ($row = $m_res->fetch_assoc()) {
    $time_str = $row['submitted_at'] ? time_ago($row['submitted_at']) : 'Recently';
    $milestone_notifs[] = [
        'category' => 'milestones',
        'tag' => 'Work Approval',
        'tag_class' => 'tag-work',
        'icon' => 'check-square',
        'icon_box' => 'icon-yellow',
        'title' => 'Milestone Submitted: ' . $row['milestone_title'],
        'desc' => '<strong>' . htmlspecialchars($row['freelancer_name']) . '</strong> submitted Milestone: <em>"' . htmlspecialchars($row['milestone_title']) . '"</em> (' . format_currency($row['amount']) . ') for project <em>' . htmlspecialchars($row['project_title']) . '</em>.',
        'meta' => $time_str . ' • <span class="text-accent">Action Required</span>',
        'action_url' => 'milestone-details.php?milestone_id=' . $row['milestone_id'],
        'action_text' => 'Review Work',
        'action_class' => 'btn btn-primary btn-compact',
        'unread' => true,
        'timestamp' => $row['submitted_at'] ? strtotime($row['submitted_at']) : time()
    ];
}

$proposal_notifs = [];
$p_stmt = $conn->prepare("
    SELECT 
        pr.id AS proposal_id,
        pr.bid_amount,
        pr.estimated_duration,
        pr.created_at,
        p.title AS project_title,
        u.full_name AS freelancer_name,
        fp.title AS freelancer_title,
        (SELECT tr.score FROM test_results tr WHERE tr.freelancer_id = fp.id AND tr.passed = 1 ORDER BY tr.score DESC LIMIT 1) AS test_score
    FROM proposals pr
    JOIN projects p ON pr.project_id = p.id
    JOIN freelancer_profiles fp ON pr.freelancer_id = fp.id
    JOIN users u ON fp.user_id = u.id
    WHERE p.client_id = ? AND pr.status = 'pending'
    ORDER BY pr.created_at DESC
    LIMIT 5
");
$p_stmt->bind_param("i", $client_id);
$p_stmt->execute();
$p_res = $p_stmt->get_result();
while ($row = $p_res->fetch_assoc()) {
    $time_str = $row['created_at'] ? time_ago($row['created_at']) : 'Recently';
    $score_text = $row['test_score'] ? ' with a test score of ' . $row['test_score'] . ' pts' : '';
    $proposal_notifs[] = [
        'category' => 'proposals',
        'tag' => 'Proposals',
        'tag_class' => 'tag-proposals',
        'icon' => 'file-text',
        'icon_box' => 'icon-blue',
        'title' => 'New Proposal: ' . $row['project_title'],
        'desc' => '<strong>' . htmlspecialchars($row['freelancer_name']) . ($row['freelancer_title'] ? ' (' . htmlspecialchars($row['freelancer_title']) . ')' : '') . '</strong> submitted a proposal for ' . format_currency($row['bid_amount']) . ' (' . format_duration($row['estimated_duration']) . ')' . $score_text . '.',
        'meta' => $time_str,
        'action_url' => 'review-proposal.php?id=' . $row['proposal_id'],
        'action_text' => 'Review Proposal',
        'action_class' => 'btn btn-outline btn-compact',
        'unread' => true,
        'timestamp' => $row['created_at'] ? strtotime($row['created_at']) : time()
    ];
}

$message_notifs = [];
$msg_stmt = $conn->prepare("
    SELECT 
        m.id AS message_id,
        m.sender_id,
        m.content,
        m.created_at,
        u.full_name AS sender_name
    FROM messages m
    JOIN users u ON m.sender_id = u.id
    WHERE m.receiver_id = ? AND m.is_read = 0
    ORDER BY m.created_at DESC
    LIMIT 5
");
$msg_stmt->bind_param("i", $user_id);
$msg_stmt->execute();
$msg_res = $msg_stmt->get_result();
while ($row = $msg_res->fetch_assoc()) {
    $time_str = $row['created_at'] ? time_ago($row['created_at']) : 'Recently';
    $snippet = strlen($row['content']) > 80 ? substr($row['content'], 0, 77) . '...' : $row['content'];
    $message_notifs[] = [
        'category' => 'messages',
        'tag' => 'Messages',
        'tag_class' => 'tag-messages',
        'icon' => 'message-square',
        'icon_box' => 'icon-purple',
        'title' => 'New Message from ' . $row['sender_name'],
        'desc' => '<em>"' . htmlspecialchars($snippet) . '"</em>',
        'meta' => $time_str,
        'action_url' => 'chat.php?with=' . $row['sender_id'],
        'action_text' => 'Open Chat',
        'action_class' => 'btn btn-outline btn-compact',
        'unread' => true,
        'timestamp' => $row['created_at'] ? strtotime($row['created_at']) : time()
    ];
}

$contract_notifs = [];
$pay_stmt = $conn->prepare("
    SELECT 
        m.id AS milestone_id,
        m.title AS milestone_title,
        m.amount,
        p.title AS project_title,
        u.full_name AS freelancer_name
    FROM milestones m
    JOIN contracts c ON m.contract_id = c.id
    JOIN projects p ON c.project_id = p.id
    JOIN freelancer_profiles fp ON c.freelancer_id = fp.id
    JOIN users u ON fp.user_id = u.id
    WHERE c.client_id = ? AND m.status = 'approved'
    ORDER BY m.id DESC
    LIMIT 3
");
$pay_stmt->bind_param("i", $client_id);
$pay_stmt->execute();
$pay_res = $pay_stmt->get_result();
while ($row = $pay_res->fetch_assoc()) {
    $contract_notifs[] = [
        'category' => 'contracts',
        'tag' => 'Contracts',
        'tag_class' => 'tag-contracts',
        'icon' => 'dollar-sign',
        'icon_box' => 'icon-green',
        'title' => 'Payment Released: ' . $row['milestone_title'],
        'desc' => format_currency($row['amount']) . ' was successfully released to <strong>' . htmlspecialchars($row['freelancer_name']) . '</strong> for milestone: <em>"' . htmlspecialchars($row['milestone_title']) . '"</em>.',
        'meta' => 'Completed',
        'action_url' => 'milestone-details.php?milestone_id=' . $row['milestone_id'],
        'action_text' => 'Details',
        'action_class' => 'details-link',
        'unread' => false,
        'timestamp' => 0
    ];
}

$all_notifs = array_merge($milestone_notifs, $proposal_notifs, $message_notifs, $contract_notifs);
usort($all_notifs, function($a, $b) {
    if ($a['unread'] !== $b['unread']) {
        return $a['unread'] ? -1 : 1;
    }
    return $b['timestamp'] <=> $a['timestamp'];
});

$unread_count = count($milestone_notifs) + count($proposal_notifs) + count($message_notifs);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Dashboard - FreeMark</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/client/layout.css">
    <link rel="stylesheet" href="../css/client/components.css">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        .notif-pill {
            cursor: pointer;
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 0.85rem;
            padding: 5px 12px;
            border-radius: 4px;
            transition: all 0.2s;
        }
        .notif-pill:hover {
            color: var(--text-primary);
        }
        .notif-pill.active {
            background: var(--bg-secondary, #1e293b);
            color: var(--accent, #facc15);
            font-weight: 600;
        }
    </style>
</head>
<body>
    <aside class="client-sidebar">
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
                <li><a href="profile.php"><i data-lucide="building"></i> Company Profile</a></li>
                <li><a href="projects.php"><i data-lucide="briefcase"></i> My Projects</a></li>
                <li><a href="post-project.php"><i data-lucide="plus-circle"></i> Post Project</a></li>
                <li><a href="freelancers.php"><i data-lucide="users"></i> Freelancers</a></li>
                <li><a href="proposals.php"><i data-lucide="file-text"></i> Proposals</a></li>
                <li><a href="work-approval.php"><i data-lucide="check-square"></i> Work & Ratings</a></li>
                <li><a href="chat.php"><i data-lucide="message-square"></i> Messages</a></li>
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
                <h2>Welcome back, <?= htmlspecialchars(current_user_name()) ?></h2>
                <p>Manage your projects and hire top talent.</p>
            </div>
            <div class="header-actions">
                <a href="post-project.php" class="btn btn-primary">Post a New Project</a>
            </div>
        </header>

        <div class="client-content">
            <!-- Sleek Stats Grid -->
            <div class="sleek-stats-grid">
                <div class="sleek-stat-card">
                    <div class="sleek-stat-header">
                        <span class="sleek-stat-title">Active Projects</span>
                        <div class="sleek-stat-icon icon-blue"><i data-lucide="briefcase"></i></div>
                    </div>
                    <div class="sleek-stat-value"><?= $active_projects ?></div>
                    <div class="sleek-stat-trend trend-neutral"><i data-lucide="minus"></i> Open for bidding</div>
                </div>
                
                <div class="sleek-stat-card">
                    <div class="sleek-stat-header">
                        <span class="sleek-stat-title">Pending Proposals</span>
                        <div class="sleek-stat-icon icon-yellow"><i data-lucide="file-text"></i></div>
                    </div>
                    <div class="sleek-stat-value"><?= $pending_proposals ?></div>
                    <div class="sleek-stat-trend trend-up"><i data-lucide="trending-up"></i> Awaiting review</div>
                </div>
                
                <div class="sleek-stat-card">
                    <div class="sleek-stat-header">
                        <span class="sleek-stat-title">Total Spent</span>
                        <div class="sleek-stat-icon icon-green"><i data-lucide="dollar-sign"></i></div>
                    </div>
                    <div class="sleek-stat-value"><?= format_currency($total_spent) ?></div>
                    <div class="sleek-stat-trend trend-up"><i data-lucide="trending-up"></i> Active milestones</div>
                </div>
            </div>

            <!-- Quick Notifications & Action Center Panel -->
            <div class="card">
                <div class="card-header">
                    <div class="header-title-row" style="display: flex; align-items: center; justify-content: space-between; width: 100%; flex-wrap: wrap; gap: 10px;">
                        <div style="display: flex; align-items: center;">
                            <h3>Action Center & Notifications</h3>
                            <?php if ($unread_count > 0): ?>
                                <span class="notif-badge"><?= $unread_count ?> Action Required</span>
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
                                <?php if (!empty($proposal_notifs)): ?>
                                    <button type="button" class="notif-pill" onclick="filterNotifs('proposals', this)">Proposals (<?= count($proposal_notifs) ?>)</button>
                                <?php endif; ?>
                                <?php if (!empty($message_notifs)): ?>
                                    <button type="button" class="notif-pill" onclick="filterNotifs('messages', this)">Messages (<?= count($message_notifs) ?>)</button>
                                <?php endif; ?>
                                <?php if (!empty($contract_notifs)): ?>
                                    <button type="button" class="notif-pill" onclick="filterNotifs('contracts', this)">Contracts (<?= count($contract_notifs) ?>)</button>
                                <?php endif; ?>
                            </div>
                            <?php if ($unread_count > 0): ?>
                                <a href="dashboard.php?mark_read=1" class="text-muted-sm" style="margin-left: 15px;">Mark all read</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="card-body card-body-flush">
                    <div class="notif-list">
                        <?php if (empty($all_notifs)): ?>
                            <div style="padding: 40px; text-align: center; color: var(--text-secondary);">
                                <i data-lucide="check-circle" style="width: 40px; height: 40px; color: #22c55e; margin-bottom: 10px;"></i>
                                <p style="margin: 0; font-size: 1rem; color: var(--text-primary); font-weight: 600;">All caught up!</p>
                                <p style="margin: 5px 0 0 0; font-size: 0.85rem;">No pending approvals, proposals, or unread messages right now.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($all_notifs as $n): ?>
                                <div class="notif-item <?= $n['unread'] ? 'unread' : '' ?>" data-category="<?= $n['category'] ?>">
                                    <div class="notif-main">
                                        <div class="notif-icon-box <?= $n['icon_box'] ?>">
                                            <i data-lucide="<?= $n['icon'] ?>" class="icon-xl"></i>
                                        </div>
                                        <div class="notif-content">
                                            <div class="notif-top">
                                                <span class="notif-tag <?= $n['tag_class'] ?>"><?= $n['tag'] ?></span>
                                                <h4 class="notif-title"><?= htmlspecialchars($n['title']) ?></h4>
                                            </div>
                                            <p class="notif-desc"><?= $n['desc'] ?></p>
                                            <div class="notif-meta">
                                                <i data-lucide="clock" class="icon-xs"></i> <?= $n['meta'] ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="notif-actions">
                                        <?php if ($n['action_class'] === 'details-link'): ?>
                                            <a href="<?= $n['action_url'] ?>" class="details-link"><?= $n['action_text'] ?> <i data-lucide="chevron-right" class="icon-sm"></i></a>
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
