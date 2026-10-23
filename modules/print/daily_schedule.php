<?php
require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/clinic_settings.php';
$_cs = clinic_settings($conn);

$date = $_GET['date'] ?? date('Y-m-d');
$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');

$ds_stmt = $conn->prepare("
    SELECT a.*, CONCAT(p.first_name,' ',p.last_name) as patient_name,
           p.patient_code, p.phone, COALESCE((SELECT GROUP_CONCAT(ms_x.service_name ORDER BY ms_x.id SEPARATOR ', ') FROM `appointment_services` ms_x WHERE ms_x.`appointment_id` = a.id), s.service_name) AS service_name, s.price,
           u.full_name as staff_name
    FROM appointments a
    LEFT JOIN patients p ON a.patient_id = p.id
    LEFT JOIN services s ON a.service_id = s.id
    LEFT JOIN users u ON a.handled_by = u.id
    WHERE a.appointment_date = ?
    ORDER BY a.appointment_time ASC
");
$ds_stmt->execute([$date]);
$appointments = $ds_stmt->fetchAll(PDO::FETCH_ASSOC);

$total    = count($appointments);
$completed= count(array_filter($appointments, fn($a) => $a['status'] === 'completed'));
$pending  = count(array_filter($appointments, fn($a) => $a['status'] === 'pending'));
$confirmed= count(array_filter($appointments, fn($a) => $a['status'] === 'confirmed'));
?><!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>assets/images/favicon.svg">
    <meta charset="UTF-8">
    <title>Daily Schedule — <?php echo date('M d, Y', strtotime($date)); ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/print.css">
    <style>@media print { @page { size: landscape; } }</style>
</head>
<body>
<div class="print-toolbar">
    <label style="font-size:0.85rem;color:#64748b;margin-right:8px;">Date:</label>
    <input type="date" id="dateInput" value="<?php echo $date; ?>" style="padding:7px;border:1.5px solid #e2e8f0;border-radius:8px;font-family:inherit;">
    <button onclick="location.href='daily_schedule.php?date='+document.getElementById('dateInput').value" style="padding:8px 14px;background:#2563eb;color:#fff;border:none;border-radius:8px;cursor:pointer;font-family:inherit;">Go</button>
    <button onclick="window.print()" style="padding:8px 16px;background:#2563eb;color:#fff;border:none;border-radius:8px;cursor:pointer;font-family:inherit;">🖨️ Print</button>
    <button onclick="downloadPDF('dailySched','daily-schedule-<?php echo $date; ?>')" style="padding:8px 16px;background:#fff;color:#2563eb;border:1.5px solid #2563eb;border-radius:8px;cursor:pointer;font-family:inherit;">📄 PDF</button>
    <a href="../reports/index.php" style="padding:8px 16px;background:#fff;color:#64748b;border:1.5px solid #e2e8f0;border-radius:8px;text-decoration:none;">← Back</a>
</div>

<div class="print-page" id="dailySched">
    <div class="doc-header">
        <div class="clinic-info">
            
            <div style="display:flex;align-items:center;gap:10px;">
                <div><?php echo $_cs['logo_html']; ?></div>
                <div>
                <div class="clinic-name"><?php echo e($_cs['name']); ?></div>
                <div class="clinic-sub">Daily Appointment Schedule</div>
            </div></div>
        </div>
        <div class="doc-meta">
            <div class="doc-title">Daily Schedule</div>
            <div class="doc-code" style="font-size:1rem;font-weight:700;color:#0f172a;"><?php echo date('l', strtotime($date)); ?></div>
            <div class="doc-date"><?php echo date('F d, Y', strtotime($date)); ?></div>
        </div>
    </div>

    <!-- Summary -->
    <div style="display:flex;gap:12px;margin-bottom:20px;">
        <?php foreach ([['Total','#2563eb',$total],['Confirmed','#0891b2',$confirmed],['Pending','#d97706',$pending],['Completed','#16a34a',$completed]] as [$label,$color,$val]): ?>
        <div style="flex:1;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px;text-align:center;">
            <div style="font-size:1.4rem;font-weight:800;color:<?php echo $color; ?>;font-family:'Outfit',sans-serif;"><?php echo $val; ?></div>
            <div style="font-size:0.7rem;color:#64748b;text-transform:uppercase;letter-spacing:0.06em;"><?php echo $label; ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (empty($appointments)): ?>
        <div style="text-align:center;padding:40px;color:#94a3b8;">
            <div style="font-size:2rem;margin-bottom:8px;">📅</div>
            No appointments scheduled for this day.
        </div>
    <?php else: ?>
    <table class="doc-table">
        <thead>
            <tr>
                <th>Time</th>
                <th>Code</th>
                <th>Patient</th>
                <th>Phone</th>
                <th>Service</th>
                <th>Type</th>
                <th>Status</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($appointments as $a): ?>
            <tr>
                <td style="font-weight:700;color:#2563eb;white-space:nowrap;"><?php echo date('h:i A', strtotime($a['appointment_time'])); ?></td>
                <td style="font-size:0.78rem;"><?php echo e($a['appointment_code']); ?></td>
                <td>
                    <div style="font-weight:600;"><?php echo e($a['patient_name']); ?></div>
                    <div style="font-size:0.72rem;color:#94a3b8;"><?php echo e($a['patient_code']); ?></div>
                </td>
                <td><?php echo e($a['phone'] ?? '—'); ?></td>
                <td><?php echo e($a['service_name'] ?? '—'); ?></td>
                <td><span class="status-badge" style="background:#f1f5f9;color:#64748b;"><?php echo ucfirst($a['type']); ?></span></td>
                <td><span class="status-badge status-<?php echo $a['status']; ?>"><?php echo ucfirst($a['status']); ?></span></td>
                <td style="font-size:0.78rem;color:#64748b;max-width:130px;overflow-wrap:anywhere;word-break:break-word;"><?php echo e(strlen($a['notes']??'') > 40 ? substr($a['notes'],0,40).'...' : ($a['notes']??'—')); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <div class="sig-row">
        <div class="sig-item"><div class="sig-line"></div><div class="sig-label">Prepared by</div></div>
        <div class="sig-item"><div class="sig-line"></div><div class="sig-label">Dentist / Clinic Head</div></div>
    </div>

    <div class="doc-footer"><?php echo e($_cs['name']); ?> — Daily Schedule for <?php echo date('F d, Y', strtotime($date)); ?> — Printed: <?php echo date('M d, Y h:i A'); ?></div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function downloadPDF(elementId, filename) {
    html2pdf().set({ margin:6, filename:filename+'.pdf', image:{type:'jpeg',quality:0.98}, html2canvas:{scale:2}, jsPDF:{unit:'mm',format:'a4',orientation:'landscape'} }).from(document.getElementById(elementId)).save();
}
</script>
</body>
</html>