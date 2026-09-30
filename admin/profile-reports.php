<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';
require_role('admin');

$admin_user_id = (int)current_user_id();
$flash_success = '';
$flash_error = '';

// Handle Admin Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $report_id = (int)($_POST['report_id'] ?? 0);
    $admin_notes = trim($_POST['admin_notes'] ?? '');

    if ($action === 'suspend_user') {
        $target_user_id = (int)($_POST['target_user_id'] ?? 0);
        if ($target_user_id > 0) {
            // Suspend user
            $sus_stmt = $conn->prepare("UPDATE users SET status = 'suspended' WHERE id = ?");
            $sus_stmt->bind_param("i", $target_user_id);
            $sus_stmt->execute();

            // Mark report as action_taken
            if ($report_id > 0) {
                $rep_stmt = $conn->prepare("UPDATE profile_reports SET status = 'action_taken', admin_notes = COALESCE(NULLIF(?, ''), admin_notes), resolved_by = ?, resolved_at = NOW() WHERE id = ?");
                $rep_stmt->bind_param("sii", $admin_notes, $admin_user_id, $report_id);
                $rep_stmt->execute();
            }
            $flash_success = "User account has been suspended and report #$report_id marked as Action Taken.";
        }
    } elseif ($action === 'reactivate_user') {
        $target_user_id = (int)($_POST['target_user_id'] ?? 0);
        if ($target_user_id > 0) {
            $act_stmt = $conn->prepare("UPDATE users SET status = 'active' WHERE id = ?");
            $act_stmt->bind_param("i", $target_user_id);
            $act_stmt->execute();
            $flash_success = "User account #$target_user_id has been reactivated.";
        }
    } elseif ($action === 'dismiss_report') {
        if ($report_id > 0) {
            $rep_stmt = $conn->prepare("UPDATE profile_reports SET status = 'dismissed', admin_notes = COALESCE(NULLIF(?, ''), admin_notes), resolved_by = ?, resolved_at = NOW() WHERE id = ?");
            $rep_stmt->bind_param("sii", $admin_notes, $admin_user_id, $report_id);
            $rep_stmt->execute();
            $flash_success = "Report #$report_id has been dismissed.";
        }
    } elseif ($action === 'mark_reviewed') {
        if ($report_id > 0) {
            $rep_stmt = $conn->prepare("UPDATE profile_reports SET status = 'reviewed', admin_notes = COALESCE(NULLIF(?, ''), admin_notes), resolved_by = ?, resolved_at = NOW() WHERE id = ?");
            $rep_stmt->bind_param("sii", $admin_notes, $admin_user_id, $report_id);
            $rep_stmt->execute();
            $flash_success = "Report #$report_id has been marked as Reviewed.";
        }
    } elseif ($action === 'update_notes') {
        if ($report_id > 0) {
            $rep_stmt = $conn->prepare("UPDATE profile_reports SET admin_notes = ? WHERE id = ?");
            $rep_stmt->bind_param("si", $admin_notes, $report_id);
            $rep_stmt->execute();
            $flash_success = "Admin notes for Report #$report_id have been updated.";
        }
    }
}

// KPI Statistics
$kpi_res = $conn->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'action_taken' THEN 1 ELSE 0 END) as action_taken,
        SUM(CASE WHEN status = 'reviewed' THEN 1 ELSE 0 END) as reviewed,
        SUM(CASE WHEN status = 'dismissed' THEN 1 ELSE 0 END) as dismissed
    FROM profile_reports
");
$kpi = $kpi_res ? $kpi_res->fetch_assoc() : [];
$total_reports = (int)($kpi['total'] ?? 0);
$total_pending = (int)($kpi['pending'] ?? 0);
$total_action_taken = (int)($kpi['action_taken'] ?? 0);
$total_resolved = (int)(($kpi['reviewed'] ?? 0) + ($kpi['dismissed'] ?? 0));

// Pending Approvals Count for Sidebar
$pending_users_cnt_res = $conn->query("SELECT COUNT(*) FROM users WHERE status = 'pending'");
$total_pending_approvals = $pending_users_cnt_res ? (int)$pending_users_cnt_res->fetch_row()[0] : 0;

// Filter Parameters
$status_filter = strtolower(trim($_GET['status'] ?? 'all'));
$allowed_statuses = ['all', 'pending', 'action_taken', 'reviewed', 'dismissed'];
if (!in_array($status_filter, $allowed_statuses)) {
    $status_filter = 'all';
}

$type_filter = strtolower(trim($_GET['type'] ?? 'all'));
$allowed_types = ['all', 'freelancer', 'client'];
if (!in_array($type_filter, $allowed_types)) {
    $type_filter = 'all';
}

$search_query = trim($_GET['q'] ?? '');

// Build WHERE Clauses
$where = ["1=1"];
$params = [];
$types = "";

if ($status_filter !== 'all') {
    $where[] = "pr.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($type_filter !== 'all') {
    $where[] = "pr.target_type = ?";
    $params[] = $type_filter;
    $types .= "s";
}

if ($search_query !== '') {
    $where[] = "(t_u.full_name LIKE ? OR t_u.email LIKE ? OR r_u.full_name LIKE ? OR r_u.email LIKE ? OR pr.details LIKE ? OR pr.reason LIKE ?)";
    $like = '%' . $search_query . '%';
    for ($i = 0; $i < 6; $i++) {
        $params[] = $like;
        $types .= "s";
    }
}

// Count total matching reports
$count_sql = "
    SELECT COUNT(*) 
    FROM profile_reports pr
    JOIN users r_u ON pr.reporter_id = r_u.id
    JOIN users t_u ON pr.reported_user_id = t_u.id
    WHERE " . implode(' AND ', $where);

$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$filtered_count = (int)$count_stmt->get_result()->fetch_row()[0];

// Pagination (8 per page)
$pag = paginate($filtered_count, 8);

// Query Reports with Joined User and Profile Details
$sql = "
    SELECT 
        pr.*,
        r_u.full_name as reporter_name,
        r_u.email as reporter_email,
        r_u.role as reporter_role,
        t_u.full_name as target_user_name,
        t_u.email as target_user_email,
        t_u.status as target_user_status,
        t_u.role as target_user_role,
        cp.company_name as client_company_name,
        fp.title as freelancer_title,
        admin_u.full_name as resolved_admin_name
    FROM profile_reports pr
    JOIN users r_u ON pr.reporter_id = r_u.id
    JOIN users t_u ON pr.reported_user_id = t_u.id
    LEFT JOIN client_profiles cp ON (pr.target_type = 'client' AND pr.target_profile_id = cp.id)
    LEFT JOIN freelancer_profiles fp ON (pr.target_type = 'freelancer' AND pr.target_profile_id = fp.id)
    LEFT JOIN users admin_u ON pr.resolved_by = admin_u.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY (pr.status = 'pending') DESC, pr.created_at DESC
    LIMIT ? OFFSET ?
";

$paged_params = $params;
$paged_params[] = $pag['per_page'];
$paged_params[] = $pag['offset'];
$paged_types = $types . "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($paged_types, ...$paged_params);
$stmt->execute();
$reports_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Reports - FreeMark Admin</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/admin/layout.css">
    <link rel="stylesheet" href="../css/admin/components.css">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        .report-filter-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 22px;
            flex-wrap: wrap;
        }
        .report-tabs {
            display: flex;
            gap: 6px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 4px;
        }
        .report-tab-btn {
            padding: 6px 14px;
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--text-secondary);
            border-radius: 6px;
            text-decoration: none;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .report-tab-btn:hover {
            color: var(--text-primary);
        }
        .report-tab-btn.active {
            background: var(--bg-primary);
            color: var(--accent);
            font-weight: 600;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        .report-tab-badge {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
            font-size: 0.72rem;
            padding: 1px 6px;
            border-radius: 10px;
            font-weight: 700;
        }
        .modal-action-box {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 16px;
        }
        .modal-content-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            width: 100%;
            max-width: 480px;
            padding: 24px;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5);
        }
    </style>
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
                <li><a href="dashboard.php"><i data-lucide="layout-dashboard"></i> Overview</a></li>
                <li><a href="approvals.php"><i data-lucide="check-circle"></i> Approvals</a></li>
                <li><a href="skills.php"><i data-lucide="award"></i> Skill Categories</a></li>
                <li><a href="quizzes.php"><i data-lucide="help-circle"></i> Quiz Banks</a></li>
                <li><a href="users.php"><i data-lucide="users"></i> User Management</a></li>
                <li><a href="reports.php"><i data-lucide="file-text"></i> Analytics</a></li>
                <li><a href="profile-reports.php" class="active"><i data-lucide="flag"></i> Profile Reports</a></li>
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
                <h2>Suspicious Profile Reports</h2>
                <p>Investigate reported user misconduct, scams, and policy violations</p>
            </div>
            <form class="form-inline" style="padding: 0; align-items: center; margin: 0;" method="GET" action="profile-reports.php">
                <?php if ($status_filter !== 'all'): ?>
                    <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
                <?php endif; ?>
                <?php if ($type_filter !== 'all'): ?>
                    <input type="hidden" name="type" value="<?= htmlspecialchars($type_filter) ?>">
                <?php endif; ?>
                <input type="text" name="q" placeholder="Search reports..." value="<?= htmlspecialchars($search_query) ?>">
                <button type="submit" class="btn btn-primary" style="padding: 10px 15px;"><i data-lucide="search" style="width: 18px;"></i></button>
            </form>
        </header>

        <div class="admin-content">
            <?php if (!empty($flash_success)): ?>
                <div class="alert alert-success" style="background: rgba(34, 197, 94, 0.15); border: 1px solid rgba(34, 197, 94, 0.3); color: #22c55e; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                    <i data-lucide="check-circle" style="width: 20px; height: 20px; flex-shrink: 0;"></i>
                    <span><?= htmlspecialchars($flash_success) ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($flash_error)): ?>
                <div class="alert alert-danger" style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                    <i data-lucide="alert-circle" style="width: 20px; height: 20px; flex-shrink: 0;"></i>
                    <span><?= htmlspecialchars($flash_error) ?></span>
                </div>
            <?php endif; ?>

            <!-- Stats KPI Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i data-lucide="flag" style="color: var(--accent);"></i></div>
                    <div class="stat-value"><?= $total_reports ?></div>
                    <div class="stat-label">Total Reports Filed</div>
                </div>
                <div class="stat-card" style="<?= $total_pending > 0 ? 'border-color: rgba(239, 68, 68, 0.4);' : '' ?>">
                    <div class="stat-icon"><i data-lucide="alert-circle" style="color: #ef4444;"></i></div>
                    <div class="stat-value" style="color: #ef4444;"><?= $total_pending ?></div>
                    <div class="stat-label">Pending Investigation</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i data-lucide="shield-alert" style="color: #f97316;"></i></div>
                    <div class="stat-value"><?= $total_action_taken ?></div>
                    <div class="stat-label">Action Taken (Suspensions)</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i data-lucide="check-check" style="color: #22c55e;"></i></div>
                    <div class="stat-value"><?= $total_resolved ?></div>
                    <div class="stat-label">Dismissed / Reviewed</div>
                </div>
            </div>

            <!-- Filter Controls & Tabs -->
            <div class="report-filter-nav">
                <div class="report-tabs">
                    <a href="<?= build_filter_url('profile-reports.php', 'status') ?>" class="report-tab-btn <?= $status_filter === 'all' ? 'active' : '' ?>">
                        All Reports (<?= $total_reports ?>)
                    </a>
                    <a href="profile-reports.php?status=pending<?= $type_filter !== 'all' ? '&type=' . urlencode($type_filter) : '' ?><?= $search_query !== '' ? '&q=' . urlencode($search_query) : '' ?>" class="report-tab-btn <?= $status_filter === 'pending' ? 'active' : '' ?>">
                        Pending
                        <?php if ($total_pending > 0): ?>
                            <span class="report-tab-badge"><?= $total_pending ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="profile-reports.php?status=action_taken<?= $type_filter !== 'all' ? '&type=' . urlencode($type_filter) : '' ?><?= $search_query !== '' ? '&q=' . urlencode($search_query) : '' ?>" class="report-tab-btn <?= $status_filter === 'action_taken' ? 'active' : '' ?>">
                        Action Taken
                    </a>
                    <a href="profile-reports.php?status=reviewed<?= $type_filter !== 'all' ? '&type=' . urlencode($type_filter) : '' ?><?= $search_query !== '' ? '&q=' . urlencode($search_query) : '' ?>" class="report-tab-btn <?= $status_filter === 'reviewed' ? 'active' : '' ?>">
                        Reviewed
                    </a>
                    <a href="profile-reports.php?status=dismissed<?= $type_filter !== 'all' ? '&type=' . urlencode($type_filter) : '' ?><?= $search_query !== '' ? '&q=' . urlencode($search_query) : '' ?>" class="report-tab-btn <?= $status_filter === 'dismissed' ? 'active' : '' ?>">
                        Dismissed
                    </a>
                </div>

                <div style="display: flex; gap: 10px; align-items: center;">
                    <label style="font-size: 0.85rem; color: var(--text-secondary); margin: 0;">Target Type:</label>
                    <select onchange="location = this.value;" style="padding: 7px 12px; border-radius: 6px; background: var(--bg-card); border: 1px solid var(--border-color); color: var(--text-primary); font-size: 0.85rem;">
                        <option value="profile-reports.php?type=all<?= $status_filter !== 'all' ? '&status=' . urlencode($status_filter) : '' ?><?= $search_query !== '' ? '&q=' . urlencode($search_query) : '' ?>" <?= $type_filter === 'all' ? 'selected' : '' ?>>All Profile Types</option>
                        <option value="profile-reports.php?type=freelancer<?= $status_filter !== 'all' ? '&status=' . urlencode($status_filter) : '' ?><?= $search_query !== '' ? '&q=' . urlencode($search_query) : '' ?>" <?= $type_filter === 'freelancer' ? 'selected' : '' ?>>Freelancers Only</option>
                        <option value="profile-reports.php?type=client<?= $status_filter !== 'all' ? '&status=' . urlencode($status_filter) : '' ?><?= $search_query !== '' ? '&q=' . urlencode($search_query) : '' ?>" <?= $type_filter === 'client' ? 'selected' : '' ?>>Clients Only</option>
                    </select>
                </div>
            </div>

            <!-- Reports List Feed -->
            <div class="admin-card">
                <div class="card-header">
                    <h3>Filtered Profile Reports (<?= $filtered_count ?>)</h3>
                    <?php if ($search_query !== '' || $status_filter !== 'all' || $type_filter !== 'all'): ?>
                        <a href="profile-reports.php" style="color: var(--accent); font-size: 0.85rem; text-decoration: none;">&times; Clear All Filters</a>
                    <?php endif; ?>
                </div>

                <div style="padding: 20px;">
                    <?php if (empty($reports_list)): ?>
                        <div style="text-align: center; padding: 40px 20px; color: var(--text-secondary);">
                            <i data-lucide="shield-check" style="width: 44px; height: 44px; margin-bottom: 12px; opacity: 0.5; color: #22c55e;"></i>
                            <h4 style="margin: 0 0 6px 0; color: var(--text-primary);">No Profile Reports Found</h4>
                            <p style="margin: 0; font-size: 0.9rem;">There are no reported profiles matching your selected criteria.</p>
                        </div>
                    <?php else: ?>
                        <div class="report-feed">
                            <?php foreach ($reports_list as $rep): ?>
                                <?php
                                    $is_target_suspended = ($rep['target_user_status'] === 'suspended');
                                    $target_display_name = $rep['target_type'] === 'client' && !empty($rep['client_company_name'])
                                        ? $rep['client_company_name']
                                        : $rep['target_user_name'];
                                    $profile_url = $rep['target_type'] === 'client'
                                        ? "../guest/client-profile.php?id=" . (int)$rep['target_profile_id']
                                        : "../guest/freelancer-profile.php?id=" . (int)$rep['target_profile_id'];
                                    $target_initials = strtoupper(substr($target_display_name, 0, 2));
                                ?>
                                <div class="report-item-card <?= htmlspecialchars($rep['status']) ?>">
                                    <div class="report-card-header">
                                        <div class="report-target-meta">
                                            <div class="avatar <?= $rep['target_type'] === 'client' ? 'purple' : 'yellow' ?>" style="<?= $is_target_suspended ? 'background:#ef4444; color:white;' : '' ?>">
                                                <?= $target_initials ?>
                                            </div>
                                            <div>
                                                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                                    <h4 style="margin: 0; font-size: 1.05rem;">
                                                        <?= htmlspecialchars($target_display_name) ?>
                                                    </h4>
                                                    <span class="role-badge" style="background: <?= $rep['target_type'] === 'client' ? 'rgba(168, 85, 247, 0.2); color: #c084fc;' : 'rgba(234, 179, 8, 0.2); color: #facc15;' ?>">
                                                        <?= ucfirst($rep['target_type']) ?>
                                                    </span>
                                                    <?php if ($is_target_suspended): ?>
                                                        <span style="font-size: 0.72rem; padding: 2px 6px; border-radius: 4px; background: rgba(239, 68, 68, 0.2); color: #ef4444; font-weight: 700;">
                                                            SUSPENDED
                                                        </span>
                                                    <?php else: ?>
                                                        <span style="font-size: 0.72rem; padding: 2px 6px; border-radius: 4px; background: rgba(34, 197, 94, 0.15); color: #22c55e; font-weight: 600;">
                                                            Active
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <div style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 2px;">
                                                    <span><?= htmlspecialchars($rep['target_user_email']) ?></span> &bull; 
                                                    <a href="<?= $profile_url ?>" target="_blank" style="color: var(--accent); text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                                                        View Public Profile <i data-lucide="external-link" style="width: 12px; height: 12px;"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        </div>

                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <?= get_status_badge($rep['status']) ?>
                                            <span style="font-size: 0.8rem; color: var(--text-secondary);">
                                                Report #<?= $rep['id'] ?>
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Reporter & Reason Banner -->
                                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: 6px; padding: 8px 14px; margin-bottom: 12px; flex-wrap: wrap;">
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <span style="font-size: 0.82rem; color: var(--text-secondary);">Reported by:</span>
                                            <strong style="font-size: 0.85rem; color: var(--text-primary);"><?= htmlspecialchars($rep['reporter_name']) ?></strong>
                                            <span style="font-size: 0.75rem; color: var(--text-secondary);">(<?= htmlspecialchars($rep['reporter_email']) ?> &bull; <?= ucfirst($rep['reporter_role']) ?>)</span>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <span style="font-size: 0.82rem; color: var(--text-secondary);">Reason:</span>
                                            <?= get_report_reason_badge($rep['reason']) ?>
                                            <span style="font-size: 0.78rem; color: var(--text-secondary);"><?= time_ago($rep['created_at']) ?></span>
                                        </div>
                                    </div>

                                    <!-- Description Quote -->
                                    <div class="report-details-quote">
                                        <div style="font-size: 0.78rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-secondary); margin-bottom: 4px;">
                                            Report Details
                                        </div>
                                        <?= nl2br(htmlspecialchars($rep['details'])) ?>
                                    </div>

                                    <!-- Admin Notes Section -->
                                    <?php if (!empty($rep['admin_notes'])): ?>
                                        <div class="report-admin-notes-box">
                                            <strong style="display: flex; align-items: center; gap: 6px; margin-bottom: 2px;">
                                                <i data-lucide="message-square" style="width: 14px; height: 14px;"></i> Admin Notes:
                                            </strong>
                                            <?= nl2br(htmlspecialchars($rep['admin_notes'])) ?>
                                            <?php if (!empty($rep['resolved_admin_name'])): ?>
                                                <div style="font-size: 0.75rem; opacity: 0.8; margin-top: 4px;">
                                                    Resolved by <?= htmlspecialchars($rep['resolved_admin_name']) ?> &bull; <?= time_ago($rep['resolved_at']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Footer Actions -->
                                    <div class="report-card-footer">
                                        <div style="font-size: 0.8rem; color: var(--text-secondary);">
                                            Reported on <?= date('M j, Y H:i', strtotime($rep['created_at'])) ?>
                                        </div>

                                        <div class="report-actions-group">
                                            <!-- Note Editor Trigger -->
                                            <button type="button" class="btn-sm btn-dismiss-report" onclick="openNoteModal(<?= $rep['id'] ?>, '<?= htmlspecialchars(addslashes($rep['admin_notes'] ?? '')) ?>')">
                                                <i data-lucide="edit-3" style="width: 13px; height: 13px;"></i> <?= !empty($rep['admin_notes']) ? 'Edit Note' : 'Add Note' ?>
                                            </button>

                                            <!-- Suspension / Reactivation Form -->
                                            <?php if (!$is_target_suspended): ?>
                                                <button type="button" class="btn-suspend-report" onclick="openSuspendModal(<?= $rep['id'] ?>, <?= $rep['reported_user_id'] ?>, '<?= htmlspecialchars(addslashes($target_display_name)) ?>')">
                                                    <i data-lucide="user-x" style="width: 14px; height: 14px;"></i> Suspend User
                                                </button>
                                            <?php else: ?>
                                                <form method="POST" action="profile-reports.php" onsubmit="return confirm('Reactivate this user account?');">
                                                    <input type="hidden" name="action" value="reactivate_user">
                                                    <input type="hidden" name="target_user_id" value="<?= $rep['reported_user_id'] ?>">
                                                    <input type="hidden" name="report_id" value="<?= $rep['id'] ?>">
                                                    <button type="submit" class="btn-sm" style="border-color: #22c55e; color: #22c55e;">
                                                        <i data-lucide="user-check" style="width: 13px; height: 13px; vertical-align: middle;"></i> Reactivate User
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <!-- Dismiss Form -->
                                            <?php if ($rep['status'] !== 'dismissed'): ?>
                                                <form method="POST" action="profile-reports.php">
                                                    <input type="hidden" name="action" value="dismiss_report">
                                                    <input type="hidden" name="report_id" value="<?= $rep['id'] ?>">
                                                    <button type="submit" class="btn-dismiss-report" onclick="return confirm('Dismiss this report?');">
                                                        <i data-lucide="x-circle" style="width: 13px; height: 13px;"></i> Dismiss
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <!-- Mark Reviewed Form -->
                                            <?php if ($rep['status'] === 'pending'): ?>
                                                <form method="POST" action="profile-reports.php">
                                                    <input type="hidden" name="action" value="mark_reviewed">
                                                    <input type="hidden" name="report_id" value="<?= $rep['id'] ?>">
                                                    <button type="submit" class="btn-review-report">
                                                        <i data-lucide="check" style="width: 13px; height: 13px;"></i> Mark Reviewed
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($filtered_count > 0): ?>
                            <div style="margin-top: 24px;">
                                <?= render_pagination($pag, 'profile-reports.php') ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal: Suspend User Confirmation with Note -->
    <div id="suspendConfirmModal" class="modal-action-box" style="display: none;">
        <div class="modal-content-card">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="width: 36px; height: 36px; border-radius: 8px; background: rgba(239, 68, 68, 0.15); color: #ef4444; display: flex; align-items: center; justify-content: center;">
                        <i data-lucide="shield-alert" style="width: 20px; height: 20px;"></i>
                    </div>
                    <div>
                        <h3 style="margin: 0; font-size: 1.15rem; color: #ef4444;">Suspend User Account</h3>
                        <p style="margin: 2px 0 0 0; font-size: 0.8rem; color: var(--text-secondary);">Enforce platform safety violation</p>
                    </div>
                </div>
                <button type="button" onclick="closeSuspendModal()" style="background: none; border: none; color: var(--text-secondary); cursor: pointer;">
                    <i data-lucide="x" style="width: 18px; height: 18px;"></i>
                </button>
            </div>

            <p style="font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 12px; line-height: 1.5;">
                Are you sure you want to suspend <strong id="suspendUserName" style="color: var(--text-primary);"></strong>? Their account will be locked and this report will be marked as <strong>Action Taken</strong>.
            </p>

            <form method="POST" action="profile-reports.php">
                <input type="hidden" name="action" value="suspend_user">
                <input type="hidden" name="report_id" id="suspendReportId" value="">
                <input type="hidden" name="target_user_id" id="suspendTargetUserId" value="">

                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--text-primary); margin-bottom: 6px;">
                        Internal Admin Investigation Note (Optional)
                    </label>
                    <textarea name="admin_notes" rows="3" style="width: 100%; padding: 10px; border-radius: 6px; background: var(--bg-primary); border: 1px solid var(--border-color); color: var(--text-primary); font-size: 0.88rem; box-sizing: border-box;" placeholder="e.g. Chat logs confirmed fraud attempt..."></textarea>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn btn-outline btn-compact" onclick="closeSuspendModal()">Cancel</button>
                    <button type="submit" class="btn btn-compact" style="background: #ef4444; border-color: #ef4444; color: white;">Confirm Suspension</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Edit Admin Note -->
    <div id="noteModal" class="modal-action-box" style="display: none;">
        <div class="modal-content-card">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="width: 36px; height: 36px; border-radius: 8px; background: rgba(59, 130, 246, 0.15); color: #3b82f6; display: flex; align-items: center; justify-content: center;">
                        <i data-lucide="edit-3" style="width: 20px; height: 20px;"></i>
                    </div>
                    <div>
                        <h3 style="margin: 0; font-size: 1.15rem; color: var(--text-primary);">Admin Investigation Note</h3>
                        <p style="margin: 2px 0 0 0; font-size: 0.8rem; color: var(--text-secondary);">Add or update internal notes for this report</p>
                    </div>
                </div>
                <button type="button" onclick="closeNoteModal()" style="background: none; border: none; color: var(--text-secondary); cursor: pointer;">
                    <i data-lucide="x" style="width: 18px; height: 18px;"></i>
                </button>
            </div>

            <form method="POST" action="profile-reports.php">
                <input type="hidden" name="action" value="update_notes">
                <input type="hidden" name="report_id" id="noteReportId" value="">

                <div style="margin-bottom: 16px;">
                    <textarea name="admin_notes" id="noteTextArea" rows="4" style="width: 100%; padding: 10px; border-radius: 6px; background: var(--bg-primary); border: 1px solid var(--border-color); color: var(--text-primary); font-size: 0.88rem; box-sizing: border-box;" placeholder="Enter investigation notes, escrow review, or action log..."></textarea>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn btn-outline btn-compact" onclick="closeNoteModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-compact">Save Note</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openSuspendModal(reportId, targetUserId, targetName) {
            document.getElementById('suspendReportId').value = reportId;
            document.getElementById('suspendTargetUserId').value = targetUserId;
            document.getElementById('suspendUserName').textContent = targetName;
            document.getElementById('suspendConfirmModal').style.display = 'flex';
        }
        function closeSuspendModal() {
            document.getElementById('suspendConfirmModal').style.display = 'none';
        }

        function openNoteModal(reportId, currentNote) {
            document.getElementById('noteReportId').value = reportId;
            document.getElementById('noteTextArea').value = currentNote;
            document.getElementById('noteModal').style.display = 'flex';
        }
        function closeNoteModal() {
            document.getElementById('noteModal').style.display = 'none';
        }

        window.addEventListener('click', function(e) {
            const sm = document.getElementById('suspendConfirmModal');
            if (sm && e.target === sm) closeSuspendModal();
            const nm = document.getElementById('noteModal');
            if (nm && e.target === nm) closeNoteModal();
        });

        lucide.createIcons();
    </script>
</body>
</html>
