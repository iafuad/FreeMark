<?php
require_once 'config/db.php';
require_once 'includes/auth.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $company = trim($_POST['company'] ?? '');
    $hiring_vol = $_POST['hiring_vol'] ?? '1-10';
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $error = 'Email is already registered.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $role = 'client';
        $status = 'pending'; 

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO users (email, password_hash, full_name, role, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $email, $hash, $name, $role, $status);
            $stmt->execute();
            $user_id = $conn->insert_id;

            $stmt = $conn->prepare("INSERT INTO client_profiles (user_id, company_name, hiring_volume) VALUES (?, ?, ?)");
            // Map values nicely for the enum '1-10', '10-50', '50+'
            if ($hiring_vol == '1') $h_vol = '1-10';
            else if ($hiring_vol == '5') $h_vol = '10-50';
            else $h_vol = '50+';

            $stmt->bind_param("iss", $user_id, $company, $h_vol);
            $stmt->execute();

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
    <title>Sign Up as Client - FreeMark</title>
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
            <h2>Sign Up as a Client</h2>
            <p>Find and hire the best freelance talent</p>

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
                        <label for="company">Company Name</label>
                        <input type="text" id="company" name="company" placeholder="e.g. Acme Corp (Optional)">
                    </div>
                    <div class="form-group">
                        <label for="hiring-vol">Hiring Needs</label>
                        <select id="hiring-vol" name="hiring_vol">
                            <option value="1">1-2 freelancers</option>
                            <option value="5">3-10 freelancers</option>
                            <option value="10+">10+ freelancers</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="email">Work Email Address</label>
                    <input type="email" id="email" name="email" placeholder="you@company.com" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Create a secure password" required>
                </div>
                
                <button type="submit" class="btn btn-primary">Create Client Account</button>
            </form>
            <?php endif; ?>

            <div class="auth-links">
                <p>Already have an account? <a href="login-client.php">Log in</a></p>
            </div>
        </div>
    </main>
    <script>lucide.createIcons();</script>
</body>
</html>
