<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

require_role('freelancer');
$user_id = current_user_id();
$profile_id = get_profile_id($conn, $user_id, 'freelancer');

// Fetch completed tests aggregated by quiz (highest score, latest attempt, attempt count)
$comp_stmt = $conn->prepare("SELECT 
        tr.quiz_id,
        q.title as quiz_title,
        q.description as quiz_description,
        q.passing_score,
        MAX(tr.score) as best_score,
        MAX(tr.max_score) as max_score,
        MAX(tr.passed) as has_passed,
        COUNT(tr.id) as attempt_count,
        MAX(tr.created_at) as latest_attempt_at
    FROM test_results tr
    JOIN quizzes q ON tr.quiz_id = q.id
    WHERE tr.freelancer_id = ?
    GROUP BY tr.quiz_id, q.title, q.description, q.passing_score
    ORDER BY latest_attempt_at DESC");
$comp_stmt->bind_param("i", $profile_id);
$comp_stmt->execute();
$completed_tests = $comp_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Map quiz status by quiz_id for quick lookup
$quiz_stats_map = [];
$verified_count = 0;
foreach ($completed_tests as $ct) {
    $quiz_stats_map[$ct['quiz_id']] = $ct;
    if ($ct['has_passed'] == 1) {
        $verified_count++;
    }
}

// Fetch available tests with question count
$quizzes_res = $conn->query("SELECT q.*, 
    (SELECT COUNT(*) FROM quiz_questions WHERE quiz_id = q.id) as question_count
    FROM quizzes q
    ORDER BY q.title ASC");
$all_quizzes = $quizzes_res ? $quizzes_res->fetch_all(MYSQLI_ASSOC) : [];
$total_available = count($all_quizzes);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Skill Tests - FreeMark</title>
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
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>
                </svg>
                FreeMark
            </a>
        </div>
        <nav class="sidebar-nav">
            <ul>
                <li><a href="dashboard.php"><i data-lucide="layout-dashboard"></i> Overview</a></li>
                <li><a href="profile.php"><i data-lucide="user"></i> My Profile</a></li>
                <li><a href="jobs.php"><i data-lucide="briefcase"></i> Find Jobs</a></li>
                <li><a href="tests.php" class="active"><i data-lucide="check-square"></i> Skill Tests</a></li>
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
                <h2>Skill Verification Tests</h2>
                <p>Pass benchmark tests to earn verified badges and elevate your proposal rankings.</p>
            </div>
        </header>

        <div class="freelancer-content">
            <!-- Stats Strip -->
            <div class="sleek-stats-grid" style="margin-bottom: 25px;">
                <div class="sleek-stat-card">
                    <div class="sleek-stat-header">
                        <span class="sleek-stat-title">Verified Badges</span>
                        <div class="sleek-stat-icon icon-purple"><i data-lucide="award" class="icon-base"></i></div>
                    </div>
                    <div class="sleek-stat-value"><?= $verified_count ?></div>
                    <div class="sleek-stat-trend trend-up"><i data-lucide="check-circle" class="icon-sm"></i> Passed Assessments</div>
                </div>

                <div class="sleek-stat-card">
                    <div class="sleek-stat-header">
                        <span class="sleek-stat-title">Available Quizzes</span>
                        <div class="sleek-stat-icon icon-blue"><i data-lucide="layers" class="icon-base"></i></div>
                    </div>
                    <div class="sleek-stat-value"><?= $total_available ?></div>
                    <div class="sleek-stat-trend trend-neutral"><i data-lucide="check-square" class="icon-sm"></i> Benchmark Tests</div>
                </div>

                <div class="sleek-stat-card">
                    <div class="sleek-stat-header">
                        <span class="sleek-stat-title">Total Attempts</span>
                        <div class="sleek-stat-icon icon-yellow"><i data-lucide="trending-up" class="icon-base"></i></div>
                    </div>
                    <div class="sleek-stat-value"><?= count($completed_tests) > 0 ? array_sum(array_column($completed_tests, 'attempt_count')) : 0 ?></div>
                    <div class="sleek-stat-trend trend-neutral"><i data-lucide="clock" class="icon-sm"></i> Tests Taken</div>
                </div>

                <div class="sleek-stat-card">
                    <div class="sleek-stat-header">
                        <span class="sleek-stat-title">Profile Advantage</span>
                        <div class="sleek-stat-icon icon-green"><i data-lucide="star" class="icon-base"></i></div>
                    </div>
                    <div class="sleek-stat-value"><?= $verified_count > 0 ? 'Active' : 'Pending' ?></div>
                    <div class="sleek-stat-trend trend-up"><i data-lucide="shield-check" class="icon-sm"></i> Top Search Priority</div>
                </div>
            </div>

            <div class="suggestion-alert" style="margin-bottom: 30px;">
                <h4><i data-lucide="award" class="icon-base" style="margin-right: 5px;"></i> Verified Skill Advantage</h4>
                <p>Freelancers with verified badges have a <strong>3x higher hire rate</strong> on FreeMark. Clients prioritize candidates who pass our benchmark quizzes.</p>
            </div>

            <!-- Available Tests -->
            <h3 class="mb-20">Available Quizzes (<?= $total_available ?>)</h3>
            <div class="test-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; margin-bottom: 35px;">
                <?php if (empty($all_quizzes)): ?>
                    <p style="color: var(--text-secondary);">No quizzes created by admin yet.</p>
                <?php else: ?>
                    <?php foreach ($all_quizzes as $quiz): ?>
                        <?php 
                            $q_id = $quiz['id'];
                            $has_attempt = isset($quiz_stats_map[$q_id]);
                            $stat = $has_attempt ? $quiz_stats_map[$q_id] : null;
                            $passed = $stat && $stat['has_passed'] == 1;
                        ?>
                        <div class="test-card" style="padding: 22px; border: 1px solid var(--border-color); border-radius: 8px; background: var(--bg-card); display: flex; flex-direction: column;">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; gap: 8px;">
                                <h4 style="margin: 0; font-size: 1.05rem;"><?= htmlspecialchars($quiz['title']) ?></h4>
                                <?php if ($passed): ?>
                                    <span class="status-badge success" style="flex-shrink: 0;">Verified ✓</span>
                                <?php elseif ($has_attempt): ?>
                                    <span class="status-badge danger" style="flex-shrink: 0;">Needs Retake</span>
                                <?php else: ?>
                                    <span class="status-badge warning" style="flex-shrink: 0;">Pass: <?= (int)$quiz['passing_score'] ?>%</span>
                                <?php endif; ?>
                            </div>
                            <p style="color: var(--text-secondary); font-size: 0.85rem; min-height: 40px; margin-bottom: 15px; line-height: 1.5;">
                                <?= htmlspecialchars($quiz['description'] ?: 'Benchmark assessment test.') ?>
                            </p>
                            <div style="font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 18px; margin-top: auto;">
                                <i data-lucide="help-circle" style="width: 13px; display: inline; vertical-align: middle;"></i> Questions: <strong><?= (int)$quiz['question_count'] ?></strong>
                                <?php if ($has_attempt): ?>
                                    &bull; Best: <strong><?= $stat['best_score'] ?>/<?= $stat['max_score'] ?></strong>
                                <?php endif; ?>
                            </div>
                            <a href="take-quiz.php?id=<?= $quiz['id'] ?>" class="btn <?= $passed ? 'btn-outline' : 'btn-primary' ?>" style="width: 100%; text-align: center; text-decoration: none; display: block;">
                                <?= $passed ? 'Retake Test' : ($has_attempt ? 'Retake to Pass' : 'Take Test') ?>
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Completed Tests Overview -->
            <?php if (!empty($completed_tests)): ?>
                <h3 class="mt-30 mb-20">Your Assessment Records (<?= count($completed_tests) ?>)</h3>
                <div class="test-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px;">
                    <?php foreach ($completed_tests as $ct): ?>
                        <?php 
                            $pct = ($ct['max_score'] > 0) ? round(($ct['best_score'] / $ct['max_score']) * 100) : 0;
                            $passed = ($ct['has_passed'] == 1) || ($pct >= (int)$ct['passing_score']);
                        ?>
                        <div class="test-card" style="padding: 22px; border: 1px solid <?= $passed ? 'rgba(34, 197, 94, 0.4)' : 'rgba(239, 68, 68, 0.4)' ?>; border-radius: 8px; background: var(--bg-card); display: flex; flex-direction: column;">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; gap: 8px;">
                                <h4 style="margin: 0; font-size: 1.05rem;"><?= htmlspecialchars($ct['quiz_title']) ?></h4>
                                <span class="status-badge <?= $passed ? 'success' : 'danger' ?>" style="flex-shrink: 0;">
                                    <?= $passed ? 'Verified ✓' : 'Failed' ?>
                                </span>
                            </div>
                            <div style="margin: 5px 0 10px 0; font-size: 1.2rem; font-weight: bold; color: <?= $passed ? '#22c55e' : '#ef4444' ?>;">
                                Best: <?= $ct['best_score'] ?> / <?= $ct['max_score'] ?> (<?= $pct ?>%)
                            </div>
                            <div style="color: var(--text-secondary); font-size: 0.8rem; margin-bottom: 18px; margin-top: auto; line-height: 1.6;">
                                <div><i data-lucide="rotate-ccw" style="width: 12px; display: inline;"></i> Total Attempts: <strong><?= (int)$ct['attempt_count'] ?></strong></div>
                                <div><i data-lucide="clock" style="width: 12px; display: inline;"></i> Latest: <?= date('M j, Y', strtotime($ct['latest_attempt_at'])) ?></div>
                            </div>
                            <a href="take-quiz.php?id=<?= $ct['quiz_id'] ?>" class="btn btn-outline" style="width: 100%; text-align: center; text-decoration: none; display: block; font-size: 0.85rem;">
                                Retake Quiz
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
    <script>lucide.createIcons();</script>
</body>
</html>
