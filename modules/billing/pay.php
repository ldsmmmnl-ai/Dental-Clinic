<?php
// Record an additional payment against an existing unpaid or partial bill.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

$current_user_id = $_SESSION['user_id'] ?? 0;
$current_user_name = $current_user_name ?? $_SESSION['full_name'] ?? 'System';

$page_title = 'Record Payment';
$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: list.php'); exit(); }

// If opened from patient profile, remember where to send Cancel/Back/success
$from_patient = intval($_GET['from_patient'] ?? 0);
$return_url   = $from_patient
    ? BASE_URL . 'modules/patients/view.php?id=' . $from_patient
    : 'view.php?id=' . $id;

$bill_stmt = $conn->prepare("
    SELECT b.*, CONCAT(p.first_name,' ',p.last_name) as patient_name,
           p.patient_code, COALESCE((SELECT GROUP_CONCAT(ms_x.service_name ORDER BY ms_x.id SEPARATOR ', ') FROM `bill_services` ms_x WHERE ms_x.`bill_id` = b.id), s.service_name) AS service_name
    FROM bills b
    LEFT JOIN patients p ON b.patient_id = p.id
    LEFT JOIN services s ON b.service_id = s.id
    WHERE b.id = ? AND b.status != 'paid' LIMIT 1
");
$bill_stmt->execute([$id]);
$bill = $bill_stmt->fetch(PDO::FETCH_ASSOC);
$bill_stmt->closeCursor();

if (!$bill) { header('Location: list.php'); exit(); }

$balance = $bill['amount_due'] - $bill['amount_paid'];
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validate_csrf();
        $add_payment    = round(floatval($_POST['add_payment'] ?? 0), 2);
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $gcash_ref      = trim($_POST['gcash_ref'] ?? '');
    $bank_ref       = trim($_POST['bank_ref']  ?? '');
    $payment_ref    = $gcash_ref ?: $bank_ref ?: '';
    // Date the patient really paid (defaults to today; no future dates)
    $pay_date = trim($_POST['pay_date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pay_date) || $pay_date > date('Y-m-d')) $pay_date = date('Y-m-d');

    // Whitelist payment method
    $allowed_methods = ['cash', 'gcash', 'bank', 'other'];
    if (!in_array($payment_method, $allowed_methods)) $payment_method = 'cash';

    if ($add_payment <= 0) {
        $error = 'Please enter a valid payment amount.';
    } elseif ($add_payment > $balance + 0.01) {
        $error = 'Payment amount cannot exceed the remaining balance of ₱' . number_format($balance, 2) . '.';
    } else {
        // Make sure payments made BEFORE payment history existed are in the history first
        ms_reconcile_payments($conn, $id);

        $new_paid = $bill['amount_paid'] + $add_payment;
        $new_status = $new_paid >= $bill['amount_due'] ? 'paid' : 'partial';

        $stmt = $conn->prepare("
            UPDATE bills
            SET amount_paid = ?, payment_method = ?, payment_ref = ?, status = ?
            WHERE id = ?
        ");
        $stmt->execute([$new_paid, $payment_method, $payment_ref, $new_status, $id]);

        if ($stmt->rowCount() < 1) {
            $error = 'Failed to record payment. Please try again.';
        } else {
            // Payment history: one row for THIS payment (date, amount, method, reference)
            ms_log_payment($conn, $id, $add_payment, $payment_method, $payment_ref, null, (int)$current_user_id, $pay_date);
            log_action($conn, $current_user_id, $current_user_name,
                'Recorded Payment', 'billing', $id,
                "Bill: {$bill['bill_code']} | Added: ₱$add_payment | Method: $payment_method | New status: $new_status"
            );

            // ── Notification trigger ──────────────────────────────
            $pname = $bill['patient_name'] ?? 'Patient';
            $bcode = $bill['bill_code'];
            if ($new_status === 'paid') {
                notify($conn, 'payment', 'Bill Fully Paid',
                    "$pname's bill $bcode is now fully paid. Total: ₱" . number_format($bill['amount_due'], 2) . ".",
                    'modules/billing/list.php');
            } else {
                $remaining = $bill['amount_due'] - $new_paid;
                notify($conn, 'payment', 'Payment Recorded',
                    "₱" . number_format($add_payment, 2) . " received from $pname. Remaining balance: ₱" . number_format($remaining, 2) . ". Bill: $bcode.",
                    'modules/billing/list.php');
            }
            // ─────────────────────────────────────────────────────

            header('Location: ' . $return_url);
            exit();
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?></head>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">

        <div class="page-header">
            <div>
                <h5>Record Payment</h5>
                <p>Bill: <?php echo e($bill['bill_code']); ?> — <?php echo e($bill['patient_name']); ?></p>
            </div>
            <a href="<?php echo htmlspecialchars($return_url); ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-x-circle-fill"></i> <?php echo e($error); ?></div>
        <?php endif; ?>

        <div class="card" style="max-width:520px;">
            <div class="card-header"><i class="bi bi-cash" style="color:var(--success)"></i> Payment Details</div>
            <div class="card-body">

                <!-- Current balance info -->
                <div style="background:var(--blue-50);border:1px solid var(--blue-100);border-radius:8px;padding:14px;margin-bottom:20px;">
                    <div style="display:flex;justify-content:space-between;font-size:0.875rem;margin-bottom:6px;">
                        <span style="color:var(--gray-600);">Service</span>
                        <span style="font-weight:600;"><?php echo e($bill['service_name'] ?? '—'); ?></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:0.875rem;margin-bottom:6px;">
                        <span style="color:var(--gray-600);">Total Bill</span>
                        <span style="font-weight:600;">₱<?php echo number_format($bill['amount_due'],2); ?></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:0.875rem;margin-bottom:6px;">
                        <span style="color:var(--gray-600);">Already Paid</span>
                        <span style="font-weight:600;color:var(--success);">₱<?php echo number_format($bill['amount_paid'],2); ?></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:1rem;border-top:1px solid var(--blue-200);padding-top:8px;margin-top:4px;">
                        <span style="font-weight:700;color:var(--danger);">Remaining Balance</span>
                        <span style="font-weight:800;color:var(--danger);font-family:'Outfit',sans-serif;">₱<?php echo number_format($balance,2); ?></span>
                    </div>
                </div>

                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Amount to Pay (₱) <span style="color:var(--danger)">*</span></label>
                            <input type="number" name="add_payment" id="add_payment" class="form-control"
                                step="0.01" min="0.01" max="<?php echo $balance; ?>"
                                placeholder="Enter amount" oninput="showChange()"
                                required autofocus>
                            <div id="change_display" style="margin-top:6px;font-size:0.82rem;color:var(--gray-500);"></div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Payment Method</label>
                            <select name="payment_method" id="method_select" class="form-select" onchange="toggleRef(this.value)">
                                <option value="cash">💵 Cash</option>
                                <option value="gcash">📱 GCash</option>
                                <option value="bank">🏦 Bank Transfer</option>
                                <option value="other">💳 Other</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Payment Date</label>
                            <input type="date" name="pay_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>">
                            <small style="color:var(--gray-400);font-size:0.72rem;">The date the patient paid — change it if you are entering an earlier payment.</small>
                        </div>
                        <div class="col-12" id="gcash_ref_row" style="display:none;">
                            <!-- GCash QR code panel -->
                            <div id="gcash_qr_panel" style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:12px;padding:16px;margin-bottom:14px;text-align:center;">
                                <div style="font-size:0.72rem;font-weight:700;color:#15803d;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:6px;">
                                    <i class="bi bi-qr-code"></i> Scan to Pay via GCash
                                </div>
                                <div style="font-size:0.8rem;color:#166534;margin-bottom:12px;">Open GCash app → Scan QR → Pay</div>
                                <div id="gcash_qr_canvas" style="display:inline-block;background:#fff;padding:10px;border-radius:8px;border:1px solid #d1fae5;"></div>
                                <div style="font-size:0.72rem;color:#15803d;margin-top:10px;font-weight:600;">
                                    Amount: <span id="gcash_qr_amount" style="color:#166534;">₱—</span>
                                </div>
                            </div>
                            <label class="form-label">GCash Reference No. <span style="color:var(--gray-400);font-weight:400;">(after paying)</span></label>
                            <input type="text" name="gcash_ref" class="form-control" placeholder="e.g. 1234567890">
                        </div>
                        <div class="col-12" id="bank_ref_row" style="display:none;">
                            <label class="form-label">Bank Reference No.</label>
                            <input type="text" name="bank_ref" class="form-control" placeholder="e.g. Transfer ref number">
                        </div>
                    </div>
                    <div style="display:flex;gap:10px;margin-top:20px;">
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-lg"></i> Confirm Payment
                        </button>
                        <a href="<?php echo htmlspecialchars($return_url); ?>" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

    </div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
var balance = <?php echo $balance; ?>;

// ── Clinic GCash number — change this to the real clinic number ──
var CLINIC_GCASH = '09XXXXXXXXX';

var qrInstance = null;

function showChange() {
    var paid   = parseFloat(document.getElementById('add_payment').value) || 0;
    var disp   = document.getElementById('change_display');
    var remain = balance - paid;
    if (paid <= 0) { disp.textContent = ''; return; }
    if (paid >= balance) {
        disp.innerHTML = '<span style="color:var(--success);font-weight:600;">✅ Full payment — no balance remaining</span>';
    } else {
        disp.innerHTML = 'Remaining balance after this payment: <strong style="color:var(--danger);">₱' + remain.toFixed(2) + '</strong>';
    }
    updateGcashQR(paid);
}

function updateGcashQR(amount) {
    var canvas  = document.getElementById('gcash_qr_canvas');
    var amtSpan = document.getElementById('gcash_qr_amount');
    if (!canvas) return;

    // QR data in GCash-compatible format
    var qrData = 'GCASH:' + CLINIC_GCASH + ':' + (amount > 0 ? amount.toFixed(2) : '0.00');

    if (amtSpan) {
        amtSpan.textContent = amount > 0 ? '₱' + amount.toFixed(2) : '₱—';
    }

    // Clear old QR and render new one
    canvas.innerHTML = '';
    if (typeof QRCode !== 'undefined') {
        new QRCode(canvas, {
            text: qrData,
            width: 160,
            height: 160,
            colorDark: '#166534',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M
        });
    }
}

function toggleRef(method) {
    var gcashRow = document.getElementById('gcash_ref_row');
    var bankRow  = document.getElementById('bank_ref_row');
    gcashRow.style.display = method === 'gcash' ? 'block' : 'none';
    bankRow.style.display  = method === 'bank'  ? 'block' : 'none';

    // When GCash is selected, generate QR with current amount immediately
    if (method === 'gcash') {
        var paid = parseFloat(document.getElementById('add_payment').value) || 0;
        updateGcashQR(paid);
    }
}

// Update QR when amount changes while GCash is selected
document.getElementById('add_payment').addEventListener('input', function() {
    if (document.getElementById('method_select').value === 'gcash') {
        var paid = parseFloat(this.value) || 0;
        updateGcashQR(paid);
    }
});
</script>
</div>
<?php include '../../includes/footer.php'; ?>
</body>
</html>