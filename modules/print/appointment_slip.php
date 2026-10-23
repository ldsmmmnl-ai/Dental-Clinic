<?php
// print/appointment_slip.php
require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/clinic_settings.php';
$_cs = clinic_settings($conn);

$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: ../appointments/list.php'); exit(); }

$appt_stmt = $conn->prepare("
    SELECT a.*, CONCAT(p.first_name,' ',p.last_name) as patient_name,
           p.patient_code, p.phone, p.address,
           COALESCE((SELECT GROUP_CONCAT(ms_x.service_name ORDER BY ms_x.id SEPARATOR ', ') FROM `appointment_services` ms_x WHERE ms_x.`appointment_id` = a.id), s.service_name) AS service_name, s.price,
           u.full_name as staff_name
    FROM appointments a
    LEFT JOIN patients p ON a.patient_id = p.id
    LEFT JOIN services s ON a.service_id = s.id
    LEFT JOIN users u ON a.handled_by = u.id
    WHERE a.id = ? LIMIT 1
");
$appt_stmt->execute([$id]);
$appt = $appt_stmt->fetch(PDO::FETCH_ASSOC);

if (!$appt) { header('Location: ../appointments/list.php'); exit(); }
?><!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>assets/images/favicon.svg">
    <meta charset="UTF-8">
    <title>Appointment Slip — <?php echo e($appt['appointment_code']); ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/print.css">
</head>
<body>

<div class="print-toolbar">
    <button onclick="window.print()" style="padding:8px 16px;background:#2563eb;color:#fff;border:none;border-radius:8px;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:6px;">
        🖨️ Print Slip
    </button>
    <button onclick="downloadPDF('apptSlip','<?php echo e($appt["appointment_code"]); ?>')" style="padding:8px 16px;background:#fff;color:#2563eb;border:1.5px solid #2563eb;border-radius:8px;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:6px;">
        📄 Download PDF
    </button>
    <a href="../appointments/list.php" style="padding:8px 16px;background:#fff;color:#64748b;border:1.5px solid #e2e8f0;border-radius:8px;text-decoration:none;">
        ← Back
    </a>
</div>

<div class="print-page" id="apptSlip">
    <div class="doc-header">
        <div class="clinic-info">
            <div class="clinic-logo"><?php echo $_cs['logo_html']; ?></div>
            <div>
                <div class="clinic-name"><?php echo e($_cs['name']); ?></div>
                <div class="clinic-sub"><?php echo e($_cs['subtitle']); ?></div>
                <?php if ($_cs['address'] || $_cs['phone']): ?>
                <div style="font-size:0.71rem;color:#94a3b8;margin-top:2px;"><?php
                    echo e(implode(' · ', array_filter([$_cs['address'], $_cs['phone']])));
                ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="doc-meta">
            <div class="doc-title">Appointment Slip</div>
            <div class="doc-code"><?php echo e($appt['appointment_code']); ?></div>
            <div class="doc-date">Printed: <?php echo date('M d, Y h:i A'); ?></div>
        </div>
    </div>

    <!-- Time Highlight -->
    <div class="highlight-box" style="text-align:center;margin-bottom:20px;">
        <div style="font-size:0.72rem;color:#64748b;text-transform:uppercase;letter-spacing:0.08em;">Appointment Schedule</div>
        <div style="font-family:'Outfit',sans-serif;font-size:2rem;font-weight:800;color:#2563eb;line-height:1.1;">
            <?php echo date('h:i A', strtotime($appt['appointment_time'])); ?>
        </div>
        <div style="font-size:0.9rem;font-weight:600;color:#334155;">
            <?php echo date('l, F d, Y', strtotime($appt['appointment_date'])); ?>
        </div>
        <div style="margin-top:6px;">
            <span class="status-badge status-<?php echo $appt['status']; ?>"><?php echo ucfirst($appt['status']); ?></span>
            <span class="status-badge" style="background:#f1f5f9;color:#64748b;margin-left:4px;"><?php echo ucfirst($appt['type']); ?></span>
        </div>
    </div>

    <div class="doc-section">
        <div class="section-title">Patient Information</div>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Patient Name</div>
                <div class="info-value"><?php echo e($appt['patient_name']); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Patient Code</div>
                <div class="info-value"><?php echo e($appt['patient_code']); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Phone</div>
                <div class="info-value"><?php echo e($appt['phone'] ?: '—'); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Address</div>
                <div class="info-value"><?php echo e($appt['address'] ?: '—'); ?></div>
            </div>
        </div>
    </div>

    <div class="doc-section">
        <div class="section-title">Service Details</div>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Service</div>
                <div class="info-value"><?php echo e($appt['service_name'] ?? '—'); ?></div>
            </div>
            <div class="info-item cols-1">
                <div class="info-label">Notes / Complaint</div>
                <div class="info-value"><?php echo e($appt['notes'] ?: '—'); ?></div>
            </div>
        </div>
    </div>

    <div class="sig-row">
        <div class="sig-item">
            <div class="sig-line"></div>
            <div class="sig-label">Patient / Guardian Signature</div>
        </div>
        <div class="sig-item">
            <div class="sig-line"></div>
            <div class="sig-label">Staff: <?php echo e($appt['staff_name'] ?? ''); ?></div>
        </div>
    </div>

    <div class="doc-footer">
        Please present this slip upon arrival. Thank you for choosing <?php echo e($_cs['name']); ?>.
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function downloadPDF(elementId, filename) {
    html2pdf().set({
        margin: 8, filename: filename + '.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2 },
        jsPDF: { unit: 'mm', format: 'a5', orientation: 'portrait' }
    }).from(document.getElementById(elementId)).save();
}
</script>
</body>
</html>