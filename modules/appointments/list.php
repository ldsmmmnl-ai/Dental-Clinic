<?php
// modules/appointments/list.php
// List appointments with filters. Confirm, check-in, update status, print, or delete.
// ── OOP REFACTOR: main data access now handled by AppointmentService ─────────

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/AppointmentService.php'; // ← OOP: load the service class

$page_title        = 'Appointments';
$current_user_role = $_SESSION['role'] ?? 'user';

// Pre-load services for the walk-in drawer (lookup data — kept as direct query)
$walkin_services = sc_get('svc_list') ?? sc_set('svc_list',
    $conn->query("SELECT id, service_name, price FROM services WHERE is_active = TRUE ORDER BY service_name")
         ->fetchAll(PDO::FETCH_ASSOC), 300);

// Auto-open walk-in drawer if ?walkin=1 is in the URL
$auto_open_walkin   = isset($_GET['walkin']) && $_GET['walkin'] === '1';
// Pre-select a patient when coming from Patient Profile → Book
$prefill_patient_id = isset($_GET['patient_id']) ? intval($_GET['patient_id']) : 0;
$prefill_patient    = null;
if ($prefill_patient_id > 0) {
    $pp = $conn->prepare("SELECT id, CONCAT(first_name,' ',last_name) as full_name, patient_code, phone FROM patients WHERE id = ? AND is_active = TRUE LIMIT 1");
    $pp->execute([$prefill_patient_id]);
    $prefill_patient = $pp->fetch(PDO::FETCH_ASSOC);
    $pp->closeCursor();
}

// ── Input sanitization ────────────────────────────────────────────────────────
$status_filter = $_GET['status'] ?? '';
$date_filter   = $_GET['date']   ?? '';
$doctor_filter = intval($_GET['doctor_id'] ?? 0);
$search        = trim($_GET['search'] ?? '');
$type_filter   = $_GET['type']   ?? '';

// Pre-load doctors for filter dropdown (lookup data — kept as direct query)
$all_doctors = sc_get('doc_list') ?? sc_set('doc_list',
    $conn->query("SELECT id, full_name FROM doctors WHERE is_active = TRUE ORDER BY full_name ASC")
         ->fetchAll(PDO::FETCH_ASSOC), 300);

// Whitelist status and type values
$allowed_statuses = ['pending', 'confirmed', 'completed', 'cancelled', 'no-show'];
if (!in_array($status_filter, $allowed_statuses, true)) $status_filter = '';
$allowed_types = ['walk-in', 'scheduled'];
if (!in_array($type_filter, $allowed_types, true)) $type_filter = '';
// Validate date format (YYYY-MM-DD)
if ($date_filter && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_filter)) $date_filter = '';

$per_page = 20;
$page     = max(1, intval($_GET['page'] ?? 1));

// Build filter array — AppointmentService handles the WHERE clause internally
$filters = [
    'status'    => $status_filter,
    'date'      => $date_filter,
    'doctor_id' => $doctor_filter ?: null,
    'type'      => $type_filter,
    'search'    => $search,
];

// ── Data access via AppointmentService (OOP) ─────────────────────────────────
$appointmentService = new AppointmentService($conn);  // constructor injection

$total_count = $appointmentService->count($filters);
$total_pages = max(1, ceil($total_count / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

// Service call replaces the raw prepare/execute list query
$appointments = $appointmentService->getList($filters, $per_page, $offset);

// Build filter query string for pagination links
$filter_parts = [];
if ($status_filter) $filter_parts[] = 'status='    . urlencode($status_filter);
if ($date_filter)   $filter_parts[] = 'date='      . urlencode($date_filter);
if ($doctor_filter) $filter_parts[] = 'doctor_id=' . $doctor_filter;
if ($type_filter)   $filter_parts[] = 'type='      . urlencode($type_filter);
if ($search)        $filter_parts[] = 'search='    . urlencode($search);
$filter_qs = $filter_parts ? implode('&', $filter_parts) . '&' : '';
?><!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?>
<style>
/* ── Stronger backdrop blur for New Appointment modal ─── */
.modal-backdrop.show {
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    background-color: rgba(0,0,0,0.55);
    opacity: 1 !important;
}
/* Ensure all table cells align vertically to centre */
#appointmentsTable td { vertical-align: middle !important; }
/* Uniform minimum height on all action buttons */
#appointmentsTable td a[style],
#appointmentsTable td button[style] { box-sizing: border-box; min-height: 30px; }

/* ── Status + Type pills: horizontal scroll on mobile (no wrap) ── */
.mobile-tab-bar,
.mobile-type-bar {
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
}
.mobile-tab-bar::-webkit-scrollbar,
.mobile-type-bar::-webkit-scrollbar { display: none; }

@media (max-width: 768px) {
    /* Pills become a single scrollable strip — no messy wrapping */
    .mobile-tab-bar,
    .mobile-type-bar {
        flex-wrap: nowrap !important;
        overflow-x: auto !important;
        padding-bottom: 4px;
    }
    .mobile-tab-bar a,
    .mobile-type-bar a { flex-shrink: 0; }

    /* Filter bar stacks vertically, each row full-width */
    .mobile-filter-bar {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 8px !important;
    }
    .mobile-filter-bar > * { width: 100% !important; min-width: unset !important; }

    /* Date input + Today shortcut sit side-by-side */
    .appt-date-row { display: flex !important; gap: 8px; width: 100%; }
    .appt-date-row .date-input-wrap { flex: 1; }
    .appt-date-row .today-btn       { flex-shrink: 0; }

    /* Filter + Clear buttons side-by-side */
    .appt-btn-row { display: flex !important; gap: 8px; width: 100%; }
    .appt-btn-row > * { flex: 1; justify-content: center; }
}

/* Dark mode */
[data-theme="dark"] .mobile-filter-bar input,
[data-theme="dark"] .mobile-filter-bar select {
    background: var(--gray-200) !important;
    color: var(--gray-900) !important;
    border-color: var(--gray-300) !important;
}
[data-theme="dark"] .mobile-filter-bar input::placeholder { color: var(--gray-500) !important; }
[data-theme="dark"] .mobile-tab-bar a,
[data-theme="dark"] .mobile-type-bar a {
    background: var(--gray-200) !important;
    border-color: var(--gray-300) !important;
    color: var(--gray-600) !important;
}
</style>
</head>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">

        <!-- ── Page Header ─────────────────────────────────────── -->
        <div class="page-header">
            <div>
                <h5>Appointments</h5>
                <small class="text-muted"><?php echo number_format($total_count); ?> total record<?php echo $total_count !== 1 ? 's' : ''; ?></small>
            </div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <a href="<?php echo BASE_URL; ?>modules/walkin/add.php" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-person-walking"></i> Walk-in
                </a>
                <button onclick="openWalkinDrawer()" class="btn btn-sm btn-success">
                    <i class="bi bi-plus-circle-fill"></i> New Appointment
                </button>
            </div>
        </div>

        <!-- ── Quick Status Tabs ────────────────────────────────── -->
        <div class="mobile-tab-bar" style="display:flex;gap:6px;margin-bottom:16px;overflow-x:auto;padding-bottom:2px;">
            <?php
            $tab_statuses = [
                '' => ['label'=>'All', 'icon'=>'bi-grid-3x3-gap', 'color'=>'var(--primary)', 'bg'=>'var(--primary-bg)', 'border'=>'var(--blue-200)'],
                'pending'   => ['label'=>'Pending',   'icon'=>'bi-clock',       'color'=>'var(--warning)', 'bg'=>'var(--warning-bg)', 'border'=>'var(--warning-border)'],
                'confirmed' => ['label'=>'Confirmed', 'icon'=>'bi-check-circle','color'=>'var(--primary)', 'bg'=>'var(--primary-bg)', 'border'=>'var(--blue-200)'],
                'completed' => ['label'=>'Completed', 'icon'=>'bi-check2-all',  'color'=>'var(--success)', 'bg'=>'var(--success-bg)', 'border'=>'var(--success-border)'],
                'cancelled' => ['label'=>'Cancelled', 'icon'=>'bi-x-circle',    'color'=>'var(--danger)',  'bg'=>'var(--danger-bg)',  'border'=>'var(--danger-border)'],
                'no-show'   => ['label'=>'No-show',   'icon'=>'bi-person-dash', 'color'=>'var(--gray-500)','bg'=>'var(--gray-100)',   'border'=>'var(--gray-200)'],
            ];
            foreach ($tab_statuses as $val => $tab):
                $isActive = ($status_filter === $val);
                $href = 'list.php?' . ($val ? 'status=' . $val . '&' : '') . ($date_filter ? 'date=' . urlencode($date_filter) . '&' : '') . ($type_filter ? 'type=' . urlencode($type_filter) . '&' : '') . ($search ? 'search=' . urlencode($search) . '&' : '') . ($doctor_filter ? 'doctor_id=' . $doctor_filter . '&' : '');
            ?>
            <a href="<?php echo $href; ?>" style="display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border-radius:20px;font-size:0.76rem;font-weight:700;text-decoration:none;transition:all 0.15s;
                border:1.5px solid <?php echo $isActive ? $tab['color'] : 'var(--gray-200)'; ?>;
                background:<?php echo $isActive ? $tab['bg'] : 'var(--white)'; ?>;
                color:<?php echo $isActive ? $tab['color'] : 'var(--gray-500)'; ?>;
                box-shadow:<?php echo $isActive ? '0 2px 8px rgba(0,0,0,0.08)' : 'none'; ?>;">
                <i class="bi <?php echo $tab['icon']; ?>"></i>
                <?php echo $tab['label']; ?>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- ── Type Filter Pills ────────────────────────────────── -->
        <button type="button" class="phone-filter-btn" onclick="document.getElementById('apptFilters').classList.toggle('open')">
    <i class="bi bi-funnel"></i> Search &amp; Filters
</button>
<div class="appt-filters<?php echo ($search || $date_filter || $doctor_filter || $type_filter) ? ' open' : ''; ?>" id="apptFilters">
        <div class="mobile-type-bar" style="display:flex;gap:6px;margin-bottom:16px;align-items:center;overflow-x:auto;padding-bottom:2px;">
            <span style="font-size:0.72rem;color:var(--gray-400);font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-right:2px;">Type:</span>
            <?php
            $type_opts = [
                ''          => ['label'=>'All Types',       'icon'=>'bi-grid'],
                'walk-in'   => ['label'=>'Walk-in',          'icon'=>'bi-person-walking'],
                'scheduled' => ['label'=>'Advance Booking',  'icon'=>'bi-calendar-check'],
            ];
            foreach ($type_opts as $tval => $topt):
                $tActive = ($type_filter === $tval);
                $tHref   = 'list.php?' . ($tval ? 'type=' . $tval . '&' : '') . ($status_filter ? 'status=' . urlencode($status_filter) . '&' : '') . ($date_filter ? 'date=' . urlencode($date_filter) . '&' : '') . ($search ? 'search=' . urlencode($search) . '&' : '') . ($doctor_filter ? 'doctor_id=' . $doctor_filter . '&' : '');
            ?>
            <a href="<?php echo $tHref; ?>" style="display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:20px;font-size:0.75rem;font-weight:700;text-decoration:none;transition:all 0.15s;
                border:1.5px solid <?php echo $tActive ? 'var(--primary)' : 'var(--gray-200)'; ?>;
                background:<?php echo $tActive ? 'var(--primary-bg)' : 'var(--white)'; ?>;
                color:<?php echo $tActive ? 'var(--primary)' : 'var(--gray-500)'; ?>;">
                <i class="bi <?php echo $topt['icon']; ?>"></i>
                <?php echo $topt['label']; ?>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- ── Filter Bar ───────────────────────────────────────── -->
        <form method="GET" style="background:var(--white);border:var(--border);border-radius:12px;padding:12px 16px;margin-bottom:16px;box-shadow:0 1px 4px rgba(0,0,0,0.04);">
            <?php if ($status_filter): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>"><?php endif; ?>
            <?php if ($type_filter): ?><input type="hidden" name="type" value="<?php echo htmlspecialchars($type_filter); ?>"><?php endif; ?>
            <div class="mobile-filter-bar" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                <!-- Search — full-width on mobile -->
                <div style="position:relative;flex:1;min-width:160px;">
                    <i class="bi bi-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--gray-400);font-size:0.82rem;"></i>
                    <input type="text" name="search" style="width:100%;padding:8px 12px 8px 32px;border:1.5px solid var(--gray-200);border-radius:9px;font-size:0.82rem;outline:none;transition:border 0.15s;background:var(--white);color:var(--gray-900);" placeholder="Search patient or code&#x2026;" value="<?php echo htmlspecialchars($search); ?>" onfocus="this.style.borderColor='var(--primary)'" onblur="this.style.borderColor='var(--gray-200)'">
                </div>
                <!-- Date + Today on same row -->
                <div class="appt-date-row" style="display:flex;gap:8px;align-items:center;">
                    <div class="date-input-wrap" style="position:relative;flex:1;">
                        <i class="bi bi-calendar3" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--gray-400);font-size:0.82rem;pointer-events:none;"></i>
                        <input type="date" name="date" style="width:100%;padding:8px 12px 8px 32px;border:1.5px solid var(--gray-200);border-radius:9px;font-size:0.82rem;outline:none;transition:border 0.15s;background:var(--white);color:var(--gray-900);" value="<?php echo htmlspecialchars($date_filter); ?>" onfocus="this.style.borderColor='var(--primary)'" onblur="this.style.borderColor='var(--gray-200)'">
                    </div>
                    <?php
                    // Carry ALL active filters so Today never silently drops context
                    $today_href = 'list.php?date=' . date('Y-m-d')
                        . ($status_filter ? '&status='    . urlencode($status_filter) : '')
                        . ($type_filter   ? '&type='      . urlencode($type_filter)   : '')
                        . ($search        ? '&search='    . urlencode($search)        : '')
                        . ($doctor_filter ? '&doctor_id=' . $doctor_filter            : '');
                    $today_active = ($date_filter === date('Y-m-d'));
                    ?>
                    <a href="<?php echo $today_href; ?>" class="today-btn" style="padding:8px 14px;border-radius:9px;white-space:nowrap;font-size:0.78rem;font-weight:700;text-decoration:none;
                        border:1.5px solid <?php echo $today_active ? 'var(--primary)' : 'var(--gray-200)'; ?>;
                        background:<?php echo $today_active ? 'var(--primary-bg)' : 'var(--white)'; ?>;
                        color:<?php echo $today_active ? 'var(--primary)' : 'var(--gray-500)'; ?>;">
                        <?php echo $today_active ? '&#x2713;&nbsp;Today' : 'Today'; ?>
                    </a>
                </div>
                <!-- Doctor dropdown -->
                <?php if (!empty($all_doctors)): ?>
                <select name="doctor_id" style="padding:8px 14px;border:1.5px solid var(--gray-200);border-radius:9px;font-size:0.82rem;outline:none;background:var(--white);color:var(--gray-600);transition:border 0.15s;" onfocus="this.style.borderColor='var(--primary)'" onblur="this.style.borderColor='var(--gray-200)'">
                    <option value="">All Doctors</option>
                    <?php foreach ($all_doctors as $d): ?>
                    <option value="<?php echo $d['id']; ?>" <?php echo $doctor_filter == $d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <!-- Filter + Clear side-by-side -->
                <div class="appt-btn-row" style="display:flex;gap:8px;">
                    <button type="submit" style="padding:8px 20px;border-radius:9px;background:var(--primary);color:var(--white);border:none;font-size:0.82rem;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px;transition:background 0.15s;" onmouseover="this.style.background='var(--primary-dark)'" onmouseout="this.style.background='var(--primary)'">
                        <i class="bi bi-funnel-fill"></i> Filter
                    </button>
                    <?php if ($search || $date_filter || $doctor_filter || $status_filter): ?>
                    <a href="list.php" style="padding:8px 16px;border-radius:9px;border:1.5px solid var(--danger-border);background:var(--danger-bg);color:var(--danger);font-size:0.82rem;font-weight:700;text-decoration:none;display:flex;align-items:center;gap:5px;">
                        <i class="bi bi-x-lg"></i> Clear
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <!-- ── Table ────────────────────────────────────────────── -->
        <div class="mobile-card-table-wrap" style="background:var(--white);border-radius:14px;border:var(--border);overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.06);">
            <div class="table-responsive" style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;" id="appointmentsTable" class="mobile-card-table">
                    <thead>
                        <tr style="border-bottom:2px solid var(--gray-200);">
                            <th style="padding:12px 16px;font-size:0.7rem;font-weight:800;text-transform:uppercase;letter-spacing:0.07em;color:var(--gray-600);text-align:left;">Code</th>
                            <th style="padding:12px 16px;font-size:0.7rem;font-weight:800;text-transform:uppercase;letter-spacing:0.07em;color:var(--gray-600);text-align:left;">Patient</th>
                            <th style="padding:12px 16px;font-size:0.7rem;font-weight:800;text-transform:uppercase;letter-spacing:0.07em;color:var(--gray-600);text-align:left;">Service</th>
                            <th style="padding:12px 16px;font-size:0.7rem;font-weight:800;text-transform:uppercase;letter-spacing:0.07em;color:var(--gray-600);text-align:left;">Doctor</th>
                            <th style="padding:12px 16px;font-size:0.7rem;font-weight:800;text-transform:uppercase;letter-spacing:0.07em;color:var(--gray-600);text-align:left;">Date & Time</th>
                            <th style="padding:12px 16px;font-size:0.7rem;font-weight:800;text-transform:uppercase;letter-spacing:0.07em;color:var(--gray-600);text-align:left;">Status</th>
                            <th style="padding:12px 16px;font-size:0.7rem;font-weight:800;text-transform:uppercase;letter-spacing:0.07em;color:var(--gray-600);text-align:left;min-width:260px;white-space:nowrap;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($appointments)): ?>
                        <tr>
                            <td colspan="7" style="padding:60px 20px;text-align:center;color:var(--gray-400);">
                                <i class="bi bi-calendar-x" style="font-size:2.5rem;display:block;margin-bottom:12px;opacity:0.5;"></i>
                                <div style="font-size:0.9rem;font-weight:600;">No appointments found.</div>
                                <div style="font-size:0.78rem;margin-top:4px;">Try adjusting your filters or add a new appointment.</div>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($appointments as $a):
                            $isToday = ($a['appointment_date'] === date('Y-m-d'));
                            $rowBg   = $isToday ? 'background:linear-gradient(to right,var(--blue-50),var(--white));' : '';
                            $sCfg = match($a['status']) {
                                'pending'   => ['bg'=>'var(--warning-bg)', 'color'=>'var(--warning)', 'border'=>'var(--warning-border)', 'label'=>'Pending',   'icon'=>'bi-clock-history'],
                                'confirmed' => ['bg'=>'var(--primary-bg)', 'color'=>'var(--primary)', 'border'=>'var(--blue-200)',      'label'=>'Confirmed', 'icon'=>'bi-check-circle-fill'],
                                'completed' => ['bg'=>'var(--success-bg)', 'color'=>'var(--success)', 'border'=>'var(--success-border)','label'=>'Completed', 'icon'=>'bi-check2-all'],
                                'cancelled' => ['bg'=>'var(--danger-bg)',  'color'=>'var(--danger)',  'border'=>'var(--danger-border)', 'label'=>'Cancelled', 'icon'=>'bi-x-circle-fill'],
                                'no-show'   => ['bg'=>'var(--gray-100)',   'color'=>'var(--gray-500)','border'=>'var(--gray-200)',      'label'=>'No-show',   'icon'=>'bi-person-dash'],
                                default     => ['bg'=>'var(--gray-100)',   'color'=>'var(--gray-400)','border'=>'var(--gray-200)',      'label'=>ucfirst($a['status']), 'icon'=>'bi-circle'],
                            };
                        ?>
                        <tr data-appt-id="<?php echo $a['id']; ?>" style="<?php echo $rowBg; ?>border-bottom:1px solid var(--gray-100);transition:background 0.15s;" onmouseover="this.style.background='var(--gray-50)'" onmouseout="this.style.background='<?php echo $isToday ? 'linear-gradient(to right,var(--blue-50),var(--white))' : 'var(--white)'; ?>'">
                            <!-- Code -->
                            <td data-label="Code" style="padding:13px 16px;">
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <?php if ($isToday): ?><span style="width:6px;height:6px;border-radius:50%;background:var(--primary);display:inline-block;flex-shrink:0;box-shadow:0 0 0 2px var(--blue-200);"></span><?php endif; ?>
                                    <span style="font-size:0.79rem;font-weight:700;color:var(--primary);font-family:monospace;"><?php echo htmlspecialchars($a['appointment_code']); ?></span>
                                </div>
                            </td>
                            <!-- Patient -->
                            <td data-label="Patient" style="padding:13px 16px;">
                                <a href="<?php echo BASE_URL; ?>modules/patients/view.php?id=<?php echo $a['patient_id']; ?>" style="font-size:0.85rem;font-weight:700;color:var(--gray-900);text-decoration:none;" onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color='var(--gray-900)'"><?php echo htmlspecialchars(ucwords(strtolower($a['patient_name'] ?? ''))); ?></a>
                                <?php if (($a['type'] ?? 'walk-in') === 'walk-in'): ?>
                                <span style="display:inline-flex;align-items:center;gap:3px;margin-left:5px;padding:1px 6px;border-radius:20px;font-size:0.67rem;font-weight:700;background:rgba(37,99,235,0.08);color:#2563eb;border:1px solid rgba(37,99,235,0.2);vertical-align:middle;"><i class="bi bi-person-walking" style="font-size:0.62rem;"></i>Walk-in</span>
                                <?php else: ?>
                                <span style="display:inline-flex;align-items:center;gap:3px;margin-left:5px;padding:1px 6px;border-radius:20px;font-size:0.67rem;font-weight:700;background:rgba(13,110,110,0.08);color:var(--primary);border:1px solid rgba(13,110,110,0.2);vertical-align:middle;"><i class="bi bi-calendar-check" style="font-size:0.62rem;"></i>Booked</span>
                                <?php endif; ?>
                            </td>
                            <!-- Service -->
                            <td data-label="Service" style="padding:13px 16px;">
                                <div style="font-size:0.82rem;color:var(--gray-600);font-weight:500;"><?php echo htmlspecialchars($a['service_name'] ?? '—'); ?></div>
                            </td>
                            <!-- Doctor -->
                            <td data-label="Doctor" style="padding:13px 16px;">
                                <?php if (!empty($a['doctor_name'])): ?>
                                <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;background:linear-gradient(135deg,var(--blue-500),var(--blue-400));color:var(--white);font-size:0.71rem;font-weight:700;white-space:nowrap;">
                                    <i class="bi bi-person-badge" style="font-size:0.68rem;"></i>
                                    <?php echo htmlspecialchars($a['doctor_name']); ?>
                                </span>
                                <?php else: ?>
                                <span style="font-size:0.78rem;color:var(--gray-300);">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <!-- Date & Time -->
                            <td data-label="Date & Time" style="padding:13px 16px;">
                                <div style="font-size:0.82rem;font-weight:700;color:var(--gray-700);"><?php echo date('M d, Y', strtotime($a['appointment_date'])); ?></div>
                                <div style="font-size:0.73rem;color:var(--gray-400);margin-top:1px;"><i class="bi bi-clock" style="font-size:0.65rem;"></i> <?php echo date('h:i A', strtotime($a['appointment_time'])); ?></div>
                            </td>
                            <!-- Status -->
                            <td data-label="Status" style="padding:13px 16px;">
                                <span id="appt-status-badge-<?php echo $a['id']; ?>" data-status="<?php echo $a['status']; ?>" style="display:inline-flex;align-items:center;gap:4px;padding:4px 11px;border-radius:20px;font-size:0.73rem;font-weight:700;background:<?php echo $sCfg['bg']; ?>;color:<?php echo $sCfg['color']; ?>;border:1.5px solid <?php echo $sCfg['border']; ?>;">
                                    <i class="bi <?php echo $sCfg['icon']; ?>" style="font-size:0.68rem;"></i>
                                    <?php echo $sCfg['label']; ?>
                                </span>
                            </td>
                            <!-- Actions -->
                            <td data-label="Actions" style="padding:13px 16px;min-width:260px;white-space:nowrap;">
                                <div style="display:flex;gap:5px;flex-wrap:nowrap;align-items:center;white-space:nowrap;">
                                    <?php if ($a['status'] === 'confirmed'): ?>
                                    <a href="<?php echo BASE_URL; ?>modules/treatments/add.php?patient_id=<?php echo $a['patient_id']; ?>&appointment_id=<?php echo $a['id']; ?>"
                                       style="display:inline-flex;align-items:center;gap:4px;padding:5px 12px;border-radius:8px;background:linear-gradient(135deg,var(--success),var(--success-light));color:var(--white);font-size:0.75rem;font-weight:700;text-decoration:none;box-shadow:0 2px 6px rgba(22,163,74,0.3);white-space:nowrap;">
                                        <i class="bi bi-person-check-fill"></i> Check-in
                                    </a>
                                    <?php elseif ($a['status'] === 'completed'): ?>
                                    <a href="<?php echo BASE_URL; ?>modules/patients/view.php?id=<?php echo $a['patient_id']; ?>"
                                       style="display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border-radius:8px;background:var(--success-bg);color:var(--success);border:1.5px solid var(--success-border);font-size:0.75rem;font-weight:700;text-decoration:none;white-space:nowrap;">
                                        <i class="bi bi-clipboard2-check"></i> Record
                                    </a>
                                    <?php if (!empty($a['bill_id'])): ?>
                                    <a href="<?php echo BASE_URL; ?>modules/billing/view.php?id=<?php echo $a['bill_id']; ?>"
                                       style="display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border-radius:8px;font-size:0.75rem;font-weight:700;text-decoration:none;white-space:nowrap;<?php echo $a['bill_status'] === 'paid' ? 'background:linear-gradient(135deg,var(--success),var(--success-light));color:var(--white);box-shadow:0 2px 6px rgba(22,163,74,0.25);' : 'background:var(--warning-bg);color:var(--warning);border:1.5px solid var(--warning-border);'; ?>">
                                        <i class="bi bi-receipt"></i> <?php echo $a['bill_status'] === 'paid' ? 'Paid' : 'Bill'; ?>
                                    </a>
                                    <?php else: ?>
                                    <a href="<?php echo BASE_URL; ?>modules/billing/create.php?patient_id=<?php echo $a['patient_id']; ?>&appointment_id=<?php echo $a['id']; ?>"
                                       style="display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border-radius:8px;background:var(--blue-50);color:var(--primary);border:1.5px solid var(--blue-200);font-size:0.75rem;font-weight:700;text-decoration:none;white-space:nowrap;">
                                        <i class="bi bi-receipt"></i> Create Bill
                                    </a>
                                    <?php endif; ?>
                                    <?php elseif ($a['status'] === 'pending'): ?>
                                    <button onclick="openConfirmModal(<?php echo $a['id']; ?>, '<?php echo htmlspecialchars($a['appointment_code'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($a['patient_name'], ENT_QUOTES); ?>')"
                                       style="display:inline-flex;align-items:center;gap:4px;padding:5px 12px;border-radius:8px;background:var(--blue-50);color:var(--primary);border:1.5px solid var(--blue-200);font-size:0.75rem;font-weight:700;cursor:pointer;white-space:nowrap;">
                                        <i class="bi bi-check-lg"></i> Confirm
                                    </button>
                                    <?php endif; ?>
                                    <!-- Print -->
                                    <a href="<?php echo BASE_URL; ?>modules/print/appointment_slip.php?id=<?php echo $a['id']; ?>"
                                       style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:7px;background:var(--gray-50);color:var(--gray-500);border:1.5px solid var(--gray-200);text-decoration:none;font-size:0.8rem;transition:all 0.12s;" title="Print Slip" aria-label="Print appointment slip">
                                        <i class="bi bi-printer" aria-hidden="true"></i>
                                    </a>
                                    <!-- Reschedule / Edit -->
                                    <button onclick="openRescheduleModal(<?php echo $a['id']; ?>)"
                                       style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:7px;background:var(--primary-bg);color:var(--primary);border:1.5px solid var(--blue-200);cursor:pointer;font-size:0.8rem;transition:all 0.12s;" title="Reschedule / Edit" aria-label="Reschedule appointment">
                                        <i class="bi bi-calendar-week" aria-hidden="true"></i>
                                    </button>
                                    <!-- Edit Status -->
                                    <button onclick="updateStatus(<?php echo $a['id']; ?>, this)"
                                       style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:7px;background:var(--gray-50);color:var(--gray-500);border:1.5px solid var(--gray-200);cursor:pointer;font-size:0.8rem;transition:all 0.12s;" title="Update Status" aria-label="Update appointment status">
                                        <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                    </button>
                                    <!-- Delete — admin only -->
                                    <?php if ($current_user_role === 'admin'): ?>
                                    <button onclick="confirmDeleteAppt(<?php echo $a['id']; ?>, '<?php echo htmlspecialchars($a['appointment_code'], ENT_QUOTES); ?>')"
                                       style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:7px;background:var(--danger-bg);color:var(--danger);border:1.5px solid var(--danger-border);cursor:pointer;font-size:0.8rem;transition:all 0.12s;" title="Delete" aria-label="Delete appointment">
                                        <i class="bi bi-trash" aria-hidden="true"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div><!-- /.table-responsive -->
        </div><!-- /.mobile-card-table-wrap -->

        <?php if ($total_pages > 1): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-top:16px;background:var(--white);border:var(--border);border-radius:12px;padding:10px 16px;box-shadow:0 1px 4px rgba(0,0,0,0.04);">
            <div style="font-size:0.78rem;color:var(--gray-400);font-weight:600;">
                Showing <strong style="color:var(--gray-600);"><?php echo number_format($offset + 1); ?>–<?php echo number_format(min($offset + $per_page, $total_count)); ?></strong>
                of <strong style="color:var(--gray-600);"><?php echo number_format($total_count); ?></strong> appointments
            </div>
            <div style="display:flex;gap:5px;align-items:center;">
                <?php if ($page > 1): ?>
                <a href="list.php?<?php echo $filter_qs; ?>page=<?php echo $page - 1; ?>" style="display:inline-flex;align-items:center;gap:4px;padding:6px 12px;border-radius:8px;border:1.5px solid var(--gray-200);background:var(--white);color:var(--gray-600);font-size:0.78rem;font-weight:600;text-decoration:none;transition:all 0.12s;" onmouseover="this.style.background='var(--gray-100)'" onmouseout="this.style.background='var(--white)'">
                    <i class="bi bi-chevron-left"></i> Prev
                </a>
                <?php endif; ?>
                <?php for ($pg = max(1, $page - 2); $pg <= min($total_pages, $page + 2); $pg++): ?>
                <a href="list.php?<?php echo $filter_qs; ?>page=<?php echo $pg; ?>" style="display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:8px;font-size:0.82rem;font-weight:700;text-decoration:none;transition:all 0.12s;<?php echo $pg === $page ? 'background:linear-gradient(135deg,var(--blue-500),var(--blue-400));color:var(--white);border:none;box-shadow:0 2px 8px rgba(37,99,235,0.3);' : 'border:1.5px solid var(--gray-200);background:var(--white);color:var(--gray-600);'; ?>"><?php echo $pg; ?></a>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                <a href="list.php?<?php echo $filter_qs; ?>page=<?php echo $page + 1; ?>" style="display:inline-flex;align-items:center;gap:4px;padding:6px 12px;border-radius:8px;border:1.5px solid var(--gray-200);background:var(--white);color:var(--gray-600);font-size:0.78rem;font-weight:600;text-decoration:none;transition:all 0.12s;" onmouseover="this.style.background='var(--gray-100)'" onmouseout="this.style.background='var(--white)'">
                    Next <i class="bi bi-chevron-right"></i>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>

<!-- Status Update Modal -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">Update Appointment Status</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="appt_id">
                <label class="form-label">New Status</label>
                <select id="new_status" class="form-select">
                    <option value="pending">Pending</option>
                    <option value="confirmed">Confirmed</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                    <option value="no-show">No-show</option>
                </select>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary btn-sm" onclick="saveStatus()">Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Confirm Appointment Modal -->
<div class="modal fade" id="confirmApptModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" style="color:var(--blue-600);"><i class="bi bi-check-circle-fill"></i> Confirm Appointment</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="font-size:0.875rem;">
                Confirm appointment <strong id="confirmApptCode"></strong> for <strong id="confirmApptPatient"></strong>?
                <div style="margin-top:10px;padding:10px;background:var(--blue-50);border-radius:6px;font-size:0.78rem;color:var(--blue-600);">
                    <i class="bi bi-info-circle-fill"></i> This will mark the appointment as <strong>Confirmed</strong> and notify the patient.
                </div>
                <div id="confirmApptError" style="display:none;margin-top:10px;padding:8px 10px;background:var(--danger-bg);border-radius:6px;font-size:0.78rem;color:var(--danger);border:1px solid var(--danger-border);">
                    <i class="bi bi-exclamation-circle-fill"></i> <span></span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="doConfirmBtn" class="btn btn-sm btn-primary" onclick="doConfirmAppt()">
                    <i class="bi bi-check-lg"></i> Yes, Confirm
                </button>
            </div>
        </div>
    </div>
</div>

<!-- No Dental Record Warning Modal -->
<div class="modal fade" id="noRecordModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header" style="border-bottom:1px solid var(--warning-border);background:var(--warning-bg);">
                <h6 class="modal-title" style="color:var(--warning);">
                    <i class="bi bi-exclamation-triangle-fill" style="color:var(--warning-light);margin-right:6px;"></i>
                    No Treatment Record Yet
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="font-size:0.875rem;color:var(--gray-700);">
                <p style="margin:0 0 10px;">This appointment has <strong>no dental record</strong> attached to it yet.</p>
                <p style="margin:0;color:var(--gray-500);font-size:0.8rem;">Would you like to record the treatment first, or mark it as completed anyway?</p>
                <div style="margin-top:12px;padding:10px 12px;background:var(--warning-bg);border-radius:8px;border:1px solid var(--warning-border);font-size:0.78rem;color:var(--warning);">
                    <i class="bi bi-info-circle-fill" style="margin-right:5px;"></i>
                    Skipping this may leave the patient's clinical history incomplete.
                </div>
            </div>
            <div class="modal-footer" style="flex-wrap:wrap;gap:6px;justify-content:stretch;">
                <button type="button" class="btn btn-sm btn-primary" style="flex:1;min-width:140px;" onclick="goToRecordTreatment()">
                    <i class="bi bi-clipboard2-pulse"></i> Go to Record Treatment
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" style="flex:1;min-width:120px;" onclick="completeAnyway()">
                    <i class="bi bi-check2-all"></i> Complete Anyway
                </button>
                <button type="button" class="btn btn-sm btn-link text-secondary w-100" style="font-size:0.78rem;" data-bs-dismiss="modal">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Appointment Modal -->
<div class="modal fade" id="deleteApptModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" style="color:var(--danger);"><i class="bi bi-exclamation-triangle-fill"></i> Delete Appointment</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="font-size:0.875rem;">
                Permanently delete appointment <strong id="deleteApptCode"></strong>?
                <div style="margin-top:10px;padding:10px;background:var(--danger-bg);border-radius:6px;font-size:0.78rem;color:var(--danger);">
                    <i class="bi bi-exclamation-triangle-fill"></i> <strong>Warning:</strong> This will permanently delete the appointment and its payment records. This cannot be undone.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">No, Keep It</button>
                <button type="button" class="btn btn-sm btn-danger" onclick="doDeleteAppt()">
                    <i class="bi bi-trash"></i> Yes, Delete It
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── Reschedule / Edit Appointment Modal ─────────────────── -->
<div class="modal fade" id="rescheduleModal" tabindex="-1" aria-labelledby="rescheduleModalLabel">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="border-bottom:var(--border);padding:16px 20px;">
                <h6 class="modal-title" id="rescheduleModalLabel" style="font-weight:700;font-size:0.95rem;display:flex;align-items:center;gap:8px;">
                    <i class="bi bi-calendar-week" style="color:var(--primary);"></i>
                    Reschedule / Edit Appointment
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding:20px;">
                <div id="rescheduleAlert" style="display:none;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:0.83rem;"></div>

                <!-- Patient info (readonly) -->
                <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--gray-50);border:1.5px solid var(--gray-200);border-radius:8px;margin-bottom:16px;">
                    <i class="bi bi-person-fill" style="color:var(--primary);font-size:1.1rem;"></i>
                    <div>
                        <div id="rsPatientName" style="font-weight:700;font-size:0.87rem;color:var(--gray-900);"></div>
                        <div id="rsApptCode"   style="font-size:0.72rem;color:var(--gray-400);font-family:monospace;"></div>
                    </div>
                    <span id="rsStatusBadge" style="margin-left:auto;font-size:0.72rem;font-weight:700;padding:3px 9px;border-radius:20px;"></span>
                </div>

                <input type="hidden" id="rs_appt_id">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" style="font-size:0.8rem;font-weight:700;">New Date <span style="color:var(--danger);">*</span></label>
                        <input type="date" id="rs_date" class="form-control" min="<?php echo date('Y-m-d'); ?>" onchange="rsLoadSlots()">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" style="font-size:0.8rem;font-weight:700;">New Time <span style="color:var(--danger);">*</span></label>
                        <select id="rs_time" class="form-select">
                            <option value="">Select date first…</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" style="font-size:0.8rem;font-weight:700;">Service</label>
                        <select id="rs_service" class="form-select">
                            <option value="">— No change —</option>
                            <?php
                            $svc_list = sc_get('svc_walkin') ?? sc_set('svc_walkin',
                                $conn->query("SELECT id, service_name, price FROM services WHERE is_active = TRUE ORDER BY service_name")
                                     ->fetchAll(PDO::FETCH_ASSOC), 300);
                            foreach ($svc_list as $sv): ?>
                            <option value="<?php echo $sv['id']; ?>"><?php echo e($sv['service_name']); ?> — ₱<?php echo number_format($sv['price'], 2); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" style="font-size:0.8rem;font-weight:700;">Doctor</label>
                        <select id="rs_doctor" class="form-select" onchange="rsLoadSlots()">
                            <option value="">Any Available Doctor</option>
                            <?php foreach ($all_doctors as $d): ?>
                            <option value="<?php echo $d['id']; ?>"><?php echo e($d['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" style="font-size:0.8rem;font-weight:700;">Notes / Chief Complaint</label>
                        <textarea id="rs_notes" class="form-control" rows="2" placeholder="Update notes if needed…"></textarea>
                    </div>
                </div>

                <!-- Slot status notice -->
                <div id="rsSlotNotice" style="display:none;margin-top:12px;padding:8px 12px;border-radius:7px;font-size:0.78rem;"></div>
            </div>
            <div class="modal-footer" style="border-top:var(--border);padding:12px 20px;">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-primary" id="rsSubmitBtn" onclick="doReschedule()">
                    <i class="bi bi-calendar-check"></i> Save Changes
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function getModal(id){var el=document.getElementById(id);return el?bootstrap.Modal.getOrCreateInstance(el):null;}
// Strip ?walkin and ?patient_id from the URL before reloading so the
// New Appointment drawer never auto-reopens after a booking or confirm action.
function cleanReload(){var u=new URL(window.location.href);u.searchParams.delete('walkin');u.searchParams.delete('patient_id');window.location.href=u.toString();}
// Remove any orphaned Bootstrap backdrops left over from a previous modal.
function nukeBackdrops(){document.querySelectorAll('.modal-backdrop').forEach(function(el){el.remove();});document.body.classList.remove('modal-open');document.body.style.removeProperty('overflow');document.body.style.removeProperty('padding-right');}
/* ── Reschedule / Edit Modal ─────────────────────────────── */
function openRescheduleModal(apptId) {
    document.getElementById('rescheduleAlert').style.display = 'none';
    document.getElementById('rsSlotNotice').style.display    = 'none';
    document.getElementById('rs_appt_id').value = apptId;
    document.getElementById('rsSubmitBtn').disabled = false;

    fetch(_baseUrl + 'api/appointments.php?action=get_appointment&id=' + apptId)
        .then(r => r.json())
        .then(function (res) {
            if (res.status !== 'ok') { alert(res.message || 'Failed to load appointment.'); return; }
            var a = res.appointment;
            document.getElementById('rsPatientName').textContent = a.patient_name || '--';
            document.getElementById('rsApptCode').textContent   = a.appointment_code || '';
            var statusColors = {
                pending:   ['var(--warning-bg)','var(--warning)'],
                confirmed: ['var(--blue-50)','#1d4ed8'],
                completed: ['var(--success-bg)','var(--success)'],
                cancelled: ['var(--danger-bg)','var(--danger)'],
                'no-show': ['var(--gray-100)','var(--gray-500)'],
            };
            var sc = statusColors[a.status] || ['var(--gray-100)','var(--gray-500)'];
            var badge = document.getElementById('rsStatusBadge');
            badge.textContent = a.status ? a.status.charAt(0).toUpperCase() + a.status.slice(1) : '';
            badge.style.background = sc[0]; badge.style.color = sc[1];

            // Prefill fields
            document.getElementById('rs_date').value  = a.appointment_date || '';
            document.getElementById('rs_notes').value = a.notes || '';
            // Service
            var svcSel = document.getElementById('rs_service');
            svcSel.value = a.service_id || '';
            // Doctor
            var docSel = document.getElementById('rs_doctor');
            docSel.value = a.doctor_id || '';

            // Load slots for the current date
            rsLoadSlots(a.appointment_time);
            getModal('rescheduleModal').show();
        })
        .catch(function () { alert('Network error. Please try again.'); });
}

function rsLoadSlots(preselect) {
    var date     = document.getElementById('rs_date').value;
    var doctorId = document.getElementById('rs_doctor').value;
    var timeSel  = document.getElementById('rs_time');
    var notice   = document.getElementById('rsSlotNotice');

    if (!date) { timeSel.innerHTML = '<option value="">Pick a date first…</option>'; return; }
    timeSel.innerHTML = '<option value="">Loading slots…</option>';
    notice.style.display = 'none';

    var url = _baseUrl + 'api/appointments.php?action=get_slots&date=' + date + (doctorId ? '&doctor_id=' + doctorId : '');
    fetch(url).then(r => r.json()).then(function (res) {
        timeSel.innerHTML = '';
        if (!res.slots || res.slots.length === 0) {
            timeSel.innerHTML = '<option value="">No slots available</option>';
            notice.style.cssText = 'display:block;background:var(--danger-bg);color:var(--danger);border:1px solid var(--danger-border);';
            notice.textContent = res.message || 'No available slots on this date.';
            return;
        }
        var availCount = 0;
        res.slots.forEach(function (s) {
            var opt = document.createElement('option');
            opt.value = s.time_24;
            opt.textContent = s.time_12 + (s.available ? '' : ' (booked)');
            opt.disabled = !s.available;
            if (preselect && s.time_24 === preselect.slice(0,5)) opt.selected = true;
            if (s.available) availCount++;
            timeSel.appendChild(opt);
        });
        if (availCount > 0) {
            notice.style.cssText = 'display:block;background:var(--success-bg);color:var(--success);border:1px solid rgba(21,128,61,0.2);';
            notice.textContent = availCount + ' slot' + (availCount===1?'':'s') + ' available on this date.';
            notice.style.display = 'block';
        }
    }).catch(function () {
        timeSel.innerHTML = '<option value="">Error loading slots</option>';
    });
}

function rsShowAlert(type, msg) {
    var el = document.getElementById('rescheduleAlert');
    var styles = {
        danger:  'background:var(--danger-bg);color:var(--danger);border:1.5px solid var(--danger-border);',
        success: 'background:var(--success-bg);color:var(--success);border:1.5px solid rgba(21,128,61,0.2);',
        warning: 'background:var(--warning-bg);color:var(--warning);border:1.5px solid var(--warning-border);',
    };
    el.style.cssText = (styles[type] || styles.danger) + 'padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:0.83rem;';
    el.innerHTML = msg;
    el.style.display = 'block';
}

function doReschedule() {
    var id      = document.getElementById('rs_appt_id').value;
    var date    = document.getElementById('rs_date').value;
    var time    = document.getElementById('rs_time').value;
    var service = document.getElementById('rs_service').value;
    var doctor  = document.getElementById('rs_doctor').value;
    var notes   = document.getElementById('rs_notes').value;
    var btn     = document.getElementById('rsSubmitBtn');

    if (!date) { rsShowAlert('danger', 'Please select a date.'); return; }
    if (!time) { rsShowAlert('danger', 'Please select a time slot.'); return; }

    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving…';

    fetch(_baseUrl + 'api/appointments.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'reschedule',
            id: parseInt(id),
            appointment_date: date,
            appointment_time: time,
            service_id: service ? parseInt(service) : null,
            doctor_id:  doctor  ? parseInt(doctor)  : null,
            notes: notes,
            _csrf: getCsrfToken(),
        }),
    })
    .then(r => r.json())
    .then(function (res) {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-calendar-check"></i> Save Changes';
        if (res.status === 'success') {
            if (res.duplicate_warning) {
                rsShowAlert('warning', '<i class="bi bi-exclamation-triangle-fill"></i> ' + res.duplicate_warning + '<br>Appointment rescheduled successfully.');
                setTimeout(function () { getModal('rescheduleModal').hide(); cleanReload(); }, 3000);
            } else {
                getModal('rescheduleModal').hide();
                cleanReload();
            }
        } else {
            rsShowAlert('danger', '<i class="bi bi-x-circle-fill"></i> ' + (res.message || 'Reschedule failed.'));
        }
    })
    .catch(function () {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-calendar-check"></i> Save Changes';
        rsShowAlert('danger', 'Network error. Please try again.');
    });
}
var deleteApptId    = null;

function updateStatus(id, btn) {
    document.getElementById('appt_id').value = id;
    getModal('statusModal').show();
}

function getCsrfToken() {
    var el = document.querySelector('input[name="_csrf"]');
    return el ? el.value : '';
}

// Status badge config for optimistic update (mirrors PHP $statusConfig)
var _statusCfg = {
    pending:   { bg:'var(--warning-bg)',  color:'var(--warning)',  border:'var(--warning-border)',  icon:'bi-clock',            label:'Pending'   },
    confirmed: { bg:'var(--primary-bg)', color:'var(--primary)',  border:'var(--blue-200)',         icon:'bi-calendar-check',   label:'Confirmed' },
    completed: { bg:'var(--success-bg)', color:'var(--success)',  border:'var(--success-border)',  icon:'bi-check-circle-fill', label:'Completed' },
    cancelled: { bg:'var(--danger-bg)',  color:'var(--danger)',   border:'var(--danger-border)',   icon:'bi-x-circle-fill',    label:'Cancelled' },
    'no-show': { bg:'var(--gray-100)',   color:'var(--gray-500)', border:'var(--gray-300)',         icon:'bi-person-x-fill',    label:'No-Show'   }
};

function _applyStatusBadge(badge, status) {
    var cfg = _statusCfg[status];
    if (!cfg || !badge) return;
    badge.dataset.status = status;
    badge.style.background = cfg.bg;
    badge.style.color      = cfg.color;
    badge.style.border     = '1.5px solid ' + cfg.border;
    var icon = badge.querySelector('i');
    if (icon) { icon.className = 'bi ' + cfg.icon; icon.style.fontSize = '0.68rem'; }
    // Replace text node (last child) with label
    var nodes = badge.childNodes;
    for (var n = nodes.length - 1; n >= 0; n--) {
        if (nodes[n].nodeType === 3) { badge.removeChild(nodes[n]); break; }
    }
    badge.appendChild(document.createTextNode('\n                                    ' + cfg.label + '\n                                '));
}

function saveStatus() {
    var id     = document.getElementById('appt_id').value;
    var status = document.getElementById('new_status').value;
    var badge  = document.getElementById('appt-status-badge-' + id);

    // ── Optimistic: snapshot → update badge → close modal immediately ─────────
    var snapshot = badge ? badge.outerHTML : null;
    if (badge) _applyStatusBadge(badge, status);
    getModal('statusModal').hide();

    fetch(_baseUrl+'api/appointments.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update_status', id: id, status: status, _csrf: getCsrfToken() })
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            // Server confirmed — do a silent reload to refresh action buttons
            cleanReload();
        } else if (data.status === 'no_record_warning') {
            pendingCompleteId = data.appt_id;
            getModal('noRecordModal').show();
        } else {
            // ── Rollback badge ───────────────────────────────────────────────
            if (badge && snapshot) {
                var tmp = document.createElement('span');
                tmp.innerHTML = snapshot;
                badge.parentNode.replaceChild(tmp.firstChild, badge);
            }
            alert('Error: ' + data.message);
        }
    })
    .catch(function() {
        if (badge && snapshot) {
            var tmp = document.createElement('span');
            tmp.innerHTML = snapshot;
            badge.parentNode.replaceChild(tmp.firstChild, badge);
        }
        alert('Network error. Status was not updated.');
    });
}

var pendingCompleteId = null;

function goToRecordTreatment() {
    getModal('noRecordModal').hide();
    window.location.href = _baseUrl + 'modules/treatments/add.php?appointment_id=' + pendingCompleteId;
}

function completeAnyway() {
    getModal('noRecordModal').hide();
    fetch(_baseUrl+'api/appointments.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update_status', id: pendingCompleteId, status: 'completed', force: true, _csrf: getCsrfToken() })
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') cleanReload();
        else alert('Error: ' + data.message);
    });
}

function confirmDeleteAppt(id, code) {
    deleteApptId = id;
    document.getElementById('deleteApptCode').textContent = code;
    getModal('deleteApptModal').show();
}

function doDeleteAppt() {
    var row = document.querySelector('tr[data-appt-id="' + deleteApptId + '"]');

    // ── Optimistic: close modal + collapse row immediately ────────────────────
    getModal('deleteApptModal').hide();
    if (row) {
        row.style.transition = 'opacity 0.25s, transform 0.25s';
        row.style.opacity    = '0.3';
        row.style.transform  = 'scaleY(0.95)';
        row.style.pointerEvents = 'none';
    }

    fetch(_baseUrl+'api/appointments.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete_appointment', id: deleteApptId, _csrf: getCsrfToken() })
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            // Server confirmed — animate fully out then remove
            if (row) {
                row.style.transition = 'opacity 0.2s, max-height 0.3s, padding 0.3s';
                row.style.opacity    = '0';
                row.style.overflow   = 'hidden';
                row.style.maxHeight  = '0';
                row.style.padding    = '0';
                setTimeout(function() { if (row.parentNode) row.parentNode.removeChild(row); }, 320);
            }
        } else {
            // ── Rollback row ─────────────────────────────────────────────────
            if (row) {
                row.style.opacity    = '1';
                row.style.transform  = 'none';
                row.style.pointerEvents = '';
            }
            alert('Error: ' + data.message);
        }
    })
    .catch(function() {
        if (row) {
            row.style.opacity    = '1';
            row.style.transform  = 'none';
            row.style.pointerEvents = '';
        }
        alert('Network error. Appointment was not deleted.');
    });
}

var pendingConfirmId = null;

function openConfirmModal(id, code, patient) {
    pendingConfirmId = id;
    document.getElementById('confirmApptCode').textContent    = code;
    document.getElementById('confirmApptPatient').textContent = patient;
    nukeBackdrops(); // clear any orphaned backdrop before opening
    getModal('confirmApptModal').show();
}

function doConfirmAppt() {
    if (!pendingConfirmId) return;
    var btn = document.getElementById('doConfirmBtn');
    if (btn) { btn.disabled = true; btn.textContent = 'Confirming...'; }
    getModal('confirmApptModal').hide();
    fetch(_baseUrl+'api/appointments.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update_status', id: pendingConfirmId, status: 'confirmed', _csrf: getCsrfToken() })
    })
    .then(res => {
        if (!res.ok) throw new Error('Server error (' + res.status + '). Please refresh and try again.');
        return res.json();
    })
    .then(data => {
        if (data.status === 'success') {
            cleanReload();
        } else {
            getModal('confirmApptModal').show();
            if (btn) { btn.disabled = false; btn.textContent = 'Yes, Confirm'; }
            var errEl = document.getElementById('confirmApptError');
            if (errEl) { errEl.querySelector('span').textContent = data.message || 'Confirmation failed. Please try again.'; errEl.style.display = 'block'; }
            else { alert('Error: ' + (data.message || 'Confirmation failed. Please try again.')); }
        }
    })
    .catch(err => {
        getModal('confirmApptModal').show();
        if (btn) { btn.disabled = false; btn.textContent = 'Yes, Confirm'; }
        var errEl = document.getElementById('confirmApptError');
        if (errEl) { errEl.querySelector('span').textContent = err.message || 'A server error occurred. Please refresh and try again.'; errEl.style.display = 'block'; }
        else { alert(err.message || 'A server error occurred. Please refresh.'); }
    });
}
</script>

<!-- ── New Appointment Modal ─────────────────────────────── -->
<div class="modal fade" id="apptModal" tabindex="-1" aria-labelledby="apptModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content" style="border:none;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,0.18);">

      <!-- Header -->
      <div class="modal-header" style="padding:18px 24px;border-bottom:var(--border);background:linear-gradient(135deg,var(--blue-500),var(--blue-400));border-radius:16px 16px 0 0;">
        <div>
          <h5 class="modal-title" id="apptModalLabel" style="color:#fff;font-weight:800;font-size:1rem;margin:0;"><i class="bi bi-calendar2-plus"></i> New Appointment</h5>
          <p id="drawerSubtitle" style="color:rgba(255,255,255,0.82);font-size:0.78rem;margin:2px 0 0;">Schedule a future appointment for a patient</p>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <!-- Slot info bar -->
      <div id="drawerSlotBar" style="background:var(--primary-bg);border-bottom:var(--border);padding:8px 24px;font-size:0.82rem;display:flex;align-items:center;gap:8px;">
        <span style="color:var(--gray-400);">Loading slot info...</span>
      </div>

      <!-- Alert -->
      <div id="drawerAlert" style="display:none;padding:0 24px;margin-top:14px;"></div>

      <div class="modal-body" style="padding:22px 24px;">
        <form id="walkinDrawerForm" autocomplete="off">
            <input type="hidden" name="_ajax" value="1">
            <input type="hidden" name="mode" value="appointment">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="existing_patient_id" id="drawerExistingPatientId" value="">
            <div class="row g-3">

                <!-- Date -->
                <div class="col-12">
                    <label class="form-label" style="font-weight:600;">Appointment Date <span style="color:var(--danger)">*</span></label>
                    <input type="date" name="appointment_date" id="drawerDate" class="form-control" value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
                    <div style="font-size:0.72rem;color:var(--gray-500);margin-top:4px;">Advance booking — choose a future date.</div>
                </div>

                <!-- Patient search -->
                <div class="col-12">
                    <label class="form-label" style="font-weight:600;">Patient Search</label>
                    <div style="position:relative;">
                        <input type="text" id="drawerPatientSearch" class="form-control"
                            placeholder="Search by name or phone to find returning patient…"
                            autocomplete="off" oninput="searchPatient(this.value)">
                        <div id="drawerPatientResults" style="display:none;position:absolute;left:0;right:0;top:100%;z-index:500;background:var(--white);border:var(--border);border-top:none;border-radius:0 0 8px 8px;box-shadow:0 6px 20px rgba(0,0,0,0.1);max-height:200px;overflow-y:auto;"></div>
                    </div>
                    <div id="drawerPatientSelected" style="display:none;margin-top:7px;background:var(--blue-50);border:1px solid var(--blue-200);border-radius:8px;padding:8px 12px;font-size:0.82rem;display:flex;align-items:center;gap:8px;">
                        <i class="bi bi-person-check-fill" style="color:var(--blue-500);"></i>
                        <span id="drawerPatientSelectedName" style="font-weight:600;flex:1;"></span>
                        <button type="button" onclick="clearPatientSelection()" style="background:none;border:none;color:var(--gray-400);cursor:pointer;font-size:0.75rem;">✕ New patient</button>
                    </div>
                    <div style="margin-top:6px;display:flex;align-items:center;gap:8px;">
                        <span style="font-size:0.72rem;color:var(--gray-400);">New patient?</span>
                        <button type="button" id="drawerNewPatientBtn" onclick="toggleNewPatientFields()" class="btn btn-sm btn-outline-secondary" style="font-size:0.72rem;padding:2px 10px;">
                            <i class="bi bi-person-plus"></i> Enter Name Manually
                        </button>
                    </div>
                </div>

                <!-- Quick-entry new patient (hidden until "Enter Name Manually" is clicked) -->
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
                    <input type="text" name="phone" id="drawerPhone" class="form-control" placeholder="09XXXXXXXXX" maxlength="13">
                </div>

                <div class="col-12">
                    <label class="form-label">Service</label>
                    <select name="service_id" class="form-select">
                        <option value="">— No service selected —</option>
                        <?php foreach ($walkin_services as $sv): ?>
                        <option value="<?php echo $sv['id']; ?>"><?php echo e($sv['service_name']); ?> — ₱<?php echo number_format($sv['price'],2); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label">Doctor <span style="font-size:0.72rem;color:var(--gray-400);font-weight:400;">(optional)</span></label>
                    <select name="doctor_id" id="drawerDoctorSelect" class="form-select">
                        <option value="">Any Available Doctor</option>
                        <?php
                        $today_abbr  = strtolower(substr(date('l'), 0, 3));
                        $all_docs_dw = $conn->query("SELECT id, full_name, specialization, schedule_days FROM doctors WHERE is_active = TRUE ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($all_docs_dw as $d):
                            $ddays = array_map('trim', explode(',', $d['schedule_days'] ?? ''));
                            if (!in_array($today_abbr, $ddays)) continue;
                        ?>
                        <option value="<?php echo $d['id']; ?>"><?php echo e($d['full_name']); ?><?php if ($d['specialization']): ?> — <?php echo e($d['specialization']); ?><?php endif; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div id="drawerDoctorNote" style="font-size:0.72rem;color:var(--gray-400);margin-top:3px;">Showing doctors available today.</div>
                </div>

                <!-- Time slot picker -->
                <div class="col-12" id="drawerSlotPickerWrap">
                    <label class="form-label">Appointment Time <span style="color:var(--danger)">*</span></label>
                    <select name="selected_time" id="drawerSlotSelect" class="form-select" required>
                        <option value="">— Loading slots… —</option>
                    </select>
                    <div id="drawerSlotNote" style="font-size:0.72rem;color:var(--gray-400);margin-top:3px;"></div>
                </div>

                <div class="col-12">
                    <label class="form-label">Notes <span style="font-size:0.72rem;color:var(--gray-400);font-weight:400;">(optional)</span></label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="Chief complaint or remarks…" maxlength="500"></textarea>
                </div>
            </div>
        </form>
      </div><!-- /.modal-body -->

      <div class="modal-footer" style="padding:14px 24px;border-top:var(--border);gap:8px;">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success" id="walkinSubmitBtn" onclick="submitWalkin()" style="min-width:160px;font-weight:700;">
            <i class="bi bi-person-check-fill"></i> <span id="walkinBtnLabel">Book Appointment</span>
        </button>
      </div>
    </div><!-- /.modal-content -->
  </div><!-- /.modal-dialog -->
</div><!-- /#apptModal -->

<div id="walkinToast" style="display:none;position:fixed;bottom:28px;right:28px;z-index:2000;background:var(--white);border:1.5px solid var(--success-border);border-radius:12px;padding:14px 20px;box-shadow:0 8px 24px rgba(0,0,0,0.12);max-width:360px;animation:slideToast 0.3s ease;">
    <div style="display:flex;align-items:flex-start;gap:10px;">
        <span style="font-size:1.4rem;">✅</span>
        <div style="flex:1;">
            <div style="font-weight:700;margin-bottom:4px;color:var(--success);" id="walkinToastTitle"></div>
            <div style="font-size:0.85rem;color:var(--gray-600);" id="walkinToastMsg"></div>
        </div>
        <button onclick="document.getElementById('walkinToast').style.display='none'" style="background:none;border:none;color:var(--gray-400);cursor:pointer;font-size:1rem;padding:0;margin-left:4px;">✕</button>
    </div>
</div>

<script>
var _today = '<?php echo date('Y-m-d'); ?>';
var _tomorrow = '<?php echo date('Y-m-d', strtotime('+1 day')); ?>';
var _phpBaseUrl = '<?php echo BASE_URL; ?>';
var _baseUrl=(function(){
    try {
        var parsed=new URL(_phpBaseUrl);
        if(parsed.hostname===window.location.hostname){
            return window.location.protocol+'//'+window.location.host+parsed.pathname;
        }
    } catch(e){}
    var p=window.location.pathname.replace(/\/modules\/.*$/,'/');
    return window.location.protocol+'//'+window.location.host+p;
})();
var _patientSearchTimer = null;

function openWalkinDrawer(presetDate) {
    // Appointment drawer is for future bookings only — clamp to tomorrow
    var dateToUse = (presetDate && presetDate > _today) ? presetDate : _tomorrow;
    // Dispose any stale instance and nuke orphaned backdrops before re-opening
    var modalEl = document.getElementById('apptModal');
    var existing = bootstrap.Modal.getInstance(modalEl);
    if (existing) { try { existing.hide(); existing.dispose(); } catch(e) {} }
    document.querySelectorAll('.modal-backdrop').forEach(function(el) { el.remove(); });
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
    // Small delay so Bootstrap's hide transition fully finishes before creating
    // a fresh instance — prevents double-backdrop stacking.
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

function closeWalkinDrawer() {
    var modal = bootstrap.Modal.getInstance(document.getElementById('apptModal'));
    if (modal) modal.hide();
}

function searchPatient(q) {
    clearTimeout(_patientSearchTimer);
    var results = document.getElementById('drawerPatientResults');
    if (q.length < 2) { results.style.display = 'none'; return; }
    _patientSearchTimer = setTimeout(function() {
        fetch(_baseUrl + 'modules/walkin/add.php?action=search_patient&q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(data => {
            if (!data.patients || data.patients.length === 0) {
                results.innerHTML = '<div style="padding:10px 14px;font-size:0.82rem;color:var(--gray-400);">No existing patients found — will register as new.</div>';
            } else {
                results.innerHTML = data.patients.map(p => {
                    var name  = p.first_name + ' ' + p.last_name;
                    var phone = p.phone || 'No phone';
                    var appts = p.appt_count + ' appt' + (p.appt_count != 1 ? 's' : '');
                    return '<div class="patient-result-item" style="padding:9px 14px;cursor:pointer;border-bottom:1px solid var(--gray-100);font-size:0.83rem;transition:background 0.12s;" '
                        + 'onmouseenter="this.style.background=\'var(--gray-50)\'" onmouseleave="this.style.background=\'\'" '
                        + 'onclick="selectPatient(' + p.id + ',\'' + name.replace(/'/g,"\\'") + '\',\'' + p.patient_code + '\',\'' + phone.replace(/'/g,"\\'") + '\')">'
                        + '<div style="font-weight:600;">' + name + ' <span style="font-size:0.72rem;color:var(--gray-400);">(' + p.patient_code + ')</span></div>'
                        + '<div style="color:var(--gray-500);font-size:0.75rem;">' + phone + ' · ' + appts + '</div>'
                        + '</div>';
                }).join('');
            }
            results.style.display = 'block';
        });
    }, 280);
}

function selectPatient(id, name, code, phone) {
    document.getElementById('drawerExistingPatientId').value = id;
    document.getElementById('drawerPatientResults').style.display = 'none';
    document.getElementById('drawerPatientSearch').value = '';
    document.getElementById('drawerPatientSelectedName').textContent = name + ' (' + code + ') · ' + phone;
    document.getElementById('drawerPatientSelected').style.display = 'flex';
    _newPatientFieldsVisible = false;
    showNewPatientFields(false);
    updateNewPatientBtn();
}

function clearPatientSelection() {
    document.getElementById('drawerExistingPatientId').value = '';
    document.getElementById('drawerPatientSelected').style.display = 'none';
    _newPatientFieldsVisible = true;
    showNewPatientFields(true);
    updateNewPatientBtn();
}

var _newPatientFieldsVisible = false;

function showNewPatientFields(show) {
    var heading  = document.getElementById('drawerNewPatientHeading');
    var fn       = document.getElementById('drawerFirstNameWrap');
    var ln       = document.getElementById('drawerLastNameWrap');
    var ph       = document.getElementById('drawerPhoneWrap');
    var fn_input = document.getElementById('drawerFirstName');
    var ln_input = document.getElementById('drawerLastName');
    var ph_input = document.getElementById('drawerPhone');
    if (heading) heading.style.display = show ? '' : 'none';
    [fn, ln, ph].forEach(el => { if(el) el.style.display = show ? '' : 'none'; });
    if (fn_input) fn_input.required = show;
    if (ln_input) ln_input.required = show;
    if (ph_input) ph_input.required = show;
    if (!show) {
        if (fn_input) fn_input.value = '';
        if (ln_input) ln_input.value = '';
        if (ph_input) ph_input.value = '';
    }
}

function toggleNewPatientFields() {
    _newPatientFieldsVisible = !_newPatientFieldsVisible;
    showNewPatientFields(_newPatientFieldsVisible);
    updateNewPatientBtn();
    if (_newPatientFieldsVisible) {
        document.getElementById('drawerExistingPatientId').value = '';
        document.getElementById('drawerPatientSelected').style.display = 'none';
    }
}

function updateNewPatientBtn() {
    var btn = document.getElementById('drawerNewPatientBtn');
    if (!btn) return;
    if (_newPatientFieldsVisible) {
        btn.innerHTML = '<i class="bi bi-x"></i> Hide Name Fields';
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('btn-outline-danger');
    } else {
        btn.innerHTML = '<i class="bi bi-person-plus"></i> Enter Name Manually';
        btn.classList.remove('btn-outline-danger');
        btn.classList.add('btn-outline-secondary');
    }
}


document.addEventListener('click', function(e) {
    var res = document.getElementById('drawerPatientResults');
    var inp = document.getElementById('drawerPatientSearch');
    if (res && inp && !inp.contains(e.target) && !res.contains(e.target)) {
        res.style.display = 'none';
    }
});

function loadDrawerDateData(date) {
    var bar        = document.getElementById('drawerSlotBar');
    var docSelect  = document.getElementById('drawerDoctorSelect');
    var slotSelect = document.getElementById('drawerSlotSelect');
    var slotNote   = document.getElementById('drawerSlotNote');
    var docNote    = document.getElementById('drawerDoctorNote');
    if (!bar || !slotSelect || !docSelect) { return; }

    bar.innerHTML = '<span style="color:var(--gray-400);">Checking schedule…</span>';
    slotSelect.innerHTML = '<option value="">— Loading slots… —</option>';

    fetch(_baseUrl + 'modules/walkin/add.php?action=get_slots&date=' + encodeURIComponent(date))
    .then(r => r.json())
    .then(data => {
        if (data.status !== 'success') {
            bar.innerHTML = '<i class="bi bi-exclamation-triangle-fill" style="color:var(--warning);"></i> ' + (data.message || 'Could not load schedule.');
            return;
        }
        var sd = data.slot_data, docs = data.doctors || [];

        if (sd.is_closed) {
            bar.innerHTML = '<i class="bi bi-calendar-x" style="color:var(--warning);"></i> <strong>' + sd.reason + '</strong>';
        } else {
            var free = (sd.total_slots||0) - (sd.booked_count||0);
            bar.innerHTML = '<i class="bi bi-calendar-check" style="color:var(--primary);"></i> '
                + data.day_name + ' — <strong style="color:var(--primary);">' + free + ' slot' + (free!==1?'s':'') + ' free</strong>';
        }

        var savedDoc = docSelect.value;
        docSelect.innerHTML = '<option value="">Any Available Doctor</option>';
        if (docs.length === 0) {
            docNote.textContent = 'No doctors scheduled on this day.';
        } else {
            docs.forEach(function(d) {
                var opt = document.createElement('option');
                opt.value = d.id;
                opt.textContent = d.full_name + (d.specialization ? ' — ' + d.specialization : '');
                if (String(d.id) === String(savedDoc)) opt.selected = true;
                docSelect.appendChild(opt);
            });
            docNote.textContent = docs.length + ' doctor' + (docs.length!==1?'s':'') + ' available on this day.';
        }

        slotSelect.innerHTML = '<option value="">— Choose a time slot —</option>';
        slotSelect.setAttribute('required', 'required');

        if (sd.is_closed) {
            slotSelect.innerHTML = '<option value="" disabled>Clinic closed this day</option>';
            slotNote.textContent = '';
        } else {
            var fc = 0;
            (sd.all_slots || []).forEach(function(s) {
                if (!s.taken && !s.past) {
                    var opt = document.createElement('option');
                    opt.value = s.time;
                    opt.textContent = s.label;
                    slotSelect.appendChild(opt);
                    fc++;
                }
            });
            slotNote.textContent = fc > 0 ? fc + ' available slot' + (fc!==1?'s':'') + '.' : 'No available slots.';
            if (fc === 0) slotSelect.innerHTML = '<option value="" disabled>No slots available</option>';
        }
    })
    .catch(function(err) {
        if (bar) bar.innerHTML = '<i class="bi bi-exclamation-triangle-fill" style="color:var(--warning);"></i> Could not load schedule.';
        if (slotSelect) slotSelect.innerHTML = '<option value="" disabled>Error loading slots</option>';
    });
}

document.addEventListener('DOMContentLoaded', function() {
    var di = document.getElementById('drawerDate');
    if (di) di.addEventListener('change', function() { if (this.value) loadDrawerDateData(this.value); });
});

function showDrawerAlert(type, msg) {
    var el = document.getElementById('drawerAlert');
    el.style.display = 'block';
    el.innerHTML = '<div class="alert alert-' + type + '" style="margin:0;font-size:0.85rem;"><i class="bi bi-' + (type==='danger'?'x-circle-fill':'check-circle-fill') + '"></i> ' + msg + '</div>';
}
function hideDrawerAlert() { document.getElementById('drawerAlert').style.display = 'none'; }

function submitWalkin() {
    var form       = document.getElementById('walkinDrawerForm');
    var btn        = document.getElementById('walkinSubmitBtn');
    var existingId = document.getElementById('drawerExistingPatientId').value;
    var date       = form.querySelector('[name=appointment_date]').value || _tomorrow;
    var sv         = form.querySelector('[name=selected_time]') ? form.querySelector('[name=selected_time]').value : '';

    if (!existingId) {
        var first = form.querySelector('[name=first_name]').value.trim();
        var last  = form.querySelector('[name=last_name]').value.trim();
        if (!first || !last) { showDrawerAlert('danger', 'Enter a patient name, or search and select an existing patient.'); return; }
    }
    if (date <= _today) { showDrawerAlert('danger', 'Please choose a future date for the appointment.'); return; }
    if (!sv) { showDrawerAlert('danger', 'Please select a time slot for the appointment.'); return; }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Booking...';
    hideDrawerAlert();

    fetch(_baseUrl + 'modules/walkin/add.php', { method: 'POST', body: new FormData(form) })
    .then(r => r.json())
    .then(res => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-person-check-fill"></i> <span>Book Appointment</span>';
        if (res.status === 'success') {
            var appt           = res.appt || {};
            var isReturning    = !!res.is_returning;
            var returningBadge = isReturning
                ? ' <span style="font-size:0.7rem;background:var(--primary-bg);color:var(--primary);border:1px solid var(--blue-200);border-radius:10px;padding:1px 6px;font-weight:600;">Returning</span>'
                : '';
            var timeLabel = appt.time ? new Date('1970-01-01T' + appt.time).toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit',hour12:true}) : '';
            var apptDate  = appt.date || date;
            var dateLabel = new Date(apptDate + 'T00:00:00').toLocaleDateString('en-PH',{month:'short',day:'2-digit',year:'numeric'});

            var tbody = document.querySelector('#appointmentsTable tbody');
            if (tbody) {
                var placeholder = tbody.querySelector('td[colspan]');
                if (placeholder) placeholder.closest('tr').remove();
                var cBtn = '<button style="display:inline-flex;align-items:center;gap:4px;padding:5px 12px;min-height:30px;border-radius:8px;background:var(--blue-50);color:var(--primary);border:1.5px solid var(--blue-200);font-size:0.75rem;font-weight:700;cursor:pointer;" onclick="openConfirmModal(' + (appt.appt_id||0) + ',\'' + (appt.appt_code||'').replace(/'/g,"\\x27") + '\',\'' + (appt.patient_name||'').replace(/'/g,"\\x27") + '\')"><i class="bi bi-check-lg"></i> Confirm</button>';
                var pBtn = '<a href="' + _baseUrl + 'modules/print/appointment_slip.php?id=' + (appt.appt_id||'') + '" style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:7px;background:var(--gray-50);color:var(--gray-500);border:1.5px solid var(--gray-200);text-decoration:none;font-size:0.8rem;"><i class="bi bi-printer"></i></a>';
                var eBtn = '<button style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:7px;background:var(--primary-bg);color:var(--primary);border:1.5px solid var(--blue-200);cursor:pointer;font-size:0.8rem;" onclick="openRescheduleModal(' + (appt.appt_id||0) + ')" title="Reschedule / Edit"><i class="bi bi-calendar-week"></i></button>';
                var dBtn = <?php echo ($current_user_role === 'admin') ? 'true' : 'false'; ?>
                    ? '<button style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:7px;background:var(--danger-bg);color:var(--danger);border:1.5px solid var(--danger-border);cursor:pointer;font-size:0.8rem;" onclick="confirmDeleteAppt(' + (appt.appt_id||0) + ',\'' + (appt.appt_code||'').replace(/\'/g,"\\x27") + '\')"><i class="bi bi-trash"></i></button>'
                    : '';
                var tr = document.createElement('tr');
                tr.style.cssText = 'background:var(--warning-bg);transition:background 3s ease;';
                tr.innerHTML =
                    '<td data-label="Code" style="padding:13px 16px;vertical-align:middle;"><span style="font-size:0.79rem;font-weight:700;color:var(--primary);font-family:monospace;">' + (appt.appt_code||'--') + '</span></td>'
                    + '<td data-label="Patient" style="padding:13px 16px;vertical-align:middle;"><div style="font-size:0.85rem;font-weight:700;color:var(--gray-900);">' + (appt.patient_name||'--') + returningBadge + '</div></td>'
                    + '<td data-label="Service" style="padding:13px 16px;vertical-align:middle;"><div style="font-size:0.82rem;color:var(--gray-600);font-weight:500;">' + (appt.service||'--') + '</div></td>'
                    + '<td data-label="Doctor" style="padding:13px 16px;vertical-align:middle;"><span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;background:linear-gradient(135deg,var(--blue-500),var(--blue-400));color:var(--white);font-size:0.71rem;font-weight:700;white-space:nowrap;"><i class="bi bi-person-badge" style="font-size:0.68rem;"></i>' + (appt.doctor_name ? appt.doctor_name : 'Any') + '</span></td>'
                    + '<td data-label="Date &amp; Time" style="padding:13px 16px;vertical-align:middle;"><div style="font-size:0.82rem;font-weight:700;color:var(--gray-700);">' + dateLabel + '</div><div style="font-size:0.73rem;color:var(--gray-400);margin-top:1px;"><i class="bi bi-clock" style="font-size:0.65rem;"></i> ' + timeLabel + '</div></td>'
                    + '<td data-label="Status" style="padding:13px 16px;vertical-align:middle;"><span style="display:inline-flex;align-items:center;gap:4px;padding:4px 11px;border-radius:20px;font-size:0.73rem;font-weight:700;background:var(--warning-bg);color:var(--warning);border:1.5px solid var(--warning-border);"><i class="bi bi-clock-history" style="font-size:0.68rem;"></i> Pending</span></td>'
                    + '<td data-label="Actions" style="padding:13px 16px;vertical-align:middle;"><div style="display:flex;gap:5px;flex-wrap:wrap;align-items:center;">' + cBtn + pBtn + eBtn + dBtn + '</div></td>';
                tbody.insertBefore(tr, tbody.firstChild);
                setTimeout(() => { tr.style.background = ''; }, 3000);
            }

            var toastTitle = isReturning ? 'Returning Patient Booked!' : 'Appointment Booked!';
            var toastMsg   = '<strong>' + (appt.patient_name||'') + '</strong> (' + (appt.patient_code||'') + ')<br>';
            toastMsg += isReturning ? 'Existing record used - no duplicate created.<br>' : '';
            toastMsg += 'Appt: <strong>' + (appt.appt_code||'') + '</strong> - ' + dateLabel + ' at <strong>' + timeLabel + '</strong>';
            if (res.duplicate_warning) {
                toastMsg += '<br><span style="color:var(--warning);font-weight:700;"><i class="bi bi-exclamation-triangle-fill"></i> ' + res.duplicate_warning + '</span>';
            }
            document.getElementById('walkinToastTitle').textContent = toastTitle;
            document.getElementById('walkinToastMsg').innerHTML     = toastMsg;
            document.getElementById('walkinToast').style.display    = 'block';
            setTimeout(function(){ document.getElementById('walkinToast').style.display='none'; }, 6000);
            form.reset();
            closeWalkinDrawer();
            setTimeout(function(){ cleanReload(); }, 2500);
        } else {
            showDrawerAlert('danger', res.message || 'Something went wrong. Please try again.');
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-person-check-fill"></i> <span>Book Appointment</span>';
        showDrawerAlert('danger', 'Network error. Please try again.');
    });
}

<?php if ($auto_open_walkin): ?>
document.addEventListener('DOMContentLoaded', function() {
    openWalkinDrawer();
    <?php if ($prefill_patient): ?>
    // Delay longer than openWalkinDrawer's internal 50ms reset, so the form
    // reset runs first — then we fill in the patient.
    setTimeout(function() {
        selectPatient(
            <?php echo (int)$prefill_patient['id']; ?>,
            <?php echo json_encode($prefill_patient['full_name']); ?>,
            <?php echo json_encode($prefill_patient['patient_code']); ?>,
            <?php echo json_encode($prefill_patient['phone'] ?? ''); ?>
        );
    }, 200);
    <?php endif; ?>
});
<?php endif; ?>
</script>
</div>
<?php include '../../includes/footer.php'; ?>
</body>
</html>
