<?php
require_once 'includes/auth.php';
require_once 'includes/helpers.php';
require_once 'config/db.php';

$user_role = current_user_role();
$dash_url = 'login.php';
if ($user_role === 'freelancer') {
    $dash_url = 'freelancer/dashboard.php';
} elseif ($user_role === 'client') {
    $dash_url = 'client/dashboard.php';
} elseif ($user_role === 'admin') {
    $dash_url = 'admin/dashboard.php';
}

// Fetch live open projects for showcase
$proj_res = $conn->query("SELECT p.*, cp.company_name, sc.name as skill_name,
    (SELECT COUNT(*) FROM proposals WHERE project_id = p.id) as proposal_count
    FROM projects p
    JOIN client_profiles cp ON p.client_id = cp.id
    LEFT JOIN skill_categories sc ON p.skill_category_id = sc.id
    WHERE p.status = 'open'
    ORDER BY p.created_at DESC LIMIT 4");
$open_projects = $proj_res ? $proj_res->fetch_all(MYSQLI_ASSOC) : [];

// Fetch live freelancers for showcase
$fl_res = $conn->query("SELECT fp.*, u.full_name,
    (SELECT AVG(stars) FROM reviews WHERE freelancer_id = fp.id) as avg_rating,
    (SELECT q.title FROM test_results tr JOIN quizzes q ON tr.quiz_id = q.id WHERE tr.freelancer_id = fp.id AND tr.passed = 1 ORDER BY tr.score DESC LIMIT 1) as verified_badge
    FROM freelancer_profiles fp
    JOIN users u ON fp.user_id = u.id
    WHERE u.status = 'active'
    ORDER BY fp.id ASC LIMIT 4");
$top_freelancers = $fl_res ? $fl_res->fetch_all(MYSQLI_ASSOC) : [];

// Fetch skills for each freelancer
foreach ($top_freelancers as &$tfl) {
    $sk_stmt = $conn->prepare("SELECT sc.name FROM freelancer_skills fs JOIN skill_categories sc ON fs.skill_id = sc.id WHERE fs.freelancer_id = ? LIMIT 3");
    $sk_stmt->bind_param("i", $tfl['id']);
    $sk_stmt->execute();
    $tfl['skills'] = array_column($sk_stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'name');
}
unset($tfl);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreeMark - Freelance Marketplace</title>
    <link rel="stylesheet" href="css/style.css">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
    <header>
        <div class="container navbar">
            <a href="index.php" class="logo">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>
                </svg>
                FreeMark
            </a>
            
            <nav class="nav-links">
                <a href="guest/jobs.php">Find Jobs</a>
                <a href="guest/freelancers.php">Find Freelancers</a>
            </nav>

            <div class="nav-actions">
                <?php if (is_logged_in()): ?>
                    <a href="<?= $dash_url ?>" class="btn btn-primary">Dashboard</a>
                    <a href="logout.php" class="btn btn-outline">Log Out</a>
                <?php else: ?>
                    <a href="login.php" class="btn btn-primary">Login/Sign up</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main>
        <section class="hero container">
            <h1>Hire the best.<br><span class="highlight">Verified by skill.</span></h1>
            
            <div class="search-tabs" style="display: flex; justify-content: center; gap: 10px; margin-top: 25px; margin-bottom: -25px;">
                <button type="button" id="tabFreelancers" style="padding: 6px 18px; font-size: 0.85rem; border-radius: 20px; background: var(--accent); color: #000; font-weight: 600; cursor: pointer; border: none; transition: all 0.2s;" onclick="setSearchType('freelancers')">Find Freelancers</button>
                <button type="button" id="tabJobs" style="padding: 6px 18px; font-size: 0.85rem; border-radius: 20px; background: rgba(255,255,255,0.08); color: var(--text-secondary); font-weight: 600; cursor: pointer; border: none; transition: all 0.2s;" onclick="setSearchType('jobs')">Find Jobs</button>
            </div>

            <form id="heroSearchForm" action="guest/freelancers.php" method="GET" class="search-bar">
                <i data-lucide="search"></i>
                <input type="text" id="heroSearchInput" name="search" placeholder="Search Full stack developers, UI/UX designers, ML Engineers.....">
                <button type="submit" class="btn btn-primary">Search</button>
            </form>

            <div class="stats">
                <div class="stat-item">
                    <h3>8,400+</h3>
                    <p>Freelancers</p>
                </div>
                <div class="stat-item">
                    <h3>5,600+</h3>
                    <p>Projects completed</p>
                </div>
                <div class="stat-item">
                    <h3>$2.8M+</h3>
                    <p>Paid out</p>
                </div>
                <div class="stat-item">
                    <h3>4.8★</h3>
                    <p>Avg. rating</p>
                </div>
            </div>
        </section>

        <!-- Open Projects Showcase -->
        <section class="container">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
                <h2 class="section-title" style="margin-bottom: 0;">Open Projects</h2>
                <a href="guest/jobs.php" class="btn btn-outline" style="padding: 8px 18px; font-size: 0.9rem; text-decoration: none;">View All Projects &rightarrow;</a>
            </div>
            <div class="projects-list">
                <?php if (empty($open_projects)): ?>
                    <p style="color: var(--text-secondary);">No open projects at the moment.</p>
                <?php else: ?>
                    <?php foreach ($open_projects as $p): ?>
                        <div class="project-card">
                            <div class="project-header">
                                <div>
                                    <h3><?= htmlspecialchars($p['title']) ?></h3>
                                    <p class="project-company"><?= htmlspecialchars($p['company_name']) ?></p>
                                </div>
                                <div class="project-price"><?= format_currency($p['budget_max']) ?></div>
                            </div>
                            <p class="project-desc"><?= htmlspecialchars(substr($p['description'], 0, 140)) ?>...</p>
                            <div class="tags">
                                <?php if ($p['skill_name']): ?>
                                    <span class="tag"><?= htmlspecialchars($p['skill_name']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="project-meta">
                                <span><i data-lucide="file-text" style="width: 16px;"></i> <?= (int)$p['proposal_count'] ?> proposals</span>
                                <span><i data-lucide="clock" style="width: 16px;"></i> <?= date('M j', strtotime($p['created_at'])) ?></span>
                                <span><i data-lucide="calendar" style="width: 16px;"></i> <?= format_duration($p['duration']) ?></span>
                            </div>
                            <a href="guest/job-details.php?id=<?= $p['id'] ?>" class="btn btn-outline">View Details</a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <!-- Top Freelancers Showcase -->
        <section class="container">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
                <h2 class="section-title" style="margin-bottom: 0;">Top Freelancers</h2>
                <a href="guest/freelancers.php" class="btn btn-outline" style="padding: 8px 18px; font-size: 0.9rem; text-decoration: none;">View All Freelancers &rightarrow;</a>
            </div>
            <div class="freelancers-grid">
                <?php if (empty($top_freelancers)): ?>
                    <p style="color: var(--text-secondary);">No active freelancers yet.</p>
                <?php else: ?>
                    <?php foreach ($top_freelancers as $idx => $fl): ?>
                        <div class="freelancer-card">
                            <div class="freelancer-top">
                                <div class="freelancer-info">
                                    <div class="avatar <?= ($idx % 2 == 1) ? 'purple' : '' ?>"><?= strtoupper(substr($fl['full_name'], 0, 2)) ?></div>
                                    <div>
                                        <div class="freelancer-name"><?= htmlspecialchars($fl['full_name']) ?></div>
                                        <div class="freelancer-title"><?= htmlspecialchars($fl['title'] ?? 'Freelancer') ?></div>
                                    </div>
                                </div>
                                <div class="rank">#<?= ($idx + 1) ?></div>
                            </div>
                            <div class="freelancer-stats">
                                <div class="rating">
                                    <?php if ($fl['verified_badge']): ?>
                                        <span>🏅 Verified</span>
                                    <?php endif; ?>
                                    <span>★ <?= $fl['avg_rating'] ? number_format((float)$fl['avg_rating'], 1) : '5.0' ?></span>
                                </div>
                                <div class="rate"><?= format_currency($fl['hourly_rate'] ?? 0) ?>/hr</div>
                            </div>
                            <div class="tags" style="margin-bottom: 25px;">
                                <?php foreach ($fl['skills'] as $sk): ?>
                                    <span class="tag"><?= htmlspecialchars($sk) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <a href="guest/freelancer-profile.php?id=<?= $fl['id'] ?>" class="btn btn-outline">View Profile</a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <footer>
        <div class="container">
            <p>&copy; 2026 FreeMark. All rights reserved.</p>
        </div>
    </footer>

    <script>
        lucide.createIcons();

        function setSearchType(type) {
            const form = document.getElementById('heroSearchForm');
            const input = document.getElementById('heroSearchInput');
            const tabF = document.getElementById('tabFreelancers');
            const tabJ = document.getElementById('tabJobs');
            if (type === 'jobs') {
                form.action = 'guest/jobs.php';
                input.name = 'q';
                input.placeholder = 'Search React, Node.js, Mobile apps, APIs.....';
                tabJ.style.background = 'var(--accent)';
                tabJ.style.color = '#000';
                tabF.style.background = 'rgba(255,255,255,0.08)';
                tabF.style.color = 'var(--text-secondary)';
            } else {
                form.action = 'guest/freelancers.php';
                input.name = 'search';
                input.placeholder = 'Search Full stack developers, UI/UX designers, ML Engineers.....';
                tabF.style.background = 'var(--accent)';
                tabF.style.color = '#000';
                tabJ.style.background = 'rgba(255,255,255,0.08)';
                tabJ.style.color = 'var(--text-secondary)';
            }
        }
    </script>
</body>
</html>
