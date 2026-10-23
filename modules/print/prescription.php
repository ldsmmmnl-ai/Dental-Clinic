<?php
// modules/print/prescription.php
// Printable prescription — laid out to match the clinic's paper prescription pad:
//   logo + clinic name + dentist  →  address / contact numbers  →  heavy rule
//   Patient / Age · Address / Sex · Rx / Date  →  large faint tooth watermark
//   footer: clinic hours (left)  |  dentist name, License No., PTR No. (right)
require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/clinic_settings.php';
require_once '../../includes/doctor_ptr.php';
$_cs = clinic_settings($conn);

$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: ../treatments/list.php'); exit(); }

// doctors.ptr_number is added automatically on older databases; if that ever fails
// we still print (PTR line just stays blank) instead of crashing.
$has_ptr = ensure_doctor_ptr_column($conn);
$ptr_sel = $has_ptr ? 'd.ptr_number' : 'NULL AS ptr_number';

$stmt = $conn->prepare("
    SELECT dr.*, s.service_name,
           CONCAT(p.first_name,' ',p.last_name) AS patient_name,
           p.patient_code, p.date_of_birth, p.gender, p.address AS patient_address,
           d.full_name AS doctor_name, d.license_number, $ptr_sel, d.specialization AS doctor_spec
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

// Records saved without an appointment have no doctor attached — use the first active dentist.
if (empty($record['doctor_name'])) {
    $ptr_sel2 = $has_ptr ? 'ptr_number' : 'NULL AS ptr_number';
    $fb = $conn->query("SELECT full_name, license_number, $ptr_sel2 FROM doctors WHERE is_active = 1 ORDER BY id ASC LIMIT 1")
               ->fetch(PDO::FETCH_ASSOC);
    if ($fb) {
        $record['doctor_name']    = $fb['full_name'];
        $record['license_number'] = $fb['license_number'];
        $record['ptr_number']     = $fb['ptr_number'];
    }
}

$age    = $record['date_of_birth'] ? date_diff(date_create($record['date_of_birth']), date_create('today'))->y : '';
$rx_num = 'RX-' . str_pad($id, 5, '0', STR_PAD_LEFT);
$sex    = ucfirst($record['gender'] ?? '');
$date   = date('M j, Y', strtotime($record['visit_date']));
$meds   = trim($record['medications_prescribed'] ?? '');
$diag   = trim($record['diagnosis'] ?? '');
$notes  = trim($record['next_visit_notes'] ?? '');

// "Dr. Maria Santos" / "Maria Santos" / "Maria Santos, D.M.D." → "DR. MARIA SANTOS, D.M.D."
$raw_doc  = trim($record['doctor_name'] ?? '');
$bare_doc = trim(preg_replace('/^dr\.?\s+/i', '', preg_replace('/,?\s*d\.?m\.?d\.?$/i', '', $raw_doc)));
$doc_line = $bare_doc !== '' ? 'DR. ' . mb_strtoupper($bare_doc) . ', D.M.D.' : '';

$clinic_name = mb_strtoupper($_cs['name']);
$license_no  = trim($record['license_number'] ?? '');
$ptr_no      = trim($record['ptr_number'] ?? '');
$logo_src    = !empty($_cs['logo_url']) ? $_cs['logo_url'] : BASE_URL . 'assets/images/amparo-tooth-logo.png';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Prescription — <?php echo e($rx_num); ?></title>
<link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>assets/images/favicon.svg">
<style>
/* ── SCREEN TOOLBAR ── */
* { box-sizing: border-box; }
body { margin:0; padding:0; background:#e8ecf0; font-family:'Segoe UI',Arial,sans-serif; }
.toolbar { position:fixed;top:0;left:0;right:0;z-index:100;background:#fff;
           border-bottom:1px solid #dde;padding:10px 20px;display:flex;gap:8px;align-items:center;
           box-shadow:0 2px 6px rgba(0,0,0,0.08); }
.toolbar button,.toolbar a {
    padding:7px 16px;border-radius:7px;font-size:0.83rem;font-weight:600;
    cursor:pointer;text-decoration:none;border:none;display:inline-flex;align-items:center;gap:5px; }
.btn-print { background:#1d4ed8;color:#fff; }
.btn-back  { background:#f1f5f9;color:#475569;border:1px solid #cbd5e1!important; }
.page-wrap { padding:72px 12px 40px;display:flex;justify-content:center;overflow-x:auto; }

/* ── RX PAPER — half-letter portrait (5.5 × 8.5 in), same as the clinic's pad ── */
.rx-paper {
    position:relative;
    background:#fff;
    width:5.5in;
    height:8.5in;
    flex:0 0 auto;
    padding:.34in .4in .32in;
    display:flex;
    flex-direction:column;
    color:#111;
    font-family:Arial, Helvetica, sans-serif;
    box-shadow:0 6px 28px rgba(0,0,0,0.18);
    overflow:hidden;
}

/* ── Header: logo + clinic name + dentist ── */
.hdr-top   { display:flex; align-items:center; gap:.14in; }
.hdr-logo  { width:.82in; height:.85in; object-fit:contain; flex:0 0 auto; }
.hdr-titles{ flex:1; text-align:center; }
.hdr-clinic{ margin:0; font-size:17pt; font-weight:900; letter-spacing:.01em; line-height:1.05; text-transform:uppercase; }
.hdr-doctor{ margin:.05in 0 0; font-size:12pt; font-weight:400; letter-spacing:-.01em; }

/* address (left) · numbers (right) */
.hdr-contact { display:flex; justify-content:space-between; align-items:flex-start; gap:.15in;
               margin-top:.05in; font-size:7.6pt; line-height:1.3; }
.hdr-contact .addr { max-width:2.4in; }
.hdr-contact .nums { text-align:left; white-space:nowrap; }
.rule { border:none; border-top:3px solid #111; margin:.06in 0 .12in; }

/* ── Patient block ── */
.pt-grid  { display:grid; grid-template-columns:1fr 1.45in; column-gap:.22in; row-gap:.07in; align-items:end; }
.field    { display:flex; align-items:flex-end; gap:4px; font-size:8.5pt; min-width:0; }
.field .lbl { flex:0 0 auto; }
.field .val { flex:1; min-width:0; border-bottom:1px solid #111; min-height:.2in; padding:0 3px 1px;
              font-size:10pt; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.rx-mark  { font-family:Georgia,'Times New Roman',serif; font-weight:700; font-size:34pt; line-height:.9;
            position:relative; display:inline-block; padding-top:.04in; }
.rx-mark .x { font-size:24pt; font-weight:400; position:absolute; left:.2in; bottom:.0in; }

/* ── Body (watermark + prescription text) ── */
.rx-body  { position:relative; flex:1; margin-top:.1in; }
.rx-water { position:absolute; left:50%; top:46%; width:2.7in; height:auto; transform:translate(-50%,-50%);
            z-index:0; pointer-events:none; }
.rx-content { position:relative; z-index:1; padding:0 .05in; }
.rx-meds  { font-size:11pt; line-height:1.9; white-space:pre-wrap; min-height:1in; }
.rx-empty { color:#9a9a9a; font-style:italic; font-size:9pt; }
.rx-extra { margin-top:.12in; font-size:9pt; line-height:1.55; white-space:pre-wrap; }
.rx-extra b { font-size:7pt; text-transform:uppercase; letter-spacing:.06em; color:#555; display:block; margin-bottom:1px; }

/* ── Footer: clinic hours · dentist / license / PTR ── */
.rx-foot  { display:flex; justify-content:space-between; align-items:flex-end; gap:.2in; font-size:8pt; line-height:1.45; }
.hours b  { display:block; font-size:8pt; letter-spacing:.01em; }
.sign     { width:2.35in; }
.sign .doc-name { border-top:2px solid #111; padding-top:.03in; font-weight:700; font-size:8.5pt; }
.sign .row { display:flex; align-items:flex-end; gap:4px; margin-top:.02in; }
.sign .row .v { flex:1; min-height:.15in; padding:0 2px; font-weight:600; }
.sign .row .v.ul { border-bottom:1px solid #111; }
.rx-no    { position:absolute; left:0; right:0; bottom:.1in; text-align:center; font-size:6pt; color:#9a9a9a; letter-spacing:.08em; }

/* ── PRINT ── */
@page { size:5.5in 8.5in; margin:0; }
@media print {
    .toolbar { display:none!important; }
    html, body { background:#fff; }
    .page-wrap { padding:0; display:block; overflow:visible; }
    .rx-paper { box-shadow:none; page-break-inside:avoid; break-inside:avoid; }
}
</style>
</head>
<body>

<div class="toolbar">
    <button class="btn-print" onclick="window.print()">🖨️ Print</button>
    <a class="btn-back" href="javascript:history.back()">← Back</a>
    <span style="margin-left:auto;font-size:0.82rem;color:#64748b;">
        <?php echo e($rx_num); ?> &nbsp;·&nbsp; <?php echo e($date); ?>
    </span>
</div>

<div class="page-wrap">
<div class="rx-paper">

    <!-- ════ HEADER ════ -->
    <div class="hdr-top">
        <img class="hdr-logo" src="<?php echo e($logo_src); ?>" alt="">
        <div class="hdr-titles">
            <h1 class="hdr-clinic"><?php echo e($clinic_name); ?></h1>
            <?php if ($doc_line): ?><p class="hdr-doctor"><?php echo e($doc_line); ?></p><?php endif; ?>
        </div>
    </div>

    <div class="hdr-contact">
        <div class="addr"><?php echo e($_cs['address']); ?></div>
        <div class="nums">
            <?php if (!empty($_cs['phone'])): ?>Mobile No.: <?php echo e($_cs['phone']); ?><br><?php endif; ?>
            <?php if (!empty($_cs['landline'])): ?>Landline No.: <?php echo e($_cs['landline']); ?><?php endif; ?>
        </div>
    </div>
    <hr class="rule">

    <!-- ════ PATIENT ════ -->
    <div class="pt-grid">
        <div class="field"><span class="lbl">Patient:</span><span class="val"><?php echo e($record['patient_name']); ?></span></div>
        <div class="field"><span class="lbl">Age:</span><span class="val"><?php echo e((string)$age); ?></span></div>

        <div class="field"><span class="lbl">Address:</span><span class="val"><?php echo e($record['patient_address'] ?? ''); ?></span></div>
        <div class="field"><span class="lbl">Sex:</span><span class="val"><?php echo e($sex); ?></span></div>

        <div><span class="rx-mark">R<span class="x">x</span></span></div>
        <div class="field"><span class="lbl">Date:</span><span class="val"><?php echo e($date); ?></span></div>
    </div>

    <!-- ════ PRESCRIPTION AREA ════ -->
    <div class="rx-body">
        <!-- faint tooth watermark (same motif as the pad) -->
        <svg class="rx-water" viewBox="0 0 200 270" aria-hidden="true">
            <g fill="none" stroke="#e2e2e2" stroke-width="7" stroke-linejoin="round" stroke-linecap="round">
                <path d="M100,44 C90,24 56,14 38,40 C22,64 34,102 42,132 C48,166 48,226 66,248
                         C80,264 90,226 96,192 C98,182 102,182 104,192 C110,226 120,264 134,248
                         C152,226 152,166 158,132 C166,102 178,64 162,40 C144,14 110,24 100,44 Z"/>
                <path d="M72,48 C86,36 98,42 106,48" stroke-width="5"/>
            </g>
        </svg>
        <div class="rx-content">
            <div class="rx-meds"><?php
                if ($meds) echo e($meds);
                else echo '<span class="rx-empty">No medications prescribed for this visit.</span>';
            ?></div>
            <?php if ($notes): ?>
            <div class="rx-extra"><b>Instructions / Follow-up</b><?php echo e($notes); ?></div>
            <?php endif; ?>
            <?php if ($diag): ?>
            <div class="rx-extra"><b>Diagnosis</b><?php echo e($diag); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ════ FOOTER ════ -->
    <div class="rx-foot">
        <div class="hours">
            <b>CLINIC HOURS:</b>
            Monday to Saturday<br>
            9:00 AM - 5:00 PM<br>
            Sunday - by appointment
        </div>
        <div class="sign">
            <div class="doc-name"><?php echo e($doc_line); ?></div>
            <div class="row"><span>License No.</span><span class="v"><?php echo e($license_no); ?></span></div>
            <div class="row"><span>PTR No.</span><span class="v ul"><?php echo e($ptr_no); ?></span></div>
        </div>
    </div>

    <div class="rx-no"><?php echo e($rx_num); ?></div>

</div><!-- /rx-paper -->
</div><!-- /page-wrap -->

</body>
</html>