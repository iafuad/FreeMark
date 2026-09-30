<?php
require_once '../config/db.php';
require_once '../includes/helpers.php';
require_once '../includes/auth.php';

$freelancer_id = (int)($_GET['id'] ?? 0);

$stmt = $conn->prepare("SELECT fp.*, u.full_name, u.email, u.id as user_id,
    (SELECT AVG(stars) FROM reviews WHERE freelancer_id = fp.id) as avg_rating,
    (SELECT COUNT(*) FROM reviews WHERE freelancer_id = fp.id) as rev_count,
    (SELECT COUNT(*) FROM contracts WHERE freelancer_id = fp.id AND status = 'completed') as completed_contracts
    FROM freelancer_profiles fp
    JOIN users u ON fp.user_id = u.id
    WHERE fp.id = ?");
$stmt->bind_param("i", $freelancer_id);
$stmt->execute();
$freelancer = $stmt->get_result()->fetch_assoc();

if (!$freelancer) {
    die("Freelancer profile not found.");
}

// Fetch verified quiz tests
$q_stmt = $conn->prepare("SELECT tr.*, q.title as quiz_title, q.passing_score
    FROM test_results tr
    JOIN quizzes q ON tr.quiz_id = q.id
    WHERE tr.freelancer_id = ? AND tr.passed = 1
    ORDER BY tr.score DESC");
$q_stmt->bind_param("i", $freelancer_id);
$q_stmt->execute();
$verified_tests = $q_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch reviews
$r_stmt = $conn->prepare("SELECT r.*, cp.company_name, u.full_name as client_name
    FROM reviews r
    JOIN client_profiles cp ON r.client_id = cp.id
    JOIN users u ON cp.user_id = u.id
    WHERE r.freelancer_id = ?
    ORDER BY r.created_at DESC");
$r_stmt->bind_param("i", $freelancer_id);
$r_stmt->execute();
$reviews = $r_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch skills
$sk_stmt = $conn->prepare("SELECT sc.name FROM freelancer_skills fs JOIN skill_categories sc ON fs.skill_id = sc.id WHERE fs.freelancer_id = ? ORDER BY sc.name ASC");
$sk_stmt->bind_param("i", $freelancer_id);
$sk_stmt->execute();
$skills = array_column($sk_stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'name');

$report_success = '';
$report_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'report_profile') {
    if (!is_logged_in()) {
        $report_error = "You must be logged in to report a profile.";
    } else {
        $reporter_id = (int)current_user_id();
        $reported_user_id = (int)$freelancer['user_id'];
        $reason = trim($_POST['reason'] ?? '');
        $details = trim($_POST['details'] ?? '');
        $allowed_reasons = ['scam_phishing', 'off_platform', 'fake_profile', 'harassment', 'spam', 'other'];

        if ($reporter_id === $reported_user_id) {
            $report_error = "You cannot report your own profile.";
        } elseif (!in_array($reason, $allowed_reasons)) {
            $report_error = "Please select a valid reason for the report.";
        } elseif (strlen($details) < 10) {
            $report_error = "Please provide at least 10 characters explaining the suspicious behavior.";
        } else {
            // Check for existing pending report from this user
            $check_stmt = $conn->prepare("SELECT id FROM profile_reports WHERE reporter_id = ? AND target_type = 'freelancer' AND target_profile_id = ? AND status = 'pending'");
            $check_stmt->bind_param("ii", $reporter_id, $freelancer_id);
            $check_stmt->execute();
            if ($check_stmt->get_result()->fetch_assoc()) {
                $report_error = "You already have a pending report under review for this freelancer.";
            } else {
                $ins_stmt = $conn->prepare("INSERT INTO profile_reports (reporter_id, reported_user_id, target_type, target_profile_id, reason, details, status) VALUES (?, ?, 'freelancer', ?, ?, ?, 'pending')");
                $ins_stmt->bind_param("iiiss", $reporter_id, $reported_user_id, $freelancer_id, $reason, $details);
                if ($ins_stmt->execute()) {
                    $report_success = "Report submitted successfully. Our trust and safety team will investigate this profile.";
                } else {
                    $report_error = "Failed to submit report. Please try again later.";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($freelancer['full_name']) ?> - FreeMark</title>
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
                <a href="freelancers.php" class="nav-active">Find Freelancers</a>
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
        <?php if (!empty($report_success)): ?>
            <div class="alert alert-success" style="background: rgba(34, 197, 94, 0.15); border: 1px solid rgba(34, 197, 94, 0.3); color: #22c55e; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                <i data-lucide="check-circle" style="width: 20px; height: 20px; flex-shrink: 0;"></i>
                <span><?= htmlspecialchars($report_success) ?></span>
            </div>
        <?php endif; ?>
        <?php if (!empty($report_error)): ?>
            <div class="alert alert-danger" style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                <i data-lucide="alert-circle" style="width: 20px; height: 20px; flex-shrink: 0;"></i>
                <span><?= htmlspecialchars($report_error) ?></span>
            </div>
        <?php endif; ?>

        <div class="profile-layout">
            <!-- Sidebar -->
            <aside class="profile-sidebar">
                <div class="profile-card">
                    <div class="profile-avatar-large">
                        <?= strtoupper(substr($freelancer['full_name'], 0, 2)) ?>
                    </div>
                    <h2 class="profile-name"><?= htmlspecialchars($freelancer['full_name']) ?></h2>
                    <p class="profile-title"><?= htmlspecialchars($freelancer['title'] ?? 'Professional Freelancer') ?></p>
                    
                    <span class="profile-badge-tag badge-freelancer">
                        <i data-lucide="shield-check" class="icon-sm"></i> Verified Talent
                    </span>

                    <div class="profile-stats-mini">
                        <div class="stat-mini">
                            <div class="stat-mini-val">
                                <?= $freelancer['avg_rating'] ? number_format((float)$freelancer['avg_rating'], 1) : '5.0' ?><span style="color:#facc15; font-size: 0.95rem;">★</span>
                            </div>
                            <div class="stat-mini-label">Rating</div>
                        </div>
                        <div class="stat-mini">
                            <div class="stat-mini-val"><?= format_currency($freelancer['hourly_rate'] ?? 0) ?></div>
                            <div class="stat-mini-label">Hourly</div>
                        </div>
                        <div class="stat-mini">
                            <div class="stat-mini-val"><?= (int)$freelancer['completed_contracts'] ?></div>
                            <div class="stat-mini-label">Jobs</div>
                        </div>
                    </div>

                    <div class="profile-actions">
                        <?php if (is_logged_in()): ?>
                            <?php if (current_user_role() === 'client'): ?>
                                <a href="../client/hire.php?freelancer_id=<?= $freelancer['id'] ?>" class="btn btn-primary btn-full">
                                    <i data-lucide="user-plus" class="icon-sm"></i> Hire Freelancer
                                </a>
                                <a href="../client/chat.php?with=<?= $freelancer['user_id'] ?>" class="btn btn-outline btn-full">
                                    <i data-lucide="message-square" class="icon-sm"></i> Send Message
                                </a>
                            <?php elseif ($freelancer['user_id'] == current_user_id()): ?>
                                <a href="../freelancer/profile.php" class="btn btn-primary btn-full">
                                    <i data-lucide="edit-3" class="icon-sm"></i> Edit My Profile
                                </a>
                            <?php endif; ?>
                        <?php else: ?>
                            <a href="../login-client.php" class="btn btn-primary btn-full">
                                <i data-lucide="lock" class="icon-sm"></i> Log In to Hire
                            </a>
                        <?php endif; ?>
                    </div>

                    <div class="profile-details-list">
                        <div class="detail-item">
                            <i data-lucide="check-circle" class="detail-icon detail-icon--success"></i>
                            <span>Identity Verified</span>
                        </div>
                        <div class="detail-item">
                            <i data-lucide="calendar" class="detail-icon"></i>
                            <span>Member since <?= date('M Y', strtotime($freelancer['created_at'])) ?></span>
                        </div>
                    </div>

                    <?php if ($freelancer['github_link'] || $freelancer['portfolio_link']): ?>
                        <div class="profile-links-card">
                            <h4>Portfolio & Links</h4>
                            <?php if ($freelancer['github_link']): ?>
                                <a href="<?= htmlspecialchars($freelancer['github_link']) ?>" target="_blank" class="link-row">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-github">
                                        <path d="M15 22v-4a4.8 4.8 0 0 0-1-3.5c3 0 6-2 6-5.5.08-1.25-.27-2.48-1-3.5.28-1.15.28-2.35 0-3.5 0 0-1 0-3 1.5-2.64-.5-5.36-.5-8 0C6 2 5 2 5 2c-.3 1.15-.3 2.35 0 3.5A5.403 5.403 0 0 0 4 9c0 3.5 3 5.5 6 5.5-.39.49-.68 1.05-.85 1.65-.17.6-.22 1.23-.15 1.85v4"></path>
                                        <path d="M9 18c-4.51 2-5-2-7-2"></path>
                                    </svg>
                                    <span>GitHub Profile</span>
                                    <i data-lucide="external-link" style="margin-left: auto; width: 13px; opacity: 0.6;"></i>
                                </a>
                            <?php endif; ?>
                            <?php if ($freelancer['portfolio_link']): ?>
                                <a href="<?= htmlspecialchars($freelancer['portfolio_link']) ?>" target="_blank" class="link-row">
                                    <i data-lucide="globe"></i>
                                    <span>Personal Website</span>
                                    <i data-lucide="external-link" style="margin-left: auto; width: 13px; opacity: 0.6;"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!is_logged_in() || current_user_id() != $freelancer['user_id']): ?>
                        <div style="padding-top: 14px; border-top: 1px solid var(--border-color); margin-top: 16px;">
                            <button type="button" class="btn-report-profile" onclick="openReportModal()">
                                <i data-lucide="flag" style="width: 14px; height: 14px;"></i>
                                <span>Report Suspicious Activity</span>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </aside>

            <!-- Main Sections -->
            <section class="profile-main">
                <!-- Overview -->
                <div class="profile-section">
                    <h3 class="section-title">
                        <i data-lucide="user"></i> Professional Overview
                    </h3>
                    <?php if (!empty($freelancer['bio'])): ?>
                        <?= render_expandable_text($freelancer['bio'], 260, 4, 'bio-paragraph') ?>
                    <?php else: ?>
                        <p class="bio-paragraph">No professional bio provided yet.</p>
                    <?php endif; ?>
                </div>

                <!-- Verified Skills -->
                <div class="profile-section">
                    <h3 class="section-title">
                        <i data-lucide="tag"></i> Skills & Specializations
                    </h3>
                    <?php if (empty($skills)): ?>
                        <p style="color: var(--text-secondary); margin: 0;">No specific skills listed.</p>
                    <?php else: ?>
                        <div class="skill-tags-cloud">
                            <?php foreach ($skills as $sk): ?>
                                <span class="profile-skill-tag"><?= htmlspecialchars($sk) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Verified Benchmark Assessments -->
                <?php if (!empty($verified_tests)): ?>
                    <div class="profile-section">
                        <h3 class="section-title">
                            <i data-lucide="award"></i> Verified Benchmark Badges (<?= count($verified_tests) ?>)
                        </h3>
                        <div class="badges-grid">
                            <?php foreach ($verified_tests as $vt): ?>
                                <div class="verified-badge-card">
                                    <div class="badge-medal-icon">
                                        <i data-lucide="shield-check" style="width: 22px; height: 22px;"></i>
                                    </div>
                                    <div class="badge-card-info">
                                        <h4><?= htmlspecialchars($vt['quiz_title']) ?></h4>
                                        <div class="badge-card-score">
                                            Score: <strong><?= $vt['score'] ?> / <?= $vt['max_score'] ?></strong> (<?= round(($vt['score'] / $vt['max_score']) * 100) ?>%) &bull; Verified ✓
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Client Reviews -->
                <div class="profile-section">
                    <h3 class="section-title">
                        <i data-lucide="star"></i> Client Reviews (<?= count($reviews) ?>)
                    </h3>
                    <?php if (empty($reviews)): ?>
                        <p style="color: var(--text-secondary); margin: 0;">No reviews posted yet.</p>
                    <?php else: ?>
                        <div class="reviews-stack">
                            <?php foreach ($reviews as $rev): ?>
                                <div class="profile-review-card">
                                    <div class="review-card-header">
                                        <div class="review-client-box">
                                            <div class="review-client-avatar">
                                                <?= strtoupper(substr($rev['company_name'] ?: $rev['client_name'], 0, 2)) ?>
                                            </div>
                                            <span class="review-client-name"><?= htmlspecialchars($rev['company_name'] ?: $rev['client_name']) ?></span>
                                        </div>
                                        <div class="review-stars-gold">
                                            <?= str_repeat('★', (int)$rev['stars']) ?>
                                        </div>
                                    </div>
                                    <div class="review-quote">
                                        <?= render_expandable_text('"' . $rev['comment'] . '"', 160, 3) ?>
                                    </div>
                                    <div class="review-timestamp">
                                        <?= date('M j, Y', strtotime($rev['created_at'])) ?>
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

    <!-- Report Profile Modal -->
    <div id="reportProfileModal" class="report-modal-backdrop" style="display: none;">
        <div class="report-modal-box">
            <div class="report-modal-header">
                <div class="report-modal-title-wrap">
                    <div class="report-modal-icon-badge">
                        <i data-lucide="flag" style="width: 18px; height: 18px;"></i>
                    </div>
                    <div>
                        <h3 style="margin: 0; font-size: 1.15rem; color: var(--text-primary);">Report Suspicious Profile</h3>
                        <p style="margin: 2px 0 0 0; font-size: 0.8rem; color: var(--text-secondary);">Help us maintain trust and safety on FreeMark</p>
                    </div>
                </div>
                <button type="button" class="report-modal-close-btn" onclick="closeReportModal()">
                    <i data-lucide="x" style="width: 18px; height: 18px;"></i>
                </button>
            </div>

            <?php if (!is_logged_in()): ?>
                <div class="report-modal-notice">
                    <i data-lucide="lock" style="width: 18px; height: 18px; flex-shrink: 0;"></i>
                    <span>You must be logged in to submit a report against this freelancer.</span>
                </div>
                <div class="report-modal-footer">
                    <button type="button" class="btn btn-outline btn-compact" onclick="closeReportModal()">Cancel</button>
                    <a href="../login.php" class="btn btn-primary btn-compact">Log In to Report</a>
                </div>
            <?php else: ?>
                <div class="report-modal-notice">
                    <i data-lucide="shield-alert" style="width: 18px; height: 18px; flex-shrink: 0;"></i>
                    <span>Reporting <strong><?= htmlspecialchars($freelancer['full_name']) ?></strong>. False or malicious reports violate platform terms.</span>
                </div>

                <form method="POST" action="">
                    <input type="hidden" name="action" value="report_profile">
                    <div class="report-form-group">
                        <label for="reportReason">Reason for Report <span style="color: #ef4444;">*</span></label>
                        <select id="reportReason" name="reason" required>
                            <option value="" disabled selected>Select a reason...</option>
                            <option value="scam_phishing">Scam, Phishing, or Financial Fraud</option>
                            <option value="off_platform">Asking for Off-Platform Escrow Bypass</option>
                            <option value="fake_profile">Fake Identity / Misleading Credentials</option>
                            <option value="harassment">Harassment, Abuse, or Inappropriate Conduct</option>
                            <option value="spam">Spam, Bot Promotion, or Suspicious Links</option>
                            <option value="other">Other Suspicious Activity</option>
                        </select>
                    </div>

                    <div class="report-form-group">
                        <label for="reportDetails">Detailed Description <span style="color: #ef4444;">*</span></label>
                        <textarea id="reportDetails" name="details" rows="4" required minlength="10" placeholder="Please describe the suspicious behavior, messages, or deliverables with specifics..."></textarea>
                    </div>

                    <div class="report-modal-footer">
                        <button type="button" class="btn btn-outline btn-compact" onclick="closeReportModal()">Cancel</button>
                        <button type="submit" class="btn btn-compact" style="background: #ef4444; border-color: #ef4444; color: #ffffff;">Submit Report</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <script src="../js/expandable.js"></script>
    <script>
        function openReportModal() {
            const modal = document.getElementById('reportProfileModal');
            if (modal) modal.style.display = 'flex';
        }
        function closeReportModal() {
            const modal = document.getElementById('reportProfileModal');
            if (modal) modal.style.display = 'none';
        }
        window.addEventListener('click', function(e) {
            const modal = document.getElementById('reportProfileModal');
            if (modal && e.target === modal) {
                closeReportModal();
            }
        });
        lucide.createIcons();
    </script>
</body>
</html>