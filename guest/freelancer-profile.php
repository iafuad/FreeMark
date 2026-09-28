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
                                    <i data-lucide="github"></i>
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
                </div>
            </aside>

            <!-- Main Sections -->
            <section class="profile-main">
                <!-- Overview -->
                <div class="profile-section">
                    <h3 class="section-title">
                        <i data-lucide="user"></i> Professional Overview
                    </h3>
                    <p class="bio-paragraph"><?= htmlspecialchars($freelancer['bio'] ?: 'No professional bio provided yet.') ?></p>
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
                                    <p class="review-quote">
                                        "<?= nl2br(htmlspecialchars($rev['comment'])) ?>"
                                    </p>
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
    <script>lucide.createIcons();</script>
</body>
</html>