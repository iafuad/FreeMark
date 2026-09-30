<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';
require_role('admin');

// Session Flash Messages
$flash_success = $_SESSION['flash_success'] ?? '';
$flash_error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Handle Actions (Add, Edit, Delete) with Post-Redirect-Get
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $_SESSION['flash_error'] = "Category name cannot be empty.";
        } else {
            // Check for duplicate name
            $chk = $conn->prepare("SELECT id FROM skill_categories WHERE LOWER(name) = LOWER(?)");
            $chk->bind_param("s", $name);
            $chk->execute();
            if ($chk->get_result()->fetch_assoc()) {
                $_SESSION['flash_error'] = "Category '" . htmlspecialchars($name) . "' already exists.";
            } else {
                $stmt = $conn->prepare("INSERT INTO skill_categories (name) VALUES (?)");
                $stmt->bind_param("s", $name);
                if ($stmt->execute()) {
                    $_SESSION['flash_success'] = "Category '" . htmlspecialchars($name) . "' added successfully.";
                } else {
                    $_SESSION['flash_error'] = "Failed to add category. Database error.";
                }
            }
        }
        header("Location: skills.php");
        exit;
    } elseif ($action === 'edit') {
        $skill_id = (int)($_POST['skill_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');

        if ($skill_id <= 0 || $name === '') {
            $_SESSION['flash_error'] = "Invalid category details provided for update.";
        } else {
            // Check if name collides with another category
            $chk = $conn->prepare("SELECT id FROM skill_categories WHERE LOWER(name) = LOWER(?) AND id != ?");
            $chk->bind_param("si", $name, $skill_id);
            $chk->execute();
            if ($chk->get_result()->fetch_assoc()) {
                $_SESSION['flash_error'] = "Another category with the name '" . htmlspecialchars($name) . "' already exists.";
            } else {
                $stmt = $conn->prepare("UPDATE skill_categories SET name = ? WHERE id = ?");
                $stmt->bind_param("si", $name, $skill_id);
                if ($stmt->execute()) {
                    $_SESSION['flash_success'] = "Category renamed to '" . htmlspecialchars($name) . "' successfully.";
                } else {
                    $_SESSION['flash_error'] = "Failed to update category.";
                }
            }
        }
        header("Location: skills.php");
        exit;
    } elseif ($action === 'delete') {
        $skill_id = (int)($_POST['skill_id'] ?? 0);
        if ($skill_id > 0) {
            // Get category name and dependencies
            $cat_stmt = $conn->prepare("SELECT name FROM skill_categories WHERE id = ?");
            $cat_stmt->bind_param("i", $skill_id);
            $cat_stmt->execute();
            $cat_info = $cat_stmt->get_result()->fetch_assoc();
            $cat_name = $cat_info['name'] ?? "Category #$skill_id";

            $stmt = $conn->prepare("DELETE FROM skill_categories WHERE id = ?");
            $stmt->bind_param("i", $skill_id);
            if ($stmt->execute()) {
                $_SESSION['flash_success'] = "Category '" . htmlspecialchars($cat_name) . "' has been removed.";
            } else {
                $_SESSION['flash_error'] = "Failed to remove category.";
            }
        }
        header("Location: skills.php");
        exit;
    }
}

// Optimized Aggregation Query: Categories with linked Freelancers & Projects
$categories_res = $conn->query("
    SELECT 
        sc.id, 
        sc.name,
        COUNT(DISTINCT fs.freelancer_id) as freelancer_count,
        COUNT(DISTINCT p.id) as project_count
    FROM skill_categories sc
    LEFT JOIN freelancer_skills fs ON sc.id = fs.skill_id
    LEFT JOIN projects p ON sc.id = p.skill_category_id
    GROUP BY sc.id, sc.name
    ORDER BY sc.name ASC
");
$categories = $categories_res ? $categories_res->fetch_all(MYSQLI_ASSOC) : [];

// Compute KPI Metrics
$total_categories = count($categories);
$total_freelancer_tags = 0;
$total_categorized_projects = 0;
$unused_categories_count = 0;

foreach ($categories as $cat) {
    $fl_count = (int)$cat['freelancer_count'];
    $pr_count = (int)$cat['project_count'];
    $total_freelancer_tags += $fl_count;
    $total_categorized_projects += $pr_count;
    if ($fl_count === 0 && $pr_count === 0) {
        $unused_categories_count++;
    }
}
$in_use_count = $total_categories - $unused_categories_count;
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
    <!-- Sidebar -->
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
                <li><a href="reports.php"><i data-lucide="file-text"></i> Analytics</a></li>
                <li><a href="profile-reports.php"><i data-lucide="flag"></i> Profile Reports</a></li>
            </ul>
        </nav>
        <div class="sidebar-footer">
            <a href="../logout.php" style="color: var(--text-secondary); text-decoration: none; display: flex; align-items: center; gap: 10px;">
                <i data-lucide="log-out"></i> Logout
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="admin-main">
        <header class="admin-header">
            <div class="header-title">
                <h2>Skill Categories & Specializations</h2>
                <p>Manage platform taxonomy, track usage across projects, and configure service domains</p>
            </div>
            <button type="button" class="btn btn-primary" onclick="focusAddInput()" style="display: flex; align-items: center; gap: 6px;">
                <i data-lucide="plus" style="width: 16px; height: 16px;"></i> Add Category
            </button>
        </header>

        <div class="admin-content">
            <?php if (!empty($flash_success)): ?>
                <div class="alert alert-success" style="background: rgba(34, 197, 94, 0.15); border: 1px solid rgba(34, 197, 94, 0.3); color: #22c55e; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                    <i data-lucide="check-circle" style="width: 20px; height: 20px; flex-shrink: 0;"></i>
                    <span><?= htmlspecialchars($flash_success) ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($flash_error)): ?>
                <div class="alert alert-danger" style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                    <i data-lucide="alert-circle" style="width: 20px; height: 20px; flex-shrink: 0;"></i>
                    <span><?= htmlspecialchars($flash_error) ?></span>
                </div>
            <?php endif; ?>

            <!-- Sleek KPI Metrics Cards -->
            <div class="sleek-stats-grid" style="margin-bottom: 24px;">
                <div class="sleek-stat-card">
                    <div class="sleek-stat-header">
                        <span class="sleek-stat-title">Total Categories</span>
                        <div class="sleek-stat-icon icon-yellow"><i data-lucide="award"></i></div>
                    </div>
                    <div class="sleek-stat-value"><?= $total_categories ?></div>
                    <div class="sleek-stat-trend trend-neutral"><i data-lucide="layers" class="icon-sm"></i> Platform Taxonomies</div>
                </div>

                <div class="sleek-stat-card">
                    <div class="sleek-stat-header">
                        <span class="sleek-stat-title">Freelancer Skill Tags</span>
                        <div class="sleek-stat-icon icon-blue"><i data-lucide="users"></i></div>
                    </div>
                    <div class="sleek-stat-value"><?= $total_freelancer_tags ?></div>
                    <div class="sleek-stat-trend trend-up"><i data-lucide="trending-up" class="icon-sm"></i> Associated Talents</div>
                </div>

                <div class="sleek-stat-card">
                    <div class="sleek-stat-header">
                        <span class="sleek-stat-title">Categorized Projects</span>
                        <div class="sleek-stat-icon icon-purple"><i data-lucide="briefcase"></i></div>
                    </div>
                    <div class="sleek-stat-value"><?= $total_categorized_projects ?></div>
                    <div class="sleek-stat-trend trend-up"><i data-lucide="check-circle" class="icon-sm"></i> Client Listings</div>
                </div>

                <div class="sleek-stat-card" style="<?= $unused_categories_count > 0 ? 'cursor: pointer;' : '' ?>" onclick="filterByTab('unused')">
                    <div class="sleek-stat-header">
                        <span class="sleek-stat-title">Unused Categories</span>
                        <div class="sleek-stat-icon" style="background: rgba(148, 163, 184, 0.15); color: #94a3b8;"><i data-lucide="help-circle"></i></div>
                    </div>
                    <div class="sleek-stat-value" style="<?= $unused_categories_count > 0 ? 'color: #94a3b8;' : '' ?>"><?= $unused_categories_count ?></div>
                    <div class="sleek-stat-trend trend-neutral"><i data-lucide="filter" class="icon-sm"></i> <?= $unused_categories_count > 0 ? 'Click to filter cleanup' : 'All categories in use' ?></div>
                </div>
            </div>

            <!-- Quick Add Category Card -->
            <div class="skill-add-card">
                <div class="card-header">
                    <h3><i data-lucide="plus-circle" class="icon-sm" style="margin-right: 6px; vertical-align: middle;"></i> Quick Add Category</h3>
                </div>
                <form class="skill-add-form" method="POST" action="skills.php">
                    <input type="hidden" name="action" value="add">
                    <input type="text" id="addCategoryInput" name="name" placeholder="Enter skill category name (e.g. Flutter, Cybersecurity, Machine Learning)..." required autocomplete="off">
                    <button type="submit" class="btn btn-primary" style="white-space: nowrap;">
                        <i data-lucide="check" style="width: 15px; height: 15px;"></i> Save Category
                    </button>
                </form>
            </div>

            <!-- Toolbar: Filter Tabs, Real-time Search, Sort -->
            <div class="skills-toolbar">
                <div class="skills-filter-group">
                    <button type="button" class="skills-tab-btn active" id="tabAll" onclick="filterByTab('all')">
                        All <span class="skills-tab-badge" id="badgeAll"><?= $total_categories ?></span>
                    </button>
                    <button type="button" class="skills-tab-btn" id="tabInUse" onclick="filterByTab('in_use')">
                        In Use <span class="skills-tab-badge" id="badgeInUse"><?= $in_use_count ?></span>
                    </button>
                    <button type="button" class="skills-tab-btn" id="tabUnused" onclick="filterByTab('unused')">
                        Unused <span class="skills-tab-badge" id="badgeUnused"><?= $unused_categories_count ?></span>
                    </button>
                </div>

                <div class="skills-controls-right">
                    <div class="skills-search-wrap">
                        <i data-lucide="search" class="skills-search-icon"></i>
                        <input type="text" id="skillSearchInput" placeholder="Filter categories..." oninput="handleSearch()">
                    </div>

                    <select id="skillSortSelect" onchange="handleSort()" style="padding: 8px 12px; border-radius: 6px; background: var(--bg-card); border: 1px solid var(--border-color); color: var(--text-primary); font-size: 0.85rem; outline: none;">
                        <option value="name_asc">Name (A &rarr; Z)</option>
                        <option value="name_desc">Name (Z &rarr; A)</option>
                        <option value="freelancers_desc">Most Freelancers</option>
                        <option value="projects_desc">Most Projects</option>
                    </select>
                </div>
            </div>

            <!-- Categories Table View -->
            <div class="skills-table-card">
                <div class="card-header">
                    <h3>Active Categories (<span id="visibleCount"><?= $total_categories ?></span>)</h3>
                    <span style="font-size: 0.8rem; color: var(--text-secondary);">Click counts to inspect linked freelancers or projects</span>
                </div>

                <div style="overflow-x: auto;">
                    <table class="skills-table" id="skillsTable">
                        <thead>
                            <tr>
                                <th style="width: 45%;">Category Name</th>
                                <th style="width: 20%;">Linked Freelancers</th>
                                <th style="width: 20%;">Associated Projects</th>
                                <th style="width: 15%; text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="skillsTableBody">
                            <?php if (empty($categories)): ?>
                                <tr id="noCategoriesRow">
                                    <td colspan="4" style="text-align: center; padding: 40px; color: var(--text-secondary);">
                                        <i data-lucide="award" style="width: 36px; height: 36px; opacity: 0.5; margin-bottom: 8px;"></i>
                                        <p style="margin: 0;">No skill categories found. Add your first category above.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($categories as $cat): ?>
                                    <?php 
                                        $fl_c = (int)$cat['freelancer_count'];
                                        $pr_c = (int)$cat['project_count'];
                                        $is_used = ($fl_c > 0 || $pr_c > 0);
                                        $initials = strtoupper(substr($cat['name'], 0, 2));
                                    ?>
                                    <tr class="skill-row" 
                                        data-id="<?= $cat['id'] ?>" 
                                        data-name="<?= htmlspecialchars(strtolower($cat['name'])) ?>"
                                        data-used="<?= $is_used ? '1' : '0' ?>"
                                        data-freelancers="<?= $fl_c ?>"
                                        data-projects="<?= $pr_c ?>">
                                        <td>
                                            <div class="skill-name-cell">
                                                <div class="skill-icon-avatar">
                                                    <?= $initials ?>
                                                </div>
                                                <div>
                                                    <span class="skill-label-text"><?= htmlspecialchars($cat['name']) ?></span>
                                                    <?php if (!$is_used): ?>
                                                        <span style="display: block; font-size: 0.72rem; color: var(--text-secondary); margin-top: 2px;">
                                                            Unassigned
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($fl_c > 0): ?>
                                                <a href="../guest/freelancers.php?categories[]=<?= $cat['id'] ?>" target="_blank" class="skill-count-pill freelancers" title="View freelancers with this skill">
                                                    <i data-lucide="users" style="width: 12px; height: 12px;"></i> <?= $fl_c ?> freelancer<?= $fl_c === 1 ? '' : 's' ?> &rarr;
                                                </a>
                                            <?php else: ?>
                                                <span class="skill-count-pill zero">0 freelancers</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($pr_c > 0): ?>
                                                <a href="../guest/jobs.php?q=<?= urlencode($cat['name']) ?>" target="_blank" class="skill-count-pill projects" title="View projects under this skill">
                                                    <i data-lucide="briefcase" style="width: 12px; height: 12px;"></i> <?= $pr_c ?> project<?= $pr_c === 1 ? '' : 's' ?> &rarr;
                                                </a>
                                            <?php else: ?>
                                                <span class="skill-count-pill zero">0 projects</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="skill-action-btns">
                                                <button type="button" class="btn-skill-action edit" onclick="openEditModal(<?= $cat['id'] ?>, '<?= htmlspecialchars(addslashes($cat['name'])) ?>')" title="Rename Category">
                                                    <i data-lucide="edit-3" style="width: 14px; height: 14px;"></i>
                                                </button>
                                                <button type="button" class="btn-skill-action delete" onclick="openDeleteModal(<?= $cat['id'] ?>, '<?= htmlspecialchars(addslashes($cat['name'])) ?>', <?= $fl_c ?>, <?= $pr_c ?>)" title="Delete Category">
                                                    <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <tr id="emptySearchRow" style="display: none;">
                                <td colspan="4" style="text-align: center; padding: 35px; color: var(--text-secondary);">
                                    <i data-lucide="search-x" style="width: 32px; height: 32px; opacity: 0.5; margin-bottom: 6px;"></i>
                                    <p style="margin: 0; font-size: 0.9rem;">No categories match your search filter.</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal: Edit / Rename Category -->
    <div id="editSkillModal" class="skill-modal-backdrop" style="display: none;">
        <div class="skill-modal-box">
            <div class="skill-modal-header">
                <div class="skill-modal-title-group">
                    <div class="skill-modal-icon-badge">
                        <i data-lucide="edit-3" style="width: 18px; height: 18px;"></i>
                    </div>
                    <div>
                        <h3 style="margin: 0; font-size: 1.15rem; color: var(--text-primary);">Rename Category</h3>
                        <p style="margin: 2px 0 0 0; font-size: 0.8rem; color: var(--text-secondary);">Preserves all linked freelancer and project relationships</p>
                    </div>
                </div>
                <button type="button" class="skill-modal-close-btn" onclick="closeEditModal()">
                    <i data-lucide="x" style="width: 18px; height: 18px;"></i>
                </button>
            </div>

            <form method="POST" action="skills.php">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="skill_id" id="editSkillId" value="">

                <div class="skill-modal-body">
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--text-primary); margin-bottom: 6px;">
                        Category Name
                    </label>
                    <input type="text" id="editSkillNameInput" name="name" required style="width: 100%; padding: 10px 12px; border-radius: 6px; background: var(--bg-primary); border: 1px solid var(--border-color); color: var(--text-primary); font-size: 0.9rem; outline: none; box-sizing: border-box;">
                </div>

                <div class="skill-modal-footer">
                    <button type="button" class="btn btn-outline btn-compact" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-compact">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Delete Confirmation with Dependency Warning -->
    <div id="deleteSkillModal" class="skill-modal-backdrop" style="display: none;">
        <div class="skill-modal-box">
            <div class="skill-modal-header">
                <div class="skill-modal-title-group">
                    <div class="skill-modal-icon-badge danger">
                        <i data-lucide="trash-2" style="width: 18px; height: 18px;"></i>
                    </div>
                    <div>
                        <h3 style="margin: 0; font-size: 1.15rem; color: #ef4444;">Delete Category</h3>
                        <p style="margin: 2px 0 0 0; font-size: 0.8rem; color: var(--text-secondary);">Confirm category removal from platform</p>
                    </div>
                </div>
                <button type="button" class="skill-modal-close-btn" onclick="closeDeleteModal()">
                    <i data-lucide="x" style="width: 18px; height: 18px;"></i>
                </button>
            </div>

            <form method="POST" action="skills.php">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="skill_id" id="deleteSkillId" value="">

                <div class="skill-modal-body">
                    <p style="font-size: 0.9rem; line-height: 1.5; color: var(--text-primary); margin-bottom: 12px;">
                        Are you sure you want to permanently delete <strong id="deleteSkillName"></strong>?
                    </p>

                    <div id="deleteWarningBox" style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 8px; padding: 12px 14px; font-size: 0.85rem; color: #fca5a5; display: none;">
                        <i data-lucide="alert-triangle" style="width: 16px; height: 16px; display: inline; vertical-align: middle; margin-right: 4px;"></i>
                        <span id="deleteWarningText"></span>
                    </div>
                </div>

                <div class="skill-modal-footer">
                    <button type="button" class="btn btn-outline btn-compact" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" class="btn btn-compact" style="background: #ef4444; border-color: #ef4444; color: white;">
                        Confirm Delete
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let currentTab = 'all';

        function focusAddInput() {
            const input = document.getElementById('addCategoryInput');
            if (input) {
                input.focus();
                input.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        // Live Filtering & Search
        function filterByTab(tab) {
            currentTab = tab;
            document.getElementById('tabAll').classList.toggle('active', tab === 'all');
            document.getElementById('tabInUse').classList.toggle('active', tab === 'in_use');
            document.getElementById('tabUnused').classList.toggle('active', tab === 'unused');
            applyFilters();
        }

        function handleSearch() {
            applyFilters();
        }

        function applyFilters() {
            const query = (document.getElementById('skillSearchInput').value || '').toLowerCase().trim();
            const rows = document.querySelectorAll('.skill-row');
            let visibleCount = 0;

            rows.forEach(row => {
                const name = row.getAttribute('data-name');
                const isUsed = row.getAttribute('data-used') === '1';

                let matchesTab = true;
                if (currentTab === 'in_use' && !isUsed) matchesTab = false;
                if (currentTab === 'unused' && isUsed) matchesTab = false;

                let matchesQuery = true;
                if (query !== '' && !name.includes(query)) matchesQuery = false;

                if (matchesTab && matchesQuery) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            document.getElementById('visibleCount').textContent = visibleCount;
            const emptyRow = document.getElementById('emptySearchRow');
            if (emptyRow) {
                emptyRow.style.display = (visibleCount === 0 && rows.length > 0) ? '' : 'none';
            }
        }

        // Sorting
        function handleSort() {
            const sortMode = document.getElementById('skillSortSelect').value;
            const tbody = document.getElementById('skillsTableBody');
            const rows = Array.from(document.querySelectorAll('.skill-row'));

            rows.sort((a, b) => {
                const nameA = a.getAttribute('data-name');
                const nameB = b.getAttribute('data-name');
                const flA = parseInt(a.getAttribute('data-freelancers'), 10) || 0;
                const flB = parseInt(b.getAttribute('data-freelancers'), 10) || 0;
                const prA = parseInt(a.getAttribute('data-projects'), 10) || 0;
                const prB = parseInt(b.getAttribute('data-projects'), 10) || 0;

                if (sortMode === 'name_asc') return nameA.localeCompare(nameB);
                if (sortMode === 'name_desc') return nameB.localeCompare(nameA);
                if (sortMode === 'freelancers_desc') return flB - flA || nameA.localeCompare(nameB);
                if (sortMode === 'projects_desc') return prB - prA || nameA.localeCompare(nameB);
                return 0;
            });

            rows.forEach(r => tbody.appendChild(r));
            const emptyRow = document.getElementById('emptySearchRow');
            if (emptyRow) tbody.appendChild(emptyRow);
        }

        // Edit Modal
        function openEditModal(id, name) {
            document.getElementById('editSkillId').value = id;
            const nameInput = document.getElementById('editSkillNameInput');
            nameInput.value = name;
            document.getElementById('editSkillModal').style.display = 'flex';
            setTimeout(() => nameInput.focus(), 50);
        }
        function closeEditModal() {
            document.getElementById('editSkillModal').style.display = 'none';
        }

        // Delete Modal
        function openDeleteModal(id, name, flCount, prCount) {
            document.getElementById('deleteSkillId').value = id;
            document.getElementById('deleteSkillName').textContent = name;
            const warnBox = document.getElementById('deleteWarningBox');
            const warnText = document.getElementById('deleteWarningText');

            if (flCount > 0 || prCount > 0) {
                warnBox.style.display = 'block';
                warnText.innerHTML = `<strong>Attention:</strong> This category is currently associated with <strong>${flCount} freelancer profile(s)</strong> and <strong>${prCount} active project listing(s)</strong>. Deleting it will detach it from those profiles and listings.`;
            } else {
                warnBox.style.display = 'none';
            }

            document.getElementById('deleteSkillModal').style.display = 'flex';
        }
        function closeDeleteModal() {
            document.getElementById('deleteSkillModal').style.display = 'none';
        }

        // Modal backdrop click
        window.addEventListener('click', function(e) {
            const em = document.getElementById('editSkillModal');
            if (em && e.target === em) closeEditModal();
            const dm = document.getElementById('deleteSkillModal');
            if (dm && e.target === dm) closeDeleteModal();
        });

        lucide.createIcons();
    </script>
</body>
</html>
