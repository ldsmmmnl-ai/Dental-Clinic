<?php
require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/clinic_settings.php';
$_cs = clinic_settings($conn);

$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: ../treatments/list.php'); exit(); }

$stmt = $conn->prepare("
    SELECT dr.*, s.service_name,
           CONCAT(p.first_name,' ',p.last_name) AS patient_name,
           p.patient_code, p.date_of_birth, p.gender, p.address AS patient_address,
           d.full_name AS doctor_name, d.license_number, d.ptr_number, d.specialization AS doctor_spec
    FROM dental_records dr
    LEFT JOIN patients p ON dr.patient_id = p.id
    LEFT JOIN services s ON dr.service_id = s.id
    LEFT JOIN appointments a ON dr.appointment_id = a.id
    LEFT JOIN doctors d ON a.doctor_id = d.id
    WHERE dr.id = ? LIMIT 1
");
$stmt->execute([$id]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$record) { header('Location: ../treatments/list.php'); exit(); }

$age    = $record['date_of_birth'] ? date_diff(date_create($record['date_of_birth']), date_create('today'))->y : '—';
$rx_num = 'RX-' . str_pad($id, 5, '0', STR_PAD_LEFT);
$sex    = ucfirst($record['gender'] ?? '');
$date   = date('F d, Y', strtotime($record['visit_date']));
$meds   = trim($record['medications_prescribed'] ?? '');
$diag   = trim($record['diagnosis'] ?? '');
$notes  = trim($record['next_visit_notes'] ?? '');

$doc_name    = $record['doctor_name']    ?: ($_cs['name'] ?? 'Dentist');
$license_no  = $record['license_number'] ?: '—';
$ptr_no      = $record['ptr_number']     ?: '';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Prescription — <?php echo e($rx_num); ?></title>
<link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>assets/images/favicon.svg">
<style>
/* ── SCREEN TOOLBAR ── */
body { margin:0; padding:0; background:#e8ecf0; font-family:'Segoe UI',Arial,sans-serif; }
.toolbar { position:fixed;top:0;left:0;right:0;z-index:100;background:#fff;
           border-bottom:1px solid #dde;padding:10px 20px;display:flex;gap:8px;align-items:center;
           box-shadow:0 2px 6px rgba(0,0,0,0.08); }
.toolbar button,.toolbar a {
    padding:7px 16px;border-radius:7px;font-size:0.83rem;font-weight:600;
    cursor:pointer;text-decoration:none;border:none;display:inline-flex;align-items:center;gap:5px; }
.btn-print { background:#1d4ed8;color:#fff; }
.btn-back  { background:#f1f5f9;color:#475569;border:1px solid #cbd5e1!important; }
.page-wrap { padding:72px 20px 40px;display:flex;justify-content:center; }

/* ── RX PAPER (landscape half-sheet) ── */
.rx-paper {
    background:#fff;
    width:190mm;          /* half of a landscape A4 = one pad sheet */
    display:flex;
    border:1px solid #bbb;
    box-shadow:0 6px 28px rgba(0,0,0,0.15);
    font-size:9pt;
}

/* ──── LEFT PANEL — patient copy ──── */
.left-panel {
    flex:1;
    padding:14px 16px;
    border-right:1.5px dashed #999;  /* fold line */
}

/* clinic header */
.clinic-header { display:flex;align-items:flex-start;gap:10px;margin-bottom:8px; }
.clinic-logo   { width:42px;height:42px;object-fit:contain; }
.clinic-info h2 { margin:0;font-size:11pt;font-weight:800;letter-spacing:.03em;color:#0a0a0a; }
.clinic-info p  { margin:2px 0 0;font-size:7.5pt;color:#444; }
.clinic-divider { border:none;border-top:1.5px solid #222;margin:6px 0 8px; }

/* patient strip */
.patient-row { display:flex;align-items:flex-end;gap:6px;margin-bottom:5px;font-size:8.5pt; }
.patient-row label { color:#555;white-space:nowrap;flex-shrink:0; }
.underline { flex:1;border-bottom:1px solid #555;min-width:40px;height:16px; padding-bottom:1px; font-weight:600;}
.short { flex:0 0 60px; }

/* Rx section */
.rx-symbol { font-size:22pt;font-weight:900;line-height:1;color:#111;margin:10px 0 4px; }
.rx-lines   { min-height:90px;border-bottom:1px solid #ccc;font-size:8.5pt;
              color:#111;white-space:pre-wrap;line-height:1.9; }
.rx-empty   { color:#aaa;font-style:italic; }
.section-label { font-size:7pt;font-weight:700;text-transform:uppercase;letter-spacing:.06em;
                 color:#666;margin:8px 0 2px; }
.notes-box  { font-size:8.5pt;color:#111;white-space:pre-wrap;min-height:30px;line-height:1.7; }

/* signature row */
.sig-row    { display:flex;gap:16px;margin-top:14px;padding-top:8px;border-top:1px dashed #bbb; }
.sig-col    { flex:1;text-align:center; }
.sig-line   { border-bottom:1px solid #555;height:28px;margin-bottom:3px; }
.sig-name   { font-size:7.5pt;font-weight:700; }
.sig-sub    { font-size:6.5pt;color:#888; }

/* ──── RIGHT PANEL — doctor's copy ──── */
.right-panel {
    width:68mm;
    padding:14px 14px;
    display:flex;
    flex-direction:column;
    justify-content:space-between;
}
.doc-copy-label {
    font-size:6.5pt;font-weight:700;text-transform:uppercase;letter-spacing:.08em;
    color:#888;margin-bottom:8px;text-align:center;
}
.doc-name-block { text-align:center;margin-bottom:10px; }
.doc-name-block h3 { margin:0;font-size:10pt;font-weight:800;color:#0a0a0a;border-bottom:1.5px solid #0a0a0a;padding-bottom:3px;display:inline-block; }
.doc-name-block .doc-cred { font-size:7.5pt;color:#333;margin-top:4px; }
.doc-detail  { font-size:7.5pt;margin:3px 0; }
.detail-line { border-bottom:1px solid #555;display:inline-block;min-width:90px;height:14px;vertical-align:bottom; }
.clinic-hours { margin-top:auto;font-size:7pt;color:#333;border-top:1px solid #ccc;padding-top:8px;line-height:1.7; }
.clinic-hours strong { font-size:7.5pt; }

/* copy separator */
.doc-copy-patient {
    font-size:7pt;border:1px solid #ccc;border-radius:3px;padding:3px 7px;
    text-align:center;color:#666;margin-bottom:8px;
}

/* ── PRINT ── */
@media print {
    .toolbar { display:none!important; }
    body { background:#fff; }
    .page-wrap { padding:0;display:block; }
    .rx-paper { box-shadow:none;border:none;width:100%; }
}
</style>
</head>
<body>

<div class="toolbar">
    <button class="btn-print" onclick="window.print()">🖨️ Print</button>
    <a class="btn-back" href="javascript:history.back()">← Back</a>
    <span style="margin-left:auto;font-size:0.82rem;color:#64748b;">
        <?php echo e($rx_num); ?> &nbsp;·&nbsp; <?php echo $date; ?>
    </span>
</div>

<div class="page-wrap">
<div class="rx-paper">

    <!-- ════ LEFT PANEL — Patient Copy ════ -->
    <div class="left-panel">

        <!-- Clinic Header -->
        <div class="clinic-header">
            <?php if (!empty($_cs['logo_url'])): ?>
            <img src="<?php echo e($_cs['logo_url']); ?>" class="clinic-logo" alt="logo">
            <?php else: ?>
            <div style="width:42px;height:42px;background:#dbeafe;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:20px;">🦷</div>
            <?php endif; ?>
            <div class="clinic-info">
                <h2><?php echo e($_cs['name']); ?></h2>
                <p><?php echo e($doc_name); ?>, D.M.D.</p>
                <?php if (!empty($_cs['address'])): ?>
                <p><?php echo e($_cs['address']); ?></p>
                <?php endif; ?>
                <?php if (!empty($_cs['phone'])): ?>
                <p>Mobile No.: <?php echo e($_cs['phone']); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <hr class="clinic-divider">

        <!-- Patient Info -->
        <div class="patient-row">
            <label>Patient:</label>
            <div class="underline"><?php echo e($record['patient_name']); ?></div>
            <label>Age:</label>
            <div class="underline short"><?php echo $age; ?></div>
        </div>
        <div class="patient-row">
            <label>Address:</label>
            <div class="underline"><?php echo e($record['patient_address'] ?? ''); ?></div>
            <label>Sex:</label>
            <div class="underline short"><?php echo e($sex); ?></div>
        </div>
        <div class="patient-row">
            <label>Date:</label>
            <div class="underline"><?php echo $date; ?></div>
        </div>

        <?php if ($diag): ?>
        <div class="section-label">Diagnosis</div>
        <div class="notes-box" style="margin-bottom:4px;"><?php echo e($diag); ?></div>
        <?php endif; ?>

        <!-- Rx -->
        <div class="rx-symbol">℞</div>
        <div class="rx-lines">
            <?php if ($meds): ?>
                <?php echo e($meds); ?>
            <?php else: ?>
                <span class="rx-empty">No medications prescribed for this visit.</span>
            <?php endif; ?>
        </div>

        <?php if ($notes): ?>
        <div class="section-label">Instructions / Follow-up</div>
        <div class="notes-box"><?php echo e($notes); ?></div>
        <?php endif; ?>

        <!-- Signatures -->
        <div class="sig-row">
            <div class="sig-col">
                <div class="sig-line"></div>
                <div class="sig-name">Patient / Guardian</div>
                <div class="sig-sub">Signature over printed name</div>
            </div>
            <div class="sig-col">
                <div class="sig-line"></div>
                <div class="sig-name"><?php echo e($doc_name); ?></div>
                <div class="sig-sub">Attending Dentist</div>
            </div>
        </div>

    </div><!-- /left-panel -->

    <!-- ════ RIGHT PANEL — Doctor's Copy ════ -->
    <div class="right-panel">

        <div>
            <div class="doc-copy-label">— Doctor's Copy —</div>
            <div class="doc-copy-patient">
                <?php echo e($rx_num); ?> &nbsp;|&nbsp; <?php echo e($record['patient_name']); ?><br>
                <?php echo $date; ?>
            </div>

            <div class="doc-name-block">
                <h3><?php echo e($doc_name); ?>, D.M.D.</h3>
            </div>

            <div class="doc-detail">
                License No. &nbsp;<span class="detail-line"><?php echo e($license_no); ?></span>
            </div>
            <div class="doc-detail" style="margin-top:6px;">
                PTR No. &nbsp;<span class="detail-line"><?php echo e($ptr_no); ?></span>
            </div>
        </div>

        <div class="clinic-hours">
            <strong>CLINIC HOURS:</strong><br>
            Monday to Saturday<br>
            9:00 AM – 5:00 PM<br>
            Sunday – by appointment
        </div>

    </div><!-- /right-panel -->

</div><!-- /rx-paper -->
</div><!-- /page-wrap -->

</body>
</html>
