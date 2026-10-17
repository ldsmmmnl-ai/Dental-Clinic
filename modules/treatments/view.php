<?php
// View a single dental record in full detail.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

$page_title = 'Dental Record';

$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: ../patients/list.php'); exit(); }

$stmt = $conn->prepare("
    SELECT dr.*,
           s.service_name,
           CONCAT(p.first_name,' ',p.last_name) as patient_name,
           p.patient_code, p.id as pid,
           p.date_of_birth, p.gender, p.blood_type,
           p.allergies, p.medical_notes, p.illness_history,
           CONCAT(u.full_name) as recorded_by_name,
           doc.full_name as doctor_name,
           a.appointment_code as linked_appt_code,
           a.appointment_date as linked_appt_date
    FROM dental_records dr
    LEFT JOIN patients  p   ON dr.patient_id     = p.id
    LEFT JOIN services  s   ON dr.service_id     = s.id
    LEFT JOIN users     u   ON dr.recorded_by    = u.id
    LEFT JOIN appointments a ON dr.appointment_id = a.id
    LEFT JOIN doctors   doc ON a.doctor_id        = doc.id
    WHERE dr.id = ?
    LIMIT 1
");
$stmt->execute([$id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$r) { header('Location: ../patients/list.php'); exit(); }

// Fetch other dental records for this patient — must be done here before HTML output
$other_stmt = $conn->prepare("
    SELECT dr.id, dr.visit_date, dr.tooth_number, dr.tooth_status, dr.treatment_done,
           s.service_name
    FROM dental_records dr
    LEFT JOIN services s ON dr.service_id = s.id
    WHERE dr.patient_id = ? AND dr.id != ?
    ORDER BY dr.visit_date DESC
    LIMIT 5
");
$other_stmt->execute([$r['pid'], $id]);
$other_records = $other_stmt->fetchAll(PDO::FETCH_ASSOC);
$other_stmt = null;

// Tooth status label + color map (uses CSS variables — dark mode safe)
$status_map = [
    'normal'    => ['label' => 'Normal / Healthy',     'bg' => 'var(--success-bg)',  'color' => 'var(--success)',  'border' => 'var(--success-border)'],
    'caries'    => ['label' => 'Caries (Cavity)',       'bg' => 'var(--warning-bg)',  'color' => 'var(--warning)',  'border' => 'var(--warning-border)'],
    'filling'   => ['label' => 'Filling Done',          'bg' => 'var(--primary-bg)',  'color' => 'var(--primary)',  'border' => 'var(--blue-200)'],
    'extraction'=> ['label' => 'Extraction / Pulled',   'bg' => 'var(--danger-bg)',   'color' => 'var(--danger)',   'border' => 'var(--danger-border)'],
    'missing'   => ['label' => 'Already Missing',       'bg' => 'var(--gray-100)',    'color' => 'var(--gray-500)', 'border' => 'var(--gray-200)'],
    'crown'     => ['label' => 'Crown Placed',          'bg' => '#fdf4ff',            'color' => '#7e22ce',         'border' => '#e9d5ff'],
    'rootcanal' => ['label' => 'Root Canal Treated',    'bg' => '#fff1f2',            'color' => '#be123c',         'border' => '#fecdd3'],
    'bridge'    => ['label' => 'Bridge',                'bg' => 'var(--success-bg)',  'color' => 'var(--success)',  'border' => 'var(--success-border)'],
    'implant'   => ['label' => 'Implant',               'bg' => 'var(--primary-bg)',  'color' => 'var(--primary)',  'border' => 'var(--blue-200)'],
    'denture'   => ['label' => 'Denture',               'bg' => 'var(--gray-100)',    'color' => 'var(--gray-500)', 'border' => 'var(--gray-300)'],
];
$ts  = $r['tooth_status'] ?? 'normal';
$ts_info = $status_map[$ts] ?? $status_map['normal'];
$tsc = $status_map[$ts] ?? $status_map['normal'];
?><!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?>
<style>
/* ── Dental Record view — mobile ── */
@media (max-width: 640px) {
    .dental-view-grid {
        grid-template-columns: 1fr !important;
    }
    .dental-view-actions {
        flex-direction: column !important;
        width: 100% !important;
    }
    .dental-view-actions a {
        width: 100% !important;
        justify-content: center !important;
    }
    .dental-meds-grid {
        grid-template-columns: 1fr !important;
    }
    .patient-action-btns {
        flex-wrap: wrap !important;
    }
    .patient-action-btns a {
        flex: 1 !important;
        justify-content: center !important;
        text-align: center !important;
    }
    .tooth-chart-scroll {
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch !important;
    }
    .dental-page-header {
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 10px !important;
    }
}

/* ── Medical alert — dark mode ── */
[data-theme="dark"] .view-medical-alert {
    background: #2d2007 !important;
    border-color: #b45309 !important;
}
[data-theme="dark"] .view-alert-icon { color: #fbbf24 !important; }
[data-theme="dark"] .view-alert-body { color: #fde68a !important; }

/* ── Treatment done box — dark mode ── */
[data-theme="dark"] .treatment-done-box {
    background: var(--gray-100) !important;
    border-color: var(--gray-200) !important;
    color: var(--gray-800) !important;
}
</style>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">

        <!-- Header row -->
        <div class="page-header" style="margin-bottom:20px;">
            <div>
                <h5>Dental Record</h5>
                <p style="font-size:0.82rem;color:var(--gray-500);margin:0;">
                    <a href="../patients/view.php?id=<?php echo $r['pid']; ?>" style="color:var(--blue-500);">
                        <?php echo e($r['patient_name']); ?>
                    </a>
                    &nbsp;·&nbsp; <?php echo e($r['patient_code']); ?>
                    &nbsp;·&nbsp; Visit: <?php echo date('F d, Y', strtotime($r['visit_date'])); ?>
                </p>
            </div>
            <div class="dental-view-actions" style="display:flex;gap:8px;flex-wrap:wrap;">
                <a href="../patients/view.php?id=<?php echo $r['pid']; ?>" class="btn btn-sm btn-primary">
                    <i class="bi bi-person-fill"></i> Patient Profile
                </a>
                <?php if (!empty($r['linked_appt_code'])): ?>
                <a href="../appointments/list.php?search=<?php echo urlencode($r['linked_appt_code']); ?>"
                   class="btn btn-sm btn-outline-primary" title="View linked appointment">
                    <i class="bi bi-calendar2-check"></i> <?php echo e($r['linked_appt_code']); ?>
                </a>
                <?php endif; ?>
                <a href="../patients/view.php?id=<?php echo $r['pid']; ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Patient
                </a>
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button"
                            data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-printer"></i> Print
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <a class="dropdown-item" href="../print/dental_record.php?id=<?php echo $id; ?>">
                                <i class="bi bi-file-earmark-medical"></i> Dental Record
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="../print/dental_certificate.php?id=<?php echo $id; ?>">
                                <i class="bi bi-patch-check"></i> Dental Certificate
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="../print/prescription.php?id=<?php echo $id; ?>">
                                <i class="bi bi-prescription2"></i> Prescription
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Medical alert banner (shown if patient has relevant data) -->
        <?php if ($r['allergies'] || $r['medical_notes'] || $r['illness_history']): ?>
        <div class="view-medical-alert" style="background:#fffbeb;border:1.5px solid #f59e0b;border-radius:10px;padding:12px 16px;margin-bottom:18px;display:flex;align-items:flex-start;gap:10px;">
            <i class="bi bi-shield-exclamation view-alert-icon" style="color:#d97706;font-size:1.1rem;flex-shrink:0;margin-top:2px;"></i>
            <div class="view-alert-body" style="font-size:0.8rem;color:#92400e;">
                <strong>Medical Alert</strong>
                <?php if ($r['blood_type']): ?>
                    <span style="background:#dc2626;color:#fff;font-size:0.68rem;font-weight:700;padding:1px 7px;border-radius:20px;margin-left:8px;"><?php echo e($r['blood_type']); ?></span>
                <?php endif; ?>
                <div style="margin-top:5px;display:flex;gap:16px;flex-wrap:wrap;">
                    <?php if ($r['allergies']): ?><span><strong>Allergies:</strong> <?php echo e($r['allergies']); ?></span><?php endif; ?>
                    <?php if ($r['medical_notes']): ?><span><strong>Medical:</strong> <?php echo e($r['medical_notes']); ?></span><?php endif; ?>
                    <?php if ($r['illness_history']): ?><span><strong>History:</strong> <?php echo e($r['illness_history']); ?></span><?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="dental-view-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:18px;">

            <!-- LEFT: Clinical Details -->
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-journal-medical" style="color:var(--blue-500);margin-right:6px;"></i>
                    Clinical Record
                    <span style="margin-left:auto;font-size:0.75rem;color:var(--gray-400);">
                        <?php echo date('M d, Y', strtotime($r['visit_date'])); ?>
                    </span>
                </div>
                <div class="card-body" style="padding:18px 22px;">

                    <!-- Service + Doctor row -->
                    <div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;">
                        <?php if ($r['service_name']): ?>
                        <span style="background:var(--primary-bg);color:var(--primary);border:1px solid var(--blue-200);border-radius:7px;padding:4px 12px;font-size:0.78rem;font-weight:600;">
                            <i class="bi bi-clipboard2-pulse"></i> <?php echo e($r['service_name']); ?>
                        </span>
                        <?php endif; ?>
                        <?php if ($r['doctor_name']): ?>
                        <span style="background:var(--success-bg);color:var(--success);border:1px solid var(--success-border);border-radius:7px;padding:4px 12px;font-size:0.78rem;font-weight:600;">
                            <i class="bi bi-person-badge"></i> <?php echo e($r['doctor_name']); ?>
                        </span>
                        <?php endif; ?>
                    </div>

                    <!-- Chief Complaint -->
                    <?php if (($r['chief_complaint'] ?? '')): ?>
                    <div style="margin-bottom:14px;">
                        <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--gray-400);font-weight:600;margin-bottom:4px;">Chief Complaint</div>
                        <div style="font-size:0.88rem;color:var(--gray-700);font-style:italic;">"<?php echo e(($r['chief_complaint'] ?? '')); ?>"</div>
                    </div>
                    <?php endif; ?>

                    <!-- Tooth Chart (read-only visual) -->
                    <div style="margin-bottom:14px;">
                        <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--gray-400);font-weight:600;margin-bottom:8px;">
                            Tooth Chart
                            <?php if ($r['tooth_number']): ?>
                            <span style="margin-left:8px;font-size:0.72rem;color:var(--blue-500);text-transform:none;letter-spacing:0;font-weight:500;">
                                Selected: <?php echo e($r['tooth_number']); ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php
                        // Build tooth → status map for the shared chart component
                        $chart_teeth = [];
                        if (!empty($r['tooth_number']) && !empty($r['tooth_status'])) {
                            foreach (preg_split('/[\s,;]+/', $r['tooth_number']) as $_t) {
                                $_t = trim($_t);
                                if ($_t !== '') $chart_teeth[$_t] = $r['tooth_status'];
                            }
                        }
                        $chart_marks = parse_tooth_marks($r['tooth_marks'] ?? '');
                        $chart_uid = 'view_' . $r['id'];
                        $tc_mode   = 'display';
                        ?>
                        <div style="background:var(--white);border:1px solid var(--gray-200);border-radius:10px;padding:14px 10px;overflow-x:auto;">
                            <?php include dirname(__FILE__) . '/../../includes/tooth_chart_grid.php'; ?>
                        </div>
                    </div>

                    <!-- Tooth Condition -->
                    <div style="margin-bottom:14px;">
                        <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--gray-400);font-weight:600;margin-bottom:4px;">Tooth Condition</div>
                        <span style="background:<?php echo $tsc['bg']; ?>;color:<?php echo $tsc['color']; ?>;border:1px solid <?php echo $tsc['border']; ?>;border-radius:7px;padding:3px 10px;font-size:0.78rem;font-weight:600;">
                            <?php echo $tsc['label']; ?>
                        </span>
                    </div>

                    <!-- Diagnosis -->
                    <?php if (!empty($r['diagnosis'] ?? '')): ?>
                    <div style="margin-bottom:14px;">
                        <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--gray-400);font-weight:600;margin-bottom:4px;">Diagnosis</div>
                        <div style="font-size:0.85rem;color:var(--gray-700);line-height:1.6;white-space:pre-wrap;"><?php echo e($r['diagnosis']); ?></div>
                    </div>
                    <?php endif; ?>

                    <!-- Treatment Done -->
                    <div style="margin-bottom:14px;">
                        <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--gray-400);font-weight:600;margin-bottom:4px;">Treatment Done</div>
                        <div style="background:var(--gray-50);border:1px solid var(--gray-100);border-radius:8px;padding:10px 12px;font-size:0.85rem;color:var(--gray-800);line-height:1.6;white-space:pre-wrap;">
                            <?php
                            // For old records where materials were appended to treatment_done, strip them out so they show in their own section below
                            $td_clean = $r['treatment_done'] ?? '';
                            if (str_contains($td_clean, "\n\nMaterials/Equipment Used:")) {
                                $td_clean = explode("\n\nMaterials/Equipment Used:", $td_clean)[0];
                            }
                            echo e($td_clean);
                            ?>
                        </div>
                    </div>

                    <!-- Materials / Equipment Used -->
                    <?php
                    // New records: own column. Old records: extracted from treatment_done text.
                    $mat_view = $r['materials_used'] ?? '';
                    if (empty($mat_view) && str_contains(($r['treatment_done'] ?? ''), "\n\nMaterials/Equipment Used:")) {
                        $mat_view = trim(explode("\n\nMaterials/Equipment Used:", $r['treatment_done'])[1] ?? '');
                    }
                    ?>
                    <?php if (!empty($mat_view)): ?>
                    <div style="margin-bottom:14px;">
                        <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--gray-400);font-weight:600;margin-bottom:4px;">Materials / Equipment Used</div>
                        <div style="font-size:0.85rem;color:var(--gray-700);line-height:1.6;white-space:pre-wrap;"><?php echo e($mat_view); ?></div>
                    </div>
                    <?php endif; ?>

                    <!-- Treatment Plan -->
                    <?php if (!empty($r['treatment_plan'] ?? '')): ?>
                    <div style="margin-bottom:14px;">
                        <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--gray-400);font-weight:600;margin-bottom:4px;">Treatment Plan</div>
                        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 12px;font-size:0.85rem;color:var(--gray-700);line-height:1.6;white-space:pre-wrap;"><?php echo e($r['treatment_plan']); ?></div>
                    </div>
                    <?php endif; ?>

                    <!-- Medications + Next Visit -->
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
                        <div>
                            <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--gray-400);font-weight:600;margin-bottom:4px;">Medications Prescribed</div>
                            <div style="font-size:0.82rem;color:var(--gray-700);white-space:pre-wrap;">
                                <?php echo !empty($r['medications_prescribed']) ? e($r['medications_prescribed']) : '<span style="color:var(--gray-400);">None prescribed</span>'; ?>
                            </div>
                        </div>
                        <div>
                            <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--gray-400);font-weight:600;margin-bottom:4px;">Next Visit Notes</div>
                            <div style="font-size:0.82rem;color:var(--gray-700);white-space:pre-wrap;">
                                <?php echo !empty($r['next_visit_notes']) ? e($r['next_visit_notes']) : '<span style="color:var(--gray-400);">None</span>'; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Footer meta -->
                    <div style="margin-top:16px;padding-top:12px;border-top:1px solid var(--gray-100);font-size:0.73rem;color:var(--gray-400);display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px;">
                        <span><i class="bi bi-person-fill"></i> Recorded by: <?php echo e($r['recorded_by_name'] ?? '—'); ?></span>
                        <span><i class="bi bi-clock"></i> <?php echo date('M d, Y h:i A', strtotime($r['created_at'])); ?></span>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Patient Summary -->
            <div style="display:flex;flex-direction:column;gap:18px;">

                <!-- Patient quick info -->
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-person-circle" style="color:var(--blue-500);margin-right:6px;"></i>
                        Patient
                    </div>
                    <div class="card-body" style="padding:16px 22px;">
                        <div style="display:flex;align-items:center;gap:14px;margin-bottom:14px;">
                            <div style="width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg,var(--primary),#5a8fff);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.2rem;font-weight:700;flex-shrink:0;">
                                <?php echo strtoupper(substr($r['patient_name'], 0, 1)); ?>
                            </div>
                            <div>
                                <div style="font-weight:700;font-size:0.95rem;"><?php echo e($r['patient_name']); ?></div>
                                <div style="font-size:0.78rem;color:var(--blue-500);"><?php echo e($r['patient_code']); ?></div>
                                <?php if ($r['date_of_birth']): ?>
                                <div style="font-size:0.75rem;color:var(--gray-400);">
                                    <?php
                                    $dob = new DateTime($r['date_of_birth']);
                                    $age = (new DateTime())->diff($dob)->y;
                                    echo $age . ' years old · ' . ucfirst($r['gender'] ?? '');
                                    ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="patient-action-btns" style="display:flex;gap:8px;flex-wrap:wrap;">
                            <a href="../patients/view.php?id=<?php echo $r['pid']; ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-person"></i> Full Profile
                            </a>
                            <?php if (!empty($other_records)): ?>
                            <a href="../patients/view.php?id=<?php echo $r['pid']; ?>" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-journal-medical"></i> All Records
                            </a>
                            <?php endif; ?>
                            <a href="add.php?patient_id=<?php echo $r['pid']; ?>" class="btn btn-sm btn-success">
                                <i class="bi bi-plus"></i> Add Record
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Other dental records for this patient -->
                <?php if (!empty($other_records)): ?>
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-clock-history" style="color:var(--blue-500);margin-right:6px;"></i>
                        Previous Records
                        <span style="margin-left:auto;font-size:0.73rem;color:var(--gray-400);">Most recent 5</span>
                    </div>
                    <div class="card-body p-0">
                        <?php foreach ($other_records as $or): ?>
                        <a href="view.php?id=<?php echo $or['id']; ?>" style="display:block;padding:11px 18px;border-bottom:1px solid var(--gray-100);text-decoration:none;transition:background 0.15s;"
                           onmouseover="this.style.background='var(--gray-50)'" onmouseout="this.style.background=''">
                            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
                                <div>
                                    <div style="font-size:0.82rem;font-weight:600;color:var(--gray-800);">
                                        <?php echo e($or['service_name'] ?? 'Treatment'); ?>
                                        <?php if ($or['tooth_number']): ?>
                                            <span style="font-size:0.72rem;color:var(--gray-400);">· Tooth <?php echo e($or['tooth_number']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size:0.75rem;color:var(--gray-500);margin-top:1px;">
                                        <?php echo strlen($or['treatment_done']) > 55 ? substr(e($or['treatment_done']), 0, 55) . '…' : e($or['treatment_done']); ?>
                                    </div>
                                </div>
                                <div style="font-size:0.72rem;color:var(--gray-400);white-space:nowrap;flex-shrink:0;">
                                    <?php echo date('M d, Y', strtotime($or['visit_date'])); ?>
                                </div>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>

    </div>
</div>
<?php include '../../includes/footer.php'; ?>
</body>
</html>
