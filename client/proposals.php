<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';
require_role('client');

$client_id = get_profile_id($conn, current_user_id(), 'client');

// Fetch client projects for filter
$projects_stmt = $conn->prepare("SELECT id, title FROM projects WHERE client_id = ? ORDER BY created_at DESC");
$projects_stmt->bind_param("i", $client_id);
$projects_stmt->execute();
$client_projects = $projects_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$selected_project_id = (int)($_GET['project_id'] ?? 0);

// Count proposals for selected project or all projects of client
if ($selected_project_id > 0) {
    $count_stmt = $conn->prepare("SELECT COUNT(*) FROM proposals p JOIN projects pr ON p.project_id = pr.id WHERE pr.client_id = ? AND p.project_id = ?");
    $count_stmt->bind_param("ii", $client_id, $selected_project_id);
} else {
    $count_stmt = $conn->prepare("SELECT COUNT(*) FROM proposals p JOIN projects pr ON p.project_id = pr.id WHERE pr.client_id = ?");
    $count_stmt->bind_param("i", $client_id);
}
$count_stmt->execute();
$total_proposals = (int)$count_stmt->get_result()->fetch_row()[0];

// Paginate (6 per page)
$pag = paginate($total_proposals, 6);

// Fetch proposals for selected project or all projects of client
if ($selected_project_id > 0) {
    $prop_stmt = $conn->prepare("SELECT p.*, pr.title as project_title, u.full_name as freelancer_name, fp.hourly_rate, fp.title as freelancer_title, fp.id as freelancer_profile_id
        FROM proposals p
        JOIN projects pr ON p.project_id = pr.id
        JOIN freelancer_profiles fp ON p.freelancer_id = fp.id
        JOIN users u ON fp.user_id = u.id
        WHERE pr.client_id = ? AND p.project_id = ?
        ORDER BY p.created_at DESC
        LIMIT ? OFFSET ?");
    $prop_stmt->bind_param("iiii", $client_id, $selected_project_id, $pag['per_page'], $pag['offset']);
} else {
    $prop_stmt = $conn->prepare("SELECT p.*, pr.title as project_title, u.full_name as freelancer_name, fp.hourly_rate, fp.title as freelancer_title, fp.id as freelancer_profile_id
        FROM proposals p
        JOIN projects pr ON p.project_id = pr.id
        JOIN freelancer_profiles fp ON p.freelancer_id = fp.id
        JOIN users u ON fp.user_id = u.id
        WHERE pr.client_id = ?
        ORDER BY p.created_at DESC
        LIMIT ? OFFSET ?");
    $prop_stmt->bind_param("iii", $client_id, $pag['per_page'], $pag['offset']);
}
$prop_stmt->execute();
$proposals = $prop_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Proposals - FreeMark</title>
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
                <li><a href="proposals.php" class="active"><i data-lucide="file-text"></i> Proposals</a></li>
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
                <h2>Review Proposals</h2>
                <p>Review and hire applicants for your projects</p>
            </div>
            <?php if (!empty($client_projects)): ?>
                <form method="GET" action="proposals.php">
                    <select name="project_id" class="proposal-filter" onchange="this.form.submit()">
                        <option value="0">All Projects</option>
                        <?php foreach ($client_projects as $cp): ?>
                            <option value="<?= $cp['id'] ?>" <?= $selected_project_id == $cp['id'] ? 'selected' : '' ?>>
                                Filter by: <?= htmlspecialchars($cp['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
        </header>

        <div class="client-content">
            <div class="card">
                <div class="card-header">
                    <h3>Incoming Applications</h3>
                    <span class="text-muted"><?= $total_proposals ?> Proposals</span>
                </div>
                <div class="card-body card-body-flush">
                    <?php if (empty($proposals)): ?>
                        <div style="padding: 30px; text-align: center; color: var(--text-secondary);">
                            No proposals received yet for this project.
                        </div>
                    <?php else: ?>
                        <?php foreach ($proposals as $prop): ?>
                            <div class="proposal-item" style="padding: 20px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <h4 style="margin: 0 0 6px 0;">
                                        <a href="review-proposal.php?id=<?= $prop['id'] ?>" style="color: var(--text-primary); text-decoration: none;">
                                            <?= htmlspecialchars($prop['freelancer_name']) ?>
                                        </a>
                                        <span style="font-size: 0.85rem; font-weight: normal; color: var(--text-secondary); margin-left: 8px;">
                                            <?= htmlspecialchars($prop['freelancer_title'] ?? 'Freelancer') ?>
                                        </span>
                                    </h4>
                                    <p style="margin: 0 0 8px 0; font-size: 0.9rem; color: var(--text-secondary);">
                                        Project: <strong><?= htmlspecialchars($prop['project_title']) ?></strong>
                                    </p>
                                    <div style="display: flex; gap: 15px; font-size: 0.85rem;">
                                        <span>Bid: <strong style="color: var(--accent);"><?= format_currency($prop['bid_amount']) ?></strong></span>
                                        <span>Duration: <strong><?= format_duration($prop['estimated_duration'] ?? '') ?></strong></span>
                                        <span>Status: <?= get_status_badge($prop['status']) ?></span>
                                    </div>
                                </div>
                                <div>
                                    <a href="review-proposal.php?id=<?= $prop['id'] ?>" class="btn btn-outline" style="text-decoration: none;">
                                        Review Application
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?= render_pagination($pag, 'proposals.php') ?>
        </div>
    </main>
    <script>lucide.createIcons();</script>
</body>
</html>
