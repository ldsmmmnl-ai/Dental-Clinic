<?php
require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/clinic_settings.php';
$_cs = clinic_settings($conn);

$bill_id = intval($_GET['bill_id'] ?? $_GET['id'] ?? 0);
if (!$bill_id) { header('Location: ../billing/list.php'); exit(); }

// Fetch bill with all related info
$bill_stmt = $conn->prepare("
    SELECT b.*,
           CONCAT(p.first_name,' ',p.last_name) as patient_name,
           p.patient_code, p.address, p.phone, p.email,
           COALESCE((SELECT GROUP_CONCAT(ms_x.service_name ORDER BY ms_x.id SEPARATOR ', ') FROM `bill_services` ms_x WHERE ms_x.`bill_id` = b.id), s.service_name) AS service_name, s.price as service_price, s.duration_minutes,
           a.appointment_code, a.appointment_date, a.appointment_time,
           u.full_name as received_by_name
    FROM bills b
    LEFT JOIN patients p ON b.patient_id = p.id
    LEFT JOIN services s ON b.service_id = s.id
    LEFT JOIN appointments a ON b.appointment_id = a.id
    LEFT JOIN users u ON b.created_by = u.id
    WHERE b.id = ? LIMIT 1
");
$bill_stmt->execute([$bill_id]);
$bill = $bill_stmt->fetch(PDO::FETCH_ASSOC);

if (!$bill) { header('Location: ../billing/list.php'); exit(); }

// Fetch linked dental record (same patient + appointment)
$dental = null;
if ($bill['patient_id']) {
    if ($bill['appointment_id']) {
        // Try to get the dental record linked to this specific appointment
        $dr_stmt = $conn->prepare(
            "SELECT * FROM dental_records WHERE patient_id = ? AND appointment_id = ? ORDER BY created_at DESC LIMIT 1"
        );
        $dr_stmt->execute([$bill['patient_id'], $bill['appointment_id']]);
    } else {
        // Fallback: latest dental record for this patient
        $dr_stmt = $conn->prepare(
            "SELECT * FROM dental_records WHERE patient_id = ? ORDER BY created_at DESC LIMIT 1"
        );
        $dr_stmt->execute([$bill['patient_id']]);
    }
    $dental = $dr_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $dr_stmt->closeCursor();
}

$balance  = $bill['amount_due'] - $bill['amount_paid'];

// Payment history (one row per payment)
$pay_history = [];
ms_reconcile_payments($conn, $bill_id);   // bills paid before payment history existed get one "earlier payments" line
try {
    $ph = $conn->prepare("SELECT * FROM bill_payments WHERE bill_id = ? ORDER BY paid_at ASC, id ASC");
    $ph->execute([$bill_id]);
    $pay_history = $ph->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* run database/migration_payment_history.sql */ }
$or_num   = 'OR-' . str_pad($bill_id, 5, '0', STR_PAD_LEFT);
$is_paid  = $balance <= 0;

// ADA code map — common dental procedure codes
$ada_codes = [
    'Consultation / Check-up'  => 'D0120',
    'Oral Prophylaxis'         => 'D1110',
    'Tooth Restoration'        => 'D2140',
    'Root Canal Treatment'     => 'D3330',
    'Odontectomy / Surgery'    => 'D7210',
    'Orthodontic Treatment'    => 'D8080',
    'Prosthodontic Treatment'  => 'D5110',
    'Dental Checkup'           => 'D0120',
    'Dental Cleaning'          => 'D1110',
    'Teeth Whitening'          => 'D9975',
    'Tooth Extraction'         => 'D7140',
    'Tooth Filling'            => 'D2140',
    'Root Canal'               => 'D3330',
    'X-Ray'                    => 'D0220',
    'Fluoride Treatment'       => 'D1208',
    'Orthodontic Consultation' => 'D8660',
    'Dentures'                 => 'D5110',
];
$bill_svcs = ms_get($conn, 'bill_services', 'bill_id', $bill_id, (int)($bill['service_id'] ?? 0) ?: null);
$multi_svc = count($bill_svcs) > 1;
$ada = $multi_svc ? '—' : ($ada_codes[$bill['service_name'] ?? ''] ?? '—');
?><!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>assets/images/favicon.svg">
    <meta charset="UTF-8">
    <title>Itemized Receipt — <?php echo e($or_num); ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/print.css">
    <style>
    body { font-family: 'DM Sans', sans-serif; background: #f1f5f9; margin: 0; padding: 0; }

    .toolbar {
        position: fixed; top: 0; left: 0; right: 0; z-index: 100;
        background: #fff; border-bottom: 1px solid #e2e8f0;
        padding: 12px 24px; display: flex; gap: 10px; align-items: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.07);
    }
    .toolbar button, .toolbar a {
        padding: 8px 18px; border-radius: 8px; font-size: 0.85rem;
        font-weight: 600; cursor: pointer; text-decoration: none;
        display: inline-flex; align-items: center; gap: 6px; border: none;
    }
    .btn-print  { background: #2563eb; color: #fff; }
    .btn-pdf    { background: #fff; color: #2563eb; border: 1.5px solid #2563eb !important; }
    .btn-back   { background: #f8fafc; color: #64748b; border: 1.5px solid #e2e8f0 !important; }
    .toolbar-title { font-weight: 700; color: #0f172a; font-size: 0.95rem; margin-left: auto; }

    /* Receipt card */
    .receipt-wrap { padding: 90px 24px 40px; display: flex; justify-content: center; }
    .receipt {
        background: #fff; width: 100%; max-width: 560px;
        border-radius: 16px; box-shadow: 0 4px 24px rgba(0,0,0,0.10);
        overflow: hidden;
    }

    /* Header band */
    .receipt-header {
        background: linear-gradient(135deg, #1d4ed8, #1e3a8a);
        padding: 28px 32px; display: flex; justify-content: space-between; align-items: flex-start;
    }
    .clinic-name { font-size: 1.3rem; font-weight: 800; color: #fff; letter-spacing: -0.02em; }
    .clinic-sub  { font-size: 0.78rem; color: rgba(255,255,255,0.65); margin-top: 3px; }
    .receipt-meta { text-align: right; }
    .or-number { font-size: 1rem; font-weight: 700; color: #fff; }
    .or-date   { font-size: 0.75rem; color: rgba(255,255,255,0.65); margin-top: 3px; }

    /* Status badge */
    .status-band {
        padding: 10px 32px;
        font-size: 0.8rem; font-weight: 700; text-align: center;
        letter-spacing: 0.05em; text-transform: uppercase;
    }
    .status-paid    { background: #dcfce7; color: #166534; }
    .status-partial { background: #fef9c3; color: #854d0e; }
    .status-unpaid  { background: #fee2e2; color: #991b1b; }

    /* Body */
    .receipt-body { padding: 24px 32px; }

    .section-label {
        font-size: 0.68rem; font-weight: 700; letter-spacing: 0.1em;
        text-transform: uppercase; color: #94a3b8; margin-bottom: 10px;
        padding-bottom: 6px; border-bottom: 1px solid #f1f5f9;
    }
    .info-row {
        display: flex; justify-content: space-between;
        font-size: 0.83rem; padding: 4px 0;
    }
    .info-row .label { color: #64748b; }
    .info-row .value { font-weight: 600; color: #0f172a; text-align: right; max-width: 60%; }

    /* Itemized table */
    .item-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 0.82rem; }
    .item-table th {
        background: #f8fafc; color: #64748b; font-size: 0.72rem;
        font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;
        padding: 8px 10px; text-align: left; border-bottom: 1px solid #e2e8f0;
    }
    .item-table th:last-child { text-align: right; }
    .item-table td { padding: 10px 10px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
    .item-table td:last-child { text-align: right; font-weight: 700; white-space: nowrap; }
    .ada-badge {
        display: inline-block; background: #eff6ff; color: #1d4ed8;
        font-size: 0.68rem; font-weight: 700; padding: 2px 7px;
        border-radius: 4px; margin-top: 3px; letter-spacing: 0.04em;
    }
    .tooth-info { font-size: 0.75rem; color: #64748b; margin-top: 3px; }

    /* Totals */
    .totals { background: #f8fafc; border-radius: 10px; padding: 14px 16px; margin-bottom: 20px; }
    .total-row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 0.85rem; }
    .total-row .tl { color: #64748b; }
    .total-row .tv { font-weight: 600; }
    .total-final {
        display: flex; justify-content: space-between;
        border-top: 2px solid #e2e8f0; margin-top: 8px; padding-top: 10px;
        font-weight: 800; font-size: 1rem;
    }

    /* Clinical notes */
    .notes-box {
        background: #f8fafc; border-radius: 8px; padding: 12px 14px;
        font-size: 0.8rem; color: #475569; margin-bottom: 20px;
        border-left: 3px solid #2563eb;
    }
    .notes-box strong { display: block; margin-bottom: 4px; color: #1e40af; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; }

    /* Signature */
    .sig-row { display: flex; gap: 24px; margin-bottom: 20px; }
    .sig-item { flex: 1; text-align: center; }
    .sig-line { border-bottom: 1.5px solid #cbd5e1; height: 40px; margin-bottom: 6px; }
    .sig-label { font-size: 0.72rem; color: #94a3b8; }

    /* Footer */
    .receipt-footer {
        background: #f8fafc; border-top: 1px solid #e2e8f0;
        padding: 14px 32px; text-align: center;
        font-size: 0.75rem; color: #94a3b8; line-height: 1.8;
    }

    @media print {
        @page { size: 80mm auto; margin: 4mm 3mm; }
        .toolbar { display: none !important; }
        body { background: #fff; font-size: 10px; }
        .receipt-wrap { padding: 0; display: block; }
        .receipt { max-width: 100%; box-shadow: none; border-radius: 0; font-size: 10px; }
        .receipt-header { padding: 8px 10px; }
        .clinic-name { font-size: 11px; }
        .clinic-sub  { font-size: 8px; }
        .or-number   { font-size: 10px; }
        .or-date     { font-size: 8px; }
        .status-band { padding: 5px 10px; font-size: 8px; }
        .receipt-body { padding: 8px 10px; }
        .section-label { font-size: 7px; margin-bottom: 5px; padding-bottom: 3px; }
        .info-row { font-size: 9px; padding: 2px 0; }
        .item-table { font-size: 8px; }
        .item-table th { padding: 4px 6px; font-size: 7px; }
        .item-table td { padding: 5px 6px; }
        .ada-badge   { font-size: 7px; padding: 1px 4px; }
        .tooth-info  { font-size: 7px; }
        .totals { padding: 8px 10px; border-radius: 0; }
        .total-row { font-size: 9px; padding: 2px 0; }
        .total-final { font-size: 10px; padding-top: 5px; margin-top: 4px; }
        .notes-box { font-size: 8px; padding: 6px 8px; margin-bottom: 8px; }
        .notes-box strong { font-size: 7px; margin-bottom: 2px; }
        .sig-row { gap: 10px; margin-bottom: 10px; }
        .sig-line { height: 24px; margin-bottom: 3px; }
        .sig-label { font-size: 7px; }
        .receipt-footer { padding: 6px 10px; font-size: 7px; line-height: 1.5; }
    }
    </style>
</head>
<body>

<!-- Toolbar -->
<div class="toolbar">
    <button class="btn-print" onclick="window.print()">🖨️ Print</button>
    <button class="btn-pdf" onclick="downloadPDF()">📄 Download PDF</button>
    <a class="btn-back" href="../billing/view.php?id=<?php echo $bill_id; ?>">← Back to Bill</a>
    <span class="toolbar-title">Receipt — <?php echo e($or_num); ?></span>
</div>

<!-- Receipt -->
<div class="receipt-wrap">
<div class="receipt" id="receipt-doc">

    <!-- Header -->
    <div class="receipt-header">
        <div>
            <div style="display:flex;align-items:center;gap:12px;">
                <?php echo $_cs['logo_html']; ?>
                <div>
                    <div class="clinic-name"><?php echo e($_cs['name']); ?></div>
                    <div class="clinic-sub">Official Itemized Receipt</div>
                </div>
            </div>
        </div>
        <div class="receipt-meta">
            <div class="or-number"><?php echo e($or_num); ?></div>
            <div class="or-date"><?php echo date('M d, Y  h:i A', strtotime($bill['created_at'])); ?></div>
            <div class="or-date">Bill: <?php echo e($bill['bill_code']); ?></div>
        </div>
    </div>

    <!-- Status Band -->
    <?php if ($is_paid): ?>
        <div class="status-band status-paid">✅ PAID IN FULL</div>
    <?php elseif ($bill['amount_paid'] > 0): ?>
        <div class="status-band status-partial">⚠ PARTIAL PAYMENT — BALANCE REMAINING</div>
    <?php else: ?>
        <div class="status-band status-unpaid">⛔ UNPAID</div>
    <?php endif; ?>

    <div class="receipt-body">

        <!-- Patient Info -->
        <div class="section-label">Billed To</div>
        <div class="info-row"><span class="label">Patient Name</span><span class="value"><?php echo e($bill['patient_name']); ?></span></div>
        <div class="info-row"><span class="label">Patient Code</span><span class="value"><?php echo e($bill['patient_code']); ?></span></div>
        <?php if ($bill['phone']): ?>
        <div class="info-row"><span class="label">Phone</span><span class="value"><?php echo e($bill['phone']); ?></span></div>
        <?php endif; ?>
        <?php if ($bill['appointment_code']): ?>
        <div class="info-row"><span class="label">Appointment</span><span class="value"><?php echo e($bill['appointment_code']); ?> — <?php echo $bill['appointment_date'] ? date('M d, Y', strtotime($bill['appointment_date'])) : ''; ?></span></div>
        <?php endif; ?>
        <div style="margin-bottom:20px;"></div>

        <!-- Itemized Service -->
        <div class="section-label">Itemized Services</div>
        <table class="item-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th>ADA Code</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($multi_svc):
                    $svc_sum = 0.0;
                    foreach ($bill_svcs as $__sv): $svc_sum += (float)$__sv['price']; $__ada = $ada_codes[$__sv['service_name']] ?? '—'; ?>
                <tr>
                    <td><strong><?php echo e($__sv['service_name']); ?></strong></td>
                    <td><span class="ada-badge"><?php echo $__ada; ?></span></td>
                    <td></td>
                </tr>
                <?php endforeach; ?>
                <?php if ($dental && ($dental['tooth_number'] || $dental['diagnosis'] || $dental['treatment_done'] || $bill['appointment_date'])): ?>
                <tr>
                    <td colspan="3">
                        <?php if ($dental['tooth_number']): ?><div class="tooth-info">🦷 Tooth / Area: <?php echo e($dental['tooth_number']); ?></div><?php endif; ?>
                        <?php if ($dental['diagnosis']): ?><div class="tooth-info">Diagnosis: <?php echo e($dental['diagnosis']); ?></div><?php endif; ?>
                        <?php if ($dental['treatment_done']): ?><div class="tooth-info">Procedure: <?php echo e($dental['treatment_done']); ?></div><?php endif; ?>
                        <?php if ($bill['appointment_date']): ?><div class="tooth-info">Visit Date: <?php echo date('M d, Y', strtotime($bill['appointment_date'])); ?></div><?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
                <?php else: ?>
                <tr>
                    <td>
                        <strong><?php echo e($bill['service_name'] ?? 'Dental Service'); ?></strong>
                        <?php if ($dental && $dental['tooth_number']): ?>
                            <div class="tooth-info">🦷 Tooth / Area: <?php echo e($dental['tooth_number']); ?></div>
                        <?php endif; ?>
                        <?php if ($dental && $dental['diagnosis']): ?>
                            <div class="tooth-info">Diagnosis: <?php echo e($dental['diagnosis']); ?></div>
                        <?php endif; ?>
                        <?php if ($dental && $dental['treatment_done']): ?>
                            <div class="tooth-info">Procedure: <?php echo e($dental['treatment_done']); ?></div>
                        <?php endif; ?>
                        <?php if ($bill['appointment_date']): ?>
                            <div class="tooth-info">Visit Date: <?php echo date('M d, Y', strtotime($bill['appointment_date'])); ?></div>
                        <?php endif; ?>
                        <?php if ($ada !== '—'): ?>
                            <div><span class="ada-badge"><?php echo $ada; ?></span></div>
                        <?php endif; ?>
                    </td>
                    <td><span class="ada-badge"><?php echo $ada; ?></span></td>
                    <td>₱<?php echo number_format($bill['amount_due'], 2); ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Totals -->
        <div class="totals">
            <div class="total-row">
                <span class="tl">Total Bill</span>
                <span class="tv">₱<?php echo number_format($bill['amount_due'], 2); ?></span>
            </div>
            <?php if ($pay_history): ?>
            <div class="total-row" style="margin-top:4px;"><span class="tl"><strong>Payment History</strong></span><span class="tv"></span></div>
            <?php $run_bal = (float)$bill['amount_due']; foreach ($pay_history as $ph_row):
                $ph_amt = (float)$ph_row['amount']; $run_bal = round($run_bal - $ph_amt, 2); ?>
            <div class="total-row" style="font-size:0.78rem;">
                <span class="tl"><?php echo date('m/d/Y', strtotime($ph_row['paid_at'])); ?> · <?php echo e(ucfirst($ph_row['payment_method'] ?? '')); ?><?php echo $ph_amt < 0 ? ' (cancelled)' : ''; ?></span>
                <span class="tv"><?php echo $ph_amt < 0 ? '−' : ''; ?>₱<?php echo number_format(abs($ph_amt), 2); ?> <span style="color:#64748b;font-weight:400;">(bal ₱<?php echo number_format(max($run_bal,0), 2); ?>)</span></span>
            </div>
            <?php endforeach; endif; ?>
            <div class="total-row">
                <span class="tl">Amount Paid</span>
                <span class="tv" style="color:#16a34a;">₱<?php echo number_format($bill['amount_paid'], 2); ?></span>
            </div>
            <div class="total-row">
                <span class="tl">Payment Method</span>
                <span class="tv"><?php echo match($bill['payment_method']) {
                    'cash'  => '💵 Cash',
                    'gcash' => '📱 GCash',
                    'bank'  => '🏦 Bank Transfer',
                    default => ucfirst($bill['payment_method'])
                }; ?></span>
            </div>
            <?php if ($bill['payment_ref']): ?>
            <div class="total-row">
                <span class="tl">Reference No.</span>
                <span class="tv"><?php echo e($bill['payment_ref']); ?></span>
            </div>
            <?php endif; ?>
            <div class="total-final">
                <span><?php echo $is_paid ? '✅ Balance' : '⚠ Remaining Balance'; ?></span>
                <span style="color:<?php echo $is_paid ? '#16a34a' : '#dc2626'; ?>;">
                    ₱<?php echo number_format(max($balance, 0), 2); ?>
                </span>
            </div>
        </div>

        <!-- Clinical Notes (if any) -->
        <?php if ($dental && ($dental['medications_prescribed'] || $dental['next_visit_notes'])): ?>
        <div class="section-label">Clinical Notes</div>
        <?php if ($dental['medications_prescribed']): ?>
        <div class="notes-box">
            <strong>💊 Medications Prescribed</strong>
            <?php echo e($dental['medications_prescribed']); ?>
        </div>
        <?php endif; ?>
        <?php if ($dental['next_visit_notes']): ?>
        <div class="notes-box">
            <strong>📅 Next Visit Instructions</strong>
            <?php echo e($dental['next_visit_notes']); ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($bill['notes']): ?>
        <div class="notes-box" style="margin-bottom:20px;">
            <strong>📝 Billing Notes</strong>
            <?php echo e($bill['notes']); ?>
        </div>
        <?php endif; ?>

        <!-- Signatures -->
        <div class="sig-row">
            <div class="sig-item">
                <div class="sig-line"></div>
                <div class="sig-label">Patient Signature</div>
            </div>
            <div class="sig-item">
                <div class="sig-line"></div>
                <div class="sig-label">Received by: <?php echo e($bill['received_by_name'] ?? ''); ?></div>
            </div>
        </div>

    </div><!-- end receipt-body -->

    <!-- Footer -->
    <div class="receipt-footer">
        Thank you for choosing <?php echo e($_cs['name']); ?>! 🦷<br>
        <?php echo e($or_num); ?> &nbsp;·&nbsp; Generated: <?php echo date('M d, Y h:i A'); ?><br>
        <em>Keep this receipt for your insurance records. ADA codes are standard dental billing codes.</em>
    </div>

</div><!-- end receipt -->
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function downloadPDF() {
    var el = document.getElementById('receipt-doc');
    html2pdf().set({
        margin:        [6, 6, 6, 6],
        filename:      '<?php echo $or_num; ?>.pdf',
        image:         { type: 'jpeg', quality: 0.98 },
        html2canvas:   { scale: 2, useCORS: true },
        jsPDF:         { unit: 'mm', format: [80, 200], orientation: 'portrait' }
    }).from(el).save();
}
</script>
</body>
</html>