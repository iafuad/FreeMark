<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';
require_role('client');

$client_id = get_profile_id($conn, current_user_id(), 'client');
$milestone_id = (int)($_GET['milestone_id'] ?? ($_GET['id'] ?? 0));

if ($milestone_id <= 0) {
    header("Location: work-approval.php");
    exit;
}

// Fetch milestone details
$stmt = $conn->prepare("SELECT m.*, c.id as contract_id, c.total_budget, c.paid_to_date, c.freelancer_id,
        p.title as project_title, p.id as project_id,
        u.full_name as freelancer_name, u.id as freelancer_user_id
    FROM milestones m
    JOIN contracts c ON m.contract_id = c.id
    JOIN projects p ON c.project_id = p.id
    JOIN freelancer_profiles fp ON c.freelancer_id = fp.id
    JOIN users u ON fp.user_id = u.id
    WHERE m.id = ? AND c.client_id = ?");
$stmt->bind_param("ii", $milestone_id, $client_id);
$stmt->execute();
$milestone = $stmt->get_result()->fetch_assoc();

if (!$milestone) {
    die("Milestone not found or access denied.");
}

$error = '';
$success = '';

// Handle Actions (Approve, Request Changes, Review)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'approve' && $milestone['status'] === 'submitted') {
        $conn->begin_transaction();
        try {
            // 1. Mark milestone approved
            $m_stmt = $conn->prepare("UPDATE milestones SET status = 'approved' WHERE id = ?");
            $m_stmt->bind_param("i", $milestone_id);
            $m_stmt->execute();

            // 2. Increment contract paid_to_date
            $c_stmt = $conn->prepare("UPDATE contracts SET paid_to_date = paid_to_date + ? WHERE id = ?");
            $c_stmt->bind_param("di", $milestone['amount'], $milestone['contract_id']);
            $c_stmt->execute();

            // 3. Check if all milestones are approved for this contract
            $chk_stmt = $conn->prepare("SELECT COUNT(*) FROM milestones WHERE contract_id = ? AND status != 'approved'");
            $chk_stmt->bind_param("i", $milestone['contract_id']);
            $chk_stmt->execute();
            $remaining = $chk_stmt->get_result()->fetch_row()[0];

            if ($remaining == 0) {
                $comp_stmt = $conn->prepare("UPDATE contracts SET status = 'completed' WHERE id = ?");
                $comp_stmt->bind_param("i", $milestone['contract_id']);
                $comp_stmt->execute();

                $p_comp = $conn->prepare("UPDATE projects SET status = 'completed' WHERE id = ?");
                $p_comp->bind_param("i", $milestone['project_id']);
                $p_comp->execute();
            }

            $conn->commit();
            $success = "Milestone approved and payment released!";
            $milestone['status'] = 'approved';
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error: " . $e->getMessage();
        }
    } elseif ($action === 'request_changes' && $milestone['status'] === 'submitted') {
        $m_stmt = $conn->prepare("UPDATE milestones SET status = 'changes_requested' WHERE id = ?");
        $m_stmt->bind_param("i", $milestone_id);
        $m_stmt->execute();
        $success = "Changes requested from freelancer.";
        $milestone['status'] = 'changes_requested';
    } elseif ($action === 'submit_review') {
        $stars = (int)($_POST['stars'] ?? 5);
        $comment = trim($_POST['comment'] ?? '');
        if ($stars >= 1 && $stars <= 5) {
            $r_stmt = $conn->prepare("INSERT INTO reviews (contract_id, client_id, freelancer_id, stars, comment) VALUES (?, ?, ?, ?, ?)");
            $r_stmt->bind_param("iiiis", $milestone['contract_id'], $client_id, $milestone['freelancer_id'], $stars, $comment);
            $r_stmt->execute();
            $success = "Review submitted successfully! Thank you for your feedback.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Milestone Review - FreeMark</title>
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
                <h2>Review Milestone</h2>
                <p><?= htmlspecialchars($milestone['title']) ?> (<?= htmlspecialchars($milestone['project_title']) ?>)</p>
            </div>
            <a href="work-approval.php" class="btn btn-outline">Back to Contracts</a>
        </header>

        <div class="client-content">
            <?php if ($error): ?>
                <div style="color: #ef4444; background: rgba(239, 68, 68, 0.1); padding: 10px 15px; border-radius: 6px; margin-bottom: 20px;">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div style="color: #22c55e; background: rgba(34, 197, 94, 0.1); padding: 10px 15px; border-radius: 6px; margin-bottom: 20px;">
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <div class="client-split-layout">
                <!-- Submission Details -->
                <div class="card client-split-main">
                    <div class="card-header">
                        <h3>Submitted Work</h3>
                        <div>Status: <?= get_status_badge($milestone['status']) ?></div>
                    </div>
                    <div class="card-body">
                        <div class="content-panel submission-panel">
                            <h4>Message from <?= htmlspecialchars($milestone['freelancer_name']) ?></h4>
                            <p class="submission-message">
                                <?= $milestone['submission_message'] ? nl2br(htmlspecialchars($milestone['submission_message'])) : '<em>No submission note provided yet.</em>' ?>
                            </p>
                            
                            <?php if ($milestone['submission_link']): ?>
                                <h4 class="section-heading" style="margin-top: 15px;">Deliverable Links & Files</h4>
                                <div class="attachment-list stacked">
                                    <a href="<?= htmlspecialchars($milestone['submission_link']) ?>" target="_blank" class="attachment-link accent">
                                        <i data-lucide="external-link" class="icon-lg"></i> <?= htmlspecialchars($milestone['submission_link']) ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($milestone['status'] === 'submitted'): ?>
                            <div class="submission-actions" style="margin-top: 20px; display: flex; gap: 15px;">
                                <form method="POST" action="milestone-details.php?milestone_id=<?= $milestone_id ?>">
                                    <input type="hidden" name="action" value="request_changes">
                                    <button type="submit" class="btn btn-outline" style="color: #ef4444; border-color: #ef4444;">Request Changes</button>
                                </form>
                                <form method="POST" action="milestone-details.php?milestone_id=<?= $milestone_id ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <button type="submit" class="btn btn-primary">Approve & Pay <?= format_currency($milestone['amount']) ?></button>
                                </form>
                            </div>
                        <?php endif; ?>

                        <!-- Rating Form -->
                        <div class="rating-form rating-form-expanded" style="margin-top: 30px;">
                            <h3>Rate this Freelancer</h3>
                            <p>Feedback helps other clients discover top talent.</p>
                            
                            <form method="POST" action="milestone-details.php?milestone_id=<?= $milestone_id ?>">
                                <input type="hidden" name="action" value="submit_review">
                                <div class="form-group" style="margin-bottom: 12px;">
                                    <label>Star Rating (1-5):</label>
                                    <select name="stars" style="padding: 6px; width: 120px;">
                                        <option value="5">★★★★★ (5 Stars)</option>
                                        <option value="4">★★★★☆ (4 Stars)</option>
                                        <option value="3">★★★☆☆ (3 Stars)</option>
                                        <option value="2">★★☆☆☆ (2 Stars)</option>
                                        <option value="1">★☆☆☆☆ (1 Star)</option>
                                    </select>
                                </div>
                                <textarea name="comment" class="review-textarea" placeholder="Write a review about your experience working with <?= htmlspecialchars($milestone['freelancer_name']) ?>..."></textarea>
                                <button type="submit" class="btn btn-outline" style="margin-top: 10px;">Submit Rating & Review</button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Snapshot Sidebar -->
                <div class="card client-split-sidebar">
                    <div class="card-header">
                        <h3>Contract Overview</h3>
                    </div>
                    <div class="card-body">
                        <h4 class="contract-summary-title"><?= htmlspecialchars($milestone['project_title']) ?></h4>
                        <p class="contract-summary-subtitle">Freelancer: <strong><?= htmlspecialchars($milestone['freelancer_name']) ?></strong></p>
                        
                        <div class="summary-list">
                            <div class="summary-row">
                                <span class="summary-label">Total Contract:</span>
                                <strong><?= format_currency($milestone['total_budget']) ?></strong>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">Paid to Date:</span>
                                <strong><?= format_currency($milestone['paid_to_date']) ?></strong>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">This Milestone:</span>
                                <strong><?= format_currency($milestone['amount']) ?></strong>
                            </div>
                        </div>

                        <div style="margin-top: 20px;">
                            <a href="chat.php?with=<?= $milestone['freelancer_user_id'] ?>" class="btn btn-outline" style="width: 100%; text-align: center; display: block; text-decoration: none;">
                                Message Freelancer
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <script>lucide.createIcons();</script>
</body>
</html>
