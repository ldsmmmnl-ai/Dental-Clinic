<?php
// API: current "NEW" counts for the sidebar badges (new patients / new appointments).
// Read-only — it never clears a badge. Badges clear when the user opens the matching list page
// (see includes/sidebar.php). The sidebar calls this every 30 seconds so a patient who is
// registered while you are on another page shows up without a refresh.

require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/sidebar_badges.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$uid = (int)($_SESSION['user_id'] ?? 0);
if (!$uid) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$counts = sb_new_counts($conn, $uid);

// Release the session lock right away so this poll never makes another page wait.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

echo json_encode(['status' => 'success', 'counts' => $counts]);
