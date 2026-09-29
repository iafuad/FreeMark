<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

require_role('client');

$user_id = current_user_id();
$client_id = get_profile_id($conn, $user_id, 'client');

// If client profile doesn't exist, create it
if (!$client_id) {
    $stmt = $conn->prepare("INSERT INTO client_profiles (user_id, company_name, hiring_volume) VALUES (?, 'My Company', '1-10')");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $client_id = $conn->insert_id;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $company_name = trim($_POST['company_name'] ?? '');
    $hiring_volume = trim($_POST['hiring_volume'] ?? '1-10');
    $bio = trim($_POST['bio'] ?? '');

    // Validate hiring volume
    $allowed_volumes = ['1-10', '10-50', '50+'];
    if (!in_array($hiring_volume, $allowed_volumes)) {
        $hiring_volume = '1-10';
    }

    $conn->begin_transaction();
    try {
        // Update user full_name if provided
        if (!empty($full_name)) {
            $u_stmt = $conn->prepare("UPDATE users SET full_name = ? WHERE id = ?");
            $u_stmt->bind_param("si", $full_name, $user_id);
            $u_stmt->execute();
            $_SESSION['full_name'] = $full_name;
        }

        // Update client profile
        $cp_stmt = $conn->prepare("UPDATE client_profiles SET company_name = ?, hiring_volume = ?, bio = ? WHERE id = ?");
        $cp_stmt->bind_param("sssi", $company_name, $hiring_volume, $bio, $client_id);
        $cp_stmt->execute();

        $conn->commit();
        $message = "Company profile updated successfully! Changes are live on your public employer page.";
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Error updating company profile: " . $e->getMessage();
    }
}

// Fetch user data
$u_stmt = $conn->prepare("SELECT full_name, email FROM users WHERE id = ?");
$u_stmt->bind_param("i", $user_id);
$u_stmt->execute();
$user_data = $u_stmt->get_result()->fetch_assoc();
$full_name = $user_data['full_name'] ?? '';

// Fetch client profile data
$cp_stmt = $conn->prepare("SELECT * FROM client_profiles WHERE id = ?");
$cp_stmt->bind_param("i", $client_id);
$cp_stmt->execute();
$client_profile = $cp_stmt->get_result()->fetch_assoc() ?? [];

$company_name = $client_profile['company_name'] ?? '';
$hiring_volume = $client_profile['hiring_volume'] ?? '1-10';
$bio = $client_profile['bio'] ?? '';

// Fetch employer metrics
$stat_stmt = $conn->prepare("SELECT 
    (SELECT COUNT(*) FROM projects WHERE client_id = ?) as total_jobs,
    (SELECT COUNT(*) FROM contracts WHERE client_id = ?) as total_hires,
    (SELECT COALESCE(SUM(paid_to_date), 0) FROM contracts WHERE client_id = ?) as total_spent");
$stat_stmt->bind_param("iii", $client_id, $client_id, $client_id);
$stat_stmt->execute();
$client_stats = $stat_stmt->get_result()->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Profile - FreeMark</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/client/layout.css">
    <link rel="stylesheet" href="../css/client/components.css">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
    <aside class="client-sidebar">
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
                <li><a href="profile.php" class="active"><i data-lucide="building"></i> Company Profile</a></li>
                <li><a href="projects.php"><i data-lucide="briefcase"></i> My Projects</a></li>
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
        <form method="POST" action="profile.php">
            <header class="client-header">
                <div class="header-title">
                    <h2>Company Profile</h2>
                    <p>Manage your employer branding, hiring volume, and public company description.</p>
                </div>
                <div style="display: flex; gap: 12px; align-items: center;">
                    <a href="../guest/client-profile.php?id=<?= $client_id ?>" target="_blank" class="btn btn-outline" style="display: inline-flex; align-items: center; gap: 6px;">
                        <i data-lucide="external-link" class="icon-sm"></i> Preview Public Profile
                    </a>
                    <button type="submit" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px;">
                        <i data-lucide="check" class="icon-sm"></i> Save Changes
                    </button>
                </div>
            </header>

            <div class="client-content">
                <?php if ($message): ?>
                    <div class="alert alert-success">
                        <i data-lucide="check-circle-2" class="icon-base"></i>
                        <span><?= htmlspecialchars($message) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i data-lucide="alert-circle" class="icon-base"></i>
                        <span><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>

                <!-- Company Overview Header Strip -->
                <div class="card" style="padding: 24px 28px; margin-bottom: 25px; display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap; background: linear-gradient(135deg, rgba(255,255,255,0.02), rgba(168,85,247,0.03));">
                    <div style="display: flex; align-items: center; gap: 18px;">
                        <div style="width: 64px; height: 64px; border-radius: 12px; background: linear-gradient(135deg, #a855f7, #7e22ce); display: flex; align-items: center; justify-content: center; font-size: 1.6rem; font-weight: 700; color: white;">
                            <?= strtoupper(substr($company_name ?: ($full_name ?: 'CP'), 0, 2)) ?>
                        </div>
                        <div>
                            <h3 style="margin: 0 0 4px 0; font-size: 1.25rem;"><?= htmlspecialchars($company_name ?: 'Your Company Name') ?></h3>
                            <div style="font-size: 0.88rem; color: var(--text-secondary); display: flex; gap: 12px; align-items: center;">
                                <span>Contact: <strong><?= htmlspecialchars($full_name) ?></strong></span>
                                <span>•</span>
                                <span>Volume: <strong><?= htmlspecialchars($hiring_volume) ?> hires</strong></span>
                            </div>
                        </div>
                    </div>
                    <div>
                        <a href="../guest/client-profile.php?id=<?= $client_id ?>" target="_blank" class="btn btn-outline btn-sm">
                            <i data-lucide="eye" class="icon-sm"></i> View Public Profile
                        </a>
                    </div>
                </div>

                <!-- Company Details Card -->
                <div class="card">
                    <div class="card-header">
                        <h3><i data-lucide="building-2" class="icon-base" style="margin-right: 6px;"></i> Company Information</h3>
                    </div>
                    <div class="card-body">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Company / Organization Name</label>
                                <input type="text" name="company_name" value="<?= htmlspecialchars($company_name); ?>" placeholder="e.g. Acme Corporation" required>
                            </div>
                            <div class="form-group">
                                <label>Primary Contact Person Name</label>
                                <input type="text" name="full_name" value="<?= htmlspecialchars($full_name); ?>" placeholder="e.g. Jane Doe" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Target Hiring Volume</label>
                                <select name="hiring_volume">
                                    <option value="1-10" <?= $hiring_volume === '1-10' ? 'selected' : '' ?>>1 - 10 Hires (Small Team / Startup)</option>
                                    <option value="10-50" <?= $hiring_volume === '10-50' ? 'selected' : '' ?>>10 - 50 Hires (Mid-Market / Growth)</option>
                                    <option value="50+" <?= $hiring_volume === '50+' ? 'selected' : '' ?>>50+ Hires (Enterprise / High Volume)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Account Email (Managed by FreeMark)</label>
                                <input type="text" value="<?= htmlspecialchars($user_data['email'] ?? ''); ?>" disabled style="opacity: 0.65; cursor: not-allowed;">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Company Overview / Bio</label>
                            <textarea name="bio" rows="5" placeholder="Describe your company mission, industry focus, and what makes working with your team rewarding for top freelancers..."><?= htmlspecialchars($bio); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Platform Activity Snapshot Card -->
                <div class="card">
                    <div class="card-header">
                        <h3><i data-lucide="bar-chart-2" class="icon-base" style="margin-right: 6px;"></i> Platform Activity Snapshot</h3>
                    </div>
                    <div class="card-body">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                            <div style="padding: 16px; background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 8px; text-align: center;">
                                <div style="font-size: 1.4rem; font-weight: 700; color: var(--text-primary); margin-bottom: 2px;">
                                    <?= (int)$client_stats['total_jobs'] ?>
                                </div>
                                <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-secondary);">Jobs Posted</div>
                            </div>

                            <div style="padding: 16px; background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 8px; text-align: center;">
                                <div style="font-size: 1.4rem; font-weight: 700; color: var(--text-primary); margin-bottom: 2px;">
                                    <?= (int)$client_stats['total_hires'] ?>
                                </div>
                                <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-secondary);">Contracts Created</div>
                            </div>

                            <div style="padding: 16px; background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 8px; text-align: center;">
                                <div style="font-size: 1.4rem; font-weight: 700; color: var(--accent); margin-bottom: 2px;">
                                    <?= format_currency($client_stats['total_spent']) ?>
                                </div>
                                <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-secondary);">Total Paid to Freelancers</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bottom Action Bar -->
                <div class="card" style="padding: 20px 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-top: 10px;">
                    <span style="font-size: 0.9rem; color: var(--text-secondary);">
                        Updates to your company profile are immediately visible on your job listings and public employer page.
                    </span>
                    <div style="display: flex; gap: 12px;">
                        <a href="dashboard.php" class="btn btn-outline">Cancel</a>
                        <button type="submit" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px;">
                            <i data-lucide="check" class="icon-sm"></i> Save Company Profile
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </main>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>
