<?php
require_once 'config/db.php';
require_once 'includes/auth.php';

$error = '';
$success = '';

$categories = $conn->query("SELECT * FROM skill_categories ORDER BY name");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $primary_skill = intval($_POST['primary_skill'] ?? 0);
    $rate = floatval($_POST['rate'] ?? 0);
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $error = 'Email is already registered.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $role = 'freelancer';
        $status = 'pending';

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO users (email, password_hash, full_name, role, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $email, $hash, $name, $role, $status);
            $stmt->execute();
            $user_id = $conn->insert_id;

            $stmt = $conn->prepare("INSERT INTO freelancer_profiles (user_id, hourly_rate, title) VALUES (?, ?, 'New Freelancer')");
            $stmt->bind_param("id", $user_id, $rate);
            $stmt->execute();
            $profile_id = $conn->insert_id;

            if ($primary_skill > 0) {
                $stmt = $conn->prepare("INSERT INTO freelancer_skills (freelancer_id, skill_id) VALUES (?, ?)");
                $stmt->bind_param("ii", $profile_id, $primary_skill);
                $stmt->execute();
            }

            $conn->commit();
            $success = 'Account created! Please wait for admin approval.';
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Registration failed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up as Freelancer - FreeMark</title>
    <link rel="stylesheet" href="css/style.css">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
    <header>
        <div class="container navbar">
            <a href="index.php" class="logo">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>
                </svg>
                FreeMark
            </a>
            <div class="nav-actions">
                <a href="register.php" class="btn btn-outline">Back to Roles</a>
            </div>
        </div>
    </header>

    <main class="auth-container">
        <div class="auth-card">
            <h2>Sign Up as a Freelancer</h2>
            <p>Build your profile and start getting hired</p>

            <?php if ($error): ?>
                <div style="color: red; margin-bottom: 15px; text-align: center;"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div style="color: green; margin-bottom: 15px; text-align: center;"><?= htmlspecialchars($success) ?></div>
            <?php else: ?>

            <form action="" method="POST">
                <div class="form-group">
                    <label for="name">Your Full Name</label>
                    <input type="text" id="name" name="name" placeholder="John Doe" required>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="skills">Primary Skill</label>
                        <select id="skills" name="primary_skill" required>
                            <option value="">Select your expertise...</option>
                            <?php while ($cat = $categories->fetch_assoc()): ?>
                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="rate">Expected Hourly Rate ($)</label>
                        <input type="number" id="rate" name="rate" placeholder="e.g. 50" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" placeholder="you@example.com" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Create a secure password" required>
                </div>
                
                <button type="submit" class="btn btn-primary">Create Freelancer Account</button>
            </form>
            <?php endif; ?>

            <div class="auth-links">
                <p>Already have an account? <a href="login-freelancer.php">Log in</a></p>
            </div>
        </div>
    </main>
    <script>lucide.createIcons();</script>
</body>
</html>
