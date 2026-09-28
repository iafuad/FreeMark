<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';
require_role('admin');

$message = '';
$error = '';

// Handle Delete Quiz
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_quiz_id'])) {
    $del_id = (int)$_POST['delete_quiz_id'];
    if ($del_id > 0) {
        $del_stmt = $conn->prepare("DELETE FROM quizzes WHERE id = ?");
        $del_stmt->bind_param("i", $del_id);
        if ($del_stmt->execute()) {
            $message = "Quiz deleted successfully.";
        } else {
            $error = "Failed to delete quiz. Please try again.";
        }
    }
}

// Fetch platform quiz analytics in a single query
$analytics_res = $conn->query("SELECT 
    (SELECT COUNT(*) FROM quizzes) as total_quizzes,
    (SELECT COUNT(*) FROM quiz_questions) as total_questions,
    (SELECT COUNT(*) FROM test_results) as total_attempts,
    (SELECT COUNT(*) FROM test_results WHERE passed = 1) as passed_attempts");
$analytics = $analytics_res ? $analytics_res->fetch_assoc() : [];
$total_quizzes = (int)($analytics['total_quizzes'] ?? 0);
$total_questions = (int)($analytics['total_questions'] ?? 0);
$total_attempts = (int)($analytics['total_attempts'] ?? 0);
$passed_attempts = (int)($analytics['passed_attempts'] ?? 0);
$pass_rate = $total_attempts > 0 ? round(($passed_attempts / $total_attempts) * 100) : 0;

// Paginate (6 per page)
$pag = paginate($total_quizzes, 6);

// Fetch quizzes with question count and attempts count
$sql = "SELECT q.*, 
        (SELECT COUNT(*) FROM quiz_questions WHERE quiz_id = q.id) as question_count,
        (SELECT COUNT(*) FROM test_results WHERE quiz_id = q.id) as attempt_count,
        (SELECT COUNT(*) FROM test_results WHERE quiz_id = q.id AND passed = 1) as passed_count
        FROM quizzes q 
        ORDER BY q.created_at DESC
        LIMIT ? OFFSET ?";
$q_stmt = $conn->prepare($sql);
$q_stmt->bind_param("ii", $pag['per_page'], $pag['offset']);
$q_stmt->execute();
$quizzes = $q_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quiz Banks - FreeMark Admin</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/admin/layout.css">
    <link rel="stylesheet" href="../css/admin/components.css">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
    <aside class="admin-sidebar">
        <div class="sidebar-header">
            <a href="../index.php" class="logo">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>
                </svg>
                FreeMark Admin
            </a>
        </div>
        <nav class="sidebar-nav">
            <ul>
                <li><a href="dashboard.php"><i data-lucide="layout-dashboard"></i> Overview</a></li>
                <li><a href="approvals.php"><i data-lucide="check-circle"></i> Approvals</a></li>
                <li><a href="skills.php"><i data-lucide="award"></i> Skill Categories</a></li>
                <li><a href="quizzes.php" class="active"><i data-lucide="help-circle"></i> Quiz Banks</a></li>
                <li><a href="users.php"><i data-lucide="users"></i> User Management</a></li>
                <li><a href="reports.php"><i data-lucide="file-text"></i> Reports</a></li>
            </ul>
        </nav>
        <div class="sidebar-footer">
            <a href="../logout.php" style="color: var(--text-secondary); text-decoration: none; display: flex; align-items: center; gap: 10px;">
                <i data-lucide="log-out"></i> Logout
            </a>
        </div>
    </aside>

    <main class="admin-main">
        <header class="admin-header">
            <div class="header-title">
                <h2>Manage Quiz & Question Banks</h2>
                <p>Manage benchmark skill verification assessments</p>
            </div>
            <a href="quiz-editor.php" class="btn btn-primary" style="text-decoration: none;">+ Create New Quiz</a>
        </header>

        <div class="admin-content">
            <?php if ($message): ?>
                <div style="background: rgba(34, 197, 94, 0.15); border: 1px solid rgba(34, 197, 94, 0.3); color: #22c55e; padding: 12px 16px; border-radius: 6px; margin-bottom: 20px;">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444; padding: 12px 16px; border-radius: 6px; margin-bottom: 20px;">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- Analytics Strip -->
            <div class="sleek-stats-grid" style="margin-bottom: 25px;">
                <div class="sleek-stat-card">
                    <div class="sleek-stat-header">
                        <span class="sleek-stat-title">Active Quizzes</span>
                        <div class="sleek-stat-icon icon-purple"><i data-lucide="help-circle"></i></div>
                    </div>
                    <div class="sleek-stat-value"><?= $total_quizzes ?></div>
                    <div class="sleek-stat-trend trend-neutral"><i data-lucide="layers" class="icon-sm"></i> Test Banks</div>
                </div>

                <div class="sleek-stat-card">
                    <div class="sleek-stat-header">
                        <span class="sleek-stat-title">Questions Banked</span>
                        <div class="sleek-stat-icon icon-blue"><i data-lucide="file-text"></i></div>
                    </div>
                    <div class="sleek-stat-value"><?= $total_questions ?></div>
                    <div class="sleek-stat-trend trend-neutral"><i data-lucide="check-square" class="icon-sm"></i> Total Questions</div>
                </div>

                <div class="sleek-stat-card">
                    <div class="sleek-stat-header">
                        <span class="sleek-stat-title">Total Attempts</span>
                        <div class="sleek-stat-icon icon-yellow"><i data-lucide="user-check"></i></div>
                    </div>
                    <div class="sleek-stat-value"><?= $total_attempts ?></div>
                    <div class="sleek-stat-trend trend-up"><i data-lucide="trending-up" class="icon-sm"></i> Submissions</div>
                </div>

                <div class="sleek-stat-card">
                    <div class="sleek-stat-header">
                        <span class="sleek-stat-title">Avg Pass Rate</span>
                        <div class="sleek-stat-icon icon-green"><i data-lucide="award"></i></div>
                    </div>
                    <div class="sleek-stat-value"><?= $pass_rate ?>%</div>
                    <div class="sleek-stat-trend trend-up"><i data-lucide="check-circle" class="icon-sm"></i> <?= $passed_attempts ?> Passed</div>
                </div>
            </div>

            <div class="admin-card">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Test Title</th>
                            <th>Passing Score</th>
                            <th>Questions</th>
                            <th>Attempts</th>
                            <th>Pass Rate</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($quizzes)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 30px; color: var(--text-secondary);">No quizzes created yet. Click "+ Create New Quiz" to get started.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($quizzes as $quiz): ?>
                                <?php 
                                    $q_attempts = (int)$quiz['attempt_count'];
                                    $q_passed = (int)$quiz['passed_count'];
                                    $q_rate = $q_attempts > 0 ? round(($q_passed / $q_attempts) * 100) : 0;
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($quiz['title']) ?></strong>
                                        <?php if (!empty($quiz['description'])): ?>
                                            <div style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 3px;">
                                                <?= htmlspecialchars(substr($quiz['description'], 0, 80)) ?><?= strlen($quiz['description']) > 80 ? '...' : '' ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge warning"><?= (int)$quiz['passing_score'] ?>% Required</span>
                                    </td>
                                    <td><strong><?= (int)$quiz['question_count'] ?></strong> question<?= $quiz['question_count'] == 1 ? '' : 's' ?></td>
                                    <td><strong><?= $q_attempts ?></strong></td>
                                    <td>
                                        <?php if ($q_attempts > 0): ?>
                                            <span style="color: <?= $q_rate >= 50 ? '#22c55e' : '#eab308' ?>; font-weight: 600;"><?= $q_rate ?>%</span>
                                            <span style="font-size: 0.8rem; color: var(--text-secondary);">(<?= $q_passed ?>/<?= $q_attempts ?>)</span>
                                        <?php else: ?>
                                            <span style="color: var(--text-secondary); font-size: 0.85rem;">No attempts</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 8px; align-items: center;">
                                            <a href="quiz-editor.php?id=<?= $quiz['id'] ?>" class="btn-sm btn-outline" style="text-decoration: none;">Edit</a>
                                            <form method="POST" action="quizzes.php" style="display: inline; margin: 0;" onsubmit="return confirm('Are you sure you want to delete \'<?= htmlspecialchars(addslashes($quiz['title'])) ?>\'? All questions and historical results will be deleted.');">
                                                <input type="hidden" name="delete_quiz_id" value="<?= $quiz['id'] ?>">
                                                <button type="submit" class="btn-sm btn-outline" style="color: #ef4444; border-color: rgba(239, 68, 68, 0.4); cursor: pointer;">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <div style="padding: 0 20px 20px 20px;">
                    <?= render_pagination($pag, 'quizzes.php') ?>
                </div>
            </div>
        </div>
    </main>
    <script>lucide.createIcons();</script>
</body>
</html>
