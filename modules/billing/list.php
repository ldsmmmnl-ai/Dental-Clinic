<?php
// modules/billing/list.php
// List all bills with filters and summary totals.
// ── OOP REFACTOR: data access now handled by BillingService ──────────────────

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/BillingService.php'; // ← OOP: load the service class

$page_title = 'Billing';

// ── Input sanitization ────────────────────────────────────────────────────────
$status_filter = $_GET['status']    ?? '';
$search        = trim($_GET['search'] ?? '');
$date_from     = $_GET['date_from'] ?? '';
$date_to       = $_GET['date_to']   ?? '';

$allowed_statuses = ['unpaid', 'partial', 'paid'];
if (!in_array($status_filter, $allowed_statuses, true)) $status_filter = '';
if ($date_from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = '';
if ($date_to   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = '';

$per_page = 20;
$page     = max(1, intval($_GET['page'] ?? 1));

// Build filter array — BillingService handles the WHERE clause internally
$filters = [
    'status'    => $status_filter,
    'search'    => $search,
    'date_from' => $date_from,
    'date_to'   => $date_to,
];

// ── Data access via BillingService (OOP) ─────────────────────────────────────
$billingService = new BillingService($conn);  // constructor injection

// Handle CSV export via service method
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $rows = $billingService->getExportData($filters);

    // Text cells that start with = + - @ could run as formulas when opened in Excel, so a ' is put in front
    $csv_text = static function ($v): string {
        $v = (string)$v;
        return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
    };
    $csv_num = static function ($v): string { return number_format((float)$v, 2, '.', ''); };

    log_action(
        $conn, $_SESSION['user_id'] ?? null, $_SESSION['full_name'] ?? 'Unknown',
        'Exported billing CSV', 'billing', null, count($rows) . ' bills'
    );

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="billing_export_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 byte-order mark so Excel shows the symbols correctly
    fputcsv($out, ['Bill Code','Patient','Patient Code','Service','Amount Due (PHP)',
                   'Amount Paid (PHP)','Balance (PHP)','Payment Method','Reference','Status','Date']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['bill_code'],
            $csv_text($r['patient_name']),
            $csv_text($r['patient_code']),
            $csv_text($r['service_name'] ?? '—'),
            $csv_num($r['amount_due']),
            $csv_num($r['amount_paid']),
            $csv_num($r['balance']),
            $csv_text($r['payment_method'] ?? '—'),
            $csv_text($r['payment_ref'] ?? '—'),
            $r['status'],
            $r['date_created'],
        ]);
    }
    fclose($out);
    exit;
}

$total_count = $billingService->count($filters);
$total_pages = max(1, ceil($total_count / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

// Service calls replace the raw prepare/execute list and totals queries
$bills  = $billingService->getList($filters, $per_page, $offset);
$totals = $billingService->getSummary();

// Build filter query string for pagination links
$filter_parts = [];
if ($status_filter) $filter_parts[] = 'status='    . urlencode($status_filter);
if ($search)        $filter_parts[] = 'search='    . urlencode($search);
if ($date_from)     $filter_parts[] = 'date_from=' . urlencode($date_from);
if ($date_to)       $filter_parts[] = 'date_to='   . urlencode($date_to);
$filter_qs = $filter_parts ? implode('&', $filter_parts) . '&' : '';
?><!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?>
<style>
/* ── Billing KPI Cards ─────────────────────────────────── */
.bill-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}
.bill-kpi {
    background: var(--white);
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-lg);
    padding: 20px 22px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    box-shadow: var(--shadow-sm);
    transition: box-shadow 0.2s, transform 0.2s;
    position: relative;
    overflow: hidden;
}
.bill-kpi::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    border-radius: var(--radius-lg) var(--radius-lg) 0 0;
}
.bill-kpi.blue::before   { background: linear-gradient(90deg, var(--blue-500), var(--blue-400)); }
.bill-kpi.green::before  { background: linear-gradient(90deg, var(--success), var(--success-light)); }
.bill-kpi.red::before    { background: linear-gradient(90deg, var(--danger), #f87171); }
.bill-kpi.amber::before  { background: linear-gradient(90deg, #d97706, #f59e0b); }
.bill-kpi:hover { box-shadow: var(--shadow-md); transform: translateY(-1px); }
.bill-kpi-label {
    font-size: 0.72rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--gray-400);
}
.bill-kpi-value {
    font-family: 'Sora', sans-serif;
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--gray-900);
    line-height: 1;
}
.bill-kpi-sub {
    font-size: 0.75rem;
    color: var(--gray-400);
    margin-top: 2px;
}
.bill-kpi-icon {
    position: absolute;
    top: 16px; right: 18px;
    font-size: 1.6rem;
    opacity: 0.12;
}

/* ── Progress bar ─────────────────────────────────────── */
/* collection-bar removed */

/* ── Filter bar ───────────────────────────────────────── */
.billing-filter-bar {
    background: var(--white);
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-md);
    padding: 14px 16px;
    margin-bottom: 16px;
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
    box-shadow: var(--shadow-xs);
}

/* ── Status pills ─────────────────────────────────────── */
.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    border-radius: 99px;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.03em;
}
.status-pill.paid    { background: var(--success-bg); color: var(--success); border: 1px solid var(--success-border); }
.status-pill.unpaid  { background: var(--danger-bg);  color: var(--danger);  border: 1px solid var(--danger-border); }
.status-pill.partial { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }

/* ── Bill row cards ───────────────────────────────────── */
.bill-table-wrap {
    background: var(--white);
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-lg);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
}
.bill-table-wrap table { margin: 0; }
.bill-table-wrap thead tr {
    background: var(--gray-50);
    border-bottom: 2px solid var(--gray-200);
}
.bill-table-wrap thead th {
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--gray-400);
    padding: 12px 16px;
    border: none;
}
.bill-table-wrap tbody tr {
    border-bottom: 1px solid var(--gray-100);
    transition: background 0.12s;
}
.bill-table-wrap tbody tr:last-child { border-bottom: none; }
.bill-table-wrap tbody tr:hover { background: var(--blue-50); }
.bill-table-wrap tbody td {
    padding: 14px 16px;
    vertical-align: middle;
    border: none;
    font-size: 0.85rem;
}

/* ── Method badge ─────────────────────────────────────── */
.method-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 6px;
    font-size: 0.72rem;
    font-weight: 600;
    background: var(--gray-100);
    color: var(--gray-600);
    border: 1px solid var(--gray-200);
}
.method-badge.gcash { background: #f0fdf4; color: #15803d; border-color: #bbf7d0; }
.method-badge.bank  { background: #eff6ff; color: var(--blue-600); border-color: var(--blue-200); }

/* ── Dark mode ────────────────────────────────────────── */
[data-theme="dark"] .bill-kpi,
[data-theme="dark"] .billing-filter-bar,
[data-theme="dark"] .bill-table-wrap {
    background: var(--gray-200);
    border-color: var(--gray-300);
}
[data-theme="dark"] .bill-table-wrap thead tr { background: var(--gray-100); border-color: var(--gray-300); }
[data-theme="dark"] .bill-table-wrap thead th { color: var(--gray-700); }
[data-theme="dark"] .bill-table-wrap tbody tr { border-color: var(--gray-300); }
[data-theme="dark"] .bill-table-wrap tbody tr:hover { background: rgba(77,134,240,0.08); }
[data-theme="dark"] .bill-kpi-label { color: var(--gray-600); }
[data-theme="dark"] .bill-kpi-value { color: var(--gray-900); }
[data-theme="dark"] .bill-kpi-sub { color: var(--gray-500); }
[data-theme="dark"] .method-badge { background: var(--gray-300); border-color: var(--gray-400); color: var(--gray-700); }

@media (max-width: 900px) {
    .bill-kpi-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>
</head>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">

        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h5>Billing</h5>
                <p>Manage patient payments — Cash, GCash, Bank Transfer</p>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <a href="list.php?<?php echo htmlspecialchars($filter_qs . 'export=csv', ENT_QUOTES, 'UTF-8'); ?>"
                   class="btn btn-outline-secondary btn-sm"
                   title="Download the bills that match the current filters as a CSV file">
                    <i class="bi bi-filetype-csv" aria-hidden="true"></i> Export CSV
                </a>
                <a href="create.php" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus"></i> Create Bill
                </a>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="bill-kpi-grid">
            <div class="bill-kpi blue">
                <i class="bi bi-receipt bill-kpi-icon"></i>
                <div class="bill-kpi-label">Total Bills</div>
                <div class="bill-kpi-value"><?php echo number_format($totals['total_bills']); ?></div>
                <div class="bill-kpi-sub">All time</div>
            </div>
            <div class="bill-kpi green">
                <i class="bi bi-cash-coin bill-kpi-icon"></i>
                <div class="bill-kpi-label">Total Collected</div>
                <div class="bill-kpi-value" style="font-size:1.4rem;">₱<?php echo number_format($totals['total_paid'], 0); ?></div>
                <div class="bill-kpi-sub">of ₱<?php echo number_format($totals['total_due'], 0); ?> billed</div>
            </div>
            <div class="bill-kpi red">
                <i class="bi bi-exclamation-circle bill-kpi-icon"></i>
                <div class="bill-kpi-label">Outstanding</div>
                <div class="bill-kpi-value" style="font-size:1.4rem;">₱<?php echo number_format($totals['total_outstanding'], 0); ?></div>
                <div class="bill-kpi-sub"><?php echo $totals['unpaid_count']; ?> unpaid · <?php echo $totals['partial_count']; ?> partial</div>
            </div>
            <div class="bill-kpi amber">
                <i class="bi bi-check-circle bill-kpi-icon"></i>
                <div class="bill-kpi-label">Fully Paid</div>
                <div class="bill-kpi-value"><?php echo $totals['paid_count']; ?></div>
                <div class="bill-kpi-sub">Bills settled</div>
            </div>
        </div>



        <!-- Filter Bar -->
        <form method="GET" class="billing-filter-bar">
            <input type="text" name="search" class="form-control form-control-sm" style="max-width:220px;"
                placeholder="🔍  Patient or bill code..."
                value="<?php echo e($search); ?>">
            <select name="status" aria-label="Filter by status" class="form-select form-select-sm" style="max-width:140px;">
                <option value="">All Status</option>
                <option value="unpaid"  <?php echo $status_filter === 'unpaid'  ? 'selected' : ''; ?>>Unpaid</option>
                <option value="partial" <?php echo $status_filter === 'partial' ? 'selected' : ''; ?>>Partial</option>
                <option value="paid"    <?php echo $status_filter === 'paid'    ? 'selected' : ''; ?>>Paid</option>
            </select>
            <div style="display:flex;flex-direction:column;gap:1px;">
                <label style="font-size:0.68rem;font-weight:600;color:var(--gray-500);text-transform:uppercase;letter-spacing:0.04em;line-height:1;margin-bottom:2px;">From</label>
                <input type="date" name="date_from" aria-label="From date" class="form-control form-control-sm" style="max-width:150px;"
                    value="<?php echo e($date_from); ?>">
            </div>
            <div style="display:flex;flex-direction:column;gap:1px;">
                <label style="font-size:0.68rem;font-weight:600;color:var(--gray-500);text-transform:uppercase;letter-spacing:0.04em;line-height:1;margin-bottom:2px;">To</label>
                <input type="date" name="date_to" aria-label="To date" class="form-control form-control-sm" style="max-width:150px;"
                    value="<?php echo e($date_to); ?>">
            </div>
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            <a href="list.php" class="btn btn-sm btn-outline-secondary">Clear</a>

        </form>

        <!-- Bills Table -->
        <div class="bill-table-wrap">
            <div class="mobile-card-table-wrap">
<table class="table mb-0 mobile-card-table">
                <thead>
                    <tr>
                        <th>Bill</th>
                        <th>Patient</th>
                        <th>Service</th>
                        <th>Amount Due</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bills)): ?>
                    <tr>
                        <td colspan="10" style="text-align:center;padding:60px;color:var(--gray-400);">
                            <i class="bi bi-receipt" style="font-size:2.5rem;display:block;margin-bottom:12px;opacity:0.4;"></i>
                            <div style="font-weight:600;margin-bottom:4px;">No bills found</div>
                            <div style="font-size:0.78rem;">Try adjusting your filters</div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($bills as $b):
                        $balance = $b['amount_due'] - $b['amount_paid'];
                        $method_map = [
                            'cash'  => ['label'=>'💵 Cash',  'class'=>''],
                            'gcash' => ['label'=>'📱 GCash', 'class'=>'gcash'],
                            'bank'  => ['label'=>'🏦 Bank',  'class'=>'bank'],
                            'other' => ['label'=>'💳 Other', 'class'=>''],
                        ];
                        $m = $method_map[$b['payment_method']] ?? ['label'=>ucfirst($b['payment_method']),'class'=>''];
                    ?>
                    <tr>
                        <td data-label="Bill">
                            <div style="font-weight:700;color:var(--blue-500);font-size:0.78rem;font-family:'Sora',sans-serif;">
                                <?php echo e($b['bill_code']); ?>
                            </div>
                            <?php if ($b['appointment_code']): ?>
                            <div style="font-size:0.68rem;color:var(--gray-400);"><?php echo e($b['appointment_code']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td data-label="Patient">
                            <a href="<?php echo BASE_URL; ?>modules/patients/view.php?id=<?php echo $b['patient_id']; ?>" style="font-weight:600;font-size:0.85rem;color:var(--gray-900);text-decoration:none;" onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color='var(--gray-900)'"><?php echo e(ucwords(strtolower($b['patient_name'] ?? ''))); ?></a>
                            <div style="font-size:0.72rem;color:var(--gray-400);"><?php echo e($b['patient_code']); ?></div>
                        </td>
                        <td data-label="Service" style="font-size:0.82rem;color:var(--gray-600);"><?php echo e($b['service_name'] ?? '—'); ?></td>
                        <td data-label="Amount Due" style="font-weight:600;font-size:0.85rem;">₱<?php echo number_format($b['amount_due'], 2); ?></td>
                        <td data-label="Paid" style="color:var(--success);font-weight:700;font-size:0.85rem;">
                            ₱<?php echo number_format($b['amount_paid'], 2); ?>
                        </td>
                        <td data-label="Balance" style="font-weight:700;font-size:0.85rem;color:<?php echo $balance > 0 ? 'var(--danger)' : 'var(--success)'; ?>;">
                            <?php echo $balance > 0 ? '₱'.number_format($balance,2) : '✓ Settled'; ?>
                        </td>
                        <td data-label="Method">
                            <span class="method-badge <?php echo $m['class']; ?>">
                                <?php echo $m['label']; ?>
                            </span>
                        </td>
                        <td data-label="Status">
                            <span class="status-pill <?php echo $b['status']; ?>">
                                <?php if ($b['status'] === 'paid'): ?>✓<?php elseif ($b['status'] === 'unpaid'): ?>✗<?php else: ?>◑<?php endif; ?>
                                <?php echo ucfirst($b['status']); ?>
                            </span>
                        </td>
                        <td data-label="Date" style="font-size:0.75rem;color:var(--gray-400);">
                            <?php echo date('M d, Y', strtotime($b['created_at'])); ?>
                        </td>
                        <td data-label="Actions">
                            <div style="display:flex;gap:5px;">
                                <a href="view.php?id=<?php echo $b['id']; ?>"
                                   class="btn btn-sm btn-outline-info" title="View" aria-label="View bill">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </a>
                                <?php if ($b['status'] !== 'paid'): ?>
                                <a href="pay.php?id=<?php echo $b['id']; ?>"
                                   class="btn btn-sm btn-outline-success" title="Record Payment" aria-label="Record payment">
                                    <i class="bi bi-cash" aria-hidden="true"></i>
                                </a>
                                <?php endif; ?>
                                <a href="<?php echo BASE_URL; ?>modules/print/payment_receipt.php?bill_id=<?php echo $b['id']; ?>"
                                   class="btn btn-sm btn-outline-secondary" title="Print Receipt" aria-label="Print receipt">
                                    <i class="bi bi-printer" aria-hidden="true"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="pagination-bar">
            <div class="pagination-info">
                Showing <?php echo number_format($offset + 1); ?>–<?php echo number_format(min($offset + $per_page, $total_count)); ?> of <?php echo number_format($total_count); ?> bills
            </div>
            <div class="pagination-links">
                <?php if ($page > 1): ?>
                <a href="list.php?<?php echo $filter_qs; ?>page=<?php echo $page - 1; ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-left"></i> Prev</a>
                <?php endif; ?>
                <?php for ($pg = max(1, $page - 2); $pg <= min($total_pages, $page + 2); $pg++): ?>
                <a href="list.php?<?php echo $filter_qs; ?>page=<?php echo $pg; ?>"
                   class="btn btn-sm <?php echo $pg === $page ? 'btn-primary' : 'btn-outline-secondary'; ?>"><?php echo $pg; ?></a>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                <a href="list.php?<?php echo $filter_qs; ?>page=<?php echo $page + 1; ?>" class="btn btn-sm btn-outline-secondary">Next <i class="bi bi-chevron-right"></i></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>
<?php include '../../includes/footer.php'; ?>
</body>
</html>