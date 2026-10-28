<?php
// modules/appointments/list.php
// List appointments with filters. Confirm, check-in, update status, print, or delete.
// ── OOP REFACTOR: main data access now handled by AppointmentService ─────────
// ── DESIGN UPGRADE: new layout, count tabs, solid action buttons, avatars ────

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

// ── Count badges for the status tabs (same filters, status swapped) ──────────
$status_counts = [];
foreach (array_merge([''], $allowed_statuses) as $sv) {
    if ($sv === $status_filter) { $status_counts[$sv] = $total_count; continue; }
    $cf = $filters;
    $cf['status'] = $sv;
    $status_counts[$sv] = $appointmentService->count($cf);
}

// Build filter query string for pagination links
$filter_parts = [];
if ($status_filter) $filter_parts[] = 'status='    . urlencode($status_filter);
if ($date_filter)   $filter_parts[] = 'date='      . urlencode($date_filter);
if ($doctor_filter) $filter_parts[] = 'doctor_id=' . $doctor_filter;
if ($type_filter)   $filter_parts[] = 'type='      . urlencode($type_filter);
if ($search)        $filter_parts[] = 'search='    . urlencode($search);
$filter_qs = $filter_parts ? implode('&', $filter_parts) . '&' : '';

// URL builder for tabs / pills / Today — carries every active filter, overrides what you pass
$qs = function (array $override = []) use ($status_filter, $date_filter, $type_filter, $search, $doctor_filter) {
    $p = array_merge([
        'status'    => $status_filter,
        'type'      => $type_filter,
        'date'      => $date_filter,
        'search'    => $search,
        'doctor_id' => $doctor_filter ?: '',
    ], $override);
    $p = array_filter($p, fn($v) => $v !== '' && $v !== null);
    return 'list.php' . ($p ? '?' . http_build_query($p) : '');
};

$has_filters = ($search || $date_filter || $doctor_filter || $status_filter || $type_filter);
$today_str    = date('Y-m-d');
$tomorrow_str = date('Y-m-d', strtotime('+1 day'));
?><!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?>
<style>
/* ════════════════════════════════════════════════════════════════════════════
   APPOINTMENTS — design system for this page
   Uses the site's existing CSS variables so light/dark theme keeps working.
   ════════════════════════════════════════════════════════════════════════════ */

/* ── Modal backdrop ───────────────────────────────────────────────────────── */
.modal-backdrop.show {
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    background-color: rgba(0,0,0,0.55);
    opacity: 1 !important;
}

/* ── Status colour tokens (shared by tabs, pills, status picker) ──────────── */
.st-all       { --pc: var(--primary);  --pbg: var(--primary-bg); --pb: var(--blue-200); }
.st-pending   { --pc: var(--warning);  --pbg: var(--warning-bg); --pb: var(--warning-border); }
.st-confirmed { --pc: var(--primary);  --pbg: var(--primary-bg); --pb: var(--blue-200); }
.st-completed { --pc: var(--success);  --pbg: var(--success-bg); --pb: var(--success-border); }
.st-cancelled { --pc: var(--danger);   --pbg: var(--danger-bg);  --pb: var(--danger-border); }
.st-no-show   { --pc: var(--gray-500); --pbg: var(--gray-100);   --pb: var(--gray-200); }

/* ── Buttons ──────────────────────────────────────────────────────────────── */
.ap-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 7px;
    height: 40px; padding: 0 18px; border-radius: 10px;
    border: 1px solid transparent; font-size: 0.84rem; font-weight: 700; line-height: 1;
    text-decoration: none; white-space: nowrap; cursor: pointer;
    transition: background .15s, border-color .15s, box-shadow .15s, filter .15s, transform .06s;
}
.ap-btn:active { transform: translateY(1px); }
.ap-btn:focus-visible, .ap-ib:focus-visible, .ap-tab:focus-visible, .ap-pg:focus-visible {
    outline: none; box-shadow: 0 0 0 3px color-mix(in srgb, var(--primary) 32%, transparent);
}
.ap-btn--sm { height: 32px; padding: 0 13px; font-size: 0.78rem; border-radius: 8px; gap: 6px; }

.ap-btn--primary { background: var(--primary); color: #fff; box-shadow: 0 1px 2px rgba(16,24,40,.18), inset 0 1px 0 rgba(255,255,255,.16); }
.ap-btn--primary:hover { background: var(--primary-dark, var(--primary)); color: #fff; filter: brightness(.96); box-shadow: 0 4px 12px color-mix(in srgb, var(--primary) 38%, transparent); }

.ap-btn--success { background: var(--success); color: #fff; box-shadow: 0 1px 2px rgba(16,24,40,.18), inset 0 1px 0 rgba(255,255,255,.16); }
.ap-btn--success:hover { color: #fff; filter: brightness(.94); box-shadow: 0 4px 12px color-mix(in srgb, var(--success) 38%, transparent); }

.ap-btn--warning { background: var(--warning); color: #fff; box-shadow: 0 1px 2px rgba(16,24,40,.18), inset 0 1px 0 rgba(255,255,255,.16); }
.ap-btn--warning:hover { color: #fff; filter: brightness(.94); box-shadow: 0 4px 12px color-mix(in srgb, var(--warning) 38%, transparent); }

.ap-btn--danger { background: var(--danger); color: #fff; box-shadow: 0 1px 2px rgba(16,24,40,.18), inset 0 1px 0 rgba(255,255,255,.16); }
.ap-btn--danger:hover { color: #fff; filter: brightness(.94); box-shadow: 0 4px 12px color-mix(in srgb, var(--danger) 38%, transparent); }

.ap-btn--soft { background: var(--white); color: var(--gray-700); border-color: var(--gray-300); }
.ap-btn--soft:hover { background: var(--gray-50); border-color: var(--gray-400); color: var(--gray-900); }

.ap-btn--tint { background: var(--primary-bg); color: var(--primary); border-color: var(--blue-200); }
.ap-btn--tint:hover { background: var(--primary); border-color: var(--primary); color: #fff; }

.ap-btn--paid { background: var(--success-bg); color: var(--success); border-color: var(--success-border); }
.ap-btn--paid:hover { background: var(--success); border-color: var(--success); color: #fff; }

/* Icon-only buttons */
.ap-ib {
    width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center;
    border-radius: 8px; border: 1px solid var(--gray-200); background: var(--white);
    color: var(--gray-500); font-size: 0.9rem; cursor: pointer; text-decoration: none; padding: 0;
    transition: background .14s, border-color .14s, color .14s, transform .06s;
}
.ap-ib:hover { background: var(--gray-100); border-color: var(--gray-300); color: var(--gray-900); }
.ap-ib:active { transform: translateY(1px); }
.ap-ib--primary { color: var(--primary); background: var(--primary-bg); border-color: var(--blue-200); }
.ap-ib--primary:hover { background: var(--primary); border-color: var(--primary); color: #fff; }
.ap-ib--danger { color: var(--danger); background: var(--danger-bg); border-color: var(--danger-border); }
.ap-ib--danger:hover { background: var(--danger); border-color: var(--danger); color: #fff; }

/* ── Page header ──────────────────────────────────────────────────────────── */
.appt-page .ap-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
.ap-head__title { display: flex; align-items: center; gap: 14px; }
.ap-head__icon {
    width: 46px; height: 46px; border-radius: 13px; flex: 0 0 46px;
    display: inline-flex; align-items: center; justify-content: center;
    background: var(--primary); color: #fff; font-size: 1.3rem;
    box-shadow: 0 6px 16px color-mix(in srgb, var(--primary) 32%, transparent);
}
.ap-head h5 { margin: 0; font-size: 1.3rem; font-weight: 800; color: var(--gray-900); letter-spacing: -0.01em; }
.ap-head small { display: block; margin-top: 2px; font-size: 0.82rem; color: var(--gray-500); }
.ap-head__actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }

/* ── Status tabs ──────────────────────────────────────────────────────────── */
.ap-tabs { display: flex; gap: 8px; margin-bottom: 16px; overflow-x: auto; padding: 2px 2px 8px; -webkit-overflow-scrolling: touch; scrollbar-width: none; }
.ap-tabs::-webkit-scrollbar { display: none; }
.ap-tab {
    display: inline-flex; align-items: center; gap: 8px; flex-shrink: 0;
    padding: 7px 7px 7px 14px; border-radius: 999px;
    border: 1px solid var(--gray-200); background: var(--white); color: var(--gray-600);
    font-size: 0.83rem; font-weight: 700; text-decoration: none; white-space: nowrap;
    transition: background .15s, border-color .15s, color .15s, box-shadow .15s;
}
.ap-tab > i { font-size: 0.92rem; color: var(--pc); }
.ap-tab__n {
    min-width: 26px; height: 24px; padding: 0 8px; border-radius: 999px;
    display: inline-flex; align-items: center; justify-content: center;
    background: var(--gray-100); color: var(--gray-600);
    font-size: 0.74rem; font-weight: 800; font-variant-numeric: tabular-nums;
}
.ap-tab:hover { border-color: var(--pc); background: var(--pbg); color: var(--pc); }
.ap-tab.is-active {
    background: var(--pc); border-color: var(--pc); color: #fff;
    box-shadow: 0 6px 16px color-mix(in srgb, var(--pc) 36%, transparent);
}
.ap-tab.is-active > i { color: #fff; }
.ap-tab.is-active .ap-tab__n { background: rgba(255,255,255,.24); color: #fff; }

/* ── Toolbar (type switch + filters) ──────────────────────────────────────── */
.ap-toolbar { background: var(--white); border: var(--border); border-radius: 14px; padding: 14px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(16,24,40,.05); }
.ap-seg { display: inline-flex; gap: 2px; padding: 3px; border-radius: 11px; background: var(--gray-100); margin-bottom: 12px; max-width: 100%; overflow-x: auto; scrollbar-width: none; }
.ap-seg::-webkit-scrollbar { display: none; }
.ap-seg a {
    display: inline-flex; align-items: center; gap: 6px; flex-shrink: 0;
    padding: 7px 14px; border-radius: 9px; font-size: 0.8rem; font-weight: 700;
    color: var(--gray-500); text-decoration: none; transition: background .15s, color .15s;
}
.ap-seg a:hover { color: var(--gray-900); }
.ap-seg a.is-active { background: var(--white); color: var(--primary); box-shadow: 0 1px 3px rgba(0,0,0,.14); }

.ap-filter { display: grid; grid-template-columns: minmax(220px, 1.5fr) minmax(250px, 1.1fr) minmax(170px, .8fr) auto; gap: 10px; align-items: center; }
.ap-field { position: relative; min-width: 0; }
.ap-field > i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--gray-400); font-size: 0.9rem; pointer-events: none; }
.ap-input {
    width: 100%; height: 40px; padding: 0 12px 0 36px; border: 1px solid var(--gray-300); border-radius: 10px;
    background: var(--white); color: var(--gray-900); font-size: 0.85rem; outline: none;
    transition: border-color .15s, box-shadow .15s;
}
select.ap-input { padding-left: 12px; color: var(--gray-700); }
.ap-input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px color-mix(in srgb, var(--primary) 20%, transparent); }
.ap-input::placeholder { color: var(--gray-400); }
.ap-date-row { display: flex; gap: 8px; align-items: center; }
.ap-date-row .ap-field { flex: 1; }
.ap-filter__btns { display: flex; gap: 8px; }
[data-theme="dark"] .ap-input { background: var(--gray-200); color: var(--gray-900); border-color: var(--gray-300); }
[data-theme="dark"] .ap-input::placeholder { color: var(--gray-500); }

/* ── Table card ───────────────────────────────────────────────────────────── */
.ap-card { background: var(--white); border: var(--border); border-radius: 14px; overflow: hidden; box-shadow: 0 1px 3px rgba(16,24,40,.06); }
.ap-table { width: 100%; border-collapse: separate; border-spacing: 0; }

@media (min-width: 769px) {
    .ap-table thead th {
        padding: 12px 16px; text-align: left; white-space: nowrap;
        font-size: 0.76rem; font-weight: 700; color: var(--gray-500);
        background: var(--gray-50); border-bottom: 1px solid var(--gray-200);
    }
    .ap-table td { padding: 14px 16px; vertical-align: middle; border-bottom: 1px solid var(--gray-100); }
    .ap-table tbody tr:last-child td { border-bottom: 0; }
    .ap-row.is-today td:first-child { box-shadow: inset 4px 0 0 var(--primary); }
}
.ap-row { transition: background .12s; }
.ap-row:hover { background: var(--gray-50); }
.ap-row.is-today { background: color-mix(in srgb, var(--primary) 5%, var(--white)); }
.ap-row.is-today:hover { background: color-mix(in srgb, var(--primary) 9%, var(--white)); }
.ap-row.is-new { animation: apFlash 2.6s ease-out; }
@keyframes apFlash { from { background: var(--warning-bg); } to { background: transparent; } }
@keyframes slideToast { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
@media (prefers-reduced-motion: reduce) { .ap-row.is-new { animation: none; } }

/* Code */
.ap-code {
    display: inline-block; padding: 4px 9px; border-radius: 7px;
    background: var(--primary-bg); color: var(--primary);
    font: 700 0.76rem/1.2 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

/* Patient */
.ap-pt { display: flex; align-items: center; gap: 11px; min-width: 0; }
.ap-pt__txt { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; min-width: 0; }
.ap-pt__name { font-size: 0.9rem; font-weight: 700; color: var(--gray-900); text-decoration: none; line-height: 1.2; }
a.ap-pt__name:hover { color: var(--primary); }
.ap-av {
    width: 38px; height: 38px; flex: 0 0 38px; border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 0.8rem; font-weight: 800;
}
.ap-av--0 { background: rgba(37,99,235,.13);  color: #2563eb; }
.ap-av--1 { background: rgba(13,148,136,.14); color: #0d9488; }
.ap-av--2 { background: rgba(124,58,237,.13); color: #7c3aed; }
.ap-av--3 { background: rgba(217,119,6,.15);  color: #d97706; }
.ap-av--4 { background: rgba(225,29,72,.12);  color: #e11d48; }
.ap-av--5 { background: rgba(22,163,74,.14);  color: #16a34a; }
.ap-type { display: inline-flex; align-items: center; gap: 4px; padding: 1px 8px; border-radius: 999px; font-size: 0.68rem; font-weight: 700; }
.ap-type i { font-size: 0.66rem; }
.ap-type--walkin { background: rgba(37,99,235,.11); color: #2563eb; }
.ap-type--booked { background: var(--primary-bg); color: var(--primary); }

/* Service / Doctor */
.ap-svc { font-size: 0.84rem; font-weight: 600; color: var(--gray-700); max-width: 230px; line-height: 1.35; }
.ap-doc {
    display: inline-flex; align-items: center; gap: 7px; padding: 4px 11px 4px 4px; border-radius: 999px;
    background: var(--gray-100); color: var(--gray-700); font-size: 0.79rem; font-weight: 700; white-space: nowrap;
}
.ap-doc i { width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; background: var(--primary); color: #fff; font-size: 0.72rem; }
.ap-doc--none { padding: 5px 12px; background: transparent; border: 1px dashed var(--gray-300); color: var(--gray-400); font-weight: 600; }

/* Date & time */
.ap-when { display: flex; align-items: center; gap: 11px; }
.ap-cal { width: 44px; flex: 0 0 44px; border-radius: 10px; overflow: hidden; border: 1px solid var(--gray-200); background: var(--white); text-align: center; line-height: 1; }
.ap-cal__m { display: block; padding: 4px 0; background: var(--primary); color: #fff; font-size: 0.68rem; font-weight: 800; }
.ap-cal__d { display: block; padding: 5px 0 6px; font-size: 1.1rem; font-weight: 800; color: var(--gray-900); }
.ap-when__time { font-size: 0.88rem; font-weight: 800; color: var(--gray-900); font-variant-numeric: tabular-nums; display: flex; align-items: center; gap: 6px; }
.ap-when__date { margin-top: 2px; font-size: 0.75rem; color: var(--gray-500); }
.ap-tag { padding: 1px 8px; border-radius: 999px; background: var(--primary); color: #fff; font-size: 0.66rem; font-weight: 800; }
.ap-tag--soft { background: var(--primary-bg); color: var(--primary); }

/* Status pill */
.ap-pill {
    display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 999px; white-space: nowrap;
    font-size: 0.77rem; font-weight: 700;
    background: var(--pbg, var(--gray-100)); color: var(--pc, var(--gray-500)); border: 1px solid var(--pb, var(--gray-200));
}
.ap-pill i { font-size: 0.82rem; }

/* Actions */
.ap-td-actions { min-width: 330px; white-space: nowrap; }
.ap-actions { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.ap-actions__main { display: flex; align-items: center; gap: 6px; }
.ap-actions__tools { display: flex; align-items: center; gap: 6px; margin-left: auto; }

/* Empty state */
.ap-empty { padding: 56px 20px; text-align: center; }
.ap-empty__ic { width: 68px; height: 68px; border-radius: 50%; margin-bottom: 14px; display: inline-flex; align-items: center; justify-content: center; background: var(--primary-bg); color: var(--primary); font-size: 1.9rem; }
.ap-empty h6 { margin: 0 0 4px; font-size: 1rem; font-weight: 800; color: var(--gray-900); }
.ap-empty p { margin: 0 0 18px; font-size: 0.85rem; color: var(--gray-500); }
.ap-empty__btns { display: inline-flex; gap: 8px; flex-wrap: wrap; justify-content: center; }

/* Pagination */
.ap-pager { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-top: 16px; padding: 10px 14px; background: var(--white); border: var(--border); border-radius: 14px; box-shadow: 0 1px 3px rgba(16,24,40,.05); }
.ap-pager__info { font-size: 0.8rem; color: var(--gray-500); font-weight: 600; }
.ap-pager__info strong { color: var(--gray-900); }
.ap-pager__nav { display: flex; gap: 6px; align-items: center; }
.ap-pg {
    min-width: 36px; height: 36px; padding: 0 12px; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center; gap: 5px;
    border: 1px solid var(--gray-200); background: var(--white); color: var(--gray-600);
    font-size: 0.82rem; font-weight: 700; text-decoration: none; transition: background .14s, border-color .14s;
}
.ap-pg:hover { background: var(--gray-100); border-color: var(--gray-300); color: var(--gray-900); }
.ap-pg.is-active { background: var(--primary); border-color: var(--primary); color: #fff; box-shadow: 0 4px 10px color-mix(in srgb, var(--primary) 32%, transparent); }

/* ── Modals ───────────────────────────────────────────────────────────────── */
.ap-modal .modal-content { border: 0; border-radius: 16px; box-shadow: 0 24px 60px rgba(15,23,42,.3); overflow: hidden; }
.ap-modal .modal-header { padding: 16px 22px; border-bottom: 1px solid var(--gray-200); }
.ap-modal .modal-title { display: flex; align-items: center; gap: 9px; font-size: 1rem; font-weight: 800; }
.ap-modal .modal-footer { padding: 12px 22px; border-top: 1px solid var(--gray-200); background: var(--gray-50); gap: 8px; }
.ap-modal .form-control, .ap-modal .form-select { border-radius: 10px; border: 1px solid var(--gray-300); font-size: 0.86rem; }
.ap-modal .form-control:focus, .ap-modal .form-select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px color-mix(in srgb, var(--primary) 20%, transparent); }
.ap-modal .form-label { font-weight: 700; font-size: 0.82rem; color: var(--gray-700); }

/* Small centred dialogs (confirm / delete) */
.ap-dialog { padding: 28px 24px 8px; text-align: center; }
.ap-dialog__ic { width: 58px; height: 58px; border-radius: 50%; margin-bottom: 14px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.6rem; }
.ap-dialog__ic--primary { background: var(--primary-bg); color: var(--primary); }
.ap-dialog__ic--danger  { background: var(--danger-bg);  color: var(--danger); }
.ap-dialog__ic--warning { background: var(--warning-bg); color: var(--warning); }
.ap-dialog h6 { margin: 0 0 6px; font-size: 1.05rem; font-weight: 800; color: var(--gray-900); }
.ap-dialog p { margin: 0; font-size: 0.86rem; color: var(--gray-600); line-height: 1.5; }
.ap-note { margin-top: 14px; padding: 10px 12px; border-radius: 10px; font-size: 0.78rem; text-align: left; line-height: 1.45; }
.ap-note--primary { background: var(--blue-50); color: var(--primary); border: 1px solid var(--blue-200); }
.ap-note--danger  { background: var(--danger-bg); color: var(--danger); border: 1px solid var(--danger-border); }
.ap-note--warning { background: var(--warning-bg); color: var(--warning); border: 1px solid var(--warning-border); }
.ap-modal .modal-footer.ap-split > .ap-btn { flex: 1; }

/* Status picker */
.ap-pick { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
.ap-pick button {
    display: flex; align-items: center; gap: 9px; padding: 12px; border-radius: 11px; text-align: left; cursor: pointer;
    border: 1.5px solid var(--gray-200); background: var(--white); color: var(--gray-700);
    font-size: 0.86rem; font-weight: 700; transition: border-color .14s, background .14s, box-shadow .14s;
}
.ap-pick button i { font-size: 1rem; color: var(--pc); }
.ap-pick button:hover { border-color: var(--pc); }
.ap-pick button.is-selected { border-color: var(--pc); background: var(--pbg); color: var(--pc); box-shadow: 0 0 0 3px color-mix(in srgb, var(--pc) 18%, transparent); }

/* Selected-patient chip in New Appointment modal */
.ap-selected-patient { margin-top: 7px; background: var(--blue-50); border: 1px solid var(--blue-200); border-radius: 10px; padding: 9px 12px; font-size: 0.82rem; align-items: center; gap: 8px; }

/* ── Responsive ───────────────────────────────────────────────────────────── */
@media (max-width: 1180px) {
    .ap-filter { grid-template-columns: 1fr 1fr; }
    .ap-filter__btns { grid-column: 1 / -1; }
    .ap-filter__btns > * { flex: 1; }
}
@media (max-width: 768px) {
    .ap-head__actions { width: 100%; }
    .ap-head__actions .ap-btn { flex: 1; }
    .ap-filter { grid-template-columns: 1fr; }
    .ap-seg { display: flex; }
    .ap-seg a { flex: 1; justify-content: center; }
    .ap-row.is-today { box-shadow: inset 4px 0 0 var(--primary); }
    .ap-td-actions { min-width: 0; white-space: normal; }
    .ap-actions { flex-wrap: wrap; }
    .ap-svc { max-width: none; }
    .ap-pager { justify-content: center; }
}
</style>
</head>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content appt-page">

        <!-- ── Page Header ─────────────────────────────────────── -->
        <div class="ap-head">
            <div class="ap-head__title">
                <span class="ap-head__icon"><i class="bi bi-calendar2-week"></i></span>
                <div>
                    <h5>Appointments</h5>
                    <small><?php echo number_format($total_count); ?> total record<?php echo $total_count !== 1 ? 's' : ''; ?></small>
                </div>
            </div>
            <div class="ap-head__actions">
                <a href="<?php echo BASE_URL; ?>modules/walkin/add.php" class="ap-btn ap-btn--soft">
                    <i class="bi bi-person-walking"></i> Walk-in
                </a>
                <button type="button" onclick="openWalkinDrawer()" class="ap-btn ap-btn--primary">
                    <i class="bi bi-plus-lg"></i> New appointment
                </button>
            </div>
        </div>

        <!-- ── Status Tabs (with live counts) ───────────────────── -->
        <nav class="ap-tabs" aria-label="Filter by status">
            <?php
            $tab_statuses = [
                ''          => ['All',       'bi-grid-3x3-gap',   'all'],
                'pending'   => ['Pending',   'bi-clock-history',  'pending'],
                'confirmed' => ['Confirmed', 'bi-check-circle',   'confirmed'],
                'completed' => ['Completed', 'bi-check2-all',     'completed'],
                'cancelled' => ['Cancelled', 'bi-x-circle',       'cancelled'],
                'no-show'   => ['No-show',   'bi-person-dash',    'no-show'],
            ];
            foreach ($tab_statuses as $val => $tab):
                $isActive = ($status_filter === $val);
            ?>
            <a href="<?php echo htmlspecialchars($qs(['status' => $val])); ?>" class="ap-tab st-<?php echo $tab[2]; ?><?php echo $isActive ? ' is-active' : ''; ?>"<?php echo $isActive ? ' aria-current="page"' : ''; ?>>
                <i class="bi <?php echo $tab[1]; ?>"></i>
                <?php echo $tab[0]; ?>
                <span class="ap-tab__n"><?php echo number_format($status_counts[$val] ?? 0); ?></span>
            </a>
            <?php endforeach; ?>
        </nav>

<button type="button" class="phone-filter-btn" onclick="document.getElementById('apptFilters').classList.toggle('open')">
    <i class="bi bi-funnel"></i> Search &amp; Filters
</button>
<div class="appt-filters<?php echo ($search || $date_filter || $doctor_filter || $type_filter) ? ' open' : ''; ?>" id="apptFilters">

        <div class="ap-toolbar">
            <!-- ── Type switch ──────────────────────────────────── -->
            <div class="ap-seg" role="group" aria-label="Filter by type">
                <?php
                $type_opts = [
                    ''          => ['All types',       'bi-grid'],
                    'walk-in'   => ['Walk-in',         'bi-person-walking'],
                    'scheduled' => ['Advance booking', 'bi-calendar-check'],
                ];
                foreach ($type_opts as $tval => $topt):
                    $tActive = ($type_filter === $tval);
                ?>
                <a href="<?php echo htmlspecialchars($qs(['type' => $tval])); ?>" class="<?php echo $tActive ? 'is-active' : ''; ?>">
                    <i class="bi <?php echo $topt[1]; ?>"></i> <?php echo $topt[0]; ?>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- ── Filter form ──────────────────────────────────── -->
            <form method="GET" class="ap-filter">
                <?php if ($status_filter): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>"><?php endif; ?>
                <?php if ($type_filter): ?><input type="hidden" name="type" value="<?php echo htmlspecialchars($type_filter); ?>"><?php endif; ?>

                <div class="ap-field">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" class="ap-input" placeholder="Search patient or code&#x2026;" value="<?php echo htmlspecialchars($search); ?>">
                </div>

                <div class="ap-date-row">
                    <div class="ap-field">
                        <i class="bi bi-calendar3"></i>
                        <input type="date" name="date" class="ap-input" value="<?php echo htmlspecialchars($date_filter); ?>">
                    </div>
                    <?php $today_active = ($date_filter === $today_str); ?>
                    <a href="<?php echo htmlspecialchars($qs(['date' => $today_str])); ?>" class="ap-btn <?php echo $today_active ? 'ap-btn--tint' : 'ap-btn--soft'; ?>">
                        <?php echo $today_active ? '<i class="bi bi-check-lg"></i> Today' : 'Today'; ?>
                    </a>
                </div>

                <?php if (!empty($all_doctors)): ?>
                <div class="ap-field">
                    <select name="doctor_id" class="ap-input">
                        <option value="">All doctors</option>
                        <?php foreach ($all_doctors as $d): ?>
                        <option value="<?php echo $d['id']; ?>" <?php echo $doctor_filter == $d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="ap-filter__btns">
                    <button type="submit" class="ap-btn ap-btn--primary"><i class="bi bi-funnel-fill"></i> Filter</button>
                    <?php if ($has_filters): ?>
                    <a href="list.php" class="ap-btn ap-btn--soft"><i class="bi bi-x-lg"></i> Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

</div><!-- /#apptFilters -->

        <!-- ── Table ────────────────────────────────────────────── -->
        <div class="ap-card mobile-card-table-wrap">
            <div class="table-responsive" style="overflow-x:auto;">
                <table class="ap-table mobile-card-table" id="appointmentsTable">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Patient</th>
                            <th>Service</th>
                            <th>Doctor</th>
                            <th>Date &amp; time</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($appointments)): ?>
                        <tr>
                            <td colspan="7">
                                <div class="ap-empty">
                                    <span class="ap-empty__ic"><i class="bi bi-calendar-x"></i></span>
                                    <h6>No appointments found</h6>
                                    <p><?php echo $has_filters ? 'Nothing matches these filters. Clear them or try a different search.' : 'There are no appointments yet. Book the first one.'; ?></p>
                                    <div class="ap-empty__btns">
                                        <?php if ($has_filters): ?>
                                        <a href="list.php" class="ap-btn ap-btn--soft"><i class="bi bi-x-lg"></i> Clear filters</a>
                                        <?php endif; ?>
                                        <button type="button" onclick="openWalkinDrawer()" class="ap-btn ap-btn--primary"><i class="bi bi-plus-lg"></i> New appointment</button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($appointments as $a):
                            $isToday    = ($a['appointment_date'] === $today_str);
                            $isTomorrow = ($a['appointment_date'] === $tomorrow_str);
                            $ts         = strtotime($a['appointment_date']);
                            $pname      = ucwords(strtolower($a['patient_name'] ?? ''));
                            $nparts     = preg_split('/\s+/', trim($pname));
                            $initials   = strtoupper(mb_substr($nparts[0] ?? '', 0, 1) . (count($nparts) > 1 ? mb_substr(end($nparts), 0, 1) : ''));
                            if ($initials === '') $initials = '?';
                            $avTone     = abs(crc32(strtolower($pname))) % 6;
                            $sCfg = match($a['status']) {
                                'pending'   => ['icon' => 'bi-clock-history',     'label' => 'Pending'],
                                'confirmed' => ['icon' => 'bi-check-circle-fill', 'label' => 'Confirmed'],
                                'completed' => ['icon' => 'bi-check2-all',        'label' => 'Completed'],
                                'cancelled' => ['icon' => 'bi-x-circle-fill',     'label' => 'Cancelled'],
                                'no-show'   => ['icon' => 'bi-person-dash',       'label' => 'No-show'],
                                default     => ['icon' => 'bi-circle',            'label' => ucfirst($a['status'])],
                            };
                        ?>
                        <tr class="ap-row<?php echo $isToday ? ' is-today' : ''; ?>" data-appt-id="<?php echo $a['id']; ?>">
                            <!-- Code -->
                            <td data-label="Code">
                                <span class="ap-code"><?php echo htmlspecialchars($a['appointment_code']); ?></span>
                            </td>
                            <!-- Patient -->
                            <td data-label="Patient">
                                <div class="ap-pt">
                                    <span class="ap-av ap-av--<?php echo $avTone; ?>" aria-hidden="true"><?php echo htmlspecialchars($initials); ?></span>
                                    <div class="ap-pt__txt">
                                        <a class="ap-pt__name" href="<?php echo BASE_URL; ?>modules/patients/view.php?id=<?php echo $a['patient_id']; ?>"><?php echo htmlspecialchars($pname); ?></a>
                                        <?php if (($a['type'] ?? 'walk-in') === 'walk-in'): ?>
                                        <span class="ap-type ap-type--walkin"><i class="bi bi-person-walking"></i>Walk-in</span>
                                        <?php else: ?>
                                        <span class="ap-type ap-type--booked"><i class="bi bi-calendar-check"></i>Booked</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <!-- Service -->
                            <td data-label="Service">
                                <div class="ap-svc"><?php echo htmlspecialchars($a['service_name'] ?? '—'); ?></div>
                            </td>
                            <!-- Doctor -->
                            <td data-label="Doctor">
                                <?php if (!empty($a['doctor_name'])): ?>
                                <span class="ap-doc"><i class="bi bi-person-badge"></i><?php echo htmlspecialchars($a['doctor_name']); ?></span>
                                <?php else: ?>
                                <span class="ap-doc ap-doc--none">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <!-- Date & Time -->
                            <td data-label="Date &amp; Time">
                                <div class="ap-when">
                                    <span class="ap-cal"><span class="ap-cal__m"><?php echo date('M', $ts); ?></span><span class="ap-cal__d"><?php echo date('j', $ts); ?></span></span>
                                    <div>
                                        <div class="ap-when__time">
                                            <?php echo date('h:i A', strtotime($a['appointment_time'])); ?>
                                            <?php if ($isToday): ?><span class="ap-tag">Today</span>
                                            <?php elseif ($isTomorrow): ?><span class="ap-tag ap-tag--soft">Tomorrow</span><?php endif; ?>
                                        </div>
                                        <div class="ap-when__date"><?php echo date('D, M d, Y', $ts); ?></div>
                                    </div>
                                </div>
                            </td>
                            <!-- Status -->
                            <td data-label="Status">
                                <span id="appt-status-badge-<?php echo $a['id']; ?>" data-status="<?php echo htmlspecialchars($a['status']); ?>" class="ap-pill st-<?php echo htmlspecialchars($a['status']); ?>"><i class="bi <?php echo $sCfg['icon']; ?>"></i> <?php echo htmlspecialchars($sCfg['label']); ?></span>
                            </td>
                            <!-- Actions -->
                            <td data-label="Actions" class="ap-td-actions">
                                <div class="ap-actions">
                                    <div class="ap-actions__main">
                                        <?php if ($a['status'] === 'pending'): ?>
                                        <button type="button" class="ap-btn ap-btn--sm ap-btn--primary"
                                            data-code="<?php echo htmlspecialchars($a['appointment_code'], ENT_QUOTES); ?>"
                                            data-patient="<?php echo htmlspecialchars($a['patient_name'] ?? '', ENT_QUOTES); ?>"
                                            onclick="openConfirmModal(<?php echo (int)$a['id']; ?>, this.dataset.code, this.dataset.patient)">
                                            <i class="bi bi-check-lg"></i> Confirm
                                        </button>
                                        <?php elseif ($a['status'] === 'confirmed'): ?>
                                        <a class="ap-btn ap-btn--sm ap-btn--success" href="<?php echo BASE_URL; ?>modules/treatments/add.php?patient_id=<?php echo $a['patient_id']; ?>&appointment_id=<?php echo $a['id']; ?>">
                                            <i class="bi bi-person-check-fill"></i> Check-in
                                        </a>
                                        <?php elseif ($a['status'] === 'completed'): ?>
                                        <a class="ap-btn ap-btn--sm ap-btn--soft" href="<?php echo BASE_URL; ?>modules/patients/view.php?id=<?php echo $a['patient_id']; ?>">
                                            <i class="bi bi-clipboard2-check"></i> Record
                                        </a>
                                        <?php if (!empty($a['bill_id'])): ?>
                                        <a class="ap-btn ap-btn--sm <?php echo $a['bill_status'] === 'paid' ? 'ap-btn--paid' : 'ap-btn--warning'; ?>" href="<?php echo BASE_URL; ?>modules/billing/view.php?id=<?php echo $a['bill_id']; ?>">
                                            <i class="bi <?php echo $a['bill_status'] === 'paid' ? 'bi-patch-check-fill' : 'bi-receipt'; ?>"></i> <?php echo $a['bill_status'] === 'paid' ? 'Paid' : 'Bill'; ?>
                                        </a>
                                        <?php else: ?>
                                        <a class="ap-btn ap-btn--sm ap-btn--primary" href="<?php echo BASE_URL; ?>modules/billing/create.php?patient_id=<?php echo $a['patient_id']; ?>&appointment_id=<?php echo $a['id']; ?>">
                                            <i class="bi bi-receipt"></i> Create bill
                                        </a>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="ap-actions__tools">
                                        <a class="ap-ib" href="<?php echo BASE_URL; ?>modules/print/appointment_slip.php?id=<?php echo $a['id']; ?>" title="Print slip" aria-label="Print appointment slip">
                                            <i class="bi bi-printer" aria-hidden="true"></i>
                                        </a>
                                        <button type="button" class="ap-ib ap-ib--primary" onclick="openRescheduleModal(<?php echo (int)$a['id']; ?>)" title="Reschedule / edit" aria-label="Reschedule appointment">
                                            <i class="bi bi-calendar-week" aria-hidden="true"></i>
                                        </button>
                                        <button type="button" class="ap-ib" onclick="updateStatus(<?php echo (int)$a['id']; ?>, this)" title="Update status" aria-label="Update appointment status">
                                            <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                        </button>
                                        <?php if ($current_user_role === 'admin'): ?>
                                        <button type="button" class="ap-ib ap-ib--danger"
                                            data-code="<?php echo htmlspecialchars($a['appointment_code'], ENT_QUOTES); ?>"
                                            onclick="confirmDeleteAppt(<?php echo (int)$a['id']; ?>, this.dataset.code)" title="Delete" aria-label="Delete appointment">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
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
        <div class="ap-pager">
            <div class="ap-pager__info">
                Showing <strong><?php echo number_format($offset + 1); ?>–<?php echo number_format(min($offset + $per_page, $total_count)); ?></strong>
                of <strong><?php echo number_format($total_count); ?></strong> appointments
            </div>
            <div class="ap-pager__nav">
                <?php if ($page > 1): ?>
                <a class="ap-pg" href="list.php?<?php echo $filter_qs; ?>page=<?php echo $page - 1; ?>"><i class="bi bi-chevron-left"></i> Prev</a>
                <?php endif; ?>
                <?php for ($pg = max(1, $page - 2); $pg <= min($total_pages, $page + 2); $pg++): ?>
                <a class="ap-pg<?php echo $pg === $page ? ' is-active' : ''; ?>" href="list.php?<?php echo $filter_qs; ?>page=<?php echo $pg; ?>"><?php echo $pg; ?></a>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                <a class="ap-pg" href="list.php?<?php echo $filter_qs; ?>page=<?php echo $page + 1; ?>">Next <i class="bi bi-chevron-right"></i></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>

<!-- Status Update Modal -->
<div class="modal fade ap-modal" id="statusModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="bi bi-pencil-square" style="color:var(--primary);"></i> Update status</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding:20px 22px;">
                <input type="hidden" id="appt_id">
                <div class="ap-pick" id="statusPick">
                    <button type="button" class="st-pending"   data-val="pending"   onclick="pickStatus('pending')"><i class="bi bi-clock-history"></i> Pending</button>
                    <button type="button" class="st-confirmed" data-val="confirmed" onclick="pickStatus('confirmed')"><i class="bi bi-check-circle-fill"></i> Confirmed</button>
                    <button type="button" class="st-completed" data-val="completed" onclick="pickStatus('completed')"><i class="bi bi-check2-all"></i> Completed</button>
                    <button type="button" class="st-cancelled" data-val="cancelled" onclick="pickStatus('cancelled')"><i class="bi bi-x-circle-fill"></i> Cancelled</button>
                    <button type="button" class="st-no-show"   data-val="no-show"   onclick="pickStatus('no-show')"><i class="bi bi-person-dash"></i> No-show</button>
                </div>
                <select id="new_status" class="d-none" aria-hidden="true" tabindex="-1">
                    <option value="pending">Pending</option>
                    <option value="confirmed">Confirmed</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                    <option value="no-show">No-show</option>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="ap-btn ap-btn--soft" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="ap-btn ap-btn--primary" onclick="saveStatus()"><i class="bi bi-check-lg"></i> Save status</button>
            </div>
        </div>
    </div>
</div>

<!-- Confirm Appointment Modal -->
<div class="modal fade ap-modal" id="confirmApptModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-body ap-dialog">
                <span class="ap-dialog__ic ap-dialog__ic--primary"><i class="bi bi-check-circle-fill"></i></span>
                <h6>Confirm appointment?</h6>
                <p>Confirm <strong id="confirmApptCode"></strong> for <strong id="confirmApptPatient"></strong>.</p>
                <div class="ap-note ap-note--primary">
                    <i class="bi bi-info-circle-fill"></i> This marks the appointment as <strong>Confirmed</strong> and notifies the patient.
                </div>
                <div id="confirmApptError" class="ap-note ap-note--danger" style="display:none;">
                    <i class="bi bi-exclamation-circle-fill"></i> <span></span>
                </div>
            </div>
            <div class="modal-footer ap-split" style="margin-top:16px;">
                <button type="button" class="ap-btn ap-btn--soft" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="doConfirmBtn" class="ap-btn ap-btn--primary" onclick="doConfirmAppt()">Yes, Confirm</button>
            </div>
        </div>
    </div>
</div>

<!-- No Dental Record Warning Modal -->
<div class="modal fade ap-modal" id="noRecordModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-body ap-dialog">
                <span class="ap-dialog__ic ap-dialog__ic--warning"><i class="bi bi-exclamation-triangle-fill"></i></span>
                <h6>No treatment record yet</h6>
                <p>This appointment has <strong>no dental record</strong> attached. Record the treatment first, or mark it completed anyway?</p>
                <div class="ap-note ap-note--warning">
                    <i class="bi bi-info-circle-fill"></i> Skipping this may leave the patient's clinical history incomplete.
                </div>
            </div>
            <div class="modal-footer" style="flex-direction:column;align-items:stretch;margin-top:16px;">
                <button type="button" class="ap-btn ap-btn--primary" onclick="goToRecordTreatment()">
                    <i class="bi bi-clipboard2-pulse"></i> Go to record treatment
                </button>
                <button type="button" class="ap-btn ap-btn--soft" onclick="completeAnyway()">
                    <i class="bi bi-check2-all"></i> Complete anyway
                </button>
                <button type="button" class="ap-btn ap-btn--sm ap-btn--soft" style="border-color:transparent;background:transparent;" data-bs-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Appointment Modal -->
<div class="modal fade ap-modal" id="deleteApptModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-body ap-dialog">
                <span class="ap-dialog__ic ap-dialog__ic--danger"><i class="bi bi-trash3-fill"></i></span>
                <h6>Delete appointment?</h6>
                <p>Permanently delete <strong id="deleteApptCode"></strong>?</p>
                <div class="ap-note ap-note--danger">
                    <i class="bi bi-exclamation-triangle-fill"></i> <strong>This cannot be undone.</strong> Any bill or payments stay in Billing; they just won't be linked to an appointment anymore.
                </div>
            </div>
            <div class="modal-footer ap-split" style="margin-top:16px;">
                <button type="button" class="ap-btn ap-btn--soft" data-bs-dismiss="modal">No, keep it</button>
                <button type="button" class="ap-btn ap-btn--danger" onclick="doDeleteAppt()"><i class="bi bi-trash"></i> Yes, delete it</button>
            </div>
        </div>
    </div>
</div>

<!-- ── Reschedule / Edit Appointment Modal ─────────────────── -->
<div class="modal fade ap-modal" id="rescheduleModal" tabindex="-1" aria-labelledby="rescheduleModalLabel">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" id="rescheduleModalLabel">
                    <i class="bi bi-calendar-week" style="color:var(--primary);"></i>
                    Reschedule / edit appointment
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding:20px 22px;">
                <div id="rescheduleAlert" style="display:none;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:0.83rem;"></div>

                <!-- Patient info (readonly) -->
                <div style="display:flex;align-items:center;gap:12px;padding:12px 14px;background:var(--gray-50);border:1px solid var(--gray-200);border-radius:12px;margin-bottom:18px;">
                    <span class="ap-av ap-av--0" aria-hidden="true"><i class="bi bi-person-fill"></i></span>
                    <div>
                        <div id="rsPatientName" style="font-weight:800;font-size:0.92rem;color:var(--gray-900);"></div>
                        <div id="rsApptCode"   style="font-size:0.74rem;color:var(--gray-500);font-family:monospace;"></div>
                    </div>
                    <span id="rsStatusBadge" style="margin-left:auto;font-size:0.74rem;font-weight:700;padding:4px 11px;border-radius:20px;"></span>
                </div>

                <input type="hidden" id="rs_appt_id">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">New date <span style="color:var(--danger);">*</span></label>
                        <input type="date" id="rs_date" class="form-control" min="<?php echo date('Y-m-d'); ?>" onchange="rsLoadSlots()">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">New time <span style="color:var(--danger);">*</span></label>
                        <select id="rs_time" class="form-select">
                            <option value="">Select date first…</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Services</label>
                        <?php
                        $svc_list = sc_get('svc_walkin') ?? sc_set('svc_walkin',
                            $conn->query("SELECT id, service_name, price FROM services WHERE is_active = TRUE ORDER BY service_name ASC")
                                 ->fetchAll(PDO::FETCH_ASSOC), 300);
                        echo ms_checkbox_list($svc_list, [], ['prefix' => 'rs_svc']);
                        ?>
                        <div style="font-size:0.72rem;color:var(--gray-500);margin-top:5px;">Tick every service for this appointment. Leave all unticked to keep the current services.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Doctor</label>
                        <select id="rs_doctor" class="form-select" onchange="rsLoadSlots()">
                            <option value="">Any available doctor</option>
                            <?php foreach ($all_doctors as $d): ?>
                            <option value="<?php echo $d['id']; ?>"><?php echo e($d['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Notes / chief complaint</label>
                        <textarea id="rs_notes" class="form-control" rows="2" placeholder="Update notes if needed…"></textarea>
                    </div>
                </div>

                <!-- Slot status notice -->
                <div id="rsSlotNotice" style="display:none;margin-top:12px;padding:9px 12px;border-radius:9px;font-size:0.8rem;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="ap-btn ap-btn--soft" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="ap-btn ap-btn--primary" id="rsSubmitBtn" onclick="doReschedule()">
                    <i class="bi bi-calendar-check"></i> Save changes
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
function _esc(s){var d=document.createElement('div');d.textContent=(s==null?'':String(s));return d.innerHTML;}
function _initials(n){var p=String(n||'').trim().split(/\s+/);return ((p[0]||'').charAt(0)+(p.length>1?p[p.length-1].charAt(0):'')).toUpperCase()||'?';}

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
            // Services (tick every service this appointment has)
            var svcIds = (a.service_ids && a.service_ids.length ? a.service_ids : (a.service_id ? [a.service_id] : [])).map(String);
            document.querySelectorAll('.ms-box[data-prefix="rs_svc"] input[type=checkbox]').forEach(function (cb) {
                cb.checked = svcIds.indexOf(String(cb.value)) !== -1;
                if (typeof msRecalc === 'function') msRecalc(cb);
            });
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
            notice.style.cssText = 'display:block;background:var(--danger-bg);color:var(--danger);border:1px solid var(--danger-border);margin-top:12px;padding:9px 12px;border-radius:9px;font-size:0.8rem;';
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
            notice.style.cssText = 'display:block;background:var(--success-bg);color:var(--success);border:1px solid rgba(21,128,61,0.2);margin-top:12px;padding:9px 12px;border-radius:9px;font-size:0.8rem;';
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
    var serviceIds = Array.prototype.map.call(
        document.querySelectorAll('.ms-box[data-prefix="rs_svc"] input[type=checkbox]:checked'),
        function (cb) { return parseInt(cb.value); });
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
            service_ids: serviceIds,
            doctor_id:  doctor  ? parseInt(doctor)  : null,
            notes: notes,
            _csrf: getCsrfToken(),
        }),
    })
    .then(r => r.json())
    .then(function (res) {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-calendar-check"></i> Save changes';
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
        btn.innerHTML = '<i class="bi bi-calendar-check"></i> Save changes';
        rsShowAlert('danger', 'Network error. Please try again.');
    });
}
var deleteApptId    = null;

/* ── Status picker ───────────────────────────────────────── */
function pickStatus(val) {
    document.getElementById('new_status').value = val;
    document.querySelectorAll('#statusPick button').forEach(function (b) {
        b.classList.toggle('is-selected', b.getAttribute('data-val') === val);
    });
}

function updateStatus(id, btn) {
    document.getElementById('appt_id').value = id;
    var badge = document.getElementById('appt-status-badge-' + id);
    pickStatus(badge && badge.dataset.status ? badge.dataset.status : 'pending');
    getModal('statusModal').show();
}

function getCsrfToken() {
    var el = document.querySelector('input[name="_csrf"]');
    return el ? el.value : '';
}

// Status badge config for optimistic update (mirrors the PHP status config)
var _statusCfg = {
    pending:   { icon:'bi-clock-history',     label:'Pending'   },
    confirmed: { icon:'bi-check-circle-fill', label:'Confirmed' },
    completed: { icon:'bi-check2-all',        label:'Completed' },
    cancelled: { icon:'bi-x-circle-fill',     label:'Cancelled' },
    'no-show': { icon:'bi-person-dash',       label:'No-show'   }
};

function _applyStatusBadge(badge, status) {
    var cfg = _statusCfg[status];
    if (!cfg || !badge) return;
    badge.dataset.status = status;
    badge.className = 'ap-pill st-' + status;
    badge.innerHTML = '<i class="bi ' + cfg.icon + '"></i> ' + cfg.label;
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
    var errEl = document.getElementById('confirmApptError');
    if (errEl) errEl.style.display = 'none';
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
<div class="modal fade ap-modal" id="apptModal" tabindex="-1" aria-labelledby="apptModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">

      <!-- Header -->
      <div class="modal-header" style="padding:18px 24px;border-bottom:var(--border);background:linear-gradient(135deg,var(--blue-500),var(--blue-400));">
        <div>
          <h5 class="modal-title" id="apptModalLabel" style="color:#fff;font-weight:800;font-size:1.05rem;margin:0;"><i class="bi bi-calendar2-plus"></i> New appointment</h5>
          <p id="drawerSubtitle" style="color:rgba(255,255,255,0.85);font-size:0.78rem;margin:2px 0 0;">Schedule a future appointment for a patient</p>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <!-- Slot info bar -->
      <div id="drawerSlotBar" style="background:var(--primary-bg);border-bottom:var(--border);padding:9px 24px;font-size:0.82rem;display:flex;align-items:center;gap:8px;">
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
                    <label class="form-label">Appointment date <span style="color:var(--danger)">*</span></label>
                    <input type="date" name="appointment_date" id="drawerDate" class="form-control" value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
                    <div style="font-size:0.72rem;color:var(--gray-500);margin-top:4px;">Advance booking — choose a future date.</div>
                </div>

                <!-- Patient search -->
                <div class="col-12">
                    <label class="form-label">Patient search</label>
                    <div style="position:relative;">
                        <input type="text" id="drawerPatientSearch" class="form-control"
                            placeholder="Search by name or phone to find returning patient…"
                            autocomplete="off" oninput="searchPatient(this.value)">
                        <div id="drawerPatientResults" style="display:none;position:absolute;left:0;right:0;top:100%;z-index:500;background:var(--white);border:var(--border);border-top:none;border-radius:0 0 10px 10px;box-shadow:0 6px 20px rgba(0,0,0,0.1);max-height:200px;overflow-y:auto;"></div>
                    </div>
                    <div id="drawerPatientSelected" class="ap-selected-patient" style="display:none;">
                        <i class="bi bi-person-check-fill" style="color:var(--blue-500);"></i>
                        <span id="drawerPatientSelectedName" style="font-weight:600;flex:1;"></span>
                        <button type="button" onclick="clearPatientSelection()" style="background:none;border:none;color:var(--gray-500);cursor:pointer;font-size:0.75rem;">✕ New patient</button>
                    </div>
                    <div style="margin-top:8px;display:flex;align-items:center;gap:8px;">
                        <span style="font-size:0.74rem;color:var(--gray-500);">New patient?</span>
                        <button type="button" id="drawerNewPatientBtn" onclick="toggleNewPatientFields()" class="ap-btn ap-btn--sm ap-btn--soft">
                            <i class="bi bi-person-plus"></i> Enter name manually
                        </button>
                    </div>
                </div>

                <!-- Quick-entry new patient (hidden until "Enter name manually" is clicked) -->
                <div class="col-12" id="drawerNewPatientHeading" style="display:none;margin-top:4px;">
                    <div style="font-size:0.8rem;font-weight:700;color:var(--gray-700);">New patient — quick entry</div>
                    <div style="font-size:0.72rem;color:var(--gray-500);">Creates a basic patient record now. Add full details (date of birth, address, etc.) later in Patient Records.</div>
                </div>
                <div class="col-6" id="drawerFirstNameWrap" style="display:none;">
                    <label class="form-label">First name <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="first_name" id="drawerFirstName" class="form-control" placeholder="e.g. Juan">
                </div>
                <div class="col-6" id="drawerLastNameWrap" style="display:none;">
                    <label class="form-label">Last name <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="last_name" id="drawerLastName" class="form-control" placeholder="e.g. dela Cruz">
                </div>
                <div class="col-12" id="drawerPhoneWrap" style="display:none;">
                    <label class="form-label">Phone <span style="font-size:0.72rem;color:var(--gray-400);font-weight:400;">(optional)</span></label>
                    <input type="text" name="phone" id="drawerPhone" class="form-control" placeholder="09XXXXXXXXX" maxlength="13">
                </div>

                <div class="col-12">
                    <label class="form-label">Services <span style="font-size:0.72rem;color:var(--gray-400);font-weight:400;">(tick all that apply)</span></label>
                    <?php echo ms_checkbox_list($walkin_services, [], ['prefix' => 'drw_svc']); ?>
                </div>

                <div class="col-12">
                    <label class="form-label">Doctor <span style="font-size:0.72rem;color:var(--gray-400);font-weight:400;">(optional)</span></label>
                    <select name="doctor_id" id="drawerDoctorSelect" class="form-select">
                        <option value="">Any available doctor</option>
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
                    <div id="drawerDoctorNote" style="font-size:0.72rem;color:var(--gray-500);margin-top:3px;">Showing doctors available today.</div>
                </div>

                <!-- Time slot picker -->
                <div class="col-12" id="drawerSlotPickerWrap">
                    <label class="form-label">Appointment time <span style="color:var(--danger)">*</span></label>
                    <select name="selected_time" id="drawerSlotSelect" class="form-select" required>
                        <option value="">— Loading slots… —</option>
                    </select>
                    <div id="drawerSlotNote" style="font-size:0.72rem;color:var(--gray-500);margin-top:3px;"></div>
                </div>

                <div class="col-12">
                    <label class="form-label">Notes <span style="font-size:0.72rem;color:var(--gray-400);font-weight:400;">(optional)</span></label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="Chief complaint or remarks…" maxlength="500"></textarea>
                </div>
            </div>
        </form>
      </div><!-- /.modal-body -->

      <div class="modal-footer">
        <button type="button" class="ap-btn ap-btn--soft" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="ap-btn ap-btn--success" id="walkinSubmitBtn" onclick="submitWalkin()" style="min-width:170px;">
            <i class="bi bi-person-check-fill"></i> <span id="walkinBtnLabel">Book appointment</span>
        </button>
      </div>
    </div><!-- /.modal-content -->
  </div><!-- /.modal-dialog -->
</div><!-- /#apptModal -->

<div id="walkinToast" style="display:none;position:fixed;bottom:28px;right:28px;z-index:2000;background:var(--white);border:1.5px solid var(--success-border);border-radius:14px;padding:14px 20px;box-shadow:0 12px 32px rgba(0,0,0,0.16);max-width:360px;animation:slideToast 0.3s ease;">
    <div style="display:flex;align-items:flex-start;gap:10px;">
        <span style="font-size:1.4rem;">✅</span>
        <div style="flex:1;">
            <div style="font-weight:800;margin-bottom:4px;color:var(--success);" id="walkinToastTitle"></div>
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
        var lbl = document.getElementById('walkinBtnLabel');
        if (lbl) lbl.textContent = 'Book appointment';
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
        btn.innerHTML = '<i class="bi bi-x-lg"></i> Hide name fields';
        btn.classList.remove('ap-btn--soft');
        btn.classList.add('ap-btn--danger');
    } else {
        btn.innerHTML = '<i class="bi bi-person-plus"></i> Enter name manually';
        btn.classList.remove('ap-btn--danger');
        btn.classList.add('ap-btn--soft');
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
        docSelect.innerHTML = '<option value="">Any available doctor</option>';
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
    var btnIdle    = '<i class="bi bi-person-check-fill"></i> <span id="walkinBtnLabel">Book appointment</span>';

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
        btn.innerHTML = btnIdle;
        if (res.status === 'success') {
            var appt           = res.appt || {};
            var isReturning    = !!res.is_returning;
            var timeLabel = appt.time ? new Date('1970-01-01T' + appt.time).toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit',hour12:true}) : '';
            var apptDate  = appt.date || date;
            var d         = new Date(apptDate + 'T00:00:00');
            var dateLabel = d.toLocaleDateString('en-PH',{month:'short',day:'2-digit',year:'numeric'});

            var tbody = document.querySelector('#appointmentsTable tbody');
            if (tbody) {
                var placeholder = tbody.querySelector('td[colspan]');
                if (placeholder) placeholder.closest('tr').remove();

                var pName   = appt.patient_name || '--';
                var tone    = 0; for (var i = 0; i < pName.length; i++) tone += pName.charCodeAt(i); tone = tone % 6;
                var isAdmin = <?php echo ($current_user_role === 'admin') ? 'true' : 'false'; ?>;
                var apptId  = appt.appt_id || 0;

                var tr = document.createElement('tr');
                tr.className = 'ap-row is-new';
                tr.setAttribute('data-appt-id', apptId);
                tr.innerHTML =
                    '<td data-label="Code"><span class="ap-code">' + _esc(appt.appt_code || '--') + '</span></td>'
                    + '<td data-label="Patient"><div class="ap-pt"><span class="ap-av ap-av--' + tone + '" aria-hidden="true">' + _esc(_initials(pName)) + '</span>'
                    +   '<div class="ap-pt__txt"><span class="ap-pt__name">' + _esc(pName) + '</span>'
                    +   '<span class="ap-type ap-type--booked"><i class="bi bi-calendar-check"></i>Booked</span>'
                    +   (isReturning ? '<span class="ap-type ap-type--walkin"><i class="bi bi-arrow-repeat"></i>Returning</span>' : '')
                    +   '</div></div></td>'
                    + '<td data-label="Service"><div class="ap-svc">' + _esc(appt.service || '--') + '</div></td>'
                    + '<td data-label="Doctor">' + (appt.doctor_name
                        ? '<span class="ap-doc"><i class="bi bi-person-badge"></i>' + _esc(appt.doctor_name) + '</span>'
                        : '<span class="ap-doc ap-doc--none">Unassigned</span>') + '</td>'
                    + '<td data-label="Date &amp; Time"><div class="ap-when"><span class="ap-cal"><span class="ap-cal__m">' + _esc(d.toLocaleDateString('en-US',{month:'short'})) + '</span><span class="ap-cal__d">' + d.getDate() + '</span></span>'
                    +   '<div><div class="ap-when__time">' + _esc(timeLabel) + '</div><div class="ap-when__date">' + _esc(d.toLocaleDateString('en-US',{weekday:'short',month:'short',day:'2-digit',year:'numeric'})) + '</div></div></div></td>'
                    + '<td data-label="Status"><span id="appt-status-badge-' + apptId + '" data-status="pending" class="ap-pill st-pending"><i class="bi bi-clock-history"></i> Pending</span></td>'
                    + '<td data-label="Actions" class="ap-td-actions"><div class="ap-actions">'
                    +   '<div class="ap-actions__main"><button type="button" class="ap-btn ap-btn--sm ap-btn--primary" data-act="confirm"><i class="bi bi-check-lg"></i> Confirm</button></div>'
                    +   '<div class="ap-actions__tools">'
                    +     '<a class="ap-ib" href="' + _baseUrl + 'modules/print/appointment_slip.php?id=' + apptId + '" title="Print slip" aria-label="Print appointment slip"><i class="bi bi-printer"></i></a>'
                    +     '<button type="button" class="ap-ib ap-ib--primary" data-act="reschedule" title="Reschedule / edit" aria-label="Reschedule appointment"><i class="bi bi-calendar-week"></i></button>'
                    +     '<button type="button" class="ap-ib" data-act="status" title="Update status" aria-label="Update appointment status"><i class="bi bi-pencil-square"></i></button>'
                    +     (isAdmin ? '<button type="button" class="ap-ib ap-ib--danger" data-act="delete" title="Delete" aria-label="Delete appointment"><i class="bi bi-trash"></i></button>' : '')
                    +   '</div></div></td>';

                tr.querySelector('[data-act="confirm"]').addEventListener('click', function(){ openConfirmModal(apptId, appt.appt_code || '', appt.patient_name || ''); });
                tr.querySelector('[data-act="reschedule"]').addEventListener('click', function(){ openRescheduleModal(apptId); });
                tr.querySelector('[data-act="status"]').addEventListener('click', function(){ updateStatus(apptId, this); });
                var delBtn = tr.querySelector('[data-act="delete"]');
                if (delBtn) delBtn.addEventListener('click', function(){ confirmDeleteAppt(apptId, appt.appt_code || ''); });

                tbody.insertBefore(tr, tbody.firstChild);
            }

            var toastTitle = isReturning ? 'Returning Patient Booked!' : 'Appointment Booked!';
            var toastMsg   = '<strong>' + _esc(appt.patient_name||'') + '</strong> (' + _esc(appt.patient_code||'') + ')<br>';
            toastMsg += isReturning ? 'Existing record used - no duplicate created.<br>' : '';
            toastMsg += 'Appt: <strong>' + _esc(appt.appt_code||'') + '</strong> - ' + dateLabel + ' at <strong>' + timeLabel + '</strong>';
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
        btn.innerHTML = btnIdle;
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
