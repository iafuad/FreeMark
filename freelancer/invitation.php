<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

require_role('freelancer');
$user_id = current_user_id();
$profile_id = get_profile_id($conn, $user_id, 'freelancer');

$invitation_id = (int)($_GET['id'] ?? 0);

// Fetch invitation details if ID is provided
$invitation = null;
if ($invitation_id > 0) {
    $stmt = $conn->prepare("SELECT ji.*, p.title as project_title, p.description as project_description, p.budget_max, p.budget_type, p.duration,
            cp.company_name, u.full_name as client_name
        FROM job_invitations ji
        LEFT JOIN projects p ON ji.project_id = p.id
        JOIN client_profiles cp ON ji.client_id = cp.id
        JOIN users u ON cp.user_id = u.id
        WHERE ji.id = ? AND ji.freelancer_id = ?");
    $stmt->bind_param("ii", $invitation_id, $profile_id);
    $stmt->execute();
    $invitation = $stmt->get_result()->fetch_assoc();
}

// Handle Accept and Decline
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $invitation) {
    $action = $_POST['action'] ?? '';

    if ($action === 'accept' && $invitation['status'] === 'pending') {
        $conn->begin_transaction();
        try {
            // 1. Update invitation status
            $upd = $conn->prepare("UPDATE job_invitations SET status = 'accepted' WHERE id = ?");
            $upd->bind_param("i", $invitation_id);
            $upd->execute();

            // 2. Determine budget
            $budget = $invitation['budget_max'] ?? 1000.00;
            $project_id = $invitation['project_id'];

            // 3. Create Contract
            $c_stmt = $conn->prepare("INSERT INTO contracts (project_id, client_id, freelancer_id, total_budget, status) VALUES (?, ?, ?, ?, 'active')");
            $c_stmt->bind_param("iiid", $project_id, $invitation['client_id'], $profile_id, $budget);
            $c_stmt->execute();
            $contract_id = $conn->insert_id;

            // 4. Create 2 milestones
            $m1_amount = round($budget * 0.40, 2);
            $m2_amount = round($budget - $m1_amount, 2);
            $m1_title = "Milestone 1: Core Implementation & Prototype";
            $m2_title = "Milestone 2: Final Integration & Delivery";

            $m_stmt = $conn->prepare("INSERT INTO milestones (contract_id, title, amount, status) VALUES (?, ?, ?, 'in_progress'), (?, ?, ?, 'in_progress')");
            $m_stmt->bind_param("isdisd", $contract_id, $m1_title, $m1_amount, $contract_id, $m2_title, $m2_amount);
            $m_stmt->execute();

            // If project is set, update to in_progress
            if ($project_id) {
                $p_upd = $conn->prepare("UPDATE projects SET status = 'in_progress' WHERE id = ?");
                $p_upd->bind_param("i", $project_id);
                $p_upd->execute();
            }

            $conn->commit();
            header("Location: work.php");
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error accepting invitation: " . $e->getMessage();
        }
    } elseif ($action === 'decline' && $invitation['status'] === 'pending') {
        $upd = $conn->prepare("UPDATE job_invitations SET status = 'declined' WHERE id = ?");
        $upd->bind_param("i", $invitation_id);
        $upd->execute();
        header("Location: dashboard.php");
        exit;
    }
}

// Fetch all invitations if no specific valid ID
$all_invites = [];
if (!$invitation) {
    $stmt = $conn->prepare("SELECT ji.*, p.title as project_title, cp.company_name, u.full_name as client_name
        FROM job_invitations ji
        LEFT JOIN projects p ON ji.project_id = p.id
        JOIN client_profiles cp ON ji.client_id = cp.id
        JOIN users u ON cp.user_id = u.id
        WHERE ji.freelancer_id = ?
        ORDER BY ji.created_at DESC");
    $stmt->bind_param("i", $profile_id);
    $stmt->execute();
    $all_invites = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Invitations - FreeMark</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/freelancer/layout.css">
    <link rel="stylesheet" href="../css/freelancer/components.css">
    <link rel="stylesheet" href="../css/freelancer/inline-helpers.css">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
    <aside class="freelancer-sidebar">
        <div class="sidebar-header">
            <a href="../index.php" class="logo">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>
                FreeMark
            </a>
        </div>
        <nav class="sidebar-nav">
            <ul>
                <li><a href="dashboard.php" class="active"><i data-lucide="layout-dashboard"></i> Overview</a></li>
                <li><a href="profile.php"><i data-lucide="user"></i> My Profile</a></li>
                <li><a href="jobs.php"><i data-lucide="briefcase"></i> Find Jobs</a></li>
                <li><a href="tests.php"><i data-lucide="check-square"></i> Skill Tests</a></li>
                <li><a href="work.php"><i data-lucide="upload-cloud"></i> My Contracts</a></li>
                <li><a href="chat.php"><i data-lucide="message-square"></i> Messages</a></li>
            </ul>
        </nav>
        <div class="sidebar-footer">
            <a href="../logout.php" class="link-unstyled">
                <i data-lucide="log-out"></i> Logout
            </a>
        </div>
    </aside>

    <main class="freelancer-main">
        <header class="freelancer-header">
            <div class="header-title">
                <h2>Project Invitations</h2>
                <p>Direct project offers and invitations sent to you by clients</p>
            </div>
            <a href="dashboard.php" class="btn btn-outline">Back to Dashboard</a>
        </header>

        <div class="freelancer-content">
            <?php if ($invitation): ?>
                <div class="form-two-column">
                    <div class="card form-main-section">
                        <div class="card-header">
                            <h3>Offer Details: <?= htmlspecialchars($invitation['project_title'] ?: 'Direct Contract Offer') ?></h3>
                            <div>Status: <?= get_status_badge($invitation['status']) ?></div>
                        </div>
                        <div class="card-body">
                            <div class="client-message-box" style="padding: 20px; background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: 6px; margin-bottom: 20px;">
                                <h4 style="margin: 0 0 10px 0;">Message from <?= htmlspecialchars($invitation['company_name'] ?: $invitation['client_name']) ?></h4>
                                <p style="line-height: 1.5; color: var(--text-secondary); margin: 0; white-space: pre-wrap;"><?= htmlspecialchars(trim($invitation['message'])) ?></p>
                            </div>

                            <div class="info-row" style="display: flex; gap: 30px; margin-bottom: 25px;">
                                <div class="info-item">
                                    <h4 style="margin: 0 0 5px 0; font-size: 0.85rem; color: var(--text-secondary);">Total Offer</h4>
                                    <p class="accent" style="margin: 0; font-size: 1.3rem; font-weight: bold; color: var(--accent);">
                                        <?= format_currency($invitation['budget_max'] ?? 0) ?>
                                    </p>
                                </div>
                                <div class="info-item">
                                    <h4 style="margin: 0 0 5px 0; font-size: 0.85rem; color: var(--text-secondary);">Estimated Timeline</h4>
                                    <p style="margin: 0; font-size: 1.1rem; font-weight: 600;">
                                        <?= format_duration($invitation['duration'] ?? '1_4w') ?>
                                    </p>
                                </div>
                            </div>

                            <?php if ($invitation['status'] === 'pending'): ?>
                                <div style="display: flex; gap: 15px;">
                                    <form method="POST" action="invitation.php?id=<?= $invitation_id ?>">
                                        <input type="hidden" name="action" value="accept">
                                        <button type="submit" class="btn btn-primary btn-md">Accept Offer & Start Contract</button>
                                    </form>
                                    <form method="POST" action="invitation.php?id=<?= $invitation_id ?>">
                                        <input type="hidden" name="action" value="decline">
                                        <button type="submit" class="btn btn-outline" style="border-color: #ef4444; color: #ef4444;">Decline Offer</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <p style="color: var(--text-secondary);">This invitation is currently <?= htmlspecialchars($invitation['status']) ?>.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card form-sidebar-section">
                        <div class="card-header">
                            <h3>Client Profile</h3>
                        </div>
                        <div class="card-body">
                            <h4><?= htmlspecialchars($invitation['company_name'] ?: $invitation['client_name']) ?></h4>
                            <p style="color: var(--text-secondary); font-size: 0.85rem;">FreeMark Verified Client</p>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- List all invitations -->
                <div class="card">
                    <div class="card-header">
                        <h3>All Received Invitations (<?= count($all_invites) ?>)</h3>
                    </div>
                    <div class="card-body card-body-flush">
                        <?php if (empty($all_invites)): ?>
                            <p style="padding: 25px; text-align: center; color: var(--text-secondary);">You have no invitations at this time.</p>
                        <?php else: ?>
                            <?php foreach ($all_invites as $inv): ?>
                                <div style="display:flex; justify-content:space-between; align-items:center; padding: 18px 20px; border-bottom: 1px solid var(--border-color);">
                                    <div>
                                        <h4 style="margin: 0 0 6px 0;">
                                            <a href="invitation.php?id=<?= $inv['id'] ?>" style="color: var(--text-primary); text-decoration: none;">
                                                <?= htmlspecialchars($inv['project_title'] ?: 'Direct Offer') ?>
                                            </a>
                                        </h4>
                                        <p style="margin: 0 0 6px 0; font-size: 0.85rem; color: var(--text-secondary);">
                                            From: <strong><?= htmlspecialchars($inv['company_name'] ?: $inv['client_name']) ?></strong> • Received <?= date('M j, Y', strtotime($inv['created_at'])) ?>
                                        </p>
                                        <div>
                                            Status: <?= get_status_badge($inv['status']) ?>
                                        </div>
                                    </div>
                                    <div>
                                        <a href="invitation.php?id=<?= $inv['id'] ?>" class="btn btn-primary btn-sm">Review Invitation</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
    <script>lucide.createIcons();</script>
</body>
</html>
