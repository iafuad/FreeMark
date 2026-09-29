<?php
/**
 * FreeMark - Admin Account Creator CLI Script
 * Usage: php database/create_admin.php [email] [password] [full_name]
 */

require_once __DIR__ . '/../config/db.php';

$is_cli = (php_sapi_name() === 'cli');

$email = $argv[1] ?? null;
$password = $argv[2] ?? null;
$full_name = $argv[3] ?? null;

if (!$email) {
    if ($is_cli) {
        echo "========================================================\n";
        echo "           FreeMark Admin Account Creator               \n";
        echo "========================================================\n\n";

        echo "Enter Admin Email [admin@freemark.com]: ";
        $input = trim(fgets(STDIN));
        $email = $input !== '' ? $input : 'admin@freemark.com';

        echo "Enter Admin Password [admin123]: ";
        $input = trim(fgets(STDIN));
        $password = $input !== '' ? $input : 'admin123';

        echo "Enter Admin Full Name [System Administrator]: ";
        $input = trim(fgets(STDIN));
        $full_name = $input !== '' ? $input : 'System Administrator';
    } else {
        die("Please run this script from the command line: php database/create_admin.php [email] [password] [full_name]");
    }
} else {
    $password = $password ?? 'admin123';
    $full_name = $full_name ?? 'System Administrator';
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    die("Error: Invalid email format: '{$email}'\n");
}

if (strlen($password) < 6) {
    die("Error: Password must be at least 6 characters.\n");
}

$hash = password_hash($password, PASSWORD_DEFAULT);

// Check if user already exists
$stmt = $conn->prepare("SELECT id, role, status FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();

if ($existing) {
    // Update existing user to admin with active status and new password
    $upd = $conn->prepare("UPDATE users SET password_hash = ?, full_name = ?, role = 'admin', status = 'active' WHERE id = ?");
    $upd->bind_param("ssi", $hash, $full_name, $existing['id']);
    $upd->execute();
    echo "\n[SUCCESS] Existing user '{$email}' updated to active Admin with new credentials.\n";
} else {
    // Insert new admin user
    $ins = $conn->prepare("INSERT INTO users (email, password_hash, full_name, role, status) VALUES (?, ?, ?, 'admin', 'active')");
    $ins->bind_param("sss", $email, $hash, $full_name);
    $ins->execute();
    echo "\n[SUCCESS] New Admin account created successfully!\n";
}

echo "========================================================\n";
echo " Email:    {$email}\n";
echo " Password: {$password}\n";
echo " Role:     admin\n";
echo " Status:   active\n";
echo " Login at: http://localhost/FreeMark/login-admin.php\n";
echo "========================================================\n\n";
