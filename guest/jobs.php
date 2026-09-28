<?php
require_once '../config/db.php';
require_once '../includes/helpers.php';
require_once '../includes/auth.php';



$q = trim($_GET['q'] ?? '');
$selected_types = isset($_GET['type']) ? (is_array($_GET['type']) ? $_GET['type'] : [$_GET['type']]) : [];
$selected_categories = isset($_GET['category']) ? (is_array($_GET['category']) ? array_map('intval', $_GET['category']) : [(int)$_GET['category']]) : [];
$selected_durations = isset($_GET['duration']) ? (is_array($_GET['duration']) ? $_GET['duration'] : [$_GET['duration']]) : [];
$min_budget = floatval($_GET['min_budget'] ?? 0);
$max_budget = floatval($_GET['max_budget'] ?? 0);
$sort = $_GET['sort'] ?? 'newest';

// Fetch all skill categories with open job counts
$cat_stmt = $conn->query("
    SELECT sc.*, 
        (SELECT COUNT(*) FROM projects p WHERE p.skill_category_id = sc.id AND p.status = 'open') as job_count 
    FROM skill_categories sc 
    ORDER BY sc.name ASC
");
$all_categories = $cat_stmt ? $cat_stmt->fetch_all(MYSQLI_ASSOC) : [];
$cat_map = array_column($all_categories, 'name', 'id');

// Build query
$where = ["p.status = 'open'"];
$params = [];
$types = "";

if ($q !== '') {
    $where[] = "(p.title LIKE ? OR p.description LIKE ? OR c.company_name LIKE ? OR s.name LIKE ?)";
    $q_term = '%' . $q . '%';
    $params[] = $q_term;
    $params[] = $q_term;
    $params[] = $q_term;
    $params[] = $q_term;
    $types .= "ssss";
}

$valid_types = array_values(array_intersect($selected_types, ['fixed', 'hourly']));
if (!empty($valid_types)) {
    $placeholders = implode(',', array_fill(0, count($valid_types), '?'));
    $where[] = "p.budget_type IN ($placeholders)";
    foreach ($valid_types as $vt) {
        $params[] = $vt;
        $types .= "s";
    }
}

$valid_categories = array_values(array_filter($selected_categories, fn($c) => $c > 0));
if (!empty($valid_categories)) {
    $placeholders = implode(',', array_fill(0, count($valid_categories), '?'));
    $where[] = "p.skill_category_id IN ($placeholders)";
    foreach ($valid_categories as $cid) {
        $params[] = $cid;
        $types .= "i";
    }
}

$valid_durations = array_values(array_intersect($selected_durations, ['less_1w', '1_4w', '1_3m', '3m_plus']));
if (!empty($valid_durations)) {
    $placeholders = implode(',', array_fill(0, count($valid_durations), '?'));
    $where[] = "p.duration IN ($placeholders)";
    foreach ($valid_durations as $vd) {
        $params[] = $vd;
        $types .= "s";
    }
}

if ($min_budget > 0) {
    $where[] = "p.budget_max >= ?";
    $params[] = $min_budget;
    $types .= "d";
}

if ($max_budget > 0) {
    $where[] = "p.budget_max <= ?";
    $params[] = $max_budget;
    $types .= "d";
}

$order_by = match($sort) {
    'budget_high' => 'p.budget_max DESC',
    'budget_low' => 'p.budget_max ASC',
    'proposals_low' => 'proposal_count ASC',
    'proposals_high' => 'proposal_count DESC',
    default => 'p.created_at DESC'
};

// Count total jobs matching filters
$count_sql = "SELECT COUNT(*) FROM projects p 
              JOIN client_profiles c ON p.client_id = c.id 
              LEFT JOIN skill_categories s ON p.skill_category_id = s.id 
              WHERE " . implode(' AND ', $where);
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_jobs = (int)$count_stmt->get_result()->fetch_row()[0];

// Paginate (6 per page)
$pag = paginate($total_jobs, 6);

$sql = "SELECT p.*, c.company_name, s.name as skill_name, 
        (SELECT COUNT(*) FROM proposals WHERE project_id = p.id) as proposal_count 
        FROM projects p 
        JOIN client_profiles c ON p.client_id = c.id 
        LEFT JOIN skill_categories s ON p.skill_category_id = s.id 
        WHERE " . implode(' AND ', $where) . " 
        ORDER BY " . $order_by . " 
        LIMIT ? OFFSET ?";

$paged_params = $params;
$paged_params[] = $pag['per_page'];
$paged_params[] = $pag['offset'];
$paged_types = $types . "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($paged_types, ...$paged_params);
$stmt->execute();
$jobs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Active filter labels for chips
$active_filters = [];
if ($q !== '') {
    $active_filters[] = ['label' => 'Keyword: "' . htmlspecialchars($q) . '"', 'url' => build_filter_url('jobs.php', 'q')];
}
foreach ($valid_types as $vt) {
    $active_filters[] = ['label' => 'Type: ' . ucfirst($vt), 'url' => build_filter_url('jobs.php', 'type', $vt)];
}
foreach ($valid_categories as $vc) {
    $cname = $cat_map[$vc] ?? ('Category #' . $vc);
    $active_filters[] = ['label' => 'Skill: ' . htmlspecialchars($cname), 'url' => build_filter_url('jobs.php', 'category', $vc)];
}
foreach ($valid_durations as $vd) {
    $active_filters[] = ['label' => 'Duration: ' . format_duration($vd), 'url' => build_filter_url('jobs.php', 'duration', $vd)];
}
if ($min_budget > 0 || $max_budget > 0) {
    $b_label = 'Budget: ';
    if ($min_budget > 0 && $max_budget > 0) $b_label .= format_currency($min_budget) . ' - ' . format_currency($max_budget);
    elseif ($min_budget > 0) $b_label .= '≥ ' . format_currency($min_budget);
    else $b_label .= '≤ ' . format_currency($max_budget);
    $active_filters[] = ['label' => $b_label, 'url' => build_filter_url('jobs.php', 'min_budget', null, ['max_budget'])];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Jobs - FreeMark</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/guest/components.css">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
    <header>
        <div class="container navbar">
            <a href="../index.php" class="logo">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>
                </svg>
                FreeMark
            </a>
            
            <nav class="nav-links">
                <a href="jobs.php" class="nav-active">Find Jobs</a>
                <a href="freelancers.php">Find Freelancers</a>
            </nav>

            <div class="nav-actions">
                <?php if (is_logged_in()): ?>
                    <?php
                        $role = current_user_role();
                        $dash_url = ($role === 'client') ? '../client/dashboard.php' : (($role === 'admin') ? '../admin/dashboard.php' : '../freelancer/dashboard.php');
                    ?>
                    <a href="<?= $dash_url ?>" class="btn btn-primary">Dashboard</a>
                    <a href="../logout.php" class="btn btn-outline">Log Out</a>
                <?php else: ?>
                    <a href="../login.php" class="btn btn-outline">Log In</a>
                    <a href="../register.php" class="btn btn-primary">Sign Up</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="container">
        <div class="browse-layout">
            <aside class="filter-sidebar">
                <form action="jobs.php" method="GET" id="jobsFilterForm">
                    <input type="hidden" name="sort" id="sortHiddenInput" value="<?= htmlspecialchars($sort) ?>">

                    <div class="filter-header">
                        <h3>Filters</h3>
                        <a href="jobs.php" style="color: var(--text-secondary); text-decoration: none; font-size: 0.85rem;">Clear all</a>
                    </div>
                    
                    <!-- Search input -->
                    <div class="filter-section">
                        <h4>Search</h4>
                        <div class="search-input-wrapper">
                            <input type="text" name="q" class="filter-input" placeholder="Search keywords..." value="<?= htmlspecialchars($q) ?>">
                            <i data-lucide="search" class="search-icon"></i>
                        </div>
                    </div>

                    <!-- Project Type -->
                    <div class="filter-section">
                        <h4>Project Type</h4>
                        <div class="filter-group">
                            <label class="filter-label">
                                <input type="checkbox" name="type[]" value="fixed" <?= in_array('fixed', $valid_types) ? 'checked' : '' ?> onchange="this.form.submit()"> Fixed Price
                            </label>
                            <label class="filter-label">
                                <input type="checkbox" name="type[]" value="hourly" <?= in_array('hourly', $valid_types) ? 'checked' : '' ?> onchange="this.form.submit()"> Hourly Rate
                            </label>
                        </div>
                    </div>

                    <!-- Required Skills -->
                    <div class="filter-section">
                        <h4>Required Skill</h4>
                        <div class="filter-group">
                            <?php foreach ($all_categories as $cat): ?>
                                <label class="filter-label">
                                    <input type="checkbox" name="category[]" value="<?= $cat['id'] ?>" <?= in_array($cat['id'], $valid_categories) ? 'checked' : '' ?> onchange="this.form.submit()">
                                    <span><?= htmlspecialchars($cat['name']) ?></span>
                                    <span class="filter-count-badge"><?= $cat['job_count'] ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Estimated Duration -->
                    <div class="filter-section">
                        <h4>Estimated Duration</h4>
                        <div class="filter-group">
                            <label class="filter-label">
                                <input type="checkbox" name="duration[]" value="less_1w" <?= in_array('less_1w', $valid_durations) ? 'checked' : '' ?> onchange="this.form.submit()"> Less than 1 week
                            </label>
                            <label class="filter-label">
                                <input type="checkbox" name="duration[]" value="1_4w" <?= in_array('1_4w', $valid_durations) ? 'checked' : '' ?> onchange="this.form.submit()"> 1 - 4 weeks
                            </label>
                            <label class="filter-label">
                                <input type="checkbox" name="duration[]" value="1_3m" <?= in_array('1_3m', $valid_durations) ? 'checked' : '' ?> onchange="this.form.submit()"> 1 - 3 months
                            </label>
                            <label class="filter-label">
                                <input type="checkbox" name="duration[]" value="3m_plus" <?= in_array('3m_plus', $valid_durations) ? 'checked' : '' ?> onchange="this.form.submit()"> 3+ months
                            </label>
                        </div>
                    </div>

                    <!-- Budget Range -->
                    <div class="filter-section">
                        <h4>Budget Range ($)</h4>
                        <div class="filter-range-row">
                            <input type="number" name="min_budget" placeholder="Min" class="filter-input" step="10" value="<?= $min_budget > 0 ? htmlspecialchars($min_budget) : '' ?>">
                            <span style="color: var(--text-secondary);">-</span>
                            <input type="number" name="max_budget" placeholder="Max" class="filter-input" step="50" value="<?= $max_budget > 0 ? htmlspecialchars($max_budget) : '' ?>">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-full" style="margin-top: 15px;">Apply Filters</button>
                </form>
            </aside>

            <div class="results-area">
                <div class="results-header">
                    <h2>Open Jobs <span class="results-count" style="font-size: 1rem; font-weight: normal; color: var(--text-secondary);">(<?= $total_jobs ?> found)</span></h2>
                    <div class="results-sort">
                        <span class="sort-label">Sort by:</span>
                        <select onchange="document.getElementById('sortHiddenInput').value = this.value; document.getElementById('jobsFilterForm').submit();">
                            <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
                            <option value="budget_high" <?= $sort === 'budget_high' ? 'selected' : '' ?>>Highest Budget</option>
                            <option value="budget_low" <?= $sort === 'budget_low' ? 'selected' : '' ?>>Lowest Budget</option>
                            <option value="proposals_low" <?= $sort === 'proposals_low' ? 'selected' : '' ?>>Fewest Proposals</option>
                            <option value="proposals_high" <?= $sort === 'proposals_high' ? 'selected' : '' ?>>Most Proposals</option>
                        </select>
                    </div>
                </div>

                <!-- Active Filter Chips -->
                <?php if (!empty($active_filters)): ?>
                    <div class="active-filters-bar">
                        <span class="active-filter-label">Active Filters:</span>
                        <?php foreach ($active_filters as $af): ?>
                            <a href="<?= $af['url'] ?>" class="active-filter-chip">
                                <?= $af['label'] ?> <span class="chip-remove">&times;</span>
                            </a>
                        <?php endforeach; ?>
                        <a href="jobs.php" class="clear-all-link">Clear all</a>
                    </div>
                <?php endif; ?>

                <div class="guest-jobs-list projects-list">
                    <?php if (!empty($jobs)): ?>
                        <?php foreach ($jobs as $job): ?>
                            <div class="project-card">
                                <div class="project-header">
                                    <div>
                                        <h3><a href="job-details.php?id=<?= $job['id'] ?>" class="project-title-link"><?= htmlspecialchars($job['title']) ?></a></h3>
                                        <p class="project-company">
                                            <a href="client-profile.php?id=<?= $job['client_id'] ?>" style="color: var(--text-secondary); text-decoration: none;">
                                                <i data-lucide="building" class="icon-inline" style="width: 14px;"></i> <?= htmlspecialchars($job['company_name']) ?>
                                            </a>
                                        </p>
                                    </div>
                                    <div class="project-price"><?= format_currency($job['budget_max']) ?> <span style="font-size: 0.8rem; font-weight: normal; color: var(--text-secondary);">(<?= ucfirst($job['budget_type']) ?>)</span></div>
                                </div>
                                <p class="project-desc"><?= htmlspecialchars(substr($job['description'], 0, 160)) ?><?= strlen($job['description']) > 160 ? '...' : '' ?></p>
                                <div class="tags" style="display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 15px;">
                                    <?php if ($job['skill_name']): ?>
                                        <a href="jobs.php?category[]=<?= $job['skill_category_id'] ?>" class="tag" style="text-decoration: none;"><?= htmlspecialchars($job['skill_name']) ?></a>
                                    <?php endif; ?>
                                </div>
                                <div class="project-meta">
                                    <span><i data-lucide="file-text" class="icon-inline"></i> <?= $job['proposal_count'] ?> proposal<?= $job['proposal_count'] == 1 ? '' : 's' ?></span>
                                    <span><i data-lucide="clock" class="icon-inline"></i> <?= time_ago($job['created_at']) ?></span>
                                    <span><i data-lucide="calendar" class="icon-inline"></i> <?= format_duration($job['duration']) ?></span>
                                </div>
                                <div class="card-actions">
                                    <a href="job-details.php?id=<?= $job['id'] ?>" class="btn btn-outline btn-full">View Details</a>
                                    <?php if (is_logged_in() && current_user_role() === 'freelancer'): ?>
                                        <a href="../freelancer/apply.php?job_id=<?= $job['id'] ?>" class="btn btn-primary btn-full">Apply Now</a>
                                    <?php elseif (is_logged_in() && current_user_role() === 'client'): ?>
                                        <a href="job-details.php?id=<?= $job['id'] ?>" class="btn btn-primary btn-full">View Job</a>
                                    <?php else: ?>
                                        <a href="../login.php" class="btn btn-primary btn-full">Apply Now</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-results">
                            <i data-lucide="search-x"></i>
                            <h3>No jobs found matching your criteria</h3>
                            <p>Try clearing some filters or searching for different keywords.</p>
                            <a href="jobs.php" class="btn btn-outline">Reset all filters</a>
                        </div>
                    <?php endif; ?>
                </div>

                <?= render_pagination($pag, 'jobs.php') ?>
            </div>
        </div>
    </main>

    <footer>
        <div class="container">
            <p>&copy; 2026 FreeMark. All rights reserved.</p>
        </div>
    </footer>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>