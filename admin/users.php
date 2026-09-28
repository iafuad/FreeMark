<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';
require_role('admin');

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'], $_POST['action'])) {
    $target_user_id = (int)$_POST['user_id'];
    $action = $_POST['action'];
    
    if ($action === 'suspend') {
        $stmt = $conn->prepare("UPDATE users SET status = 'suspended' WHERE id = ?");
        $stmt->bind_param("i", $target_user_id);
        $stmt->execute();
    } elseif ($action === 'activate') {
        $stmt = $conn->prepare("UPDATE users SET status = 'active' WHERE id = ?");
        $stmt->bind_param("i", $target_user_id);
        $stmt->execute();
    }
    
    header("Location: users.php");
    exit;
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$where = ["status != 'pending'"];
$params = [];
$types = "";

if ($search !== '') {
    $where[] = "(full_name LIKE ? OR email LIKE ?)";
    $s_term = '%' . $search . '%';
    $params[] = $s_term;
    $params[] = $s_term;
    $types .= "ss";
}

// Count total matching users
$count_sql = "SELECT COUNT(*) FROM users WHERE " . implode(' AND ', $where);
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_users = (int)$count_stmt->get_result()->fetch_row()[0];

// Paginate (8 per page)
$pag = paginate($total_users, 8);

$sql = "SELECT * FROM users WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC LIMIT ? OFFSET ?";
$paged_params = $params;
$paged_params[] = $pag['per_page'];
$paged_params[] = $pag['offset'];
$paged_types = $types . "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($paged_types, ...$paged_params);
$stmt->execute();
$user_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - FreeMark Admin</title>
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
                <li><a href="approvals.php"><i data-lucide="check-circle"></i> Approvals</a></li>
                <li><a href="skills.php"><i data-lucide="award"></i> Skill Categories</a></li>
                <li><a href="quizzes.php"><i data-lucide="help-circle"></i> Quiz Banks</a></li>
                <li><a href="users.php" class="active"><i data-lucide="users"></i> User Management</a></li>
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
                <h2>User Management</h2>
                <p>Suspend fraudulent users and manage platform access</p>
            </div>
            <form class="form-inline" style="padding: 0; align-items: center; margin: 0;" method="GET" action="users.php">
                <input type="text" name="search" placeholder="Search users..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-primary" style="padding: 10px 15px;"><i data-lucide="search" style="width: 18px;"></i></button>
            </form>
        </header>

        <div class="admin-content">
            <div class="admin-card">
                <div class="card-header">
                    <h3>Registered Users (<?php echo $total_users; ?>)</h3>
                </div>
                <div class="user-list">
                    <?php if (empty($user_list)): ?>
                        <p style="padding: 20px; text-align: center;">No users found.</p>
                    <?php else: ?>
                        <?php foreach ($user_list as $u): ?>
                            <?php 
                                $initials = strtoupper(substr($u['full_name'] ?? 'U', 0, 2));
                                $role_str = ucfirst($u['role'] ?? 'User');
                                $is_suspended = ($u['status'] === 'suspended');
                            ?>
                            <div class="user-list-item" <?php if ($is_suspended) echo 'style="background-color: rgba(239, 68, 68, 0.05);"'; ?>>
                                <div class="user-info">
                                    <div class="avatar <?php echo $is_suspended ? 'gray' : 'yellow'; ?>"><?php echo $initials; ?></div>
                                    <div class="user-details">
                                        <h4><?php echo htmlspecialchars($u['full_name'] ?? 'Unknown'); ?> 
                                            <span class="role-badge" <?php if ($is_suspended) echo 'style="background-color: rgba(239, 68, 68, 0.2); color: #ef4444;"'; ?>>
                                                <?php echo $is_suspended ? 'Suspended' : htmlspecialchars($role_str); ?>
                                            </span>
                                        </h4>
                                        <p><?php echo htmlspecialchars($u['email'] ?? ''); ?></p>
                                    </div>
                                </div>
                                <div class="actions">
                                    <?php if ($is_suspended): ?>
                                        <form method="POST" action="users.php">
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <input type="hidden" name="action" value="activate">
                                            <button type="submit" class="btn-sm btn-approve">Activate</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" action="users.php">
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <input type="hidden" name="action" value="suspend">
                                            <button type="submit" class="btn-sm btn-reject">Suspend</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div style="padding: 0 20px 20px 20px;">
                    <?= render_pagination($pag, 'users.php') ?>
                </div>
            </div>
        </div>
    </main>
    <script>lucide.createIcons();</script>
</body>
</html>
