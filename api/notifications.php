<?php
// API: mark notifications as read (individually or all at once).
// Unread count for the badge is computed directly in includes/header.php,
// not through this file.

require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

$current_user_id = $_SESSION['user_id'] ?? 0;
if (!$current_user_id) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? ($_GET['action'] ?? '');

// Security token check. Both actions below change data, so both need it.
// (The shared validate_csrf() only reads normal form posts, and this API receives JSON,
//  so the token is read from the JSON body — same way api/appointments.php does it.)
if ($action === 'mark_read' || $action === 'mark_all_read') {
    $submitted = $body['_csrf'] ?? '';
    $expected  = $_SESSION['csrf_token'] ?? '';
    if (!is_string($submitted) || $submitted === '' || $expected === '' || !hash_equals($expected, $submitted)) {
        error_log('[CSRF] Token mismatch on api/notifications.php action=' . $action . ' from IP: ' . get_client_ip());
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Invalid request token. Please refresh and try again.']);
        exit();
    }
}

// Mark a notification as read
if ($action === 'mark_read') {
    $id = intval($body['id'] ?? 0);
    if ($id) {
        // Scope update to the current user's own notifications (or broadcast ones).
        // Without this, any logged-in user could mark any other user's notification as read.
        $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE id = ? AND (user_id = ? OR user_id IS NULL)");
        $stmt->execute([$id, $current_user_id]);
        $stmt = null;
        // Bust the session cache so the next page load reflects the updated count
        $cache_key = 'notif_cache_' . $current_user_id;
        unset($_SESSION[$cache_key . '_time'], $_SESSION[$cache_key . '_count'], $_SESSION[$cache_key . '_recent']);
        echo json_encode(['status' => 'success']);
    } else {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Invalid ID']);
    }
    exit();
}

// Mark all as read for current user
if ($action === 'mark_all_read') {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = ? OR user_id IS NULL");
    $stmt->execute([$current_user_id]);
    $stmt = null;
    // Bust the session cache so the next page load reflects the updated count
    $cache_key = 'notif_cache_' . $current_user_id;
    unset($_SESSION[$cache_key . '_time'], $_SESSION[$cache_key . '_count'], $_SESSION[$cache_key . '_recent']);
    echo json_encode(['status' => 'success']);
    exit();
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
?>