<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';
require_role('client');

$client_id = get_profile_id($conn, current_user_id(), 'client');

// Fetch active & completed contracts with their milestones
$stmt = $conn->prepare("SELECT c.*, p.title as project_title, u.full_name as freelancer_name, fp.id as freelancer_profile_id
    FROM contracts c
    JOIN projects p ON c.project_id = p.id
    JOIN freelancer_profiles fp ON c.freelancer_id = fp.id
    JOIN users u ON fp.user_id = u.id
    WHERE c.client_id = ?
    ORDER BY c.created_at DESC");
$stmt->bind_param("i", $client_id);
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
    <title>Work Approval - FreeMark</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/client/layout.css">
    <link rel="stylesheet" href="../css/client/components.css">
    <script src="https://unpkg.com/lucide@latest"></script>
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
                <li><a href="work-approval.php" class="active"><i data-lucide="check-square"></i> Work & Ratings</a></li>
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
                <h2>Contracts & Milestones</h2>
                <p>Manage your active contracts and review submitted work</p>
            </div>
        </header>

        <div class="client-content">
            <div class="card">
                <div class="card-header">
                    <h3>Your Contracts (<?= count($contracts) ?>)</h3>
                </div>
                <div class="card-body">
                    <?php if (empty($contracts)): ?>
                        <p style="padding: 20px; text-align: center; color: var(--text-secondary);">No contracts initiated yet. Accept an incoming proposal to start a contract.</p>
                    <?php else: ?>
                        <?php foreach ($contracts as $cnt): ?>
                            <div class="contract-block" style="margin-bottom: 25px; padding: 20px; border: 1px solid var(--border-color); border-radius: 8px;">
                                <div class="contract-title" style="font-size: 1.1rem; font-weight: 600; margin-bottom: 15px; display:flex; justify-content:space-between; align-items:center;">
                                    <div>
                                        <?= htmlspecialchars($cnt['project_title']) ?>
                                        <span class="contract-freelancer" style="font-weight: normal; color: var(--text-secondary); font-size: 0.95rem;">• Freelancer: <?= htmlspecialchars($cnt['freelancer_name']) ?></span>
                                    </div>
                                    <div style="font-size: 0.9rem;">
                                        Total: <strong><?= format_currency($cnt['total_budget']) ?></strong> | Paid: <strong><?= format_currency($cnt['paid_to_date']) ?></strong> | Status: <?= get_status_badge($cnt['status']) ?>
                                    </div>
                                </div>

                                <?php foreach ($cnt['milestones'] as $m): ?>
                                    <div class="milestone-item <?= $m['status'] === 'submitted' ? 'milestone-item-pending' : '' ?>" style="display:flex; justify-content:space-between; align-items:center; padding: 12px 15px; border-bottom: 1px solid var(--border-color);">
                                        <div class="milestone-info">
                                            <div class="milestone-heading" style="display:flex; align-items:center; gap:10px;">
                                                <h4 style="margin:0; font-size:0.95rem;"><?= htmlspecialchars($m['title']) ?></h4>
                                                <?= get_status_badge($m['status']) ?>
                                            </div>
                                            <div class="milestone-meta" style="font-size:0.85rem; color:var(--text-secondary); margin-top:4px;">
                                                Amount: <?= format_currency($m['amount']) ?>
                                                <?php if ($m['submitted_at']): ?>
                                                    • Submitted on <?= date('M j, Y H:i', strtotime($m['submitted_at'])) ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div>
                                            <?php if ($m['status'] === 'submitted'): ?>
                                                <a href="milestone-details.php?milestone_id=<?= $m['id'] ?>" class="btn btn-primary btn-sm">Review Work</a>
                                            <?php else: ?>
                                                <a href="milestone-details.php?milestone_id=<?= $m['id'] ?>" class="btn btn-outline btn-sm">View Details</a>
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
