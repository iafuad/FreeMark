<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function require_login() {
    if (!is_logged_in()) {
        header('Location: /FreeMark/login.php');
        exit;
    }
}

function require_role($role) {
    require_login();
    if ($_SESSION['role'] !== $role) {
        http_response_code(403);
        die('Access denied. You do not have the required role.');
    }
}

function current_user_id() {
    return $_SESSION['user_id'] ?? null;
}

function current_user_role() {
    return $_SESSION['role'] ?? null;
}

function current_user_name() {
    return $_SESSION['full_name'] ?? null;
}

function get_profile_id($conn, $user_id, $role) {
    if (!$user_id) return null;
    if ($role === 'client') {
        $stmt = $conn->prepare("SELECT id FROM client_profiles WHERE user_id = ?");
    } else if ($role === 'freelancer') {
        $stmt = $conn->prepare("SELECT id FROM freelancer_profiles WHERE user_id = ?");
    } else {
        return null;
    }
    
    if (!$stmt) return null;
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    if ($res && isset($res['id'])) {
        return (int)$res['id'];
    }

    // Auto-create missing profile row
    if ($role === 'client') {
        $company = current_user_name() ?: 'Client';
        $ins = $conn->prepare("INSERT INTO client_profiles (user_id, company_name) VALUES (?, ?)");
        $ins->bind_param("is", $user_id, $company);
        if ($ins->execute()) {
            return (int)$conn->insert_id;
        }
    } else if ($role === 'freelancer') {
        $title = 'Freelancer';
        $ins = $conn->prepare("INSERT INTO freelancer_profiles (user_id, title) VALUES (?, ?)");
        $ins->bind_param("is", $user_id, $title);
        if ($ins->execute()) {
            return (int)$conn->insert_id;
        }
    }

    return null;
}
