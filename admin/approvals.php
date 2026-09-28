<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';
require_role('admin');

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'], $_POST['action'])) {
    $target_user_id = (int)$_POST['user_id'];
    $action = $_POST['action'];
    
    if ($action === 'approve') {
        $stmt = $conn->prepare("UPDATE users SET status = 'active' WHERE id = ?");
        $stmt->bind_param("i", $target_user_id);
        $stmt->execute();
    } elseif ($action === 'reject') {
        $stmt = $conn->prepare("UPDATE users SET status = 'suspended' WHERE id = ?");
        $stmt->bind_param("i", $target_user_id);
        $stmt->execute();
    }
    
    header("Location: approvals.php");
    exit;
}

// Fetch pending users with pagination
$count_res = $conn->query("SELECT COUNT(*) FROM users WHERE status = 'pending'");
$total_pending = (int)($count_res ? $count_res->fetch_row()[0] : 0);
$pag = paginate($total_pending, 8);

$stmt = $conn->prepare("SELECT * FROM users WHERE status = 'pending' ORDER BY created_at DESC LIMIT ? OFFSET ?");
$stmt->bind_param("ii", $pag['per_page'], $pag['offset']);
$stmt->execute();
$res = $stmt->get_result();
$pending_users = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approvals - FreeMark Admin</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/admin/layout.css">
    <link rel="stylesheet" href="../css/admin/components.css">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        .actions form { display: inline-block; }
    </style>
</head>
<body>
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
                <li><a href="dashboard.php"><i data-lucide="layout-dashboard"></i> Overview</a></li>
                <li><a href="approvals.php" class="active"><i data-lucide="check-circle"></i> Approvals</a></li>
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

    <main class="admin-main">
        <header class="admin-header">
            <div class="header-title">
                <h2>Pending Approvals</h2>
                <p>Approve or reject new freelancer and client accounts</p>
            </div>
        </header>

        <div class="admin-content">
            <div class="admin-card">
                <div class="card-header">
                    <h3>All Pending Requests (<?= $total_pending ?>)</h3>
                </div>
                <div class="user-list">
                    <?php if (empty($pending_users)): ?>
                        <p style="padding: 20px; text-align: center;">No pending approvals at the moment.</p>
                    <?php else: ?>
                        <?php foreach ($pending_users as $p_user): ?>
                            <?php 
                                $initials = strtoupper(substr($p_user['full_name'] ?? 'U', 0, 2));
                                $role_str = ucfirst($p_user['role'] ?? 'User');
                                $time_str = isset($p_user['created_at']) ? date('M j, Y H:i', strtotime($p_user['created_at'])) : 'Unknown time';
                            ?>
                            <div class="user-list-item">
                                <div class="user-info">
                                    <div class="avatar yellow"><?php echo $initials; ?></div>
                                    <div class="user-details">
                                        <h4><?php echo htmlspecialchars($p_user['full_name'] ?? 'Unknown'); ?> <span class="role-badge"><?php echo htmlspecialchars($role_str); ?></span></h4>
                                        <p><?php echo htmlspecialchars($p_user['email'] ?? ''); ?> - <?php echo $time_str; ?></p>
                                    </div>
                                </div>
                                <div class="actions">
                                    <form method="POST" action="approvals.php">
                                        <input type="hidden" name="user_id" value="<?php echo $p_user['id']; ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button type="submit" class="btn-sm btn-approve">Approve</button>
                                    </form>
                                    <form method="POST" action="approvals.php">
                                        <input type="hidden" name="user_id" value="<?php echo $p_user['id']; ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <button type="submit" class="btn-sm btn-reject">Reject</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <?php if ($total_pending > 0): ?>
                    <div style="padding: 0 20px 20px 20px;">
                        <?= render_pagination($pag, 'approvals.php') ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
    <script>lucide.createIcons();</script>
</body>
</html>
