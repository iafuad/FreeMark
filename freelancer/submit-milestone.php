<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';
require_role('freelancer');

$freelancer_id = get_profile_id($conn, current_user_id(), 'freelancer');
$milestone_id = (int)($_GET['id'] ?? 0);

if ($milestone_id <= 0) {
    header("Location: work.php");
    exit;
}

// Fetch milestone details
$stmt = $conn->prepare("SELECT m.*, c.id as contract_id, p.title as project_title, cp.company_name, u.full_name as client_name
    FROM milestones m
    JOIN contracts c ON m.contract_id = c.id
    JOIN projects p ON c.project_id = p.id
    JOIN client_profiles cp ON c.client_id = cp.id
    JOIN users u ON cp.user_id = u.id
    WHERE m.id = ? AND c.freelancer_id = ?");
$stmt->bind_param("ii", $milestone_id, $freelancer_id);
$stmt->execute();
$milestone = $stmt->get_result()->fetch_assoc();

if (!$milestone) {
    die("Milestone not found or unauthorized.");
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submission_message = trim($_POST['submission_message'] ?? '');
    $submission_link = trim($_POST['submission_link'] ?? '');

    if (empty($submission_message)) {
        $error = "Please include a message describing the work delivered.";
    } else {
        $upd = $conn->prepare("UPDATE milestones SET status = 'submitted', submission_message = ?, submission_link = ?, submitted_at = NOW() WHERE id = ?");
        $upd->bind_param("ssi", $submission_message, $submission_link, $milestone_id);
        if ($upd->execute()) {
            $success = "Work submitted successfully! The client has been notified to review your deliverable.";
            $milestone['status'] = 'submitted';
            $milestone['submission_message'] = $submission_message;
            $milestone['submission_link'] = $submission_link;
        } else {
            $error = "Failed to update milestone. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Milestone - FreeMark</title>
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
                <h2>Submit Milestone Work</h2>
                <p><?= htmlspecialchars($milestone['title']) ?> (<?= htmlspecialchars($milestone['project_title']) ?>)</p>
            </div>
            <a href="work.php" class="btn btn-outline">Cancel</a>
        </header>

        <div class="freelancer-content">
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

            <div class="form-two-column">
                <!-- Submission Form -->
                <div class="card form-main-section">
                    <div class="card-header">
                        <h3>Submission Details</h3>
                        <div>Status: <?= get_status_badge($milestone['status']) ?></div>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="submit-milestone.php?id=<?= $milestone_id ?>">
                            <div class="form-group">
                                <label>Message to Client</label>
                                <textarea name="submission_message" rows="5" placeholder="Describe the work you've completed, how to test it, and any notes for the client..." required><?= htmlspecialchars($milestone['submission_message'] ?? '') ?></textarea>
                            </div>
                            
                            <div class="form-group" style="margin-top: 15px;">
                                <label>Deliverable Link (GitHub, Staging URL, Google Drive, Figma, etc.)</label>
                                <input type="url" name="submission_link" placeholder="https://..." value="<?= htmlspecialchars($milestone['submission_link'] ?? '') ?>">
                            </div>
                            
                            <div class="form-actions-divider" style="margin-top: 25px;">
                                <button type="submit" class="btn btn-primary btn-full">
                                    <?= $milestone['status'] === 'submitted' ? 'Update Submission' : 'Submit Milestone for Review' ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Milestone Sidebar Info -->
                <div class="card form-sidebar-section">
                    <div class="card-header">
                        <h3>Milestone Info</h3>
                    </div>
                    <div class="card-body">
                        <h4 style="margin: 0 0 5px 0;"><?= htmlspecialchars($milestone['title']) ?></h4>
                        <p style="color: var(--text-secondary); margin: 0 0 15px 0; font-size: 0.9rem;">
                            Client: <strong><?= htmlspecialchars($milestone['company_name'] ?: $milestone['client_name']) ?></strong>
                        </p>
                        
                        <div style="padding: 15px; background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: 6px; margin-bottom: 20px;">
                            <div style="font-size: 0.85rem; color: var(--text-secondary);">Payout on Approval</div>
                            <div style="font-size: 1.5rem; font-weight: bold; color: var(--accent); margin-top: 4px;">
                                <?= format_currency($milestone['amount']) ?>
                            </div>
                        </div>

                        <p style="font-size: 0.85rem; color: var(--text-secondary);">
                            Once submitted, the client will review your deliverables and can either approve payment release or request modifications.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <script>lucide.createIcons();</script>
</body>
</html>
