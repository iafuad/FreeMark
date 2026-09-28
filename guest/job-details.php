<?php
require_once '../config/db.php';
require_once '../includes/helpers.php';
require_once '../includes/auth.php';

$job_id = intval($_GET['id'] ?? 0);
if ($job_id <= 0) {
    header("Location: jobs.php");
    exit;
}

$stmt = $conn->prepare(
    "SELECT p.*, 
            c.id as client_id, c.company_name, c.hiring_volume, c.user_id as client_user_id, c.created_at as client_created_at,
            u.full_name as client_full_name,
            s.name as skill_name,
            (SELECT COUNT(*) FROM proposals WHERE project_id = p.id) as proposal_count,
            (SELECT COUNT(*) FROM projects WHERE client_id = c.id) as client_total_jobs,
            (SELECT COALESCE(SUM(paid_to_date), 0) FROM contracts WHERE client_id = c.id) as client_total_spent
     FROM projects p
     JOIN client_profiles c ON p.client_id = c.id
     JOIN users u ON c.user_id = u.id
     LEFT JOIN skill_categories s ON p.skill_category_id = s.id
     WHERE p.id = ?"
);
$stmt->bind_param("i", $job_id);
$stmt->execute();
$job = $stmt->get_result()->fetch_assoc();

if (!$job) {
    header("Location: jobs.php");
    exit;
}

// User context
$is_freelancer = false;
$is_project_owner = false;
$existing_proposal = null;

if (is_logged_in()) {
    $role = current_user_role();
    $curr_user_id = current_user_id();
    
    if ($role === 'freelancer') {
        $is_freelancer = true;
        $fl_profile_id = get_profile_id($conn, $curr_user_id, 'freelancer');
        if ($fl_profile_id) {
            $p_stmt = $conn->prepare("SELECT id, status, bid_amount, estimated_duration, created_at FROM proposals WHERE project_id = ? AND freelancer_id = ?");
            $p_stmt->bind_param("ii", $job_id, $fl_profile_id);
            $p_stmt->execute();
            $existing_proposal = $p_stmt->get_result()->fetch_assoc();
        }
    } elseif ($role === 'client' && $curr_user_id == $job['client_user_id']) {
        $is_project_owner = true;
    }
}

$client_display_name = !empty($job['company_name']) ? $job['company_name'] : $job['client_full_name'];
$initials = strtoupper(substr($client_display_name, 0, 2));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($job['title']) ?> - FreeMark</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/guest/components.css">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
    <header>
        <div class="container navbar">
            <a href="../index.php" class="logo">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>
                FreeMark
            </a>
            <nav class="nav-links">
                <a href="jobs.php" class="nav-active">Find Jobs</a>
                <a href="freelancers.php">Find Freelancers</a>
            </nav>
            <div class="nav-actions">
                <?php if (is_logged_in()): ?>
                    <?php
                        $role = current_user_role();
                        $dash_url = ($role === 'client') ? '../client/dashboard.php' : (($role === 'admin') ? '../admin/dashboard.php' : '../freelancer/dashboard.php');
                    ?>
                    <a href="<?= $dash_url ?>" class="btn btn-primary">Dashboard</a>
                    <a href="../logout.php" class="btn btn-outline">Log Out</a>
                <?php else: ?>
                    <a href="../login.php" class="btn btn-outline">Log In</a>
                    <a href="../register.php" class="btn btn-primary">Sign Up</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="container">
        <div class="back-navigation-wrapper">
            <a href="jobs.php" class="btn btn-outline btn-back">
                <i data-lucide="arrow-left" class="icon-sm"></i> Back to Projects
            </a>
        </div>

        <div class="job-detail-layout">
            <div class="job-detail-card">
                <?php if ($job['status'] !== 'open'): ?>
                    <div class="alert alert-warning" style="margin-bottom: 25px; display: flex; align-items: center; gap: 10px;">
                        <i data-lucide="alert-circle"></i>
                        <span>This project is currently <strong><?= ucfirst(str_replace('_', ' ', $job['status'])) ?></strong> and is not accepting new proposals.</span>
                    </div>
                <?php endif; ?>

                <div class="job-header-top">
                    <div>
                        <div class="job-title-row">
                            <h1><?= htmlspecialchars($job['title']) ?></h1>
                            <?= get_status_badge($job['status']) ?>
                        </div>
                        <div class="job-client-subtitle">
                            <span>Posted by <a href="client-profile.php?id=<?= $job['client_id'] ?>" style="color: var(--accent); text-decoration: none; font-weight: 600;"><?= htmlspecialchars($client_display_name) ?></a></span>
                            <span>•</span>
                            <span><i data-lucide="clock" class="icon-inline"></i> <?= date('M d, Y', strtotime($job['created_at'])) ?></span>
                            <span>•</span>
                            <span><i data-lucide="map-pin" class="icon-inline"></i> Worldwide (Remote)</span>
                        </div>
                    </div>
                    <div class="job-budget-box">
                        <div class="amount"><?= format_currency($job['budget_max']) ?></div>
                        <div class="type"><?= ucfirst($job['budget_type']) ?> Price</div>
                    </div>
                </div>

                <div class="job-meta-pills">
                    <div class="meta-pill-item">
                        <i data-lucide="calendar"></i>
                        <div>
                            <div class="meta-label">Project Timeline</div>
                            <strong><?= format_duration($job['duration']) ?></strong>
                        </div>
                    </div>
                    <div class="meta-pill-item">
                        <i data-lucide="file-text"></i>
                        <div>
                            <div class="meta-label">Proposals Received</div>
                            <strong><?= $job['proposal_count'] ?> applicants</strong>
                        </div>
                    </div>
                    <div class="meta-pill-item">
                        <i data-lucide="shield-check"></i>
                        <div>
                            <div class="meta-label">Escrow Protection</div>
                            <strong class="text-success">Verified</strong>
                        </div>
                    </div>
                    <?php if ($job['skill_name']): ?>
                    <div class="meta-pill-item">
                        <i data-lucide="tag"></i>
                        <div>
                            <div class="meta-label">Category</div>
                            <strong><?= htmlspecialchars($job['skill_name']) ?></strong>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <h3 class="job-section-title">Project Overview</h3>
                <div class="job-body-text" style="white-space: pre-wrap; margin-bottom: 25px;">
                    <?= htmlspecialchars($job['description']) ?>
                </div>

                <h3 class="job-section-title">Required Category & Skills</h3>
                <div class="tags card-tags">
                    <?php if ($job['skill_name']): ?>
                        <span class="tag" style="background: rgba(250, 204, 21, 0.15); border: 1px solid rgba(250, 204, 21, 0.3); color: var(--accent); font-weight: 600; padding: 6px 14px; border-radius: 20px;">
                            <i data-lucide="check" class="icon-sm" style="margin-right: 4px; display: inline-block; vertical-align: middle;"></i>
                            <?= htmlspecialchars($job['skill_name']) ?>
                        </span>
                    <?php else: ?>
                        <span class="tag">General</span>
                    <?php endif; ?>
                </div>

                <?php if ($existing_proposal): ?>
                    <!-- Proposal already submitted card -->
                    <div class="job-applied-card">
                        <div class="applied-header">
                            <div class="applied-title">
                                <i data-lucide="check-circle" style="color: #22c55e; width: 24px; height: 24px;"></i>
                                <div>
                                    <h4>You have submitted a proposal for this project</h4>
                                    <span style="color: var(--text-secondary); font-size: 0.85rem;">Applied on <?= date('M d, Y', strtotime($existing_proposal['created_at'])) ?></span>
                                </div>
                            </div>
                            <div>
                                Status: <?= get_status_badge($existing_proposal['status']) ?>
                            </div>
                        </div>
                        <div class="applied-details">
                            <div>
                                <div class="meta-label">Your Proposed Rate</div>
                                <div class="applied-bid"><?= format_currency($existing_proposal['bid_amount']) ?></div>
                            </div>
                            <div>
                                <div class="meta-label">Estimated Delivery</div>
                                <div class="applied-delivery"><?= format_duration($existing_proposal['estimated_duration']) ?></div>
                            </div>
                            <div class="applied-actions">
                                <a href="../freelancer/apply.php?job_id=<?= $job['id'] ?>" class="btn btn-outline btn-compact">
                                    <i data-lucide="file-text"></i> View Full Submission
                                </a>
                            </div>
                        </div>
                    </div>
                <?php elseif ($is_project_owner): ?>
                    <!-- Project Owner Controls -->
                    <div class="job-owner-card">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="width: 40px; height: 40px; border-radius: 8px; background: rgba(99, 102, 241, 0.15); display: flex; align-items: center; justify-content: center; color: #818cf8;">
                                <i data-lucide="shield"></i>
                            </div>
                            <div>
                                <strong style="color: var(--text-primary); display: block;">You are the client who posted this job</strong>
                                <span style="color: var(--text-secondary); font-size: 0.85rem;">Manage received applications and proposals</span>
                            </div>
                        </div>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <a href="../client/proposals.php?project_id=<?= $job['id'] ?>" class="btn btn-primary btn-compact">
                                <i data-lucide="file-text"></i> Review Proposals (<?= $job['proposal_count'] ?>)
                            </a>
                            <a href="../client/dashboard.php" class="btn btn-outline btn-compact">
                                <i data-lucide="layout-dashboard"></i> Dashboard
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Apply or Auth CTA -->
                    <div class="job-action-footer">
                        <?php if (is_logged_in()): ?>
                            <?php if ($is_freelancer): ?>
                                <?php if ($job['status'] === 'open'): ?>
                                    <a href="../freelancer/apply.php?job_id=<?= $job['id'] ?>" class="btn btn-primary btn-apply">
                                        <i data-lucide="send"></i> Apply for this Job
                                    </a>
                                <?php else: ?>
                                    <button class="btn btn-outline btn-apply" disabled style="opacity: 0.6; cursor: not-allowed;">
                                        <i data-lucide="lock"></i> Applications Closed
                                    </button>
                                <?php endif; ?>
                            <?php elseif (current_user_role() === 'client'): ?>
                                <div style="display: flex; align-items: center; gap: 10px; color: var(--text-secondary); font-size: 0.9rem;">
                                    <i data-lucide="info"></i>
                                    <span>Logged in as an Employer account. Switch to a freelancer account to apply for projects.</span>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <a href="../login.php" class="btn btn-primary btn-apply">
                                <i data-lucide="log-in"></i> Log in to Apply
                            </a>
                            <a href="../register.php" class="btn btn-outline">
                                Create Free Account
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div>
                <!-- Employer Card -->
                <div class="job-sidebar-card">
                    <div class="sidebar-client-header">
                        <div class="client-avatar-sq"><?= $initials ?></div>
                        <div>
                            <h3 class="client-name"><?= htmlspecialchars($client_display_name) ?></h3>
                            <div class="client-category"><i data-lucide="check-circle" class="icon-inline" style="color: #22c55e;"></i> Verified Employer</div>
                        </div>
                    </div>

                    <div class="client-stats-list">
                        <div class="client-stat-item">
                            <i data-lucide="shield-check" class="text-success"></i>
                            <span>Payment Verified</span>
                        </div>
                        <div class="client-stat-item">
                            <i data-lucide="briefcase"></i>
                            <span><strong><?= $job['client_total_jobs'] ?></strong> jobs posted</span>
                        </div>
                        <div class="client-stat-item">
                            <i data-lucide="dollar-sign"></i>
                            <span><strong><?= format_currency($job['client_total_spent']) ?></strong> spent on FreeMark</span>
                        </div>
                        <?php if (!empty($job['hiring_volume'])): ?>
                        <div class="client-stat-item">
                            <i data-lucide="trending-up"></i>
                            <span><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $job['hiring_volume']))) ?> hiring volume</span>
                        </div>
                        <?php endif; ?>
                        <div class="client-stat-item">
                            <i data-lucide="calendar"></i>
                            <span>Member since <?= date('M Y', strtotime($job['client_created_at'])) ?></span>
                        </div>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <a href="client-profile.php?id=<?= $job['client_id'] ?>" class="btn btn-outline btn-full-width">
                            <i data-lucide="building"></i> View Company Profile
                        </a>
                        <?php if (is_logged_in() && $is_freelancer): ?>
                            <a href="../freelancer/chat.php?with=<?= $job['client_user_id'] ?>" class="btn btn-outline btn-full-width">
                                <i data-lucide="message-square"></i> Message Employer
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Guarantee Card -->
                <div class="job-sidebar-card card-guarantee">
                    <h4 class="guarantee-title">
                        <i data-lucide="shield" class="icon-shield"></i> FreeMark Guarantee
                    </h4>
                    <p class="guarantee-text">
                        Funds for this project are held safely in escrow and released milestone-by-milestone upon your completed work and approval.
                    </p>
                </div>
            </div>
        </div>
    </main>

    <footer>
        <div class="container">
            <p>&copy; 2026 FreeMark. All rights reserved.</p>
        </div>
    </footer>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>