<?php
// modules/patients/list.php
// List all active patients with search, gender/blood type filters, stats bar, and age column.
// ── OOP REFACTOR: data access now handled by PatientService ──────────────────

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/PatientService.php'; // ← OOP: load the service class

$current_user_id   = $current_user_id ?? null;
$current_user_name = $current_user_name ?? 'System';

$page_title = 'Patient Records';

// Archive feature removed — patients are always active.

// ── Input sanitization ────────────────────────────────────────────────────────
$search   = trim($_GET['search'] ?? '');
$gender_f = trim($_GET['gender'] ?? '');
$blood_f  = trim($_GET['blood']  ?? '');
$per_page = 20;
$page     = max(1, intval($_GET['page'] ?? 1));
// Sort by registration date: newest first by default; ?order=oldest flips it.
$order    = (($_GET['order'] ?? '') === 'oldest') ? 'oldest' : 'newest';

// Build filter array — PatientService handles the WHERE clause internally
$filters = [
    'search'     => $search,
    'gender'     => in_array($gender_f, ['male','female','other'], true) ? $gender_f : '',
    'blood_type' => $blood_f,
];

// ── Data access via PatientService (OOP) ─────────────────────────────────────
$patientService = new PatientService($conn);  // constructor injection

$total_count = $patientService->count($filters);
$total_pages = max(1, ceil($total_count / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$patients    = $patientService->getList($filters, $per_page, $offset, $order);
$stats_row   = $patientService->getStats();
$blood_types = $patientService->getBloodTypes();

// Build filter query string for pagination links
$filter_parts = [];
if ($search)   $filter_parts[] = 'search=' . urlencode($search);
if ($gender_f) $filter_parts[] = 'gender=' . urlencode($gender_f);
if ($blood_f)  $filter_parts[] = 'blood='  . urlencode($blood_f);
// Keep the chosen sort when paging through results
if ($order === 'oldest') $filter_parts[] = 'order=oldest';
$filter_qs = $filter_parts ? implode('&', $filter_parts) . '&' : '';

// Link for the "Registered" column header: clicking flips newest <-> oldest
$sort_parts = [];
if ($search)   $sort_parts[] = 'search=' . urlencode($search);
if ($gender_f) $sort_parts[] = 'gender=' . urlencode($gender_f);
if ($blood_f)  $sort_parts[] = 'blood='  . urlencode($blood_f);
if ($order === 'newest') $sort_parts[] = 'order=oldest';   // currently newest → next click = oldest
$sort_toggle_url = 'list.php' . ($sort_parts ? '?' . implode('&', $sort_parts) : '');
?><!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?>
<style>
/* ── Stats bar ─────────────────────────────────────────── */
.stats-bar {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 18px;
}
@media (max-width: 700px) {
    .stats-bar { grid-template-columns: repeat(2, 1fr); }
}
/* On narrow phones each card is ~165px — icon+text side-by-side overflows.
   Stack icon above text so nothing overlaps the decorative bg circle. */
@media (max-width: 480px) {
    /* COMPACT TILES: icon left, label + number right → each tile is ~54px tall
       instead of ~180px, so the patient list starts much higher on the screen. */
    .stats-bar  { gap: 8px; margin-bottom: 12px; }
    .stats-bar .stat-card {
        flex-direction: row; align-items: center; gap: 8px;
        padding: 8px 10px !important; border-radius: 10px;
        min-height: 0;
    }
    .stats-bar .stat-card::after,
    .stats-bar .stat-card::before { display: none; }      /* drop the big decorative circle */
    .stats-bar .stat-icon  { width: 28px; height: 28px; font-size: 0.8rem; border-radius: 8px; }
    .stats-bar .stat-value { font-size: 1.05rem !important; line-height: 1.1; }
    .stats-bar .stat-label { font-size: 0.58rem; letter-spacing: 0.03em; line-height: 1.2; }
}
.stat-card {
    background: var(--white);
    border: var(--border);
    border-radius: 12px;
    padding: 14px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    cursor: default;
    user-select: none;
}
.stat-icon {
    width: 38px; height: 38px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem; flex-shrink: 0;
}
.stat-label { font-size: 0.72rem; font-weight: 600; color: var(--gray-400); text-transform: uppercase; letter-spacing: 0.04em; }
.stat-value { font-size: 1.35rem; font-weight: 800; color: var(--gray-800); line-height: 1.1; }

/* ── Filter pills ──────────────────────────────────────── */
.filter-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 14px;
    align-items: center;
}
.pill-label { font-size: 0.72rem; font-weight: 700; color: var(--gray-400); text-transform: uppercase; letter-spacing: 0.05em; margin-right: 2px; }
.pill {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 5px 12px;
    border-radius: 20px;
    border: 1.5px solid var(--gray-200);
    background: var(--white);
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--gray-600);
    text-decoration: none;
    cursor: pointer;
    transition: all 0.15s;
    white-space: nowrap;
}
.pill:hover { border-color: var(--primary); color: var(--primary); }
.pill.active { background: var(--primary); border-color: var(--primary); color: #fff; }

/* ── Age badge ──────────────────────────────────────────── */
.age-badge {
    display: inline-block;
    font-size: 0.72rem;
    font-weight: 700;
    color: var(--gray-500);
    background: var(--gray-100);
    border-radius: 8px;
    padding: 1px 6px;
}

/* ── PATIENT CARDS ON PHONES ─────────────────────────────
   Problem: each patient card was white-on-white inside ANOTHER white card
   (the outer .card + 12px padding from mobile.css), with a faint 1px border,
   so it blended into the page.
   Fix: remove the outer wrapper look, and give every patient its own card with
   a strong border, a teal accent edge, a tinted header (patient code) and a
   real shadow so each one clearly stands out from the background. */
@media (max-width: 600px) {
    .page-content .card.patients-list-card,
    .page-content .card.patients-list-card:hover {
        background: transparent;
        border: none;
        box-shadow: none;
        overflow: visible;
        transform: none;
    }
    .page-content .card.patients-list-card > .card-body {
        padding: 0 !important;                 /* beats mobile.css FIX 17 */
    }
    .patients-list-card .mobile-card-table-wrap { overflow: visible; }

    .patients-list-card .mobile-card-table tr {
        background: #ffffff;
        border: 1.5px solid #b4c4d6;                 /* clearly visible outline */
        border-left: 5px solid var(--primary);       /* teal accent edge */
        border-radius: 14px;
        margin-bottom: 14px;
        padding: 0;
        overflow: hidden;
        box-shadow: 0 4px 14px rgba(15, 23, 42, 0.14), 0 1px 3px rgba(15, 23, 42, 0.10);
    }
    /* Tinted header strip = patient code */
    .patients-list-card .mobile-card-table td:first-child {
        background: var(--primary-bg);
        border-bottom: 1.5px solid #b4d8d8;
        padding: 9px 14px;
    }
    .patients-list-card .mobile-card-table td {
        padding: 7px 14px;                           /* tighter rows → more patients on screen */
        min-height: 32px;
        border-bottom: 1px solid #e2eaf2;
    }
    .patients-list-card .mobile-card-table td[data-label]::before {
        color: var(--gray-600);                      /* labels were too faint */
        min-width: 78px;
    }
    .patients-list-card .mobile-card-table td:last-child {
        background: #f3f8fc;                         /* actions row sits on its own tint */
        border-top: 1px solid #d6e2ee;
    }
}

/* Dark mode */
[data-theme="dark"] .stat-card { background: #1E293B; border-color: #334155; }
[data-theme="dark"] .stat-value { color: #E2E8F0; }
[data-theme="dark"] .pill { background: #1E293B; border-color: #334155; color: #94A3B8; }
[data-theme="dark"] .pill.active { background: var(--primary); border-color: var(--primary); color: #fff; }
[data-theme="dark"] .pill:hover { border-color: var(--primary); color: #A5B4FC; }
[data-theme="dark"] .age-badge { background: #334155; color: #94A3B8; }
@media (max-width: 600px) {
    [data-theme="dark"] .patients-list-card .mobile-card-table tr {
        background: #1E293B !important;
        border-color: #3d5a80 !important;
        border-left-color: var(--primary-light) !important;
        box-shadow: 0 4px 14px rgba(0, 0, 0, 0.5);
    }
    [data-theme="dark"] .patients-list-card .mobile-card-table td:first-child { background: #0f2a2e; border-bottom-color: #1f4a4f; }
    [data-theme="dark"] .patients-list-card .mobile-card-table td { border-bottom-color: #2a3b55; }
    [data-theme="dark"] .patients-list-card .mobile-card-table td:last-child { background: #172338; border-top-color: #2a3b55; }
    [data-theme="dark"] .patients-list-card .mobile-card-table td[data-label]::before { color: #9ab8d4; }
}
</style>
</head>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">

        <div class="page-header">
            <h5>Patient Records</h5>
            <div style="display:flex;gap:8px;align-items:center;">

                <a href="add.php" class="btn btn-primary btn-sm">
                    <i class="bi bi-person-plus"></i> Add Patient
                </a>
            </div>
        </div>

        <!-- ── Stats Bar ─────────────────────────────────────────── -->
        <div class="stats-bar">
            <div class="stat-card">
                <div class="stat-icon" style="background:#EFF6FF;"><i class="bi bi-people-fill" style="color:#3B82F6;"></i></div>
                <div>
                    <div class="stat-label">Total Patients</div>
                    <div class="stat-value"><?php echo number_format($stats_row['total']); ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#F0FDF4;"><i class="bi bi-person-check-fill" style="color:#22C55E;"></i></div>
                <div>
                    <div class="stat-label">New This Month</div>
                    <div class="stat-value"><?php echo number_format($stats_row['new_this_month']); ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#EFF6FF;"><i class="bi bi-gender-male" style="color:#3B82F6;"></i></div>
                <div>
                    <div class="stat-label">Male</div>
                    <div class="stat-value"><?php echo number_format($stats_row['males']); ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#FDF4FF;"><i class="bi bi-gender-female" style="color:#A855F7;"></i></div>
                <div>
                    <div class="stat-label">Female</div>
                    <div class="stat-value"><?php echo number_format($stats_row['females']); ?></div>
                </div>
            </div>
        </div>

        <!-- ── Search + Filter Pills ─────────────────────────────── -->
        <form method="GET" class="mb-2">
            <div class="input-group" style="max-width:420px;">
                <?php if ($gender_f): ?><input type="hidden" name="gender" value="<?php echo htmlspecialchars($gender_f); ?>"><?php endif; ?>
                <?php if ($blood_f): ?><input type="hidden" name="blood" value="<?php echo htmlspecialchars($blood_f); ?>"><?php endif; ?>
                <input type="text" name="search" class="form-control" placeholder="Search by name, code, or phone..." value="<?php echo htmlspecialchars($search); ?>">
                <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
                <?php if ($search || $gender_f || $blood_f): ?>
                    <a href="list.php" class="btn btn-outline-danger">Clear</a>
                <?php endif; ?>
            </div>
        </form>

        <!-- Gender pills -->
        <div class="filter-pills">
            <span class="pill-label">Gender:</span>
            <a href="list.php?<?php echo ($search ? 'search='.urlencode($search).'&' : '').($blood_f ? 'blood='.urlencode($blood_f).'&' : ''); ?>"
               class="pill <?php echo !$gender_f ? 'active' : ''; ?>">All</a>
            <?php foreach (['male' => '♂ Male', 'female' => '♀ Female', 'other' => 'Other'] as $gv => $gl): ?>
            <a href="list.php?<?php echo ($search ? 'search='.urlencode($search).'&' : '').($blood_f ? 'blood='.urlencode($blood_f).'&' : ''); ?>gender=<?php echo $gv; ?>"
               class="pill <?php echo $gender_f === $gv ? 'active' : ''; ?>"><?php echo $gl; ?></a>
            <?php endforeach; ?>

            <?php if (!empty($blood_types)): ?>
            <span class="pill-label ms-2">Blood:</span>
            <a href="list.php?<?php echo ($search ? 'search='.urlencode($search).'&' : '').($gender_f ? 'gender='.urlencode($gender_f).'&' : ''); ?>"
               class="pill <?php echo !$blood_f ? 'active' : ''; ?>">All</a>
            <?php foreach ($blood_types as $bt): ?>
            <a href="list.php?<?php echo ($search ? 'search='.urlencode($search).'&' : '').($gender_f ? 'gender='.urlencode($gender_f).'&' : ''); ?>blood=<?php echo urlencode($bt); ?>"
               class="pill <?php echo $blood_f === $bt ? 'active' : ''; ?>"><?php echo htmlspecialchars($bt); ?></a>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($total_count > 0 || $search || $gender_f || $blood_f): ?>
        <div style="font-size:0.8rem;color:var(--gray-400);margin-bottom:8px;">
            <?php echo number_format($total_count); ?> patient<?php echo $total_count !== 1 ? 's' : ''; ?> found
            <?php if ($gender_f || $blood_f || $search): ?> <span style="color:var(--primary);">— filtered</span><?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="card patients-list-card">
            <div class="card-body p-0">
                <div class="mobile-card-table-wrap">
                    <table class="table mb-0 mobile-card-table">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Age</th>
                                <th>Gender</th>
                                <th>Phone</th>
                                <th>Visits</th>
                                <th>
                                    <a href="<?php echo htmlspecialchars($sort_toggle_url); ?>"
                                       style="color:inherit;text-decoration:none;white-space:nowrap;"
                                       title="<?php echo $order === 'newest' ? 'Newest first — click for oldest first' : 'Oldest first — click for newest first'; ?>">
                                        Registered <i class="bi <?php echo $order === 'newest' ? 'bi-sort-down' : 'bi-sort-up'; ?>"></i>
                                    </a>
                                </th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($patients)): ?>
                                <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--gray-400);">
                                    <?php echo ($search || $gender_f || $blood_f) ? 'No patients match the current filters.' : 'No patients yet.'; ?>
                                </td></tr>
                            <?php else: ?>
                                <?php foreach ($patients as $p):
                                    $age = '';
                                    if (!empty($p['date_of_birth'])) {
                                        $age = (int)floor((time() - strtotime($p['date_of_birth'])) / 31557600);
                                    }
                                ?>
                                <tr>
                                    <td data-label="Code" style="font-weight:600;color:var(--blue-500);font-size:0.8rem;"><?php echo htmlspecialchars($p['patient_code']); ?></td>
                                    <td data-label="Name" style="font-weight:500;">
                                        <?php echo htmlspecialchars(ucwords(strtolower($p['last_name'])).', '.ucwords(strtolower($p['first_name']))); ?>
                                        <?php if (!empty($p['is_incomplete'])): ?>
                                        <span class="badge bg-warning" style="font-size:0.65rem;font-weight:600;" title="Incomplete profile"><i class="bi bi-exclamation-circle"></i> Incomplete</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Age">
                                        <?php if ($age !== ''): ?>
                                        <span class="age-badge"><?php echo $age; ?> yrs</span>
                                        <?php else: ?>
                                        <span style="color:var(--gray-300);">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Gender"><?php echo ucfirst($p['gender'] ?? '—'); ?></td>
                                    <td data-label="Phone"><?php echo htmlspecialchars($p['phone'] ?? '—'); ?></td>
                                    <td data-label="Visits"><span class="badge bg-primary"><?php echo $p['total_visits']; ?></span></td>
                                    <td data-label="Registered" style="font-size:0.8rem;color:var(--gray-500);"><?php echo date('M d, Y', strtotime($p['created_at'])); ?></td>
                                    <td data-label="Actions">
                                        <div style="display:flex;gap:6px;">
                                            <a href="view.php?id=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline-info" title="View Patient">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="edit.php?id=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline-secondary" title="Edit Patient">
                                                <i class="bi bi-pencil"></i>
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
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="pagination-bar">
            <div class="pagination-info">
                Showing <?php echo number_format(($offset+1)); ?>–<?php echo number_format(min($offset+$per_page, $total_count)); ?> of <?php echo number_format($total_count); ?> patients
            </div>
            <div class="pagination-links">
                <?php if ($page > 1): ?>
                <a href="list.php?<?php echo $filter_qs; ?>page=<?php echo $page-1; ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-left"></i> Prev</a>
                <?php endif; ?>
                <?php for ($pg = max(1,$page-2); $pg <= min($total_pages,$page+2); $pg++): ?>
                <a href="list.php?<?php echo $filter_qs; ?>page=<?php echo $pg; ?>"
                   class="btn btn-sm <?php echo $pg === $page ? 'btn-primary' : 'btn-outline-secondary'; ?>"><?php echo $pg; ?></a>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                <a href="list.php?<?php echo $filter_qs; ?>page=<?php echo $page+1; ?>" class="btn btn-sm btn-outline-secondary">Next <i class="bi bi-chevron-right"></i></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>

<script>
function getModal(id){var el=document.getElementById(id);return el?bootstrap.Modal.getOrCreateInstance(el):null;}
</script>
</div>
<?php include '../../includes/footer.php'; ?>
</body>
</html>
