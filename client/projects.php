<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';
require_role('client');

$user_id = current_user_id();
$client_id = get_profile_id($conn, $user_id, 'client');

$flash_success = '';
$flash_error = '';

// Handle project Close and Reopen actions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';
    $target_project_id = intval($_POST['project_id'] ?? 0);

    if ($target_project_id > 0) {
        if ($action === 'close_project') {
            $close_stmt = $conn->prepare("UPDATE projects SET status = 'closed' WHERE id = ? AND client_id = ? AND status = 'open'");
            $close_stmt->bind_param("ii", $target_project_id, $client_id);
            if ($close_stmt->execute() && $close_stmt->affected_rows > 0) {
                $flash_success = 'Project listing has been closed and unlisted from public view.';
            } else {
                $flash_error = 'Unable to close project. Only open projects you created can be closed.';
            }
        } elseif ($action === 'reopen_project') {
            $reopen_stmt = $conn->prepare("UPDATE projects SET status = 'open' WHERE id = ? AND client_id = ? AND status = 'closed'");
            $reopen_stmt->bind_param("ii", $target_project_id, $client_id);
            if ($reopen_stmt->execute() && $reopen_stmt->affected_rows > 0) {
                $flash_success = 'Project listing has been reopened and is now live to the public.';
            } else {
                $flash_error = 'Unable to reopen project. Only closed projects you created can be reopened.';
            }
        }
    }
}

// Filter parameters
$status_filter = strtolower(trim($_GET['status'] ?? 'all'));
$allowed_statuses = ['all', 'open', 'in_progress', 'completed', 'closed'];
if (!in_array($status_filter, $allowed_statuses)) {
    $status_filter = 'all';
}

$search_query = trim($_GET['q'] ?? '');

// Compute counts per status for this client
$counts_stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_all,
        SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as total_open,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as total_in_progress,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as total_completed,
        SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as total_closed
    FROM projects
    WHERE client_id = ?
");
$counts_stmt->bind_param("i", $client_id);
$counts_stmt->execute();
$counts = $counts_stmt->get_result()->fetch_assoc();

$total_all = (int)($counts['total_all'] ?? 0);
$total_open = (int)($counts['total_open'] ?? 0);
$total_in_progress = (int)($counts['total_in_progress'] ?? 0);
$total_completed = (int)($counts['total_completed'] ?? 0);
$total_closed = (int)($counts['total_closed'] ?? 0);

// Build WHERE conditions for filtered list
$where_clauses = ["p.client_id = ?"];
$params = [$client_id];
$types = "i";

if ($status_filter !== 'all') {
    $where_clauses[] = "p.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($search_query !== '') {
    $where_clauses[] = "(p.title LIKE ? OR p.description LIKE ?)";
    $like_param = '%' . $search_query . '%';
    $params[] = $like_param;
    $params[] = $like_param;
    $types .= "ss";
}

$where_sql = implode(" AND ", $where_clauses);

// Count total filtered projects
$count_query = "SELECT COUNT(*) FROM projects p WHERE $where_sql";
$count_stmt = $conn->prepare($count_query);
$count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$filtered_total = (int)$count_stmt->get_result()->fetch_row()[0];

// Pagination (8 items per page)
$pag = paginate($filtered_total, 8);

// Query filtered projects
$query = "
    SELECT p.*,
           sc.name as category_name,
           (SELECT COUNT(*) FROM proposals WHERE project_id = p.id) as proposal_count,
           (SELECT COUNT(*) FROM contracts WHERE project_id = p.id AND status = 'active') as active_contracts_count
    FROM projects p
    LEFT JOIN skill_categories sc ON p.skill_category_id = sc.id
    WHERE $where_sql
    ORDER BY p.created_at DESC
    LIMIT ? OFFSET ?
";

$fetch_params = $params;
$fetch_params[] = $pag['per_page'];
$fetch_params[] = $pag['offset'];
$fetch_types = $types . "ii";

$stmt = $conn->prepare($query);
$stmt->bind_param($fetch_types, ...$fetch_params);
$stmt->execute();
$projects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Projects - FreeMark</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/client/layout.css">
    <link rel="stylesheet" href="../css/client/components.css">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        .projects-filter-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            padding: 16px 20px;
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border-color);
        }
        .status-pills {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.82rem;
            color: var(--text-secondary);
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .status-pill:hover {
            color: var(--text-primary);
            border-color: var(--text-secondary);
        }
        .status-pill.active {
            background: rgba(99, 102, 241, 0.15);
            color: #818cf8;
            border-color: #818cf8;
            font-weight: 600;
        }
        .status-pill .pill-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 1px 6px;
            border-radius: 10px;
            font-size: 0.75rem;
            background: var(--bg-secondary);
            color: inherit;
        }
        .search-box {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .search-box input {
            padding: 6px 12px;
            font-size: 0.85rem;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            background: var(--bg-primary);
            color: var(--text-primary);
            min-width: 200px;
        }
        .project-card-item {
            padding: 22px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            gap: 10px;
            transition: background-color 0.15s ease;
        }
        .project-card-item:hover {
            background-color: rgba(255, 255, 255, 0.015);
        }
        .project-card-item:last-child {
            border-bottom: none;
        }
        .project-item-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 15px;
            flex-wrap: wrap;
        }
        .project-item-title {
            margin: 0;
            font-size: 1.15rem;
            font-weight: 600;
        }
        .project-item-title a {
            color: var(--text-primary);
            text-decoration: none;
        }
        .project-item-title a:hover {
            color: var(--accent);
        }
        .project-price-badge {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--accent);
            text-align: right;
            white-space: nowrap;
        }
        .project-price-type {
            font-size: 0.8rem;
            font-weight: normal;
            color: var(--text-secondary);
        }
        .project-meta-row {
            display: flex;
            align-items: center;
            gap: 18px;
            flex-wrap: wrap;
            font-size: 0.85rem;
            color: var(--text-secondary);
        }
        .project-meta-item {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .project-desc-snippet {
            font-size: 0.88rem;
            color: var(--text-secondary);
            line-height: 1.5;
            margin: 2px 0 6px 0;
        }
        .project-actions-row {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 4px;
            padding-top: 14px;
            border-top: 1px solid var(--border-color);
        }
        .project-modal-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        .project-modal-box {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            width: 90%;
            max-width: 480px;
            padding: 24px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5);
            animation: modalPop 0.2s ease-out;
        }
        @keyframes modalPop {
            from { transform: scale(0.95); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
    </style>
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
                <h2>My Projects</h2>
                <p>Manage your posted project listings, track incoming proposals, and make updates</p>
            </div>
            <div class="header-actions">
                <a href="post-project.php" class="btn btn-primary">
                    <i data-lucide="plus-circle"></i> Post a Project
                </a>
            </div>
        </header>

        <div class="client-content">
            <?php if ($flash_success): ?>
                <div class="alert alert-success" style="background: rgba(34, 197, 94, 0.15); border: 1px solid rgba(34, 197, 94, 0.3); color: #22c55e; padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                    <i data-lucide="check-circle" style="width: 18px; height: 18px; flex-shrink: 0;"></i>
                    <span><?= htmlspecialchars($flash_success) ?></span>
                </div>
            <?php endif; ?>
            <?php if ($flash_error): ?>
                <div class="alert alert-danger" style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444; padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                    <i data-lucide="alert-circle" style="width: 18px; height: 18px; flex-shrink: 0;"></i>
                    <span><?= htmlspecialchars($flash_error) ?></span>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="projects-filter-bar">
                    <div class="status-pills">
                        <a href="projects.php?status=all<?= $search_query !== '' ? '&q=' . urlencode($search_query) : '' ?>" class="status-pill <?= $status_filter === 'all' ? 'active' : '' ?>">
                            All <span class="pill-count"><?= $total_all ?></span>
                        </a>
                        <a href="projects.php?status=open<?= $search_query !== '' ? '&q=' . urlencode($search_query) : '' ?>" class="status-pill <?= $status_filter === 'open' ? 'active' : '' ?>">
                            Open <span class="pill-count"><?= $total_open ?></span>
                        </a>
                        <a href="projects.php?status=in_progress<?= $search_query !== '' ? '&q=' . urlencode($search_query) : '' ?>" class="status-pill <?= $status_filter === 'in_progress' ? 'active' : '' ?>">
                            In Progress <span class="pill-count"><?= $total_in_progress ?></span>
                        </a>
                        <a href="projects.php?status=completed<?= $search_query !== '' ? '&q=' . urlencode($search_query) : '' ?>" class="status-pill <?= $status_filter === 'completed' ? 'active' : '' ?>">
                            Completed <span class="pill-count"><?= $total_completed ?></span>
                        </a>
                        <a href="projects.php?status=closed<?= $search_query !== '' ? '&q=' . urlencode($search_query) : '' ?>" class="status-pill <?= $status_filter === 'closed' ? 'active' : '' ?>">
                            Closed <span class="pill-count"><?= $total_closed ?></span>
                        </a>
                    </div>
                    <form method="GET" action="projects.php" class="search-box">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
                        <input type="text" name="q" placeholder="Search by title or keyword..." value="<?= htmlspecialchars($search_query) ?>">
                        <button type="submit" class="btn btn-outline btn-compact">
                            <i data-lucide="search" class="icon-xs"></i> Search
                        </button>
                        <?php if ($search_query !== ''): ?>
                            <a href="projects.php?status=<?= htmlspecialchars($status_filter) ?>" class="btn btn-compact btn-outline" title="Clear Search">
                                <i data-lucide="x" class="icon-xs"></i>
                            </a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="card-body card-body-flush">
                    <?php if (empty($projects)): ?>
                        <div style="padding: 50px 20px; text-align: center; color: var(--text-secondary);">
                            <i data-lucide="folder-search" style="width: 44px; height: 44px; color: var(--text-secondary); margin-bottom: 12px; opacity: 0.6;"></i>
                            <h4 style="margin: 0 0 6px 0; color: var(--text-primary); font-size: 1.05rem;">No projects found</h4>
                            <?php if ($search_query !== '' || $status_filter !== 'all'): ?>
                                <p style="margin: 0 0 16px 0; font-size: 0.88rem;">No projects match your current filter or search criteria.</p>
                                <a href="projects.php" class="btn btn-outline btn-compact">Clear Filters</a>
                            <?php else: ?>
                                <p style="margin: 0 0 16px 0; font-size: 0.88rem;">You haven't posted any projects yet. Get started by creating your first listing!</p>
                                <a href="post-project.php" class="btn btn-primary btn-compact"><i data-lucide="plus-circle"></i> Post Your First Project</a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <?php foreach ($projects as $p): ?>
                            <div class="project-card-item">
                                <div class="project-item-header">
                                    <div>
                                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px; flex-wrap: wrap;">
                                            <h3 class="project-item-title">
                                                <a href="../guest/job-details.php?id=<?= $p['id'] ?>">
                                                    <?= htmlspecialchars($p['title']) ?>
                                                </a>
                                            </h3>
                                            <?= get_status_badge($p['status']) ?>
                                            <?php if ($p['active_contracts_count'] > 0): ?>
                                                <span class="status-badge primary" title="This project has an active contract in progress">
                                                    <i data-lucide="shield-check" class="icon-xs"></i> Active Contract
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="project-meta-row">
                                            <?php if (!empty($p['category_name'])): ?>
                                                <span class="project-meta-item">
                                                    <i data-lucide="tag" class="icon-xs"></i> <?= htmlspecialchars($p['category_name']) ?>
                                                </span>
                                            <?php endif; ?>
                                            <span class="project-meta-item">
                                                <i data-lucide="clock" class="icon-xs"></i> <?= format_duration($p['duration']) ?>
                                            </span>
                                            <span class="project-meta-item">
                                                <i data-lucide="calendar" class="icon-xs"></i> Posted <?= time_ago($p['created_at']) ?>
                                            </span>
                                            <span class="project-meta-item">
                                                <i data-lucide="file-text" class="icon-xs"></i> <strong><?= (int)$p['proposal_count'] ?></strong> Proposal<?= $p['proposal_count'] == 1 ? '' : 's' ?>
                                            </span>
                                        </div>
                                    </div>

                                    <div class="project-price-badge">
                                        <?= format_currency($p['budget_max']) ?>
                                        <span class="project-price-type">(<?= ucfirst($p['budget_type']) ?>)</span>
                                    </div>
                                </div>

                                <?php if (!empty($p['description'])): ?>
                                    <p class="project-desc-snippet">
                                        <?= htmlspecialchars(mb_strimwidth($p['description'], 0, 160, '...')) ?>
                                    </p>
                                <?php endif; ?>

                                <div class="project-actions-row">
                                    <a href="edit-project.php?id=<?= $p['id'] ?>" class="btn btn-outline btn-compact" title="Edit listing specifications, budget, or status">
                                        <i data-lucide="edit-3"></i> Edit Listing
                                    </a>
                                    <a href="proposals.php?project_id=<?= $p['id'] ?>" class="btn btn-outline btn-compact" title="Review proposals submitted for this project">
                                        <i data-lucide="users"></i> Proposals (<?= (int)$p['proposal_count'] ?>)
                                    </a>
                                    <?php if ($p['status'] === 'open'): ?>
                                        <button type="button" class="btn btn-outline btn-compact" style="color: #ef4444; border-color: rgba(239, 68, 68, 0.4);" onclick="openConfirmModal('close', <?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['title'])) ?>')" title="Close this project and unlist from public view">
                                            <i data-lucide="x-circle"></i> Close
                                        </button>
                                    <?php elseif ($p['status'] === 'closed'): ?>
                                        <button type="button" class="btn btn-outline btn-compact" style="color: #22c55e; border-color: rgba(34, 197, 94, 0.4);" onclick="openConfirmModal('reopen', <?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['title'])) ?>')" title="Reopen this project and relist publicly">
                                            <i data-lucide="rotate-ccw"></i> Reopen
                                        </button>
                                    <?php endif; ?>
                                    <a href="../guest/job-details.php?id=<?= $p['id'] ?>" target="_blank" class="btn btn-outline btn-compact" title="View public job posting">
                                        <i data-lucide="external-link"></i> Public View
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?= render_pagination($pag, 'projects.php') ?>
        </div>
    </main>

    <!-- Confirmation Modal for Close/Reopen Project -->
    <div id="projectActionModal" class="project-modal-backdrop" style="display: none;">
        <div class="project-modal-box">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div id="modalIconBox" style="width: 38px; height: 38px; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                        <i id="modalIcon" data-lucide="help-circle" style="width: 20px; height: 20px;"></i>
                    </div>
                    <h3 id="modalTitle" style="margin: 0; font-size: 1.15rem; color: var(--text-primary);">Confirm Action</h3>
                </div>
                <button type="button" onclick="closeConfirmModal()" style="background: none; border: none; color: var(--text-secondary); cursor: pointer; padding: 4px;">
                    <i data-lucide="x" style="width: 18px; height: 18px;"></i>
                </button>
            </div>
            
            <div style="margin-bottom: 18px;">
                <p id="modalDesc" style="color: var(--text-secondary); font-size: 0.9rem; line-height: 1.5; margin: 0 0 10px 0;"></p>
                <div style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 6px; padding: 10px 14px;">
                    <strong style="color: var(--text-primary); font-size: 0.92rem;" id="modalProjectName"></strong>
                </div>
            </div>

            <form method="POST" action="" id="modalActionForm">
                <input type="hidden" name="action" id="modalActionInput" value="">
                <input type="hidden" name="project_id" id="modalProjectIdInput" value="">
                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn btn-outline btn-compact" onclick="closeConfirmModal()">Cancel</button>
                    <button type="submit" id="modalConfirmBtn" class="btn btn-compact">Confirm</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        lucide.createIcons();

        function openConfirmModal(type, projectId, projectTitle) {
            const modal = document.getElementById('projectActionModal');
            const modalTitle = document.getElementById('modalTitle');
            const modalDesc = document.getElementById('modalDesc');
            const modalProjectName = document.getElementById('modalProjectName');
            const actionInput = document.getElementById('modalActionInput');
            const projectIdInput = document.getElementById('modalProjectIdInput');
            const confirmBtn = document.getElementById('modalConfirmBtn');
            const iconBox = document.getElementById('modalIconBox');
            const icon = document.getElementById('modalIcon');

            projectIdInput.value = projectId;
            modalProjectName.textContent = projectTitle;

            if (type === 'close') {
                actionInput.value = 'close_project';
                modalTitle.textContent = 'Close Project Listing';
                modalDesc.textContent = 'Are you sure you want to close this project? It will be unlisted from public browse and search, and freelancers will no longer be able to submit new proposals.';
                confirmBtn.textContent = 'Confirm & Close Project';
                confirmBtn.className = 'btn btn-compact';
                confirmBtn.style.background = '#ef4444';
                confirmBtn.style.color = '#ffffff';
                confirmBtn.style.borderColor = '#ef4444';
                iconBox.style.background = 'rgba(239, 68, 68, 0.15)';
                iconBox.style.color = '#ef4444';
                icon.setAttribute('data-lucide', 'x-circle');
            } else if (type === 'reopen') {
                actionInput.value = 'reopen_project';
                modalTitle.textContent = 'Reopen Project Listing';
                modalDesc.textContent = 'Reopen this project? It will be relisted publicly on the job board and freelancers will be able to submit proposals once again.';
                confirmBtn.textContent = 'Confirm & Reopen Project';
                confirmBtn.className = 'btn btn-compact';
                confirmBtn.style.background = '#22c55e';
                confirmBtn.style.color = '#0f172a';
                confirmBtn.style.borderColor = '#22c55e';
                iconBox.style.background = 'rgba(34, 197, 94, 0.15)';
                iconBox.style.color = '#22c55e';
                icon.setAttribute('data-lucide', 'rotate-ccw');
            }

            modal.style.display = 'flex';
            lucide.createIcons();
        }

        function closeConfirmModal() {
            document.getElementById('projectActionModal').style.display = 'none';
        }

        window.addEventListener('click', function(e) {
            const modal = document.getElementById('projectActionModal');
            if (e.target === modal) {
                closeConfirmModal();
            }
        });
    </script>
</body>
</html>
