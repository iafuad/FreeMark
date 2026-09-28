<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';
require_role('freelancer');

$freelancer_id = get_profile_id($conn, current_user_id(), 'freelancer');

// Fetch contracts belonging to this freelancer
$stmt = $conn->prepare("SELECT c.*, p.title as project_title, cp.company_name, u.full_name as client_name
    FROM contracts c
    JOIN projects p ON c.project_id = p.id
    JOIN client_profiles cp ON c.client_id = cp.id
    JOIN users u ON cp.user_id = u.id
    WHERE c.freelancer_id = ?
    ORDER BY c.created_at DESC");
$stmt->bind_param("i", $freelancer_id);
$stmt->execute();
$contracts_res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$contracts = [];
foreach ($contracts_res as $cnt) {
    $m_stmt = $conn->prepare("SELECT * FROM milestones WHERE contract_id = ? ORDER BY id ASC");
    $m_stmt->bind_param("i", $cnt['id']);
    $m_stmt->execute();
    $cnt['milestones'] = $m_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $contracts[] = $cnt;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Contracts - FreeMark</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/freelancer/layout.css">
    <link rel="stylesheet" href="../css/freelancer/components.css">
    <link rel="stylesheet" href="../css/freelancer/work.css">
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
                <li><a href="dashboard.php"><i data-lucide="layout-dashboard"></i> Overview</a></li>
                <li><a href="profile.php"><i data-lucide="user"></i> My Profile</a></li>
                <li><a href="jobs.php"><i data-lucide="briefcase"></i> Find Jobs</a></li>
                <li><a href="tests.php"><i data-lucide="check-square"></i> Skill Tests</a></li>
                <li><a href="work.php" class="active"><i data-lucide="upload-cloud"></i> My Contracts</a></li>
                <li><a href="chat.php"><i data-lucide="message-square"></i> Messages</a></li>
            </ul>
        </nav>
        <div class="sidebar-footer">
            <a href="../logout.php" style="color: var(--text-secondary); text-decoration: none; display: flex; align-items: center; gap: 10px;">
                <i data-lucide="log-out"></i> Logout
            </a>
        </div>
    </aside>

    <main class="freelancer-main">
        <header class="freelancer-header">
            <div class="header-title">
                <h2>My Contracts & Milestones</h2>
                <p>Manage your active projects and submit work for payment</p>
            </div>
        </header>

        <div class="freelancer-content">
            <div class="card">
                <div class="card-header">
                    <h3>Active Contracts (<?= count($contracts) ?>)</h3>
                </div>
                <div class="card-body">
                    <?php if (empty($contracts)): ?>
                        <p style="padding: 20px; text-align: center; color: var(--text-secondary);">No contracts assigned yet. Check the job board and submit proposals!</p>
                    <?php else: ?>
                        <?php foreach ($contracts as $cnt): ?>
                            <div class="contract-block" style="margin-bottom: 25px; padding: 20px; border: 1px solid var(--border-color); border-radius: 8px;">
                                <div class="contract-title" style="font-size: 1.1rem; font-weight: 600; margin-bottom: 15px; display:flex; justify-content:space-between; align-items:center;">
                                    <div>
                                        <?= htmlspecialchars($cnt['project_title']) ?>
                                        <span class="contract-subtitle" style="font-weight: normal; color: var(--text-secondary); font-size: 0.95rem;">• Client: <?= htmlspecialchars($cnt['company_name'] ?: $cnt['client_name']) ?></span>
                                    </div>
                                    <div style="font-size: 0.9rem;">
                                        Total: <strong><?= format_currency($cnt['total_budget']) ?></strong> | Earned: <strong><?= format_currency($cnt['paid_to_date']) ?></strong> | Status: <?= get_status_badge($cnt['status']) ?>
                                    </div>
                                </div>

                                <?php foreach ($cnt['milestones'] as $m): ?>
                                    <div class="milestone-item" style="display:flex; justify-content:space-between; align-items:center; padding: 12px 15px; border-bottom: 1px solid var(--border-color);">
                                        <div class="milestone-info">
                                            <div class="job-title-wrapper" style="display:flex; align-items:center; gap:10px;">
                                                <h4 style="margin:0; font-size:0.95rem;"><?= htmlspecialchars($m['title']) ?></h4>
                                                <?= get_status_badge($m['status']) ?>
                                            </div>
                                            <div class="milestone-meta" style="font-size:0.85rem; color:var(--text-secondary); margin-top:4px;">
                                                Amount: <?= format_currency($m['amount']) ?>
                                                <?php if ($m['submitted_at']): ?>
                                                    • Submitted: <?= date('M j, Y H:i', strtotime($m['submitted_at'])) ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div>
                                            <?php if ($m['status'] === 'in_progress' || $m['status'] === 'changes_requested'): ?>
                                                <a href="submit-milestone.php?id=<?= $m['id'] ?>" class="btn btn-primary btn-sm">Submit Work</a>
                                            <?php elseif ($m['status'] === 'submitted'): ?>
                                                <span class="text-muted" style="font-size:0.85rem;">Under Review</span>
                                            <?php elseif ($m['status'] === 'approved'): ?>
                                                <span style="color: #22c55e; font-weight: 600; font-size:0.85rem;">✓ Paid</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
    <script>lucide.createIcons();</script>
</body>
</html>
