<?php
require_once '../config/db.php';
require_once '../includes/helpers.php';
require_once '../includes/auth.php';

$client_id = (int)($_GET['id'] ?? 0);

$stmt = $conn->prepare("SELECT cp.*, u.full_name, u.email,
    (SELECT COUNT(*) FROM projects WHERE client_id = cp.id) as total_jobs,
    (SELECT COUNT(*) FROM contracts WHERE client_id = cp.id) as total_hires,
    (SELECT COALESCE(SUM(paid_to_date), 0) FROM contracts WHERE client_id = cp.id) as total_spent
    FROM client_profiles cp
    JOIN users u ON cp.user_id = u.id
    WHERE cp.id = ?");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();

if (!$client) {
    die("Client profile not found.");
}

// Fetch open jobs posted by this client
$p_stmt = $conn->prepare("SELECT p.*, sc.name as skill_name,
    (SELECT COUNT(*) FROM proposals WHERE project_id = p.id) as proposal_count
    FROM projects p
    LEFT JOIN skill_categories sc ON p.skill_category_id = sc.id
    WHERE p.client_id = ? AND p.status = 'open'
    ORDER BY p.created_at DESC");
$p_stmt->bind_param("i", $client_id);
$p_stmt->execute();
$open_jobs = $p_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($client['company_name'] ?: $client['full_name']) ?> - FreeMark</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/guest/profile.css">
    <link rel="stylesheet" href="../css/freelancer/inline-helpers.css">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
    <header>
        <div class="container navbar">
            <a href="../index.php" class="logo">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>
                </svg>
                FreeMark
            </a>
            <nav class="nav-links">
                <a href="jobs.php">Find Jobs</a>
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
        <div class="profile-layout">
            <!-- Sidebar -->
            <aside class="profile-sidebar">
                <div class="profile-card">
                    <div class="profile-avatar-large purple">
                        <?= strtoupper(substr($client['company_name'] ?: $client['full_name'], 0, 2)) ?>
                    </div>
                    <h2 class="profile-name"><?= htmlspecialchars($client['company_name'] ?: $client['full_name']) ?></h2>
                    <p class="profile-title"><?= htmlspecialchars($client['full_name']) ?></p>
                    
                    <span class="profile-badge-tag badge-employer">
                        <i data-lucide="shield-check" class="icon-sm"></i> Verified Employer
                    </span>

                    <div class="profile-stats-mini">
                        <div class="stat-mini">
                            <div class="stat-mini-val"><?= (int)$client['total_jobs'] ?></div>
                            <div class="stat-mini-label">Jobs Posted</div>
                        </div>
                        <div class="stat-mini">
                            <div class="stat-mini-val"><?= (int)$client['total_hires'] ?></div>
                            <div class="stat-mini-label">Hires</div>
                        </div>
                        <div class="stat-mini">
                            <div class="stat-mini-val"><?= format_currency($client['total_spent']) ?></div>
                            <div class="stat-mini-label">Total Paid</div>
                        </div>
                    </div>

                    <div class="profile-actions">
                        <?php if (is_logged_in()): ?>
                            <?php if (current_user_role() === 'freelancer'): ?>
                                <a href="../freelancer/chat.php?with=<?= $client['user_id'] ?>" class="btn btn-primary btn-full">
                                    <i data-lucide="message-square" class="icon-sm"></i> Message Employer
                                </a>
                                <a href="#open-jobs" class="btn btn-outline btn-full">
                                    <i data-lucide="briefcase" class="icon-sm"></i> View Open Jobs (<?= count($open_jobs) ?>)
                                </a>
                            <?php elseif ($client['user_id'] == current_user_id()): ?>
                                <a href="../client/profile.php" class="btn btn-primary btn-full">
                                    <i data-lucide="edit-3" class="icon-sm"></i> Edit Company Profile
                                </a>
                            <?php endif; ?>
                        <?php else: ?>
                            <a href="../login.php" class="btn btn-primary btn-full">
                                <i data-lucide="lock" class="icon-sm"></i> Log In to Apply
                            </a>
                        <?php endif; ?>
                    </div>

                    <div class="profile-details-list">
                        <div class="detail-item">
                            <i data-lucide="check-circle" class="detail-icon detail-icon--success"></i>
                            <span>Payment Verified</span>
                        </div>
                        <div class="detail-item">
                            <i data-lucide="users" class="detail-icon"></i>
                            <span>Hiring Volume: <strong><?= htmlspecialchars($client['hiring_volume'] ?? '1-10') ?> hires</strong></span>
                        </div>
                        <div class="detail-item">
                            <i data-lucide="calendar" class="detail-icon"></i>
                            <span>Member since <?= date('M Y', strtotime($client['created_at'])) ?></span>
                        </div>
                    </div>
                </div>
            </aside>

            <!-- Main Sections -->
            <section class="profile-main">
                <!-- Overview -->
                <div class="profile-section">
                    <h3 class="section-title">
                        <i data-lucide="building-2"></i> About Company
                    </h3>
                    <?php if (!empty($client['bio'])): ?>
                        <?= render_expandable_text($client['bio'], 260, 4, 'bio-paragraph') ?>
                    <?php else: ?>
                        <p class="bio-paragraph">No company overview provided yet.</p>
                    <?php endif; ?>
                </div>

                <!-- Company Snapshot Metrics -->
                <div class="profile-section">
                    <h3 class="section-title">
                        <i data-lucide="bar-chart-2"></i> Employer Hiring Metrics
                    </h3>
                    <div class="client-metrics-grid">
                        <div class="client-metric-cell">
                            <div class="client-metric-val"><?= (int)$client['total_jobs'] ?></div>
                            <div class="client-metric-lbl">Total Jobs Posted</div>
                        </div>
                        <div class="client-metric-cell">
                            <div class="client-metric-val"><?= (int)$client['total_hires'] ?></div>
                            <div class="client-metric-lbl">Contracts Initiated</div>
                        </div>
                        <div class="client-metric-cell">
                            <div class="client-metric-val" style="color: var(--accent);"><?= format_currency($client['total_spent']) ?></div>
                            <div class="client-metric-lbl">Total Spend to Date</div>
                        </div>
                        <div class="client-metric-cell">
                            <div class="client-metric-val" style="color: #86efac;"><?= htmlspecialchars($client['hiring_volume'] ?? '1-10') ?></div>
                            <div class="client-metric-lbl">Target Hiring Volume</div>
                        </div>
                    </div>
                </div>

                <!-- Open Job Postings -->
                <div class="profile-section" id="open-jobs">
                    <h3 class="section-title">
                        <i data-lucide="briefcase"></i> Active Open Positions (<?= count($open_jobs) ?>)
                    </h3>
                    <?php if (empty($open_jobs)): ?>
                        <p style="color: var(--text-secondary); margin: 0;">No open positions posted at this time.</p>
                    <?php else: ?>
                        <div class="client-jobs-stack">
                            <?php foreach ($open_jobs as $job): ?>
                                <div class="client-job-row">
                                    <div class="client-job-info">
                                        <h4>
                                            <a href="job-details.php?id=<?= $job['id'] ?>">
                                                <?= htmlspecialchars($job['title']) ?>
                                            </a>
                                        </h4>
                                        <div class="client-job-meta">
                                            <span class="budget-pill"><?= format_currency($job['budget_max']) ?> (<?= ucfirst($job['budget_type']) ?>)</span>
                                            <span>•</span>
                                            <span><?= htmlspecialchars($job['skill_name'] ?? 'General') ?></span>
                                            <span>•</span>
                                            <span><?= (int)$job['proposal_count'] ?> proposal<?= $job['proposal_count'] == 1 ? '' : 's' ?></span>
                                            <span>•</span>
                                            <span>Posted <?= date('M j, Y', strtotime($job['created_at'])) ?></span>
                                        </div>
                                    </div>
                                    <div>
                                        <a href="job-details.php?id=<?= $job['id'] ?>" class="btn btn-outline btn-sm">
                                            View Job &rarr;
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </main>

    <footer>
        <div class="container">
            <p>&copy; 2026 FreeMark. All rights reserved.</p>
        </div>
    </footer>
    <script src="../js/expandable.js"></script>
    <script>lucide.createIcons();</script>
</body>
</html>