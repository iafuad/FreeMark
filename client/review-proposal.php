<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';
require_role('client');

$client_id = get_profile_id($conn, current_user_id(), 'client');
$proposal_id = (int)($_GET['id'] ?? 0);

if ($proposal_id <= 0) {
    header("Location: proposals.php");
    exit;
}

// Fetch proposal details
$stmt = $conn->prepare("SELECT p.*, pr.title as project_title, pr.id as project_id, 
        fp.id as freelancer_profile_id, fp.hourly_rate, fp.title as freelancer_title, fp.bio as freelancer_bio,
        u.id as freelancer_user_id, u.full_name as freelancer_name, u.email as freelancer_email
    FROM proposals p
    JOIN projects pr ON p.project_id = pr.id
    JOIN freelancer_profiles fp ON p.freelancer_id = fp.id
    JOIN users u ON fp.user_id = u.id
    WHERE p.id = ? AND pr.client_id = ?");
$stmt->bind_param("ii", $proposal_id, $client_id);
$stmt->execute();
$proposal = $stmt->get_result()->fetch_assoc();

if (!$proposal) {
    die("Proposal not found or access denied.");
}

// Handle Accept & Decline actions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'accept' && $proposal['status'] === 'pending') {
        $conn->begin_transaction();
        try {
            // 1. Update proposal status
            $u_stmt = $conn->prepare("UPDATE proposals SET status = 'accepted' WHERE id = ?");
            $u_stmt->bind_param("i", $proposal_id);
            $u_stmt->execute();

            // 2. Update project status to in_progress
            $p_stmt = $conn->prepare("UPDATE projects SET status = 'in_progress' WHERE id = ?");
            $p_stmt->bind_param("i", $proposal['project_id']);
            $p_stmt->execute();

            // 3. Create contract
            $c_stmt = $conn->prepare("INSERT INTO contracts (project_id, client_id, freelancer_id, total_budget, status) VALUES (?, ?, ?, ?, 'active')");
            $c_stmt->bind_param("iiid", $proposal['project_id'], $client_id, $proposal['freelancer_profile_id'], $proposal['bid_amount']);
            $c_stmt->execute();
            $contract_id = $conn->insert_id;

            // 4. Create standard 2 milestones (40% and 60%)
            $m1_amount = round($proposal['bid_amount'] * 0.40, 2);
            $m2_amount = round($proposal['bid_amount'] - $m1_amount, 2);

            $m1_title = "Milestone 1: Core Implementation & Prototype";
            $m2_title = "Milestone 2: Final Integration & Delivery";

            $m_stmt = $conn->prepare("INSERT INTO milestones (contract_id, title, amount, status) VALUES (?, ?, ?, 'in_progress'), (?, ?, ?, 'in_progress')");
            $m_stmt->bind_param("isdisd", $contract_id, $m1_title, $m1_amount, $contract_id, $m2_title, $m2_amount);
            $m_stmt->execute();

            $conn->commit();
            header("Location: work-approval.php");
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error accepting proposal: " . $e->getMessage();
        }
    } elseif ($action === 'decline' && $proposal['status'] === 'pending') {
        $u_stmt = $conn->prepare("UPDATE proposals SET status = 'declined' WHERE id = ?");
        $u_stmt->bind_param("i", $proposal_id);
        $u_stmt->execute();
        header("Location: proposals.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Proposal - FreeMark</title>
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
                <li><a href="projects.php"><i data-lucide="briefcase"></i> My Projects</a></li>
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
                <h2>Review Proposal</h2>
                <p>Application for: <?= htmlspecialchars($proposal['project_title']) ?></p>
            </div>
            <a href="proposals.php" class="btn btn-outline">Back to Proposals</a>
        </header>

        <div class="client-content">
            <div class="client-split-layout">
                <div class="card client-split-main">
                    <div class="card-header">
                        <h3>Proposal Details</h3>
                        <div>Status: <?= get_status_badge($proposal['status']) ?></div>
                    </div>
                    <div class="card-body">
                        <div class="proposal-summary">
                            <div class="proposal-summary-item">
                                <h4>Proposed Rate</h4>
                                <p class="rate"><?= format_currency($proposal['bid_amount']) ?></p>
                            </div>
                            <div>
                                <h4>Estimated Timeline</h4>
                                <p class="timeline"><?= format_duration($proposal['estimated_duration'] ?? '') ?></p>
                            </div>
                        </div>

                        <div class="cover-letter-section">
                            <h4>Cover Letter</h4>
                            <div class="cover-letter">
                                <?= render_expandable_text($proposal['cover_letter'], 240, 4) ?>
                            </div>
                        </div>

                        <?php if ($proposal['status'] === 'pending'): ?>
                            <div class="review-actions" style="margin-top: 25px; display: flex; gap: 15px;">
                                <form method="POST" action="review-proposal.php?id=<?= $proposal['id'] ?>">
                                    <input type="hidden" name="action" value="accept">
                                    <button type="submit" class="btn btn-primary">Accept & Hire</button>
                                </form>
                                <a href="chat.php?with=<?= $proposal['freelancer_user_id'] ?>" class="btn btn-outline">Message</a>
                                <form method="POST" action="review-proposal.php?id=<?= $proposal['id'] ?>">
                                    <input type="hidden" name="action" value="decline">
                                    <button type="submit" class="btn btn-outline decline" style="color: #ef4444; border-color: #ef4444;">Decline</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <div style="margin-top: 20px;">
                                <a href="chat.php?with=<?= $proposal['freelancer_user_id'] ?>" class="btn btn-outline">Message Freelancer</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Freelancer Snapshot -->
                <div class="card client-split-sidebar">
                    <div class="card-header">
                        <h3>Applicant Snapshot</h3>
                    </div>
                    <div class="card-body profile-snapshot-body">
                        <div class="profile-avatar-lg profile-avatar-purple">
                            <?= strtoupper(substr($proposal['freelancer_name'], 0, 2)) ?>
                        </div>
                        <h4 class="profile-name"><?= htmlspecialchars($proposal['freelancer_name']) ?></h4>
                        <p class="profile-role"><?= htmlspecialchars($proposal['freelancer_title'] ?? 'Freelancer') ?></p>
                        
                        <div class="profile-stats">
                            <div>
                                <div class="profile-stat-value"><?= format_currency($proposal['hourly_rate'] ?? 0) ?>/hr</div>
                                <div class="profile-stat-label">Rate</div>
                            </div>
                        </div>

                        <p style="font-size: 0.85rem; color: var(--text-secondary); margin-top: 15px;">
                            <?= htmlspecialchars(substr($proposal['freelancer_bio'] ?? '', 0, 150)) ?>
                        </p>
                        
                        <a href="../guest/freelancer-profile.php?id=<?= $proposal['freelancer_profile_id'] ?>" class="btn btn-outline profile-full-link" style="margin-top: 15px;">
                            View Public Profile
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <script src="../js/expandable.js"></script>
    <script>lucide.createIcons();</script>
</body>
</html>
