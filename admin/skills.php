<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';
require_role('admin');

$error = '';
$success = '';

// Handle add category
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'add') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            $stmt = $conn->prepare("INSERT INTO skill_categories (name) VALUES (?)");
            $stmt->bind_param("s", $name);
            if ($stmt->execute()) {
                $success = "Category added successfully.";
            } else {
                $error = "Failed to add category. It may already exist.";
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $skill_id = (int)($_POST['skill_id'] ?? 0);
        if ($skill_id > 0) {
            $stmt = $conn->prepare("DELETE FROM skill_categories WHERE id = ?");
            $stmt->bind_param("i", $skill_id);
            $stmt->execute();
            $success = "Category removed.";
        }
    }
}

// Fetch all categories
$categories_res = $conn->query("SELECT * FROM skill_categories ORDER BY name ASC");
$categories = $categories_res ? $categories_res->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Skill Categories - FreeMark Admin</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/admin/layout.css">
    <link rel="stylesheet" href="../css/admin/components.css">
    <link rel="stylesheet" href="../css/admin/skills.css">
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
                <li><a href="skills.php" class="active"><i data-lucide="award"></i> Skill Categories</a></li>
                <li><a href="quizzes.php"><i data-lucide="help-circle"></i> Quiz Banks</a></li>
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
                <h2>Skill Categories</h2>
                <p>Manage platform skills and specializations</p>
            </div>
        </header>

        <div class="admin-content">
            <?php if ($error): ?>
                <div style="color: #ef4444; background: rgba(239, 68, 68, 0.1); padding: 10px 15px; border-radius: 6px; margin-bottom: 20px;">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div style="color: #22c55e; background: rgba(34, 197, 94, 0.1); padding: 10px 15px; border-radius: 6px; margin-bottom: 20px;">
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <div class="admin-card">
                <div class="card-header">
                    <h3>Add New Category</h3>
                </div>
                <form class="form-inline" method="POST" action="skills.php">
                    <input type="hidden" name="action" value="add">
                    <input type="text" name="name" placeholder="Enter new skill category name..." required>
                    <button type="submit" class="btn btn-primary">+ Add category</button>
                </form>
            </div>

            <div class="admin-card">
                <div class="card-header">
                    <h3>Current Categories (<?= count($categories) ?>)</h3>
                </div>
                <div class="skills-container">
                    <?php if (empty($categories)): ?>
                        <p style="padding: 10px; color: var(--text-secondary);">No categories added yet.</p>
                    <?php else: ?>
                        <?php foreach ($categories as $cat): ?>
                            <div class="skill-badge">
                                <?= htmlspecialchars($cat['name']) ?>
                                <form method="POST" action="skills.php" style="display:inline;">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="skill_id" value="<?= $cat['id'] ?>">
                                    <button type="submit" style="background:none; border:none; color:inherit; cursor:pointer; padding:0; margin-left:6px; display:inline-flex; align-items:center;">
                                        <i data-lucide="x" style="width: 14px;"></i>
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
    <script>lucide.createIcons();</script>
</body>
</html>
