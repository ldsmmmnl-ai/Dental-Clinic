<?php
require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

$page_title = 'Calendar';

$view  = $_GET['view'] ?? 'month';
$today = date('Y-m-d');

// Month params
$year  = intval($_GET['year']  ?? date('Y'));
$month = intval($_GET['month'] ?? date('n'));
if ($month < 1)  { $month = 12; $year--; }
if ($month > 12) { $month = 1;  $year++; }

// Day view param
$day_date = $_GET['date'] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day_date)) $day_date = $today;

// Month helpers
$month_start = sprintf('%04d-%02d-01', $year, $month);
$month_end   = date('Y-m-t', strtotime($month_start));
$month_label = date('F Y', strtotime($month_start));
$prev_month = $month - 1; $prev_year = $year;
if ($prev_month < 1)  { $prev_month = 12; $prev_year--; }
$next_month = $month + 1; $next_year = $year;
if ($next_month > 12) { $next_month = 1;  $next_year++; }

// Doctor palette
$doctor_palette = [
    ['bg'=>'#e8f0fe','border'=>'#4285f4','text'=>'#1a56db'],
    ['bg'=>'#fce8f3','border'=>'#e040fb','text'=>'#8e24aa'],
    ['bg'=>'#e6f4ea','border'=>'#34a853','text'=>'#137333'],
    ['bg'=>'#fef7e0','border'=>'#fbbc04','text'=>'#b06000'],
    ['bg'=>'#fce8e6','border'=>'#ea4335','text'=>'#c5221f'],
    ['bg'=>'#e4f7fb','border'=>'#00acc1','text'=>'#006064'],
    ['bg'=>'#fff3e0','border'=>'#ff6d00','text'=>'#bf360c'],
    ['bg'=>'#f3e5f5','border'=>'#9c27b0','text'=>'#6a1b9a'],
];
$default_color = ['bg'=>'#f0f3f4','border'=>'#5d6d7e','text'=>'#2c3e50'];
$all_doctors_raw = $conn->query("SELECT id, full_name FROM doctors WHERE is_active = TRUE ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$doctor_color_map = [];
foreach ($all_doctors_raw as $i => $dr) {
    $doctor_color_map[$dr['id']] = $doctor_palette[$i % 8];
}

$status_dot = ['pending'=>'#f39c12','confirmed'=>'#2980b9','completed'=>'#27ae60','cancelled'=>'#e74c3c','no-show'=>'#95a5a6'];
$status_labels = ['pending'=>'Pending','confirmed'=>'Confirmed','completed'=>'Completed','cancelled'=>'Cancelled','no-show'=>'No-Show'];

// Initialize variables used conditionally (prevents "undefined variable" warnings)
$first_day_of_week = 0;
$days_in_month = 0;
$appts_by_date = [];
$blocked_by_date = [];
$day_label = '';
$prev_day = '';
$next_day = '';
$day_appts = [];
$sched = null;

// Month view data
if ($view === 'month') {
    $appts_raw = $conn->query("
        SELECT a.id, a.appointment_code, a.appointment_date, a.appointment_time,
               a.status, a.patient_id, a.notes,
               CONCAT(p.first_name,' ',p.last_name) as patient_name,
               p.patient_code, p.phone, p.allergies,
               s.service_name, s.duration_minutes, s.price as service_price,
               d.full_name as doctor_name, d.id as doctor_id,
               b.id as bill_id, b.amount_due, b.amount_paid, b.status as bill_status
        FROM appointments a
        LEFT JOIN patients p ON a.patient_id = p.id
        LEFT JOIN services s ON a.service_id = s.id
        LEFT JOIN doctors  d ON a.doctor_id  = d.id
        LEFT JOIN bills    b ON b.appointment_id = a.id AND b.id = (SELECT MAX(b2.id) FROM bills b2 WHERE b2.appointment_id = a.id)
        WHERE a.appointment_date BETWEEN '$month_start' AND '$month_end'
        ORDER BY a.appointment_time ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $blocked_raw = $conn->query("SELECT blocked_date, reason FROM blocked_dates WHERE blocked_date BETWEEN '$month_start' AND '$month_end'")->fetchAll(PDO::FETCH_ASSOC);
    $appts_by_date = [];
    foreach ($appts_raw as $a) { $appts_by_date[$a['appointment_date']][] = $a; }
    $blocked_by_date = [];
    foreach ($blocked_raw as $b) { $blocked_by_date[$b['blocked_date']] = $b['reason']; }
    $first_day_of_week = intval(date('w', strtotime($month_start)));
    $days_in_month     = intval(date('t', strtotime($month_start)));
}

// Day view data
if ($view === 'day') {
    $day_appts = $conn->query("
        SELECT a.id, a.appointment_code, a.appointment_date, a.appointment_time,
               a.status, a.patient_id, a.notes,
               CONCAT(p.first_name,' ',p.last_name) as patient_name,
               p.patient_code, p.phone, p.allergies, p.blood_type, p.medical_notes,
               s.service_name, s.duration_minutes, s.price as service_price,
               d.full_name as doctor_name, d.id as doctor_id,
               b.id as bill_id, b.amount_due, b.amount_paid, b.status as bill_status
        FROM appointments a
        LEFT JOIN patients p ON a.patient_id = p.id
        LEFT JOIN services s ON a.service_id = s.id
        LEFT JOIN doctors  d ON a.doctor_id  = d.id
        LEFT JOIN bills    b ON b.appointment_id = a.id AND b.id = (SELECT MAX(b2.id) FROM bills b2 WHERE b2.appointment_id = a.id)
        WHERE a.appointment_date = '$day_date' AND a.status NOT IN ('cancelled')
        ORDER BY a.appointment_time ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $day_name = strtolower(date('l', strtotime($day_date)));
    $sched = $conn->query("SELECT open_time, close_time, slot_duration_minutes FROM schedules WHERE day_of_week = '$day_name' AND is_open = TRUE LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $day_label = date('l, F j, Y', strtotime($day_date));
    $prev_day  = date('Y-m-d', strtotime($day_date . ' -1 day'));
    $next_day  = date('Y-m-d', strtotime($day_date . ' +1 day'));
}

// Week view data
$week_appts_by_day = [];
$week_start = $today;
$week_end   = $today;
$week_days  = [];
$week_label = '';
$prev_week  = $today;
$next_week  = $today;
if ($view === 'week') {
    $ref_date = $_GET['date'] ?? $today;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ref_date)) $ref_date = $today;
    $dow        = date('N', strtotime($ref_date)); // 1=Mon 7=Sun
    $week_start = date('Y-m-d', strtotime($ref_date . ' -' . ($dow - 1) . ' days'));
    $week_end   = date('Y-m-d', strtotime($week_start . ' +6 days')); // Mon–Sun (grid shows all 7 days)
    $prev_week  = date('Y-m-d', strtotime($week_start . ' -7 days'));
    $next_week  = date('Y-m-d', strtotime($week_start . ' +7 days'));
    $week_label = date('M d', strtotime($week_start)) . ' – ' . date('M d, Y', strtotime($week_end));
    for ($i = 0; $i < 7; $i++) {
        $week_days[] = date('Y-m-d', strtotime($week_start . " +$i days"));
    }
    $week_appts_raw = $conn->query("
        SELECT a.id, a.appointment_code, a.appointment_date, a.appointment_time,
               a.status, a.patient_id, a.notes,
               CONCAT(p.first_name,' ',p.last_name) as patient_name,
               p.patient_code, p.phone, p.allergies,
               s.service_name, s.duration_minutes,
               d.full_name as doctor_name, d.id as doctor_id,
               b.id as bill_id, b.amount_due, b.amount_paid, b.status as bill_status
        FROM appointments a
        LEFT JOIN patients p ON a.patient_id = p.id
        LEFT JOIN services s ON a.service_id = s.id
        LEFT JOIN doctors  d ON a.doctor_id  = d.id
        LEFT JOIN bills    b ON b.appointment_id = a.id AND b.id = (SELECT MAX(b2.id) FROM bills b2 WHERE b2.appointment_id = a.id)
        WHERE a.appointment_date BETWEEN '$week_start' AND '$week_end'
        AND a.status NOT IN ('cancelled')
        ORDER BY a.appointment_time ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($week_days as $wd) $week_appts_by_day[$wd] = [];
    foreach ($week_appts_raw as $a) $week_appts_by_day[$a['appointment_date']][] = $a;
    // Use first open schedule for hour range
    if (!$sched) $sched = $conn->query("SELECT open_time, close_time, slot_duration_minutes FROM schedules WHERE is_open = TRUE LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}

$walkin_services = $conn->query("SELECT id, service_name, price FROM services WHERE is_active = TRUE ORDER BY service_name")->fetchAll(PDO::FETCH_ASSOC);
// Needed by the drawer doctor dropdown
$all_docs_dw = $conn->query("SELECT id, full_name, specialization, schedule_days FROM doctors WHERE is_active = TRUE ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
?><!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?>
<style>
/* ── View Toggle ─────────────────────────────────────────── */
.view-toggle{display:flex;background:var(--gray-100);border-radius:10px;padding:3px;gap:2px;}
.view-toggle a{padding:6px 16px;font-size:0.8rem;font-weight:600;text-decoration:none;color:var(--gray-500);border-radius:8px;transition:all 0.18s;}
.view-toggle a.active{background:var(--white);color:#2563eb;box-shadow:0 1px 6px rgba(37,99,235,0.12);}
.view-toggle a:hover:not(.active){background:var(--gray-50);color:var(--gray-700);}

/* ── Calendar Wrap ───────────────────────────────────────── */
.calendar-wrap{
  border-radius:16px;
  overflow:hidden;
  border:none;
  box-shadow:var(--shadow-md);
}

/* ── Day-of-week header ──────────────────────────────────── */
.cal-header-row{display:grid;grid-template-columns:repeat(7,1fr);}
.cal-dow{
  padding:12px 0;
  text-align:center;
  font-size:0.72rem;
  font-weight:800;
  text-transform:uppercase;
  letter-spacing:0.08em;
  color:var(--gray-400);
  background:linear-gradient(to bottom,var(--gray-50),var(--gray-100));
  border-bottom:1px solid var(--gray-200);
}
.cal-dow:first-child{color:#e11d48;} /* Sunday */
.cal-dow:last-child{color:#e11d48;}  /* Saturday */

/* ── Calendar Grid ───────────────────────────────────────── */
.cal-grid{display:grid;grid-template-columns:repeat(7,1fr);}
.cal-cell{
  min-height:130px;
  padding:8px 9px 6px;
  border-right:1px solid var(--gray-100);
  border-bottom:1px solid var(--gray-100);
  background:var(--white);
  transition:background 0.15s;
  position:relative;
}
.cal-cell:nth-child(7n){border-right:none;}
.cal-cell:nth-child(7n+1) .day-num,
.cal-cell:nth-child(7n) .day-num{color:#e11d48;} /* Sun/Sat in red */

.cal-cell.empty{background:var(--gray-50);opacity:0.6;}
.cal-cell.today{background:linear-gradient(135deg,var(--blue-50),var(--blue-100))!important;}
.cal-cell.today::before{
  content:'';position:absolute;top:0;left:0;right:0;height:3px;
  background:linear-gradient(90deg,#2563eb,#60a5fa);border-radius:0;
}
.cal-cell.blocked{background:var(--danger-bg);}
.cal-cell.has-appts{cursor:pointer;}
.cal-cell.has-appts:hover{background:var(--blue-50);}

/* ── Day Number ──────────────────────────────────────────── */
.day-num{
  font-size:0.78rem;font-weight:700;color:var(--gray-600);
  margin-bottom:6px;display:flex;align-items:center;gap:4px;
}
.today-dot{
  width:24px;height:24px;
  background:linear-gradient(135deg,#2563eb,#3b82f6);
  border-radius:50%;color:var(--white);font-size:0.68rem;font-weight:800;
  display:flex;align-items:center;justify-content:center;
  box-shadow:0 2px 6px rgba(37,99,235,0.35);
}

/* ── Appointment Chips ───────────────────────────────────── */
.appt-chip{
  display:flex;align-items:center;gap:4px;
  margin-bottom:3px;
  border-radius:6px;
  padding:3px 7px;
  font-size:0.69rem;font-weight:600;
  cursor:pointer;
  border-left:3px solid;
  transition:transform 0.1s, box-shadow 0.1s;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
  max-width:100%;
  box-shadow:0 1px 3px rgba(0,0,0,0.06);
}
.appt-chip:hover{
  transform:translateY(-1px);
  box-shadow:0 3px 8px rgba(0,0,0,0.12);
}
.status-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0;}
.chip-time{font-size:0.6rem;opacity:0.7;flex-shrink:0;font-weight:700;}
.more-pill{
  font-size:0.67rem;color:#2563eb;font-weight:700;
  cursor:pointer;margin-top:3px;display:inline-flex;align-items:center;gap:2px;
  background:#eff6ff;border-radius:20px;padding:1px 8px;border:1px solid #bfdbfe;
}
.more-pill:hover{background:#dbeafe;}
.blocked-tag{
  font-size:0.67rem;color:#dc2626;
  background:#fee2e2;border-radius:20px;
  padding:1px 8px;display:inline-flex;align-items:center;gap:3px;
  border:1px solid #fecaca;font-weight:600;
}

/* ── Legend Pills ────────────────────────────────────────── */
.svc-legend{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;}
.svc-pill{
  font-size:0.7rem;padding:3px 10px;border-radius:20px;
  font-weight:600;border-left:3px solid;
  box-shadow:var(--shadow-sm);
}

/* ── Nav Bar ─────────────────────────────────────────────── */
.cal-nav-bar{
  display:flex;align-items:center;gap:10px;margin-bottom:14px;
  background:var(--white);border:1px solid var(--gray-200);border-radius:12px;
  padding:8px 14px;box-shadow:var(--shadow-sm);
}
.cal-nav-btn{
  display:inline-flex;align-items:center;justify-content:center;
  width:32px;height:32px;border-radius:8px;
  border:var(--border);background:var(--white);
  color:var(--gray-600);text-decoration:none;font-size:0.85rem;
  transition:all 0.15s;
}
.cal-nav-btn:hover{background:var(--gray-100);border-color:var(--gray-300);color:var(--gray-900);}
.cal-nav-label{
  font-size:0.95rem;font-weight:700;color:var(--gray-900);
  min-width:140px;text-align:center;
}
.cal-today-btn{
  padding:4px 14px;border-radius:8px;font-size:0.78rem;font-weight:700;
  background:linear-gradient(135deg,#2563eb,#3b82f6);color:var(--white);
  border:none;text-decoration:none;cursor:pointer;
  box-shadow:0 2px 6px rgba(37,99,235,0.3);transition:all 0.15s;
}
.cal-today-btn:hover{box-shadow:0 4px 10px rgba(37,99,235,0.4);color:var(--white);}

/* ── Day View ────────────────────────────────────────────── */
.day-view-wrap{
  display:grid;grid-template-columns:64px 1fr;
  border:none;border-radius:16px;overflow:hidden;background:var(--white);
  box-shadow:var(--shadow-md);
}
.day-time-gutter{background:var(--gray-50);border-right:1px solid var(--gray-200);}
.day-time-slot{
  height:60px;display:flex;align-items:flex-start;
  justify-content:flex-end;padding:4px 10px 0 0;
  font-size:0.63rem;color:var(--gray-400);font-weight:700;
  border-bottom:1px solid var(--gray-100);letter-spacing:0.02em;
}
.day-appt-block{
  position:absolute;left:8px;right:8px;
  border-radius:10px;padding:7px 10px;border-left:4px solid;
  cursor:pointer;overflow:hidden;
  transition:transform 0.12s, box-shadow 0.12s;
  box-shadow:0 2px 8px rgba(0,0,0,0.1);z-index:2;
}
.day-appt-block:hover{transform:translateY(-1px) scale(1.005);box-shadow:0 6px 18px rgba(0,0,0,0.14);z-index:3;}
.day-appt-title{font-size:0.79rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.day-appt-sub{font-size:0.68rem;opacity:0.8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px;}
.day-appt-time{font-size:0.62rem;opacity:0.65;margin-top:2px;}
.allergy-badge{
  display:inline-flex;align-items:center;gap:3px;
  font-size:0.6rem;font-weight:800;
  background:#fee2e2;color:#dc2626;
  border-radius:4px;padding:1px 6px;margin-top:2px;
}
.day-now-line{position:absolute;left:0;right:0;height:2px;background:#ef4444;z-index:10;pointer-events:none;}
.day-now-line::before{
  content:'';position:absolute;left:-6px;top:-5px;
  width:12px;height:12px;border-radius:50%;background:#ef4444;
  box-shadow:0 0 6px rgba(239,68,68,0.5);
}

/* ── Appointment Modal ───────────────────────────────────── */
.allergy-alert{
  display:flex;align-items:center;gap:8px;
  background:#fef2f2;border:1px solid #fecaca;border-radius:10px;
  padding:10px 14px;margin:14px 0 0;
  font-size:0.8rem;color:#dc2626;font-weight:600;
}
.modal-info-row{
  display:flex;align-items:center;gap:10px;
  padding:9px 0;border-bottom:1px solid var(--gray-100);font-size:0.83rem;
}
.modal-info-row:last-child{border-bottom:none;}
.balance-pill{display:inline-flex;align-items:center;gap:5px;padding:3px 11px;border-radius:20px;font-size:0.78rem;font-weight:700;}
.status-btn{
  padding:5px 14px;border-radius:20px;border:2px solid;
  font-size:0.75rem;font-weight:700;cursor:pointer;
  transition:all 0.15s;background:transparent;
}
.status-btn.active{color:#fff!important;}
.status-btn:hover:not(.active){opacity:0.75;transform:translateY(-1px);}
.day-modal-appt{
  display:flex;align-items:center;gap:10px;
  padding:11px 14px;border-radius:10px;
  margin-bottom:8px;border-left:4px solid;
  transition:transform 0.12s;
}
.day-modal-appt:hover{transform:translateX(2px);}

/* ═══════════════════════════════════════════════
   MOBILE CALENDAR — phones ≤ 640px
   ═══════════════════════════════════════════════ */
@media (max-width: 640px) {

    /* ── Page header: stack title + controls ── */
    .cal-page-header {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 10px !important;
        padding: 10px 12px !important;
    }
    .cal-page-header .view-toggle { margin-left: 0 !important; align-self: flex-start; }
    .cal-header-actions {
        display: flex !important;
        gap: 8px !important;
        width: 100% !important;
    }
    .cal-header-actions > * { flex: 1 !important; justify-content: center !important; }

    /* ── View toggle: scrollable row ── */
    .view-toggle {
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch !important;
        scrollbar-width: none !important;
        flex-wrap: nowrap !important;
    }
    .view-toggle::-webkit-scrollbar { display: none; }
    .view-toggle a { white-space: nowrap !important; padding: 6px 12px !important; }

    /* ── Nav bar: compact ── */
    .cal-nav-bar {
        padding: 6px 10px !important;
        gap: 6px !important;
        flex-wrap: wrap !important;
    }
    .cal-nav-label {
        min-width: 0 !important;
        font-size: 0.82rem !important;
        flex: 1 !important;
        text-align: center !important;
    }
    .cal-nav-bar > span:not(.cal-nav-label) { display: none !important; }

    /* ── Legend: horizontal scroll ── */
    .svc-legend {
        flex-wrap: nowrap !important;
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch !important;
        padding-bottom: 4px !important;
        scrollbar-width: none !important;
    }
    .svc-legend::-webkit-scrollbar { display: none; }

    /* ── Month grid: compact cells ── */
    .cal-cell {
        min-height: 54px !important;
        padding: 4px 3px 3px !important;
    }
    .day-num { font-size: 0.68rem !important; margin-bottom: 3px !important; }
    .today-dot {
        width: 20px !important;
        height: 20px !important;
        font-size: 0.6rem !important;
    }

    /* Hide text chips — show dots only */
    .appt-chip { display: none !important; }
    .more-pill { display: none !important; }
    .blocked-tag { font-size: 0.58rem !important; padding: 1px 4px !important; }

    /* Dot row — shown only on mobile */
    .mobile-dot-row {
        display: flex !important;
        flex-wrap: wrap !important;
        gap: 2px !important;
        align-items: center !important;
    }

    /* ── Day-of-week header: abbreviate ── */
    .cal-dow { font-size: 0.6rem !important; padding: 8px 0 !important; letter-spacing: 0 !important; }

    /* ── Week view: show switch-to-day banner ── */
    .week-mobile-tip { display: block !important; }
    .week-grid-wrap  { display: none !important; }

    /* ── Day view: tighter gutter ── */
    .day-view-wrap { grid-template-columns: 46px 1fr !important; }
    .day-time-slot { font-size: 0.58rem !important; padding: 3px 5px 0 0 !important; height: 54px !important; }
    .day-appt-block { padding: 5px 8px !important; border-radius: 8px !important; }
    .day-appt-title { font-size: 0.75rem !important; }

    /* ── Day nav: compact ── */
    .day-nav-bar {
        gap: 8px !important;
        flex-wrap: wrap !important;
    }
    .day-nav-bar h6 { min-width: 0 !important; font-size: 0.82rem !important; flex: 1 !important; text-align: center !important; }
}

/* ═══════════════════════════════════════════════════════════════
   DARK MODE — doctor-color chips/pills + misc hardcoded badges
   These colors are computed in PHP/JS and written as inline styles,
   so they can't read the live theme. Instead we match on the fixed,
   unique inline value each one already carries and re-tint it for
   dark mode, using the same translucent-bg + light-text pattern the
   rest of the app already uses (e.g. the .st.* status badges).
   ═══════════════════════════════════════════════════════════════ */

/* Doctor palette — used by .svc-pill (legend), .appt-chip (month view),
   and .day-appt-block (week/day view). Matched on the doctor's unique
   border-color so every appointment stays color-coded by doctor, just
   with dark-mode-appropriate contrast instead of the pale light-mode bg. */
[data-theme="dark"] .svc-pill[style*="border-color:#4285f4"],
[data-theme="dark"] .appt-chip[style*="border-color:#4285f4"],
[data-theme="dark"] .day-appt-block[style*="border-color:#4285f4"] { background: rgba(66,133,244,0.18) !important; color: #8ab4f8 !important; }

[data-theme="dark"] .svc-pill[style*="border-color:#e040fb"],
[data-theme="dark"] .appt-chip[style*="border-color:#e040fb"],
[data-theme="dark"] .day-appt-block[style*="border-color:#e040fb"] { background: rgba(224,64,251,0.18) !important; color: #ea80fc !important; }

[data-theme="dark"] .svc-pill[style*="border-color:#34a853"],
[data-theme="dark"] .appt-chip[style*="border-color:#34a853"],
[data-theme="dark"] .day-appt-block[style*="border-color:#34a853"] { background: rgba(52,168,83,0.18) !important; color: #81c995 !important; }

[data-theme="dark"] .svc-pill[style*="border-color:#fbbc04"],
[data-theme="dark"] .appt-chip[style*="border-color:#fbbc04"],
[data-theme="dark"] .day-appt-block[style*="border-color:#fbbc04"] { background: rgba(251,188,4,0.18) !important; color: #fdd663 !important; }

[data-theme="dark"] .svc-pill[style*="border-color:#ea4335"],
[data-theme="dark"] .appt-chip[style*="border-color:#ea4335"],
[data-theme="dark"] .day-appt-block[style*="border-color:#ea4335"] { background: rgba(234,67,53,0.18) !important; color: #f28b82 !important; }

[data-theme="dark"] .svc-pill[style*="border-color:#00acc1"],
[data-theme="dark"] .appt-chip[style*="border-color:#00acc1"],
[data-theme="dark"] .day-appt-block[style*="border-color:#00acc1"] { background: rgba(0,172,193,0.18) !important; color: #4dd0e1 !important; }

[data-theme="dark"] .svc-pill[style*="border-color:#ff6d00"],
[data-theme="dark"] .appt-chip[style*="border-color:#ff6d00"],
[data-theme="dark"] .day-appt-block[style*="border-color:#ff6d00"] { background: rgba(255,109,0,0.18) !important; color: #ffb74d !important; }

[data-theme="dark"] .svc-pill[style*="border-color:#9c27b0"],
[data-theme="dark"] .appt-chip[style*="border-color:#9c27b0"],
[data-theme="dark"] .day-appt-block[style*="border-color:#9c27b0"] { background: rgba(156,39,176,0.18) !important; color: #ce93d8 !important; }

[data-theme="dark"] .svc-pill[style*="border-color:#5d6d7e"],
[data-theme="dark"] .appt-chip[style*="border-color:#5d6d7e"],
[data-theme="dark"] .day-appt-block[style*="border-color:#5d6d7e"] { background: rgba(93,109,126,0.20) !important; color: #b0c4de !important; }

/* "No Dental Record" warning modal */
[data-theme="dark"] #noRecordModal .modal-title { color: #fbbf24 !important; }
[data-theme="dark"] #noRecordModal p[style*="color:#6b7280"] { color: var(--gray-500) !important; }
[data-theme="dark"] #noRecordModal div[style*="background:#fef3c7"] { background: rgba(217,119,6,0.16) !important; color: #fbbf24 !important; border-color: rgba(217,119,6,0.35) !important; }

/* Walk-in booking success toast */
[data-theme="dark"] #walkinToastTitle { color: #86efac !important; }
[data-theme="dark"] #walkinToastMsg { color: var(--gray-700) !important; }
[data-theme="dark"] #walkinToast { border-color: rgba(34,197,94,0.35) !important; }
[data-theme="dark"] #walkinToast button[style*="color:#9ca3af"] { color: var(--gray-500) !important; }

/* Mobile "switch to Day view" tip banner (week view) */
[data-theme="dark"] .week-mobile-tip { background: rgba(37,99,235,0.14) !important; border-color: rgba(37,99,235,0.35) !important; }
[data-theme="dark"] .week-mobile-tip div[style*="color:#1e40af"] { color: #93c5fd !important; }
[data-theme="dark"] .week-mobile-tip div[style*="color:#3b82f6"] { color: #bfdbfe !important; }

/* "+N more" pill (month view) and "Closed" / allergy / balance pills */
[data-theme="dark"] .more-pill { background: rgba(37,99,235,0.16) !important; border-color: rgba(37,99,235,0.4) !important; color: #93c5fd !important; }
[data-theme="dark"] .more-pill:hover { background: rgba(37,99,235,0.24) !important; }
[data-theme="dark"] .blocked-tag,
[data-theme="dark"] [style*="background:#fee2e2"] { background: rgba(220,38,38,0.16) !important; }
[data-theme="dark"] [style*="color:#dc2626"] { color: #fca5a5 !important; }
[data-theme="dark"] [style*="background:#fef2f2"] { background: rgba(220,38,38,0.12) !important; }
[data-theme="dark"] .balance-pill[style*="background:#dcfce7"] { background: rgba(22,163,74,0.18) !important; color: #86efac !important; }
[data-theme="dark"] .balance-pill[style*="background:#fef9c3"] { background: rgba(217,119,6,0.18) !important; color: #fde68a !important; }
</style>
</head>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
<?php include '../../includes/header.php'; ?>
<div class="page-content">

<div class="cal-page-header" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:18px;background:var(--white);border:1px solid var(--gray-200);border-radius:14px;padding:10px 16px;box-shadow:var(--shadow-sm);">
    <div style="display:flex;align-items:center;gap:8px;">
        <div style="width:36px;height:36px;background:linear-gradient(135deg,#2563eb,#60a5fa);border-radius:10px;display:flex;align-items:center;justify-content:center;">
            <i class="bi bi-calendar3" style="color:var(--white);font-size:1rem;"></i>
        </div>
        <div>
            <div style="font-size:0.95rem;font-weight:800;color:var(--gray-900);line-height:1.1;">Appointment Calendar</div>
            <div style="font-size:0.72rem;color:var(--gray-400);font-weight:500;"><?php echo date('F Y'); ?></div>
        </div>
    </div>
    <div class="view-toggle" style="margin-left:10px;">
        <a href="calendar.php?view=month&month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="<?php echo $view==='month'?'active':''; ?>"><i class="bi bi-calendar3"></i> Month</a>
        <?php
            // Week link: stay in current week if already in week view, otherwise jump to today's week
            if ($view === 'week')       $ctx_week = $week_start;
            elseif ($view === 'day')    $ctx_week = $day_date;
            else                        $ctx_week = $today;

            // Day link: always jump to TODAY — never the week start
            $ctx_day = ($view === 'day') ? $day_date : $today;
        ?>
        <a href="calendar.php?view=week&date=<?php echo $ctx_week; ?>" class="<?php echo $view==='week'?'active':''; ?>"><i class="bi bi-calendar-week"></i> Week</a>
        <a href="calendar.php?view=day&date=<?php echo $ctx_day; ?>" class="<?php echo $view==='day'?'active':''; ?>"><i class="bi bi-calendar-day"></i> Day</a>
    </div>
    <div class="cal-header-actions" style="margin-left:auto;display:flex;gap:8px;align-items:center;">
        <button onclick="openWalkinDrawer()" style="display:inline-flex;align-items:center;gap:6px;padding:7px 16px;border-radius:10px;background:linear-gradient(135deg,#16a34a,#22c55e);color:var(--white);border:none;font-size:0.82rem;font-weight:700;cursor:pointer;box-shadow:0 2px 8px rgba(22,163,74,0.3);transition:all 0.15s;" onmouseover="this.style.boxShadow='0 4px 14px rgba(22,163,74,0.45)'" onmouseout="this.style.boxShadow='0 2px 8px rgba(22,163,74,0.3)'">
            <i class="bi bi-plus-circle-fill"></i> New Appointment
        </button>
        <a href="list.php" style="display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border-radius:10px;background:var(--gray-50);color:var(--gray-600);border:1px solid var(--gray-200);font-size:0.82rem;font-weight:600;text-decoration:none;transition:all 0.15s;" onmouseover="this.style.background='var(--gray-100)'" onmouseout="this.style.background='var(--gray-50)'">
            <i class="bi bi-list-ul"></i> List View
        </a>
    </div>
</div>

<?php if ($view === 'month'): ?>
<!-- MONTH VIEW -->
<div class="cal-nav-bar">
    <a href="calendar.php?view=month&month=<?php echo $prev_month; ?>&year=<?php echo $prev_year; ?>" class="cal-nav-btn"><i class="bi bi-chevron-left"></i></a>
    <span class="cal-nav-label"><?php echo $month_label; ?></span>
    <a href="calendar.php?view=month&month=<?php echo $next_month; ?>&year=<?php echo $next_year; ?>" class="cal-nav-btn"><i class="bi bi-chevron-right"></i></a>
    <span style="margin-left:auto;font-size:0.78rem;color:var(--gray-400);font-weight:600;">
        <?php $total_this_month = array_sum(array_map('count', $appts_by_date)); ?>
        <?php echo $total_this_month; ?> appointment<?php echo $total_this_month !== 1 ? 's' : ''; ?> this month
    </span>
</div>
<?php if (!empty($all_doctors_raw)): ?>
<div class="svc-legend">
<?php foreach ($all_doctors_raw as $dr): $c=$doctor_color_map[$dr['id']]??$default_color; ?>
<span class="svc-pill" style="background:<?php echo $c['bg'];?>;border-color:<?php echo $c['border'];?>;color:<?php echo $c['text'];?>"><?php echo htmlspecialchars($dr['full_name']);?></span>
<?php endforeach; ?>
<span class="svc-pill" style="background:#f0f3f4;border-color:#95a5a6;color:#5d6d7e;">Unassigned</span>
<span class="svc-pill" style="background:#fff5f5;border-color:#e74c3c;color:#c0392b;">Blocked</span>
</div>
<?php endif; ?>
<div style="display:flex;gap:10px;flex-wrap:wrap;font-size:0.74rem;margin-bottom:14px;background:var(--gray-50);border:1px solid var(--gray-200);border-radius:10px;padding:8px 14px;">
<span style="font-size:0.7rem;font-weight:700;color:var(--gray-400);text-transform:uppercase;letter-spacing:0.05em;align-self:center;margin-right:4px;">Status:</span>
<?php foreach ($status_dot as $s=>$color): ?>
<span style="display:flex;align-items:center;gap:5px;font-weight:600;color:var(--gray-600);">
    <span style="width:9px;height:9px;border-radius:50%;background:<?php echo $color;?>;display:inline-block;box-shadow:0 0 0 2px <?php echo $color;?>33;"></span>
    <?php echo ucfirst($s);?>
</span>
<?php endforeach; ?>
</div>
<?php $total_cells=ceil(($first_day_of_week+$days_in_month)/7)*7; ?>
<div class="calendar-wrap">
    <div class="cal-header-row"><?php foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $dow): ?><div class="cal-dow"><?php echo $dow;?></div><?php endforeach;?></div>
    <div class="cal-grid">
    <?php
    $d=1;
    for($i=0;$i<$total_cells;$i++): if($i<$first_day_of_week||$d>$days_in_month): ?>
    <div class="cal-cell empty"></div>
    <?php else:
        $ds=sprintf('%04d-%02d-%02d',$year,$month,$d);
        $it=($ds===$today); $ib=isset($blocked_by_date[$ds]);
        $dam=$appts_by_date[$ds]??[]; $ha=!empty($dam)||$ib;
        $cc=implode(' ',array_filter(['cal-cell',$it?'today':'',$ib?'blocked':'',$ha?'has-appts':'']));
    ?>
    <div class="<?php echo $cc;?>" data-cal-date="<?php echo $ds;?>" onclick="handleCalCellClick(event, this)">
        <div class="day-num <?php echo $it?'is-today':'';?>">
            <?php if($it): ?><span class="today-dot"><?php echo $d;?></span><?php else: ?><?php echo $d;?><?php endif;?>
        </div>
        <?php if($ib): ?><span class="blocked-tag">🚫 Closed</span><?php endif;?>
        <?php $shown=0; foreach($dam as $a): if($shown>=3) break;
            $c=isset($a['doctor_id'])&&$a['doctor_id']?($doctor_color_map[$a['doctor_id']]??$default_color):$default_color;
            $dot=$status_dot[$a['status']]??'#95a5a6';
            $time=date('h:i A',strtotime($a['appointment_time']));
            $first=explode(' ',ucwords(strtolower($a['patient_name']??'')))[0];
        ?>
        <div class="appt-chip" data-appt-id="<?php echo $a['id']; ?>" style="background:<?php echo $c['bg'];?>;border-color:<?php echo $c['border'];?>;color:<?php echo $c['text'];?>;"
             onclick="event.stopPropagation();openApptModal(<?php echo htmlspecialchars(json_encode($a),ENT_QUOTES);?>)"
             title="<?php echo htmlspecialchars($time.' — '.ucwords(strtolower($a['patient_name']??'')).' | '.($a['service_name']??''));?>">
            <span class="status-dot" style="background:<?php echo $dot;?>;"></span>
            <span class="chip-time"><?php echo $time;?></span>
            <span style="overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($first);?></span>
        </div>
        <?php $shown++; endforeach;?>
        <?php $rem=count($dam)-$shown; if($rem>0): ?><span class="more-pill" onclick="event.stopPropagation();viewDay('<?php echo $ds;?>')">+<?php echo $rem;?> more</span><?php endif;?>
        <!-- Mobile: colored dots instead of chips -->
        <?php if(!empty($dam)): ?>
        <div class="mobile-dot-row" style="display:none;">
            <?php foreach(array_slice($dam,0,5) as $md):
                $mc=isset($md['doctor_id'])&&$md['doctor_id']?($doctor_color_map[$md['doctor_id']]??$default_color):$default_color;
            ?><span style="width:7px;height:7px;border-radius:50%;background:<?php echo $mc['border'];?>;flex-shrink:0;"></span><?php endforeach;?>
            <?php if(count($dam)>5): ?><span style="font-size:0.55rem;color:var(--gray-500);font-weight:700;line-height:1;">+<?php echo count($dam)-5;?></span><?php endif;?>
        </div>
        <?php endif;?>
    </div>
    <?php $d++; endif; endfor;?>
    </div>
</div>

<?php elseif ($view === 'week'): ?>
<!-- WEEK VIEW -->
<?php
$wopen_h  = intval(substr($sched['open_time']  ?? '08:00', 0, 2));
$wclose_h = intval(substr($sched['close_time'] ?? '18:00', 0, 2));
if ($wclose_h <= $wopen_h) $wclose_h = $wopen_h + 10;
$w_now_h = intval(date('G')); $w_now_m = intval(date('i'));
?>
<div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;flex-wrap:wrap;">
    <a href="calendar.php?view=week&date=<?php echo $prev_week;?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-left"></i></a>
    <h6 style="margin:0;min-width:190px;text-align:center;"><?php echo $week_label;?></h6>
    <a href="calendar.php?view=week&date=<?php echo $next_week;?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-right"></i></a>
    <a href="calendar.php?view=week&date=<?php echo $today;?>" class="btn btn-sm btn-outline-primary">Today</a>
    <?php $total_week_appts = array_sum(array_map('count', $week_appts_by_day)); ?>
    <span style="font-size:0.8rem;color:var(--gray-500);"><?php echo $total_week_appts;?> appointment<?php echo $total_week_appts!==1?'s':'';?> this week</span>
</div>

<?php if(!empty($all_doctors_raw)): ?>
<div class="svc-legend">
<?php foreach($all_doctors_raw as $dr): $c=$doctor_color_map[$dr['id']]??$default_color; ?>
<span class="svc-pill" style="background:<?php echo $c['bg'];?>;border-color:<?php echo $c['border'];?>;color:<?php echo $c['text'];?>"><?php echo htmlspecialchars($dr['full_name']);?></span>
<?php endforeach;?>
</div>
<?php endif;?>

<!-- Week grid -->
<!-- Mobile tip: week view is desktop-only, suggest day view -->
<div class="week-mobile-tip" style="display:none;background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:12px;padding:14px 16px;margin-bottom:14px;text-align:center;">
    <i class="bi bi-calendar-week" style="font-size:1.4rem;color:#2563eb;display:block;margin-bottom:6px;"></i>
    <div style="font-size:0.85rem;font-weight:700;color:#1e40af;margin-bottom:4px;">Week view works best on desktop</div>
    <div style="font-size:0.78rem;color:#3b82f6;margin-bottom:12px;">Switch to Day view for a better phone experience</div>
    <a href="calendar.php?view=day&date=<?php echo $today;?>" style="display:inline-flex;align-items:center;gap:6px;padding:8px 20px;border-radius:10px;background:#2563eb;color:#fff;font-size:0.82rem;font-weight:700;text-decoration:none;">
        <i class="bi bi-calendar-day"></i> Switch to Day View
    </a>
</div>
<div class="week-grid-wrap">
<div style="display:grid;grid-template-columns:52px repeat(7,1fr);border:1px solid var(--gray-200);border-radius:12px;overflow:hidden;background:var(--white);">

    <!-- Header row: day labels -->
    <div style="background:var(--gray-50);border-right:1px solid var(--gray-100);border-bottom:1px solid var(--gray-200);"></div>
    <?php foreach($week_days as $i => $wd):
        $is_today_col = ($wd === $today);
        $day_appt_count = count($week_appts_by_day[$wd]);
        $col_border = $i < 5 ? 'border-right:1px solid var(--gray-100);' : '';
    ?>
    <div style="<?php echo $col_border;?>border-bottom:1px solid var(--gray-200);padding:8px 6px;background:<?php echo $is_today_col?'var(--blue-50)':'var(--gray-50)';?>;text-align:center;">
        <div style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:<?php echo $is_today_col?'var(--blue-600)':'var(--gray-500)';?>;">
            <?php echo date('D', strtotime($wd));?>
        </div>
        <div style="font-size:<?php echo $is_today_col?'1rem':'0.9rem';?>;font-weight:700;color:<?php echo $is_today_col?'var(--white)':'var(--gray-700)';?>;
            <?php if($is_today_col): ?>background:#2563eb;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:3px auto 0;<?php else: ?>margin-top:3px;<?php endif;?>">
            <?php echo date('j', strtotime($wd));?>
        </div>
        <?php if($day_appt_count > 0): ?>
        <div style="font-size:0.62rem;color:var(--blue-500);margin-top:2px;font-weight:600;"><?php echo $day_appt_count;?> appt<?php echo $day_appt_count!==1?'s':'';?></div>
        <?php endif;?>
    </div>
    <?php endforeach;?>

    <!-- Time gutter + columns -->
    <div style="display:contents;">
    <?php for($h = $wopen_h; $h <= $wclose_h; $h++): ?>
        <!-- Time label -->
        <div style="background:var(--gray-50);border-right:1px solid var(--gray-100);border-bottom:1px dashed var(--gray-100);height:60px;display:flex;align-items:flex-start;justify-content:flex-end;padding:4px 6px 0 0;font-size:0.62rem;color:var(--gray-400);font-weight:600;">
            <?php echo date('g A', strtotime("$h:00"));?>
        </div>
        <!-- Day columns for this hour -->
        <?php foreach($week_days as $ci => $wd):
            $col_border = $ci < 5 ? 'border-right:1px solid var(--gray-100);' : '';
            $is_today_col = ($wd === $today);
        ?>
        <div style="<?php echo $col_border;?>border-bottom:1px dashed var(--gray-100);height:60px;position:relative;background:<?php echo $is_today_col?'rgba(219,234,254,0.15)':'transparent';?>;">
            <?php
            // Now line (only on today's column, at the right hour)
            if ($is_today_col && $h === $w_now_h && in_array($wd, $week_days) && $wd === $today):
                $now_top_in_cell = $w_now_m;
            ?>
            <div style="position:absolute;left:0;right:0;top:<?php echo $now_top_in_cell;?>px;height:2px;background:#ef4444;z-index:10;pointer-events:none;">
                <div style="position:absolute;left:-4px;top:-4px;width:10px;height:10px;border-radius:50%;background:#ef4444;"></div>
            </div>
            <?php endif;?>

            <?php
            // Render appointments that start in this hour for this day
            foreach($week_appts_by_day[$wd] as $a):
                $ah = intval(substr($a['appointment_time'],0,2));
                if ($ah !== $h) continue; // only render in the correct hour cell
                $am  = intval(substr($a['appointment_time'],3,2));
                $dur = max(25, intval($a['duration_minutes'] ?? 30));
                // Position within the 60px hour cell
                $top_in_cell = $am; // 1min = 1px
                $c   = isset($a['doctor_id'])&&$a['doctor_id'] ? ($doctor_color_map[$a['doctor_id']]??$default_color) : $default_color;
                $dot = $status_dot[$a['status']] ?? '#95a5a6';
                $name_parts = explode(' ', ucwords(strtolower($a['patient_name']??'')));
                $short_name = $name_parts[0] . (isset($name_parts[1]) ? ' '.substr($name_parts[1],0,1).'.' : '');
                $ts  = date('h:i A', strtotime($a['appointment_time']));
            ?>
            <div class="day-appt-block"
                 data-appt-id="<?php echo $a['id']; ?>" style="top:<?php echo $top_in_cell;?>px;height:<?php echo min($dur, 60 - $top_in_cell);?>px;left:2px;right:2px;background:<?php echo $c['bg'];?>;border-color:<?php echo $c['border'];?>;color:<?php echo $c['text'];?>;padding:3px 5px;font-size:0.68rem;"
                 onclick="openApptModal(<?php echo htmlspecialchars(json_encode($a),ENT_QUOTES);?>)"
                 title="<?php echo htmlspecialchars($ts.' — '.ucwords(strtolower($a['patient_name']??'')).' | '.($a['service_name']??''));?>">
                <div style="font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($short_name);?></div>
                <?php if($dur >= 35): ?><div style="font-size:0.6rem;opacity:0.75;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo $ts;?></div><?php endif;?>
                <?php if(!empty(trim($a['allergies']??''))): ?><div style="font-size:0.58rem;background:#fee2e2;color:#dc2626;border-radius:2px;padding:0 3px;display:inline-block;margin-top:1px;">⚠ ALLERGY</div><?php endif;?>
                <span style="position:absolute;top:3px;right:4px;width:6px;height:6px;border-radius:50%;background:<?php echo $dot;?>;display:inline-block;"></span>
            </div>
            <?php endforeach;?>
        </div>
        <?php endforeach;?>
    <?php endfor;?>
    </div>
</div>
</div><!-- /.week-grid-wrap -->

<?php else: ?>
<!-- DAY VIEW -->
<div class="day-nav-bar" style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
    <a href="calendar.php?view=day&date=<?php echo $prev_day;?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-left"></i></a>
    <h6 style="margin:0;min-width:200px;text-align:center;"><?php echo $day_label;?></h6>
    <a href="calendar.php?view=day&date=<?php echo $next_day;?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-right"></i></a>
    <a href="calendar.php?view=day&date=<?php echo $today;?>" class="btn btn-sm btn-outline-primary">Today</a>
    <span style="font-size:0.8rem;color:var(--gray-500);margin-left:4px;"><?php echo count($day_appts);?> appointment<?php echo count($day_appts)!==1?'s':'';?></span>
</div>
<?php if(!empty($all_doctors_raw)): ?>
<div class="svc-legend">
<?php foreach($all_doctors_raw as $dr): $c=$doctor_color_map[$dr['id']]??$default_color; ?>
<span class="svc-pill" style="background:<?php echo $c['bg'];?>;border-color:<?php echo $c['border'];?>;color:<?php echo $c['text'];?>"><?php echo htmlspecialchars($dr['full_name']);?></span>
<?php endforeach;?>
</div>
<?php endif;?>
<?php
$open_h =intval(substr($sched['open_time']??'08:00',0,2));
$close_h=intval(substr($sched['close_time']??'18:00',0,2));
if($close_h<=$open_h) $close_h=$open_h+10;
$total_hours=$close_h-$open_h;
$now_h=intval(date('G')); $now_m=intval(date('i'));
$now_top=(($now_h-$open_h)*60+$now_m);
$show_now=($day_date===$today&&$now_h>=$open_h&&$now_h<$close_h);
?>
<div class="day-view-wrap">
    <div class="day-time-gutter">
        <?php for($h=$open_h;$h<=$close_h;$h++): ?><div class="day-time-slot"><?php echo date('g A',strtotime("$h:00"));?></div><?php endfor;?>
    </div>
    <div style="position:relative;height:<?php echo ($total_hours+1)*60;?>px;overflow:hidden;">
        <?php for($h=0;$h<=$total_hours;$h++): ?>
        <div style="position:absolute;left:0;right:0;top:<?php echo $h*60;?>px;border-bottom:1px solid var(--gray-100);"></div>
        <?php if($h<$total_hours): ?><div style="position:absolute;left:0;right:0;top:<?php echo $h*60+30;?>px;border-bottom:1px dashed var(--gray-100);pointer-events:none;"></div><?php endif;?>
        <?php endfor;?>
        <?php if($show_now): ?><div class="day-now-line" style="top:<?php echo $now_top;?>px;"></div><?php endif;?>
        <?php if(empty($day_appts)): ?>
        <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;color:var(--gray-400);">
            <i class="bi bi-calendar-x" style="font-size:2.5rem;display:block;margin-bottom:10px;"></i>
            <div style="font-size:0.88rem;">No appointments for this day.</div>
            <button onclick="openWalkinDrawer('<?php echo $day_date;?>')" class="btn btn-sm btn-success mt-3"><i class="bi bi-plus"></i> Add Appointment</button>
        </div>
        <?php endif;?>
        <?php foreach($day_appts as $a):
            $ah=intval(substr($a['appointment_time'],0,2));
            $am=intval(substr($a['appointment_time'],3,2));
            $top_px=(($ah-$open_h)*60+$am);
            $dur=max(28,intval($a['duration_minutes']??30));
            $c=isset($a['doctor_id'])&&$a['doctor_id']?($doctor_color_map[$a['doctor_id']]??$default_color):$default_color;
            $dot=$status_dot[$a['status']]??'#95a5a6';
            $name=ucwords(strtolower($a['patient_name']??''));
            $ts=date('h:i A',strtotime($a['appointment_time']));
            $te=date('h:i A',strtotime('+'.$dur.' minutes',strtotime($a['appointment_time'])));
            $has_allergy=!empty(trim($a['allergies']??''));
        ?>
        <div class="day-appt-block"
             data-appt-id="<?php echo $a['id']; ?>" style="top:<?php echo $top_px;?>px;height:<?php echo $dur;?>px;background:<?php echo $c['bg'];?>;border-color:<?php echo $c['border'];?>;color:<?php echo $c['text'];?>;"
             onclick="openApptModal(<?php echo htmlspecialchars(json_encode($a),ENT_QUOTES);?>)">
            <div class="day-appt-title"><?php echo htmlspecialchars($name);?></div>
            <?php if($dur>=44): ?>
            <div class="day-appt-sub"><?php echo htmlspecialchars($a['service_name']??'No service');?></div>
            <div class="day-appt-time"><?php echo $ts;?> – <?php echo $te;?></div>
            <?php if($has_allergy): ?><div class="allergy-badge"><i class="bi bi-exclamation-triangle-fill"></i> ALLERGY</div><?php endif;?>
            <?php endif;?>
            <span style="position:absolute;top:5px;right:6px;width:8px;height:8px;border-radius:50%;background:<?php echo $dot;?>;display:inline-block;" title="<?php echo ucfirst($a['status']);?>"></span>
        </div>
        <?php endforeach;?>
    </div>
</div>
<?php endif;?>
</div>

<!-- Day detail modal (month view) -->
<div class="modal fade" id="dayModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius:14px;border:none;box-shadow:0 20px 60px rgba(0,0,0,0.15);">
            <div class="modal-header" style="border-bottom:1px solid var(--gray-200);padding:18px 22px;">
                <div><h6 class="modal-title mb-0" id="dayModalTitle">Appointments</h6><div id="dayModalSub" style="font-size:0.78rem;color:var(--gray-400);margin-top:2px;"></div></div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="dayModalBody" style="padding:18px 22px;"></div>
            <div class="modal-footer" style="border-top:1px solid var(--gray-200);padding:12px 22px;">
                <button id="dayModalAddBtn" type="button" class="btn btn-sm btn-success" onclick="getModal('dayModal').hide();setTimeout(()=>openWalkinDrawer(_dayModalDate),200)"><i class="bi bi-plus-circle"></i> Add Appointment</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Appointment detail modal -->
<div class="modal fade" id="apptModal" tabindex="-1">
    <div class="modal-dialog modal-md">
        <div class="modal-content" style="border-radius:14px;border:none;box-shadow:0 20px 60px rgba(0,0,0,0.15);">
            <div id="apptModalHeaderWrap" style="padding:20px 22px 14px;border-bottom:var(--border);">
                <div style="display:flex;align-items:flex-start;justify-content:space-between;">
                    <div style="flex:1;">
                        <div id="apptModalName" style="font-size:1.05rem;font-weight:700;color:var(--gray-900);"></div>
                        <div id="apptModalCode" style="font-size:0.8rem;color:var(--gray-500);margin-top:2px;"></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" style="margin-left:10px;"></button>
                </div>
                <div id="apptAllergyAlert" style="display:none;" class="allergy-alert">
                    <i class="bi bi-exclamation-triangle-fill" style="font-size:1rem;flex-shrink:0;"></i>
                    <div><div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:2px;">Known Allergies</div><div id="apptAllergyText" style="font-weight:700;"></div></div>
                </div>
            </div>
            <div class="modal-body" style="padding:16px 22px;">
                <div id="apptModalInfoRows"></div>
                <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--gray-100);">
                    <div style="font-size:0.78rem;font-weight:600;color:var(--gray-600);margin-bottom:8px;">Update Status</div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;" id="statusBtns"></div>
                </div>
            </div>
            <div class="modal-footer" style="border-top:var(--border);padding:12px 18px;gap:7px;flex-wrap:wrap;">
                <a id="apptCheckInBtn" href="#" class="btn btn-sm btn-success" style="display:none;"><i class="bi bi-person-check"></i> Check-in</a>
                <a id="apptRecordBtn"  href="#" class="btn btn-sm btn-outline-success" style="display:none;"><i class="bi bi-clipboard2-check"></i> Records</a>
                <button id="apptFollowUpBtn" class="btn btn-sm btn-outline-primary" onclick="scheduleFollowUp()"><i class="bi bi-calendar-plus"></i> Follow-up</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- New Appointment Modal -->
<div class="modal fade" id="newApptModal" tabindex="-1" aria-labelledby="newApptModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content" style="border:none;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,0.18);">
      <div class="modal-header" style="padding:18px 24px;border-bottom:var(--border);background:linear-gradient(135deg,var(--blue-500),var(--blue-400));border-radius:16px 16px 0 0;">
        <div>
          <h5 class="modal-title" id="newApptModalLabel" style="color:#fff;font-weight:800;font-size:1rem;margin:0;"><i class="bi bi-calendar2-plus"></i> New Appointment</h5>
          <p id="drawerSubtitle" style="color:rgba(255,255,255,0.82);font-size:0.78rem;margin:2px 0 0;">Schedule a future appointment for a patient</p>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div id="drawerSlotBar" style="background:var(--primary-bg);border-bottom:var(--border);padding:8px 24px;font-size:0.82rem;display:flex;align-items:center;gap:8px;"><span style="color:var(--gray-400);">Loading slot info...</span></div>
      <div id="drawerAlert" style="display:none;padding:0 24px;margin-top:14px;"></div>
      <div class="modal-body" style="padding:22px 24px;">
        <form id="walkinDrawerForm" autocomplete="off">
            <input type="hidden" name="_ajax" value="1">
            <input type="hidden" name="mode" value="appointment">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="existing_patient_id" id="drawerExistingPatientId" value="">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label" style="font-weight:600;">Appointment Date <span style="color:var(--danger)">*</span></label>
                    <input type="date" name="appointment_date" id="drawerDate" class="form-control" min="<?php echo date('Y-m-d', strtotime('+1 day'));?>" value="<?php echo date('Y-m-d', strtotime('+1 day'));?>">
                    <div style="font-size:0.72rem;color:var(--gray-500);margin-top:4px;">Advance booking — choose a future date.</div>
                </div>
                <div class="col-12">
                    <label class="form-label" style="font-weight:600;">Patient Search</label>
                    <div style="position:relative;">
                        <input type="text" id="drawerPatientSearch" class="form-control"
                            placeholder="Search by name or phone to find returning patient…"
                            autocomplete="off" oninput="searchPatient(this.value)">
                        <div id="drawerPatientResults" style="display:none;position:absolute;left:0;right:0;top:100%;z-index:500;background:var(--white);border:var(--border);border-top:none;border-radius:0 0 8px 8px;box-shadow:0 6px 20px rgba(0,0,0,0.1);max-height:200px;overflow-y:auto;"></div>
                    </div>
                    <div id="drawerPatientSelected" style="display:none;margin-top:7px;background:var(--blue-50);border:1px solid var(--blue-200);border-radius:8px;padding:8px 12px;font-size:0.82rem;align-items:center;gap:8px;">
                        <i class="bi bi-person-check-fill" style="color:var(--blue-500);"></i>
                        <span id="drawerPatientSelectedName" style="font-weight:600;flex:1;"></span>
                        <button type="button" onclick="clearPatientSelection()" style="background:none;border:none;color:var(--gray-400);cursor:pointer;font-size:0.75rem;">✕ Clear</button>
                    </div>
                    <div style="margin-top:6px;display:flex;align-items:center;gap:8px;">
                        <span style="font-size:0.72rem;color:var(--gray-400);">New patient?</span>
                        <button type="button" id="drawerNewPatientBtn" onclick="toggleNewPatientFields()" class="btn btn-sm btn-outline-secondary" style="font-size:0.72rem;padding:2px 10px;">
                            <i class="bi bi-person-plus"></i> Enter Name Manually
                        </button>
                    </div>
                </div>
                <div class="col-12" id="drawerNewPatientHeading" style="display:none;margin-top:4px;">
                    <div style="font-size:0.78rem;font-weight:600;color:var(--gray-700);">New Patient — Quick Entry</div>
                    <div style="font-size:0.72rem;color:var(--gray-400);">Creates a basic patient record now. Add full details (date of birth, address, etc.) later in Patient Records.</div>
                </div>
                <div class="col-6" id="drawerFirstNameWrap" style="display:none;">
                    <label class="form-label">First Name <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="first_name" id="drawerFirstName" class="form-control" placeholder="e.g. Juan">
                </div>
                <div class="col-6" id="drawerLastNameWrap" style="display:none;">
                    <label class="form-label">Last Name <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="last_name" id="drawerLastName" class="form-control" placeholder="e.g. dela Cruz">
                </div>
                <div class="col-12" id="drawerPhoneWrap" style="display:none;">
                    <label class="form-label">Phone <span style="font-size:0.72rem;color:var(--gray-400);font-weight:400;">(optional)</span></label>
                    <input type="text" name="phone" class="form-control" placeholder="09XXXXXXXXX" maxlength="13">
                </div>
                <div class="col-12">
                    <label class="form-label">Service</label>
                    <select name="service_id" class="form-select">
                        <option value="">— No service selected —</option>
                        <?php foreach($walkin_services as $sv): ?><option value="<?php echo $sv['id'];?>"><?php echo e($sv['service_name']);?> — ₱<?php echo number_format($sv['price'],2);?></option><?php endforeach;?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Doctor</label>
                    <select name="doctor_id" id="drawerDoctorSelect" class="form-select">
                        <option value="">Any Available Doctor</option>
                        <?php $ta=strtolower(substr(date('l'),0,3)); foreach($all_docs_dw as $d): $dd=array_map('trim',explode(',',$d['schedule_days']??'')); if(!in_array($ta,$dd)) continue; ?><option value="<?php echo $d['id'];?>"><?php echo e($d['full_name']);?><?php if($d['specialization']): ?> — <?php echo e($d['specialization']);?><?php endif;?></option><?php endforeach;?>
                    </select>
                    <div id="drawerDoctorNote" style="font-size:0.72rem;color:var(--gray-400);margin-top:3px;">Showing doctors available today.</div>
                </div>
                <div class="col-12" id="drawerSlotPickerWrap">
                    <label class="form-label">Appointment Time <span style="color:var(--danger)">*</span></label>
                    <select name="selected_time" id="drawerSlotSelect" class="form-select" required>
                        <option value="">— Loading slots… —</option>
                    </select>
                    <div id="drawerSlotNote" style="font-size:0.72rem;color:var(--gray-400);margin-top:3px;"></div>
                </div>
                <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2" maxlength="500"></textarea></div>
            </div>
        </form>
      </div>
      <div class="modal-footer" style="padding:14px 24px;border-top:var(--border);gap:8px;">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success" id="walkinSubmitBtn" onclick="submitWalkin()" style="min-width:160px;font-weight:700;"><i class="bi bi-person-check-fill"></i> <span id="walkinBtnLabel">Book Appointment</span></button>
      </div>
    </div>
  </div>
</div>
<!-- No Dental Record Warning Modal -->
<div class="modal fade" id="noRecordModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header" style="border-bottom:1px solid #fde68a;background:#fffbeb;">
                <h6 class="modal-title" style="color:#92400e;">
                    <i class="bi bi-exclamation-triangle-fill" style="color:#f59e0b;margin-right:6px;"></i>
                    No Treatment Record Yet
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="font-size:0.875rem;color:#374151;">
                <p style="margin:0 0 10px;">This appointment has <strong>no dental record</strong> attached to it yet.</p>
                <p style="margin:0;color:#6b7280;font-size:0.8rem;">Record the treatment first, or mark it as completed anyway?</p>
                <div style="margin-top:12px;padding:10px 12px;background:#fef3c7;border-radius:8px;border:1px solid #fde68a;font-size:0.78rem;color:#92400e;">
                    <i class="bi bi-info-circle-fill" style="margin-right:5px;"></i>
                    Skipping may leave the patient's clinical history incomplete.
                </div>
            </div>
            <div class="modal-footer" style="flex-wrap:wrap;gap:6px;">
                <button class="btn btn-sm btn-primary" style="flex:1;" onclick="goToRecordTreatment()"><i class="bi bi-clipboard2-pulse"></i> Record Treatment</button>
                <button class="btn btn-sm btn-outline-secondary" style="flex:1;" onclick="completeAnyway()"><i class="bi bi-check2-all"></i> Complete Anyway</button>
                <button class="btn btn-sm btn-link text-secondary w-100" style="font-size:0.78rem;" data-bs-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

<div id="walkinToast" style="display:none;position:fixed;bottom:28px;right:28px;z-index:2000;background:var(--white);border:1.5px solid #bbf7d0;border-radius:12px;padding:14px 20px;box-shadow:0 8px 24px rgba(0,0,0,0.12);max-width:360px;">
    <div style="display:flex;align-items:flex-start;gap:12px;"><span style="font-size:1.4rem;">✅</span><div><div id="walkinToastTitle" style="font-weight:700;color:#166534;margin-bottom:3px;">Done!</div><div id="walkinToastMsg" style="font-size:0.82rem;color:#374151;"></div></div><button onclick="document.getElementById('walkinToast').style.display='none'" style="background:none;border:none;color:#9ca3af;cursor:pointer;margin-left:auto;font-size:1rem;">✕</button></div>
</div>

<script>
function getModal(id){var el=document.getElementById(id);return el?bootstrap.Modal.getOrCreateInstance(el):null;}
function getCsrfToken(){var el=document.querySelector('input[name="_csrf"]');return el?el.value:'';}
var _today='<?php echo $today;?>';
var _tomorrow='<?php echo date('Y-m-d', strtotime('+1 day'));?>';
var _phpBaseUrl='<?php echo BASE_URL;?>';
// Use protocol-relative root to avoid http/https mismatch on Railway (proxy strips TLS).
// Derive app root from the PHP-generated URL but force current protocol/host.
var _baseUrl=(function(){
    try {
        var parsed=new URL(_phpBaseUrl);
        // If same host, trust the path; force current protocol to avoid mixed-content blocks
        if(parsed.hostname===window.location.hostname){
            return window.location.protocol+'//'+window.location.host+parsed.pathname;
        }
    } catch(e){}
    // Fallback: derive root from current script path by stripping /modules/... segment
    var p=window.location.pathname.replace(/\/modules\/.*$/,'/');
    return window.location.protocol+'//'+window.location.host+p;
})();
var doctorColors=<?php echo json_encode($doctor_color_map);?>;
var defaultC=<?php echo json_encode($default_color);?>;
var statusDot=<?php echo json_encode($status_dot);?>;
var statusLabels=<?php echo json_encode($status_labels);?>;
<?php if($view==='month'): ?>var allAppts=<?php echo json_encode($appts_by_date);?>;<?php else: ?>var allAppts={};<?php endif;?>
var _curAppt=null;
function ucwords(s){return s.toLowerCase().replace(/(^|\s)\S/g,l=>l.toUpperCase());}
function goToDay(d){ openWalkinDrawer(d); }
// Central handler: cells with appts open the day summary first,
// empty cells open the drawer directly. Either way the date is always correct.
var _lastCalClick = 0;
function handleCalCellClick(e, cell){
    var date = cell.getAttribute('data-cal-date');
    if(!date) return;
    // Don't open a new modal while one is already open or animating closed
    if(document.body.classList.contains('modal-open')) return;
    // Debounce: ignore clicks within 400ms of a prior one (prevents double-fire after modal hides)
    var now = Date.now();
    if(now - _lastCalClick < 400) return;
    _lastCalClick = now;
    // If there are appts on this date, show the day summary modal
    if(allAppts && allAppts[date] && allAppts[date].length > 0){
        viewDay(date);
    } else {
        openWalkinDrawer(date);
    }
}
var _dayModalDate=_today;
function viewDay(date){
    _dayModalDate=date;
    var appts=allAppts[date]||[];
    var d=new Date(date+'T12:00:00');
    var label=d.toLocaleDateString('en-PH',{weekday:'long',year:'numeric',month:'long',day:'numeric'});
    document.getElementById('dayModalTitle').textContent=label;
    document.getElementById('dayModalSub').textContent=appts.length+' appointment'+(appts.length!==1?'s':'');
    var body='';
    if(!appts.length){body='<p style="color:var(--gray-400);text-align:center;padding:24px 0;"><i class="bi bi-calendar-x" style="font-size:2rem;display:block;margin-bottom:8px;"></i>No appointments on this day.</p>';}
    else{
        appts.sort((a,b)=>a.appointment_time.localeCompare(b.appointment_time));
        appts.forEach(a=>{
            var c=(a.doctor_id&&doctorColors[a.doctor_id])?doctorColors[a.doctor_id]:defaultC;
            var dot=statusDot[a.status]||'#95a5a6';
            var t=new Date('1970-01-01T'+a.appointment_time);
            var time=t.toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit'});
            var name=ucwords(a.patient_name||'');
            body+='<div class="day-modal-appt" style="background:'+c.bg+';border-color:'+c.border+';">';
            body+='<div style="font-size:0.8rem;font-weight:700;min-width:60px;color:'+c.text+'">'+time+'</div>';
            body+='<div style="flex:1;"><div style="font-weight:600;font-size:0.875rem;">'+name+'</div>';
            body+='<div style="font-size:0.75rem;color:var(--gray-500);">'+(a.service_name||'No service specified')+'</div>';
            if(a.doctor_name)body+='<div style="font-size:0.72rem;color:'+c.text+';font-weight:600;margin-top:2px;"><i class="bi bi-person-badge"></i> '+a.doctor_name+'</div>';
            body+='</div>';
            body+='<span style="display:flex;align-items:center;gap:4px;font-size:0.72rem;font-weight:600;color:'+dot+';">';
            body+='<span style="width:7px;height:7px;border-radius:50%;background:'+dot+';display:inline-block;"></span>'+(statusLabels[a.status]||a.status)+'</span>';
            body+='<button onclick="getModal(\'dayModal\').hide();setTimeout(()=>openApptModal('+JSON.stringify(a)+'),300)" class="btn btn-sm btn-outline-primary" style="flex-shrink:0;"><i class="bi bi-info-circle"></i></button>';
            body+='</div>';
        });
    }
    document.getElementById('dayModalBody').innerHTML=body;
    getModal('dayModal').show();
}
function openApptModal(a){
    _curAppt=a;
    var c=(a.doctor_id&&doctorColors[a.doctor_id])?doctorColors[a.doctor_id]:defaultC;
    var name=ucwords(a.patient_name||'');
    document.getElementById('apptModalName').textContent=name;
    document.getElementById('apptModalCode').textContent=(a.appointment_code||'')+(a.doctor_name?' · '+a.doctor_name:'');
    document.getElementById('apptModalHeaderWrap').style.borderLeft='5px solid '+c.border;
    var al=document.getElementById('apptAllergyAlert');
    if(a.allergies&&a.allergies.trim()){al.style.display='flex';document.getElementById('apptAllergyText').textContent=a.allergies;}
    else{al.style.display='none';}
    var t=new Date('1970-01-01T'+a.appointment_time);
    var time=t.toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit'});
    var dur=a.duration_minutes?a.duration_minutes+' min':'—';
    var dot=statusDot[a.status]||'#95a5a6';
    var balHtml='';
    if(a.bill_id){var bal=parseFloat(a.amount_due||0)-parseFloat(a.amount_paid||0);
        if(a.bill_status==='paid'){balHtml='<span class="balance-pill" style="background:#dcfce7;color:#16a34a;">✓ Paid ₱'+parseFloat(a.amount_paid||0).toFixed(2)+'</span>';}
        else if(bal>0){balHtml='<span class="balance-pill" style="background:#fef9c3;color:#92400e;">Balance: ₱'+bal.toFixed(2)+'</span>';}
    }else{balHtml='<span style="color:var(--gray-400);font-size:0.78rem;">No bill yet</span>';}
    var rows=[
        ['<i class="bi bi-clock"></i> Time',time+' <span style="font-size:0.75rem;color:var(--gray-400);">('+dur+')</span>'],
        ['<i class="bi bi-heart-pulse"></i> Service',a.service_name||'<em style="color:var(--gray-400);">Not specified</em>'],
        ['<i class="bi bi-person-badge"></i> Doctor',a.doctor_name||'<em style="color:var(--gray-400);">Unassigned</em>'],
        ['<i class="bi bi-circle-fill" style="color:'+dot+';font-size:0.55rem;"></i> Status','<span style="font-weight:700;color:'+dot+'">'+(statusLabels[a.status]||a.status)+'</span>'],
        ['<i class="bi bi-cash-coin"></i> Billing',balHtml],
    ];
    if(a.notes)rows.push(['<i class="bi bi-sticky"></i> Notes','<em>'+a.notes+'</em>']);
    document.getElementById('apptModalInfoRows').innerHTML=rows.map(r=>'<div class="modal-info-row"><span style="color:var(--gray-500);min-width:100px;font-size:0.78rem;">'+r[0]+'</span><span style="color:var(--gray-800);font-weight:500;">'+r[1]+'</span></div>').join('');
    var colors={pending:'#f39c12',confirmed:'#2980b9',completed:'#27ae60',cancelled:'#e74c3c','no-show':'#95a5a6'};
    document.getElementById('statusBtns').innerHTML=Object.keys(colors).map(s=>{var ia=(s===a.status);return '<button class="status-btn'+(ia?' active':'')+'" style="border-color:'+colors[s]+';color:'+(ia?'var(--white)':colors[s])+';background:'+(ia?colors[s]:'transparent')+';" onclick="updateApptStatus('+a.id+',\''+s+'\')">'+(statusLabels[s]||s)+'</button>';}).join('');
    var ci=document.getElementById('apptCheckInBtn'),rb=document.getElementById('apptRecordBtn');
    if(a.status==='confirmed'){ci.href=_baseUrl+'modules/treatments/add.php?patient_id='+a.patient_id+'&appointment_id='+a.id;ci.style.display='inline-flex';rb.style.display='none';}
    else if(a.status==='completed'){rb.href=_baseUrl+'modules/patients/view.php?id='+a.patient_id;rb.style.display='inline-flex';ci.style.display='none';}
    else{ci.style.display='none';rb.style.display='none';}
    getModal('apptModal').show();
}
// Status-dot color map (mirrors PHP $status_dot)
var _calStatusDot={pending:'#f39c12',confirmed:'#2980b9',completed:'#27ae60',cancelled:'#e74c3c','no-show':'#95a5a6'};

function _updateChipDots(id, color) {
    document.querySelectorAll('[data-appt-id="'+id+'"]').forEach(function(chip) {
        chip.querySelectorAll('span[style*="border-radius:50%"]').forEach(function(dot) {
            dot.style.background = color;
        });
    });
}

function updateApptStatus(id, status) {
    var oldStatus = _curAppt ? _curAppt.status : null;
    var oldDotColor = _calStatusDot[oldStatus] || '#95a5a6';
    var newDotColor = _calStatusDot[status]    || '#95a5a6';

    // ── Optimistic: flip chip dots + active status-btn immediately ────────────
    _updateChipDots(id, newDotColor);
    document.querySelectorAll('.status-btn').forEach(function(btn) {
        var btnStatus = (btn.getAttribute('onclick')||'').replace(/.*'([^']+)'\s*\)\s*$/, '$1');
        var isActive = btnStatus === status;
        var col = _calStatusDot[btnStatus] || '#95a5a6';
        btn.style.background = isActive ? col : 'transparent';
        btn.style.color      = isActive ? 'var(--white)' : col;
        if (isActive) btn.classList.add('active'); else btn.classList.remove('active');
    });
    if (_curAppt) _curAppt.status = status;

    // Close modal right away
    getModal('apptModal').hide();

    fetch(_baseUrl+'api/appointments.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action:'update_status', id:id, status:status, _csrf:getCsrfToken()})
    })
    .then(r => r.json())
    .then(d => {
        if (d.status === 'success') {
            location.reload(); // reload to refresh action buttons & data
        } else if (d.status === 'no_record_warning') {
            // Rollback dot
            _updateChipDots(id, oldDotColor);
            if (_curAppt) _curAppt.status = oldStatus;
            _pendingCompleteId = d.appt_id;
            getModal('noRecordModal').show();
        } else {
            // ── Rollback ──────────────────────────────────────────────────────
            _updateChipDots(id, oldDotColor);
            if (_curAppt) _curAppt.status = oldStatus;
            getModal('apptModal').show();
            alert('Error: ' + (d.message || 'Update failed'));
        }
    })
    .catch(function() {
        _updateChipDots(id, oldDotColor);
        if (_curAppt) _curAppt.status = oldStatus;
        getModal('apptModal').show();
        alert('Network error. Status was not updated.');
    });
}
var _pendingCompleteId=null;
function goToRecordTreatment(){getModal('noRecordModal')?.hide();window.location.href=_baseUrl+'modules/treatments/add.php?appointment_id='+_pendingCompleteId;}
function completeAnyway(){
    getModal('noRecordModal')?.hide();
    fetch(_baseUrl+'api/appointments.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'update_status',id:_pendingCompleteId,status:'completed',force:true,_csrf:getCsrfToken()})})
    .then(r=>r.json()).then(d=>{if(d.status==='success')location.reload();else alert('Error: '+(d.message||'Update failed'));});
}
function scheduleFollowUp(){
    if(!_curAppt)return;
    var appt=_curAppt;
    getModal('apptModal').hide();
    setTimeout(()=>{
        function toISO(d){return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');}
        // The follow-up date is 7 days after THIS appointment's own date —
        // not 7 days from today — so it lands where you'd expect relative
        // to the visit you're following up, regardless of which day you
        // happen to be viewing when you click the button. Still clamped to
        // the drawer's own minimum (tomorrow), since same-day/past booking
        // isn't allowed here.
        var base=appt.appointment_date?new Date(appt.appointment_date+'T12:00:00'):new Date();
        base.setDate(base.getDate()+7);
        var minDate=new Date();minDate.setDate(minDate.getDate()+1);
        var ds=toISO(base), minDs=toISO(minDate);
        if(ds<minDs)ds=minDs;
        openWalkinDrawer();
        setTimeout(()=>{
            document.getElementById('drawerDate').value=ds;loadDrawerDateData(ds);
            // Re-select the SAME patient record instead of retyping their
            // name into the "new patient" fields — keeps the follow-up tied
            // to the original patient instead of silently creating a duplicate.
            if(appt.patient_id){selectPatient(appt.patient_id,ucwords(appt.patient_name||''),appt.patient_code||'',appt.phone||'');}
        },100);
    },350);
}
// Drawer — fully synced with list.php
var _patientSearchTimer = null;
var _newPatientFieldsVisible = false;

function openWalkinDrawer(presetDate){
    // Close notification panel if open
    var notifPanel = document.getElementById('notifPanel');
    if(notifPanel) notifPanel.classList.remove('open');

    // Appointment drawer is for future bookings only — clamp to tomorrow
    var dateToUse = (presetDate && presetDate > _today) ? presetDate : _tomorrow;

    // Dispose any stale modal instance and nuke orphaned backdrops
    var modalEl = document.getElementById('newApptModal');
    var existing = bootstrap.Modal.getInstance(modalEl);
    if(existing) { try { existing.hide(); existing.dispose(); } catch(e) {} }
    document.querySelectorAll('.modal-backdrop').forEach(function(el) { el.remove(); });
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');

    // Small delay so Bootstrap's hide transition fully finishes before we
    // create a fresh instance — prevents double-backdrop stacking.
    setTimeout(function() {
        var modal = new bootstrap.Modal(modalEl, { backdrop: true, keyboard: true });
        modal.show();
        document.getElementById('walkinDrawerForm').reset();
        document.getElementById('drawerDate').value = dateToUse;
        document.getElementById('walkinBtnLabel').textContent = 'Book Appointment';
        document.getElementById('drawerExistingPatientId').value = '';
        document.getElementById('drawerPatientSearch').value = '';
        document.getElementById('drawerPatientResults').style.display = 'none';
        document.getElementById('drawerPatientSelected').style.display = 'none';
        _newPatientFieldsVisible = false;
        showNewPatientFields(false);
        updateNewPatientBtn();
        hideDrawerAlert();
        loadDrawerDateData(dateToUse);
    }, 50);
}
function closeWalkinDrawer(){
    var modal = bootstrap.Modal.getInstance(document.getElementById('newApptModal'));
    if(modal) modal.hide();
}
function showNewPatientFields(show){
    var h=document.getElementById('drawerNewPatientHeading');if(h)h.style.display=show?'':'none';
    ['drawerFirstNameWrap','drawerLastNameWrap','drawerPhoneWrap'].forEach(function(id){
        var el=document.getElementById(id);if(el)el.style.display=show?'':'none';
    });
    var fn=document.getElementById('drawerFirstName');
    var ln=document.getElementById('drawerLastName');
    if(fn)fn.required=show; if(ln)ln.required=show;
}
function toggleNewPatientFields(){
    _newPatientFieldsVisible=!_newPatientFieldsVisible;
    showNewPatientFields(_newPatientFieldsVisible);
    updateNewPatientBtn();
    if(_newPatientFieldsVisible){
        document.getElementById('drawerExistingPatientId').value='';
        document.getElementById('drawerPatientSelected').style.display='none';
    }
}
function updateNewPatientBtn(){
    var btn=document.getElementById('drawerNewPatientBtn');if(!btn)return;
    if(_newPatientFieldsVisible){btn.innerHTML='<i class="bi bi-x"></i> Hide Name Fields';btn.classList.remove('btn-outline-secondary');btn.classList.add('btn-outline-danger');}
    else{btn.innerHTML='<i class="bi bi-person-plus"></i> Enter Name Manually';btn.classList.remove('btn-outline-danger');btn.classList.add('btn-outline-secondary');}
}
function searchPatient(q){
    clearTimeout(_patientSearchTimer);
    var results=document.getElementById('drawerPatientResults');
    if(q.length<2){results.style.display='none';return;}
    _patientSearchTimer=setTimeout(function(){
        fetch(_baseUrl+'modules/walkin/add.php?action=search_patient&q='+encodeURIComponent(q))
        .then(r=>r.json()).then(data=>{
            if(!data.patients||data.patients.length===0){results.innerHTML='<div style="padding:10px 14px;font-size:0.82rem;color:var(--gray-400);">No existing patients found — will register as new.</div>';}
            else{results.innerHTML=data.patients.map(p=>{var name=p.first_name+' '+p.last_name;var phone=p.phone||'No phone';var appts=p.appt_count+' appt'+(p.appt_count!=1?'s':'');return '<div style="padding:9px 14px;cursor:pointer;border-bottom:1px solid var(--gray-100);font-size:0.83rem;transition:background 0.12s;" onmouseenter="this.style.background=\'var(--gray-50)\'" onmouseleave="this.style.background=\'\'" onclick="selectPatient('+p.id+',\''+name.replace(/'/g,"\\'")+'\',\''+p.patient_code+'\',\''+phone.replace(/'/g,"\\'")+'\')"><div style="font-weight:600;">'+name+' <span style="font-size:0.72rem;color:var(--gray-400);">('+p.patient_code+')</span></div><div style="color:var(--gray-500);font-size:0.75rem;">'+phone+' · '+appts+'</div></div>';}).join('');}
            results.style.display='block';
        });
    },280);
}
function selectPatient(id,name,code,phone){
    document.getElementById('drawerExistingPatientId').value=id;
    document.getElementById('drawerPatientResults').style.display='none';
    document.getElementById('drawerPatientSearch').value='';
    document.getElementById('drawerPatientSelectedName').textContent=name+' ('+code+') · '+phone;
    document.getElementById('drawerPatientSelected').style.display='flex';
    _newPatientFieldsVisible=false;
    showNewPatientFields(false);
    updateNewPatientBtn();
}
function clearPatientSelection(){
    document.getElementById('drawerExistingPatientId').value='';
    document.getElementById('drawerPatientSelected').style.display='none';
    _newPatientFieldsVisible=true;
    showNewPatientFields(true);
    updateNewPatientBtn();
}
document.addEventListener('click',function(e){
    var res=document.getElementById('drawerPatientResults');
    var inp=document.getElementById('drawerPatientSearch');
    if(res&&inp&&!inp.contains(e.target)&&!res.contains(e.target))res.style.display='none';
});
function loadDrawerDateData(date){
    var bar=document.getElementById('drawerSlotBar');
    var docSelect=document.getElementById('drawerDoctorSelect');
    var slotSelect=document.getElementById('drawerSlotSelect');
    var slotNote=document.getElementById('drawerSlotNote');
    var docNote=document.getElementById('drawerDoctorNote');
    if(!bar||!slotSelect||!docSelect){return;}
    bar.innerHTML='<span style="color:var(--gray-400);">Checking schedule…</span>';
    slotSelect.innerHTML='<option value="">— Loading slots… —</option>';
    fetch(_baseUrl+'modules/walkin/add.php?action=get_slots&date='+encodeURIComponent(date))
    .then(r=>r.json()).then(data=>{
        if(data.status!=='success'){bar.innerHTML='<i class="bi bi-exclamation-triangle-fill" style="color:#f59e0b;"></i> '+(data.message||'Could not load schedule.');return;}
        var sd=data.slot_data,docs=data.doctors||[];
        if(sd.is_closed){bar.innerHTML='<i class="bi bi-calendar-x" style="color:#f59e0b;"></i> <strong>'+sd.reason+'</strong>';}
        else{var free=(sd.total_slots||0)-(sd.booked_count||0);bar.innerHTML='<i class="bi bi-calendar-check" style="color:#2563eb;"></i> '+data.day_name+' — <strong style="color:#2563eb;">'+free+' slot'+(free!==1?'s':'')+' free</strong>';}
        var savedDoc=docSelect.value;
        docSelect.innerHTML='<option value="">Any Available Doctor</option>';
        if(!docs.length){docNote.textContent='No doctors scheduled on this day.';}
        else{docs.forEach(function(d){var o=document.createElement('option');o.value=d.id;o.textContent=d.full_name+(d.specialization?' — '+d.specialization:'');if(String(d.id)===String(savedDoc))o.selected=true;docSelect.appendChild(o);});docNote.textContent=docs.length+' doctor'+(docs.length!==1?'s':'')+' available on this day.';}
        slotSelect.innerHTML='<option value="">— Choose a time slot —</option>';
        slotSelect.setAttribute('required','required');
        if(sd.is_closed){slotSelect.innerHTML='<option value="" disabled>Clinic closed this day</option>';slotNote.textContent='';}
        else{var fc=0;(sd.all_slots||[]).forEach(function(s){if(!s.taken&&!s.past){var o=document.createElement('option');o.value=s.time;o.textContent=s.label;slotSelect.appendChild(o);fc++;}});slotNote.textContent=fc>0?fc+' available slot'+(fc!==1?'s':'')+'.':'No available slots.';if(!fc)slotSelect.innerHTML='<option value="" disabled>No slots available</option>';}
    }).catch(function(err){if(bar)bar.innerHTML='<i class="bi bi-exclamation-triangle-fill" style="color:#f59e0b;"></i> Could not load schedule.';if(slotSelect)slotSelect.innerHTML='<option value="" disabled>Error loading slots</option>';});
}
document.addEventListener('DOMContentLoaded',function(){
    var di=document.getElementById('drawerDate');
    if(di)di.addEventListener('change',function(){if(this.value)loadDrawerDateData(this.value);});
});
function showDrawerAlert(t,m){var e=document.getElementById('drawerAlert');e.style.display='block';e.innerHTML='<div class="alert alert-'+t+'" style="margin:0;font-size:0.85rem;"><i class="bi bi-'+(t==='danger'?'x-circle-fill':'check-circle-fill')+'"></i> '+m+'</div>';}
function hideDrawerAlert(){document.getElementById('drawerAlert').style.display='none';}
function submitWalkin(){
    var form=document.getElementById('walkinDrawerForm');
    var btn=document.getElementById('walkinSubmitBtn');
    var existingId=document.getElementById('drawerExistingPatientId').value;
    var date=form.querySelector('[name=appointment_date]').value||_tomorrow;
    var sv=form.querySelector('[name=selected_time]')?form.querySelector('[name=selected_time]').value:'';
    if(!existingId){
        var first=form.querySelector('[name=first_name]')?form.querySelector('[name=first_name]').value.trim():'';
        var last=form.querySelector('[name=last_name]')?form.querySelector('[name=last_name]').value.trim():'';
        if(!first||!last){showDrawerAlert('danger','Enter a patient name, or search and select an existing patient.');return;}
    }
    if(date<=_today){showDrawerAlert('danger','Please choose a future date for the appointment.');return;}
    if(!sv){showDrawerAlert('danger','Please select a time slot for the appointment.');return;}
    btn.disabled=true;btn.innerHTML='<span class="spinner-border spinner-border-sm"></span> Booking...';
    hideDrawerAlert();
    fetch(_baseUrl+'modules/walkin/add.php',{method:'POST',body:new FormData(form)})
    .then(r=>r.json()).then(res=>{
        btn.disabled=false;btn.innerHTML='<i class="bi bi-person-check-fill"></i> <span>Book Appointment</span>';
        if(res.status==='success'){
            closeWalkinDrawer();
            var appt=res.appt||{};
            var tl=appt.time?new Date('1970-01-01T'+appt.time).toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit',hour12:true}):'';
            var isRet=res.is_returning;
            document.getElementById('walkinToastTitle').textContent=isRet?'📋 Returning Patient Booked!':'📅 Appointment Booked!';
            document.getElementById('walkinToastMsg').innerHTML='<strong>'+(appt.patient_name||'')+'</strong>'+(isRet?' <em style="font-size:0.75rem;">(existing record used)</em>':'')+'<br>'+appt.appt_code+' at '+tl;
            var toast=document.getElementById('walkinToast');toast.style.display='block';setTimeout(()=>{toast.style.display='none';},6000);
            setTimeout(()=>location.reload(),1000);
        }else{showDrawerAlert('danger',res.message||'Something went wrong.');}
    }).catch(()=>{btn.disabled=false;btn.innerHTML='<i class="bi bi-person-check-fill"></i> <span>Book Appointment</span>';showDrawerAlert('danger','Network error. Please try again.');});
}
</script>
</div>
<?php include '../../includes/footer.php'; ?>
</body>
</html>
