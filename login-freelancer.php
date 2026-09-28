<?php
require_once 'config/db.php';
require_once 'includes/auth.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? AND role = 'freelancer'");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if ($user && password_verify($password, $user['password_hash'])) {
        if ($user['status'] !== 'active') {
            $error = 'Your account is pending approval or suspended.';
        } else {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['full_name'] = $user['full_name'];
            header('Location: freelancer/dashboard.php');
            exit;
        }
    } else {
        $error = 'Invalid email or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Freelancer Login - FreeMark</title>
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
                <a href="login.php" class="btn btn-outline">Back to Roles</a>
            </div>
        </div>
    </header>

    <main class="auth-container">
        <div class="auth-card">
            <h2 style="display: flex; align-items: center; justify-content: center; gap: 10px;">
                <i data-lucide="user" style="color: var(--accent);"></i> Freelancer Login
            </h2>
            <p>Log in to find work and manage contracts</p>

            <?php if ($error): ?>
                <div style="color: red; margin-bottom: 15px; text-align: center;"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form action="" method="POST">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" placeholder="you@example.com" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="••••••••" required>
                </div>
                <button type="submit" class="btn btn-primary">Log In</button>
            </form>

            <div class="auth-links">
                <p>Don't have a freelancer account? <a href="register-freelancer.php">Sign up</a></p>
            </div>
        </div>
    </main>
    <script>lucide.createIcons();</script>
</body>
</html>
