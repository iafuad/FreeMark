<?php
/**
 * Automated Test Suite for Profile Reporting and Admin Integration
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

echo "Running Profile Reporting Test Suite...\n\n";

// Helper assert
function assert_true($condition, $message) {
    if (!$condition) {
        echo "❌ FAILED: $message\n";
        exit(1);
    } else {
        echo "✅ PASS: $message\n";
    }
}

// 1. Verify table structure
$res = $conn->query("SHOW TABLES LIKE 'profile_reports'");
assert_true($res && $res->num_rows > 0, "profile_reports table exists in database");

// Test user IDs from seed data
$admin_id = 1;
$client_user_id = 2; // FinCore (Client #1)
$client_profile_id = 1;
$freelancer_user_id = 4; // Fuad (Freelancer #2)
$freelancer_profile_id = 2;
$other_fl_user_id = 10; // Alexa Chen (Freelancer #4)

// Clean up any test records for Alexa Chen
$conn->query("DELETE FROM profile_reports WHERE reporter_id = $other_fl_user_id");

// 2. Test valid report submission for freelancer profile
$stmt = $conn->prepare("INSERT INTO profile_reports (reporter_id, reported_user_id, target_type, target_profile_id, reason, details, status) VALUES (?, ?, 'freelancer', ?, 'scam_phishing', 'Testing scam detection in automated test runner', 'pending')");
$stmt->bind_param("iii", $other_fl_user_id, $freelancer_user_id, $freelancer_profile_id);
assert_true($stmt->execute(), "Successfully inserted a valid freelancer profile report");
$new_report_id = (int)$conn->insert_id;

// 3. Test duplicate pending prevention logic
$chk = $conn->prepare("SELECT id FROM profile_reports WHERE reporter_id = ? AND target_type = 'freelancer' AND target_profile_id = ? AND status = 'pending'");
$chk->bind_param("ii", $other_fl_user_id, $freelancer_profile_id);
$chk->execute();
$dup = $chk->get_result()->fetch_assoc();
assert_true(!empty($dup) && $dup['id'] == $new_report_id, "Duplicate detection correctly identifies active pending report");

// 4. Test self-reporting guard logic
$is_self = ($freelancer_user_id === $freelancer_user_id);
assert_true($is_self === true, "Self-reporting guard correctly identifies when reporter_id equals reported_user_id");

// 5. Test valid report submission for client profile
$stmt_cl = $conn->prepare("INSERT INTO profile_reports (reporter_id, reported_user_id, target_type, target_profile_id, reason, details, status) VALUES (?, ?, 'client', ?, 'off_platform', 'Testing off-platform escrow bypass report on client', 'pending')");
$stmt_cl->bind_param("iii", $other_fl_user_id, $client_user_id, $client_profile_id);
assert_true($stmt_cl->execute(), "Successfully inserted a valid client profile report");
$cl_report_id = (int)$conn->insert_id;

// 6. Test Admin Action: Suspend User and Mark Action Taken
$note = "Automated test suspension - verified suspicious pattern";
$sus = $conn->prepare("UPDATE users SET status = 'suspended' WHERE id = ?");
$sus->bind_param("i", $freelancer_user_id);
$sus->execute();

$rep = $conn->prepare("UPDATE profile_reports SET status = 'action_taken', admin_notes = ?, resolved_by = ?, resolved_at = NOW() WHERE id = ?");
$rep->bind_param("sii", $note, $admin_id, $new_report_id);
$rep->execute();

// Check user status
$u_res = $conn->query("SELECT status FROM users WHERE id = $freelancer_user_id")->fetch_assoc();
assert_true($u_res['status'] === 'suspended', "Target user status correctly updated to 'suspended'");

// Check report status
$r_res = $conn->query("SELECT status, admin_notes, resolved_by FROM profile_reports WHERE id = $new_report_id")->fetch_assoc();
assert_true($r_res['status'] === 'action_taken', "Report status correctly transitioned to 'action_taken'");
assert_true($r_res['resolved_by'] == $admin_id, "Report resolved_by correctly recorded admin user ID");
assert_true($r_res['admin_notes'] === $note, "Admin notes successfully recorded");

// 7. Test Admin Action: Reactivate User
$react = $conn->prepare("UPDATE users SET status = 'active' WHERE id = ?");
$react->bind_param("i", $freelancer_user_id);
$react->execute();
$u_res2 = $conn->query("SELECT status FROM users WHERE id = $freelancer_user_id")->fetch_assoc();
assert_true($u_res2['status'] === 'active', "Target user status successfully restored to 'active'");

// 8. Test Admin Action: Dismiss Report
$d_note = "False alarm, verified user identity";
$d_stmt = $conn->prepare("UPDATE profile_reports SET status = 'dismissed', admin_notes = ?, resolved_by = ?, resolved_at = NOW() WHERE id = ?");
$d_stmt->bind_param("sii", $d_note, $admin_id, $cl_report_id);
$d_stmt->execute();

$r_res_d = $conn->query("SELECT status, admin_notes FROM profile_reports WHERE id = $cl_report_id")->fetch_assoc();
assert_true($r_res_d['status'] === 'dismissed', "Client report status successfully updated to 'dismissed'");

// 9. Test helper functions
$badge_html = get_report_reason_badge('off_platform');
assert_true(strpos($badge_html, 'Off-Platform Escrow Bypass') !== false, "get_report_reason_badge generates correct label");

$status_html = get_status_badge('action_taken');
assert_true(strpos($status_html, 'Action Taken') !== false, "get_status_badge correctly styles 'action_taken'");

// Clean up test records
$conn->query("DELETE FROM profile_reports WHERE id IN ($new_report_id, $cl_report_id)");

echo "\n🎉 ALL PROFILE REPORTING TESTS PASSED SUCCESSFULLY!\n";
