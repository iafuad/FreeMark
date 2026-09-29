<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';
require_role('client');

$user_id = current_user_id();
$client_id = get_profile_id($conn, $user_id, 'client');

// Redirect to edit page if project id is provided
if (!empty($_GET['id'])) {
    header('Location: edit-project.php?id=' . intval($_GET['id']));
    exit;
}

$error = '';
$success = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category_id = intval($_POST['category_id'] ?? 0);
    $duration = $_POST['duration'] ?? '1_4w';
    $budget_type = $_POST['budget_type'] ?? 'fixed';
    $budget = floatval($_POST['budget'] ?? 0);

    if (empty($title) || $budget <= 0) {
        $error = 'Title and budget are required.';
    } else {
        if ($category_id <= 0) {
            $stmt = $conn->prepare(
                "INSERT INTO projects (client_id, title, description, skill_category_id, duration, budget_type, budget_max)
                 VALUES (?, ?, ?, NULL, ?, ?, ?)"
            );
            $stmt->bind_param("isssd", $client_id, $title, $description, $duration, $budget_type, $budget);
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO projects (client_id, title, description, skill_category_id, duration, budget_type, budget_max)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("ississd", $client_id, $title, $description, $category_id, $duration, $budget_type, $budget);
        }
        if ($stmt->execute()) {
            $success = 'Project posted successfully! <a href="dashboard.php" style="color:var(--accent); text-decoration:underline; font-weight:600; margin-left:8px;">View on Dashboard &rarr;</a>';
            $title = '';
            $description = '';
            $category_id = 0;
            $budget = 0;
        } else {
            $error = 'Database error posting project: ' . $conn->error;
        }
    }
}

// Fetch skill categories for dropdown
$categories = $conn->query("SELECT * FROM skill_categories ORDER BY name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Post a Project - FreeMark</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/client/layout.css">
    <link rel="stylesheet" href="../css/client/components.css">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
    <aside class="client-sidebar">
        <!-- Sidebar identical -->
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
                <li><a href="post-project.php" class="active"><i data-lucide="plus-circle"></i> Post Project</a></li>
                <li><a href="freelancers.php"><i data-lucide="users"></i> Freelancers</a></li>
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
                <h2>Post a Project</h2>
                <p>Provide details to attract the best freelancers</p>
            </div>
        </header>

        <div class="client-content">
            <div class="card client-form-card">
                <div class="card-header">
                    <h3>Project Details</h3>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div style="color: red; margin-bottom: 15px;"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div style="color: green; margin-bottom: 15px;"><?= htmlspecialchars($success) ?></div>
                    <?php endif; ?>
                    <form method="POST" action="">
                        <div class="form-group">
                            <label>Project Title</label>
                            <input type="text" name="title" required placeholder="e.g. Need a full-stack developer for an e-commerce site" value="<?= htmlspecialchars($title ?? '') ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>Project Description</label>
                            <textarea name="description" placeholder="Describe the scope of work, deliverables, and any specific requirements..."><?= htmlspecialchars($description ?? '') ?></textarea>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Required Skill Category</label>
                                <select name="category_id">
                                    <option value="0">Select a category</option>
                                    <?php while ($cat = $categories->fetch_assoc()): ?>
                                        <option value="<?= $cat['id'] ?>" <?= (isset($category_id) && $category_id == $cat['id']) ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Estimated Duration</label>
                                <select name="duration">
                                    <option value="less_1w" <?= (isset($duration) && $duration === 'less_1w') ? 'selected' : '' ?>>Less than 1 week</option>
                                    <option value="1_4w" <?= (!isset($duration) || $duration === '1_4w') ? 'selected' : '' ?>>1-4 weeks</option>
                                    <option value="1_3m" <?= (isset($duration) && $duration === '1_3m') ? 'selected' : '' ?>>1-3 months</option>
                                    <option value="3m_plus" <?= (isset($duration) && $duration === '3m_plus') ? 'selected' : '' ?>>More than 3 months</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Budget Type</label>
                                <select name="budget_type">
                                    <option value="fixed" <?= (!isset($budget_type) || $budget_type === 'fixed') ? 'selected' : '' ?>>Fixed Price</option>
                                    <option value="hourly" <?= (isset($budget_type) && $budget_type === 'hourly') ? 'selected' : '' ?>>Hourly Rate</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Estimated Budget ($)</label>
                                <input type="number" name="budget" required placeholder="e.g. 5000" step="0.01" value="<?= (isset($budget) && $budget > 0) ? htmlspecialchars($budget) : '' ?>">
                            </div>
                        </div>
                        
                        <div class="form-actions-divider">
                            <button type="submit" class="btn btn-primary btn-block">Post Project</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>
    <script>lucide.createIcons();</script>
</body>
</html>
