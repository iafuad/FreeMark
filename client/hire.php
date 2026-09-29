<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';
require_role('client');

$client_id = get_profile_id($conn, current_user_id(), 'client');
$freelancer_id = (int)($_GET['freelancer_id'] ?? 0);

if ($freelancer_id <= 0) {
    header("Location: freelancers.php");
    exit;
}

// Fetch freelancer info
$stmt = $conn->prepare("SELECT fp.*, u.full_name, u.email,
    (SELECT AVG(stars) FROM reviews WHERE freelancer_id = fp.id) as avg_rating,
    (SELECT COUNT(*) FROM test_results WHERE freelancer_id = fp.id AND passed = 1) as test_count
    FROM freelancer_profiles fp
    JOIN users u ON fp.user_id = u.id
    WHERE fp.id = ?");
$stmt->bind_param("i", $freelancer_id);
$stmt->execute();
$freelancer = $stmt->get_result()->fetch_assoc();

if (!$freelancer) {
    header("Location: freelancers.php");
    exit;
}

// Fetch client's open projects
$p_stmt = $conn->prepare("SELECT id, title, budget_max, budget_type FROM projects WHERE client_id = ? AND status = 'open' ORDER BY created_at DESC");
$p_stmt->bind_param("i", $client_id);
$p_stmt->execute();
$client_projects = $p_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$error = '';
$success = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $project_choice = $_POST['project_id'] ?? '0';
    $offer_amount = floatval($_POST['offer_amount'] ?? 0);
    $offer_title = trim($_POST['offer_title'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (empty($message)) {
        $error = "Please include a message for the freelancer.";
    } else {
        $selected_project_id = null;
        if ($project_choice !== 'new' && (int)$project_choice > 0) {
            $selected_project_id = (int)$project_choice;
        } else {
            // Create a project for this direct contract
            $title = !empty($offer_title) ? $offer_title : ("Direct Engagement with " . $freelancer['full_name']);
            $ins_proj = $conn->prepare("INSERT INTO projects (client_id, title, description, budget_max, status) VALUES (?, ?, ?, ?, 'open')");
            $ins_proj->bind_param("issd", $client_id, $title, $message, $offer_amount);
            $ins_proj->execute();
            $selected_project_id = $conn->insert_id;
        }

        // Insert invitation
        $inv_stmt = $conn->prepare("INSERT INTO job_invitations (client_id, freelancer_id, project_id, message, status) VALUES (?, ?, ?, ?, 'pending')");
        $inv_stmt->bind_param("iiis", $client_id, $freelancer_id, $selected_project_id, $message);
        if ($inv_stmt->execute()) {
            $success = "Offer sent successfully to " . htmlspecialchars($freelancer['full_name']) . "! They will receive a notification on their dashboard.";
        } else {
            $error = "Failed to send offer. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hire Freelancer - FreeMark</title>
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
                <li><a href="projects.php"><i data-lucide="briefcase"></i> My Projects</a></li>
                <li><a href="post-project.php"><i data-lucide="plus-circle"></i> Post Project</a></li>
                <li><a href="freelancers.php" class="active"><i data-lucide="users"></i> Freelancers</a></li>
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
                <h2>Hire Freelancer</h2>
                <p>Send an offer or invite to <?= htmlspecialchars($freelancer['full_name']) ?></p>
            </div>
            <a href="freelancers.php" class="btn btn-outline">Back to Freelancers</a>
        </header>

        <div class="client-content">
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

            <div class="client-split-layout">
                <!-- Hire Form -->
                <div class="card client-split-main">
                    <div class="card-header">
                        <h3>Contract Offer Details</h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="hire.php?freelancer_id=<?= $freelancer_id ?>">
                            <div class="form-group">
                                <label>Related Job Posting</label>
                                <select name="project_id" id="project_select" onchange="toggleDirectFields(this.value)">
                                    <option value="new">-- Create a new direct project offer --</option>
                                    <?php foreach ($client_projects as $cp): ?>
                                        <option value="<?= $cp['id'] ?>"><?= htmlspecialchars($cp['title']) ?> (<?= format_currency($cp['budget_max']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div id="direct_fields">
                                <div class="form-group" style="margin-top: 15px;">
                                    <label>Project Title</label>
                                    <input type="text" name="offer_title" placeholder="e.g. Full Stack MVP Development">
                                </div>
                                <div class="form-group" style="margin-top: 15px;">
                                    <label>Offer Amount ($)</label>
                                    <input type="number" name="offer_amount" placeholder="e.g. 2500" step="0.01">
                                </div>
                            </div>
                            
                            <div class="form-group" style="margin-top: 15px;">
                                <label>Message to Freelancer</label>
                                <textarea name="message" rows="4" placeholder="Describe the terms of the offer, expected milestones, or project goals..." required>Hi <?= htmlspecialchars($freelancer['full_name']) ?>, I reviewed your profile and skill test credentials. We'd love to invite you to collaborate on our project. Please review this offer!</textarea>
                            </div>
                            
                            <div class="form-actions-divider" style="margin-top: 25px;">
                                <button type="submit" class="btn btn-primary btn-block">Send Offer</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Freelancer Snapshot -->
                <div class="card client-split-sidebar">
                    <div class="card-header">
                        <h3>Freelancer Snapshot</h3>
                    </div>
                    <div class="card-body profile-snapshot-body">
                        <div class="profile-avatar-lg profile-avatar-blue">
                            <?= strtoupper(substr($freelancer['full_name'], 0, 2)) ?>
                        </div>
                        <h4 class="profile-name"><?= htmlspecialchars($freelancer['full_name']) ?></h4>
                        <p class="profile-role"><?= htmlspecialchars($freelancer['title'] ?? 'Freelancer') ?></p>
                        
                        <div class="profile-stats">
                            <div>
                                <div class="profile-stat-value"><?= $freelancer['avg_rating'] ? number_format((float)$freelancer['avg_rating'], 1) : '5.0' ?>★</div>
                                <div class="profile-stat-label">Rating</div>
                            </div>
                            <div>
                                <div class="profile-stat-value"><?= format_currency($freelancer['hourly_rate'] ?? 0) ?>/hr</div>
                                <div class="profile-stat-label">Rate</div>
                            </div>
                        </div>

                        <div style="margin-top: 20px;">
                            <a href="chat.php?with=<?= $freelancer['user_id'] ?>" class="btn btn-outline" style="width: 100%; text-align: center; display: block; text-decoration: none;">
                                Send Message
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        lucide.createIcons();
        function toggleDirectFields(val) {
            const fields = document.getElementById('direct_fields');
            if (val === 'new') {
                fields.style.display = 'block';
            } else {
                fields.style.display = 'none';
            }
        }
    </script>
</body>
</html>
