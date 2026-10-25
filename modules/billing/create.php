<?php
// Create a new bill for a patient, optionally linked to an appointment.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

// Ensure current user variables are available for bill creation and logging.
$current_user_id   = $current_user_id   ?? $_SESSION['user_id']   ?? null;
$current_user_name = $current_user_name ?? $_SESSION['full_name'] ?? 'System';

$page_title = 'Create Bill';
$error      = '';

// Pre-fill from appointment if passed
$pre_patient_id     = intval($_GET['patient_id'] ?? 0);
$pre_appt_id        = intval($_GET['appointment_id'] ?? 0);
$pre_from_treatment = isset($_GET['from_treatment']);
$pre_record_id      = intval($_GET['record_id'] ?? 0);   // dental record just saved (walk-in with no appointment)

$patients = sc_get('pat_bill') ?? sc_set('pat_bill', $conn->query(
    "SELECT id, patient_code, first_name, last_name FROM patients WHERE is_active = TRUE ORDER BY last_name ASC"
)->fetchAll(PDO::FETCH_ASSOC), 120);

$services = sc_get('svc_bill') ?? sc_set('svc_bill', $conn->query(
    "SELECT id, service_name, price FROM services WHERE is_active = TRUE ORDER BY service_name ASC"
)->fetchAll(PDO::FETCH_ASSOC), 300);

// Pre-load patient and service from appointment when coming from treatment flow
$pre_patient_info  = null;
$pre_service_info  = null;
$pre_service_id    = 0;
$pre_service_price = 0;
$pre_service_ids   = [];
if ($pre_patient_id) {
    $pp_stmt = $conn->prepare("SELECT id, patient_code, first_name, last_name FROM patients WHERE id = ? LIMIT 1");
    $pp_stmt->execute([$pre_patient_id]);
    $pre_patient_info = $pp_stmt->fetch(PDO::FETCH_ASSOC);
    $pp_stmt->closeCursor();
}
if ($pre_appt_id) {
    $ap_stmt = $conn->prepare("
        SELECT a.id, a.appointment_code, s.id as service_id, s.service_name, s.price
        FROM appointments a
        LEFT JOIN services s ON a.service_id = s.id
        WHERE a.id = ? LIMIT 1
    ");
    $ap_stmt->execute([$pre_appt_id]);
    $pre_service_info = $ap_stmt->fetch(PDO::FETCH_ASSOC);
    $ap_stmt->closeCursor();
    if ($pre_service_info) {
        $pre_service_id    = intval($pre_service_info['service_id'] ?? 0);
        $pre_service_price = floatval($pre_service_info['price'] ?? 0);
        // The appointment may have MORE THAN ONE service — pre-tick all of them.
        $pre_rows = ms_get($conn, 'appointment_services', 'appointment_id', $pre_appt_id, $pre_service_id ?: null);
        $pre_service_ids = array_values(array_filter(array_map(fn($r) => (int)$r['service_id'], $pre_rows)));
        if ($pre_rows) $pre_service_price = array_sum(array_map(fn($r) => (float)$r['price'], $pre_rows));
    }
}

// Coming from a saved dental record with no appointment services → pre-tick that record's services
if (!$pre_service_ids && $pre_record_id) {
    $rec_rows = ms_get($conn, 'dental_record_services', 'dental_record_id', $pre_record_id, null);
    $pre_service_ids = array_values(array_filter(array_map(fn($r) => (int)$r['service_id'], $rec_rows)));
}

// Get appointments for patient (populated via JS when not in treatment flow)
$appointments = [];
if ($pre_patient_id && !$pre_from_treatment) {
    $appts_stmt = $conn->prepare("
        SELECT a.id, a.appointment_code, a.appointment_date, a.status, s.service_name, s.id as service_id, s.price
        FROM appointments a
        LEFT JOIN services s ON a.service_id = s.id
        WHERE a.patient_id = ?
        AND a.status IN ('pending','confirmed','completed')
        ORDER BY a.appointment_date DESC
    ");
    $appts_stmt->execute([$pre_patient_id]);
    $appointments = $appts_stmt->fetchAll(PDO::FETCH_ASSOC);
    $appts_stmt->closeCursor();
}

// Old values for repopulating the form on validation error
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    $patient_id     = intval($_POST['patient_id'] ?? 0);
    $appointment_id = intval($_POST['appointment_id'] ?? 0) ?: null;
    // One or MANY services (check-boxes). service_id keeps the FIRST one for reports/old pages.
    $service_ids    = array_map(fn($r) => (int)$r['id'], ms_fetch_services($conn, ms_post_ids()));
    $service_id     = $service_ids[0] ?? null;
    $amount_due     = round(floatval($_POST['amount_due']  ?? 0), 2);
    // The total bill is TYPED IN by the clinic (services are names only — no list prices).
    $amount_paid    = round(floatval($_POST['amount_paid'] ?? 0), 2);
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $notes          = trim($_POST['notes'] ?? '');
    $gcash_ref      = trim($_POST['gcash_ref'] ?? '');
    $bank_ref       = trim($_POST['bank_ref']  ?? '');
    // Date the patient paid (defaults to today; no future dates)
    $pay_date       = trim($_POST['pay_date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pay_date) || $pay_date > date('Y-m-d')) $pay_date = date('Y-m-d');

    // Save for form repopulation on error
    $old = [
        'patient_id'     => $patient_id,
        'appointment_id' => $appointment_id,
        'service_id'     => $service_id,
        'service_ids'    => $service_ids,
        'amount_due'     => $amount_due,
        'amount_paid'    => $amount_paid,
        'payment_method' => $payment_method,
        'notes'          => $notes,
        'gcash_ref'      => $gcash_ref,
        'bank_ref'       => $bank_ref,
        'pay_date'       => $pay_date,
    ];

    // Whitelist payment method
    $allowed_methods = ['cash', 'gcash', 'bank', 'other'];
    if (!in_array($payment_method, $allowed_methods)) $payment_method = 'cash';

    // Determine status
    if ($amount_paid <= 0)               $status = 'unpaid';
    elseif ($amount_paid >= $amount_due) $status = 'paid';
    else                                 $status = 'partial';

    if (!$patient_id) {
        $error = 'Please select a patient.';
    } elseif ($amount_due <= 0) {
        $error = 'Amount due must be greater than zero.';
    } elseif ($amount_paid < 0) {
        $error = 'Amount paid cannot be negative.';
    } elseif ($amount_paid > $amount_due) {
        $error = 'Amount paid (₱' . number_format($amount_paid, 2) . ') cannot exceed the amount due (₱' . number_format($amount_due, 2) . ').';
    } elseif (strlen($notes) > 500) {
        $error = 'Notes must be 500 characters or fewer.';
    } else {
        // ── Auto-link: no appointment chosen → attach the bill to this patient's latest ──
        // treatment that has no bill yet (same service if one was picked), so the
        // Appointment → Billing → Patient Record chain never breaks. If nothing fits,
        // the bill is saved unlinked exactly as before.
        $auto_dr_id = null;
        if (!$appointment_id) {
            try {
                $al_sql = "SELECT dr.id, dr.appointment_id
                           FROM dental_records dr
                           WHERE dr.patient_id = ?
                             AND dr.visit_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
                        . ($service_ids ? " AND dr.service_id IN (" . implode(',', array_fill(0, count($service_ids), '?')) . ")" : "") . "
                             AND NOT EXISTS (
                                 SELECT 1 FROM bills b
                                 WHERE b.patient_id = dr.patient_id
                                   AND (b.dental_record_id = dr.id
                                        OR (dr.appointment_id IS NOT NULL AND b.appointment_id = dr.appointment_id))
                             )
                           ORDER BY dr.visit_date DESC, dr.id DESC
                           LIMIT 1";
                $al_stmt = $conn->prepare($al_sql);
                $al_stmt->execute($service_ids ? array_merge([$patient_id], $service_ids) : [$patient_id]);
                $al_row = $al_stmt->fetch(PDO::FETCH_ASSOC);
                $al_stmt->closeCursor();
                if ($al_row) {
                    $auto_dr_id     = (int)$al_row['id'];
                    $appointment_id = !empty($al_row['appointment_id']) ? (int)$al_row['appointment_id'] : null;
                }
            } catch (Exception $e) {
                error_log('[BILL AUTO-LINK] ' . $e->getMessage());
                $auto_dr_id = null;
            }
        }
        // ─────────────────────────────────────────────────────────────────────

        // ── Duplicate guard: if this appointment already has a bill, go there ──
        // This prevents duplicate bills when the user presses browser back (Alt+Left)
        // after a successful save and the form re-submits.
        if ($appointment_id) {
            $dup_chk = $conn->prepare("SELECT id FROM bills WHERE appointment_id = ? LIMIT 1");
            $dup_chk->execute([$appointment_id]);
            $existing_bill_id = $dup_chk->fetchColumn();
            $dup_chk->closeCursor();
            if ($existing_bill_id) {
                // Already billed — just redirect to the existing bill silently
                $suffix = $pre_from_treatment ? '&flow=done' : '';
                header('Location: view.php?id=' . $existing_bill_id . $suffix);
                exit();
            }
        }
        // ─────────────────────────────────────────────────────────────────────

        $bill_code   = generate_code($conn, 'bills', 'BILL');
        $payment_ref = $gcash_ref ?: $bank_ref ?: '';

        try {
            $stmt = $conn->prepare("
                INSERT INTO bills
                (bill_code, patient_id, appointment_id, service_id, amount_due, amount_paid,
                 payment_method, payment_ref, status, notes, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([
                $bill_code, $patient_id, $appointment_id, $service_id,
                $amount_due, $amount_paid,
                $payment_method, $payment_ref, $status, $notes, $current_user_id
            ]);

            if ($stmt->rowCount() < 1) {
                $error = 'Failed to save the bill. Please check all fields and try again.';
                $stmt->closeCursor();
            } else {
                $new_id = $conn->lastInsertId();
                $stmt->closeCursor();

                // Save every ticked service on this bill (name + price snapshot)
                if ($service_ids) ms_save($conn, 'bill_services', 'bill_id', (int)$new_id, $service_ids);

                // Payment history: the first payment (down payment / full payment), with its date
                if ($amount_paid > 0) {
                    ms_log_payment($conn, (int)$new_id, $amount_paid, $payment_method, $payment_ref,
                                   $status === 'paid' ? 'Full payment' : 'Down payment', (int)$current_user_id, $pay_date);
                }

                log_action($conn, $current_user_id, $current_user_name,
                    'Created Bill', 'billing', $new_id,
                    "Bill: $bill_code | Patient ID: $patient_id | ₱$amount_paid / ₱$amount_due | $status"
                );

                // ── Notification trigger ──────────────────────────────
                $pat_stmt = $conn->prepare("SELECT CONCAT(first_name,' ',last_name) as name FROM patients WHERE id = ? LIMIT 1");
                $pat_stmt->execute([$patient_id]);
                $pat = $pat_stmt->fetch(PDO::FETCH_ASSOC);
                $pat_stmt->closeCursor();
                $pname = $pat['name'] ?? 'Patient';
                if ($status === 'paid') {
                    notify($conn, 'payment', 'Payment Received',
                        "Full payment of ₱" . number_format($amount_paid, 2) . " received from $pname. Bill: $bill_code.",
                        'modules/billing/list.php');
                } elseif ($status === 'partial') {
                    $balance = $amount_due - $amount_paid;
                    notify($conn, 'payment', 'Partial Payment Recorded',
                        "$pname paid ₱" . number_format($amount_paid, 2) . ". Remaining balance: ₱" . number_format($balance, 2) . ". Bill: $bill_code.",
                        'modules/billing/list.php');
                } elseif ($status === 'unpaid') {
                    notify($conn, 'payment', 'New Bill Created',
                        "Bill $bill_code created for $pname — ₱" . number_format($amount_due, 2) . " due.",
                        'modules/billing/list.php');
                }
                // ─────────────────────────────────────────────────────

                // ── Link this bill to the dental record (so Pay Balance shows in patient view) ──
                if ($auto_dr_id) {
                    $conn->prepare("UPDATE bills SET dental_record_id = ? WHERE id = ?")
                         ->execute([$auto_dr_id, $new_id]);
                } elseif ($appointment_id) {
                    $dr_lnk = $conn->prepare(
                        "SELECT id FROM dental_records WHERE appointment_id = ? AND patient_id = ? ORDER BY id DESC LIMIT 1"
                    );
                    $dr_lnk->execute([$appointment_id, $patient_id]);
                    $linked_dr_id = $dr_lnk->fetchColumn();
                    $dr_lnk->closeCursor();
                    if ($linked_dr_id) {
                        $conn->prepare("UPDATE bills SET dental_record_id = ? WHERE id = ?")
                             ->execute([$linked_dr_id, $new_id]);
                    }
                }
                // ─────────────────────────────────────────────────────────────

                $suffix = $pre_from_treatment ? '&flow=done' : '';
                header('Location: view.php?id=' . $new_id . '&created=1' . $suffix);
                exit();
            }
        } catch (PDOException $e) {
            error_log('[BILLING INSERT ERROR] ' . $e->getMessage());
            $error = 'Could not save the bill. Please check all fields and try again.';
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?></head>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">

        <!-- Workflow breadcrumb (shown when coming from treatment flow) -->
        <?php if ($pre_from_treatment): ?>
        <div style="background:var(--blue-50);border:1px solid var(--blue-100);border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:0.82rem;">
            <strong style="color:var(--blue-600);">Patient Flow:</strong>
            <span style="color:var(--gray-400);">Appointment</span>
            <i class="bi bi-arrow-right" style="color:var(--gray-400);margin:0 6px;"></i>
            <span style="color:var(--gray-400);">Check-in</span>
            <i class="bi bi-arrow-right" style="color:var(--gray-400);margin:0 6px;"></i>
            <span style="color:var(--gray-500);">Record Treatment</span>
            <i class="bi bi-arrow-right" style="color:var(--gray-400);margin:0 6px;"></i>
            <strong style="color:var(--blue-600);">Create Bill</strong>
            <i class="bi bi-arrow-right" style="color:var(--gray-400);margin:0 6px;"></i>
            <span style="color:var(--gray-400);">Done ✓</span>
        </div>
        <?php endif; ?>

        <div class="page-header">
            <div>
                <h5>Create Bill</h5>
                <p style="font-size:0.82rem;color:var(--gray-500);">
                    Record what the patient paid — Cash, GCash, or Bank Transfer.
                    <?php if ($pre_from_treatment): ?>
                    <strong style="color:var(--success);">Last step before you're done!</strong>
                    <?php endif; ?>
                </p>
            </div>
            <a href="<?php echo $pre_from_treatment ? BASE_URL.'modules/appointments/list.php' : 'list.php'; ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> <?php echo $pre_from_treatment ? 'Back to Appointments' : 'Back to Billing'; ?>
            </a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill"></i> <?php echo e($error); ?></div>
        <?php endif; ?>

        <div class="card" style="max-width:680px;">
            <div class="card-header">
                <i class="bi bi-receipt" style="color:var(--blue-500)"></i> Bill Details
            </div>
            <div class="card-body">
                <form method="POST" id="billForm">
                    <?php echo csrf_field(); ?>

                    <!-- Patient & Appointment -->
                    <div style="background:var(--gray-50);border-radius:8px;padding:16px;margin-bottom:20px;">
                        <p style="font-family:'Outfit',sans-serif;font-weight:700;font-size:0.78rem;color:var(--blue-600);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:12px;">
                            Patient Information
                        </p>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Patient <span style="color:var(--danger)">*</span></label>
                                <?php if ($pre_from_treatment && $pre_patient_info): ?>
                                    <input type="hidden" name="patient_id" value="<?php echo $pre_patient_info['id']; ?>">
                                    <input type="text" class="form-control" readonly style="background:var(--gray-100);color:var(--gray-600);cursor:not-allowed;"
                                        value="<?php echo e($pre_patient_info['last_name'].', '.$pre_patient_info['first_name'].' ('.$pre_patient_info['patient_code'].')'); ?>">
                                    <small style="color:var(--blue-500);font-size:0.72rem;"><i class="bi bi-lock-fill"></i> Locked from treatment</small>
                                <?php else: ?>
                                    <select name="patient_id" id="patient_select" class="form-select" required onchange="loadAppointments(this.value)">
                                        <option value="">Select Patient</option>
                                        <?php foreach ($patients as $p): ?>
                                        <option value="<?php echo $p['id']; ?>"
                                            <?php echo (!empty($old['patient_id']) ? $old['patient_id'] : $pre_patient_id) === $p['id'] ? 'selected' : ''; ?>>
                                            <?php echo e($p['last_name'].', '.$p['first_name'].' ('.$p['patient_code'].')'); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Linked Appointment <span style="font-size:0.72rem;color:var(--gray-400)">(optional)</span></label>
                                <?php if ($pre_from_treatment && $pre_appt_id): ?>
                                    <input type="hidden" name="appointment_id" value="<?php echo $pre_appt_id; ?>">
                                    <input type="text" class="form-control" readonly style="background:var(--gray-100);color:var(--gray-600);cursor:not-allowed;"
                                        value="<?php echo e($pre_service_info['appointment_code'] ?? 'Linked'); ?>">
                                    <small style="color:var(--blue-500);font-size:0.72rem;"><i class="bi bi-lock-fill"></i> Locked from treatment</small>
                                <?php else: ?>
                                    <select name="appointment_id" id="appt_select" class="form-select">
                                        <option value="">Select patient first</option>
                                    </select>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Service & Amount -->
                    <div style="background:var(--gray-50);border-radius:8px;padding:16px;margin-bottom:20px;">
                        <p style="font-family:'Outfit',sans-serif;font-weight:700;font-size:0.78rem;color:var(--blue-600);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:12px;">
                            Service & Amount
                        </p>
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Services <span style="font-size:0.72rem;color:var(--gray-400)">(tick all that apply)</span></label>
                                <?php
                                $sel_svc = !empty($old) ? ($old['service_ids'] ?? []) : ($pre_from_treatment ? $pre_service_ids : []);
                                echo ms_checkbox_list($services, $sel_svc, ['prefix' => 'bill_svc']);
                                ?>
                                <?php if ($pre_from_treatment && $pre_service_ids): ?>
                                    <small style="color:var(--blue-500);font-size:0.72rem;"><i class="bi bi-info-circle"></i> Pre-ticked from the appointment — you can tick more services.</small>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Total Bill (₱) <span style="color:var(--danger)">*</span></label>
                                <input type="number" name="amount_due" id="amount_due" class="form-control"
                                    step="0.01" min="0" required placeholder="Type the total bill, e.g. 12000"
                                    oninput="updateBalance()"
                                    value="<?php echo htmlspecialchars(
                                        !empty($old) ? ($old['amount_due'] > 0 ? $old['amount_due'] : '') :
                                        ($pre_from_treatment && $pre_service_price > 0 ? $pre_service_price : '')
                                    ); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Payment -->
                    <div style="background:var(--gray-50);border-radius:8px;padding:16px;margin-bottom:20px;">
                        <p style="font-family:'Outfit',sans-serif;font-weight:700;font-size:0.78rem;color:var(--blue-600);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:12px;">
                            Payment Received
                        </p>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Payment Method</label>
                                <select name="payment_method" id="method_select" class="form-select" onchange="toggleRef(this.value)">
                                    <?php
                                    $sel_method = $old['payment_method'] ?? 'cash';
                                    $methods = ['cash' => '💵 Cash', 'gcash' => '📱 GCash', 'bank' => '🏦 Bank Transfer', 'other' => '💳 Other'];
                                    foreach ($methods as $val => $label):
                                    ?>
                                    <option value="<?php echo $val; ?>" <?php echo $sel_method === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Amount Paid (₱)</label>
                                <input type="number" name="amount_paid" id="amount_paid" class="form-control"
                                    step="0.01" min="0" placeholder="0.00"
                                    value="<?php echo htmlspecialchars($old['amount_paid'] ?? 0); ?>"
                                    oninput="updateBalance()">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Payment Date</label>
                                <input type="date" name="pay_date" class="form-control"
                                    value="<?php echo htmlspecialchars($old['pay_date'] ?? date('Y-m-d')); ?>" max="<?php echo date('Y-m-d'); ?>">
                            </div>

                            <!-- GCash reference -->
                            <div class="col-md-6" id="gcash_ref_row" style="display:<?php echo (($old['payment_method'] ?? '') === 'gcash') ? 'block' : 'none'; ?>;">
                                <label class="form-label">GCash Reference No.</label>
                                <input type="text" name="gcash_ref" class="form-control" placeholder="e.g. 1234567890"
                                    value="<?php echo htmlspecialchars($old['gcash_ref'] ?? ''); ?>">
                            </div>

                            <!-- Bank reference -->
                            <div class="col-md-6" id="bank_ref_row" style="display:<?php echo (($old['payment_method'] ?? '') === 'bank') ? 'block' : 'none'; ?>;">
                                <label class="form-label">Bank / Transfer Reference</label>
                                <input type="text" name="bank_ref" class="form-control" placeholder="e.g. Bank ref number"
                                    value="<?php echo htmlspecialchars($old['bank_ref'] ?? ''); ?>">
                            </div>
                        </div>

                        <!-- Balance display -->
                        <div id="balance_display" style="margin-top:14px;padding:12px 16px;border-radius:8px;background:var(--gray-100);display:none;">
                            <div style="display:flex;justify-content:space-between;font-size:0.875rem;margin-bottom:4px;">
                                <span style="color:var(--gray-600);">Amount Due</span>
                                <span id="bd_due" style="font-weight:600;">₱0.00</span>
                            </div>
                            <div style="display:flex;justify-content:space-between;font-size:0.875rem;margin-bottom:4px;">
                                <span style="color:var(--gray-600);">Amount Paid</span>
                                <span id="bd_paid" style="font-weight:600;color:var(--success);">₱0.00</span>
                            </div>
                            <div style="display:flex;justify-content:space-between;font-size:0.95rem;border-top:1px solid var(--gray-300);padding-top:6px;margin-top:6px;">
                                <span style="font-weight:700;">Balance</span>
                                <span id="bd_balance" style="font-weight:700;">₱0.00</span>
                            </div>
                            <div id="bd_status" style="text-align:center;margin-top:8px;font-size:0.8rem;font-weight:600;"></div>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="mb-4">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"
                            placeholder="Optional remarks about this bill..."><?php echo htmlspecialchars($old['notes'] ?? ''); ?></textarea>
                    </div>

                    <div style="display:flex;gap:10px;">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Save Bill
                        </button>
                        <a href="<?php echo $pre_from_treatment ? BASE_URL.'modules/appointments/list.php' : ($pre_patient_id ? BASE_URL.'modules/patients/view.php?id='.$pre_patient_id : 'list.php'); ?>" class="btn btn-outline-secondary">Cancel</a>
                    </div>

                </form>
            </div>
        </div>

    </div>

<script>
function fillPrice(sel) {
    var price = sel.options[sel.selectedIndex]?.dataset?.price;
    if (price) {
        document.getElementById('amount_due').value = parseFloat(price).toFixed(2);
        updateBalance();
    }
}

function updateBalance() {
    var due   = parseFloat(document.getElementById('amount_due').value) || 0;
    var paid  = parseFloat(document.getElementById('amount_paid').value) || 0;
    var bal   = due - paid;
    var disp  = document.getElementById('balance_display');

    if (due > 0) {
        disp.style.display = 'block';
        document.getElementById('bd_due').textContent     = '₱' + due.toFixed(2);
        document.getElementById('bd_paid').textContent    = '₱' + paid.toFixed(2);
        document.getElementById('bd_balance').textContent = '₱' + Math.max(bal,0).toFixed(2);

        var st = document.getElementById('bd_status');
        if (paid <= 0)       { st.textContent = '⚪ Unpaid';  st.style.color = 'var(--danger)'; }
        else if (paid < due) { st.textContent = '🟡 Partial Payment'; st.style.color = 'var(--warning)'; }
        else                 { st.textContent = '✅ Fully Paid'; st.style.color = 'var(--success)'; }

        disp.style.background = paid >= due ? 'var(--success-bg)' : (paid > 0 ? 'var(--warning-bg)' : 'var(--danger-bg)');
    } else {
        disp.style.display = 'none';
    }
}

function toggleRef(method) {
    document.getElementById('gcash_ref_row').style.display = method === 'gcash' ? 'block' : 'none';
    document.getElementById('bank_ref_row').style.display  = method === 'bank'  ? 'block' : 'none';
}

function loadAppointments(patient_id) {
    var sel = document.getElementById('appt_select');
    sel.innerHTML = '<option value="">Loading...</option>';
    if (!patient_id) { sel.innerHTML = '<option value="">Select patient first</option>'; return; }

    fetch('<?php echo BASE_URL; ?>api/billing.php?action=get_appointments&patient_id=' + patient_id)
    .then(r => r.json())
    .then(data => {
        sel.innerHTML = '<option value="">-- No linked appointment --</option>';
        (data.appointments || []).forEach(function(a) {
            var statusLabel = a.status ? ' [' + a.status.charAt(0).toUpperCase() + a.status.slice(1) + ']' : '';
            var selected = (<?php echo json_encode($old['appointment_id'] ?? null); ?> == a.id) ? ' selected' : '';
            sel.innerHTML += '<option value="' + a.id + '"' + selected + '>' + a.appointment_code + ' — ' + a.appointment_date + ' (' + (a.service_name||'No service') + ')' + statusLabel + '</option>';
        });
    });
}

// On page load: if patient was pre-selected or kept from old POST, reload appointments
<?php if (!empty($old['patient_id'])): ?>
loadAppointments(<?php echo intval($old['patient_id']); ?>);
<?php elseif ($pre_patient_id): ?>
loadAppointments(<?php echo $pre_patient_id; ?>);
<?php endif; ?>

// On page load: restore balance display if values already filled (after validation error)
document.addEventListener('DOMContentLoaded', function() {
    updateBalance();
});
</script>
</div>
<?php include '../../includes/footer.php'; ?>
</body>
</html>