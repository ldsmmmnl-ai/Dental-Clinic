<?php
require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/clinic_settings.php';
$_cs = clinic_settings($conn);

$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: ../treatments/list.php'); exit(); }

// Main record + patient full info
$stmt = $conn->prepare("
    SELECT dr.*, s.service_name,
           p.id as patient_id,
           CONCAT(p.last_name,', ',p.first_name,
                  IF(COALESCE(NULLIF(TRIM(p.middle_name),''), '') <> '',
                     CONCAT(' ', TRIM(p.middle_name)), '')) as patient_name,
           p.patient_code, p.date_of_birth, p.gender, p.civil_status,
           p.phone, p.allergies, p.occupation, p.address,
           p.medical_notes, p.illness_history,
           u.full_name as recorded_by_name
    FROM dental_records dr
    LEFT JOIN patients p ON dr.patient_id = p.id
    LEFT JOIN services s ON dr.service_id = s.id
    LEFT JOIN users u ON dr.recorded_by = u.id
    WHERE dr.id = ? LIMIT 1
");
$stmt->execute([$id]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$record) { header('Location: ../treatments/list.php'); exit(); }

// Build back-page rows: first row = this record's initial treatment,
// then any return-visit entries added to this specific record.
$back_rows = [];

// Row 0 — the initial record itself.
// If fee_charged was not set (treatment created via appointment flow without inline fee),
// fall back to the linked bill's amount_due so the print always shows the correct figure.
$initial_fee = ($record['fee_charged'] ?? null) > 0 ? (float)$record['fee_charged'] : null;
if ($initial_fee === null) {
    $appt_id_for_print = (int)($record['appointment_id'] ?? 0);
    $fb = $conn->prepare("
        SELECT amount_due FROM bills
        WHERE (dental_record_id = ? OR (? > 0 AND appointment_id = ? AND dental_record_id IS NULL))
          AND patient_id = ?
        ORDER BY id DESC LIMIT 1
    ");
    $fb->execute([$id, $appt_id_for_print, $appt_id_for_print, $record['patient_id']]);
    $fb_row = $fb->fetch();
    if ($fb_row && $fb_row['amount_due'] > 0) {
        $initial_fee = (float)$fb_row['amount_due'];
    }
}
$back_rows[] = [
    'visit_date'   => $record['visit_date'],
    'service_name' => $record['service_name'] ?? null,
    'treatment'    => $record['treatment_done'] ?? null,
    'fee'          => $initial_fee,
];

// Additional return-visit entries for this record
$stmt2 = $conn->prepare("
    SELECT visit_date, treatment_rendered AS treatment, fee, NULL AS service_name
    FROM dental_record_visits
    WHERE dental_record_id = ?
    ORDER BY visit_date ASC, id ASC
");
$stmt2->execute([$id]);
foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $_ve) {
    $back_rows[] = $_ve;
}

$dob     = $record['date_of_birth'];
$age     = $dob ? date_diff(date_create($dob), date_create('today'))->y : null;
$dob_fmt = $dob ? date('m/d/y', strtotime($dob)) : '';
$autoprint = !empty($_GET['autoprint']);

// Build tooth chart data for this record
$chart_teeth = [];
if (!empty($record['tooth_number']) && !empty($record['tooth_status'])) {
    foreach (preg_split('/[\s,;]+/', $record['tooth_number']) as $_t) {
        $_t = trim($_t);
        if ($_t !== '') $chart_teeth[$_t] = $record['tooth_status'];
    }
}

// Number of empty filler rows on back page (show at least 20 total rows)
$filled = count($back_rows);
$empty_rows = max(0, 5 - $filled); // 5 blank rows max for future hand-written visits

function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Patient Record — <?= esc($record['patient_code']) ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Times New Roman',Times,serif;font-size:10.5pt;color:#000;background:#f0f0f0;}
@page{size:A4 portrait;margin:0;}/* margin:0 removes browser-added URL/date/page-number from print */

/* Screen toolbar */
.print-toolbar{
    display:flex;align-items:center;gap:10px;
    padding:10px 20px;background:#fff;border-bottom:1px solid #ddd;
    position:sticky;top:0;z-index:100;
}
.print-toolbar button,
.print-toolbar a{
    padding:7px 16px;border-radius:7px;font-size:0.82rem;
    cursor:pointer;font-family:inherit;text-decoration:none;border:1.5px solid #2563eb;
}
.btn-print{background:#2563eb;color:#fff;border-color:#2563eb;}
.btn-back{background:#fff;color:#64748b;border-color:#e2e8f0;}
@media print{.print-toolbar{display:none!important;}}

/* Page wrapper — shown on screen with shadow */
.page{
    width:185mm;
    min-height:267mm;
    background:#fff;
    margin:16px auto;
    padding:8mm 10mm;
    box-shadow:0 2px 12px rgba(0,0,0,0.15);
    position:relative;
}
@media print{
    body{background:#fff;}
    .page{
        width:auto;margin:0;padding:10mm 12mm;
        box-shadow:none;
        page-break-after:always;
    }
    .page:last-child{page-break-after:auto;}
}

/* ── PAGE 1 STYLES ── */
.clinic-header{
    display:flex;align-items:flex-start;gap:10px;
    border-bottom:2.5px double #000;
    padding-bottom:7px;margin-bottom:8px;
}
.clinic-logo-wrap{flex-shrink:0;}
.clinic-logo-wrap img{max-height:54px;max-width:54px;object-fit:contain;}
.clinic-logo-wrap .logo-placeholder{
    width:54px;height:54px;border:1px solid #000;
    display:flex;align-items:center;justify-content:center;
    font-size:0.6rem;color:#666;text-align:center;
}
.clinic-name-block{flex:1;text-align:center;}
.clinic-script{
    font-family:'Book Antiqua','Palatino Linotype',Palatino,Georgia,serif;
    font-style:italic;font-weight:bold;font-size:22pt;
    line-height:1.1;color:#000;
}
.clinic-addr{font-size:7.5pt;margin-top:3px;line-height:1.4;}

/* Patient info fields */
.info-section{margin-bottom:6px;}
.field-line{
    display:flex;align-items:baseline;
    border-bottom:1px solid #000;
    margin-bottom:5px;min-height:20px;
    padding-bottom:1px;
}
.field-line.double-col{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.field-line.triple-col{display:grid;grid-template-columns:2fr 1fr 1fr 1fr 2fr;gap:8px;}
.fl-label{font-size:8pt;font-weight:bold;white-space:nowrap;padding-right:5px;flex-shrink:0;}
.fl-value{font-size:10pt;flex:1;padding-left:3px;}
.fl-inner{display:flex;align-items:baseline;border-bottom:1px solid #000;padding-bottom:1px;}
.fl-inner .fl-label{font-size:8pt;}
.fl-inner .fl-value{font-size:10pt;}

/* Tooth chart container */
.chart-section{
    border:1.5px solid #000;
    padding:5px 8px;
    margin:7px 0;
    display:flex;justify-content:center;
}

/* Text area fields (complaint, history, dx, tx) */
.text-field{
    margin-bottom:5px;
}
.text-field .tf-label{
    font-size:8.5pt;font-weight:bold;display:block;margin-bottom:1px;
}
.text-field .tf-value{
    display:block;font-size:10pt;
    border-bottom:1px solid #000;
    min-height:19px;padding:1px 3px;line-height:1.4;
}
.text-field .tf-value.multiline{
    min-height:34px;
}

/* ── PAGE 2 STYLES ── */
.back-header{
    text-align:right;font-size:7.5pt;
    border-bottom:1.5px solid #000;
    padding-bottom:5px;margin-bottom:8px;
    color:#333;
}
.visit-table{width:100%;border-collapse:collapse;}
.visit-table th{
    background:#f5f5f5;font-size:9pt;font-weight:bold;
    text-align:center;padding:4px 6px;
    border:1.5px solid #000;
}
.visit-table td{
    border:1px solid #000;padding:3px 5px;
    vertical-align:top;font-size:9.5pt;
    min-height:20px;
}
.col-date{width:70px;white-space:nowrap;}
.col-treatment{/* auto */}
.col-fee{width:75px;text-align:right;}
.empty-row td{height:21px;}
.visit-date{font-size:8.5pt;}
.visit-treatment{font-size:9pt;line-height:1.3;}
.visit-fee{font-size:9pt;font-weight:600;}
.total-row td{font-weight:bold;background:#f9f9f9;border:1.5px solid #000;font-size:9pt;}
.back-footer{
    margin-top:10px;font-size:7.5pt;color:#555;text-align:center;
}
</style>
</head>
<body>

<!-- ── TOOLBAR (screen only) ── -->
<div class="print-toolbar">
    <button class="btn-print" onclick="window.print()">🖨️ Print Record</button>
    <a class="btn-back" href="javascript:history.back()">← Back</a>
</div>

<!-- ══════════════════════ PAGE 1 — FRONT ══════════════════════ -->
<div class="page">

    <!-- Clinic Header -->
    <div class="clinic-header">
        <div class="clinic-logo-wrap">
            <?php if (!empty($_cs['logo_url'])): ?>
            <img src="<?= esc($_cs['logo_url']) ?>" alt="Logo">
            <?php else: ?>
            <div class="logo-placeholder">CLINIC<br>LOGO</div>
            <?php endif; ?>
        </div>
        <div class="clinic-name-block">
            <div class="clinic-script"><?= esc($_cs['name']) ?></div>
            <div class="clinic-addr">
                <?php if ($_cs['address']): ?><?= esc($_cs['address']) ?><br><?php endif; ?>
                <?php if ($_cs['phone']): ?>Tel. <?= esc($_cs['phone']) ?><?php if($_cs['email']): ?> &nbsp;·&nbsp; <?= esc($_cs['email']) ?><?php endif; ?><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Patient Info -->
    <div class="info-section">

        <!-- Name -->
        <div class="field-line">
            <span class="fl-label">Name:</span>
            <span class="fl-value"><?= esc($record['patient_name']) ?></span>
        </div>

        <!-- Address -->
        <div class="field-line">
            <span class="fl-label">Address:</span>
            <span class="fl-value"><?= esc($record['address'] ?? '') ?></span>
        </div>

        <!-- Address/Occupation -->
        <div class="field-line">
            <span class="fl-label">Address/Occupation:</span>
            <span class="fl-value"><?= esc($record['occupation'] ?? '') ?></span>
        </div>

        <!-- DOB / Age / Sex / Status / Tel -->
        <div style="display:grid;grid-template-columns:auto auto auto auto 1fr;gap:0 14px;align-items:baseline;border-bottom:1px solid #000;margin-bottom:5px;min-height:20px;padding-bottom:1px;">
            <div style="display:flex;align-items:baseline;gap:3px;">
                <span class="fl-label">D.O.B.</span>
                <span class="fl-value" style="border-bottom:1px solid #000;min-width:65px;padding:0 3px;"><?= esc($dob_fmt) ?></span>
            </div>
            <div style="display:flex;align-items:baseline;gap:3px;">
                <span class="fl-label">Age:</span>
                <span class="fl-value" style="border-bottom:1px solid #000;min-width:30px;padding:0 3px;"><?= $age !== null ? $age : '' ?></span>
            </div>
            <div style="display:flex;align-items:baseline;gap:3px;">
                <span class="fl-label">Sex:</span>
                <span class="fl-value" style="border-bottom:1px solid #000;min-width:35px;padding:0 3px;"><?= $record['gender'] ? strtoupper(substr($record['gender'],0,1)) : '' ?></span>
            </div>
            <div style="display:flex;align-items:baseline;gap:3px;">
                <span class="fl-label">Status:</span>
                <span class="fl-value" style="border-bottom:1px solid #000;min-width:55px;padding:0 3px;"><?= $record['civil_status'] ? ucfirst($record['civil_status']) : '' ?></span>
            </div>
            <div style="display:flex;align-items:baseline;gap:3px;">
                <span class="fl-label">Tel. No.</span>
                <span class="fl-value" style="border-bottom:1px solid #000;flex:1;padding:0 3px;"><?= esc($record['phone'] ?? '') ?></span>
            </div>
        </div>

    </div>

    <!-- Tooth Chart -->
    <div class="chart-section">
        <?php
        $chart_uid = 'print_' . $id;
        $tc_mode   = 'display';
        include dirname(__FILE__) . '/../../includes/tooth_chart_grid.php';
        ?>
    </div>

    <!-- Chief Complaint -->
    <div class="text-field">
        <span class="tf-label">Chief Complaint:</span>
        <span class="tf-value"><?= esc($record['chief_complaint'] ?? '') ?></span>
    </div>

    <!-- Medical / Dental History -->
    <div class="text-field">
        <span class="tf-label">Medical / Dental History:</span>
        <span class="tf-value multiline"><?= esc($record['medical_notes'] ?? '') ?></span>
    </div>

    <!-- History of Illness -->
    <div class="text-field">
        <span class="tf-label">History of Illness:</span>
        <span class="tf-value multiline"><?= esc($record['illness_history'] ?? '') ?></span>
    </div>

    <!-- Diagnosis -->
    <div class="text-field">
        <span class="tf-label">Diagnosis:</span>
        <span class="tf-value"><?= esc($record['diagnosis'] ?? '') ?></span>
    </div>

    <!-- Treatment Plan -->
    <div class="text-field">
        <span class="tf-label">Treatment Plan:</span>
        <span class="tf-value multiline"><?= esc($record['treatment_plan'] ?? '') ?></span>
    </div>

    <!-- Allergies note if present -->
    <?php if (!empty($record['allergies'])): ?>
    <div style="margin-top:6px;font-size:8.5pt;">
        <strong>⚠ Allergies:</strong> <?= esc($record['allergies']) ?>
    </div>
    <?php endif; ?>

</div>
<!-- ══════════════════════ END PAGE 1 ══════════════════════ -->


<!-- ══════════════════════ PAGE 2 — BACK ══════════════════════ -->
<div class="page">

    <!-- Back page header — clinic contact -->
    <div class="back-header">
        <strong><?= esc($_cs['name']) ?></strong>
        <?php if ($_cs['address'] || $_cs['phone']): ?>
        &nbsp;·&nbsp; <?= esc(implode(' · ', array_filter([$_cs['address'], $_cs['phone'], $_cs['email']]))) ?>
        <?php endif; ?>
        &nbsp;·&nbsp; Patient: <strong><?= esc($record['patient_name']) ?></strong>
        &nbsp;·&nbsp; <?= esc($record['patient_code']) ?>
    </div>

    <!-- Running Treatment Log -->
    <table class="visit-table">
        <thead>
            <tr>
                <th class="col-date">Date</th>
                <th class="col-treatment">Treatment Rendered</th>
                <th class="col-fee">Fee</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($back_rows as $r): ?>
        <tr>
            <td class="col-date">
                <span class="visit-date"><?= date('m/d/y', strtotime($r['visit_date'])) ?></span>
            </td>
            <td class="col-treatment">
                <span class="visit-treatment">
                    <?php
                    $svc_part = !empty($r['service_name']) ? esc($r['service_name']) : '';
                    $tx_part  = !empty($r['treatment']) ? esc($r['treatment']) : '';
                    if ($svc_part && $tx_part) {
                        echo '<strong>' . $svc_part . '</strong><br><span style="font-size:8.5pt;color:#333;">' . nl2br($tx_part) . '</span>';
                    } elseif ($svc_part) {
                        echo '<strong>' . $svc_part . '</strong>';
                    } else {
                        echo nl2br($tx_part);
                    }
                    ?>
                </span>
            </td>
            <td class="col-fee">
                <?php if (!empty($r['fee']) && $r['fee'] > 0): ?>
                <span class="visit-fee">₱<?= number_format($r['fee'], 2) ?></span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>

        <!-- Empty rows for future visits -->
        <?php for ($i = 0; $i < $empty_rows; $i++): ?>
        <tr class="empty-row"><td class="col-date">&nbsp;</td><td class="col-treatment"></td><td class="col-fee"></td></tr>
        <?php endfor; ?>

        <!-- Total row -->
        <?php
        $total_fee = array_sum(array_column($back_rows, 'fee'));
        ?>
        <?php if ($total_fee > 0): ?>
        <tr class="total-row">
            <td class="col-date" colspan="2" style="text-align:right;padding-right:8px;">Total Charged:</td>
            <td class="col-fee">₱<?= number_format($total_fee, 2) ?></td>
        </tr>
        <?php endif; ?>
        </tbody>
    </table>

    <div class="back-footer">
        Printed: <?= date('M d, Y h:i A') ?> &nbsp;·&nbsp; Record ID: <?= $id ?> &nbsp;·&nbsp; <?= esc($_cs['name']) ?>
    </div>

</div>
<!-- ══════════════════════ END PAGE 2 ══════════════════════ -->

<?php if ($autoprint): ?>
<script>
window.addEventListener('load', function() {
    setTimeout(function(){ window.print(); }, 400);
});
</script>
<?php endif; ?>

</body>
</html>
