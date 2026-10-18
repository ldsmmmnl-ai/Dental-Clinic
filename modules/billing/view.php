<?php
// View a bill in detail with patient info, service, and payment status.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

$page_title = 'View Bill';
$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: list.php'); exit(); }

$bv_stmt = $conn->prepare("
    SELECT b.*,
           CONCAT(p.first_name,' ',p.last_name) as patient_name,
           p.patient_code, p.phone, p.address, p.email,
           s.service_name, s.duration_minutes,
           a.appointment_code, a.appointment_date, a.appointment_time,
           u.full_name as created_by_name
    FROM bills b
    LEFT JOIN patients p ON b.patient_id = p.id
    LEFT JOIN services s ON b.service_id = s.id
    LEFT JOIN appointments a ON b.appointment_id = a.id
    LEFT JOIN users u ON b.created_by = u.id
    WHERE b.id = ? LIMIT 1
");
$bv_stmt->execute([$id]);
$bill = $bv_stmt->fetch(PDO::FETCH_ASSOC);
$bv_stmt->closeCursor();

if (!$bill) { header('Location: list.php'); exit(); }

// ── Correct / cancel a payment that was entered wrongly ─────────────────────
$fix_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_correct_payment'])) {
    validate_csrf();
    $new_paid = round((float)($_POST['new_paid'] ?? -1), 2);
    $reason   = mb_substr(trim($_POST['fix_reason'] ?? ''), 0, 255);
    $old_paid = (float)$bill['amount_paid'];
    $due      = (float)$bill['amount_due'];

    if ($reason === '') {
        $fix_error = 'Please type a reason for the correction.';
    } elseif ($new_paid < 0 || $new_paid > $due + 0.004) {
        $fix_error = 'The amount paid must be between ₱0.00 and the amount due (₱' . number_format($due, 2) . ').';
    } elseif (abs($new_paid - $old_paid) < 0.005) {
        $fix_error = 'That is the same amount already recorded — nothing to change.';
    } else {
        $new_status = bill_status_for($due, $new_paid);
        $conn->prepare("UPDATE bills SET amount_paid = ?, status = ?, payment_ref = IF(? <= 0, NULL, payment_ref) WHERE id = ?")
             ->execute([$new_paid, $new_status, $new_paid, $id]);
        $uid  = $_SESSION['user_id'] ?? 0;
        $uname = $_SESSION['full_name'] ?? 'System';
        log_action($conn, $uid, $uname, 'Corrected Payment', 'billing', $id,
            "Bill: {$bill['bill_code']} | Paid changed ₱" . number_format($old_paid, 2) . " → ₱" . number_format($new_paid, 2) .
            " | New status: $new_status | Reason: $reason");
        notify($conn, 'payment', 'Payment Corrected',
            "$uname changed the paid amount on bill {$bill['bill_code']} from ₱" . number_format($old_paid, 2) .
            " to ₱" . number_format($new_paid, 2) . ". Reason: $reason",
            'modules/billing/view.php?id=' . $id);
        header('Location: view.php?id=' . $id . '&corrected=1');
        exit();
    }
}

$balance   = $bill['amount_due'] - $bill['amount_paid'];
$corrected = isset($_GET['corrected']);
$created   = isset($_GET['created']);
$flow_done = isset($_GET['flow']) && $_GET['flow'] === 'done';
?><!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?>
<style>
/* ════════════════════════════════════════
   Billing View — Mobile Responsiveness
   Grid was 1fr 340px with NO breakpoint.
   ════════════════════════════════════════ */
.bill-layout {
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 18px;
    align-items: start;
}
@media (max-width: 768px) {
    /* Stack billing details + patient sidebar vertically */
    .bill-layout {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">

        <div class="page-header" style="margin-bottom:20px;">
            <div>
                <h5><?php echo e($bill['bill_code']); ?></h5>
                <small class="text-muted">
                    Created <?php echo date('M d, Y g:i A', strtotime($bill['created_at'])); ?>
                    by <?php echo e($bill['created_by_name']); ?>
                </small>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <a href="<?php echo BASE_URL; ?>modules/print/payment_receipt.php?bill_id=<?php echo $id; ?>"
                   class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-printer me-1"></i> Print Receipt
                </a>
                <?php if ($bill['status'] !== 'paid'): ?>
                <a href="pay.php?id=<?php echo $id; ?>" class="btn btn-sm btn-success">
                    <i class="bi bi-cash me-1"></i> Record Payment
                </a>
                <?php endif; ?>
                <a href="list.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back
                </a>
            </div>
        </div>

        <?php if ($corrected): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle-fill me-2"></i>Payment corrected. The bill now shows the new amount paid. Use <strong>Record Payment</strong> to add any remaining payment.</div>
        <?php endif; ?>

        <?php if ($created && $flow_done): ?>
        <div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:12px;padding:20px 22px;margin-bottom:20px;">
            <div style="display:flex;align-items:center;gap:14px;">
                <div style="width:44px;height:44px;background:#22c55e;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;">✅</div>
                <div>
                    <div style="font-weight:700;font-size:0.95rem;color:#14532d;">Patient Visit Complete</div>
                    <div style="font-size:0.82rem;color:#166534;margin-top:2px;">Appointment → Treatment recorded → Bill created. All steps done.</div>
                </div>
            </div>
            <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap;">
                <a href="<?php echo BASE_URL; ?>modules/appointments/list.php" class="btn btn-sm btn-success">
                    <i class="bi bi-calendar-check me-1"></i> Back to Appointments
                </a>
                <a href="<?php echo BASE_URL; ?>modules/print/payment_receipt.php?bill_id=<?php echo $id; ?>"
                   class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-printer me-1"></i> Print Receipt
                </a>
                <a href="<?php echo BASE_URL; ?>modules/walkin/add.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-person-walking me-1"></i> Next Walk-in
                </a>
            </div>
        </div>
        <?php elseif ($created): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle-fill me-2"></i>Bill created successfully.</div>
        <?php endif; ?>

        <div class="bill-layout">

            <!-- LEFT: Bill details -->
            <div style="display:flex;flex-direction:column;gap:18px;">

                <!-- Status + amounts -->
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-receipt me-2" style="color:var(--primary);"></i>Payment Summary
                    </div>
                    <div class="card-body">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px;">
                            <span class="badge bg-<?php echo match($bill['status']) {
                                'paid'    => 'success',
                                'partial' => 'warning',
                                'unpaid'  => 'danger',
                                default   => 'secondary'
                            }; ?>" style="font-size:0.85rem;padding:7px 18px;letter-spacing:0.04em;">
                                <?php echo strtoupper($bill['status']); ?>
                            </span>
                            <span style="font-size:0.78rem;color:var(--gray-400);">
                                <?php if ($bill['appointment_code']): ?>
                                    Linked to <?php echo e($bill['appointment_code']); ?>
                                    &nbsp;·&nbsp; <?php echo date('M d, Y', strtotime($bill['appointment_date'])); ?>
                                <?php else: ?>
                                    No linked appointment
                                <?php endif; ?>
                            </span>
                        </div>

                        <!-- Amount breakdown -->
                        <div style="background:var(--gray-50);border-radius:10px;padding:16px;margin-bottom:16px;">
                            <div style="display:flex;justify-content:space-between;font-size:0.875rem;margin-bottom:8px;">
                                <span style="color:var(--gray-500);">Amount Due</span>
                                <span style="font-weight:700;color:var(--gray-900);">₱<?php echo number_format($bill['amount_due'],2); ?></span>
                            </div>
                            <div style="display:flex;justify-content:space-between;font-size:0.875rem;margin-bottom:8px;">
                                <span style="color:var(--gray-500);">Amount Paid</span>
                                <span style="font-weight:700;color:var(--success);">₱<?php echo number_format($bill['amount_paid'],2); ?></span>
                            </div>
                            <div style="border-top:1px solid var(--border);padding-top:10px;margin-top:4px;display:flex;justify-content:space-between;font-size:1rem;">
                                <span style="font-weight:700;">Balance</span>
                                <span style="font-weight:700;color:<?php echo $balance > 0 ? 'var(--danger)' : 'var(--success)'; ?>;">
                                    <?php echo $balance > 0 ? '₱'.number_format($balance,2) : 'Fully Paid ✅'; ?>
                                </span>
                            </div>
                        </div>

                        <!-- Payment method -->
                        <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--gray-100);font-size:0.875rem;">
                            <span style="color:var(--gray-500);">Payment Method</span>
                            <span style="font-weight:600;">
                                <?php echo match($bill['payment_method']) {
                                    'cash'  => '💵 Cash',
                                    'gcash' => '📱 GCash',
                                    'bank'  => '🏦 Bank Transfer',
                                    default => ucfirst($bill['payment_method'])
                                }; ?>
                            </span>
                        </div>
                        <?php if ($bill['payment_ref']): ?>
                        <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--gray-100);font-size:0.875rem;">
                            <span style="color:var(--gray-500);">Reference No.</span>
                            <span style="font-weight:600;"><?php echo e($bill['payment_ref']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($bill['service_name']): ?>
                        <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--gray-100);font-size:0.875rem;">
                            <span style="color:var(--gray-500);">Service</span>
                            <span style="font-weight:600;"><?php echo e($bill['service_name']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($bill['notes']): ?>
                        <div style="padding:8px 0;font-size:0.875rem;">
                            <div style="color:var(--gray-500);margin-bottom:4px;">Notes</div>
                            <div style="color:var(--gray-700);"><?php echo e($bill['notes']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Cancel / correct a wrong payment -->
                <?php if ((float)$bill['amount_paid'] > 0): ?>
                <div class="card" style="border-color:#fecaca;">
                    <div class="card-header" style="cursor:pointer;" onclick="var b=document.getElementById('fixPayBody');b.style.display=b.style.display==='none'?'block':'none';">
                        <i class="bi bi-arrow-counterclockwise me-2" style="color:var(--danger);"></i>Wrong amount? Cancel / correct payment
                    </div>
                    <div class="card-body" id="fixPayBody" style="display:<?php echo $fix_error ? 'block' : 'none'; ?>;">
                        <?php if ($fix_error): ?><div class="alert alert-danger py-2"><?php echo e($fix_error); ?></div><?php endif; ?>
                        <p style="font-size:0.82rem;color:var(--gray-600);margin-bottom:10px;">
                            Currently recorded as paid: <strong>₱<?php echo number_format($bill['amount_paid'],2); ?></strong> of ₱<?php echo number_format($bill['amount_due'],2); ?>.
                            Type what the patient <em>actually</em> paid so far. Use <strong>0</strong> to cancel the payment completely, then record it again.
                            This change is saved in the audit log with your reason.
                        </p>
                        <p style="font-size:0.76rem;color:var(--gray-500);margin-bottom:10px;">
                            Tip: if the amount was typed in a <em>Return Visit</em> on the patient profile, cancel it there instead (Visit Log → “Cancel payment”) so the log and the bill stay matching.
                        </p>
                        <form method="POST" onsubmit="return confirm('Change the amount paid on this bill?');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="_correct_payment" value="1">
                            <div class="row g-2 mb-2">
                                <div class="col-sm-4">
                                    <label class="form-label" style="font-size:0.72rem;font-weight:700;">Correct amount paid (₱)</label>
                                    <input type="number" name="new_paid" class="form-control form-control-sm" step="0.01" min="0"
                                           max="<?php echo e($bill['amount_due']); ?>" required placeholder="0.00">
                                </div>
                                <div class="col-sm-8">
                                    <label class="form-label" style="font-size:0.72rem;font-weight:700;">Reason *</label>
                                    <input type="text" name="fix_reason" class="form-control form-control-sm" maxlength="255" required
                                           placeholder="e.g. typed 800 instead of 200">
                                </div>
                            </div>
                            <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-arrow-counterclockwise me-1"></i>Save correction</button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

            </div>

            <!-- RIGHT: Patient info -->
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-person-fill me-2" style="color:var(--primary);"></i>Patient
                </div>
                <div class="card-body">
                    <div style="font-family:'Outfit',sans-serif;font-weight:700;font-size:1rem;margin-bottom:2px;">
                        <?php echo e($bill['patient_name']); ?>
                    </div>
                    <div style="font-size:0.78rem;color:var(--gray-400);margin-bottom:14px;">
                        <?php echo e($bill['patient_code']); ?>
                    </div>
                    <div style="font-size:0.84rem;display:flex;flex-direction:column;gap:7px;margin-bottom:16px;">
                        <?php if ($bill['phone']): ?>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <i class="bi bi-telephone" style="color:var(--primary);width:16px;"></i>
                            <?php echo e($bill['phone']); ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($bill['email']): ?>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <i class="bi bi-envelope" style="color:var(--primary);width:16px;"></i>
                            <?php echo e($bill['email']); ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($bill['address']): ?>
                        <div style="display:flex;align-items:flex-start;gap:8px;">
                            <i class="bi bi-geo-alt" style="color:var(--primary);width:16px;margin-top:2px;"></i>
                            <span><?php echo e($bill['address']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <a href="../patients/view.php?id=<?php echo $bill['patient_id']; ?>"
                       class="btn btn-sm btn-outline-primary w-100">
                        <i class="bi bi-person me-1"></i> View Patient Profile
                    </a>
                </div>
            </div>

        </div>

    </div>
</div>
<?php include '../../includes/footer.php'; ?>
</body>
</html>
