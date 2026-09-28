<?php
require_once '../config/db.php';
require_once '../includes/helpers.php';
require_once '../includes/auth.php';

$search = trim($_GET['search'] ?? '');
$selected_categories = isset($_GET['category']) ? (is_array($_GET['category']) ? array_map('intval', $_GET['category']) : [(int)$_GET['category']]) : [];
$verified_only = !empty($_GET['verified_only']);
$min_rate = floatval($_GET['min_rate'] ?? 0);
$max_rate = floatval($_GET['max_rate'] ?? 0);
$min_rating = floatval($_GET['min_rating'] ?? 0);
$sort = $_GET['sort'] ?? 'benchmark';

// Fetch categories for sidebar filter with freelancer counts
$cat_stmt = $conn->query("
    SELECT sc.*,
        (SELECT COUNT(DISTINCT fs.freelancer_id) 
         FROM freelancer_skills fs 
         JOIN freelancer_profiles fp ON fs.freelancer_id = fp.id 
         JOIN users u ON fp.user_id = u.id 
         WHERE fs.skill_id = sc.id AND u.status = 'active') as freelancer_count
    FROM skill_categories sc
    ORDER BY sc.name ASC
");
$all_categories = $cat_stmt ? $cat_stmt->fetch_all(MYSQLI_ASSOC) : [];
$cat_map = array_column($all_categories, 'name', 'id');

// Build query
$where = ["u.status = 'active'"];
$params = [];
$types = "";

if ($search !== '') {
    $where[] = "(u.full_name LIKE ? OR fp.title LIKE ? OR fp.bio LIKE ? OR fp.id IN (SELECT fs.freelancer_id FROM freelancer_skills fs JOIN skill_categories sc ON fs.skill_id = sc.id WHERE sc.name LIKE ?))";
    $s_term = '%' . $search . '%';
    $params[] = $s_term;
    $params[] = $s_term;
    $params[] = $s_term;
    $params[] = $s_term;
    $types .= "ssss";
}

$valid_categories = array_values(array_filter($selected_categories, fn($c) => $c > 0));
if (!empty($valid_categories)) {
    $placeholders = implode(',', array_fill(0, count($valid_categories), '?'));
    $where[] = "fp.id IN (SELECT freelancer_id FROM freelancer_skills WHERE skill_id IN ($placeholders))";
    foreach ($valid_categories as $cid) {
        $params[] = $cid;
        $types .= "i";
    }
}

if ($verified_only) {
    $where[] = "EXISTS (SELECT 1 FROM test_results tr WHERE tr.freelancer_id = fp.id AND tr.passed = 1)";
}

if ($min_rate > 0) {
    $where[] = "fp.hourly_rate >= ?";
    $params[] = $min_rate;
    $types .= "d";
}

if ($max_rate > 0) {
    $where[] = "fp.hourly_rate <= ?";
    $params[] = $max_rate;
    $types .= "d";
}

if ($min_rating > 0) {
    $where[] = "(SELECT COALESCE(AVG(stars), 5.0) FROM reviews WHERE freelancer_id = fp.id) >= ?";
    $params[] = $min_rating;
    $types .= "d";
}

$order_by = match($sort) {
    'rating' => 'avg_rating DESC, rev_count DESC',
    'rate_low' => 'fp.hourly_rate ASC',
    'rate_high' => 'fp.hourly_rate DESC',
    'contracts' => 'completed_contracts DESC',
    default => 'top_quiz_score DESC, avg_rating DESC, fp.id ASC'
};

// Count total freelancers matching filters
$count_sql = "SELECT COUNT(*) FROM freelancer_profiles fp 
              JOIN users u ON fp.user_id = u.id 
              WHERE " . implode(' AND ', $where);
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_freelancers = (int)$count_stmt->get_result()->fetch_row()[0];

// Paginate (6 per page)
$pag = paginate($total_freelancers, 6);

$sql = "SELECT fp.*, u.full_name, u.id as user_id,
    (SELECT AVG(stars) FROM reviews WHERE freelancer_id = fp.id) as avg_rating,
    (SELECT COUNT(*) FROM reviews WHERE freelancer_id = fp.id) as rev_count,
    (SELECT COUNT(*) FROM contracts WHERE freelancer_id = fp.id AND status = 'completed') as completed_contracts,
    (SELECT q.title FROM test_results tr JOIN quizzes q ON tr.quiz_id = q.id WHERE tr.freelancer_id = fp.id AND tr.passed = 1 ORDER BY tr.score DESC LIMIT 1) as top_quiz_title,
    (SELECT tr.score FROM test_results tr WHERE tr.freelancer_id = fp.id AND tr.passed = 1 ORDER BY tr.score DESC LIMIT 1) as top_quiz_score,
    (SELECT tr.max_score FROM test_results tr WHERE tr.freelancer_id = fp.id AND tr.passed = 1 ORDER BY tr.score DESC LIMIT 1) as top_quiz_max
FROM freelancer_profiles fp
JOIN users u ON fp.user_id = u.id
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
$freelancers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch skills for each freelancer
foreach ($freelancers as &$fl) {
    $sk_stmt = $conn->prepare("SELECT sc.id, sc.name FROM freelancer_skills fs JOIN skill_categories sc ON fs.skill_id = sc.id WHERE fs.freelancer_id = ?");
    $sk_stmt->bind_param("i", $fl['id']);
    $sk_stmt->execute();
    $fl['skills'] = $sk_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
unset($fl);

// Active filter labels for chips
$active_filters = [];
if ($search !== '') {
    $active_filters[] = ['label' => 'Keyword: "' . htmlspecialchars($search) . '"', 'url' => build_filter_url('freelancers.php', 'search')];
}
foreach ($valid_categories as $vc) {
    $cname = $cat_map[$vc] ?? ('Category #' . $vc);
    $active_filters[] = ['label' => 'Skill: ' . htmlspecialchars($cname), 'url' => build_filter_url('freelancers.php', 'category', $vc)];
}
if ($verified_only) {
    $active_filters[] = ['label' => 'Verified Only', 'url' => build_filter_url('freelancers.php', 'verified_only')];
}
if ($min_rate > 0 || $max_rate > 0) {
    $r_label = 'Rate: ';
    if ($min_rate > 0 && $max_rate > 0) $r_label .= format_currency($min_rate) . ' - ' . format_currency($max_rate) . '/hr';
    elseif ($min_rate > 0) $r_label .= '≥ ' . format_currency($min_rate) . '/hr';
    else $r_label .= '≤ ' . format_currency($max_rate) . '/hr';
    $active_filters[] = ['label' => $r_label, 'url' => build_filter_url('freelancers.php', 'min_rate', null, ['max_rate'])];
}
if ($min_rating > 0) {
    $active_filters[] = ['label' => 'Rating: ' . $min_rating . '+ ★', 'url' => build_filter_url('freelancers.php', 'min_rating')];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Freelancers - FreeMark</title>
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
                <a href="jobs.php">Find Jobs</a>
                <a href="freelancers.php" class="nav-active">Find Freelancers</a>
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
                <form method="GET" action="freelancers.php" id="freelancerFilterForm">
                    <input type="hidden" name="sort" id="flSortHidden" value="<?= htmlspecialchars($sort) ?>">

                    <div class="filter-header">
                        <h3>Filters</h3>
                        <a href="freelancers.php" style="color: var(--text-secondary); text-decoration: none; font-size: 0.85rem;">Clear all</a>
                    </div>
                    
                    <!-- Search input -->
                    <div class="filter-section">
                        <h4>Search</h4>
                        <div class="search-input-wrapper">
                            <input type="text" name="search" class="filter-input" placeholder="Name, skill, or keyword..." value="<?= htmlspecialchars($search) ?>">
                            <i data-lucide="search" class="search-icon"></i>
                        </div>
                    </div>

                    <!-- Verified Badge Filter -->
                    <div class="filter-section">
                        <h4>Assessment Verification</h4>
                        <div class="filter-group">
                            <label class="filter-label">
                                <input type="checkbox" name="verified_only" value="1" <?= $verified_only ? 'checked' : '' ?> onchange="this.form.submit()">
                                <span>Verified Skills Only</span>
                                <span class="badge badge-success" style="margin-left: auto; font-size: 0.7rem;">✓ Verified</span>
                            </label>
                        </div>
                    </div>

                    <!-- Skill Categories -->
                    <div class="filter-section">
                        <h4>Skill Category</h4>
                        <div class="filter-group">
                            <?php foreach ($all_categories as $cat): ?>
                                <label class="filter-label">
                                    <input type="checkbox" name="category[]" value="<?= $cat['id'] ?>" <?= in_array($cat['id'], $valid_categories) ? 'checked' : '' ?> onchange="this.form.submit()">
                                    <span><?= htmlspecialchars($cat['name']) ?></span>
                                    <span class="filter-count-badge"><?= $cat['freelancer_count'] ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Hourly Rate -->
                    <div class="filter-section">
                        <h4>Hourly Rate ($/hr)</h4>
                        <div class="filter-range-row">
                            <input type="number" name="min_rate" placeholder="Min" class="filter-input" step="5" value="<?= $min_rate > 0 ? htmlspecialchars($min_rate) : '' ?>">
                            <span style="color: var(--text-secondary);">-</span>
                            <input type="number" name="max_rate" placeholder="Max" class="filter-input" step="5" value="<?= $max_rate > 0 ? htmlspecialchars($max_rate) : '' ?>">
                        </div>
                    </div>

                    <!-- Minimum Rating -->
                    <div class="filter-section">
                        <h4>Client Rating</h4>
                        <div class="filter-group">
                            <label class="filter-label">
                                <input type="radio" name="min_rating" value="0" <?= $min_rating <= 0 ? 'checked' : '' ?> onchange="this.form.submit()"> Any Rating
                            </label>
                            <label class="filter-label">
                                <input type="radio" name="min_rating" value="4.5" <?= $min_rating == 4.5 ? 'checked' : '' ?> onchange="this.form.submit()"> 4.5+ Stars ★
                            </label>
                            <label class="filter-label">
                                <input type="radio" name="min_rating" value="4.0" <?= $min_rating == 4.0 ? 'checked' : '' ?> onchange="this.form.submit()"> 4.0+ Stars ★
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-full" style="margin-top: 15px;">Apply Filters</button>
                </form>
            </aside>

            <div class="results-area">
                <div class="results-header">
                    <h2>Available Freelancers <span class="results-count" style="font-size: 1rem; font-weight: normal; color: var(--text-secondary);">(<?= $total_freelancers ?> found)</span></h2>
                    <div class="results-sort">
                        <span class="sort-label">Sort by:</span>
                        <select onchange="document.getElementById('flSortHidden').value = this.value; document.getElementById('freelancerFilterForm').submit();">
                            <option value="benchmark" <?= $sort === 'benchmark' ? 'selected' : '' ?>>Top Benchmark Score</option>
                            <option value="rating" <?= $sort === 'rating' ? 'selected' : '' ?>>Highest Rating</option>
                            <option value="rate_low" <?= $sort === 'rate_low' ? 'selected' : '' ?>>Hourly Rate: Low to High</option>
                            <option value="rate_high" <?= $sort === 'rate_high' ? 'selected' : '' ?>>Hourly Rate: High to Low</option>
                            <option value="contracts" <?= $sort === 'contracts' ? 'selected' : '' ?>>Most Completed Contracts</option>
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
                        <a href="freelancers.php" class="clear-all-link">Clear all</a>
                    </div>
                <?php endif; ?>

                <div class="freelancer-cards-list">
                    <?php if (empty($freelancers)): ?>
                        <div class="empty-results">
                            <i data-lucide="user-x"></i>
                            <h3>No freelancers found matching your criteria</h3>
                            <p>Try broadening your search keywords or clearing some filters.</p>
                            <a href="freelancers.php" class="btn btn-outline">Reset all filters</a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($freelancers as $idx => $fl): ?>
                            <div class="freelancer-list-card">
                                <div class="fl-card-header">
                                    <div class="fl-header-left">
                                        <div class="avatar <?= ($idx % 2 == 1) ? 'purple' : '' ?>">
                                            <?= strtoupper(substr($fl['full_name'], 0, 2)) ?>
                                        </div>
                                        <div class="fl-header-meta">
                                            <div class="fl-name-row">
                                                <h3>
                                                    <a href="freelancer-profile.php?id=<?= $fl['id'] ?>" class="fl-name-link">
                                                        <?= htmlspecialchars($fl['full_name']) ?>
                                                    </a>
                                                </h3>
                                                <?php if ($fl['top_quiz_title']): ?>
                                                    <span class="badge badge-success">
                                                        <i data-lucide="award" class="icon-inline" style="width: 14px;"></i> Verified: <?= htmlspecialchars($fl['top_quiz_title']) ?> (<?= $fl['top_quiz_score'] ?>/<?= $fl['top_quiz_max'] ?>)
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="fl-title-text"><?= htmlspecialchars($fl['title'] ?? 'Freelancer') ?></div>
                                        </div>
                                    </div>
                                    <div class="fl-rate-box">
                                        <div class="fl-rate-amount"><?= format_currency($fl['hourly_rate'] ?? 0) ?></div>
                                        <div class="fl-rate-label">/ hour</div>
                                    </div>
                                </div>

                                <div class="fl-stats-strip">
                                    <div class="fl-stat-item">
                                        <i data-lucide="star" class="icon-inline icon-star"></i>
                                        <strong><?= $fl['avg_rating'] ? number_format((float)$fl['avg_rating'], 1) : '5.0' ?></strong>
                                        <span class="fl-stat-sub">(<?= (int)$fl['rev_count'] ?> review<?= $fl['rev_count'] == 1 ? '' : 's' ?>)</span>
                                    </div>
                                    <div class="fl-stat-divider">•</div>
                                    <div class="fl-stat-item">
                                        <i data-lucide="check-circle" class="icon-inline icon-success"></i>
                                        <span><?= (int)$fl['completed_contracts'] ?> completed job<?= $fl['completed_contracts'] == 1 ? '' : 's' ?></span>
                                    </div>
                                    <div class="fl-stat-divider">•</div>
                                    <div class="fl-stat-item text-success">
                                        <span class="online-indicator"></span> Available for hire
                                    </div>
                                </div>

                                <?php if (!empty($fl['bio'])): ?>
                                    <p class="fl-bio-text">
                                        <?= htmlspecialchars(substr($fl['bio'], 0, 220)) ?><?= strlen($fl['bio']) > 220 ? '...' : '' ?>
                                    </p>
                                <?php endif; ?>

                                <?php if (!empty($fl['skills'])): ?>
                                    <div class="tags" style="margin-bottom: 20px; display: flex; flex-wrap: wrap; gap: 6px;">
                                        <?php foreach ($fl['skills'] as $sk): ?>
                                            <a href="freelancers.php?category[]=<?= $sk['id'] ?>" class="tag" style="text-decoration: none;">
                                                <?= htmlspecialchars($sk['name']) ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="card-actions" style="margin-top: auto; display: flex; gap: 10px;">
                                    <a href="freelancer-profile.php?id=<?= $fl['id'] ?>" class="btn btn-outline <?= !(is_logged_in() && current_user_role() === 'client') ? 'btn-full' : '' ?>">View Profile</a>
                                    <?php if (is_logged_in() && current_user_role() === 'client'): ?>
                                        <a href="../client/hire.php?freelancer_id=<?= $fl['id'] ?>" class="btn btn-primary" style="flex: 1; text-align: center;">Invite to Job</a>
                                        <a href="../client/chat.php?with=<?= $fl['user_id'] ?>" class="btn btn-outline" style="padding: 10px 18px; display: inline-flex; align-items: center; gap: 6px;"><i data-lucide="message-square" style="width: 16px;"></i> Message</a>
                                    <?php elseif (!is_logged_in()): ?>
                                        <a href="../login-client.php" class="btn btn-primary btn-full">Hire Freelancer</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?= render_pagination($pag, 'freelancers.php') ?>
            </div>
        </div>
    </main>

    <footer>
        <div class="container">
            <p>&copy; 2026 FreeMark. All rights reserved.</p>
        </div>
    </footer>
    <script>lucide.createIcons();</script>
</body>
</html>