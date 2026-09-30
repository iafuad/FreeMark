<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';
require_role('admin');

$current_admin_id = (int)($_SESSION['user_id'] ?? 0);

// Handle Actions (Suspend / Activate) with PRG and Safety Checks
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'], $_POST['action'])) {
    $target_user_id = (int)$_POST['user_id'];
    $action = trim($_POST['action']);
    
    // Prevent self-suspension
    if ($action === 'suspend' && $target_user_id === $current_admin_id) {
        $_SESSION['flash_error'] = "Action denied: You cannot suspend your own administrative account.";
    } else {
        if ($action === 'suspend') {
            $stmt = $conn->prepare("UPDATE users SET status = 'suspended' WHERE id = ?");
            $stmt->bind_param("i", $target_user_id);
            if ($stmt->execute()) {
                $_SESSION['flash_success'] = "User #{$target_user_id} has been suspended successfully.";
            } else {
                $_SESSION['flash_error'] = "Failed to suspend user: " . $conn->error;
            }
            $stmt->close();
        } elseif ($action === 'activate') {
            $stmt = $conn->prepare("UPDATE users SET status = 'active' WHERE id = ?");
            $stmt->bind_param("i", $target_user_id);
            if ($stmt->execute()) {
                $_SESSION['flash_success'] = "User #{$target_user_id} account has been activated.";
            } else {
                $_SESSION['flash_error'] = "Failed to activate user: " . $conn->error;
            }
            $stmt->close();
        }
    }
    
    // Preserve query parameters on redirect
    $return_query = [];
    if (!empty($_POST['return_search'])) $return_query['search'] = trim($_POST['return_search']);
    if (!empty($_POST['return_role'])) $return_query['role'] = trim($_POST['return_role']);
    if (!empty($_POST['return_status'])) $return_query['status'] = trim($_POST['return_status']);
    if (!empty($_POST['return_reported'])) $return_query['reported'] = (int)$_POST['return_reported'];
    if (!empty($_POST['return_sort'])) $return_query['sort'] = trim($_POST['return_sort']);
    if (!empty($_POST['return_page'])) $return_query['page'] = (int)$_POST['return_page'];
    
    $redirect_url = 'users.php' . (!empty($return_query) ? '?' . http_build_query($return_query) : '');
    header("Location: " . $redirect_url);
    exit;
}

// Flash messages
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Top-Level KPI Summary Query
$kpi_res = $conn->query("
    SELECT 
        COUNT(*) AS total_users,
        SUM(CASE WHEN role = 'freelancer' THEN 1 ELSE 0 END) AS total_freelancers,
        SUM(CASE WHEN role = 'client' THEN 1 ELSE 0 END) AS total_clients,
        SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) AS total_admins,
        SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) AS total_suspended,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS total_pending,
        (SELECT COUNT(DISTINCT reported_user_id) FROM profile_reports) AS total_flagged
    FROM users
");
$kpi = $kpi_res->fetch_assoc();

function user_filter_url($new_params = []) {
    $params = $_GET;
    unset($params['page']);
    foreach ($new_params as $k => $v) {
        if ($v === null || $v === '' || $v === false) {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    return 'users.php' . (!empty($params) ? '?' . http_build_query($params) : '');
}

// Filter & Sort parameters
$search = trim($_GET['search'] ?? '');
$role_filter = trim($_GET['role'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$reported_filter = (int)($_GET['reported'] ?? 0);
$sort = trim($_GET['sort'] ?? 'newest');
$page = (int)($_GET['page'] ?? 1);

$where = ["1=1"];
$params = [];
$types = "";

if ($search !== '') {
    $where[] = "(u.full_name LIKE ? OR u.email LIKE ? OR cp.company_name LIKE ? OR fp.title LIKE ?)";
    $s_term = '%' . $search . '%';
    $params[] = $s_term;
    $params[] = $s_term;
    $params[] = $s_term;
    $params[] = $s_term;
    $types .= "ssss";
}

if (in_array($role_filter, ['admin', 'client', 'freelancer'])) {
    $where[] = "u.role = ?";
    $params[] = $role_filter;
    $types .= "s";
}

if (in_array($status_filter, ['active', 'suspended', 'pending'])) {
    $where[] = "u.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($reported_filter === 1) {
    $where[] = "(SELECT COUNT(*) FROM profile_reports WHERE reported_user_id = u.id) > 0";
}

// Count matching records
$count_sql = "
    SELECT COUNT(DISTINCT u.id)
    FROM users u
    LEFT JOIN client_profiles cp ON u.id = cp.user_id AND u.role = 'client'
    LEFT JOIN freelancer_profiles fp ON u.id = fp.user_id AND u.role = 'freelancer'
    WHERE " . implode(' AND ', $where);

$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_matching = (int)$count_stmt->get_result()->fetch_row()[0];
$count_stmt->close();

// Pagination (10 per page)
$pag = paginate($total_matching, 10);

// Sorting
switch ($sort) {
    case 'oldest':
        $order_sql = "u.created_at ASC";
        break;
    case 'name_asc':
        $order_sql = "u.full_name ASC";
        break;
    case 'name_desc':
        $order_sql = "u.full_name DESC";
        break;
    case 'reports':
        $order_sql = "pending_reports_count DESC, total_reports_count DESC, u.created_at DESC";
        break;
    case 'newest':
    default:
        $order_sql = "u.created_at DESC";
        break;
}

// Fetch Paginated User List with Linked Profiles & Activity Counts
$sql = "
    SELECT 
        u.id, u.email, u.full_name, u.role, u.status, u.created_at,
        cp.id AS client_profile_id, cp.company_name,
        fp.id AS freelancer_profile_id, fp.title AS freelancer_title,
        (SELECT COUNT(*) FROM projects WHERE client_id = cp.id) AS project_count,
        (SELECT COUNT(*) FROM freelancer_skills WHERE freelancer_id = fp.id) AS skill_count,
        (SELECT COUNT(*) FROM profile_reports WHERE reported_user_id = u.id AND status = 'pending') AS pending_reports_count,
        (SELECT COUNT(*) FROM profile_reports WHERE reported_user_id = u.id) AS total_reports_count
    FROM users u
    LEFT JOIN client_profiles cp ON u.id = cp.user_id AND u.role = 'client'
    LEFT JOIN freelancer_profiles fp ON u.id = fp.user_id AND u.role = 'freelancer'
    WHERE " . implode(' AND ', $where) . "
    ORDER BY {$order_sql}
    LIMIT ? OFFSET ?
";

$paged_params = $params;
$paged_params[] = $pag['per_page'];
$paged_params[] = $pag['offset'];
$paged_types = $types . "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($paged_types, ...$paged_params);
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$has_active_filters = ($search !== '' || $role_filter !== '' || $status_filter !== '' || $reported_filter === 1 || $sort !== 'newest');
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
    <link rel="stylesheet" href="../css/admin/users.css">
    <script src="https://unpkg.com/lucide@latest"></script>
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
                <li><a href="reports.php"><i data-lucide="file-text"></i> Analytics</a></li>
                <li><a href="profile-reports.php"><i data-lucide="flag"></i> Profile Reports</a></li>
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
                <p>Monitor platform accounts, inspect linked profiles, and enforce access safety</p>
            </div>
            <div class="header-actions">
                <a href="approvals.php" class="btn btn-outline" style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.85rem;">
                    <i data-lucide="user-check" style="width: 16px;"></i> Review Approvals
                </a>
            </div>
        </header>

        <div class="admin-content">
            <?php if ($flash_success): ?>
                <div class="alert-banner success">
                    <i data-lucide="check-circle" style="width: 18px; flex-shrink: 0;"></i>
                    <span><?= htmlspecialchars($flash_success) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($flash_error): ?>
                <div class="alert-banner error">
                    <i data-lucide="alert-triangle" style="width: 18px; flex-shrink: 0;"></i>
                    <span><?= htmlspecialchars($flash_error) ?></span>
                </div>
            <?php endif; ?>

            <!-- KPI Summary Cards -->
            <div class="users-kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-icon blue"><i data-lucide="users"></i></div>
                    <div class="kpi-details">
                        <span class="kpi-val"><?= (int)$kpi['total_users'] ?></span>
                        <span class="kpi-lbl">Total Registered</span>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon yellow"><i data-lucide="briefcase"></i></div>
                    <div class="kpi-details">
                        <span class="kpi-val"><?= (int)$kpi['total_freelancers'] ?></span>
                        <span class="kpi-lbl">Freelancers</span>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon green"><i data-lucide="building"></i></div>
                    <div class="kpi-details">
                        <span class="kpi-val"><?= (int)$kpi['total_clients'] ?></span>
                        <span class="kpi-lbl">Clients</span>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon red"><i data-lucide="user-x"></i></div>
                    <div class="kpi-details">
                        <span class="kpi-val"><?= (int)$kpi['total_suspended'] ?></span>
                        <span class="kpi-lbl">Suspended</span>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon amber"><i data-lucide="clock"></i></div>
                    <div class="kpi-details">
                        <span class="kpi-val"><?= (int)$kpi['total_pending'] ?></span>
                        <span class="kpi-lbl">Pending Review</span>
                    </div>
                </div>
            </div>

            <!-- Toolbar & Filter Bar -->
            <div class="users-toolbar-card">
                <!-- Row 1: Role Tabs & Flagged Pill -->
                <div class="users-toolbar-top">
                    <div class="users-role-tabs">
                        <a href="<?= user_filter_url(['role' => null]) ?>" 
                           class="role-tab-btn <?= empty($role_filter) ? 'active' : '' ?>">
                            All Users <span class="tab-count-badge"><?= (int)$kpi['total_users'] ?></span>
                        </a>
                        <a href="<?= user_filter_url(['role' => 'freelancer']) ?>" 
                           class="role-tab-btn <?= $role_filter === 'freelancer' ? 'active' : '' ?>">
                            Freelancers <span class="tab-count-badge"><?= (int)$kpi['total_freelancers'] ?></span>
                        </a>
                        <a href="<?= user_filter_url(['role' => 'client']) ?>" 
                           class="role-tab-btn <?= $role_filter === 'client' ? 'active' : '' ?>">
                            Clients <span class="tab-count-badge"><?= (int)$kpi['total_clients'] ?></span>
                        </a>
                        <a href="<?= user_filter_url(['role' => 'admin']) ?>" 
                           class="role-tab-btn <?= $role_filter === 'admin' ? 'active' : '' ?>">
                            Admins <span class="tab-count-badge"><?= (int)$kpi['total_admins'] ?></span>
                        </a>
                    </div>

                    <a href="<?= user_filter_url(['reported' => ($reported_filter === 1 ? null : 1)]) ?>"
                       class="flag-filter-btn <?= $reported_filter === 1 ? 'active' : '' ?>"
                       title="Toggle flagged accounts filter">
                        <i data-lucide="flag" style="width: 14px; height: 14px;"></i>
                        Flagged Accounts
                        <span class="flag-count-badge"><?= (int)$kpi['total_flagged'] ?></span>
                    </a>
                </div>

                <!-- Row 2: Search, Status, Sort & Reset -->
                <form class="users-toolbar-bottom" method="GET" action="users.php">
                    <?php if (!empty($role_filter)): ?>
                        <input type="hidden" name="role" value="<?= htmlspecialchars($role_filter) ?>">
                    <?php endif; ?>
                    <?php if ($reported_filter === 1): ?>
                        <input type="hidden" name="reported" value="1">
                    <?php endif; ?>

                    <div class="search-box-wrap">
                        <i data-lucide="search"></i>
                        <input type="text" name="search" placeholder="Search by name, email, company, headline..." value="<?= htmlspecialchars($search) ?>" autocomplete="off">
                        <?php if (!empty($search)): ?>
                            <a href="<?= user_filter_url(['search' => null]) ?>" class="search-clear-btn" title="Clear search text">&times;</a>
                        <?php endif; ?>
                    </div>

                    <div class="filter-controls-wrap">
                        <select name="status" class="filter-select" onchange="this.form.submit()">
                            <option value="">Status: All</option>
                            <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Status: Active</option>
                            <option value="suspended" <?= $status_filter === 'suspended' ? 'selected' : '' ?>>Status: Suspended</option>
                            <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Status: Pending</option>
                        </select>

                        <select name="sort" class="filter-select" onchange="this.form.submit()">
                            <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Sort: Newest First</option>
                            <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Sort: Oldest First</option>
                            <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Sort: Name (A–Z)</option>
                            <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Sort: Name (Z–A)</option>
                            <option value="reports" <?= $sort === 'reports' ? 'selected' : '' ?>>Sort: Most Reports</option>
                        </select>

                        <?php if ($has_active_filters): ?>
                            <a href="users.php" class="btn-filter-reset" title="Reset all active search and filters">
                                <i data-lucide="rotate-ccw" style="width: 14px; height: 14px;"></i> Reset
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Users Data Table Card -->
            <div class="users-table-card">
                <div class="users-table-header">
                    <h3>
                        Users List 
                        <span class="users-count-tag"><?= number_format($total_matching) ?> Found</span>
                    </h3>
                </div>

                <div class="table-responsive">
                    <?php if (empty($users)): ?>
                        <div class="users-empty-state">
                            <i data-lucide="user-minus"></i>
                            <h4>No users match your criteria</h4>
                            <p>Try clearing your search terms or adjusting the role and status filters.</p>
                            <?php if ($has_active_filters): ?>
                                <a href="users.php" class="btn btn-outline" style="font-size: 0.85rem;">Reset All Filters</a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <table class="users-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>Account Status</th>
                                    <th>Profile Context</th>
                                    <th>Reports</th>
                                    <th>Joined</th>
                                    <th style="text-align: right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $u): ?>
                                    <?php 
                                        $initials = strtoupper(substr($u['full_name'] ?? 'U', 0, 2));
                                        $is_self = ((int)$u['id'] === $current_admin_id);
                                        $is_suspended = ($u['status'] === 'suspended');
                                        
                                        // Profile links
                                        $profile_url = null;
                                        if ($u['role'] === 'freelancer' && !empty($u['freelancer_profile_id'])) {
                                            $profile_url = "../guest/freelancer-profile.php?id=" . (int)$u['freelancer_profile_id'];
                                        } elseif ($u['role'] === 'client' && !empty($u['client_profile_id'])) {
                                            $profile_url = "../guest/client-profile.php?id=" . (int)$u['client_profile_id'];
                                        }

                                        // Role avatar style
                                        $avatar_class = 'role-' . htmlspecialchars($u['role']);
                                        if ($is_suspended) {
                                            $avatar_class = 'status-suspended';
                                        }
                                    ?>
                                    <tr class="<?= $is_suspended ? 'row-suspended' : '' ?>">
                                        <!-- User Identity -->
                                        <td>
                                            <div class="user-identity-cell">
                                                <div class="user-avatar-circle <?= $avatar_class ?>">
                                                    <?= htmlspecialchars($initials) ?>
                                                </div>
                                                <div class="user-names-wrap">
                                                    <div>
                                                        <?php if ($profile_url): ?>
                                                            <a href="<?= htmlspecialchars($profile_url) ?>" target="_blank" class="user-primary-name" title="View Public Profile">
                                                                <?= htmlspecialchars($u['full_name']) ?>
                                                                <i data-lucide="external-link" style="width: 12px; height: 12px; opacity: 0.7;"></i>
                                                            </a>
                                                        <?php else: ?>
                                                            <span class="user-primary-name"><?= htmlspecialchars($u['full_name']) ?></span>
                                                        <?php endif; ?>
                                                        <span class="user-id-pill">#<?= $u['id'] ?></span>
                                                        <?php if ($is_self): ?>
                                                            <span style="font-size: 0.7rem; color: #a855f7; font-weight: 600; margin-left: 4px;">(You)</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <span class="user-email-text"><?= htmlspecialchars($u['email']) ?></span>
                                                </div>
                                            </div>
                                        </td>

                                        <!-- Role -->
                                        <td>
                                            <span class="role-badge-custom <?= htmlspecialchars($u['role']) ?>">
                                                <?php if ($u['role'] === 'admin'): ?>
                                                    <i data-lucide="shield" style="width: 12px; height: 12px;"></i>
                                                <?php elseif ($u['role'] === 'client'): ?>
                                                    <i data-lucide="briefcase" style="width: 12px; height: 12px;"></i>
                                                <?php else: ?>
                                                    <i data-lucide="code" style="width: 12px; height: 12px;"></i>
                                                <?php endif; ?>
                                                <?= ucfirst($u['role']) ?>
                                            </span>
                                        </td>

                                        <!-- Status -->
                                        <td>
                                            <?= get_status_badge($u['status']) ?>
                                        </td>

                                        <!-- Profile Context / Stats -->
                                        <td>
                                            <div class="user-context-meta">
                                                <?php if ($u['role'] === 'freelancer'): ?>
                                                    <span class="user-context-title"><?= htmlspecialchars($u['freelancer_title'] ?: 'Freelancer') ?></span>
                                                    <span><?= (int)$u['skill_count'] ?> tagged skills</span>
                                                <?php elseif ($u['role'] === 'client'): ?>
                                                    <span class="user-context-title"><?= htmlspecialchars($u['company_name'] ?: 'Employer') ?></span>
                                                    <span><?= (int)$u['project_count'] ?> jobs posted</span>
                                                <?php else: ?>
                                                    <span style="color: var(--text-secondary);">System Administrator</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>

                                        <!-- Reports -->
                                        <td>
                                            <?php if ((int)$u['total_reports_count'] > 0): ?>
                                                <a href="profile-reports.php?search=<?= urlencode($u['email']) ?>" 
                                                   class="report-alert-badge <?= (int)$u['pending_reports_count'] > 0 ? 'has-pending' : 'resolved-only' ?>"
                                                   title="Click to view reports filed against this user">
                                                    <i data-lucide="flag" style="width: 12px; height: 12px;"></i>
                                                    <?= (int)$u['pending_reports_count'] > 0 ? (int)$u['pending_reports_count'] . ' Pending' : (int)$u['total_reports_count'] . ' Reported' ?>
                                                </a>
                                            <?php else: ?>
                                                <span style="color: var(--text-secondary); font-size: 0.8rem;">Clean</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Joined Date -->
                                        <td>
                                            <div class="date-cell-wrap">
                                                <span class="date-primary"><?= date('M j, Y', strtotime($u['created_at'])) ?></span>
                                                <span class="date-timeago"><?= time_ago($u['created_at']) ?></span>
                                            </div>
                                        </td>

                                        <!-- Actions -->
                                        <td style="text-align: right;">
                                            <div class="user-action-group" style="justify-content: flex-end;">
                                                <?php if ($profile_url): ?>
                                                    <a href="<?= htmlspecialchars($profile_url) ?>" target="_blank" class="btn-action-icon" title="View Public Profile">
                                                        <i data-lucide="eye" style="width: 15px; height: 15px;"></i>
                                                    </a>
                                                <?php endif; ?>

                                                <?php if ($is_self): ?>
                                                    <button type="button" class="btn-action-state btn-self-disabled" title="You cannot suspend your own administrative account" disabled>
                                                        <i data-lucide="shield-check" style="width: 14px; height: 14px;"></i> Protected
                                                    </button>
                                                <?php elseif ($is_suspended): ?>
                                                    <button type="button" class="btn-action-state btn-activate" 
                                                            onclick="openActivateModal(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['full_name'])) ?>', '<?= htmlspecialchars($u['role']) ?>')">
                                                        <i data-lucide="user-check" style="width: 14px; height: 14px;"></i> Activate
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn-action-state btn-suspend" 
                                                            onclick="openSuspendModal(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['full_name'])) ?>', '<?= htmlspecialchars($u['role']) ?>')">
                                                        <i data-lucide="user-x" style="width: 14px; height: 14px;"></i> Suspend
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Pagination footer -->
                <?php if ($total_matching > 0): ?>
                    <div style="padding: 16px 20px; border-top: 1px solid var(--border-color, #334155);">
                        <?= render_pagination($pag, 'users.php') ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Modal: Suspend User Confirmation -->
    <div id="suspendModal" class="user-modal-backdrop">
        <div class="user-modal-box">
            <div class="user-modal-header">
                <h4><i data-lucide="alert-triangle" style="color: #ef4444; width: 20px;"></i> Suspend User Account</h4>
                <button type="button" class="user-modal-close" onclick="closeSuspendModal()">&times;</button>
            </div>
            <form method="POST" action="users.php">
                <input type="hidden" name="user_id" id="suspendUserId">
                <input type="hidden" name="action" value="suspend">
                <input type="hidden" name="return_search" value="<?= htmlspecialchars($search) ?>">
                <input type="hidden" name="return_role" value="<?= htmlspecialchars($role_filter) ?>">
                <input type="hidden" name="return_status" value="<?= htmlspecialchars($status_filter) ?>">
                <input type="hidden" name="return_reported" value="<?= (int)$reported_filter ?>">
                <input type="hidden" name="return_sort" value="<?= htmlspecialchars($sort) ?>">
                <input type="hidden" name="return_page" value="<?= (int)$page ?>">

                <div class="user-modal-body">
                    <p>Are you sure you want to suspend this user account? Suspended users will be immediately logged out and blocked from logging in or conducting transactions.</p>
                    
                    <div class="user-modal-target-card">
                        <i data-lucide="user-x" style="color: #ef4444; width: 28px; height: 28px; flex-shrink: 0;"></i>
                        <div>
                            <div style="font-weight: 600; color: #f8fafc;" id="suspendUserName">User Name</div>
                            <div style="font-size: 0.8rem; color: #94a3b8;" id="suspendUserMeta">Role: Freelancer • ID: #0</div>
                        </div>
                    </div>
                </div>
                <div class="user-modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeSuspendModal()">Cancel</button>
                    <button type="submit" class="btn btn-danger" style="background: #ef4444; color: white;">
                        <i data-lucide="user-x" style="width: 15px;"></i> Confirm Suspension
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Activate User Confirmation -->
    <div id="activateModal" class="user-modal-backdrop">
        <div class="user-modal-box">
            <div class="user-modal-header">
                <h4><i data-lucide="check-circle" style="color: #22c55e; width: 20px;"></i> Activate User Account</h4>
                <button type="button" class="user-modal-close" onclick="closeActivateModal()">&times;</button>
            </div>
            <form method="POST" action="users.php">
                <input type="hidden" name="user_id" id="activateUserId">
                <input type="hidden" name="action" value="activate">
                <input type="hidden" name="return_search" value="<?= htmlspecialchars($search) ?>">
                <input type="hidden" name="return_role" value="<?= htmlspecialchars($role_filter) ?>">
                <input type="hidden" name="return_status" value="<?= htmlspecialchars($status_filter) ?>">
                <input type="hidden" name="return_reported" value="<?= (int)$reported_filter ?>">
                <input type="hidden" name="return_sort" value="<?= htmlspecialchars($sort) ?>">
                <input type="hidden" name="return_page" value="<?= (int)$page ?>">

                <div class="user-modal-body">
                    <p>Are you sure you want to restore and activate this user account? The user will regain immediate access to their profile and platform functions.</p>
                    
                    <div class="user-modal-target-card">
                        <i data-lucide="user-check" style="color: #22c55e; width: 28px; height: 28px; flex-shrink: 0;"></i>
                        <div>
                            <div style="font-weight: 600; color: #f8fafc;" id="activateUserName">User Name</div>
                            <div style="font-size: 0.8rem; color: #94a3b8;" id="activateUserMeta">Role: Freelancer • ID: #0</div>
                        </div>
                    </div>
                </div>
                <div class="user-modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeActivateModal()">Cancel</button>
                    <button type="submit" class="btn btn-success" style="background: #22c55e; color: white;">
                        <i data-lucide="user-check" style="width: 15px;"></i> Restore Access
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        lucide.createIcons();

        function openSuspendModal(userId, userName, role) {
            document.getElementById('suspendUserId').value = userId;
            document.getElementById('suspendUserName').textContent = userName;
            document.getElementById('suspendUserMeta').textContent = 'Role: ' + role.charAt(0).toUpperCase() + role.slice(1) + ' • ID: #' + userId;
            document.getElementById('suspendModal').classList.add('active');
            lucide.createIcons();
        }

        function closeSuspendModal() {
            document.getElementById('suspendModal').classList.remove('active');
        }

        function openActivateModal(userId, userName, role) {
            document.getElementById('activateUserId').value = userId;
            document.getElementById('activateUserName').textContent = userName;
            document.getElementById('activateUserMeta').textContent = 'Role: ' + role.charAt(0).toUpperCase() + role.slice(1) + ' • ID: #' + userId;
            document.getElementById('activateModal').classList.add('active');
            lucide.createIcons();
        }

        function closeActivateModal() {
            document.getElementById('activateModal').classList.remove('active');
        }

        // Close modals on escape key or clicking backdrop
        window.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeSuspendModal();
                closeActivateModal();
            }
        });

        document.getElementById('suspendModal').addEventListener('click', function(e) {
            if (e.target === this) closeSuspendModal();
        });

        document.getElementById('activateModal').addEventListener('click', function(e) {
            if (e.target === this) closeActivateModal();
        });
    </script>
</body>
</html>
