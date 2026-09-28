<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

require_role('freelancer');
$user_id = current_user_id();
$profile_id = get_profile_id($conn, $user_id, 'freelancer');

$search = trim($_GET['q'] ?? '');
$category_filter = (int)($_GET['category'] ?? 0);

// Fetch categories for filter tabs
$categories_res = $conn->query("SELECT * FROM skill_categories ORDER BY name ASC");
$categories = $categories_res ? $categories_res->fetch_all(MYSQLI_ASSOC) : [];

// Build filter conditions
$where = ["p.status = 'open'"];
$where_params = [];
$where_types = "";

if (!empty($search)) {
    $where[] = "(p.title LIKE ? OR p.description LIKE ?)";
    $search_param = '%' . $search . '%';
    $where_params[] = $search_param;
    $where_params[] = $search_param;
    $where_types .= "ss";
}

if ($category_filter > 0) {
    $where[] = "p.skill_category_id = ?";
    $where_params[] = $category_filter;
    $where_types .= "i";
}

// Total count
$count_sql = "SELECT COUNT(*) FROM projects p WHERE " . implode(' AND ', $where);
$count_stmt = $conn->prepare($count_sql);
if (!empty($where_params)) {
    $count_stmt->bind_param($where_types, ...$where_params);
}
$count_stmt->execute();
$total_jobs = (int)$count_stmt->get_result()->fetch_row()[0];

// Paginate (6 per page)
$pag = paginate($total_jobs, 6);

// Main query
$sql = "SELECT p.*, cp.company_name, u.full_name as client_name, sc.name as skill_name,
        (SELECT COUNT(*) FROM proposals WHERE project_id = p.id AND freelancer_id = ?) as already_applied,
        (SELECT COUNT(*) FROM proposals WHERE project_id = p.id) as total_proposals
        FROM projects p
        JOIN client_profiles cp ON p.client_id = cp.id
        JOIN users u ON cp.user_id = u.id
        LEFT JOIN skill_categories sc ON p.skill_category_id = sc.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY p.created_at DESC
        LIMIT ? OFFSET ?";

$main_params = array_merge([$profile_id], $where_params, [$pag['per_page'], $pag['offset']]);
$main_types = "i" . $where_types . "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($main_types, ...$main_params);
$stmt->execute();
$projects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Find Jobs - FreeMark</title>
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
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>
                FreeMark
            </a>
        </div>
        <nav class="sidebar-nav">
            <ul>
                <li><a href="dashboard.php"><i data-lucide="layout-dashboard"></i> Overview</a></li>
                <li><a href="profile.php"><i data-lucide="user"></i> My Profile</a></li>
                <li><a href="jobs.php" class="active"><i data-lucide="briefcase"></i> Find Jobs</a></li>
                <li><a href="tests.php"><i data-lucide="check-square"></i> Skill Tests</a></li>
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
                <h2>Browse Open Projects</h2>
                <p>Personalized project opportunities matched to your skill profile</p>
            </div>
            <div class="header-actions">
                <form method="GET" action="jobs.php" class="search-input-wrapper">
                    <i data-lucide="search" class="search-icon"></i>
                    <input type="text" name="q" placeholder="Search jobs..." value="<?= htmlspecialchars($search) ?>">
                    <?php if ($category_filter > 0): ?>
                        <input type="hidden" name="category" value="<?= $category_filter ?>">
                    <?php endif; ?>
                </form>
            </div>
        </header>

        <div class="freelancer-content">
            <!-- Filter Tabs -->
            <div class="smart-filter-bar">
                <a href="jobs.php<?= !empty($search) ? '?q=' . urlencode($search) : '' ?>" class="smart-tab <?= $category_filter === 0 ? 'active' : '' ?>">
                    All Categories
                </a>
                <?php foreach ($categories as $cat): ?>
                    <a href="jobs.php?category=<?= $cat['id'] ?><?= !empty($search) ? '&q=' . urlencode($search) : '' ?>" class="smart-tab <?= $category_filter === (int)$cat['id'] ? 'active' : '' ?>">
                        <?= htmlspecialchars($cat['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Jobs List -->
            <div>
                <?php if (!empty($projects)): ?>
                    <?php foreach ($projects as $job): ?>
                    <div class="job-card-rich" style="margin-bottom: 20px;">
                        <div class="job-header">
                            <div>
                                <div class="job-title-wrapper" style="display: flex; align-items: center; gap: 10px;">
                                    <h3 class="job-title" style="margin: 0;">
                                        <a href="../guest/job-details.php?id=<?= $job['id'] ?>"><?= htmlspecialchars($job['title']) ?></a>
                                    </h3>
                                    <?php if ($job['already_applied'] > 0): ?>
                                        <span class="status-badge success" style="font-size: 0.75rem;">Applied</span>
                                    <?php endif; ?>
                                </div>
                                <div class="job-meta-row" style="margin-top: 6px; font-size: 0.85rem; color: var(--text-secondary);">
                                    <span>Client: <strong><?= htmlspecialchars($job['company_name'] ?: $job['client_name']) ?></strong></span>
                                    <span class="meta-dot">•</span>
                                    <span><i data-lucide="file-text" style="width: 12px; display: inline;"></i> <?= $job['total_proposals'] ?> proposals</span>
                                    <span class="meta-dot">•</span>
                                    <span>Posted <?= date('M j, Y', strtotime($job['created_at'])) ?></span>
                                    <span class="meta-dot">•</span>
                                    <span><i data-lucide="calendar" style="width: 12px; display: inline;"></i> <?= format_duration($job['duration']) ?></span>
                                </div>
                            </div>
                            <div class="job-budget-section" style="text-align: right;">
                                <div class="job-budget-amount" style="font-size: 1.25rem; font-weight: bold; color: var(--accent);">
                                    <?= format_currency($job['budget_max']) ?>
                                </div>
                                <div class="job-budget-type" style="font-size: 0.8rem; color: var(--text-secondary); text-transform: capitalize;">
                                    <?= htmlspecialchars($job['budget_type']) ?>
                                </div>
                            </div>
                        </div>

                        <p class="job-card-desc"><?= nl2br(htmlspecialchars($job['description'])) ?></p>

                        <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 15px; border-top: 1px solid var(--border-color);">
                            <div class="tags" style="margin-bottom: 0;">
                                <?php if ($job['skill_name']): ?>
                                    <span class="tag"><?= htmlspecialchars($job['skill_name']) ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="job-footer-right" style="display: flex; gap: 10px;">
                                <a href="../guest/job-details.php?id=<?= $job['id'] ?>" class="btn btn-outline btn-sm">View Details</a>
                                <?php if ($job['already_applied'] > 0): ?>
                                    <button class="btn btn-outline btn-sm" disabled style="opacity: 0.7;">Already Applied</button>
                                <?php else: ?>
                                    <a href="apply.php?job_id=<?= $job['id'] ?>" class="btn btn-primary btn-sm">Apply Now</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="card" style="padding: 30px; text-align: center; color: var(--text-secondary);">
                        <p>No open projects found matching your criteria.</p>
                    </div>
                <?php endif; ?>
            </div>

            <?= render_pagination($pag, 'jobs.php') ?>
        </div>
    </main>
    <script>lucide.createIcons();</script>
</body>
</html>
