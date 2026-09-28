<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

require_role('freelancer');
$user_id = current_user_id();
$profile_id = get_profile_id($conn, $user_id, 'freelancer');

$quiz_id = (int)($_GET['id'] ?? 0);
if ($quiz_id <= 0) {
    header("Location: tests.php");
    exit;
}

// Fetch quiz details
$q_stmt = $conn->prepare("SELECT * FROM quizzes WHERE id = ?");
$q_stmt->bind_param("i", $quiz_id);
$q_stmt->execute();
$quiz = $q_stmt->get_result()->fetch_assoc();

if (!$quiz) {
    header("Location: tests.php");
    exit;
}

// Batch query to load questions and options efficiently (2 queries total, no N+1)
$qq_stmt = $conn->prepare("SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY id ASC");
$qq_stmt->bind_param("i", $quiz_id);
$qq_stmt->execute();
$questions = $qq_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

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

// Fetch freelancer's previous attempts on this quiz
$prev_stmt = $conn->prepare("SELECT 
        MAX(score) as best_score, 
        MAX(max_score) as max_score, 
        MAX(passed) as has_passed, 
        COUNT(id) as attempt_count,
        MAX(created_at) as latest_attempt_at
    FROM test_results 
    WHERE freelancer_id = ? AND quiz_id = ?");
$prev_stmt->bind_param("ii", $profile_id, $quiz_id);
$prev_stmt->execute();
$prev_attempt = $prev_stmt->get_result()->fetch_assoc();
$has_previous_attempts = ($prev_attempt && $prev_attempt['attempt_count'] > 0);

$result_data = null;

// Handle Grading on POST submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $score = 0;
    $max_score = count($questions);
    $answers_breakdown = [];

    foreach ($questions as $q) {
        $selected_opt = (int)($_POST['q_' . $q['id']] ?? 0);
        $is_question_correct = false;
        $correct_opt_id = 0;

        foreach ($q['options'] as $opt) {
            if ($opt['is_correct'] == 1) {
                $correct_opt_id = (int)$opt['id'];
                if ($selected_opt === $correct_opt_id) {
                    $is_question_correct = true;
                }
            }
        }

        if ($is_question_correct) {
            $score++;
        }

        $answers_breakdown[] = [
            'question_id' => $q['id'],
            'question_text' => $q['question_text'],
            'options' => $q['options'],
            'selected_opt_id' => $selected_opt,
            'correct_opt_id' => $correct_opt_id,
            'is_correct' => $is_question_correct
        ];
    }

    $pct = ($max_score > 0) ? round(($score / $max_score) * 100) : 0;
    $passed = $pct >= (int)$quiz['passing_score'];

    // Guard against division-by-zero on empty quizzes
    if ($max_score > 0) {
        $ins = $conn->prepare("INSERT INTO test_results (freelancer_id, quiz_id, score, max_score) VALUES (?, ?, ?, ?)");
        $ins->bind_param("iiii", $profile_id, $quiz_id, $score, $max_score);
        $ins->execute();
    }

    $result_data = [
        'score' => $score,
        'max_score' => $max_score,
        'percentage' => $pct,
        'passed' => $passed,
        'breakdown' => $answers_breakdown
    ];
}

$auto_start = isset($_GET['start']) && $_GET['start'] == '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($quiz['title']) ?> - FreeMark</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/freelancer/layout.css">
    <link rel="stylesheet" href="../css/freelancer/components.css">
    <link rel="stylesheet" href="../css/freelancer/quiz.css">
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
                <h2><?= htmlspecialchars($quiz['title']) ?></h2>
                <p>Passing requirement: <?= (int)$quiz['passing_score'] ?>% score</p>
            </div>
            <a href="tests.php" class="btn btn-outline" style="border-color: rgba(255, 255, 255, 0.2); text-decoration: none;">&larr; Exit to Tests</a>
        </header>

        <div class="freelancer-content">
            <div class="quiz-container">

                <?php if ($result_data): ?>
                    <!-- ==================================================== -->
                    <!-- OUTRO SCREEN: Comprehensive Results & Badge Showcase -->
                    <!-- ==================================================== -->
                    <div id="quizOutroScreen">
                        <div class="quiz-outro-card <?= $result_data['passed'] ? 'passed' : 'failed' ?>">
                            <div class="outro-icon-ring">
                                <?= $result_data['passed'] ? '🎉' : '📚' ?>
                            </div>

                            <h2 class="outro-title">
                                <?= $result_data['passed'] ? 'Assessment Passed! Verified Skill Badge Earned!' : 'Assessment Completed - Needs Retake' ?>
                            </h2>

                            <p class="outro-subtitle">
                                <?= $result_data['passed']
                                    ? 'Congratulations! You achieved a passing score on this benchmark assessment. Your verified skill badge is now unlocked and displayed across FreeMark.'
                                    : 'You scored ' . $result_data['percentage'] . '%, but this assessment requires a minimum of ' . (int)$quiz['passing_score'] . '% to earn the verified badge. Review your answers below and try again!' ?>
                            </p>

                            <!-- Results Metric Strip -->
                            <div class="outro-stats-strip">
                                <div class="outro-stat-cell">
                                    <div class="stat-num"><?= $result_data['score'] ?> / <?= $result_data['max_score'] ?></div>
                                    <div class="stat-sub">Score Achieved</div>
                                </div>
                                <div class="outro-stat-cell">
                                    <div class="stat-num" style="color: <?= $result_data['passed'] ? '#22c55e' : '#ef4444' ?>;">
                                        <?= $result_data['percentage'] ?>%
                                    </div>
                                    <div class="stat-sub">Percentage</div>
                                </div>
                                <div class="outro-stat-cell">
                                    <div class="stat-num"><?= (int)$quiz['passing_score'] ?>%</div>
                                    <div class="stat-sub">Passing Benchmark</div>
                                </div>
                                <div class="outro-stat-cell">
                                    <div class="stat-num" style="color: <?= $result_data['passed'] ? '#22c55e' : '#ef4444' ?>;">
                                        <?= $result_data['passed'] ? 'VERIFIED ✓' : 'FAILED' ?>
                                    </div>
                                    <div class="stat-sub">Result Status</div>
                                </div>
                            </div>

                            <!-- Verified Badge Card (if passed) -->
                            <?php if ($result_data['passed']): ?>
                                <div class="unlocked-badge-showcase">
                                    <div class="badge-showcase-medal">
                                        <i data-lucide="shield-check" style="width: 28px; height: 28px;"></i>
                                    </div>
                                    <div class="badge-showcase-info">
                                        <h4>
                                            <?= htmlspecialchars($quiz['title']) ?>
                                            <span class="status-badge success" style="font-size: 0.72rem; padding: 2px 8px;">Badge Active</span>
                                        </h4>
                                        <p>This verified credential highlights your domain competence to prospective clients in search rankings and job proposals.</p>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Action Buttons -->
                            <div class="outro-actions-row">
                                <a href="tests.php" class="btn btn-outline">
                                    <i data-lucide="check-square" class="icon-sm"></i> Return to Skill Tests
                                </a>
                                <a href="take-quiz.php?id=<?= $quiz_id ?>&start=1" class="btn <?= $result_data['passed'] ? 'btn-outline' : 'btn-primary' ?>">
                                    <i data-lucide="rotate-ccw" class="icon-sm"></i> Retake Assessment
                                </a>
                                <?php if ($result_data['passed']): ?>
                                    <a href="profile.php" class="btn btn-primary">
                                        <i data-lucide="user" class="icon-sm"></i> View on My Profile
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Question-by-Question Detailed Review -->
                        <div class="quiz-review-section">
                            <h3>
                                <i data-lucide="list" class="icon-base"></i>
                                Detailed Answer Breakdown (<?= $result_data['score'] ?> of <?= $result_data['max_score'] ?> correct)
                            </h3>

                            <?php foreach ($result_data['breakdown'] as $idx => $item): ?>
                                <div class="quiz-review-card <?= $item['is_correct'] ? 'correct' : 'incorrect' ?>">
                                    <div class="quiz-review-header">
                                        <h4>Question <?= ($idx + 1) ?>: <?= htmlspecialchars($item['question_text']) ?></h4>
                                        <span class="status-badge <?= $item['is_correct'] ? 'success' : 'danger' ?>">
                                            <?= $item['is_correct'] ? '✓ Correct' : '✗ Incorrect' ?>
                                        </span>
                                    </div>

                                    <div class="review-options-list">
                                        <?php 
                                            $letters = ['A', 'B', 'C', 'D', 'E', 'F'];
                                            foreach ($item['options'] as $opt_idx => $opt): 
                                                $is_user_choice = ($opt['id'] == $item['selected_opt_id']);
                                                $is_correct_choice = ($opt['is_correct'] == 1);
                                                $class = '';
                                                if ($is_correct_choice) {
                                                    $class = 'is-correct-answer';
                                                } elseif ($is_user_choice && !$item['is_correct']) {
                                                    $class = 'is-user-selected is-wrong';
                                                }
                                        ?>
                                            <div class="review-option <?= $class ?>">
                                                <div style="display: flex; align-items: center; gap: 10px;">
                                                    <span style="font-weight: 700; color: var(--text-secondary); width: 20px;">
                                                        <?= $letters[$opt_idx] ?? ($opt_idx + 1) ?>.
                                                    </span>
                                                    <span><?= htmlspecialchars($opt['option_text']) ?></span>
                                                </div>
                                                <div>
                                                    <?php if ($is_correct_choice): ?>
                                                        <span class="status-badge success" style="font-size: 0.75rem;">✓ Correct Answer</span>
                                                    <?php elseif ($is_user_choice): ?>
                                                        <span class="status-badge danger" style="font-size: 0.75rem;">✗ Your Answer</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                <?php else: ?>

                    <?php if (empty($questions)): ?>
                        <!-- Empty Questions Alert -->
                        <div class="quiz-intro-card" style="text-align: center; padding: 50px 30px;">
                            <i data-lucide="help-circle" style="width: 50px; height: 50px; color: var(--text-secondary); margin-bottom: 15px; opacity: 0.6;"></i>
                            <h3 style="font-size: 1.4rem; margin-bottom: 10px;">No Questions Configured</h3>
                            <p style="color: var(--text-secondary); max-width: 460px; margin: 0 auto 25px auto;">
                                This assessment test currently has no questions available. Please check back later or explore other skill benchmarks.
                            </p>
                            <a href="tests.php" class="btn btn-primary">&larr; Return to Skill Tests</a>
                        </div>

                    <?php else: ?>

                        <!-- ==================================================== -->
                        <!-- INTRO SCREEN: Assessment Briefing & Rules            -->
                        <!-- ==================================================== -->
                        <div id="quizIntroScreen" <?= $auto_start ? 'style="display: none;"' : '' ?>>
                            <div class="quiz-intro-card">
                                <div class="intro-badge-top">
                                    <i data-lucide="shield-check" class="icon-sm"></i> Official FreeMark Assessment
                                </div>

                                <div class="intro-header">
                                    <h2><?= htmlspecialchars($quiz['title']) ?></h2>
                                    <p><?= htmlspecialchars($quiz['description'] ?: 'Benchmark assessment test designed to measure and verify professional proficiency.') ?></p>
                                </div>

                                <!-- 4-Metric Grid -->
                                <div class="intro-metric-grid">
                                    <div class="intro-metric-box">
                                        <div class="intro-metric-icon icon-yellow">
                                            <i data-lucide="help-circle" class="icon-base"></i>
                                        </div>
                                        <div class="intro-metric-val"><?= count($questions) ?> Questions</div>
                                        <div class="intro-metric-lbl">Total Questions</div>
                                    </div>

                                    <div class="intro-metric-box">
                                        <div class="intro-metric-icon icon-green">
                                            <i data-lucide="target" class="icon-base"></i>
                                        </div>
                                        <div class="intro-metric-val"><?= (int)$quiz['passing_score'] ?>% Minimum</div>
                                        <div class="intro-metric-lbl">Passing Benchmark</div>
                                    </div>

                                    <div class="intro-metric-box">
                                        <div class="intro-metric-icon icon-blue">
                                            <i data-lucide="check-circle-2" class="icon-base"></i>
                                        </div>
                                        <div class="intro-metric-val">Multiple Choice</div>
                                        <div class="intro-metric-lbl">Single Answer</div>
                                    </div>

                                    <div class="intro-metric-box">
                                        <div class="intro-metric-icon icon-purple">
                                            <i data-lucide="award" class="icon-base"></i>
                                        </div>
                                        <div class="intro-metric-val">Verified Badge</div>
                                        <div class="intro-metric-lbl">Profile Credential</div>
                                    </div>
                                </div>

                                <!-- Historical Performance Banner -->
                                <?php if ($has_previous_attempts): ?>
                                    <?php 
                                        $prev_pct = ($prev_attempt['max_score'] > 0) ? round(($prev_attempt['best_score'] / $prev_attempt['max_score']) * 100) : 0;
                                        $prev_passed = ($prev_attempt['has_passed'] == 1) || ($prev_pct >= (int)$quiz['passing_score']);
                                    ?>
                                    <div class="intro-history-banner <?= $prev_passed ? 'banner-passed' : 'banner-retake' ?>">
                                        <div class="history-banner-icon">
                                            <i data-lucide="<?= $prev_passed ? 'check-circle' : 'rotate-ccw' ?>"></i>
                                        </div>
                                        <div class="history-banner-content">
                                            <h4>
                                                <?= $prev_passed ? 'Skill Already Verified ✓' : 'Previous Attempt History' ?>
                                                (Best: <?= $prev_attempt['best_score'] ?>/<?= $prev_attempt['max_score'] ?> &bull; <?= $prev_pct ?>%)
                                            </h4>
                                            <p>
                                                <?= $prev_passed 
                                                    ? 'You have already unlocked this verified skill badge across ' . (int)$prev_attempt['attempt_count'] . ' attempt(s). Retaking this test will sharpen your skills without losing your badge.' 
                                                    : 'You have taken this assessment ' . (int)$prev_attempt['attempt_count'] . ' time(s). Reach ' . (int)$quiz['passing_score'] . '% or higher on this attempt to unlock your badge!' ?>
                                            </p>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="intro-history-banner banner-new">
                                        <div class="history-banner-icon">
                                            <i data-lucide="sparkles"></i>
                                        </div>
                                        <div class="history-banner-content">
                                            <h4>First Assessment Attempt</h4>
                                            <p>Passing this benchmark will immediately attach a verified skill badge to your public profile and boost your visibility in client candidate searches.</p>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Guidelines / Instructions -->
                                <div class="intro-guidelines">
                                    <h4><i data-lucide="info" class="icon-base"></i> Assessment Instructions & Rules</h4>
                                    <ul>
                                        <li>
                                            <i data-lucide="navigation"></i>
                                            <span><strong>Interactive Stepper:</strong> Questions are presented one at a time for focus. Use the navigation buttons or top number pills to move between questions.</span>
                                        </li>
                                        <li>
                                            <i data-lucide="edit-3"></i>
                                            <span><strong>Flexible Review:</strong> You can change your chosen answers at any time before clicking the final submission button.</span>
                                        </li>
                                        <li>
                                            <i data-lucide="check-check"></i>
                                            <span><strong>Answer Tracking:</strong> The top bar highlights answered questions in real-time, warning you if any question remains untouched.</span>
                                        </li>
                                        <li>
                                            <i data-lucide="zap"></i>
                                            <span><strong>Instant Results:</strong> Grading is automatic upon submission, with immediate score calculation and an answer breakdown.</span>
                                        </li>
                                    </ul>
                                </div>

                                <!-- Actions -->
                                <div class="intro-actions">
                                    <button type="button" id="startAssessmentBtn" class="btn-start-quiz">
                                        <i data-lucide="play" class="icon-base"></i> Start Assessment Now
                                    </button>
                                    <a href="tests.php" class="btn btn-outline" style="padding: 14px 24px;">
                                        &larr; Back to Skill Tests
                                    </a>
                                </div>
                            </div>
                        </div>


                        <!-- ==================================================== -->
                        <!-- ACTIVE STEPPER SCREEN: Single Question View & Pills -->
                        <!-- ==================================================== -->
                        <div id="quizActiveScreen" <?= $auto_start ? '' : 'style="display: none;"' ?>>
                            <form method="POST" action="take-quiz.php?id=<?= $quiz_id ?>" id="quizForm">
                                <div class="quiz-stepper-box">
                                    <!-- Stepper Top Bar -->
                                    <div class="stepper-header">
                                        <div class="stepper-progress-info" id="currentStepIndicator">
                                            Question 1 of <?= count($questions) ?>
                                        </div>
                                        <div class="stepper-answered-badge">
                                            <i data-lucide="check-circle-2" class="icon-sm" style="color: #22c55e;"></i>
                                            <span><strong id="answeredCount">0</strong> of <?= count($questions) ?> Answered</span>
                                        </div>
                                    </div>

                                    <!-- Animated Progress Bar -->
                                    <div class="stepper-progress-track">
                                        <div class="stepper-progress-bar" id="stepperProgressBar" style="width: <?= round(100 / count($questions)) ?>%;"></div>
                                    </div>

                                    <!-- Navigation Number Pills -->
                                    <div class="quiz-nav-pills" id="quizNavPills">
                                        <?php foreach ($questions as $idx => $q): ?>
                                            <button type="button" class="nav-pill <?= $idx === 0 ? 'active' : '' ?>" data-step-target="<?= $idx ?>" id="navPill_<?= $idx ?>">
                                                <?= ($idx + 1) ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>

                                    <!-- Question Steps -->
                                    <div class="steps-wrapper">
                                        <?php 
                                            $letters = ['A', 'B', 'C', 'D', 'E', 'F'];
                                            foreach ($questions as $idx => $q): 
                                        ?>
                                            <div class="question-step <?= $idx === 0 ? 'step-visible' : '' ?>" data-step="<?= $idx ?>" id="step_<?= $idx ?>">
                                                <div class="step-question-header">
                                                    <div class="step-question-number">
                                                        <i data-lucide="help-circle" class="icon-sm"></i> Question <?= ($idx + 1) ?> of <?= count($questions) ?>
                                                    </div>
                                                    <h3 class="step-question-text"><?= htmlspecialchars($q['question_text']) ?></h3>
                                                </div>

                                                <div class="options-stack">
                                                    <?php foreach ($q['options'] as $opt_idx => $opt): ?>
                                                        <label class="quiz-option-label" data-question-step="<?= $idx ?>">
                                                            <div class="option-radio-bullet"></div>
                                                            <div class="option-letter-tag"><?= $letters[$opt_idx] ?? ($opt_idx + 1) ?></div>
                                                            <div class="option-main-text"><?= htmlspecialchars($opt['option_text']) ?></div>
                                                            <input type="radio" name="q_<?= $q['id'] ?>" value="<?= $opt['id'] ?>">
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <!-- Stepper Bottom Controls -->
                                    <div class="stepper-footer-bar">
                                        <button type="button" class="btn btn-outline" id="prevStepBtn" disabled>
                                            <i data-lucide="arrow-left" class="icon-sm"></i> Previous
                                        </button>

                                        <span class="stepper-counter-label" id="stepperStatusText">
                                            Question 1 of <?= count($questions) ?>
                                        </span>

                                        <div class="stepper-nav-btns">
                                            <button type="button" class="btn btn-primary" id="nextStepBtn">
                                                Next Question <i data-lucide="arrow-right" class="icon-sm"></i>
                                            </button>
                                            <button type="button" class="btn btn-primary" id="submitQuizBtn" style="display: none; background: #22c55e; border-color: #22c55e;">
                                                Submit Test for Grading <i data-lucide="check" class="icon-sm"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <!-- Unanswered Confirmation Modal -->
                        <div id="unansweredModal" class="quiz-modal-backdrop" style="display: none;">
                            <div class="quiz-modal-box">
                                <div style="font-size: 2.5rem; margin-bottom: 12px; color: #facc15;">
                                    <i data-lucide="alert-triangle" style="width: 48px; height: 48px; margin: 0 auto;"></i>
                                </div>
                                <h3 style="font-size: 1.3rem; margin-bottom: 8px;">Unanswered Questions Detected</h3>
                                <p id="unansweredModalText" style="color: var(--text-secondary); font-size: 0.95rem; margin-bottom: 22px; line-height: 1.5;">
                                    You still have unanswered questions. Are you sure you want to submit now?
                                </p>
                                <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
                                    <button type="button" class="btn btn-primary" id="modalJumpFirstUnansweredBtn">
                                        Review Question <span id="firstUnansweredNum">1</span>
                                    </button>
                                    <button type="button" class="btn btn-outline" id="modalSubmitAnywayBtn" style="border-color: rgba(239, 68, 68, 0.4); color: #fca5a5;">
                                        Submit Anyway
                                    </button>
                                    <button type="button" class="btn btn-outline" id="modalCloseBtn">
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        </div>

                    <?php endif; ?>

                <?php endif; ?>

            </div>
        </div>
    </main>

    <script>
        lucide.createIcons();

        document.addEventListener('DOMContentLoaded', function() {
            const introScreen = document.getElementById('quizIntroScreen');
            const activeScreen = document.getElementById('quizActiveScreen');
            const startBtn = document.getElementById('startAssessmentBtn');
            const quizForm = document.getElementById('quizForm');

            // Elements in Stepper
            const totalSteps = <?= !empty($questions) ? count($questions) : 0 ?>;
            let currentStep = 0;

            const prevStepBtn = document.getElementById('prevStepBtn');
            const nextStepBtn = document.getElementById('nextStepBtn');
            const submitQuizBtn = document.getElementById('submitQuizBtn');
            const stepIndicator = document.getElementById('currentStepIndicator');
            const statusText = document.getElementById('stepperStatusText');
            const progressBar = document.getElementById('stepperProgressBar');
            const answeredCountEl = document.getElementById('answeredCount');
            const pills = document.querySelectorAll('.nav-pill');
            const stepCards = document.querySelectorAll('.question-step');

            // Modal elements
            const unansweredModal = document.getElementById('unansweredModal');
            const unansweredModalText = document.getElementById('unansweredModalText');
            const modalJumpBtn = document.getElementById('modalJumpFirstUnansweredBtn');
            const modalSubmitAnywayBtn = document.getElementById('modalSubmitAnywayBtn');
            const modalCloseBtn = document.getElementById('modalCloseBtn');
            const firstUnansweredNum = document.getElementById('firstUnansweredNum');

            // Start Assessment Transition
            if (startBtn) {
                startBtn.addEventListener('click', function() {
                    if (introScreen) introScreen.style.display = 'none';
                    if (activeScreen) {
                        activeScreen.style.display = 'block';
                        goToStep(0);
                        lucide.createIcons();
                    }
                });
            }

            if (totalSteps > 0 && activeScreen) {
                // Navigation Pill Clicks
                pills.forEach(pill => {
                    pill.addEventListener('click', function() {
                        const target = parseInt(this.getAttribute('data-step-target'), 10);
                        if (!isNaN(target)) {
                            goToStep(target);
                        }
                    });
                });

                // Next & Prev Clicks
                if (nextStepBtn) {
                    nextStepBtn.addEventListener('click', function() {
                        if (currentStep < totalSteps - 1) {
                            goToStep(currentStep + 1);
                        }
                    });
                }

                if (prevStepBtn) {
                    prevStepBtn.addEventListener('click', function() {
                        if (currentStep > 0) {
                            goToStep(currentStep - 1);
                        }
                    });
                }

                // Handle Radio Change
                const radioInputs = activeScreen.querySelectorAll('input[type="radio"]');
                radioInputs.forEach(radio => {
                    radio.addEventListener('change', function() {
                        const parentLabel = this.closest('.quiz-option-label');
                        const stepIndex = parseInt(parentLabel.getAttribute('data-question-step'), 10);

                        // Clear active label style within the same question step
                        const currentStepEl = document.getElementById('step_' + stepIndex);
                        if (currentStepEl) {
                            currentStepEl.querySelectorAll('.quiz-option-label').forEach(lbl => lbl.classList.remove('is-checked'));
                        }
                        if (this.checked) {
                            parentLabel.classList.add('is-checked');
                        }

                        // Mark corresponding pill as answered
                        const targetPill = document.getElementById('navPill_' + stepIndex);
                        if (targetPill) {
                            targetPill.classList.add('answered');
                        }

                        updateAnsweredSummary();
                    });
                });

                // Submit Button Click
                if (submitQuizBtn) {
                    submitQuizBtn.addEventListener('click', function() {
                        const unanswered = getUnansweredSteps();
                        if (unanswered.length > 0) {
                            // Display confirmation modal
                            firstUnansweredNum.textContent = (unanswered[0] + 1);
                            modalJumpBtn.setAttribute('data-target-step', unanswered[0]);
                            unansweredModalText.innerHTML = `You have <strong>${unanswered.length}</strong> unanswered question(s): Question ${unanswered.map(i => i + 1).join(', ')}.<br>Would you like to review them or submit as is?`;
                            unansweredModal.style.display = 'flex';
                            lucide.createIcons();
                        } else {
                            quizForm.submit();
                        }
                    });
                }

                // Modal Button handlers
                if (modalJumpBtn) {
                    modalJumpBtn.addEventListener('click', function() {
                        const target = parseInt(this.getAttribute('data-target-step'), 10) || 0;
                        unansweredModal.style.display = 'none';
                        goToStep(target);
                    });
                }

                if (modalSubmitAnywayBtn) {
                    modalSubmitAnywayBtn.addEventListener('click', function() {
                        unansweredModal.style.display = 'none';
                        quizForm.submit();
                    });
                }

                if (modalCloseBtn) {
                    modalCloseBtn.addEventListener('click', function() {
                        unansweredModal.style.display = 'none';
                    });
                }

                function goToStep(index) {
                    if (index < 0 || index >= totalSteps) return;
                    currentStep = index;

                    // Update Step Cards
                    stepCards.forEach((card, idx) => {
                        if (idx === currentStep) {
                            card.classList.add('step-visible');
                        } else {
                            card.classList.remove('step-visible');
                        }
                    });

                    // Update Pills
                    pills.forEach((pill, idx) => {
                        if (idx === currentStep) {
                            pill.classList.add('active');
                        } else {
                            pill.classList.remove('active');
                        }
                    });

                    // Update Progress Bar & Text
                    const pct = Math.round(((currentStep + 1) / totalSteps) * 100);
                    if (progressBar) progressBar.style.width = pct + '%';

                    const label = `Question ${currentStep + 1} of ${totalSteps}`;
                    if (stepIndicator) stepIndicator.textContent = label;
                    if (statusText) statusText.textContent = label;

                    // Update Buttons
                    if (prevStepBtn) prevStepBtn.disabled = (currentStep === 0);

                    if (currentStep === totalSteps - 1) {
                        if (nextStepBtn) nextStepBtn.style.display = 'none';
                        if (submitQuizBtn) submitQuizBtn.style.display = 'inline-flex';
                    } else {
                        if (nextStepBtn) nextStepBtn.style.display = 'inline-flex';
                        if (submitQuizBtn) submitQuizBtn.style.display = 'none';
                    }

                    window.scrollTo({ top: 120, behavior: 'smooth' });
                    lucide.createIcons();
                }

                function getUnansweredSteps() {
                    const unanswered = [];
                    stepCards.forEach((step, idx) => {
                        const checked = step.querySelector('input[type="radio"]:checked');
                        if (!checked) {
                            unanswered.push(idx);
                        }
                    });
                    return unanswered;
                }

                function updateAnsweredSummary() {
                    let answered = 0;
                    stepCards.forEach(step => {
                        if (step.querySelector('input[type="radio"]:checked')) {
                            answered++;
                        }
                    });
                    if (answeredCountEl) {
                        answeredCountEl.textContent = answered;
                    }
                }

                // Initial setup
                updateAnsweredSummary();
                goToStep(0);
            }
        });
    </script>
</body>
</html>
