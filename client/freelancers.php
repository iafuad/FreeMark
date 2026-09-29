<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';
require_role('client');
$client_id = get_profile_id($conn, current_user_id(), 'client');

// Count active freelancers
$count_res = $conn->query("SELECT COUNT(*) FROM freelancer_profiles fp JOIN users u ON fp.user_id = u.id WHERE u.status = 'active'");
$total_freelancers = (int)$count_res->fetch_row()[0];

// Paginate (6 per page)
$pag = paginate($total_freelancers, 6);

// Fetch active freelancers with verified quiz badges and ratings
$sql = "SELECT fp.*, u.full_name, u.id as user_id,
    (SELECT AVG(stars) FROM reviews WHERE freelancer_id = fp.id) as avg_rating,
    (SELECT COUNT(*) FROM reviews WHERE freelancer_id = fp.id) as rev_count,
    (SELECT q.title FROM test_results tr JOIN quizzes q ON tr.quiz_id = q.id WHERE tr.freelancer_id = fp.id AND tr.passed = 1 ORDER BY tr.score DESC LIMIT 1) as top_quiz_title,
    (SELECT tr.score FROM test_results tr WHERE tr.freelancer_id = fp.id AND tr.passed = 1 ORDER BY tr.score DESC LIMIT 1) as top_quiz_score,
    (SELECT tr.max_score FROM test_results tr WHERE tr.freelancer_id = fp.id AND tr.passed = 1 ORDER BY tr.score DESC LIMIT 1) as top_quiz_max
FROM freelancer_profiles fp
JOIN users u ON fp.user_id = u.id
WHERE u.status = 'active'
ORDER BY top_quiz_score DESC, fp.id ASC
LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $pag['per_page'], $pag['offset']);
$stmt->execute();
$freelancers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch skills for each freelancer
foreach ($freelancers as &$fl) {
    $sk_stmt = $conn->prepare("SELECT sc.name FROM freelancer_skills fs JOIN skill_categories sc ON fs.skill_id = sc.id WHERE fs.freelancer_id = ?");
    $sk_stmt->bind_param("i", $fl['id']);
    $sk_stmt->execute();
    $fl['skills'] = array_column($sk_stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'name');
}
unset($fl);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Freelancer Recommendations - FreeMark</title>
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
                <li><a href="freelancers.php" class="active"><i data-lucide="users"></i> Freelancers</a></li>
                <li><a href="proposals.php"><i data-lucide="file-text"></i> Proposals</a></li>
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
                <h2>Browse Top Freelancers</h2>
                <p>Curated talent ranked by verified skill benchmark results</p>
            </div>
            <div class="client-header-actions">
                <a href="post-project.php" class="btn btn-primary btn-header-action">+ Post New Project</a>
            </div>
        </header>

        <div class="client-content">
            <div class="freelancer-list" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px;">
                <?php if (empty($freelancers)): ?>
                    <p style="padding: 20px; color: var(--text-secondary);">No active freelancers registered yet.</p>
                <?php else: ?>
                    <?php foreach ($freelancers as $fl): ?>
                        <div class="freelancer-card" style="padding: 25px; border: 1px solid var(--border-color); border-radius: 8px; background: var(--card-bg, #1e293b); display: flex; flex-direction: column; justify-content: space-between;">
                            <div>
                                <div class="freelancer-card-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                                    <?php if ($fl['top_quiz_title']): ?>
                                        <span class="match-badge" style="background: rgba(34, 197, 94, 0.15); color: #22c55e; padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">
                                            <i data-lucide="award" class="icon-xs" style="display: inline;"></i> Verified: <?= htmlspecialchars($fl['top_quiz_title']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="font-size: 0.75rem; color: var(--text-secondary);">Talent Candidate</span>
                                    <?php endif; ?>
                                    <span class="freelancer-status text-success" style="font-size: 0.8rem; color: #22c55e;">Available</span>
                                </div>

                                <div class="freelancer-header-info" style="display: flex; gap: 15px; align-items: center; margin-bottom: 15px;">
                                    <div class="freelancer-avatar purple" style="width: 45px; height: 45px; border-radius: 50%; background: rgba(99, 102, 241, 0.2); color: #818cf8; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 1.1rem;">
                                        <?= strtoupper(substr($fl['full_name'], 0, 2)) ?>
                                    </div>
                                    <div class="freelancer-details">
                                        <h4 style="margin: 0 0 4px 0; font-size: 1.1rem;"><?= htmlspecialchars($fl['full_name']) ?></h4>
                                        <p style="margin: 0; font-size: 0.85rem; color: var(--text-secondary);"><?= htmlspecialchars($fl['title'] ?? 'Freelancer') ?></p>
                                    </div>
                                </div>

                                <?php if ($fl['top_quiz_title']): ?>
                                    <div class="skill-score" style="padding: 8px 12px; background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: 4px; margin-bottom: 15px; font-size: 0.85rem; display: flex; justify-content: space-between;">
                                        <span><?= htmlspecialchars($fl['top_quiz_title']) ?></span>
                                        <strong style="color: #22c55e;">Score: <?= $fl['top_quiz_score'] ?> / <?= $fl['top_quiz_max'] ?></strong>
                                    </div>
                                <?php endif; ?>

                                <div class="freelancer-stats-row" style="display: flex; justify-content: space-between; margin-bottom: 15px; padding: 10px 0; border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color);">
                                    <div class="stat-col">
                                        <span class="stat-label" style="display: block; font-size: 0.75rem; color: var(--text-secondary);">Hourly Rate</span>
                                        <span class="stat-value" style="font-weight: 600;"><?= format_currency($fl['hourly_rate'] ?? 0) ?>/hr</span>
                                    </div>
                                    <div class="stat-col">
                                        <span class="stat-label" style="display: block; font-size: 0.75rem; color: var(--text-secondary);">Rating</span>
                                        <div class="stat-value rating" style="font-weight: 600;">
                                            <span><?= $fl['avg_rating'] ? number_format((float)$fl['avg_rating'], 1) : '5.0' ?> ★</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="tags tags-spaced" style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 20px;">
                                    <?php foreach ($fl['skills'] as $sk): ?>
                                        <span class="tag" style="background: rgba(255,255,255,0.05); padding: 3px 8px; border-radius: 4px; font-size: 0.75rem;"><?= htmlspecialchars($sk) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="freelancer-actions" style="display: flex; gap: 10px;">
                                <a href="chat.php?with=<?= $fl['user_id'] ?>" class="btn btn-outline" style="flex: 1; text-align: center; text-decoration: none; font-size: 0.85rem;">Message</a>
                                <a href="hire.php?freelancer_id=<?= $fl['id'] ?>" class="btn btn-primary" style="flex: 1; text-align: center; text-decoration: none; font-size: 0.85rem;">Invite to Job</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?= render_pagination($pag, 'freelancers.php') ?>
        </div>
    </main>
    <script>lucide.createIcons();</script>
</body>
</html>
