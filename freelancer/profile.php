<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

require_role('freelancer');

$user_id = current_user_id();
$profile_id = get_profile_id($conn, $user_id, 'freelancer');

// If profile doesn't exist, create it
if (!$profile_id) {
    $stmt = $conn->prepare("INSERT INTO freelancer_profiles (user_id, title, hourly_rate) VALUES (?, 'Freelancer', 30.00)");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $profile_id = $conn->insert_id;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    $hourly_rate = floatval($_POST['hourly_rate'] ?? 0);
    $github_link = trim($_POST['github_link'] ?? '');
    $portfolio_link = trim($_POST['portfolio_link'] ?? '');

    $conn->begin_transaction();
    try {
        // Update user full_name if provided
        if (!empty($full_name)) {
            $u_stmt = $conn->prepare("UPDATE users SET full_name = ? WHERE id = ?");
            $u_stmt->bind_param("si", $full_name, $user_id);
            $u_stmt->execute();
            $_SESSION['full_name'] = $full_name;
        }

        // Update profile
        $p_stmt = $conn->prepare("UPDATE freelancer_profiles SET title = ?, bio = ?, hourly_rate = ?, github_link = ?, portfolio_link = ? WHERE id = ?");
        $p_stmt->bind_param("ssdssi", $title, $bio, $hourly_rate, $github_link, $portfolio_link, $profile_id);
        $p_stmt->execute();

        // Update skills
        $del_skills = $conn->prepare("DELETE FROM freelancer_skills WHERE freelancer_id = ?");
        $del_skills->bind_param("i", $profile_id);
        $del_skills->execute();

        if (isset($_POST['skills']) && is_array($_POST['skills'])) {
            $ins_skill = $conn->prepare("INSERT INTO freelancer_skills (freelancer_id, skill_id) VALUES (?, ?)");
            foreach ($_POST['skills'] as $skill_id) {
                $s_id = (int)$skill_id;
                if ($s_id > 0) {
                    $ins_skill->bind_param("ii", $profile_id, $s_id);
                    $ins_skill->execute();
                }
            }
        }

        $conn->commit();
        $message = "Profile updated successfully! Changes are live on your public profile.";
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Error updating profile: " . $e->getMessage();
    }
}

// Fetch user data
$u_stmt = $conn->prepare("SELECT full_name, email FROM users WHERE id = ?");
$u_stmt->bind_param("i", $user_id);
$u_stmt->execute();
$user_data = $u_stmt->get_result()->fetch_assoc();
$full_name = $user_data['full_name'] ?? '';

// Fetch profile data
$p_stmt = $conn->prepare("SELECT * FROM freelancer_profiles WHERE id = ?");
$p_stmt->bind_param("i", $profile_id);
$p_stmt->execute();
$profile = $p_stmt->get_result()->fetch_assoc() ?? [];

$title = $profile['title'] ?? '';
$bio = $profile['bio'] ?? '';
$hourly_rate = $profile['hourly_rate'] ?? 0;
$github_link = $profile['github_link'] ?? '';
$portfolio_link = $profile['portfolio_link'] ?? '';

// Fetch all available skill categories
$cat_res = $conn->query("SELECT * FROM skill_categories ORDER BY name ASC");
$all_categories = $cat_res ? $cat_res->fetch_all(MYSQLI_ASSOC) : [];

// Fetch freelancer's current skills
$sk_stmt = $conn->prepare("SELECT skill_id FROM freelancer_skills WHERE freelancer_id = ?");
$sk_stmt->bind_param("i", $profile_id);
$sk_stmt->execute();
$sk_res = $sk_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$current_skill_ids = array_column($sk_res, 'skill_id');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - FreeMark</title>
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
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>
                </svg>
                FreeMark
            </a>
        </div>
        <nav class="sidebar-nav">
            <ul>
                <li><a href="dashboard.php"><i data-lucide="layout-dashboard"></i> Overview</a></li>
                <li><a href="profile.php" class="active"><i data-lucide="user"></i> My Profile</a></li>
                <li><a href="jobs.php"><i data-lucide="briefcase"></i> Find Jobs</a></li>
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
        <form method="POST" action="profile.php">
            <header class="freelancer-header">
                <div class="header-title">
                    <h2>Edit Freelancer Profile</h2>
                    <p>Manage your professional credentials, hourly rate, and portfolio visibility.</p>
                </div>
                <div style="display: flex; gap: 12px; align-items: center;">
                    <a href="../guest/freelancer-profile.php?id=<?= $profile_id ?>" target="_blank" class="btn btn-outline" style="display: inline-flex; align-items: center; gap: 6px;">
                        <i data-lucide="external-link" class="icon-sm"></i> Preview Public Profile
                    </a>
                    <button type="submit" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px;">
                        <i data-lucide="check" class="icon-sm"></i> Save Changes
                    </button>
                </div>
            </header>

            <div class="freelancer-content">
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

                <!-- Profile Overview Strip -->
                <div class="card" style="padding: 22px 26px; margin-bottom: 25px; display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap; background: linear-gradient(135deg, rgba(255,255,255,0.02), rgba(250,204,21,0.03));">
                    <div style="display: flex; align-items: center; gap: 18px;">
                        <div style="width: 60px; height: 60px; border-radius: 50%; background: linear-gradient(135deg, #3b82f6, #1d4ed8); display: flex; align-items: center; justify-content: center; font-size: 1.5rem; font-weight: 700; color: white;">
                            <?= strtoupper(substr($full_name ?: 'FL', 0, 2)) ?>
                        </div>
                        <div>
                            <h3 style="margin: 0 0 4px 0; font-size: 1.2rem;"><?= htmlspecialchars($full_name ?: 'Freelancer Profile') ?></h3>
                            <div style="font-size: 0.88rem; color: var(--text-secondary); display: flex; gap: 10px; align-items: center;">
                                <span><?= htmlspecialchars($title ?: 'Professional Freelancer') ?></span>
                                <span>•</span>
                                <span style="color: var(--accent); font-weight: 600;"><?= format_currency($hourly_rate) ?>/hr</span>
                            </div>
                        </div>
                    </div>
                    <div>
                        <a href="../guest/freelancer-profile.php?id=<?= $profile_id ?>" target="_blank" class="btn btn-outline btn-sm">
                            <i data-lucide="eye" class="icon-sm"></i> View Public Profile
                        </a>
                    </div>
                </div>

                <!-- Basic Information Card -->
                <div class="card">
                    <div class="card-header">
                        <h3><i data-lucide="user" class="icon-base" style="margin-right: 6px;"></i> Basic Information</h3>
                    </div>
                    <div class="card-body">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Full Name</label>
                                <input type="text" name="full_name" value="<?= htmlspecialchars($full_name); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Professional Title</label>
                                <input type="text" name="title" value="<?= htmlspecialchars($title); ?>" placeholder="e.g. Senior Full Stack Developer" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Hourly Rate ($ USD / hour)</label>
                                <input type="number" name="hourly_rate" step="0.01" min="0" value="<?= htmlspecialchars($hourly_rate); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Account Email (Managed by FreeMark)</label>
                                <input type="text" value="<?= htmlspecialchars($user_data['email'] ?? ''); ?>" disabled style="opacity: 0.65; cursor: not-allowed;">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Professional Bio / Summary</label>
                            <textarea name="bio" rows="5" placeholder="Highlight your expertise, previous projects, architecture patterns, and domain experience..."><?= htmlspecialchars($bio); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Portfolio & Links Card -->
                <div class="card">
                    <div class="card-header">
                        <h3><i data-lucide="link" class="icon-base" style="margin-right: 6px;"></i> Portfolio & External Links</h3>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <label>GitHub Profile URL</label>
                            <input type="url" name="github_link" placeholder="https://github.com/yourusername" value="<?= htmlspecialchars($github_link); ?>">
                        </div>
                        <div class="form-group">
                            <label>Personal Portfolio Website</label>
                            <input type="url" name="portfolio_link" placeholder="https://yourportfolio.com" value="<?= htmlspecialchars($portfolio_link); ?>">
                        </div>
                    </div>
                </div>

                <!-- Skill Categories Card -->
                <div class="card">
                    <div class="card-header">
                        <h3><i data-lucide="tag" class="icon-base" style="margin-right: 6px;"></i> Skill Categories</h3>
                    </div>
                    <div class="card-body">
                        <p class="text-secondary mb-15">Select the skill domains that best represent your services. These match client job recommendations.</p>
                        <div class="skill-tags">
                            <?php foreach ($all_categories as $cat): ?>
                                <?php $is_selected = in_array($cat['id'], $current_skill_ids); ?>
                                <label class="skill-tag <?= $is_selected ? 'selected' : ''; ?>" style="cursor: pointer;">
                                    <input type="checkbox" name="skills[]" value="<?= $cat['id']; ?>" class="d-none" <?= $is_selected ? 'checked' : ''; ?>> 
                                    <span class="tag-text"><?= $is_selected ? '✓ ' : ''; ?><?= htmlspecialchars($cat['name']); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Bottom Action Bar -->
                <div class="card" style="padding: 20px 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-top: 10px;">
                    <span style="font-size: 0.9rem; color: var(--text-secondary);">
                        Remember to save changes to update your proposals and search rankings.
                    </span>
                    <div style="display: flex; gap: 12px;">
                        <a href="dashboard.php" class="btn btn-outline">Cancel</a>
                        <button type="submit" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px;">
                            <i data-lucide="check" class="icon-sm"></i> Save Profile Changes
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </main>

    <script>
        lucide.createIcons();
        
        document.querySelectorAll('.skill-tag input').forEach(input => {
            input.addEventListener('change', function() {
                const parent = this.closest('.skill-tag');
                const span = parent.querySelector('.tag-text');
                const name = span.textContent.replace('✓ ', '').trim();
                if (this.checked) {
                    parent.classList.add('selected');
                    span.textContent = '✓ ' + name;
                } else {
                    parent.classList.remove('selected');
                    span.textContent = name;
                }
            });
        });
    </script>
</body>
</html>
