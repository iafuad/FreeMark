<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';
require_role('admin');

// Fetch stats
$res = $conn->query("SELECT COUNT(*) as cnt FROM users");
$total_users = $res ? $res->fetch_assoc()['cnt'] : 0;

$res = $conn->query("SELECT COUNT(*) as cnt FROM projects WHERE status IN ('open', 'in_progress')");
$active_projects = $res ? $res->fetch_assoc()['cnt'] : 0;

$res = $conn->query("SELECT SUM(paid_to_date) as total FROM contracts WHERE status = 'completed'");
$total_revenue = $res ? $res->fetch_assoc()['total'] : 0;
$total_revenue = $total_revenue ?? 0;

$res = $conn->query("SELECT COUNT(*) as cnt FROM projects WHERE status = 'completed'");
$completed_jobs = $res ? $res->fetch_assoc()['cnt'] : 0;

$res = $conn->query("SELECT COUNT(*) as cnt FROM users WHERE status = 'pending'");
$pending_approvals = $res ? $res->fetch_assoc()['cnt'] : 0;

// 1. Pending Approvals
$approval_notifs = [];
$res = $conn->query("SELECT * FROM users WHERE status = 'pending' ORDER BY created_at DESC LIMIT 5");
if ($res) {
    while ($p_user = $res->fetch_assoc()) {
        $approval_notifs[] = [
            'category' => 'approvals',
            'tag' => 'Account Approval',
            'tag_class' => 'tag-approvals',
            'icon' => 'user-check',
            'icon_box' => 'icon-yellow',
            'title' => 'New Account Application: ' . htmlspecialchars($p_user['full_name'] ?? 'Unknown User'),
            'desc' => 'Applied as <strong>' . ucfirst(htmlspecialchars($p_user['role'] ?? 'User')) . '</strong> (' . htmlspecialchars($p_user['email']) . '). Awaiting administrator review.',
            'meta' => time_ago($p_user['created_at']) . ' • Verification Queue',
            'action_url' => 'approvals.php',
            'action_text' => 'Review & Approve',
            'action_class' => 'btn btn-primary btn-sm',
            'unread' => true,
            'timestamp' => $p_user['created_at'] ? strtotime($p_user['created_at']) : time()
        ];
    }
}

// 2. Suspended Accounts (Security Alert)
$security_notifs = [];
$res = $conn->query("SELECT * FROM users WHERE status = 'suspended' ORDER BY created_at DESC LIMIT 3");
if ($res) {
    while ($s_user = $res->fetch_assoc()) {
        $security_notifs[] = [
            'category' => 'security',
            'tag' => 'Security Alert',
            'tag_class' => 'tag-fraud',
            'icon' => 'shield-alert',
            'icon_box' => 'icon-red',
            'title' => 'Suspended Account: ' . htmlspecialchars($s_user['full_name'] ?? 'User'),
            'desc' => 'Account <strong>' . htmlspecialchars($s_user['email']) . '</strong> (' . ucfirst(htmlspecialchars($s_user['role'])) . ') is currently suspended.',
            'meta' => 'Account Flagged',
            'action_url' => 'users.php',
            'action_text' => 'Manage User',
            'action_class' => 'btn btn-outline btn-sm',
            'unread' => true,
            'timestamp' => $s_user['created_at'] ? strtotime($s_user['created_at']) : time()
        ];
    }
}

// 3. Open Projects (Monitoring)
$project_notifs = [];
$res = $conn->query("SELECT p.*, cp.company_name, u.full_name as client_name 
    FROM projects p 
    JOIN client_profiles cp ON p.client_id = cp.id 
    JOIN users u ON cp.user_id = u.id 
    WHERE p.status = 'open' 
    ORDER BY p.created_at DESC LIMIT 3");
if ($res) {
    while ($p_item = $res->fetch_assoc()) {
        $project_notifs[] = [
            'category' => 'projects',
            'tag' => 'Job Listing',
            'tag_class' => 'tag-contracts',
            'icon' => 'briefcase',
            'icon_box' => 'icon-blue',
            'title' => 'Open Project: ' . htmlspecialchars($p_item['title']),
            'desc' => 'Posted by <strong>' . htmlspecialchars($p_item['company_name'] ?: $p_item['client_name']) . '</strong> with budget ' . format_currency($p_item['budget_max']) . '.',
            'meta' => time_ago($p_item['created_at']) . ' • Open for Bids',
            'action_url' => '../guest/job-details.php?id=' . $p_item['id'],
            'action_text' => 'View Details',
            'action_class' => 'details-link',
            'unread' => false,
            'timestamp' => $p_item['created_at'] ? strtotime($p_item['created_at']) : time()
        ];
    }
}

// 4. Active Contracts (Monitoring)
$contract_notifs = [];
$res = $conn->query("SELECT c.*, p.title as project_title, cp.company_name, u_cl.full_name as client_name, u_fl.full_name as freelancer_name
    FROM contracts c
    JOIN projects p ON c.project_id = p.id
    JOIN client_profiles cp ON c.client_id = cp.id
    JOIN users u_cl ON cp.user_id = u_cl.id
    JOIN freelancer_profiles fp ON c.freelancer_id = fp.id
    JOIN users u_fl ON fp.user_id = u_fl.id
    WHERE c.status = 'active'
    ORDER BY c.created_at DESC LIMIT 3");
if ($res) {
    while ($c_item = $res->fetch_assoc()) {
        $contract_notifs[] = [
            'category' => 'contracts',
            'tag' => 'Active Contract',
            'tag_class' => 'tag-tests',
            'icon' => 'check-circle',
            'icon_box' => 'icon-green',
            'title' => 'Active: ' . htmlspecialchars($c_item['project_title']),
            'desc' => 'Freelancer <strong>' . htmlspecialchars($c_item['freelancer_name']) . '</strong> working with <strong>' . htmlspecialchars($c_item['company_name'] ?: $c_item['client_name']) . '</strong> (' . format_currency($c_item['total_budget']) . ').',
            'meta' => time_ago($c_item['created_at']) . ' • In Progress',
            'action_url' => 'reports.php',
            'action_text' => 'View Reports',
            'action_class' => 'details-link',
            'unread' => false,
            'timestamp' => $c_item['created_at'] ? strtotime($c_item['created_at']) : time()
        ];
    }
}

// Merge & Sort: unread first, then by timestamp
$all_notifs = array_merge($approval_notifs, $security_notifs, $project_notifs, $contract_notifs);
usort($all_notifs, function($a, $b) {
    if ($a['unread'] !== $b['unread']) {
        return $a['unread'] ? -1 : 1;
    }
    return $b['timestamp'] <=> $a['timestamp'];
});

$action_count = count($approval_notifs) + count($security_notifs);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - FreeMark</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/admin/layout.css">
    <link rel="stylesheet" href="../css/admin/components.css">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
    <!-- Sidebar -->
    <aside class="admin-sidebar">
        <div class="sidebar-header">
            <a href="../index.php" class="logo">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>
                </svg>
                FreeMark Admin
            </a>
        </div>
        <nav class="sidebar-nav">
            <ul>
                <li><a href="dashboard.php" class="active"><i data-lucide="layout-dashboard"></i> Overview</a></li>
                <li><a href="approvals.php"><i data-lucide="check-circle"></i> Approvals</a></li>
                <li><a href="skills.php"><i data-lucide="award"></i> Skill Categories</a></li>
                <li><a href="quizzes.php"><i data-lucide="help-circle"></i> Quiz Banks</a></li>
                <li><a href="users.php"><i data-lucide="users"></i> User Management</a></li>
                <li><a href="reports.php"><i data-lucide="file-text"></i> Reports</a></li>
            </ul>
        </nav>
        <div class="sidebar-footer">
            <a href="../logout.php" style="color: var(--text-secondary); text-decoration: none; display: flex; align-items: center; gap: 10px;">
                <i data-lucide="log-out"></i> Logout
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="admin-main">
        <header class="admin-header">
            <div class="header-title">
                <h2>Admin Dashboard</h2>
                <p>Platform management & analytics</p>
            </div>
            <a href="../index.php" class="btn btn-primary" style="display: flex; align-items: center; gap: 8px;">
                <i data-lucide="home" style="width: 18px;"></i> Back to Home
            </a>
        </header>

        <div class="admin-content">
            <!-- Sleek Stats Grid -->
            <div class="sleek-stats-grid">
                <div class="sleek-stat-card">
                    <div class="sleek-stat-header">
                        <span class="sleek-stat-title">Total Users</span>
                        <div class="sleek-stat-icon icon-blue"><i data-lucide="users"></i></div>
                    </div>
                    <div class="sleek-stat-value"><?php echo number_format($total_users); ?></div>
                    <div class="sleek-stat-trend trend-up"><i data-lucide="trending-up"></i> +5% this week</div>
                </div>
                
                <div class="sleek-stat-card">
                    <div class="sleek-stat-header">
                        <span class="sleek-stat-title">Active Projects</span>
                        <div class="sleek-stat-icon icon-purple"><i data-lucide="briefcase"></i></div>
                    </div>
                    <div class="sleek-stat-value"><?php echo number_format($active_projects); ?></div>
                    <div class="sleek-stat-trend trend-up"><i data-lucide="trending-up"></i> +12% this week</div>
                </div>
                
                <div class="sleek-stat-card">
                    <div class="sleek-stat-header">
                        <span class="sleek-stat-title">Total Volume</span>
                        <div class="sleek-stat-icon icon-yellow"><i data-lucide="dollar-sign"></i></div>
                    </div>
                    <div class="sleek-stat-value">$<?php echo number_format($total_revenue, 2); ?></div>
                    <div class="sleek-stat-trend trend-up"><i data-lucide="trending-up"></i> Completed contracts</div>
                </div>

                <div class="sleek-stat-card">
                    <div class="sleek-stat-header">
                        <span class="sleek-stat-title">Completed Jobs</span>
                        <div class="sleek-stat-icon icon-green"><i data-lucide="check-circle"></i></div>
                    </div>
                    <div class="sleek-stat-value"><?php echo number_format($completed_jobs); ?></div>
                    <div class="sleek-stat-trend trend-neutral"><i data-lucide="minus"></i> Steady</div>
                </div>
            </div>

            <!-- Admin Action Center & Alerts -->
            <div class="admin-card" style="padding: 0; overflow: hidden;">
                <div class="card-header" style="padding: 15px 20px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; width: 100%; flex-wrap: wrap; gap: 10px;">
                        <div style="display: flex; align-items: center;">
                            <h3 style="margin: 0;">Action Center & Notifications</h3>
                            <?php if ($action_count > 0): ?>
                                <span class="notif-badge"><?= $action_count ?> Action Required</span>
                            <?php else: ?>
                                <span class="notif-badge badge-success">All Caught Up</span>
                            <?php endif; ?>
                        </div>
                        <div class="notif-header-actions" style="display: flex; align-items: center; gap: 10px;">
                            <div class="notif-filter-pills">
                                <button type="button" class="notif-pill active" onclick="filterNotifs('all', this)">All (<?= count($all_notifs) ?>)</button>
                                <?php if (!empty($approval_notifs)): ?>
                                    <button type="button" class="notif-pill" onclick="filterNotifs('approvals', this)">Approvals (<?= count($approval_notifs) ?>)</button>
                                <?php endif; ?>
                                <?php if (!empty($security_notifs)): ?>
                                    <button type="button" class="notif-pill" onclick="filterNotifs('security', this)">Security (<?= count($security_notifs) ?>)</button>
                                <?php endif; ?>
                                <?php if (!empty($project_notifs)): ?>
                                    <button type="button" class="notif-pill" onclick="filterNotifs('projects', this)">Projects (<?= count($project_notifs) ?>)</button>
                                <?php endif; ?>
                                <?php if (!empty($contract_notifs)): ?>
                                    <button type="button" class="notif-pill" onclick="filterNotifs('contracts', this)">Contracts (<?= count($contract_notifs) ?>)</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="notif-list">
                    <?php if (empty($all_notifs)): ?>
                        <div style="padding: 40px; text-align: center; color: var(--text-secondary);">
                            <i data-lucide="check-circle" style="width: 40px; height: 40px; color: #22c55e; margin-bottom: 10px;"></i>
                            <p style="margin: 0; font-size: 1rem; color: var(--text-primary); font-weight: 600;">All caught up!</p>
                            <p style="margin: 5px 0 0 0; font-size: 0.85rem;">No pending account applications or security alerts.</p>
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
                                        <a href="<?= $n['action_url'] ?>" class="<?= $n['action_class'] ?>" style="text-decoration: none;"><?= $n['action_text'] ?></a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
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
