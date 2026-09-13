<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';


// db_val() is now defined in includes/db.php — no local copy needed here.

$page_title = 'Dashboard';

$today            = date('Y-m-d');
$month_start      = date('Y-m-01');
$last_month_start = date('Y-m-01', strtotime('-1 month'));
$last_month_end   = date('Y-m-t',  strtotime('-1 month'));

$total_patients       = (int)db_val($conn, "SELECT COUNT(*) as c FROM patients WHERE is_active = TRUE");
$todays_appointments  = (int)db_val($conn, "SELECT COUNT(*) as c FROM appointments WHERE appointment_date = ?", [$today]);
$pending_appointments = (int)db_val($conn, "SELECT COUNT(*) as c FROM appointments WHERE status = 'pending'");
$completed_month      = (int)db_val($conn, "SELECT COUNT(*) as c FROM appointments WHERE status = 'completed' AND appointment_date >= ?", [$month_start]);
$revenue_month        = (float)db_val($conn, "SELECT COALESCE(SUM(amount_paid),0) as c FROM bills WHERE DATE(created_at) >= ?", [$month_start], 'c', 0.0);

$patients_last_month  = (int)db_val($conn, "SELECT COUNT(*) as c FROM patients WHERE DATE(created_at) BETWEEN ? AND ?", [$last_month_start, $last_month_end]);
$patients_this_month  = (int)db_val($conn, "SELECT COUNT(*) as c FROM patients WHERE DATE(created_at) >= ?", [$month_start]);
$completed_last_month = (int)db_val($conn, "SELECT COUNT(*) as c FROM appointments WHERE status='completed' AND appointment_date BETWEEN ? AND ?", [$last_month_start, $last_month_end]);
$revenue_last_month   = (float)db_val($conn, "SELECT COALESCE(SUM(amount_paid),0) as c FROM bills WHERE DATE(created_at) BETWEEN ? AND ?", [$last_month_start, $last_month_end], 'c', 0.0);

$unpaid_count = (int)db_val($conn, "SELECT COUNT(*) as c FROM bills WHERE status IN ('unpaid','partial')");

$ts_stmt = $conn->prepare("
    SELECT a.appointment_time, a.status, a.appointment_code, a.patient_id,
           CONCAT(p.first_name,' ',p.last_name) as patient_name,
           s.service_name, d.full_name as doctor_name
    FROM appointments a
    LEFT JOIN patients p ON a.patient_id = p.id
    LEFT JOIN services s ON a.service_id = s.id
    LEFT JOIN doctors  d ON a.doctor_id  = d.id
    WHERE a.appointment_date = ?
    AND a.status IN ('pending','confirmed','completed')
    ORDER BY a.appointment_time ASC
    LIMIT 8
");
$ts_stmt->execute([$today]);
$today_schedule = $ts_stmt->fetchAll(PDO::FETCH_ASSOC);

$notifications = [];
$notif_stmt = $conn->prepare("SELECT * FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND is_read = FALSE ORDER BY created_at DESC LIMIT 5");
if ($notif_stmt) {
    $notif_stmt->execute([$current_user_id]);
    $notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);
    $notif_stmt = null;
}

$recent_appts_result = $conn->query("
    SELECT a.*, CONCAT(p.first_name,' ',p.last_name) as patient_name,
           s.service_name, d.full_name as doctor_name
    FROM appointments a
    LEFT JOIN patients p ON a.patient_id = p.id
    LEFT JOIN services s ON a.service_id = s.id
    LEFT JOIN doctors  d ON a.doctor_id  = d.id
    ORDER BY a.created_at DESC LIMIT 8
");
$recent_appts = $recent_appts_result ? $recent_appts_result->fetchAll(PDO::FETCH_ASSOC) : [];

$hour     = (int)date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

function trend($current, $previous) {
    if ($previous == 0) return ['pct' => 0, 'up' => true, 'has' => false];
    $pct = round((($current - $previous) / $previous) * 100);
    return ['pct' => abs($pct), 'up' => $pct >= 0, 'has' => $pct != 0];
}
$rev_trend = trend($revenue_month,    $revenue_last_month);
$pat_trend = trend($patients_this_month, $patients_last_month);
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'includes/head.php'; ?>
<style>
/* KPI cards */
.dash-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 22px;
}
@media (max-width: 1024px) { .dash-kpi-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 540px)  { .dash-kpi-grid { grid-template-columns: 1fr; } }

.dash-kpi {
    background: var(--white);
    border: 1px solid var(--gray-100);
    border-radius: 14px;
    padding: 20px 22px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    text-decoration: none;
    color: inherit;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    transition: box-shadow 0.2s, transform 0.15s;
    position: relative;
    overflow: hidden;
}
.dash-kpi::before {
    content: '';
    position: absolute;
    inset: 0;
    opacity: 0;
    transition: opacity 0.2s;
}
.dash-kpi:hover { box-shadow: 0 6px 20px rgba(0,0,0,0.09); transform: translateY(-2px); color: inherit; }
[data-theme="dark"] .dash-kpi { background: var(--gray-100); border-color: var(--gray-200); }

.kpi-top { display: flex; align-items: center; justify-content: space-between; }
.kpi-icon {
    width: 40px; height: 40px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem; flex-shrink: 0;
}
.kpi-icon.blue   { background: rgba(37,99,235,0.10); color: #2563eb; }
.kpi-icon.teal   { background: rgba(20,184,166,0.10); color: #0d9488; }
.kpi-icon.amber  { background: rgba(245,158,11,0.10); color: #d97706; }
.kpi-icon.green  { background: rgba(22,163,74,0.10);  color: #16a34a; }

.kpi-label { font-size: 0.72rem; color: var(--gray-500); font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em; }
.kpi-value { font-size: 2rem; font-weight: 800; line-height: 1; color: var(--gray-900); }
[data-theme="dark"] .kpi-value { color: var(--gray-900); }
.kpi-value.sm { font-size: 1.5rem; }
.kpi-trend {
    display: inline-flex; align-items: center; gap: 3px;
    font-size: 0.72rem; font-weight: 600; padding: 2px 7px;
    border-radius: 20px;
}
.kpi-trend.up   { background: rgba(22,163,74,0.09);  color: #16a34a; }
.kpi-trend.down { background: rgba(220,38,38,0.09);  color: #dc2626; }
.kpi-trend.flat { background: var(--gray-100); color: var(--gray-500); font-weight:400; }

/* Main body grid */
.dash-body {
    display: grid;
    grid-template-columns: 1fr 320px;
    gap: 16px;
    align-items: start;
}
@media (max-width: 900px) { .dash-body { grid-template-columns: 1fr; } }

/* Right column (now only column — Today's Schedule + Quick Actions side by side) */
.dash-right { display: contents; }

/* Schedule items */
.sched-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 10px 12px;
    border-radius: 9px;
    border-left: 3px solid transparent;
    transition: background 0.15s;
}
.sched-item:hover { background: var(--gray-50); }
[data-theme="dark"] .sched-item:hover { background: rgba(255,255,255,0.04); }
/* The "happening now" row uses a light pastel background ($sc[1] in PHP) that
   never adapted for dark mode, while its patient-name text correctly inverts
   to a light color via var(--gray-800) — light-on-light was unreadable.
   Only the background needs fixing; the text already adapts on its own. */
[data-theme="dark"] .sched-item[style*="background:#dbeafe"] { background: rgba(37,99,235,0.16) !important; }
[data-theme="dark"] .sched-item[style*="background:#dcfce7"] { background: rgba(22,163,74,0.16) !important; }
[data-theme="dark"] .sched-item[style*="background:#fef3c7"] { background: rgba(217,119,6,0.16) !important; }

/* Alert banner */
.dash-alert {
    display: flex; align-items: center; gap: 10px;
    background: #fef2f2; border: 1px solid #fecaca; border-radius: 10px;
    padding: 10px 16px; margin-bottom: 18px; font-size: 0.82rem; color: #dc2626; font-weight: 600;
    text-decoration: none;
}
.dash-alert:hover { background: #fee2e2; color: #dc2626; }

/* Quick actions */
.qa-btn {
    display: flex; align-items: center; gap: 9px;
    padding: 9px 14px; border-radius: 9px; font-size: 0.82rem; font-weight: 600;
    text-decoration: none; transition: all 0.15s; border: 1.5px solid transparent;
}
.qa-btn.primary  { background: #2563eb; color: #fff; }
.qa-btn.primary:hover { background: #1d4ed8; color: #fff; }
.qa-btn.success  { background: #16a34a; color: #fff; }
.qa-btn.success:hover { background: #15803d; color: #fff; }
.qa-btn.outline  { background: transparent; color: var(--gray-700); border-color: var(--gray-200); }
.qa-btn.outline:hover { background: var(--gray-50); color: var(--gray-800); border-color: var(--gray-300); }
[data-theme="dark"] .qa-btn.outline { color: var(--gray-700); border-color: var(--gray-300); }
[data-theme="dark"] .qa-btn.outline:hover { background: var(--gray-150); color: var(--gray-900); }

/* Flow bar */
.flow-bar {
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
    border-radius: 12px; padding: 16px 20px; margin-bottom: 18px;
}
.flow-step {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 12px; background: rgba(255,255,255,0.12);
    border-radius: 7px; color: #fff; text-decoration: none;
    font-size: 0.78rem; font-weight: 600; white-space: nowrap;
    transition: background 0.15s;
}
.flow-step:hover { background: rgba(255,255,255,0.22); color: #fff; }
.flow-arrow { color: rgba(255,255,255,0.3); margin: 0 2px; font-size: 0.7rem; }

/* Appointment table */
.appt-table { width: 100%; border-collapse: collapse; }
.appt-table th {
    padding: 9px 14px; font-size: 0.7rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.06em;
    color: var(--gray-400); border-bottom: 1px solid var(--gray-100);
    background: var(--gray-50); text-align: left;
}
[data-theme="dark"] .appt-table th { background: var(--gray-150); border-color: var(--gray-200); }
.appt-table td {
    padding: 10px 14px; font-size: 0.82rem; color: var(--gray-700);
    border-bottom: 1px solid var(--gray-100); vertical-align: middle;
}
[data-theme="dark"] .appt-table td { border-color: var(--gray-200); color: var(--gray-700); }
.appt-table tr:last-child td { border-bottom: none; }
.appt-table tbody tr { transition: background 0.12s; cursor: pointer; }
.appt-table tbody tr:hover { background: var(--gray-50); }
[data-theme="dark"] .appt-table tbody tr:hover { background: rgba(255,255,255,0.03); }

.appt-code { font-weight: 700; color: #2563eb; font-size: 0.78rem; }
.appt-name { font-weight: 600; color: var(--gray-800); }
[data-theme="dark"] .appt-name { color: var(--gray-900); }
.appt-service { color: var(--gray-500); font-size: 0.78rem; }
.appt-doctor  { font-size: 0.72rem; color: #2563eb; font-weight: 600; margin-top: 1px; }

/* Status badge */
.st { display: inline-flex; align-items: center; gap: 4px; font-size: 0.72rem; font-weight: 700; padding: 3px 8px; border-radius: 20px; }
.st::before { content:''; width:6px; height:6px; border-radius:50%; background:currentColor; }
.st.pending   { background:#fef3c7; color:#d97706; }
.st.confirmed { background:#dbeafe; color:#1d4ed8; }
.st.completed { background:#dcfce7; color:#15803d; }
.st.cancelled { background:#fee2e2; color:#dc2626; }
.st.no-show   { background:var(--gray-100); color:var(--gray-500); }

/* Section header */
.sec-head {
    display: flex; align-items: center; gap: 8px;
    padding: 14px 18px; border-bottom: 1px solid var(--gray-100);
    font-size: 0.82rem; font-weight: 700; color: var(--gray-700);
}
[data-theme="dark"] .sec-head { border-color: var(--gray-200); color: var(--gray-700); }
.sec-head-right { margin-left: auto; display: flex; align-items: center; gap: 8px; }
</style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<div class="main-content">
    <?php include 'includes/header.php'; ?>
    <div class="page-content">
        <?php if (!empty($_GET['session_expired'])): ?>
        <div class="alert alert-warning" style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
            <i class="bi bi-clock-history" style="font-size:1.2rem;"></i>
            <div><strong>Your session token was refreshed.</strong> Your form has been reloaded — please fill it in again and click Save.</div>
        </div>
        <?php endif; ?>


        <!-- Premium Welcome Banner -->
        <div class="welcome-banner animate-in">
            <div class="welcome-greeting">📅 <?php echo date('l, F d, Y'); ?></div>
            <div class="welcome-name"><?php echo $greeting; ?>, <?php echo e(explode(' ', $current_user_name)[0]); ?>! 👋</div>
            <div class="welcome-sub">Here's what's happening at the clinic today.</div>
            <div class="welcome-stats">
                <div class="welcome-stat-item">
                    <span class="welcome-stat-val"><?php echo $todays_appointments; ?></span>
                    <span class="welcome-stat-lbl">Today's Appointments</span>
                </div>
                <?php if ($pending_appointments > 0): ?>
                <div class="welcome-stat-sep"></div>
                <div class="welcome-stat-item">
                    <span class="welcome-stat-val"><?php echo $pending_appointments; ?></span>
                    <span class="welcome-stat-lbl">Pending</span>
                </div>
                <?php endif; ?>
                <?php if ($unpaid_count > 0): ?>
                <div class="welcome-stat-sep"></div>
                <div class="welcome-stat-item">
                    <span class="welcome-stat-val"><?php echo $unpaid_count; ?></span>
                    <span class="welcome-stat-lbl">Unpaid Bills</span>
                </div>
                <?php endif; ?>
            </div>
            <div class="welcome-actions">
                <a href="modules/walkin/add.php" class="btn-welcome btn-welcome-primary">
                    <i class="bi bi-person-plus-fill"></i> New Walk-in
                </a>
                <a href="modules/appointments/list.php" class="btn-welcome btn-welcome-outline">
                    <i class="bi bi-calendar-check"></i> View Appointments
                </a>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="dash-kpi-grid">

            <a href="modules/patients/list.php" class="dash-kpi">
                <div class="kpi-top">
                    <span class="kpi-label">Total Patients</span>
                    <div class="kpi-icon blue"><i class="bi bi-people-fill"></i></div>
                </div>
                <div class="kpi-value"><?php echo number_format($total_patients); ?></div>
                <div>
                    <?php if ($pat_trend['has']): ?>
                    <span class="kpi-trend <?php echo $pat_trend['up'] ? 'up' : 'down'; ?>">
                        <i class="bi bi-arrow-<?php echo $pat_trend['up'] ? 'up' : 'down'; ?>-short"></i>
                        <?php echo $pat_trend['pct']; ?>% this month
                    </span>
                    <?php else: ?>
                    <span class="kpi-trend flat">All registered →</span>
                    <?php endif; ?>
                </div>
            </a>

            <a href="modules/appointments/list.php?date=<?php echo $today; ?>" class="dash-kpi">
                <div class="kpi-top">
                    <span class="kpi-label">Today's Appointments</span>
                    <div class="kpi-icon teal"><i class="bi bi-calendar-day"></i></div>
                </div>
                <div class="kpi-value"><?php echo $todays_appointments; ?></div>
                <div><span class="kpi-trend flat"><?php echo date('M d, Y'); ?></span></div>
            </a>

            <a href="modules/appointments/list.php?status=pending" class="dash-kpi">
                <div class="kpi-top">
                    <span class="kpi-label">Pending</span>
                    <div class="kpi-icon amber"><i class="bi bi-hourglass-split"></i></div>
                </div>
                <div class="kpi-value"><?php echo $pending_appointments; ?></div>
                <div>
                    <?php if ($pending_appointments > 0): ?>
                    <span class="kpi-trend down"><?php echo $pending_appointments; ?> need confirmation</span>
                    <?php else: ?>
                    <span class="kpi-trend up">All confirmed ✓</span>
                    <?php endif; ?>
                </div>
            </a>

            <a href="modules/appointments/list.php?status=completed" class="dash-kpi">
                <div class="kpi-top">
                    <span class="kpi-label">Revenue This Month</span>
                    <div class="kpi-icon green"><i class="bi bi-cash-coin"></i></div>
                </div>
                <div class="kpi-value sm">₱<?php echo number_format($revenue_month, 0); ?></div>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <span class="kpi-trend flat"><?php echo $completed_month; ?> completed</span>
                    <?php if ($rev_trend['has']): ?>
                    <span class="kpi-trend <?php echo $rev_trend['up'] ? 'up' : 'down'; ?>">
                        <i class="bi bi-arrow-<?php echo $rev_trend['up'] ? 'up' : 'down'; ?>-short"></i>
                        <?php echo $rev_trend['pct']; ?>%
                    </span>
                    <?php endif; ?>
                </div>
            </a>

        </div><!-- /kpi -->

        <!-- Notifications -->
        <?php if (!empty($notifications)): ?>
        <div style="margin-bottom:16px;">
            <?php foreach ($notifications as $n): ?>
            <div class="alert alert-info alert-dismissible d-flex align-items-center gap-2" style="margin-bottom:8px;border-radius:10px;font-size:0.82rem;">
                <i class="bi bi-bell-fill" style="flex-shrink:0;opacity:0.7;"></i>
                <div style="flex:1;min-width:0;">
                    <strong><?php echo htmlspecialchars($n['title']); ?></strong> —
                    <?php echo htmlspecialchars($n['message']); ?>
                    <span style="font-size:0.73rem;color:var(--gray-400);margin-left:8px;">
                        <?php echo date('M d, h:i A', strtotime($n['created_at'])); ?>
                    </span>
                </div>
                <button type="button" class="btn-close" style="flex-shrink:0;" data-bs-dismiss="alert" onclick="markRead(<?php echo $n['id']; ?>)"></button>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Flow Bar -->
        <div class="flow-bar">
            <div style="font-size:0.68rem;color:rgba(255,255,255,0.5);font-weight:700;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:10px;">
                <i class="bi bi-signpost-2"></i> &nbsp;Patient Visit Flow
            </div>
            <div style="display:flex;align-items:center;gap:4px;flex-wrap:wrap;">
                <?php
                $steps = [
                    ['bi-calendar-check-fill', 'Appointment',       'modules/appointments/list.php'],
                    ['bi-person-check-fill',   'Check-in',          'modules/appointments/list.php'],
                    ['bi-journal-medical',     'Record Treatment',  'modules/treatments/add.php'],
                    ['bi-receipt',             'Create Bill',       'modules/billing/create.php'],
                    ['bi-check-circle-fill',   'Done ✓',            'modules/appointments/list.php'],
                ];
                foreach ($steps as $i => [$ico, $lbl, $url]):
                ?>
                <a href="<?php echo BASE_URL.$url; ?>" class="flow-step">
                    <i class="bi <?php echo $ico; ?>"></i><?php echo $lbl; ?>
                </a>
                <?php if ($i < count($steps)-1): ?>
                <i class="bi bi-chevron-right flow-arrow"></i>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <div style="margin-top:8px;font-size:0.7rem;color:rgba(255,255,255,0.35);">
                New patients: start at
                <a href="<?php echo BASE_URL; ?>modules/appointments/list.php?walkin=1" style="color:rgba(255,255,255,0.6);text-decoration:underline;">New Appointment</a>
            </div>
        </div>

        <!-- Body: Today's Schedule + Quick Actions -->
        <div class="dash-body">

            <!-- TODAY'S SCHEDULE + QUICK ACTIONS -->
            <div class="dash-right">

                <!-- Today's Schedule -->
                <div style="background:var(--white);border:1px solid var(--gray-100);border-radius:14px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                    <div class="sec-head">
                        <i class="bi bi-calendar-day" style="color:#0d9488;"></i>
                        Today's Schedule
                        <div class="sec-head-right" style="font-size:0.72rem;color:var(--gray-400);">
                            <?php echo date('M d'); ?>
                        </div>
                    </div>
                    <div style="padding:12px 14px;">
                        <?php if (empty($today_schedule)): ?>
                        <div style="text-align:center;padding:28px 16px;color:var(--gray-400);">
                            <i class="bi bi-calendar-x" style="font-size:2rem;display:block;margin-bottom:8px;opacity:0.35;"></i>
                            <span style="font-size:0.78rem;">No appointments today</span>
                        </div>
                        <?php else: ?>
                        <div style="display:flex;flex-direction:column;gap:6px;">
                        <?php foreach ($today_schedule as $ts):
                            $now_mins = (int)date('H') * 60 + (int)date('i');
                            $appt_mins = (int)substr($ts['appointment_time'],0,2) * 60 + (int)substr($ts['appointment_time'],3,2);
                            $is_now = ($now_mins >= $appt_mins && $now_mins <= $appt_mins + 30);
                            $sc = match($ts['status']) {
                                'confirmed' => ['#2563eb','#dbeafe'],
                                'completed' => ['#16a34a','#dcfce7'],
                                default     => ['#d97706','#fef3c7']
                            };
                        ?>
                        <div class="sched-item" style="border-left-color:<?php echo $sc[0]; ?>;background:<?php echo $is_now ? $sc[1] : ''; ?>;">
                            <div style="min-width:56px;padding-top:1px;">
                                <div style="font-size:0.72rem;font-weight:700;color:<?php echo $sc[0]; ?>;">
                                    <?php echo date('h:i A', strtotime($ts['appointment_time'])); ?>
                                </div>
                            </div>
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:0.8rem;font-weight:600;color:var(--gray-800);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                    <?php echo e(ucwords(strtolower($ts['patient_name'] ?? ''))); ?>
                                </div>
                                <div style="font-size:0.7rem;color:var(--gray-400);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                    <?php echo e($ts['service_name'] ?? '—'); ?>
                                    <?php if (!empty($ts['doctor_name'])): ?>· <?php echo e($ts['doctor_name']); ?><?php endif; ?>
                                </div>
                            </div>
                            <?php if ($is_now): ?>
                            <span style="font-size:0.6rem;font-weight:700;background:<?php echo $sc[0]; ?>;color:#fff;padding:2px 5px;border-radius:4px;flex-shrink:0;">NOW</span>
                            <?php else: ?>
                            <span class="st <?php echo $ts['status']; ?>" style="flex-shrink:0;"></span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        </div>
                        <?php if (count($today_schedule) >= 8): ?>
                        <a href="modules/appointments/list.php?date=<?php echo $today; ?>"
                           style="display:block;text-align:center;margin-top:10px;font-size:0.75rem;color:#2563eb;font-weight:600;text-decoration:none;padding:6px;border-radius:7px;background:var(--gray-50);">
                            View all today →
                        </a>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div style="background:var(--white);border:1px solid var(--gray-100);border-radius:14px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                    <div class="sec-head">
                        <i class="bi bi-lightning-fill" style="color:#d97706;"></i>
                        Quick Actions
                    </div>
                    <div style="padding:14px;display:flex;flex-direction:column;gap:7px;">
                        <a href="modules/patients/add.php" class="qa-btn primary">
                            <i class="bi bi-person-plus" style="flex-shrink:0;"></i> Add New Patient
                        </a>
                        <a href="modules/appointments/list.php?walkin=1" class="qa-btn success">
                            <i class="bi bi-person-walking" style="flex-shrink:0;"></i> New Appointment
                        </a>
                        <a href="modules/appointments/calendar.php" class="qa-btn outline">
                            <i class="bi bi-calendar3" style="flex-shrink:0;"></i> View Calendar
                        </a>
                        <a href="modules/billing/list.php" class="qa-btn outline">
                            <i class="bi bi-receipt" style="flex-shrink:0;"></i> Billing
                        </a>
                        <a href="modules/treatments/add.php" class="qa-btn outline">
                            <i class="bi bi-journal-medical" style="flex-shrink:0;"></i> Add Dental Record
                        </a>
                        <?php if (is_admin()): ?>
                        <hr style="margin:4px 0;border-color:var(--gray-100);">
                        <a href="modules/analytics/dashboard.php" class="qa-btn outline">
                            <i class="bi bi-bar-chart-line" style="flex-shrink:0;"></i> Analytics
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

            </div><!-- /dash-right -->

        </div><!-- /dash-body -->

    </div>
<script>
function markRead(id) {
    fetch('<?php echo BASE_URL; ?>api/notifications.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'mark_read', id: id })
    });
}
</script>
</div>
<?php include 'includes/footer.php'; ?>
</body>
</html>