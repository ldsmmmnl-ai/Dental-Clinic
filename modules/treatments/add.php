<?php
// Record a dental treatment for a patient and mark appointment as completed.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

/** @var int $current_user_id */
/** @var string $current_user_name */
/** @var string $current_user_role */

$page_title = 'Add Dental Record';
$error   = '';
$success = '';

$pre_patient_id     = intval($_GET['patient_id'] ?? 0);
$pre_appointment_id = intval($_GET['appointment_id'] ?? 0);

$patients = sc_get('pat_treat') ?? sc_set('pat_treat', $conn->query("SELECT id, patient_code, first_name, last_name FROM patients WHERE is_active = TRUE ORDER BY last_name ASC")->fetchAll(PDO::FETCH_ASSOC), 120);
$services = sc_get('svc_treat') ?? sc_set('svc_treat', $conn->query("SELECT id, service_name FROM services WHERE is_active = TRUE ORDER BY service_name ASC")->fetchAll(PDO::FETCH_ASSOC), 300);

$pre_appointment = null;
$pre_service_id  = 0;
if ($pre_appointment_id) {
    $pa_stmt = $conn->prepare("SELECT a.*, s.id as service_id, s.service_name FROM appointments a LEFT JOIN services s ON a.service_id = s.id WHERE a.id = ? LIMIT 1");
    $pa_stmt->execute([$pre_appointment_id]);
    $pre_appointment = $pa_stmt->fetch(PDO::FETCH_ASSOC);
    $pa_stmt->closeCursor();
    if ($pre_appointment) $pre_service_id = intval($pre_appointment['service_id'] ?? 0);
}

$pre_patient = null;
if ($pre_patient_id) {
    $pp_stmt = $conn->prepare("SELECT first_name, last_name, allergies, medical_notes, illness_history, blood_type, phone FROM patients WHERE id = ? LIMIT 1");
    $pp_stmt->execute([$pre_patient_id]);
    $pre_patient = $pp_stmt->fetch(PDO::FETCH_ASSOC);
    $pp_stmt->closeCursor();
}

$past_treatments = [];
if ($pre_patient_id) {
    $pt_stmt = $conn->prepare("SELECT dr.visit_date, dr.treatment_done, dr.diagnosis, dr.tooth_number, dr.tooth_status, dr.medications_prescribed, dr.next_visit_notes, s.service_name FROM dental_records dr LEFT JOIN services s ON dr.service_id = s.id WHERE dr.patient_id = ? ORDER BY dr.visit_date DESC, dr.id DESC LIMIT 5");
    $pt_stmt->execute([$pre_patient_id]);
    $past_treatments = $pt_stmt->fetchAll(PDO::FETCH_ASSOC);
    $pt_stmt->closeCursor();
}

$outstanding = 0;
if ($pre_patient_id) {
    $bal_stmt = $conn->prepare("SELECT COALESCE(SUM(amount_due - amount_paid),0) as bal FROM bills WHERE patient_id = ? AND status != 'paid'");
    $bal_stmt->execute([$pre_patient_id]);
    $outstanding = floatval($bal_stmt->fetch(PDO::FETCH_ASSOC)['bal']);
    $bal_stmt->closeCursor();
}

$patient_appointments = [];
if ($pre_patient_id) {
    $pa_stmt = $conn->prepare("SELECT id, appointment_code, appointment_date FROM appointments WHERE patient_id = ? AND status IN ('pending','confirmed','completed') ORDER BY appointment_date DESC");
    $pa_stmt->execute([$pre_patient_id]);
    $patient_appointments = $pa_stmt->fetchAll(PDO::FETCH_ASSOC);
    $pa_stmt->closeCursor();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $patient_id     = intval($_POST['patient_id'] ?? 0);
    $appointment_id = intval($_POST['appointment_id'] ?? 0) ?: null;
    $service_id     = intval($_POST['service_id'] ?? 0) ?: null;
    $tooth_number   = trim($_POST['tooth_number'] ?? '');
    // tooth_number is TEXT — no length limit, never truncate
    $tooth_marks    = sanitize_tooth_marks($_POST['tooth_marks'] ?? '');
    $tooth_status   = trim($_POST['tooth_status'] ?? 'normal');
    $valid_statuses = ['normal','caries','filling','extraction','missing','crown','rootcanal','bridge','implant','denture'];
    if (!in_array($tooth_status, $valid_statuses)) $tooth_status = 'normal';
    $chief_complaint = trim($_POST['chief_complaint'] ?? '');
    $diagnosis       = trim($_POST['diagnosis'] ?? '');
    $treatment_done  = trim($_POST['treatment_done'] ?? '');
    $materials_used  = trim($_POST['materials_used'] ?? '');
    $treatment_plan  = trim($_POST['treatment_plan'] ?? '');
    $medications     = trim($_POST['medications_prescribed'] ?? '');
    $next_visit      = trim($_POST['next_visit_notes'] ?? '');
    $fee_charged     = max(0.0, (float)($_POST['fee_charged'] ?? 0));

    // Sanitise visit_date — HTML date input always sends YYYY-MM-DD
    $raw_date = trim($_POST['visit_date'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw_date)) {
        $visit_date = $raw_date;
    } elseif ($pre_appointment && !empty($pre_appointment['appointment_date'])) {
        $visit_date = substr($pre_appointment['appointment_date'], 0, 10);
    } else {
        $visit_date = date('Y-m-d');
    }

    if (!$patient_id) {
        $error = 'Please select a patient.';
    } elseif ($treatment_done === '') {
        $error = 'Treatment Done is required — describe what was done for this patient.';
    } else {
        try {
            $stmt = $conn->prepare(
                "INSERT INTO dental_records
                 (patient_id, appointment_id, service_id, tooth_number, tooth_status, tooth_marks,
                  chief_complaint, diagnosis, treatment_done, materials_used, treatment_plan,
                  medications_prescribed, next_visit_notes, recorded_by, visit_date, fee_charged)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $stmt->execute([
                $patient_id,
                $appointment_id,
                $service_id,
                $tooth_number ?: null,
                $tooth_status,
                $tooth_marks,
                $chief_complaint ?: null,
                $diagnosis ?: null,
                $treatment_done,
                $materials_used ?: null,
                $treatment_plan ?: null,
                $medications ?: null,
                $next_visit ?: null,
                $current_user_id,
                $visit_date,
                $fee_charged > 0 ? $fee_charged : null,
            ]);

            $new_id = (int)$conn->lastInsertId();
            $stmt->closeCursor();

            if ($appointment_id) {
                $conn->prepare("UPDATE appointments SET status = 'completed' WHERE id = ?")
                     ->execute([$appointment_id]);
            }

            log_action($conn, $current_user_id, $current_user_name,
                'Added Dental Record', 'treatments', $new_id,
                "Patient ID: $patient_id | Visit: $visit_date | Status: $tooth_status");

            // ── Auto-notification: alert staff that a dental record was saved ──
            // Look up patient name from the already-loaded $patients list so we
            // don't need an extra DB query. Fall back to "Patient #ID" if not found.
            $notif_pname = 'Patient #' . $patient_id;
            foreach ($patients as $_p) {
                if ((int)$_p['id'] === $patient_id) {
                    $notif_pname = trim($_p['first_name'] . ' ' . $_p['last_name']);
                    break;
                }
            }
            $fmt_visit = date('M d, Y', strtotime($visit_date));
            notify($conn, 'appointment',
                'Dental Record Saved',
                "A dental record was saved for $notif_pname (visit: $fmt_visit)." . ($appointment_id ? " Appointment marked completed." : ''),
                'modules/treatments/list.php'
            );
            // ─────────────────────────────────────────────────────────────────

            $billing_url = BASE_URL . "modules/billing/create.php?patient_id=$patient_id";
            if ($appointment_id) $billing_url .= "&appointment_id=$appointment_id";
            $billing_url .= "&from_treatment=1";
            header("Location: $billing_url");
            exit();

        } catch (PDOException $e) {
            error_log('[TREATMENT INSERT ERROR] ' . $e->getMessage()
                . ' | patient=' . $patient_id . ' | date=' . $visit_date
                . ' | appt=' . ($appointment_id ?? 'null') . ' | svc=' . ($service_id ?? 'null'));

            $msg = $e->getMessage();
            if (stripos($msg, 'unknown column') !== false) {
                $error = 'Database schema is out of date. Please refresh the page — the system will auto-update and try again.';
            } elseif (stripos($msg, 'foreign key') !== false) {
                $error = 'Invalid patient, appointment, or service reference. Please reload the page and try again.';
            } elseif (stripos($msg, 'date') !== false || stripos($msg, 'visit_date') !== false) {
                $error = 'Invalid visit date. Please pick the date using the calendar.';
            } else {
                $error = 'Could not save: ' . htmlspecialchars($msg);
            }
        }
    }
}

// (tooth chart rendering is now handled by includes/tooth_chart_grid.php)
?><!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?>
<style>
/* Tooth chart styles come from includes/tooth_chart_grid.php */

/* ── Medical alert dark mode ────────────────────── */
[data-theme="dark"] .medical-alert-wrap {
    background: #2d2007 !important;
    border-color: #b45309 !important;
}
[data-theme="dark"] .medical-alert-wrap .alert-icon { color: #fbbf24 !important; }
[data-theme="dark"] .medical-alert-wrap .alert-text { color: #fde68a !important; }
[data-theme="dark"] .medical-allergy-box   { background: #450a0a !important; border-color: #991b1b !important; }
[data-theme="dark"] .medical-allergy-title { color: #f87171 !important; }
[data-theme="dark"] .medical-allergy-body  { color: #fca5a5 !important; }
[data-theme="dark"] .medical-notes-box     { background: #1e3a8a !important; border-color: #3b82f6 !important; }
[data-theme="dark"] .medical-notes-title   { color: #93c5fd !important; }
[data-theme="dark"] .medical-notes-body    { color: #bfdbfe !important; }
[data-theme="dark"] .medical-history-box   { background: #14532d !important; border-color: #22c55e !important; }
[data-theme="dark"] .medical-history-title { color: #86efac !important; }
[data-theme="dark"] .medical-history-body  { color: #bbf7d0 !important; }

/* conditionPreview removed */

/* Mobile */
@media (max-width: 640px) {
    .workflow-breadcrumb { overflow-x:auto!important; -webkit-overflow-scrolling:touch!important; white-space:nowrap!important; }
    #toothChartWrap { overflow-x:auto!important; -webkit-overflow-scrolling:touch!important; padding:12px 8px!important; }
    #conditionPreview { width:100%!important; justify-content:center!important; }
    .dental-form-actions { flex-direction:column!important; }
    .dental-form-actions > * { width:100%!important; text-align:center!important; justify-content:center!important; }
    .medical-alert-grid { grid-template-columns:1fr!important; }
}
</style>
</head>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">
        <?php if (!empty($_GET['session_expired'])): ?>
        <div class="alert alert-warning" style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
            <i class="bi bi-clock-history" style="font-size:1.2rem;"></i>
            <div><strong>Your session token was refreshed.</strong> Your form has been reloaded — please fill it in again and click Save.</div>
        </div>
        <?php endif; ?>


        <!-- Workflow breadcrumb -->
        <?php if ($pre_appointment_id): ?>
        <div class="workflow-breadcrumb" style="background:var(--blue-50);border:1px solid var(--blue-100);border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:0.82rem;">
            <strong style="color:var(--blue-600);">Patient Flow:</strong>
            <span style="color:var(--gray-500);">Appointment</span>
            <i class="bi bi-arrow-right" style="color:var(--gray-400);margin:0 6px;"></i>
            <span style="color:var(--gray-500);">Check-in</span>
            <i class="bi bi-arrow-right" style="color:var(--gray-400);margin:0 6px;"></i>
            <strong style="color:var(--blue-600);">Record Treatment</strong>
            <i class="bi bi-arrow-right" style="color:var(--gray-400);margin:0 6px;"></i>
            <span style="color:var(--gray-400);">Create Bill</span>
            <i class="bi bi-arrow-right" style="color:var(--gray-400);margin:0 6px;"></i>
            <span style="color:var(--gray-400);">Done</span>
        </div>
        <?php endif; ?>

        <!-- Medical alert -->
        <?php if ($pre_patient && ($pre_patient['allergies'] || $pre_patient['medical_notes'] || $pre_patient['illness_history'] || $pre_patient['blood_type'])): ?>
        <div class="medical-alert-wrap" style="background:#fffbeb;border:1.5px solid #f59e0b;border-radius:10px;padding:14px 18px;margin-bottom:20px;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                <i class="bi bi-shield-exclamation alert-icon" style="font-size:1.1rem;color:#d97706;"></i>
                <strong class="alert-text" style="font-size:0.85rem;color:#92400e;">Medical Alert — <?php echo e($pre_patient['last_name'].', '.$pre_patient['first_name']); ?></strong>
                <?php if ($pre_patient['blood_type']): ?>
                <span style="margin-left:auto;background:#dc2626;color:#fff;font-size:0.7rem;font-weight:700;padding:2px 8px;border-radius:20px;"><?php echo e($pre_patient['blood_type']); ?></span>
                <?php endif; ?>
            </div>
            <div class="medical-alert-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
                <?php if ($pre_patient['allergies']): ?>
                <div class="medical-allergy-box" style="background:#fef2f2;border:1px solid #fecaca;border-radius:7px;padding:8px 12px;">
                    <div class="medical-allergy-title" style="font-size:0.7rem;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:3px;"><i class="bi bi-exclamation-triangle-fill"></i> Allergies</div>
                    <div class="medical-allergy-body" style="font-size:0.8rem;color:#7f1d1d;"><?php echo e($pre_patient['allergies']); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($pre_patient['medical_notes']): ?>
                <div class="medical-notes-box" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:7px;padding:8px 12px;">
                    <div class="medical-notes-title" style="font-size:0.7rem;font-weight:700;color:#1d4ed8;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:3px;"><i class="bi bi-heart-pulse-fill"></i> Medical Notes</div>
                    <div class="medical-notes-body" style="font-size:0.8rem;color:#1e3a8a;"><?php echo e($pre_patient['medical_notes']); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($pre_patient['illness_history']): ?>
                <div class="medical-history-box" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:7px;padding:8px 12px;">
                    <div class="medical-history-title" style="font-size:0.7rem;font-weight:700;color:#15803d;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:3px;"><i class="bi bi-clock-history"></i> Illness History</div>
                    <div class="medical-history-body" style="font-size:0.8rem;color:#14532d;"><?php echo e($pre_patient['illness_history']); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="page-header">
            <div>
                <h5>Record Treatment</h5>
                <p style="font-size:0.82rem;color:var(--gray-500);">Fill in what was done for the patient. After saving, you will be taken to billing.</p>
            </div>
            <a href="<?php echo $pre_appointment_id ? BASE_URL.'modules/appointments/list.php' : 'list.php'; ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Appointments
            </a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header"><i class="bi bi-journal-medical" style="color:var(--blue-500)"></i> Treatment Information</div>
            <div class="card-body">
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Patient <span style="color:var(--danger)">*</span></label>
                            <select name="patient_id" id="patient_select" class="form-select" required>
                                <option value="">Select Patient</option>
                                <?php foreach ($patients as $p): ?>
                                    <option value="<?php echo $p['id']; ?>" <?php echo $pre_patient_id == $p['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($p['last_name'].', '.$p['first_name'].' ('.$p['patient_code'].')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Linked Appointment</label>
                            <select name="appointment_id" id="appt_select" class="form-select">
                                <option value="">None / Walk-in</option>
                                <?php foreach ($patient_appointments as $a): ?>
                                    <option value="<?php echo $a['id']; ?>" <?php echo $pre_appointment_id == $a['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($a['appointment_code'].' — '.date('M d, Y', strtotime($a['appointment_date']))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Service
                                <?php if ($pre_appointment_id && $pre_service_id): ?>
                                <span style="font-size:0.72rem;color:var(--blue-500);font-weight:600;"><i class="bi bi-link-45deg"></i> Pre-filled — change if needed</span>
                                <?php endif; ?>
                            </label>
                            <select name="service_id" class="form-select">
                                <option value="">Select Service</option>
                                <?php foreach ($services as $s): ?>
                                    <option value="<?php echo $s['id']; ?>" <?php echo $pre_service_id == $s['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($s['service_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Visit Date</label>
                            <input type="date" name="visit_date" class="form-control" value="<?php echo htmlspecialchars($pre_appointment ? $pre_appointment['appointment_date'] : date('Y-m-d')); ?>">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Chief Complaint <span style="color:var(--gray-400);font-size:0.8rem;">(patient's own words)</span></label>
                            <input type="text" name="chief_complaint" class="form-control" placeholder="e.g. Masakit ang ngipin ko sa kanan — what the patient says when they walk in">
                        </div>

                        <!-- ═══════════════════════════════════════════════════════
                             FLAT GRID TOOTH CHART (matches paper form)
                        ═══════════════════════════════════════════════════════ -->
                        <div class="col-md-12">
                            <label class="form-label">Tooth Chart</label>
                            <div style="background:var(--gray-50);border:1px solid var(--gray-200);border-radius:10px;padding:14px 12px 12px;overflow-x:auto;">
                                <?php
                                $tc_mode        = 'input';
                                $tc_input_name  = 'tooth_number';
                                $ts_select_id   = 'tooth_status_select';
                                $tc_initial     = '';
                                $chart_uid      = 'add_main';
                                $tc_hide_legend = true;
                                include '../../includes/tooth_chart_grid.php';
                                ?>
                            </div>
                            <input type="text" name="tooth_number" id="toothNumberInput" class="form-control mt-2"
                                   placeholder="Or type directly, e.g. 16, 21 — chart will sync" value="">
                            <small style="color:var(--gray-400);font-size:0.75rem;">Selected teeth update this field automatically. You can also type here manually.</small>
                        </div>
                        <!-- ═══════════════════════ END TOOTH CHART ═══════════════ -->

                        <!-- tooth_status stored as normal; condition picker removed per client request -->
                        <input type="hidden" name="tooth_status" id="tooth_status_select" value="normal">

                        <div class="col-md-12">
                            <label class="form-label">Diagnosis</label>
                            <textarea name="diagnosis" class="form-control" rows="2" placeholder="Clinical findings..."></textarea>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Treatment Done <span style="color:var(--danger)">*</span></label>
                            <textarea name="treatment_done" class="form-control" rows="3" required placeholder="Describe the procedure performed..."></textarea>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Materials / Equipment Used</label>
                            <textarea name="materials_used" class="form-control" rows="2" placeholder="e.g. pasta (composite filling), forceps, anesthesia (lidocaine), etc."></textarea>
                            <small style="color:var(--gray-400);font-size:0.72rem;">List any dental materials or instruments used during the procedure.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Medications Prescribed</label>
                            <textarea name="medications_prescribed" class="form-control" rows="2" placeholder="List medications given..."></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Next Visit Notes</label>
                            <textarea name="next_visit_notes" class="form-control" rows="2" placeholder="Follow-up instructions..."></textarea>
                        </div>
                    </div>
                    <div class="dental-form-actions mt-3" style="display:flex;gap:10px;">
                        <button type="submit" class="btn btn-success"><i class="bi bi-check-lg"></i> Save Record</button>
                        <a href="<?php echo $pre_appointment_id ? BASE_URL.'modules/appointments/list.php' : ($pre_patient_id ? '../patients/view.php?id='.$pre_patient_id : 'list.php'); ?>" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($pre_patient_id && (!empty($past_treatments) || $outstanding > 0)): ?>
        <div style="display:grid;grid-template-columns:<?php echo $outstanding > 0 ? '1fr 1fr' : '1fr'; ?>;gap:18px;margin-top:18px;" class="treatment-context-grid">

            <?php if ($outstanding > 0): ?>
            <div class="card" style="border-left:3px solid #f59e0b;">
                <div class="card-body" style="padding:14px 18px;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <i class="bi bi-exclamation-circle-fill" style="font-size:1.2rem;color:#f59e0b;flex-shrink:0;"></i>
                        <div>
                            <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--gray-400);font-weight:600;">Outstanding Balance</div>
                            <div style="font-size:1.3rem;font-weight:700;color:#92400e;">₱<?php echo number_format($outstanding, 2); ?></div>
                            <div style="font-size:0.75rem;color:var(--gray-500);">Patient has unpaid bills</div>
                        </div>
                        <a href="../billing/list.php?patient_id=<?php echo $pre_patient_id; ?>" class="btn btn-sm btn-outline-warning ms-auto" style="flex-shrink:0;">
                            <i class="bi bi-receipt"></i> View Bills
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($past_treatments)): ?>
            <div class="card" <?php echo $outstanding > 0 ? '' : 'style="margin-top:0;"'; ?>>
                <div class="card-header" style="font-size:0.82rem;">
                    <i class="bi bi-clock-history" style="color:var(--blue-500);margin-right:6px;"></i>
                    Past Treatments
                    <span style="margin-left:auto;font-size:0.72rem;color:var(--gray-400);">Most recent <?php echo count($past_treatments); ?></span>
                    <a href="list.php?patient_id=<?php echo $pre_patient_id; ?>" style="margin-left:10px;font-size:0.72rem;color:var(--blue-500);">View all</a>
                </div>
                <div class="card-body p-0">
                    <?php foreach ($past_treatments as $pt): ?>
                    <div style="padding:10px 16px;border-bottom:1px solid var(--gray-100);">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
                            <div style="min-width:0;">
                                <div style="font-size:0.8rem;font-weight:600;color:var(--gray-800);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                    <?php echo htmlspecialchars($pt['service_name'] ?? 'Treatment'); ?>
                                    <?php if ($pt['tooth_number']): ?>
                                    <span style="font-weight:400;color:var(--gray-400);font-size:0.72rem;">· Tooth <?php echo htmlspecialchars($pt['tooth_number']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size:0.75rem;color:var(--gray-500);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    <?php echo htmlspecialchars(strlen($pt['treatment_done']) > 70 ? substr($pt['treatment_done'], 0, 70).'…' : $pt['treatment_done']); ?>
                                </div>
                                <?php if ($pt['medications_prescribed']): ?>
                                <div style="font-size:0.72rem;color:var(--gray-400);margin-top:2px;"><i class="bi bi-capsule" style="font-size:0.65rem;"></i> <?php echo htmlspecialchars(strlen($pt['medications_prescribed']) > 50 ? substr($pt['medications_prescribed'],0,50).'…' : $pt['medications_prescribed']); ?></div>
                                <?php endif; ?>
                                <?php if ($pt['next_visit_notes']): ?>
                                <div style="font-size:0.72rem;color:var(--blue-400);margin-top:2px;"><i class="bi bi-arrow-right-circle" style="font-size:0.65rem;"></i> <?php echo htmlspecialchars(strlen($pt['next_visit_notes']) > 50 ? substr($pt['next_visit_notes'],0,50).'…' : $pt['next_visit_notes']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div style="font-size:0.72rem;color:var(--gray-400);white-space:nowrap;flex-shrink:0;text-align:right;">
                                <?php echo date('M d, Y', strtotime($pt['visit_date'])); ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>
        <style>
        @media (max-width: 768px) {
            .treatment-context-grid { grid-template-columns: 1fr !important; }
        }
        </style>
        <?php endif; ?>
    </div>
<script>
// ── Patient change → reload appointments + pre-color tooth chart ──────────
document.getElementById('patient_select').addEventListener('change', function() {
    var pid    = this.value;
    var select = document.getElementById('appt_select');
    select.innerHTML = '<option value="">Loading...</option>';
    if (!pid) { select.innerHTML = '<option value="">None / Walk-in</option>'; return; }
    fetch('<?php echo BASE_URL; ?>api/patients.php?action=get_appointments&patient_id=' + pid)
    .then(r => r.json())
    .then(data => {
        select.innerHTML = '<option value="">None / Walk-in</option>';
        (data.appointments || []).forEach(function(a) {
            var opt = document.createElement('option');
            opt.value = a.id;
            opt.textContent = a.appointment_code + ' — ' + a.appointment_date;
            opt.dataset.serviceId = a.service_id || '';
            select.appendChild(opt);
        });

        // (Previous tooth conditions shown on patient record page)
    });
});

// ── Appointment change → pre-fill service ────────────────────────────────
document.getElementById('appt_select').addEventListener('change', function() {
    var opt = this.options[this.selectedIndex];
    var sid = opt && opt.dataset && opt.dataset.serviceId;
    if (sid) {
        var svc = document.querySelector('select[name="service_id"]');
        if (svc) svc.value = sid;
    }
});

// Tooth chart JS is now handled by includes/tooth_chart_grid.php
</script>
</div>
<?php include '../../includes/footer.php'; ?>
</body>
</html>
