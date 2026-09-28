<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';
require_role('admin');

// Platform Statistics
$users_by_role_res = $conn->query("SELECT role, status, COUNT(*) as count FROM users GROUP BY role, status");
$users_by_role = $users_by_role_res ? $users_by_role_res->fetch_all(MYSQLI_ASSOC) : [];

$projects_by_status_res = $conn->query("SELECT status, COUNT(*) as count, SUM(budget_max) as total_budget FROM projects GROUP BY status");
$projects_by_status = $projects_by_status_res ? $projects_by_status_res->fetch_all(MYSQLI_ASSOC) : [];

$contracts_summary_res = $conn->query("SELECT COUNT(*) as total_contracts, SUM(total_budget) as total_value, SUM(paid_to_date) as total_paid FROM contracts");
$contracts_summary = $contracts_summary_res ? $contracts_summary_res->fetch_assoc() : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - FreeMark Admin</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/admin/layout.css">
    <link rel="stylesheet" href="../css/admin/components.css">
    <link rel="stylesheet" href="../css/admin/reports.css">
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
                <li><a href="users.php"><i data-lucide="users"></i> User Management</a></li>
                <li><a href="reports.php" class="active"><i data-lucide="file-text"></i> Reports</a></li>
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
                <h2>Reports & Analytics</h2>
                <p>Platform overview and database summary</p>
            </div>
            <button onclick="window.print()" class="btn btn-primary" style="display:flex; align-items:center; gap:8px;">
                <i data-lucide="printer" style="width:18px;"></i> Print Summary
            </button>
        </header>

        <div class="admin-content">
            <!-- User Summary -->
            <div class="admin-card" style="margin-bottom: 25px;">
                <div class="card-header">
                    <h3>User Accounts Breakdown</h3>
                </div>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Total Accounts</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users_by_role as $row): ?>
                            <tr>
                                <td><strong><?= ucfirst($row['role']) ?></strong></td>
                                <td><?= get_status_badge($row['status']) ?></td>
                                <td><?= (int)$row['count'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Projects Summary -->
            <div class="admin-card" style="margin-bottom: 25px;">
                <div class="card-header">
                    <h3>Projects Summary</h3>
                </div>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Total Projects</th>
                            <th>Combined Budget</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($projects_by_status as $row): ?>
                            <tr>
                                <td><?= get_status_badge($row['status']) ?></td>
                                <td><?= (int)$row['count'] ?></td>
                                <td><?= format_currency($row['total_budget'] ?? 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Financial / Contracts Summary -->
            <div class="admin-card">
                <div class="card-header">
                    <h3>Financial & Contract Volume</h3>
                </div>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Total Contracts</th>
                            <th>Contracted Value</th>
                            <th>Total Disbursed to Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?= (int)($contracts_summary['total_contracts'] ?? 0) ?></td>
                            <td><?= format_currency($contracts_summary['total_value'] ?? 0) ?></td>
                            <td><?= format_currency($contracts_summary['total_paid'] ?? 0) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
    <script>lucide.createIcons();</script>
</body>
</html>
