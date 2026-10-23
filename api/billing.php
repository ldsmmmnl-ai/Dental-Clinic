<?php
// API: get completed appointments for a patient (used in billing form).

require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');


$action = $_GET['action'] ?? '';

// Get appointments for a patient to link to a bill (pending/confirmed/completed are all billable)
if ($action === 'get_appointments') {
    $patient_id = intval($_GET['patient_id'] ?? 0);
    if (!$patient_id) {
        echo json_encode(['appointments' => []]);
        exit();
    }

    $stmt = $conn->prepare("
        SELECT a.id, a.appointment_code, a.appointment_date, a.status, COALESCE((SELECT GROUP_CONCAT(ms_x.service_name ORDER BY ms_x.id SEPARATOR ', ') FROM `appointment_services` ms_x WHERE ms_x.`appointment_id` = a.id), s.service_name) AS service_name, s.price
        FROM appointments a
        LEFT JOIN services s ON a.service_id = s.id
        WHERE a.patient_id = ?
        AND a.status IN ('pending','confirmed','completed')
        ORDER BY a.appointment_date DESC
        LIMIT 20
    ");
    
    if (!$stmt->execute([$patient_id])) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Query failed.']);
        exit();
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = null;

    // Cast price to float — MySQLi returns DECIMAL columns as strings
    foreach ($rows as &$row) {
        $row['price'] = $row['price'] !== null ? floatval($row['price']) : null;
    }
    unset($row);

    echo json_encode(['appointments' => $rows]);
    exit();
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
?>