<?php
require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/clinic_settings.php';
$_cs = clinic_settings($conn);

$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: ../treatments/list.php'); exit(); }

$stmt_record = $conn->prepare("
    SELECT dr.*, COALESCE((SELECT GROUP_CONCAT(ms_x.service_name ORDER BY ms_x.id SEPARATOR ', ') FROM `dental_record_services` ms_x WHERE ms_x.`dental_record_id` = dr.id), s.service_name) AS service_name,
           CONCAT(p.first_name,' ',p.last_name) as patient_name,
           p.patient_code, p.date_of_birth, p.gender, p.phone, p.address, p.occupation, p.civil_status,
           d.full_name as doctor_name,
           d.specialization as doctor_spec,
           u.full_name as recorded_by_name
    FROM dental_records dr
    LEFT JOIN patients p ON dr.patient_id = p.id
    LEFT JOIN services s ON dr.service_id = s.id
    LEFT JOIN appointments a ON dr.appointment_id = a.id
    LEFT JOIN doctors d ON a.doctor_id = d.id
    LEFT JOIN users u ON dr.recorded_by = u.id
    WHERE dr.id = ? LIMIT 1\n");
$stmt_record->execute([$id]);
$record = $stmt_record->fetch(PDO::FETCH_ASSOC);

if (!$record) { header('Location: ../treatments/list.php'); exit(); }
$age = $record['date_of_birth'] ? date_diff(date_create($record['date_of_birth']), date_create('today'))->y : '—';
$cert_num = 'DC-' . str_pad($id, 5, '0', STR_PAD_LEFT);
?><!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>assets/images/favicon.svg">
    <meta charset="UTF-8">
    <title>Dental Certificate — <?php echo e($cert_num); ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/print.css">
    <style>
    body { font-family: 'DM Sans', sans-serif; background: #f1f5f9; margin: 0; }
    .toolbar { position:fixed;top:0;left:0;right:0;z-index:100;background:#fff;border-bottom:1px solid #e2e8f0;padding:12px 24px;display:flex;gap:10px;align-items:center;box-shadow:0 2px 8px rgba(0,0,0,0.07); }
    .toolbar button,.toolbar a { padding:8px 18px;border-radius:8px;font-size:0.85rem;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px;border:none; }
    .btn-print { background:#2563eb;color:#fff; }
    .btn-pdf   { background:#fff;color:#2563eb;border:1.5px solid #2563eb !important; }
    .btn-back  { background:#f8fafc;color:#64748b;border:1.5px solid #e2e8f0 !important; }
    .wrap { padding:90px 24px 40px;display:flex;justify-content:center; }
    .cert { background:#fff;width:100%;max-width:560px;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,0.10);overflow:hidden; }
    .cert-header { padding:28px 32px;text-align:center;border-bottom:3px double #1d4ed8; }
    .clinic-name { font-size:1.3rem;font-weight:800;color:#1e3a8a;letter-spacing:-0.02em; }
    .clinic-sub  { font-size:0.78rem;color:#64748b;margin-top:3px; }
    .cert-title  { font-size:1rem;font-weight:700;color:#0f172a;margin-top:16px;letter-spacing:.08em;text-transform:uppercase; }
    .cert-num    { font-size:0.75rem;color:#94a3b8;margin-top:4px; }
    .cert-body   { padding:24px 32px; }
    .to-whom     { font-size:0.85rem;color:#64748b;font-style:italic;margin-bottom:16px; }
    .cert-para   { font-size:0.88rem;color:#0f172a;line-height:1.9;margin-bottom:16px; }
    .cert-para strong { color:#1d4ed8; }
    .info-table  { width:100%;border-collapse:collapse;margin-bottom:20px;font-size:0.83rem; }
    .info-table td { padding:6px 10px;border-bottom:1px solid #f1f5f9; }
    .info-table td:first-child { color:#64748b;width:38%; }
    .info-table td:last-child { font-weight:600;color:#0f172a; }
    .finding-box { background:#eff6ff;border-left:4px solid #1d4ed8;border-radius:0 8px 8px 0;padding:14px 16px;margin-bottom:20px;font-size:0.85rem;color:#1e3a8a;white-space:pre-wrap;line-height:1.7; }
    .recommendation-box { background:#f0fdf4;border-left:4px solid #16a34a;border-radius:0 8px 8px 0;padding:14px 16px;margin-bottom:20px;font-size:0.85rem;color:#14532d;white-space:pre-wrap;line-height:1.7; }
    .sig-section { margin-top:30px;display:flex;justify-content:flex-end; }
    .sig-block   { text-align:center;min-width:200px; }
    .sig-line    { border-bottom:1.5px solid #cbd5e1;height:48px;margin-bottom:6px; }
    .sig-name    { font-size:0.85rem;font-weight:700;color:#0f172a; }
    .sig-sub     { font-size:0.72rem;color:#64748b; }
    .cert-footer { background:#f8fafc;border-top:1px solid #e2e8f0;padding:12px 32px;text-align:center;font-size:0.72rem;color:#94a3b8;line-height:1.8; }
    .stamp-area  { display:inline-block;width:100px;height:100px;border:2px dashed #cbd5e1;border-radius:50%;line-height:100px;font-size:0.7rem;color:#cbd5e1;text-align:center;vertical-align:middle; }
    @media print { .toolbar{display:none!important}body{background:#fff}.wrap{padding:0}.cert{box-shadow:none;border-radius:0} }
    </style>
</head>
<body>
<div class="toolbar">
    <button class="btn-print" onclick="window.print()">🖨️ Print</button>
    <button class="btn-pdf" onclick="downloadCertPDF()">📄 Download PDF</button>
    <a class="btn-back" href="../treatments/list.php">← Back</a>
    <span style="margin-left:auto;font-weight:700;color:#0f172a;font-size:0.9rem;">Med. Certificate — <?php echo e($cert_num); ?></span>
</div>

<div class="wrap">
<div class="cert" id="certDoc">

    <div class="cert-header">
        <div style="display:flex;align-items:center;gap:12px;">
            <?php echo $_cs['logo_html']; ?>
            <div>
                <div class="clinic-name"><?php echo e($_cs['name']); ?></div>
                <div class="clinic-sub"><?php echo e($_cs['subtitle']); ?></div>
                <?php if ($_cs['address'] || $_cs['phone']): ?>
                <div style="font-size:0.71rem;color:#94a3b8;margin-top:2px;"><?php
                    $parts = array_filter([$_cs['address'],$_cs['phone'],$_cs['email']]);
                    echo e(implode(' · ', $parts));
                ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="cert-title"> Dental Certificate</div>
        <div class="cert-num"><?php echo e($cert_num); ?></div>
    </div>

    <div class="cert-body">
        <div class="to-whom">To Whom It May Concern:</div>

        <div class="cert-para">
            This is to certify that <strong><?php echo e($record['patient_name']); ?></strong>,
            <?php echo $age; ?> year<?php echo $age != 1 ? 's' : ''; ?> old,
            <?php echo ucfirst($record['gender'] ?? ''); ?>,
            <?php echo $record['occupation'] ? 'occupation: ' . e($record['occupation']) . ',' : ''; ?>
            was examined at this clinic on
            <strong><?php echo date('F d, Y', strtotime($record['visit_date'])); ?></strong>.
        </div>

        <table class="info-table">
            <tr><td>Patient Code</td><td><?php echo e($record['patient_code']); ?></td></tr>
            <tr><td>Date of Visit</td><td><?php echo date('F d, Y', strtotime($record['visit_date'])); ?></td></tr>
            <tr><td>Service Availed</td><td><?php echo e($record['service_name'] ?? '—'); ?></td></tr>
            <?php if ($record['tooth_number']): ?>
            <tr><td>Tooth / Area Treated</td><td><?php echo e($record['tooth_number']); ?></td></tr>
            <?php endif; ?>
        </table>

        <?php if ($record['chief_complaint']): ?>
        <div style="font-size:0.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;margin-bottom:6px;">Chief Complaint</div>
        <div class="finding-box"><?php echo e($record['chief_complaint']); ?></div>
        <?php endif; ?>

        <?php if ($record['diagnosis']): ?>
        <div style="font-size:0.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;margin-bottom:6px;">Clinical Findings / Diagnosis</div>
        <div class="finding-box"><?php echo e($record['diagnosis']); ?></div>
        <?php endif; ?>

        <?php if ($record['treatment_done']): ?>
        <div style="font-size:0.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;margin-bottom:6px;">Treatment Rendered</div>
        <div class="recommendation-box"><?php echo e($record['treatment_done']); ?></div>
        <?php endif; ?>

        <?php if ($record['next_visit_notes']): ?>
        <div class="cert-para">
            <strong>Recommendations:</strong> <?php echo e($record['next_visit_notes']); ?>
        </div>
        <?php endif; ?>

        <div class="cert-para">
            This certificate is issued upon the patient's request for whatever legal purpose it may serve.
        </div>

        <div class="sig-section">
            <div style="display:flex;align-items:flex-end;gap:20px;">
                <div class="stamp-area">Official<br>Stamp</div>
                <div class="sig-block">
                    <div class="sig-line"></div>
                    <div class="sig-name">Dr. <?php echo e($record['doctor_name'] ?? $record['recorded_by_name'] ?? 'Attending Dentist'); ?></div>
                    <div class="sig-sub"><?php echo e($record['doctor_spec'] ?? 'Dentist'); ?></div>
                    <div class="sig-sub">PRC Lic. No. ________________</div>
                    <div class="sig-sub">PTR No. ________________</div>
                </div>
            </div>
        </div>
    </div>

    <div class="cert-footer">
        <?php echo e($_cs['name']); ?> &nbsp;·&nbsp; <?php echo e($cert_num); ?> &nbsp;·&nbsp; Issued: <?php echo date('M d, Y h:i A'); ?><br>
        <em>This document is computer-generated and valid without signature if bearing the official dry seal.</em>
    </div>

</div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function downloadCertPDF() {
    html2pdf().set({
        margin: [6,6,6,6],
        filename: '<?php echo $cert_num; ?>.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
    }).from(document.getElementById('certDoc')).save();
}
</script>
</body>
</html>
