<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';
require_role('admin');

$quiz_id = (int)($_GET['id'] ?? 0);
$error = '';
$success = '';

if (isset($_GET['created'])) {
    $success = "Quiz created successfully! Now add questions below.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Action: Delete a question
    if ($action === 'delete_question') {
        $del_q_id = (int)($_POST['del_question_id'] ?? 0);
        if ($del_q_id > 0 && $quiz_id > 0) {
            $del_stmt = $conn->prepare("DELETE FROM quiz_questions WHERE id = ? AND quiz_id = ?");
            $del_stmt->bind_param("ii", $del_q_id, $quiz_id);
            if ($del_stmt->execute()) {
                $success = "Question deleted successfully.";
            } else {
                $error = "Failed to delete question.";
            }
        }
    }
    // Action: Add question
    elseif ($action === 'add_question') {
        $new_question = trim($_POST['new_question'] ?? '');
        $raw_opts = $_POST['options'] ?? [];
        $correct_idx = (int)($_POST['correct_option'] ?? 0);

        // Filter non-empty options
        $valid_opts = [];
        foreach ($raw_opts as $idx => $opt_text) {
            $t = trim($opt_text);
            if ($t !== '') {
                $valid_opts[$idx] = $t;
            }
        }

        if ($new_question === '') {
            $error = "Question text cannot be empty.";
        } elseif (count($valid_opts) < 2) {
            $error = "Please provide at least 2 non-empty options for the question.";
        } elseif (!isset($valid_opts[$correct_idx])) {
            $error = "The option selected as the correct answer cannot be empty.";
        } elseif ($quiz_id <= 0) {
            $error = "Please save quiz settings before adding questions.";
        } else {
            $q_stmt = $conn->prepare("INSERT INTO quiz_questions (quiz_id, question_text) VALUES (?, ?)");
            $q_stmt->bind_param("is", $quiz_id, $new_question);
            if ($q_stmt->execute()) {
                $q_id = $conn->insert_id;
                foreach ($valid_opts as $idx => $opt_text) {
                    $is_correct = ($idx === $correct_idx) ? 1 : 0;
                    $stmt_opt = $conn->prepare("INSERT INTO question_options (question_id, option_text, is_correct) VALUES (?, ?, ?)");
                    $stmt_opt->bind_param("isi", $q_id, $opt_text, $is_correct);
                    $stmt_opt->execute();
                }
                $success = "Question added successfully.";
            } else {
                $error = "Failed to save question.";
            }
        }
    }
    // Action: Save quiz settings
    else {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $passing_score = (int)($_POST['passing_score'] ?? 70);
        if ($passing_score < 1) $passing_score = 1;
        if ($passing_score > 100) $passing_score = 100;

        if ($title === '') {
            $error = "Quiz title cannot be empty.";
        } else {
            if ($quiz_id > 0) {
                $stmt = $conn->prepare("UPDATE quizzes SET title = ?, description = ?, passing_score = ? WHERE id = ?");
                $stmt->bind_param("ssii", $title, $description, $passing_score, $quiz_id);
                $stmt->execute();
                $success = "Quiz settings updated successfully.";
            } else {
                $stmt = $conn->prepare("INSERT INTO quizzes (title, description, passing_score) VALUES (?, ?, ?)");
                $stmt->bind_param("ssi", $title, $description, $passing_score);
                $stmt->execute();
                $quiz_id = $conn->insert_id;
                header("Location: quiz-editor.php?id=" . $quiz_id . "&created=1");
                exit;
            }
        }
    }
}

// Fetch quiz data with efficient 2-query batch loading (no N+1 queries)
$quiz = null;
$questions = [];
if ($quiz_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM quizzes WHERE id = ?");
    $stmt->bind_param("i", $quiz_id);
    $stmt->execute();
    $quiz = $stmt->get_result()->fetch_assoc();

    if ($quiz) {
        $q_stmt = $conn->prepare("SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY id ASC");
        $q_stmt->bind_param("i", $quiz_id);
        $q_stmt->execute();
        $questions = $q_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        if (!empty($questions)) {
            $q_ids = array_column($questions, 'id');
            $in_clause = implode(',', array_fill(0, count($q_ids), '?'));
            $opt_stmt = $conn->prepare("SELECT * FROM question_options WHERE question_id IN ($in_clause) ORDER BY id ASC");
            $opt_stmt->bind_param(str_repeat('i', count($q_ids)), ...$q_ids);
            $opt_stmt->execute();
            $all_options = $opt_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            $options_by_q = [];
            foreach ($all_options as $opt) {
                $options_by_q[$opt['question_id']][] = $opt;
            }
            foreach ($questions as &$q) {
                $q['options'] = $options_by_q[$q['id']] ?? [];
            }
            unset($q);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $quiz ? 'Edit Quiz: ' . htmlspecialchars($quiz['title']) : 'Create Quiz' ?> - FreeMark Admin</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/admin/layout.css">
    <link rel="stylesheet" href="../css/admin/components.css">
    <link rel="stylesheet" href="../css/admin/quiz.css">
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
                <h2><?= $quiz ? 'Editing: ' . htmlspecialchars($quiz['title']) : 'Create New Benchmark Quiz' ?></h2>
                <p>Configure test parameters and manage question bank</p>
            </div>
            <div style="display: flex; gap: 10px;">
                <a href="quizzes.php" class="btn btn-outline" style="text-decoration: none;">&larr; Back to Quizzes</a>
            </div>
        </header>

        <div class="admin-content">
            <?php if ($success): ?>
                <div style="background: rgba(34, 197, 94, 0.15); border: 1px solid rgba(34, 197, 94, 0.3); color: #22c55e; padding: 12px 16px; border-radius: 6px; margin-bottom: 20px;">
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444; padding: 12px 16px; border-radius: 6px; margin-bottom: 20px;">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- Quiz Settings Form -->
            <div class="admin-card" style="margin-bottom: 25px;">
                <div class="card-header">
                    <h3>Quiz Settings</h3>
                </div>
                <form method="POST" action="quiz-editor.php<?= $quiz_id > 0 ? '?id=' . $quiz_id : '' ?>" style="padding: 20px;">
                    <div style="display: flex; gap: 20px; margin-bottom: 20px; flex-wrap: wrap;">
                        <div class="form-group" style="flex: 2; min-width: 250px;">
                            <label>Quiz Title <span style="color: var(--accent);">*</span></label>
                            <input type="text" name="title" value="<?= htmlspecialchars($quiz['title'] ?? '') ?>" required placeholder="e.g. JavaScript Fundamentals Test">
                        </div>
                        <div class="form-group" style="flex: 1; min-width: 150px;">
                            <label>Passing Score (%) <span style="color: var(--accent);">*</span></label>
                            <input type="number" name="passing_score" value="<?= (int)($quiz['passing_score'] ?? 70) ?>" min="1" max="100" required>
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom: 20px;">
                        <label>Description</label>
                        <textarea name="description" rows="2" placeholder="Brief explanation of the topics covered by this assessment..."><?= htmlspecialchars($quiz['description'] ?? '') ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <?= $quiz_id > 0 ? 'Save Quiz Settings' : 'Create Quiz & Continue' ?>
                    </button>
                </form>
            </div>

            <?php if ($quiz_id > 0): ?>
                <!-- Add New Question Card -->
                <div class="admin-card" style="margin-bottom: 25px;">
                    <div class="card-header">
                        <h3>Add New Question</h3>
                    </div>
                    <form method="POST" action="quiz-editor.php?id=<?= $quiz_id ?>" style="padding: 20px;">
                        <input type="hidden" name="action" value="add_question">
                        <div class="form-group" style="margin-bottom: 18px;">
                            <label>Question Text <span style="color: var(--accent);">*</span></label>
                            <textarea name="new_question" rows="2" placeholder="Type question prompt here..." required></textarea>
                        </div>

                        <label style="display: block; margin-bottom: 10px; font-weight: 600; font-size: 0.9rem;">
                            Multiple Choice Options (Select radio to set the correct answer):
                        </label>

                        <div class="options-list">
                            <div class="option-item">
                                <input type="radio" name="correct_option" value="0" checked title="Mark as correct answer">
                                <input type="text" name="options[0]" placeholder="Option 1 (Required)" required>
                            </div>
                            <div class="option-item">
                                <input type="radio" name="correct_option" value="1" title="Mark as correct answer">
                                <input type="text" name="options[1]" placeholder="Option 2 (Required)" required>
                            </div>
                            <div class="option-item">
                                <input type="radio" name="correct_option" value="2" title="Mark as correct answer">
                                <input type="text" name="options[2]" placeholder="Option 3 (Optional)">
                            </div>
                            <div class="option-item">
                                <input type="radio" name="correct_option" value="3" title="Mark as correct answer">
                                <input type="text" name="options[3]" placeholder="Option 4 (Optional)">
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary" style="margin-top: 15px;">
                            + Save Question to Bank
                        </button>
                    </form>
                </div>

                <!-- Current Questions List -->
                <div class="admin-card">
                    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                        <h3>Questions in this Quiz (<?= count($questions) ?>)</h3>
                        <?php if (count($questions) > 0): ?>
                            <span class="badge badge-success"><?= count($questions) ?> Active Question<?= count($questions) == 1 ? '' : 's' ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="padding: 20px;">
                        <?php if (empty($questions)): ?>
                            <p style="color: var(--text-secondary); text-align: center; padding: 25px 0;">No questions added to this quiz yet. Use the form above to add questions.</p>
                        <?php else: ?>
                            <?php foreach ($questions as $idx => $q): ?>
                                <div class="question-block">
                                    <div class="question-header">
                                        <h4>Question <?= $idx + 1 ?>: <?= htmlspecialchars($q['question_text']) ?></h4>
                                        <form method="POST" action="quiz-editor.php?id=<?= $quiz_id ?>" style="display: inline; margin: 0;" onsubmit="return confirm('Are you sure you want to delete Question <?= $idx + 1 ?>?');">
                                            <input type="hidden" name="action" value="delete_question">
                                            <input type="hidden" name="del_question_id" value="<?= $q['id'] ?>">
                                            <button type="submit" class="btn-danger-outline">Delete Question</button>
                                        </form>
                                    </div>

                                    <div class="options-display-list" style="margin-top: 12px;">
                                        <?php foreach ($q['options'] as $opt): ?>
                                            <div class="option-display-item <?= $opt['is_correct'] ? 'is-correct' : '' ?>">
                                                <span><?= htmlspecialchars($opt['option_text']) ?></span>
                                                <?php if ($opt['is_correct']): ?>
                                                    <span class="correct-option-badge">✓ Correct Option</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
    <script>lucide.createIcons();</script>
</body>
</html>
