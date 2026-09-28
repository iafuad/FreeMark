<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

require_role('freelancer');
$profile_id = get_profile_id($conn, current_user_id(), 'freelancer');

$project_id = (int)($_GET['job_id'] ?? ($_GET['id'] ?? 0));
if ($project_id <= 0) {
    header("Location: jobs.php");
    exit;
}

// Fetch project details
$stmt = $conn->prepare("SELECT p.*, cp.company_name, cp.user_id as client_user_id, u.full_name as client_name, sc.name as skill_name 
    FROM projects p
    JOIN client_profiles cp ON p.client_id = cp.id
    JOIN users u ON cp.user_id = u.id
    LEFT JOIN skill_categories sc ON p.skill_category_id = sc.id
    WHERE p.id = ?");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$project = $stmt->get_result()->fetch_assoc();

if (!$project) {
    header("Location: jobs.php");
    exit;
}

// Check if already applied
$prop_check = $conn->prepare("SELECT * FROM proposals WHERE project_id = ? AND freelancer_id = ?");
$prop_check->bind_param("ii", $project_id, $profile_id);
$prop_check->execute();
$existing_proposal = $prop_check->get_result()->fetch_assoc();

$error = '';
$success = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$existing_proposal) {
    if ($project['status'] !== 'open') {
        $error = "This project is no longer accepting new proposals.";
    } else {
        $bid_amount = floatval($_POST['bid_amount'] ?? 0);
        $estimated_duration = trim($_POST['estimated_duration'] ?? '');
        $cover_letter = trim($_POST['cover_letter'] ?? '');
        
        if ($bid_amount <= 0 || empty($cover_letter)) {
            $error = "Valid bid amount and cover letter are required.";
        } else {
            $stmt = $conn->prepare("INSERT INTO proposals (project_id, freelancer_id, cover_letter, bid_amount, estimated_duration) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("iisds", $project_id, $profile_id, $cover_letter, $bid_amount, $estimated_duration);
            if ($stmt->execute()) {
                $success = "Proposal submitted successfully! The client can now review your application.";
                // Reload existing proposal
                $prop_check->execute();
                $existing_proposal = $prop_check->get_result()->fetch_assoc();
            } else {
                $error = "Failed to submit proposal. Please try again.";
            }
        }
    }
}

$client_display_name = !empty($project['company_name']) ? $project['company_name'] : $project['client_name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $existing_proposal ? 'Your Proposal' : 'Submit Proposal' ?> - FreeMark</title>
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
                <li><a href="jobs.php" class="active"><i data-lucide="briefcase"></i> Find Jobs</a></li>
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
                <h2><?= $existing_proposal ? 'Submitted Proposal' : 'Apply for Project' ?></h2>
                <p><?= htmlspecialchars($project['title']) ?></p>
            </div>
            <div style="display: flex; gap: 10px;">
                <a href="../guest/job-details.php?id=<?= $project['id'] ?>" class="btn btn-outline" target="_blank">
                    <i data-lucide="external-link"></i> Public Job Post
                </a>
                <a href="jobs.php" class="btn btn-outline">Back to Jobs</a>
            </div>
        </header>

        <div class="freelancer-content">
            <?php if ($error): ?>
                <div class="alert alert-danger" style="margin-bottom: 20px;">
                    <i data-lucide="alert-circle"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success" style="margin-bottom: 20px;">
                    <i data-lucide="check-circle"></i>
                    <span><?= htmlspecialchars($success) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($existing_proposal): ?>
                <div class="form-two-column">
                    <!-- Proposal Details Main -->
                    <div class="card form-main-section">
                        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <h3 style="margin: 0;">Proposal Summary</h3>
                                <span style="color: var(--text-secondary); font-size: 0.85rem;">Submitted on <?= date('M d, Y \a\t h:i A', strtotime($existing_proposal['created_at'])) ?></span>
                            </div>
                            <div>
                                Status: <?= get_status_badge($existing_proposal['status']) ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 25px; padding: 20px; background: var(--bg-primary); border-radius: 8px; border: 1px solid var(--border-color);">
                                <div>
                                    <span style="color: var(--text-secondary); font-size: 0.85rem; display: block; margin-bottom: 4px;">Your Proposed Bid</span>
                                    <div style="font-size: 1.5rem; font-weight: 800; color: var(--accent);"><?= format_currency($existing_proposal['bid_amount']) ?></div>
                                </div>
                                <div>
                                    <span style="color: var(--text-secondary); font-size: 0.85rem; display: block; margin-bottom: 4px;">Estimated Delivery</span>
                                    <div style="font-size: 1.1rem; font-weight: 600; color: var(--text-primary);"><?= format_duration($existing_proposal['estimated_duration']) ?></div>
                                </div>
                                <div>
                                    <span style="color: var(--text-secondary); font-size: 0.85rem; display: block; margin-bottom: 4px;">Client's Stated Budget</span>
                                    <div style="font-size: 1.1rem; font-weight: 600; color: var(--text-primary);"><?= format_currency($project['budget_max']) ?> (<?= ucfirst($project['budget_type']) ?>)</div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label style="font-weight: 600; font-size: 1rem; color: var(--text-primary); margin-bottom: 10px;">Your Cover Letter & Pitch</label>
                                <div style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 6px; padding: 18px; line-height: 1.7; color: #cbd5e1; white-space: pre-wrap; font-size: 0.95rem;"><?= htmlspecialchars($existing_proposal['cover_letter']) ?></div>
                            </div>

                            <div style="margin-top: 25px; padding-top: 20px; border-top: 1px solid var(--border-color); display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                                <a href="chat.php?with=<?= $project['client_user_id'] ?>" class="btn btn-primary">
                                    <i data-lucide="message-square"></i> Message Client
                                </a>
                                <a href="../guest/job-details.php?id=<?= $project['id'] ?>" class="btn btn-outline" target="_blank">
                                    <i data-lucide="external-link"></i> Review Public Job Posting
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Job Summary Sidebar -->
                    <div class="card form-sidebar-section">
                        <div class="card-header">
                            <h3>Job Context</h3>
                        </div>
                        <div class="card-body text-small">
                            <h4 class="m-0 mb-10 text-primary">Client / Organization</h4>
                            <p class="m-0 mb-15 font-bold" style="color: var(--text-primary); font-size: 1rem;"><?= htmlspecialchars($client_display_name) ?></p>

                            <h4 class="m-0 mb-10 text-primary">Budget Type</h4>
                            <p class="m-0 mb-20 text-accent font-bold text-lg"><?= format_currency($project['budget_max']) ?> <span style="font-size: 0.85rem; font-weight: normal; color: var(--text-secondary);">(<?= ucfirst($project['budget_type']) ?>)</span></p>
                            
                            <h4 class="m-0 mb-10 text-primary">Required Skill Category</h4>
                            <div class="skill-tags mb-20">
                                <span class="skill-tag selected" style="padding: 4px 10px; border-radius: 4px; background: rgba(250, 204, 21, 0.15); color: var(--accent); font-weight: 600;"><?= htmlspecialchars($project['skill_name'] ?? 'General') ?></span>
                            </div>

                            <h4 class="m-0 mb-10 text-primary">Project Timeline</h4>
                            <p class="text-secondary mb-20"><?= format_duration($project['duration']) ?></p>

                            <h4 class="m-0 mb-10 text-primary">Project Status</h4>
                            <p><?= get_status_badge($project['status']) ?></p>
                        </div>
                    </div>
                </div>

            <?php elseif ($project['status'] !== 'open'): ?>
                <div class="card" style="padding: 35px; text-align: center;">
                    <div style="width: 50px; height: 50px; border-radius: 50%; background: rgba(239, 68, 68, 0.1); display: flex; align-items: center; justify-content: center; margin: 0 auto 15px; color: #ef4444;">
                        <i data-lucide="lock" style="width: 24px; height: 24px;"></i>
                    </div>
                    <h3 style="margin-bottom: 8px;">Applications Closed</h3>
                    <p style="color: var(--text-secondary); max-width: 500px; margin: 0 auto 20px;">
                        This project is currently marked as <strong><?= ucfirst(str_replace('_', ' ', $project['status'])) ?></strong> and is no longer accepting new proposals.
                    </p>
                    <a href="jobs.php" class="btn btn-primary">Browse Available Jobs</a>
                </div>

            <?php else: ?>
                <div class="form-two-column">
                    <!-- Application Form -->
                    <div class="card form-main-section">
                        <div class="card-header">
                            <h3>Submit Proposal</h3>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="apply.php?job_id=<?= $project['id'] ?>">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Proposed Rate / Total Bid ($)</label>
                                        <input type="number" name="bid_amount" placeholder="e.g. 1500" step="0.01" max="<?= (float)$project['budget_max'] * 2 ?>" required>
                                        <small style="color: var(--text-secondary); display: block; margin-top: 4px;">Client max budget: <?= format_currency($project['budget_max']) ?></small>
                                    </div>
                                    <div class="form-group">
                                        <label>Estimated Time to Complete</label>
                                        <select name="estimated_duration" required>
                                            <option value="">Select duration...</option>
                                            <option value="Less than 1 week">Less than 1 week</option>
                                            <option value="1 to 2 weeks">1 to 2 weeks</option>
                                            <option value="1 to 4 weeks">1 to 4 weeks</option>
                                            <option value="1 to 3 months">1 to 3 months</option>
                                            <option value="More than 3 months">More than 3 months</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label>Cover Letter</label>
                                    <textarea name="cover_letter" rows="6" placeholder="Introduce yourself, explain why you're a great fit for this project, and detail your approach..." required></textarea>
                                </div>
                                
                                <div class="form-separator" style="margin-top: 20px;">
                                    <button type="submit" class="btn btn-primary btn-full">
                                        <i data-lucide="send"></i> Submit Application
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Job Summary Sidebar -->
                    <div class="card form-sidebar-section">
                        <div class="card-header">
                            <h3>Job Summary</h3>
                        </div>
                        <div class="card-body text-small">
                            <h4 class="m-0 mb-10 text-primary">Client</h4>
                            <p class="m-0 mb-15 font-bold" style="color: var(--text-primary); font-size: 1rem;"><?= htmlspecialchars($client_display_name) ?></p>

                            <h4 class="m-0 mb-10 text-primary">Client Budget</h4>
                            <p class="m-0 mb-20 text-accent font-bold text-lg"><?= format_currency($project['budget_max']) ?> <span style="font-size: 0.85rem; font-weight: normal; color: var(--text-secondary);">(<?= ucfirst($project['budget_type']) ?>)</span></p>
                            
                            <h4 class="m-0 mb-10 text-primary">Skill Category</h4>
                            <div class="skill-tags mb-20">
                                <span class="skill-tag selected" style="padding: 4px 10px; border-radius: 4px; background: rgba(250, 204, 21, 0.15); color: var(--accent); font-weight: 600;"><?= htmlspecialchars($project['skill_name'] ?? 'General') ?></span>
                            </div>

                            <h4 class="m-0 mb-10 text-primary">Expected Duration</h4>
                            <p class="text-secondary mb-20"><?= format_duration($project['duration']) ?></p>

                            <a href="../guest/job-details.php?id=<?= $project['id'] ?>" class="btn btn-outline" style="width: 100%; text-align: center; justify-content: center;" target="_blank">
                                <i data-lucide="external-link"></i> Full Job Description
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
    <script>lucide.createIcons();</script>
</body>
</html>
