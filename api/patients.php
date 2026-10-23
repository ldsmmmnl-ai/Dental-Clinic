<?php
// API: get appointments for a patient (used in treatment and billing forms).

require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');


$action = $_GET['action'] ?? '';

// Get appointments for a patient (used in treatment + payment forms)
if ($action === 'get_appointments') {
    $patient_id = intval($_GET['patient_id'] ?? 0);
    if (!$patient_id) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Invalid patient ID']);
        exit();
    }

    $stmt = $conn->prepare("
        SELECT a.id, a.appointment_code, a.service_id, a.status,
            DATE_FORMAT(a.appointment_date, '%M %d, %Y') as appointment_date,
            COALESCE((SELECT GROUP_CONCAT(ms_x.service_name ORDER BY ms_x.id SEPARATOR ', ') FROM `appointment_services` ms_x WHERE ms_x.`appointment_id` = a.id), s.service_name) AS service_name, s.price
        FROM appointments a
        LEFT JOIN services s ON a.service_id = s.id
        WHERE a.patient_id = ?
        AND a.status IN ('pending', 'confirmed', 'completed')
        ORDER BY a.appointment_date DESC
        LIMIT 30
    ");
    if (!$stmt->execute([$patient_id])) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Query failed.']);
        exit();
    }
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    unset($stmt);

    // Load tooth conditions for color coding
    $tooth_conditions = [];
    $tc_stmt = $conn->prepare("
        SELECT tooth_number, tooth_status FROM dental_records
        WHERE patient_id = ?
        AND tooth_number != '' AND tooth_number IS NOT NULL
        ORDER BY visit_date DESC
    ");
    if ($tc_stmt->execute([$patient_id])) {
        while ($tr = $tc_stmt->fetch(PDO::FETCH_ASSOC)) {
            foreach (array_map('trim', explode(',', $tr['tooth_number'])) as $t) {
                if ($t && !isset($tooth_conditions[$t])) {
                    $tooth_conditions[$t] = $tr['tooth_status'];
                }
            }
        }
    }
    unset($tc_stmt);

    // Cast price to float — MySQLi returns DECIMAL columns as strings
    foreach ($appointments as &$appt) {
        $appt['price'] = $appt['price'] !== null ? floatval($appt['price']) : null;
    }
    unset($appt);

    echo json_encode(['status' => 'success', 'appointments' => $appointments, 'tooth_conditions' => $tooth_conditions]);
    exit();
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
?>