<?php
// Admin-only analytics: appointment trends, revenue charts.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_admin();

$page_title = 'Analytics';

// ── Month navigation ─────────────────────────────────────────
$selected_month = $_GET['month'] ?? '';
if (!preg_match('/^\d{4}-\d{2}$/', $selected_month)) {
    $selected_month = date('Y-m');
}
$month_start_ts = strtotime($selected_month . '-01');
if (!$month_start_ts) { $selected_month = date('Y-m'); $month_start_ts = strtotime($selected_month . '-01'); }
$month_start  = date('Y-m-d', $month_start_ts);
$month_end    = date('Y-m-t', $month_start_ts);
$prev_month   = date('Y-m', strtotime('-1 month', $month_start_ts));
$next_month   = date('Y-m', strtotime('+1 month', $month_start_ts));
$is_future    = $next_month > date('Y-m');
$month_label  = date('F Y', strtotime($month_start));
$prev_start   = $prev_month . '-01';
$prev_end     = date('Y-m-t', strtotime($prev_start));

// ── Month range helpers for SQL ──────────────────────────────
$sql_cur_start  = $month_start;
$sql_cur_end    = $month_end;
$sql_prev_start = $prev_start;
$sql_prev_end   = $prev_end;

// ── Range filter ─────────────────────────────────────────────
$range = $_GET['range'] ?? 'month';
if (!in_array($range, ['7days','month','year'])) $range = 'month';
if ($range === '7days') {
    $sql_cur_start = date('Y-m-d', strtotime('-6 days'));
    $sql_cur_end   = date('Y-m-d');
    $range_label   = 'Last 7 Days';
} elseif ($range === '30days') {
    $sql_cur_start = date('Y-m-d', strtotime('-29 days'));
    $sql_cur_end   = date('Y-m-d');
    $range_label   = 'Last 30 Days';
} elseif ($range === 'year') {
    $sql_cur_start = date('Y-01-01');
    $sql_cur_end   = date('Y-m-d');
    $range_label   = 'Year to Date (' . date('Y') . ')';
} else {
    $range_label = $month_label;
}

// ── Today's Appointments ─────────────────────────────────────
$appts_today = (int)$conn->query("
    SELECT COUNT(*) as c FROM appointments
    WHERE appointment_date = CURRENT_DATE
")->fetch(PDO::FETCH_ASSOC)['c'];

// ── Pending Bills ────────────────────────────────────────────
$pending_bills = (float)$conn->query("
    SELECT COALESCE(SUM(amount_due - amount_paid), 0) as total
    FROM bills WHERE status IN ('unpaid','partial')
")->fetch(PDO::FETCH_ASSOC)['total'];

// ── Daily Appointments ───────────────────────────────────────
$daily_raw = $conn->query("
    SELECT appointment_date as day, COUNT(*) as total
    FROM appointments
    WHERE appointment_date BETWEEN '$sql_cur_start' AND '$sql_cur_end'
    GROUP BY appointment_date ORDER BY appointment_date ASC
")->fetchAll(PDO::FETCH_ASSOC);
$daily_map = [];
foreach ($daily_raw as $row) { $daily_map[$row['day']] = (int)$row['total']; }
$daily_labels = []; $daily_values = [];
$cursor = strtotime($sql_cur_start);
$end_ts = strtotime($sql_cur_end);
$span_days = ($end_ts - $cursor) / 86400;
while ($cursor <= $end_ts) {
    $d = date('Y-m-d', $cursor);
    $daily_labels[] = $span_days > 14 ? date('M j', $cursor) : date('D j', $cursor);
    $daily_values[] = $daily_map[$d] ?? 0;
    $cursor = strtotime('+1 day', $cursor);
}
$daily_labels_json = json_encode($daily_labels);
$daily_values_json = json_encode($daily_values);

$peak_day_value = !empty($daily_values) ? max($daily_values) : 0;
$peak_day_idx   = $peak_day_value > 0 ? array_search($peak_day_value, $daily_values) : -1;
$peak_day_label = ($peak_day_idx >= 0 && isset($daily_labels[$peak_day_idx])) ? $daily_labels[$peak_day_idx] : '';

// ── KPI — Current Period ─────────────────────────────────────
$new_patients = (int)$conn->query("
    SELECT COUNT(*) as c FROM patients
    WHERE DATE(created_at) BETWEEN '$sql_cur_start' AND '$sql_cur_end'
")->fetch(PDO::FETCH_ASSOC)['c'];

$returning = (int)$conn->query("
    SELECT COUNT(DISTINCT patient_id) as c
    FROM appointments
    WHERE appointment_date BETWEEN '$sql_cur_start' AND '$sql_cur_end'
    AND patient_id NOT IN (
        SELECT id FROM patients WHERE DATE(created_at) BETWEEN '$sql_cur_start' AND '$sql_cur_end'
    )
")->fetch(PDO::FETCH_ASSOC)['c'];

$revenue = (float)$conn->query("
    SELECT COALESCE(SUM(amount_paid), 0) as total
    FROM bills
    WHERE DATE(created_at) BETWEEN '$sql_cur_start' AND '$sql_cur_end'
")->fetch(PDO::FETCH_ASSOC)['total'];

$status_breakdown = $conn->query("
    SELECT status, COUNT(*) as total
    FROM appointments
    WHERE appointment_date BETWEEN '$sql_cur_start' AND '$sql_cur_end'
    GROUP BY status
")->fetchAll(PDO::FETCH_ASSOC);

$status_map = [];
foreach ($status_breakdown as $row) { $status_map[$row['status']] = (int)$row['total']; }
$total_this_month = array_sum($status_map);
$completed_count  = $status_map['completed'] ?? 0;

// ── KPI — Previous Period ────────────────────────────────────
$prev_new_patients = (int)$conn->query("
    SELECT COUNT(*) as c FROM patients
    WHERE DATE(created_at) BETWEEN '$sql_prev_start' AND '$sql_prev_end'
")->fetch(PDO::FETCH_ASSOC)['c'];

$prev_returning = (int)$conn->query("
    SELECT COUNT(DISTINCT patient_id) as c
    FROM appointments
    WHERE appointment_date BETWEEN '$sql_prev_start' AND '$sql_prev_end'
      AND patient_id NOT IN (
          SELECT id FROM patients
          WHERE DATE(created_at) BETWEEN '$sql_prev_start' AND '$sql_prev_end'
      )
")->fetch(PDO::FETCH_ASSOC)['c'];

$prev_revenue = (float)$conn->query("
    SELECT COALESCE(SUM(amount_paid), 0) as total FROM bills
    WHERE DATE(created_at) BETWEEN '$sql_prev_start' AND '$sql_prev_end'
")->fetch(PDO::FETCH_ASSOC)['total'];

$prev_status = $conn->query("
    SELECT status, COUNT(*) as total FROM appointments
    WHERE appointment_date BETWEEN '$sql_prev_start' AND '$sql_prev_end'
    GROUP BY status
")->fetchAll(PDO::FETCH_ASSOC);
$prev_map = [];
foreach ($prev_status as $r) { $prev_map[$r['status']] = (int)$r['total']; }
$prev_total = array_sum($prev_map);

function trend_badge(float $now, float $prev, string $suffix = '%'): string {
    if ($prev == 0) {
        if ($now == 0) return '<span class="kpi-trend neutral">No data last month</span>';
        return '<span class="kpi-trend up"><i class="bi bi-arrow-up-short"></i>New this month</span>';
    }
    $pct = round((($now - $prev) / $prev) * 100, 1);
    if ($pct > 0)     return '<span class="kpi-trend up"><i class="bi bi-arrow-up-short"></i>+'.abs($pct).'% vs last month</span>';
    elseif ($pct < 0) return '<span class="kpi-trend down"><i class="bi bi-arrow-down-short"></i>'.abs($pct).'% vs last month</span>';
    else              return '<span class="kpi-trend neutral">Same as last month</span>';
}

// ── Revenue (last 6 months) + Forecast ───────────────────────
$revenue_per_month = $conn->query("
    SELECT DATE_FORMAT(created_at, '%b %Y') as month,
           DATE_FORMAT(created_at, '%Y-%m') as sort_key,
           COALESCE(SUM(amount_paid), 0) as total
    FROM bills
    WHERE created_at >= NOW() - INTERVAL 6 MONTH
    GROUP BY sort_key, month ORDER BY sort_key ASC
")->fetchAll(PDO::FETCH_ASSOC);

$rev_totals = array_column($revenue_per_month, 'total');
$last3      = array_slice($rev_totals, -3);
if (count($last3) >= 2) {
    $slope    = ($last3[count($last3)-1] - $last3[0]) / max(count($last3) - 1, 1);
    $forecast = max(0, round(end($last3) + $slope));
} elseif (count($last3) === 1) {
    $forecast = (int)$last3[0];
} else {
    $forecast = 0;
}
$next_month_label = date('M Y', strtotime('first day of next month'));

// Revenue summary stats for mini-header
$total_revenue_6m = !empty($rev_totals) ? (float)array_sum($rev_totals) : 0;
$avg_revenue_6m   = count($rev_totals) > 0 ? round($total_revenue_6m / count($rev_totals)) : 0;
$best_month_rev   = !empty($rev_totals) ? (float)max($rev_totals) : 0;
$best_month_label = '';
foreach ($revenue_per_month as $rm) {
    if ((float)$rm['total'] === $best_month_rev) { $best_month_label = $rm['month']; break; }
}

// ── Appointment Breakdown by Service ─────────────────────────
$appt_by_service = $conn->query("
    SELECT s.service_name, COUNT(a.id) as total
    FROM appointments a
    JOIN services s ON a.service_id = s.id
    WHERE a.appointment_date BETWEEN '$sql_cur_start' AND '$sql_cur_end'
    GROUP BY s.id, s.service_name
    ORDER BY total DESC
    LIMIT 6
");
$appt_by_service = $appt_by_service ? $appt_by_service->fetchAll(PDO::FETCH_ASSOC) : [];
$total_appts_breakdown = (int)array_sum(array_column($appt_by_service, 'total'));

// ── JS encoding ───────────────────────────────────────────────
$rev_labels    = json_encode(array_column($revenue_per_month, 'month'));
$rev_data      = json_encode(array_column($revenue_per_month, 'total'));
$svc_labels    = json_encode(array_column($appt_by_service, 'service_name'));
$svc_data      = json_encode(array_column($appt_by_service, 'total'));
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?>
<style>
/* ══ Analytics Dashboard ══════════════════════════════════ */

/* KPI grid */
.an-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 20px;
}
@media (max-width: 992px) { .an-kpi-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 400px)  { .an-kpi-grid { gap: 10px; } }

.an-kpi-card {
    background: #fff;
    border: 1px solid var(--gray-100);
    border-radius: 16px;
    padding: 20px;
    display: flex;
    align-items: flex-start;
    gap: 14px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.05);
    transition: box-shadow 0.2s, transform 0.2s;
}
.an-kpi-card:hover { box-shadow: 0 6px 22px rgba(0,0,0,0.09); transform: translateY(-2px); }
[data-theme="dark"] .an-kpi-card { background: var(--gray-100); border-color: var(--gray-200); }

.an-kpi-icon {
    width: 46px; height: 46px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.2rem; flex-shrink: 0;
}
.an-kpi-icon.blue   { background: rgba(37,99,235,0.10);  color: #2563eb; }
.an-kpi-icon.green  { background: rgba(22,163,74,0.10);  color: #15803d; }
.an-kpi-icon.teal   { background: rgba(13,148,136,0.10); color: #0d9488; }
.an-kpi-icon.indigo { background: rgba(99,102,241,0.10); color: #6366f1; }

.an-kpi-label  { font-size: 0.68rem; color: var(--gray-500); font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 3px; }
.an-kpi-value  { font-size: 1.8rem; font-weight: 800; line-height: 1.1; color: var(--gray-900); margin-bottom: 4px; }
.an-kpi-value.sm { font-size: 1.4rem; }

.kpi-trend         { display: inline-flex; align-items: center; gap: 1px; font-size: 0.71rem; font-weight: 600; border-radius: 20px; padding: 2px 8px; }
.kpi-trend.up      { color: #15803d; background: rgba(22,163,74,0.09); }
.kpi-trend.down    { color: #b91c1c; background: rgba(220,38,38,0.09); }
.kpi-trend.neutral { color: var(--gray-500); background: var(--gray-100); font-weight: 400; }

/* Chart cards */
.an-card {
    background: #fff;
    border: 1px solid var(--gray-100);
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 1px 4px rgba(0,0,0,0.05);
}
[data-theme="dark"] .an-card { background: var(--gray-100); border-color: var(--gray-200); }

.an-card-head {
    padding: 14px 22px;
    border-bottom: 1px solid var(--gray-100);
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 8px;
}
[data-theme="dark"] .an-card-head { border-bottom-color: var(--gray-200); }
.an-card-head-title { font-size: 0.82rem; font-weight: 700; color: var(--gray-700); display: flex; align-items: center; gap: 6px; }
.an-card-head-sub   { font-size: 0.72rem; color: var(--gray-400); }
.an-card-body { padding: 20px 22px; }

/* Chart row grids */
.an-chart-row { display: grid; gap: 18px; margin-bottom: 18px; }
.an-chart-row.cols-7-5 { grid-template-columns: 7fr 5fr; }
@media (max-width: 992px) { .an-chart-row.cols-7-5 { grid-template-columns: 1fr; } }

/* ── Revenue mini-stats header ──────────────────────────── */
.rev-stats-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    border-bottom: 1px solid var(--gray-100);
}
[data-theme="dark"] .rev-stats-row { border-bottom-color: var(--gray-200); }
.rev-stat {
    padding: 14px 20px;
    border-right: 1px solid var(--gray-100);
}
[data-theme="dark"] .rev-stat { border-right-color: var(--gray-200); }
.rev-stat:last-child { border-right: none; }
.rev-stat-label { font-size: 0.63rem; text-transform: uppercase; letter-spacing: 0.07em; font-weight: 600; color: var(--gray-400); margin-bottom: 4px; }
.rev-stat-val   { font-size: 1.1rem; font-weight: 800; line-height: 1.2; }
.rev-stat-sub   { font-size: 0.66rem; color: var(--gray-400); margin-top: 2px; }

@media (max-width: 576px) {
    .rev-stats-row { grid-template-columns: 1fr 1fr; }
    .rev-stat:nth-child(2) { border-right: none; }
    .rev-stat:nth-child(3) {
        grid-column: 1 / -1;
        border-top: 1px solid var(--gray-100);
    }
    [data-theme="dark"] .rev-stat:nth-child(3) { border-top-color: var(--gray-200); }
}

/* ── Appointment Breakdown ──────────────────────────────── */
.appt-breakdown-body {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 20px 18px 16px;
}
.donut-wrap {
    position: relative;
    width: 220px; height: 220px;
    flex-shrink: 0;
}
.donut-center {
    position: absolute;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    text-align: center;
    pointer-events: none;
}
.donut-center-total { font-size: 1.9rem; font-weight: 800; color: var(--gray-900); line-height: 1; }
.donut-center-label { font-size: 0.62rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gray-400); margin-top: 3px; max-width: 88px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* Donut legend */
.donut-legend { width: 100%; margin-top: 14px; display: flex; flex-direction: column; gap: 2px; }
.legend-item {
    display: flex; align-items: center; gap: 8px;
    padding: 7px 10px; border-radius: 10px; cursor: pointer;
    transition: background 0.14s;
}
.legend-item:hover { background: var(--gray-50); }
[data-theme="dark"] .legend-item:hover { background: rgba(255,255,255,0.05); }
.legend-swatch { width: 9px; height: 9px; border-radius: 3px; flex-shrink: 0; }
.legend-name   { font-size: 0.75rem; color: var(--gray-600); flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.legend-count  { font-size: 0.73rem; font-weight: 700; color: var(--gray-800); }
.legend-pct    { font-size: 0.67rem; color: var(--gray-400); min-width: 34px; text-align: right; }

/* Peak badge */
.peak-badge {
    display: inline-flex; align-items: center; gap: 5px;
    background: rgba(37,99,235,0.08); border: 1px solid rgba(37,99,235,0.16);
    border-radius: 20px; padding: 3px 10px;
    font-size: 0.70rem; font-weight: 600; color: var(--primary);
}

/* Page header */
.an-page-header {
    display: flex; align-items: flex-start; justify-content: space-between;
    flex-wrap: wrap; gap: 12px; margin-bottom: 20px;
}
.an-range-tabs {
    display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
}

/* ── Mobile overrides ───────────────────────────────────── */
@media (max-width: 576px) {
    .an-page-header { flex-direction: column; align-items: flex-start; }
    .an-range-tabs  {
        width: 100%; overflow-x: auto; flex-wrap: nowrap;
        padding-bottom: 4px; -webkit-overflow-scrolling: touch;
    }
    .an-range-tabs a, .an-range-tabs button { white-space: nowrap; flex-shrink: 0; }
    .an-kpi-value      { font-size: 1.5rem; }
    .an-kpi-value.sm   { font-size: 1.2rem; }
    .an-card-body      { padding: 14px 16px; }
    .rev-stat          { padding: 10px 14px; }
    .rev-chart-canvas  { height: 220px !important; }
    .daily-chart-wrap  { height: 110px !important; }
    .donut-wrap        { width: 190px; height: 190px; }
    .donut-center-total { font-size: 1.55rem; }
    .rev-stat-val      { font-size: 0.95rem; }
}

@media (max-width: 400px) {
    .an-kpi-card  { padding: 14px 12px; gap: 10px; }
    .an-kpi-icon  { width: 38px; height: 38px; font-size: 1rem; }
    .an-kpi-label { font-size: 0.62rem; }
}
</style>
</head>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">

        <!-- Page Header -->
        <div class="an-page-header">
            <div>
                <h5 style="margin:0;font-weight:800;color:var(--gray-900);">Analytics Dashboard</h5>
                <p style="color:var(--gray-500);font-size:0.83rem;margin:4px 0 0;">
                    <i class="bi bi-calendar3" style="margin-right:4px;"></i><?php echo $range_label; ?>
                </p>
            </div>
            <div class="an-range-tabs">
                <?php
                $tabs = ['7days' => 'Last 7 Days', 'month' => 'This Month', 'year' => 'This Year'];
                foreach ($tabs as $key => $label):
                    $active = ($range === $key) || ($range === '30days' && $key === 'month');
                    $href   = '?range=' . $key . ($key === 'month' ? '&month=' . $selected_month : '');
                    $style  = $active
                        ? 'padding:6px 16px;border-radius:20px;font-size:0.78rem;font-weight:700;background:var(--primary);color:#fff;border:none;cursor:pointer;text-decoration:none;display:inline-block;'
                        : 'padding:6px 16px;border-radius:20px;font-size:0.78rem;font-weight:500;background:var(--gray-100);color:var(--gray-600);border:none;cursor:pointer;text-decoration:none;display:inline-block;transition:background 0.15s;';
                ?>
                <a href="<?php echo $href; ?>" style="<?php echo $style; ?>"><?php echo $label; ?></a>
                <?php endforeach; ?>
                <?php if ($range === 'month' || $range === '30days' || $range === 'year'): ?>
                <span style="width:1px;height:20px;background:var(--gray-200);margin:0 2px;"></span>
                <?php
                    if ($range === 'year') {
                        $cur_year    = (int)date('Y');
                        $prev_href   = '?range=year&yr=' . ($cur_year - 1);
                        $next_href   = '?range=year&yr=' . ($cur_year + 1);
                        $prev_title  = 'Previous year';
                        $next_title  = 'Next year';
                        $year_future = ($cur_year + 1) > $cur_year;
                    } else {
                        $prev_href   = '?range=month&month=' . $prev_month;
                        $next_href   = '?range=month&month=' . $next_month;
                        $prev_title  = 'Previous month';
                        $next_title  = 'Next month';
                        $year_future = false;
                    }
                ?>
                <a href="<?php echo $prev_href; ?>" class="btn btn-sm btn-outline-secondary" title="<?php echo $prev_title; ?>" style="padding:4px 8px;">
                    <i class="bi bi-chevron-left"></i>
                </a>
                <?php if (!$is_future && !$year_future): ?>
                <a href="<?php echo $next_href; ?>" class="btn btn-sm btn-outline-secondary" title="<?php echo $next_title; ?>" style="padding:4px 8px;">
                    <i class="bi bi-chevron-right"></i>
                </a>
                <?php else: ?>
                <button class="btn btn-sm btn-outline-secondary" disabled style="padding:4px 8px;">
                    <i class="bi bi-chevron-right"></i>
                </button>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── KPI Cards ──────────────────────────────────────── -->
        <div class="an-kpi-grid">
            <div class="an-kpi-card">
                <div class="an-kpi-icon green"><i class="bi bi-cash-coin"></i></div>
                <div style="flex:1;min-width:0;">
                    <div class="an-kpi-label">Total Revenue</div>
                    <div class="an-kpi-value sm">₱<?php echo number_format($revenue, 0); ?></div>
                    <?php echo trend_badge($revenue, $prev_revenue); ?>
                </div>
            </div>
            <div class="an-kpi-card">
                <div class="an-kpi-icon blue"><i class="bi bi-person-plus-fill"></i></div>
                <div style="flex:1;min-width:0;">
                    <div class="an-kpi-label">New Patients</div>
                    <div class="an-kpi-value"><?php echo $new_patients; ?></div>
                    <?php echo trend_badge($new_patients, $prev_new_patients); ?>
                </div>
            </div>
            <div class="an-kpi-card">
                <div class="an-kpi-icon teal"><i class="bi bi-calendar-check-fill"></i></div>
                <div style="flex:1;min-width:0;">
                    <div class="an-kpi-label">Appointments Today</div>
                    <div class="an-kpi-value"><?php echo $appts_today; ?></div>
                    <span class="kpi-trend neutral"><?php echo date('l, M j'); ?></span>
                </div>
            </div>
            <div class="an-kpi-card">
                <div class="an-kpi-icon indigo"><i class="bi bi-receipt-cutoff"></i></div>
                <div style="flex:1;min-width:0;">
                    <div class="an-kpi-label">Pending Bills</div>
                    <div class="an-kpi-value sm">₱<?php echo number_format($pending_bills, 0); ?></div>
                    <span class="kpi-trend <?php echo $pending_bills > 0 ? 'down' : 'up'; ?>">
                        <?php echo $pending_bills > 0 ? 'Needs collection' : 'All settled'; ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- ── Daily Appointment Activity ──────────────────────── -->
        <div style="margin-bottom:18px;">
            <div class="an-card">
                <div class="an-card-head">
                    <div class="an-card-head-title">
                        <i class="bi bi-activity" style="color:var(--primary);"></i>
                        Daily Appointment Activity
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <?php if ($peak_day_value > 0 && $peak_day_label): ?>
                        <span class="peak-badge">
                            <i class="bi bi-lightning-fill"></i>
                            Peak: <?php echo htmlspecialchars($peak_day_label); ?> &mdash; <?php echo $peak_day_value; ?> appts
                        </span>
                        <?php endif; ?>
                        <span class="an-card-head-sub"><?php echo $range_label; ?> — peaks &amp; busy days</span>
                    </div>
                </div>
                <div class="an-card-body" style="padding:16px 22px 20px;">
                    <div class="daily-chart-wrap" style="position:relative;height:130px;">
                        <canvas id="dailyApptChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Revenue (7fr) + Breakdown (5fr) ─────────────────── -->
        <div class="an-chart-row cols-7-5">

            <!-- Revenue Trend & Projection (redesigned) -->
            <div class="an-card">
                <!-- Mini stats header -->
                <div class="rev-stats-row">
                    <div class="rev-stat">
                        <div class="rev-stat-label">6-Month Total</div>
                        <div class="rev-stat-val" style="color:#0f766e;">₱<?php echo number_format($total_revenue_6m, 0); ?></div>
                        <div class="rev-stat-sub">Cumulative revenue</div>
                    </div>
                    <div class="rev-stat">
                        <div class="rev-stat-label">Best Month</div>
                        <div class="rev-stat-val" style="color:var(--primary);">₱<?php echo number_format($best_month_rev, 0); ?></div>
                        <div class="rev-stat-sub"><?php echo $best_month_label ?: '—'; ?></div>
                    </div>
                    <div class="rev-stat">
                        <div class="rev-stat-label">Monthly Average</div>
                        <div class="rev-stat-val" style="color:#4f46e5;">₱<?php echo number_format($avg_revenue_6m, 0); ?></div>
                        <div class="rev-stat-sub">Per month</div>
                    </div>
                </div>
                <div class="an-card-head">
                    <div class="an-card-head-title">
                        <i class="bi bi-graph-up" style="color:#15803d;"></i>
                        Revenue Trend &amp; Projection
                    </div>
                    <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                        <?php if ($forecast > 0): ?>
                        <span style="font-size:0.73rem;color:var(--gray-500);">
                            Forecast <strong style="color:#15803d;">₱<?php echo number_format($forecast); ?></strong>
                            <span style="color:var(--gray-400);"> · <?php echo $next_month_label; ?></span>
                        </span>
                        <?php endif; ?>
                        <span class="an-card-head-sub">Last 6 months</span>
                    </div>
                </div>
                <div class="an-card-body">
                    <div class="rev-chart-canvas" style="position:relative;height:300px;">
                        <canvas id="revenueChart"></canvas>
                    </div>
                    <div style="display:flex;align-items:center;gap:18px;margin-top:14px;font-size:0.74rem;color:var(--gray-500);flex-wrap:wrap;">
                        <span style="display:flex;align-items:center;gap:6px;">
                            <span style="display:inline-block;width:22px;height:3px;background:#0d9488;border-radius:2px;vertical-align:middle;"></span>Actual
                        </span>
                        <?php if ($forecast > 0): ?>
                        <span style="display:flex;align-items:center;gap:6px;">
                            <span style="display:inline-block;width:18px;border-top:2px dashed #15803d;vertical-align:middle;"></span>Forecast
                        </span>
                        <?php endif; ?>
                        <span style="display:flex;align-items:center;gap:6px;">
                            <span style="display:inline-block;width:18px;border-top:2px dashed rgba(99,102,241,0.5);vertical-align:middle;"></span>Average
                        </span>
                    </div>
                </div>
            </div>

            <!-- Appointment Breakdown (interactive) -->
            <div class="an-card">
                <div class="an-card-head">
                    <div class="an-card-head-title">
                        <i class="bi bi-pie-chart-fill" style="color:var(--primary);"></i>
                        Appointment Breakdown
                    </div>
                    <span class="an-card-head-sub">By service — hover to explore</span>
                </div>
                <div class="appt-breakdown-body">
                    <div class="donut-wrap">
                        <canvas id="apptBreakdownChart"></canvas>
                        <div class="donut-center"<?php echo $total_appts_breakdown === 0 ? ' hidden' : ''; ?>>
                            <div class="donut-center-total" id="donutCenterVal"><?php echo $total_appts_breakdown; ?></div>
                            <div class="donut-center-label" id="donutCenterLabel">Total Appts</div>
                        </div>
                    </div>
                    <div class="donut-legend" id="donutLegend">
                        <?php if (empty($appt_by_service)): ?>
                        <p style="text-align:center;color:var(--gray-400);font-size:0.82rem;padding:12px 0;">No data this period</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div><!-- /row -->

    </div><!-- /page-content -->

<script src="<?php echo BASE_URL; ?>assets/js/chart.umd.min.js"></script>
<script>
function safeChart(id, config) {
    var el = document.getElementById(id);
    if (!el) return null;
    var ex = Chart.getChart(el);
    if (ex) ex.destroy();
    return new Chart(el, config);
}

var isDark    = document.documentElement.getAttribute('data-bs-theme') === 'dark'
             || document.documentElement.getAttribute('data-theme') === 'dark';
var gridColor = isDark ? 'rgba(255,255,255,0.07)' : 'rgba(0,0,0,0.05)';
var tickColor = isDark ? '#8a9bb0' : '#64748b';
var cardBg    = isDark ? '#1e2535' : '#ffffff';
var scaleBase = {
    grid:  { color: gridColor },
    ticks: { color: tickColor, font: { size: 11 } }
};

function noDataPlugin(msg) {
    return {
        id: 'noData',
        afterDraw(chart) {
            var empty = !chart.data.datasets[0].data.length
                     || chart.data.datasets[0].data.every(v => v == 0);
            if (!empty) return;
            var ctx = chart.ctx, w = chart.width, h = chart.height;
            chart.clear();
            ctx.save();
            ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
            ctx.fillStyle = tickColor;
            ctx.font = '13px DM Sans, system-ui, sans-serif';
            ctx.fillText(msg, w / 2, h / 2);
            ctx.restore();
        }
    };
}

// ── 1. Revenue Chart ─────────────────────────────────────────
(function () {
    var revLabels = <?php echo $rev_labels; ?>;
    var revData   = <?php echo $rev_data; ?>.map(Number);
    var forecast  = <?php echo (int)$forecast; ?>;
    var nextLabel = <?php echo json_encode($next_month_label); ?>;

    var allLabels    = [...revLabels];
    var actualData   = [...revData];
    var forecastData = new Array(revData.length).fill(null);

    if (forecast > 0 && revData.length > 0) {
        forecastData[forecastData.length - 1] = revData[revData.length - 1];
        allLabels.push(nextLabel);
        actualData.push(null);
        forecastData.push(forecast);
    }

    var avg    = revData.length ? revData.reduce((a, b) => a + b, 0) / revData.length : 0;
    var avgData = allLabels.map((_, i) => i < revData.length ? Math.round(avg) : null);

    function fmtPeso(v) {
        if (v === null || v === undefined) return '';
        if (v >= 1000000) return '₱' + (v / 1000000).toFixed(1) + 'M';
        if (v >= 1000)    return '₱' + (v / 1000).toFixed(0) + 'K';
        return '₱' + v;
    }

    safeChart('revenueChart', {
        type: 'line',
        plugins: [
            noDataPlugin('No revenue recorded yet'),
            {
                id: 'datalabels',
                afterDatasetsDraw(chart) {
                    var ctx  = chart.ctx;
                    var meta = chart.getDatasetMeta(0);
                    if (meta.hidden) return;
                    meta.data.forEach((pt, i) => {
                        var v = actualData[i];
                        if (v === null || v === undefined) return;
                        ctx.save();
                        ctx.font = 'bold 10px DM Sans, system-ui, sans-serif';
                        ctx.fillStyle = '#0d9488';
                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'bottom';
                        ctx.fillText(fmtPeso(v), pt.x, pt.y - 7);
                        ctx.restore();
                    });
                }
            }
        ],
        data: {
            labels: allLabels,
            datasets: [
                {
                    label: 'Actual',
                    data: actualData,
                    borderColor: '#0d9488', borderWidth: 2.5,
                    backgroundColor: function (ctx) {
                        var area = ctx.chart.chartArea;
                        if (!area) return 'rgba(13,148,136,0.08)';
                        var g = ctx.chart.ctx.createLinearGradient(0, area.top, 0, area.bottom);
                        g.addColorStop(0, 'rgba(13,148,136,0.28)');
                        g.addColorStop(1, 'rgba(13,148,136,0.01)');
                        return g;
                    },
                    fill: true, tension: 0.38,
                    pointRadius: 5, pointHoverRadius: 7,
                    pointBackgroundColor: '#0d9488',
                    pointBorderColor: cardBg, pointBorderWidth: 2,
                    order: 1
                },
                {
                    label: 'Forecast',
                    data: forecastData,
                    borderColor: '#15803d', borderWidth: 2, borderDash: [7, 4],
                    backgroundColor: 'transparent', fill: false, tension: 0.35,
                    pointRadius: ctx => {
                        var v = forecastData[ctx.dataIndex];
                        return (v !== null && ctx.dataIndex === forecastData.length - 1) ? 6 : 0;
                    },
                    pointBackgroundColor: '#15803d',
                    pointBorderColor: cardBg, pointBorderWidth: 2,
                    order: 2
                },
                {
                    label: 'Average',
                    data: avgData,
                    borderColor: 'rgba(99,102,241,0.45)', borderWidth: 1.5, borderDash: [3, 3],
                    backgroundColor: 'transparent', fill: false, tension: 0,
                    pointRadius: 0, pointHoverRadius: 0,
                    order: 3
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            layout: { padding: { top: 28, bottom: 6, left: 8, right: 8 } },
            interaction: { mode: 'index', intersect: false },
            animation: { duration: 1200, easing: 'easeOutQuart' },
            animations: {
                y: {
                    duration: 1200, easing: 'easeOutQuart',
                    delay: ctx => (ctx.type === 'data' && ctx.mode === 'default')
                        ? (ctx.datasetIndex * 150 + ctx.dataIndex * 80) : 0
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: isDark ? '#1e2535' : '#fff',
                    borderColor: gridColor, borderWidth: 1,
                    titleColor: tickColor, bodyColor: tickColor, padding: 12,
                    callbacks: {
                        label: ctx => {
                            if (ctx.parsed.y === null) return null;
                            var lbl = ctx.dataset.label === 'Average' ? ' Avg' : ' ' + ctx.dataset.label;
                            return lbl + ': ₱' + Number(ctx.parsed.y).toLocaleString('en-PH');
                        }
                    }
                }
            },
            scales: {
                x: { ...scaleBase, grid: { display: false }, ticks: { ...scaleBase.ticks, maxRotation: 0 } },
                y: {
                    ...scaleBase, beginAtZero: true,
                    ticks: {
                        ...scaleBase.ticks,
                        callback: v => {
                            if (v >= 1000000) return '₱' + (v/1000000).toFixed(1) + 'M';
                            if (v >= 1000)    return '₱' + (v/1000).toFixed(0) + 'K';
                            return '₱' + v;
                        }
                    }
                }
            }
        }
    });
})();

// ── 2. Appointment Breakdown Donut ───────────────────────────
(function () {
    var svcLabels = <?php echo $svc_labels; ?>;
    var svcData   = <?php echo $svc_data; ?>.map(Number);
    var palette   = ['#2563eb', '#0d9488', '#f59e0b', '#6366f1', '#15803d', '#dc2626'];
    var total     = svcData.reduce((a, b) => a + b, 0);

    var centerVal   = document.getElementById('donutCenterVal');
    var centerLabel = document.getElementById('donutCenterLabel');

    var donutChart = safeChart('apptBreakdownChart', {
        type: 'doughnut',
        plugins: [noDataPlugin('No service data this period')],
        data: {
            labels: svcLabels,
            datasets: [{
                data: svcData,
                backgroundColor: palette.slice(0, svcLabels.length),
                borderWidth: 3,
                borderColor: cardBg,
                hoverOffset: 10,
                hoverBorderWidth: 3
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false, cutout: '66%',
            animation: { duration: 950, easing: 'easeOutQuart' },
            plugins: {
                legend: { display: false },
                tooltip: { enabled: false }
            },
            onHover: function (e, elements) {
                if (elements && elements.length > 0) {
                    var idx = elements[0].index;
                    if (centerVal)   centerVal.textContent = svcData[idx];
                    if (centerLabel) { centerLabel.textContent = svcLabels[idx]; centerLabel.title = svcLabels[idx]; }
                } else {
                    if (centerVal)   centerVal.textContent = total;
                    if (centerLabel) { centerLabel.textContent = 'Total Appts'; centerLabel.title = ''; }
                }
            }
        }
    });

    // Build interactive legend
    var legend = document.getElementById('donutLegend');
    if (legend && svcLabels.length > 0) {
        legend.innerHTML = svcLabels.map((l, i) => {
            var pct = total > 0 ? Math.round((svcData[i] / total) * 100) : 0;
            return '<div class="legend-item" data-idx="' + i + '"'
                + ' onmouseenter="hlDonut(' + i + ')" onmouseleave="resetDonut()">'
                + '<span class="legend-swatch" style="background:' + palette[i] + ';"></span>'
                + '<span class="legend-name" title="' + l + '">' + l + '</span>'
                + '<span class="legend-count">' + svcData[i] + '</span>'
                + '<span class="legend-pct">' + pct + '%</span>'
                + '</div>';
        }).join('');
    }

    window.hlDonut = function (idx) {
        if (!donutChart || !svcLabels.length) return;
        donutChart.data.datasets[0].backgroundColor = palette.slice(0, svcLabels.length).map((c, i) =>
            i === idx ? c : c + '30'
        );
        donutChart.update('none');
        if (centerVal)   centerVal.textContent = svcData[idx];
        if (centerLabel) { centerLabel.textContent = svcLabels[idx]; centerLabel.title = svcLabels[idx]; }
    };

    window.resetDonut = function () {
        if (!donutChart) return;
        donutChart.data.datasets[0].backgroundColor = palette.slice(0, svcLabels.length);
        donutChart.update('none');
        if (centerVal)   centerVal.textContent = total;
        if (centerLabel) { centerLabel.textContent = 'Total Appts'; centerLabel.title = ''; }
    };
})();

// ── 3. Daily Appointment Activity ────────────────────────────
(function () {
    var dLabels = <?php echo $daily_labels_json; ?>;
    var dValues = <?php echo $daily_values_json; ?>.map(Number);
    var maxVal  = Math.max(...dValues, 1);
    var peakIdx = dValues.indexOf(maxVal);

    safeChart('dailyApptChart', {
        type: 'bar',
        plugins: [noDataPlugin('No appointments in this period')],
        data: {
            labels: dLabels,
            datasets: [{
                label: 'Appointments',
                data: dValues,
                backgroundColor: dValues.map((v, i) => {
                    if (v === 0)           return 'rgba(203,213,225,0.3)';
                    if (i === peakIdx)     return '#0d6e6e';
                    if (v >= maxVal * 0.8) return 'rgba(37,99,235,0.68)';
                    if (v >= maxVal * 0.5) return 'rgba(37,99,235,0.44)';
                    return 'rgba(37,99,235,0.26)';
                }),
                borderRadius: 6,
                borderSkipped: false
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            animation: { duration: 800, delay: ctx => ctx.dataIndex * 18 },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ' ' + ctx.parsed.y + ' appointment' + (ctx.parsed.y !== 1 ? 's' : '')
                    }
                }
            },
            scales: {
                x: { ...scaleBase, grid: { display: false }, ticks: { ...scaleBase.ticks, maxTicksLimit: 20, maxRotation: 45 } },
                y: { ...scaleBase, beginAtZero: true, ticks: { ...scaleBase.ticks, stepSize: 1, precision: 0 } }
            }
        }
    });
})();
</script>
</div><!-- /main-content -->
<?php include '../../includes/footer.php'; ?>
</body>
</html>
