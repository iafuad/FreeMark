<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';
require_role('client');

$user_id = current_user_id();
$client_id = get_profile_id($conn, $user_id, 'client');

$project_id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
$error = '';
$success = '';

if ($project_id <= 0) {
    header('Location: projects.php');
    exit;
}

// Fetch existing project belonging to this client
$stmt = $conn->prepare("
    SELECT p.*, 
           (SELECT COUNT(*) FROM proposals WHERE project_id = p.id) AS proposal_count,
           (SELECT COUNT(*) FROM contracts WHERE project_id = p.id AND status = 'active') AS active_contracts_count
    FROM projects p
    WHERE p.id = ? AND p.client_id = ?
");
$stmt->bind_param("ii", $project_id, $client_id);
$stmt->execute();
$project = $stmt->get_result()->fetch_assoc();

if (!$project) {
    // Project not found or unauthorized
    $page_not_found = true;
} else {
    $page_not_found = false;
    $has_active_contracts = ($project['active_contracts_count'] > 0 || in_array($project['status'], ['in_progress', 'completed']));

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $category_id = intval($_POST['category_id'] ?? 0);
        $duration = $_POST['duration'] ?? $project['duration'];
        $new_status = $_POST['status'] ?? $project['status'];

        // If active contracts exist, freeze budget & type to protect agreement
        if ($has_active_contracts) {
            $budget_type = $project['budget_type'];
            $budget = floatval($project['budget_max']);
            // Cannot revert status to open if in progress or completed
            if (!in_array($new_status, ['in_progress', 'completed', 'closed'])) {
                $new_status = $project['status'];
            }
        } else {
            $budget_type = $_POST['budget_type'] ?? 'fixed';
            $budget = floatval($_POST['budget'] ?? 0);
            if (!in_array($new_status, ['open', 'closed'])) {
                $new_status = $project['status'];
            }
        }

        // Validate duration
        if (!in_array($duration, ['less_1w', '1_4w', '1_3m', '3m_plus'])) {
            $duration = '1_4w';
        }
        if (!in_array($budget_type, ['fixed', 'hourly'])) {
            $budget_type = 'fixed';
        }

        if (empty($title)) {
            $error = 'Project title is required.';
        } elseif ($budget <= 0) {
            $error = 'Please specify a valid budget greater than 0.';
        } else {
            if ($category_id <= 0) {
                $upd_stmt = $conn->prepare("
                    UPDATE projects 
                    SET title = ?, description = ?, skill_category_id = NULL, duration = ?, budget_type = ?, budget_max = ?, status = ?
                    WHERE id = ? AND client_id = ?
                ");
                $upd_stmt->bind_param("ssssdsii", $title, $description, $duration, $budget_type, $budget, $new_status, $project_id, $client_id);
            } else {
                $upd_stmt = $conn->prepare("
                    UPDATE projects 
                    SET title = ?, description = ?, skill_category_id = ?, duration = ?, budget_type = ?, budget_max = ?, status = ?
                    WHERE id = ? AND client_id = ?
                ");
                $upd_stmt->bind_param("ssissdsii", $title, $description, $category_id, $duration, $budget_type, $budget, $new_status, $project_id, $client_id);
            }

            if ($upd_stmt->execute()) {
                $success = 'Project listing updated successfully!';
                // Refresh project data
                $stmt->execute();
                $project = $stmt->get_result()->fetch_assoc();
            } else {
                $error = 'Database error updating project: ' . $conn->error;
            }
        }
    }
}

// Fetch categories for dropdown
$categories = $conn->query("SELECT * FROM skill_categories ORDER BY name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_not_found ? 'Project Not Found' : 'Edit Project: ' . htmlspecialchars($project['title']) ?> - FreeMark</title>
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
                <li><a href="projects.php" class="active"><i data-lucide="briefcase"></i> My Projects</a></li>
                <li><a href="post-project.php"><i data-lucide="plus-circle"></i> Post Project</a></li>
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
                <h2>Edit Project Listing</h2>
                <p>Modify project specifications, scope, budget, or listing status</p>
            </div>
            <div class="header-actions">
                <a href="projects.php" class="btn btn-outline btn-compact">
                    <i data-lucide="arrow-left"></i> Back to My Projects
                </a>
            </div>
        </header>

        <div class="client-content">
            <?php if ($page_not_found): ?>
                <div class="card">
                    <div class="card-body" style="text-align: center; padding: 50px 20px;">
                        <i data-lucide="alert-triangle" style="width: 48px; height: 48px; color: #ef4444; margin-bottom: 16px;"></i>
                        <h3 style="margin-bottom: 8px; color: var(--text-primary);">Project Not Found</h3>
                        <p style="color: var(--text-secondary); margin-bottom: 24px;">The project you are trying to edit does not exist or you do not have permission to view it.</p>
                        <a href="projects.php" class="btn btn-primary btn-compact"><i data-lucide="briefcase"></i> Go to My Projects</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="card client-form-card">
                    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <h3>Project Details</h3>
                            <?= get_status_badge($project['status']) ?>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <a href="../guest/job-details.php?id=<?= $project['id'] ?>" target="_blank" class="btn btn-outline btn-compact" title="Open public job details in new tab">
                                <i data-lucide="external-link"></i> View Job Page
                            </a>
                            <?php if ($project['proposal_count'] > 0): ?>
                                <a href="proposals.php?project_id=<?= $project['id'] ?>" class="btn btn-outline btn-compact">
                                    <i data-lucide="file-text"></i> Proposals (<?= $project['proposal_count'] ?>)
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger" style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444; padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                                <i data-lucide="alert-circle" style="width: 18px; height: 18px; flex-shrink: 0;"></i>
                                <span><?= htmlspecialchars($error) ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success" style="background: rgba(34, 197, 94, 0.15); border: 1px solid rgba(34, 197, 94, 0.3); color: #22c55e; padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <i data-lucide="check-circle" style="width: 18px; height: 18px; flex-shrink: 0;"></i>
                                    <span><?= htmlspecialchars($success) ?></span>
                                </div>
                                <div style="display: flex; gap: 10px;">
                                    <a href="../guest/job-details.php?id=<?= $project['id'] ?>" class="btn btn-compact btn-outline" style="border-color: #22c55e; color: #22c55e;">
                                        View Job Page &rarr;
                                    </a>
                                    <a href="projects.php" class="btn btn-compact btn-primary">
                                        Back to My Projects
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($has_active_contracts): ?>
                            <div style="background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3); color: #60a5fa; padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 0.88rem; display: flex; align-items: center; gap: 10px;">
                                <i data-lucide="info" style="width: 18px; height: 18px; flex-shrink: 0;"></i>
                                <span>This project has an active contract or is in progress. Core financial terms (budget and budget type) are locked to maintain agreement integrity with the hired freelancer. You can still adjust the title, description, and timeline.</span>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="">
                            <input type="hidden" name="id" value="<?= $project['id'] ?>">
                            
                            <div class="form-group">
                                <label for="title">Project Title</label>
                                <input type="text" id="title" name="title" required placeholder="e.g. Need a full-stack developer for an e-commerce site" value="<?= htmlspecialchars($project['title']) ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="description">Project Description</label>
                                <textarea id="description" name="description" rows="7" placeholder="Describe the scope of work, deliverables, and any specific requirements..."><?= htmlspecialchars($project['description']) ?></textarea>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="category_id">Required Skill Category</label>
                                    <select id="category_id" name="category_id">
                                        <option value="0">General / None</option>
                                        <?php 
                                        $categories->data_seek(0);
                                        while ($cat = $categories->fetch_assoc()): 
                                        ?>
                                            <option value="<?= $cat['id'] ?>" <?= ((int)$project['skill_category_id'] === (int)$cat['id']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($cat['name']) ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="duration">Estimated Duration</label>
                                    <select id="duration" name="duration">
                                        <option value="less_1w" <?= ($project['duration'] === 'less_1w') ? 'selected' : '' ?>>Less than 1 week</option>
                                        <option value="1_4w" <?= ($project['duration'] === '1_4w') ? 'selected' : '' ?>>1-4 weeks</option>
                                        <option value="1_3m" <?= ($project['duration'] === '1_3m') ? 'selected' : '' ?>>1-3 months</option>
                                        <option value="3m_plus" <?= ($project['duration'] === '3m_plus') ? 'selected' : '' ?>>More than 3 months</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="budget_type">Budget Type</label>
                                    <select id="budget_type" name="budget_type" <?= $has_active_contracts ? 'disabled style="opacity: 0.6; cursor: not-allowed;"' : '' ?>>
                                        <option value="fixed" <?= ($project['budget_type'] === 'fixed') ? 'selected' : '' ?>>Fixed Price</option>
                                        <option value="hourly" <?= ($project['budget_type'] === 'hourly') ? 'selected' : '' ?>>Hourly Rate</option>
                                    </select>
                                    <?php if ($has_active_contracts): ?>
                                        <input type="hidden" name="budget_type" value="<?= htmlspecialchars($project['budget_type']) ?>">
                                    <?php endif; ?>
                                </div>
                                <div class="form-group">
                                    <label for="budget">Estimated Budget ($)</label>
                                    <input type="number" id="budget" name="budget" required placeholder="e.g. 5000" step="0.01" value="<?= htmlspecialchars($project['budget_max']) ?>" <?= $has_active_contracts ? 'readonly style="opacity: 0.6; cursor: not-allowed;"' : '' ?>>
                                    <?php if ($has_active_contracts): ?>
                                        <input type="hidden" name="budget" value="<?= htmlspecialchars($project['budget_max']) ?>">
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="status">Listing Status</label>
                                    <select id="status" name="status" <?= in_array($project['status'], ['in_progress', 'completed']) ? 'disabled style="opacity: 0.6; cursor: not-allowed;"' : '' ?>>
                                        <option value="open" <?= ($project['status'] === 'open') ? 'selected' : '' ?>>Open (Accepting proposals)</option>
                                        <option value="closed" <?= ($project['status'] === 'closed') ? 'selected' : '' ?>>Closed (Stop proposals, unlist from public)</option>
                                        <?php if (in_array($project['status'], ['in_progress', 'completed'])): ?>
                                            <option value="<?= htmlspecialchars($project['status']) ?>" selected><?= ucfirst(str_replace('_', ' ', $project['status'])) ?> (Contract in effect)</option>
                                        <?php endif; ?>
                                    </select>
                                    <?php if (in_array($project['status'], ['in_progress', 'completed'])): ?>
                                        <input type="hidden" name="status" value="<?= htmlspecialchars($project['status']) ?>">
                                        <small style="color: var(--text-secondary); margin-top: 4px; display: block;">Status is managed by milestone and contract lifecycle.</small>
                                    <?php endif; ?>
                                </div>
                                <div class="form-group" style="display: flex; flex-direction: column; justify-content: flex-end;">
                                    <div style="font-size: 0.85rem; color: var(--text-secondary); padding: 10px 0;">
                                        <strong>Posted:</strong> <?= date('F j, Y, g:i a', strtotime($project['created_at'])) ?><br>
                                        <strong>Applications:</strong> <?= (int)$project['proposal_count'] ?> received
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-actions-divider" style="display: flex; gap: 12px; align-items: center;">
                                <button type="submit" class="btn btn-primary" style="padding: 10px 24px;">
                                    <i data-lucide="check"></i> Save Changes
                                </button>
                                <a href="projects.php" class="btn btn-outline" style="padding: 10px 20px;">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
    <script>lucide.createIcons();</script>
</body>
</html>
