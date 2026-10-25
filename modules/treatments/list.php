<?php
// Dental Records are now embedded in the Patient Profile page.
// Redirect to the correct place — keep no standalone list.
require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

$patient_id = intval($_GET['patient_id'] ?? 0);
if ($patient_id) {
    header('Location: ../patients/view.php?id=' . $patient_id);
} else {
    header('Location: ../patients/list.php');
}
exit();

$service_f  = intval($_GET['service_id'] ?? 0);
$date_from  = trim($_GET['date_from'] ?? '');
$date_to    = trim($_GET['date_to'] ?? '');
$per_page   = 20;
$page       = max(1, intval($_GET['page'] ?? 1));

// Validate dates
$date_from_clean = $date_from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) ? $date_from : '';
$date_to_clean   = $date_to   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)   ? $date_to   : '';

$where  = "WHERE 1=1";
$params = [];

if ($search) {
    $like    = '%' . $search . '%';
    $where  .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.patient_code LIKE ? OR dr.treatment_done LIKE ?)";
    $params  = array_merge($params, [$like, $like, $like, $like]);
}
if ($patient_id) {
    $where  .= " AND dr.patient_id = ?";
    $params[] = $patient_id;
}
if ($service_f) {
    // match the visit if the service is ANY of its services (not only the first)
    $where  .= " AND (dr.service_id = ? OR EXISTS (SELECT 1 FROM dental_record_services drs WHERE drs.dental_record_id = dr.id AND drs.service_id = ?))";
    $params[] = $service_f;
    $params[] = $service_f;
}
if ($date_from_clean) {
    $where  .= " AND dr.visit_date >= ?";
    $params[] = $date_from_clean;
}
if ($date_to_clean) {
    $where  .= " AND dr.visit_date <= ?";
    $params[] = $date_to_clean;
}

// Load filtered patient name
$filter_patient_name = '';
if ($patient_id) {
    $fp_stmt = $conn->prepare("SELECT CONCAT(first_name,' ',last_name) as n, patient_code FROM patients WHERE id = ? LIMIT 1");
    $fp_stmt->execute([$patient_id]);
    $fp = $fp_stmt->fetch(PDO::FETCH_ASSOC);
    $fp_stmt->closeCursor();
    if ($fp) $filter_patient_name = $fp['n'] . ' (' . $fp['patient_code'] . ')';
}

// Services for filter
$all_services = $conn->query("SELECT id, service_name FROM services WHERE is_active = TRUE ORDER BY service_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// COUNT
$count_stmt = $conn->prepare("SELECT COUNT(*) as c FROM dental_records dr LEFT JOIN patients p ON dr.patient_id = p.id $where");
$count_stmt->execute($params);
$total_count = (int)$count_stmt->fetch(PDO::FETCH_ASSOC)['c'];
$count_stmt->closeCursor();

$total_pages = max(1, ceil($total_count / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$filter_parts = [];
if ($search)          $filter_parts[] = 'search='     . urlencode($search);
if ($patient_id)      $filter_parts[] = 'patient_id=' . $patient_id;
if ($service_f)       $filter_parts[] = 'service_id=' . $service_f;
if ($date_from_clean) $filter_parts[] = 'date_from='  . urlencode($date_from_clean);
if ($date_to_clean)   $filter_parts[] = 'date_to='    . urlencode($date_to_clean);
$filter_qs = $filter_parts ? implode('&', $filter_parts) . '&' : '';

$list_stmt = $conn->prepare("
    SELECT dr.*, COALESCE((SELECT GROUP_CONCAT(ms_x.service_name ORDER BY ms_x.id SEPARATOR ', ') FROM `dental_record_services` ms_x WHERE ms_x.`dental_record_id` = dr.id), s.service_name) AS service_name,
           CONCAT(p.first_name,' ',p.last_name) as patient_name,
           p.patient_code,
           CONCAT(u.full_name) as recorded_by_name,
           a.appointment_code as linked_appt_code
    FROM dental_records dr
    LEFT JOIN patients p ON dr.patient_id = p.id
    LEFT JOIN services s ON dr.service_id = s.id
    LEFT JOIN users u ON dr.recorded_by = u.id
    LEFT JOIN appointments a ON dr.appointment_id = a.id
    $where
    ORDER BY dr.visit_date DESC, p.last_name ASC
    LIMIT $per_page OFFSET $offset
");
$list_stmt->execute($params);
$records = $list_stmt->fetchAll(PDO::FETCH_ASSOC);
$list_stmt->closeCursor();

$active_filters = ($search || $patient_id || $service_f || $date_from_clean || $date_to_clean);
?><!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?>
<style>
/* ── Filter bar ─────────────────────────────────────────── */
.filter-bar {
    background: var(--white);
    border: var(--border);
    border-radius: 12px;
    padding: 14px 16px;
    margin-bottom: 16px;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: flex-end;
}
.filter-group { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 140px; }
.filter-group label { font-size: 0.72rem; font-weight: 700; color: var(--gray-400); text-transform: uppercase; letter-spacing: 0.04em; }
.filter-group .form-control, .filter-group .form-select { font-size: 0.85rem; }
.filter-actions { display: flex; gap: 6px; align-items: flex-end; }

/* Active filter chips */
.active-filters { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
.filter-chip {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 10px; border-radius: 20px;
    background: var(--primary); color: #fff;
    font-size: 0.75rem; font-weight: 600;
}
.filter-chip a { color: rgba(255,255,255,0.8); text-decoration: none; font-size: 0.8rem; }
.filter-chip a:hover { color: #fff; }

/* Dark mode */
[data-theme="dark"] .filter-bar { background: #1E293B; border-color: #334155; }
</style>
</head>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">

        <div class="page-header">
            <div>
                <h5>Dental / Treatment Records</h5>
                <?php if ($filter_patient_name): ?>
                <p style="font-size:0.82rem;color:var(--blue-500);margin:2px 0 0;">
                    <i class="bi bi-funnel-fill"></i> Filtered: <?php echo htmlspecialchars($filter_patient_name); ?>
                    <a href="list.php" style="color:var(--gray-400);margin-left:8px;font-size:0.75rem;">✕ Clear all filters</a>
                </p>
                <?php endif; ?>
            </div>
            <div class="page-header-actions">
                <?php if ($patient_id): ?>
                <a href="../patients/view.php?id=<?php echo $patient_id; ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-person"></i> Patient Profile
                </a>
                <?php endif; ?>
                <a href="add.php<?php echo $patient_id ? '?patient_id='.$patient_id : ''; ?>" class="btn btn-sm btn-success">
                    <i class="bi bi-plus"></i> Add Record
                </a>
            </div>
        </div>

        <!-- ── Filter Bar ─────────────────────────────────────── -->
        <form method="GET">
            <?php if ($patient_id): ?>
            <input type="hidden" name="patient_id" value="<?php echo $patient_id; ?>">
            <?php endif; ?>
            <div class="filter-bar">
                <div class="filter-group" style="flex:2;min-width:180px;">
                    <label>Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Name, code, or treatment..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="filter-group">
                    <label>Service</label>
                    <select name="service_id" class="form-select">
                        <option value="">All Services</option>
                        <?php foreach ($all_services as $svc): ?>
                        <option value="<?php echo $svc['id']; ?>" <?php echo $service_f === (int)$svc['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($svc['service_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Date From</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from_clean); ?>">
                </div>
                <div class="filter-group">
                    <label>Date To</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to_clean); ?>">
                </div>
                <div class="filter-actions">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-funnel"></i> Filter</button>
                    <?php if ($active_filters): ?>
                    <a href="list.php<?php echo $patient_id ? '?patient_id='.$patient_id : ''; ?>" class="btn btn-outline-danger"><i class="bi bi-x"></i> Clear</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <!-- Active filter chips -->
        <?php if ($active_filters): ?>
        <div class="active-filters">
            <?php if ($service_f): ?>
            <?php $sn = ''; foreach ($all_services as $s) { if ((int)$s['id'] === $service_f) { $sn = $s['service_name']; break; } } ?>
            <span class="filter-chip">
                <i class="bi bi-scissors"></i> <?php echo htmlspecialchars($sn); ?>
                <a href="list.php?<?php echo http_build_query(array_filter(['search'=>$search,'patient_id'=>$patient_id,'date_from'=>$date_from_clean,'date_to'=>$date_to_clean])); ?>">✕</a>
            </span>
            <?php endif; ?>
            <?php if ($date_from_clean || $date_to_clean): ?>
            <span class="filter-chip">
                <i class="bi bi-calendar-range"></i>
                <?php echo $date_from_clean ? date('M d Y', strtotime($date_from_clean)) : '…'; ?>
                → <?php echo $date_to_clean ? date('M d Y', strtotime($date_to_clean)) : 'now'; ?>
                <a href="list.php?<?php echo http_build_query(array_filter(['search'=>$search,'patient_id'=>$patient_id,'service_id'=>$service_f])); ?>">✕</a>
            </span>
            <?php endif; ?>
            <?php if ($search): ?>
            <span class="filter-chip">
                <i class="bi bi-search"></i> "<?php echo htmlspecialchars($search); ?>"
                <a href="list.php?<?php echo http_build_query(array_filter(['patient_id'=>$patient_id,'service_id'=>$service_f,'date_from'=>$date_from_clean,'date_to'=>$date_to_clean])); ?>">✕</a>
            </span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div style="font-size:0.8rem;color:var(--gray-400);margin-bottom:8px;">
            <?php echo number_format($total_count); ?> record<?php echo $total_count !== 1 ? 's' : ''; ?> found
            <?php if ($active_filters): ?><span style="color:var(--primary);">— filtered</span><?php endif; ?>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 mobile-card-table">
                        <thead>
                            <tr>
                                <th>Patient</th>
                                <th>Visit Date</th>
                                <th>Service</th>
                                <th>Tooth</th>
                                <th>Treatment Done</th>
                                <th>Recorded By</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($records)): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">
                                    <?php if ($active_filters): ?>
                                        No records match the current filters. <a href="list.php<?php echo $patient_id ? '?patient_id='.$patient_id : ''; ?>">Clear filters</a>
                                    <?php else: ?>
                                        No dental records found.
                                    <?php endif; ?>
                                </td></tr>
                            <?php else: ?>
                                <?php foreach ($records as $r): ?>
                                <tr>
                                    <td data-label="Patient">
                                        <a href="../patients/view.php?id=<?php echo $r['patient_id']; ?>"
                                           style="font-weight:600;color:var(--primary);text-decoration:none;"
                                           title="Open patient profile">
                                            <?php echo htmlspecialchars($r['patient_name']); ?>
                                        </a>
                                        <br>
                                        <small class="text-muted"><?php echo htmlspecialchars($r['patient_code']); ?></small>
                                        <?php if (!empty($r['linked_appt_code'])): ?>
                                        <br>
                                        <a href="../appointments/list.php?search=<?php echo urlencode($r['linked_appt_code']); ?>"
                                           style="font-size:0.7rem;color:var(--gray-400);text-decoration:none;"
                                           title="View linked appointment">
                                            <i class="bi bi-calendar2-check" style="font-size:0.65rem;"></i>
                                            <?php echo htmlspecialchars($r['linked_appt_code']); ?>
                                        </a>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Visit Date"><?php echo date('M d, Y', strtotime($r['visit_date'])); ?></td>
                                    <td data-label="Service">
                                        <?php if (!empty($r['service_name'])): ?>
                                        <span style="font-size:0.8rem;background:var(--gray-100);padding:2px 8px;border-radius:8px;font-weight:600;">
                                            <?php echo htmlspecialchars($r['service_name']); ?>
                                        </span>
                                        <?php else: ?>—<?php endif; ?>
                                    </td>
                                    <td data-label="Tooth"><?php echo htmlspecialchars($r['tooth_number'] ?? '—'); ?></td>
                                    <td data-label="Treatment" style="max-width:250px;">
                                        <span title="<?php echo htmlspecialchars($r['treatment_done']); ?>">
                                            <?php echo htmlspecialchars(strlen($r['treatment_done']) > 60 ? substr($r['treatment_done'], 0, 60) . '...' : $r['treatment_done']); ?>
                                        </span>
                                    </td>
                                    <td data-label="Recorded By"><?php echo htmlspecialchars($r['recorded_by_name'] ?? '—'); ?></td>
                                    <td data-label="Actions">
                                        <div style="display:flex;gap:4px;flex-wrap:wrap;">
                                            <a href="view.php?id=<?php echo $r['id']; ?>" class="btn btn-sm btn-primary" title="View full dental record">
                                                <i class="bi bi-eye"></i> View
                                            </a>
                                            <a href="../patients/view.php?id=<?php echo $r['patient_id']; ?>" class="btn btn-sm btn-outline-secondary" title="Open patient profile">
                                                <i class="bi bi-person"></i>
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
                Showing <?php echo number_format($offset+1); ?>–<?php echo number_format(min($offset+$per_page,$total_count)); ?> of <?php echo number_format($total_count); ?> records
            </div>
            <div class="pagination-links">
                <?php if ($page > 1): ?>
                <a href="list.php?<?php echo $filter_qs; ?>page=<?php echo $page-1; ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-left"></i> Prev</a>
                <?php endif; ?>
                <?php for ($pg = max(1,$page-2); $pg <= min($total_pages,$page+2); $pg++): ?>
                <a href="list.php?<?php echo $filter_qs; ?>page=<?php echo $pg; ?>"
                   class="btn btn-sm <?php echo $pg===$page ? 'btn-primary' : 'btn-outline-secondary'; ?>"><?php echo $pg; ?></a>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                <a href="list.php?<?php echo $filter_qs; ?>page=<?php echo $page+1; ?>" class="btn btn-sm btn-outline-secondary">Next <i class="bi bi-chevron-right"></i></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>
<?php include '../../includes/footer.php'; ?>
</body>
</html>
