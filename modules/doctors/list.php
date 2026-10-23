<?php
// Doctors management — profile cards, add/edit drawer, toggle active status.
// Uses PDO with MySQL

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/doctor_ptr.php';

require_admin();
$has_ptr_col = ensure_doctor_ptr_column($conn);   // adds doctors.ptr_number if missing

// Ensure current user variables are defined (auth.php should set these, but
// provide a safe fallback to avoid "undefined variable" notices).
$current_user_id   = $current_user_id ?? (int) ($_SESSION['user_id'] ?? 0);
$current_user_name = $current_user_name ?? ($_SESSION['full_name'] ?? 'Unknown');

$page_title = 'Doctors';
$error   = '';
$success = '';

// $current_user_id and $current_user_name already set by auth.php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id             = intval($_POST['doctor_id'] ?? 0);
        $full_name      = trim($_POST['full_name'] ?? '');
        $license_number = trim($_POST['license_number'] ?? '');
        $ptr_number     = trim($_POST['ptr_number'] ?? '');
        $specialization = trim($_POST['specialization'] ?? '');
        $bio            = trim($_POST['bio'] ?? '');
        $schedule_days  = trim($_POST['schedule_days'] ?? '');
        $start_time     = trim($_POST['start_time'] ?? '08:00');
        $end_time       = trim($_POST['end_time'] ?? '17:00');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $photo_url      = '';

        if (empty($full_name)) {
            $error = 'Doctor name is required.';
        } else {
            if (!empty($_FILES['photo']['name'])) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
                $ftype = mime_content_type($_FILES['photo']['tmp_name']);
                if (!in_array($ftype, $allowed_types)) {
                    $error = 'Photo must be JPG, PNG, or WebP.';
                } elseif ($_FILES['photo']['size'] > 2 * 1024 * 1024) {
                    $error = 'Photo must be under 2MB.';
                } else {
                    // SECURITY: extension comes from the *validated* MIME type, never
                    // from the client-supplied filename. Otherwise a file whose real
                    // bytes pass the image check above could still be saved with an
                    // attacker-chosen extension (e.g. photo.php) — harmless on its own,
                    // but if this folder ever gets served with PHP execution on, that's
                    // a code-execution path. $ftype is already one of the three
                    // whitelisted values at this point, so this mapping can never
                    // produce anything else.
                    $ext_by_mime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                    $ext      = $ext_by_mime[$ftype] ?? 'jpg';
                    $fname    = 'dr_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $dest_dir = __DIR__ . '/../../assets/doctors/';
                    if (!is_dir($dest_dir)) mkdir($dest_dir, 0755, true);
                    if (move_uploaded_file($_FILES['photo']['tmp_name'], $dest_dir . $fname)) {
                        $photo_url = 'assets/doctors/' . $fname;
                    }
                }
            }
            if (!$error) {
                if ($id > 0) {
                    if ($photo_url) {
                        $stmt = $conn->prepare("UPDATE doctors SET full_name=?,license_number=?,specialization=?,bio=?,photo_url=?,schedule_days=?,start_time=?,end_time=?,is_active=?,updated_at=NOW() WHERE id=?");
                        $stmt->execute([$full_name,$license_number,$specialization,$bio,$photo_url,$schedule_days,$start_time,$end_time,$is_active,$id]);
                    } else {
                        $stmt = $conn->prepare("UPDATE doctors SET full_name=?,license_number=?,specialization=?,bio=?,schedule_days=?,start_time=?,end_time=?,is_active=?,updated_at=NOW() WHERE id=?");
                        $stmt->execute([$full_name,$license_number,$specialization,$bio,$schedule_days,$start_time,$end_time,$is_active,$id]);
                    }
                    if ($has_ptr_col) {
                        $conn->prepare("UPDATE doctors SET ptr_number=? WHERE id=?")->execute([$ptr_number !== '' ? $ptr_number : null, $id]);
                    }
                    log_action($conn,$current_user_id,$current_user_name,'Updated Doctor','doctors',$id,"Updated: $full_name");
                    $success = "Doctor updated successfully.";
                } else {
                    $stmt = $conn->prepare("INSERT INTO doctors (full_name, license_number, specialization, bio, photo_url, schedule_days, start_time, end_time, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$full_name, $license_number, $specialization, $bio, $photo_url, $schedule_days, $start_time, $end_time, $is_active]);
                    $new_id = $conn->lastInsertId();
                    if ($has_ptr_col && $ptr_number !== '') {
                        $conn->prepare("UPDATE doctors SET ptr_number=? WHERE id=?")->execute([$ptr_number, $new_id]);
                    }
                    log_action($conn,$current_user_id,$current_user_name,'Added Doctor','doctors',$new_id,"Added: $full_name");
                    $success = "Dr. $full_name added successfully.";
                }
            }
        }
    }

    if ($action === 'toggle') {
        $id     = intval($_POST['doctor_id'] ?? 0);
        $active = (isset($_POST['is_active']) && $_POST['is_active']) ? 1 : 0;
        $stmt   = $conn->prepare("UPDATE doctors SET is_active=? WHERE id=?");
        $stmt->execute([$active, $id]);
        header('Location: list.php');
        exit();
    }

    if ($action === 'leave') {
        $id    = intval($_POST['doctor_id'] ?? 0);
        $start = trim($_POST['leave_start'] ?? '');
        $end   = trim($_POST['leave_end']   ?? '');
        if ($id > 0 && $start && $end) {
            $stmt = $conn->prepare("UPDATE doctors SET is_active=FALSE, leave_start=?, leave_end=? WHERE id=?");
            $stmt->execute([$start, $end, $id]);
            log_action($conn,$current_user_id,$current_user_name,'Set Doctor Leave','doctors',$id,"Leave: $start to $end");

            $dr = $conn->prepare("SELECT full_name FROM doctors WHERE id = ? LIMIT 1");
            $dr->execute([$id]);
            $drname = $dr->fetch(PDO::FETCH_ASSOC)['full_name'] ?? 'Doctor';
            notify($conn, 'system', 'Doctor On Leave',
                "$drname is on leave from " . date('M d', strtotime($start)) . " to " . date('M d, Y', strtotime($end)) . ". Appointments may need rescheduling.",
                'modules/doctors/list.php');
        }
        header('Location: list.php'); exit();
    }

    if ($action === 'reinstate') {
        $id = intval($_POST['doctor_id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE doctors SET is_active=TRUE, leave_start=NULL, leave_end=NULL WHERE id=?");
            $stmt->execute([$id]);
            log_action($conn,$current_user_id,$current_user_name,'Reinstated Doctor','doctors',$id,"Leave cancelled");
        }
        header('Location: list.php'); exit();
    }

    if ($action === 'delete') {
        $id      = intval($_POST['doctor_id'] ?? 0);
        $confirm = trim($_POST['confirm_name'] ?? '');
        $dr_stmt = $conn->prepare("SELECT full_name FROM doctors WHERE id=? LIMIT 1");
        $dr_stmt->execute([$id]);
        $dr = $dr_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$dr) {
            $error = 'Doctor not found.';
        } elseif (strtolower($confirm) !== strtolower($dr['full_name'])) {
            $error = 'Name confirmation did not match. Doctor was NOT deleted.';
        } else {
            $conn->prepare("UPDATE appointments SET doctor_id = NULL WHERE doctor_id = ?")->execute([$id]);
            $conn->prepare("DELETE FROM doctors WHERE id = ?")->execute([$id]);
            log_action($conn,$current_user_id,$current_user_name,'Deleted Doctor','doctors',$id,"Doctor removed: {$dr['full_name']}");
            $success = "Doctor removed.";
        }
    }
}

$doctors = $conn->query("SELECT * FROM doctors ORDER BY is_active DESC, full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$month_start = date('Y-m-01');
$month_end   = date('Y-m-t');
$doctor_stats = [];
$sr_stmt = $conn->prepare("
    SELECT a.doctor_id,
           COUNT(a.id) as appt_count,
           SUM(CASE WHEN a.status='completed' THEN 1 ELSE 0 END) as completed_count,
           COALESCE(SUM(b.amount_paid),0) as revenue
    FROM appointments a
    LEFT JOIN bills b ON b.appointment_id = a.id
    WHERE a.appointment_date BETWEEN ? AND ?
      AND a.doctor_id IS NOT NULL
    GROUP BY a.doctor_id
");
$sr_stmt->execute([$month_start, $month_end]);
foreach ($sr_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $doctor_stats[$row['doctor_id']] = $row;
}

$day_labels = [
    'mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed',
    'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'
];

?><!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?>
<style>
/* ── Prevent flash of unstyled content ──────────────────── */
.page-content { visibility: hidden; }
.page-content.ready { visibility: visible; }

/* ── Doctor Cards ────────────────────────────────────────── */
.doctor-card {
    border-radius: 16px;
    overflow: hidden;
    transition: box-shadow 0.2s, transform 0.2s;
    border: 1px solid var(--gray-100);
}
.doctor-card:hover { box-shadow: 0 8px 24px rgba(0,0,0,0.08); transform: translateY(-2px); }
.doctor-card--inactive { opacity: 0.65; }

.doctor-card-header {
    background: linear-gradient(135deg, var(--primary) 0%, #5a8fff 100%);
    padding: 24px 16px 16px;
    text-align: center;
    position: relative;
}
.doctor-avatar-wrap { display: inline-block; }
.doctor-avatar-img {
    width: 80px; height: 80px;
    border-radius: 50%; object-fit: cover;
    border: 3px solid rgba(255,255,255,0.7);
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}
.doctor-avatar-placeholder {
    width: 80px; height: 80px;
    border-radius: 50%;
    background: rgba(255,255,255,0.25);
    border: 3px solid rgba(255,255,255,0.5);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.8rem; font-weight: 700; color: white; margin: 0 auto;
}
.doctor-status-badge {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 0.7rem; font-weight: 600; letter-spacing: 0.05em;
    padding: 3px 10px; border-radius: 20px; margin-top: 8px;
    text-transform: uppercase;
}
.doctor-status-badge.on-duty  { background: rgba(39,174,96,0.15); color:#27ae60; border:1px solid rgba(39,174,96,0.3); }
.doctor-status-badge.on-leave { background: rgba(231,76,60,0.15);  color:#e74c3c; border:1px solid rgba(231,76,60,0.3); }
.status-dot {
    width: 7px; height: 7px; border-radius: 50%;
    background: currentColor;
    animation: pulse-dot 2s ease-in-out infinite;
}
@keyframes pulse-dot { 0%,100%{opacity:1} 50%{opacity:0.4} }

.doctor-card-body { padding: 16px; }
.doctor-name  { font-weight: 700; font-size: 0.95rem; margin-bottom: 2px; }
.doctor-spec  { font-size: 0.78rem; color: var(--primary); font-weight: 600; margin-bottom: 4px; }
.doctor-license { font-size: 0.75rem; color: var(--gray-400); margin-bottom: 6px; }
.doctor-license i { margin-right: 3px; }
.doctor-bio   { font-size: 0.78rem; color: var(--gray-500); margin-bottom: 10px; line-height: 1.5; }

/* Stats row */
.doctor-stats-row {
    display: flex; justify-content: space-between;
    background: var(--gray-50); border-radius: 8px;
    padding: 8px 10px; margin-bottom: 8px;
}
.ds-stat { text-align: center; flex: 1; }
.ds-val  { display: block; font-size: 0.9rem; font-weight: 700; color: var(--blue-600); }
.ds-lbl  { display: block; font-size: 0.65rem; color: var(--gray-400); text-transform: uppercase; letter-spacing: 0.04em; }

.doctor-schedule { background: var(--gray-50); border-radius: 8px; padding: 8px 10px; }
.schedule-time { font-size: 0.75rem; color: var(--gray-600); margin-bottom: 6px; }
.schedule-time i { margin-right: 4px; }
.doctor-days { display: flex; flex-wrap: wrap; gap: 3px; }
.doctor-day-badge {
    font-size: 0.65rem; font-weight: 600; padding: 2px 7px;
    border-radius: 4px; background: var(--gray-100); color: var(--gray-400);
}
.doctor-day-badge.active { background: var(--blue-500); color: white; }

.doctor-card-footer {
    padding: 10px 16px 14px;
    display: flex; gap: 6px; align-items: center; flex-wrap: wrap;
    border-top: 1px solid var(--gray-100);
}

/* ── Doctor Modal (replaces drawer) ─────────────────────── */
.doctor-modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.5);
    backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
    z-index: 1040;
    align-items: center; justify-content: center;
    padding: 16px;
}
.doctor-modal-overlay.open { display: flex; }
.doctor-modal-box {
    background: var(--white);
    border-radius: 16px;
    width: 100%; max-width: 540px;
    max-height: 92vh;
    display: flex; flex-direction: column;
    box-shadow: 0 24px 64px rgba(0,0,0,0.24);
    animation: doctorModalIn 0.22s cubic-bezier(.4,0,.2,1);
}
@keyframes doctorModalIn {
    from { opacity: 0; transform: translateY(18px) scale(0.97); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}
.doctor-modal-header {
    padding: 18px 22px 16px;
    border-bottom: 1px solid var(--gray-100);
    display: flex; align-items: center; justify-content: space-between;
    background: linear-gradient(135deg, var(--blue-500), var(--blue-400));
    border-radius: 16px 16px 0 0;
    flex-shrink: 0;
}
.doctor-modal-header h6 { margin: 0; font-weight: 700; font-size: 1rem; color: #fff; }
.doctor-modal-subtitle { margin: 3px 0 0; font-size: 0.75rem; color: rgba(255,255,255,0.78); }
.doctor-modal-close {
    background: rgba(255,255,255,0.18); border: none; cursor: pointer;
    font-size: 1rem; color: #fff; border-radius: 8px; padding: 5px 9px;
    transition: background 0.15s; line-height: 1;
}
.doctor-modal-close:hover { background: rgba(255,255,255,0.32); }
.doctor-modal-body { flex: 1; overflow-y: auto; padding: 22px 24px; }
[data-theme="dark"] .doctor-modal-box { background: var(--gray-200); }

/* ── Photo Upload Preview ────────────────────────────────── */
.doctor-photo-preview {
    width: 96px; height: 96px; border-radius: 50%;
    border: 2px dashed var(--gray-200);
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto; overflow: hidden; background: var(--gray-50);
}
.doctor-photo-preview img { width:100%; height:100%; object-fit:cover; }

/* ── Day Toggle Checkboxes ───────────────────────────────── */
.day-toggle-label { cursor: pointer; }
.day-toggle-label input[type=checkbox] { display: none; }
.day-toggle-btn {
    display: inline-block; padding: 5px 10px; border-radius: 6px;
    font-size: 0.78rem; font-weight: 600;
    border: 1.5px solid var(--gray-300); background: var(--gray-100); color: var(--gray-600);
    transition: all 0.15s;
}
.day-toggle-label input:checked + .day-toggle-btn {
    background: var(--primary); color: white; border-color: var(--primary);
}
</style>
</head>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content" id="pageContent">

        <div class="page-header">
            <div>
                <h5>Doctors</h5>
                <small class="text-muted"><?php echo count($doctors); ?> doctor<?php echo count($doctors) !== 1 ? 's' : ''; ?> registered</small>
            </div>
            <div class="page-header-actions">
                <button class="btn btn-primary btn-sm" onclick="openDrawer()">
                    <i class="bi bi-plus-lg"></i> Add Doctor
                </button>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <!-- Doctor Profile Cards Grid -->
        <?php if (empty($doctors)): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="bi bi-person-badge" style="font-size:3rem;color:var(--gray-300);"></i>
                    <p class="mt-3 text-muted">No doctors registered yet.</p>
                    <button class="btn btn-primary btn-sm" onclick="openDrawer()">Add First Doctor</button>
                </div>
            </div>
        <?php else: ?>
        <div class="row g-3">
            <?php foreach ($doctors as $doc): ?>
            <?php
                $days_arr = array_filter(array_map('trim', explode(',', $doc['schedule_days'] ?? '')));
                $days_html = '';
                foreach ($day_labels as $code => $lbl) {
                    $active_day = in_array($code, $days_arr);
                    $days_html .= '<span class="doctor-day-badge ' . ($active_day ? 'active' : '') . '">' . $lbl . '</span>';
                }
            ?>
            <div class="col-md-4 col-lg-3">
                <div class="card doctor-card <?php echo $doc['is_active'] ? '' : 'doctor-card--inactive'; ?>">
                    <div class="doctor-card-header">
                        <div class="doctor-avatar-wrap">
                            <?php if (!empty($doc['photo_url'])): ?>
                                <img src="<?php echo BASE_URL . htmlspecialchars($doc['photo_url']); ?>"
                                     alt="<?php echo htmlspecialchars($doc['full_name']); ?>"
                                     class="doctor-avatar-img">
                            <?php else: ?>
                                <div class="doctor-avatar-placeholder">
                                    <?php echo strtoupper(substr($doc['full_name'], 0, 2)); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="doctor-status-badge <?php echo $doc['is_active'] ? 'on-duty' : 'on-leave'; ?>">
                            <span class="status-dot"></span>
                            <?php
                            if ($doc['is_active']) {
                                echo 'On Duty';
                            } elseif (!empty($doc['leave_start']) && !empty($doc['leave_end'])) {
                                echo 'On Leave · ' . date('M d', strtotime($doc['leave_start'])) . '–' . date('M d', strtotime($doc['leave_end']));
                            } else {
                                echo 'On Leave';
                            }
                            ?>
                        </div>
                    </div>
                    <div class="doctor-card-body">
                        <h6 class="doctor-name"><?php echo htmlspecialchars($doc['full_name']); ?></h6>
                        <p class="doctor-spec"><?php echo htmlspecialchars($doc['specialization'] ?: 'General Dentist'); ?></p>
                        <?php if ($doc['license_number']): ?>
                            <p class="doctor-license"><i class="bi bi-shield-check"></i> <?php echo htmlspecialchars($doc['license_number']); ?></p>
                        <?php endif; ?>
                        <?php if ($doc['bio']): ?>
                            <p class="doctor-bio"><?php echo htmlspecialchars(substr($doc['bio'], 0, 90)) . (strlen($doc['bio']) > 90 ? '…' : ''); ?></p>
                        <?php endif; ?>

                        <?php $ds = $doctor_stats[$doc['id']] ?? ['revenue'=>0,'appt_count'=>0,'completed_count'=>0]; ?>
                        <div class="doctor-stats-row">
                            <div class="ds-stat">
                                <span class="ds-val">₱<?php echo number_format($ds['revenue'],0); ?></span>
                                <span class="ds-lbl">Revenue</span>
                            </div>
                            <div class="ds-stat">
                                <span class="ds-val"><?php echo $ds['appt_count']; ?></span>
                                <span class="ds-lbl">Appts</span>
                            </div>
                            <div class="ds-stat">
                                <span class="ds-val"><?php echo $ds['completed_count']; ?></span>
                                <span class="ds-lbl">Done</span>
                            </div>
                        </div>

                        <div class="doctor-schedule">
                            <div class="schedule-time">
                                <i class="bi bi-clock"></i>
                                <?php echo date('h:i A', strtotime($doc['start_time'])); ?> – <?php echo date('h:i A', strtotime($doc['end_time'])); ?>
                            </div>
                            <div class="doctor-days"><?php echo $days_html; ?></div>
                        </div>
                    </div>
                    <div class="doctor-card-footer">
                        <button class="btn btn-sm btn-outline-primary" onclick='editDoctor(<?php echo json_encode($doc); ?>)'>
                            <i class="bi bi-pencil"></i> Edit
                        </button>

                        <?php if ($doc['is_active']): ?>
                        <button class="btn btn-sm btn-outline-warning"
                            onclick="openLeaveModal(<?php echo $doc['id']; ?>, '<?php echo htmlspecialchars(addslashes($doc['full_name'])); ?>')">
                            <i class="bi bi-calendar-x"></i> Leave
                        </button>
                        <?php else: ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirmReinstate('<?php echo htmlspecialchars(addslashes($doc['full_name'])); ?>')">
                    <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="reinstate">
                            <input type="hidden" name="doctor_id" value="<?php echo $doc['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-success">
                                <i class="bi bi-play-circle"></i> Reinstate
                            </button>
                        </form>
                        <?php endif; ?>

                        <button class="btn btn-sm btn-outline-danger" onclick="openDeleteModal(<?php echo $doc['id']; ?>, '<?php echo htmlspecialchars(addslashes($doc['full_name'])); ?>')">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>

<!-- ── Add/Edit Doctor Modal ─────────────────────────────── -->
<div id="doctorModalOverlay" class="doctor-modal-overlay" onclick="if(event.target===this)closeDrawer()">
  <div class="doctor-modal-box">
    <div class="doctor-modal-header">
        <div>
            <h6 id="drawerTitle">Add Doctor</h6>
            <p class="doctor-modal-subtitle" id="drawerSubtitle">Fill in the doctor's profile details</p>
        </div>
        <button class="doctor-modal-close" onclick="closeDrawer()"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="doctor-modal-body">
        <form method="POST" enctype="multipart/form-data" id="doctorForm">
                    <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="doctor_id" id="f_doctor_id" value="0">

            <!-- Photo Upload -->
            <div class="mb-3 text-center">
                <div class="doctor-photo-preview" id="photoPreview">
                    <i class="bi bi-person-circle" style="font-size:4rem;color:var(--gray-300);"></i>
                </div>
                <label class="btn btn-sm btn-outline-secondary mt-2">
                    <i class="bi bi-upload"></i> Upload Photo
                    <input type="file" name="photo" id="photoInput" accept="image/*" style="display:none;" onchange="previewPhoto(this)">
                </label>
                <div class="form-text">JPG/PNG/WebP · max 2MB</div>
            </div>

            <div class="mb-3">
                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                <input type="text" name="full_name" id="f_full_name" class="form-control" placeholder="e.g. Dr. Jane Smith" required>
            </div>
            <div class="mb-3">
                <label class="form-label">License Number</label>
                <input type="text" name="license_number" id="f_license_number" class="form-control" placeholder="e.g. PRC-0001234">
            </div>
            <div class="mb-3">
                <label class="form-label">PTR Number</label>
                <input type="text" name="ptr_number" id="f_ptr_number" class="form-control" placeholder="Professional Tax Receipt no. (printed on prescriptions)">
            </div>
            <div class="mb-3">
                <label class="form-label">Specialization</label>
                <input type="text" name="specialization" id="f_specialization" class="form-control" placeholder="e.g. Orthodontist, Oral Surgeon">
            </div>
            <div class="mb-3">
                <label class="form-label">Bio / Description</label>
                <textarea name="bio" id="f_bio" class="form-control" rows="3" placeholder="Short professional bio shown to patients when booking..."></textarea>
            </div>

            <label class="form-label">Working Days</label>
            <div class="mb-3 d-flex gap-2 flex-wrap" id="dayToggles">
                <?php foreach ($day_labels as $code => $lbl): ?>
                <label class="day-toggle-label">
                    <input type="checkbox" name="days_cb[]" value="<?php echo $code; ?>" class="day-cb" checked>
                    <span class="day-toggle-btn"><?php echo $lbl; ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="schedule_days" id="f_schedule_days" value="mon,tue,wed,thu,fri,sat">

            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label">Start Time</label>
                    <input type="time" name="start_time" id="f_start_time" class="form-control" value="08:00">
                </div>
                <div class="col-6">
                    <label class="form-label">End Time</label>
                    <input type="time" name="end_time" id="f_end_time" class="form-control" value="17:00">
                </div>
            </div>

            <div class="form-check form-switch mb-4">
                <input class="form-check-input" type="checkbox" name="is_active" id="f_is_active" checked>
                <label class="form-check-label" for="f_is_active">On Duty (accepting appointments)</label>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill">Save Doctor</button>
                <button type="button" class="btn btn-outline-secondary" onclick="closeDrawer()">Cancel</button>
            </div>
        </form>
    </div><!-- /.doctor-modal-body -->
  </div><!-- /.doctor-modal-box -->
</div><!-- /#doctorModalOverlay -->

<!-- ── Delete Confirmation Modal ────────────────────────────── -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST" id="deleteForm">
                    <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="doctor_id" id="delete_doctor_id">
                <div class="modal-header" style="border-bottom:2px solid #dc2626;">
                    <h6 class="modal-title text-danger"><i class="bi bi-exclamation-triangle-fill"></i> Delete Doctor</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p style="font-size:0.85rem;color:#374151;">This action <strong>cannot be undone.</strong> The doctor will be permanently removed. Their past appointments will be kept but unlinked.</p>
                    <div class="mb-1" style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 12px;font-size:0.82rem;">
                        <span style="color:#991b1b;">Type the doctor's full name to confirm:</span><br>
                        <strong id="delete_doctor_name_label" style="color:#dc2626;"></strong>
                    </div>
                    <input type="text" name="confirm_name" id="delete_confirm_input"
                           class="form-control mt-2" placeholder="Type full name here…"
                           autocomplete="off" required>
                    <div id="delete_name_feedback" style="font-size:0.75rem;margin-top:5px;min-height:18px;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" id="delete_confirm_btn" class="btn btn-danger btn-sm" disabled>
                        <i class="bi bi-trash"></i> Yes, Delete
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Leave Date Modal ──────────────────────────────────────── -->
<div class="modal fade" id="leaveModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST" id="leaveForm" onsubmit="return validateLeaveDates()">
                    <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="leave">
                <input type="hidden" name="doctor_id" id="leave_doctor_id">
                <div class="modal-header">
                    <h6 class="modal-title"><i class="bi bi-calendar-x text-warning"></i> Set Leave</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p style="font-size:0.88rem;font-weight:600;color:#374151;" id="leave_doctor_name"></p>
                    <p style="font-size:0.78rem;color:#6b7280;margin-top:-6px;">The doctor will be marked <strong>On Leave</strong> and blocked from new appointments during this period.</p>
                    <div class="mb-3">
                        <label class="form-label">From <span class="text-danger">*</span></label>
                        <input type="date" name="leave_start" id="leave_start" class="form-control" required
                               min="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="mb-1">
                        <label class="form-label">To <span class="text-danger">*</span></label>
                        <input type="date" name="leave_end" id="leave_end" class="form-control" required
                               min="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div id="leave_date_feedback" style="font-size:0.76rem;color:#dc2626;min-height:18px;margin-top:4px;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning btn-sm">
                        <i class="bi bi-calendar-x"></i> Confirm Leave
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<script>
// ── Make content visible once styles are guaranteed loaded ────
document.addEventListener('DOMContentLoaded', function() {
    var pc = document.getElementById('pageContent');
    if (pc) pc.classList.add('ready');
});

// ── Doctor Modal ──────────────────────────────────────────────
function openDrawer() {
    document.getElementById('doctorModalOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeDrawer() {
    document.getElementById('doctorModalOverlay').classList.remove('open');
    document.body.style.overflow = '';
}
function editDoctor(doc) {
    document.getElementById('drawerTitle').textContent = 'Edit Doctor';
    document.getElementById('drawerSubtitle').textContent = 'Update this doctor\'s profile';
    document.getElementById('f_doctor_id').value       = doc.id;
    document.getElementById('f_full_name').value       = doc.full_name;
    document.getElementById('f_license_number').value  = doc.license_number || '';
    document.getElementById('f_ptr_number').value      = doc.ptr_number || '';
    document.getElementById('f_specialization').value  = doc.specialization || '';
    document.getElementById('f_bio').value             = doc.bio || '';
    document.getElementById('f_start_time').value      = (doc.start_time || '08:00:00').substring(0,5);
    document.getElementById('f_end_time').value        = (doc.end_time   || '17:00:00').substring(0,5);
    document.getElementById('f_is_active').checked     = doc.is_active == 1;
    var activeDays = (doc.schedule_days || '').split(',').map(s => s.trim());
    document.querySelectorAll('.day-cb').forEach(function(cb) {
        cb.checked = activeDays.includes(cb.value);
    });
    var preview = document.getElementById('photoPreview');
    if (doc.photo_url) {
        preview.innerHTML = '<img src="<?php echo BASE_URL; ?>' + doc.photo_url + '">';
    } else {
        preview.innerHTML = '<i class="bi bi-person-circle" style="font-size:4rem;color:var(--gray-300);"></i>';
    }
    openDrawer();
}

// ── Reinstate Confirmation ────────────────────────────────────
function confirmReinstate(name) {
    return confirm('Reinstate ' + name + '?\n\nThis will clear their leave dates and mark them as On Duty again.');
}

// ── Leave Modal ───────────────────────────────────────────────
function openLeaveModal(id, name) {
    document.getElementById('leave_doctor_id').value = id;
    document.getElementById('leave_doctor_name').textContent = name;
    document.getElementById('leave_start').value = '';
    document.getElementById('leave_end').value   = '';
    document.getElementById('leave_date_feedback').textContent = '';
    new bootstrap.Modal(document.getElementById('leaveModal')).show();
}
function validateLeaveDates() {
    var start = document.getElementById('leave_start').value;
    var end   = document.getElementById('leave_end').value;
    var fb    = document.getElementById('leave_date_feedback');
    if (!start || !end) {
        fb.textContent = 'Both dates are required.';
        return false;
    }
    if (end < start) {
        fb.textContent = 'End date cannot be before start date.';
        return false;
    }
    var startDate = new Date(start);
    var endDate   = new Date(end);
    var days = Math.round((endDate - startDate) / (1000*60*60*24)) + 1;
    return confirm('Confirm leave for ' + document.getElementById('leave_doctor_name').textContent + '?\n\nLeave period: ' + start + ' to ' + end + ' (' + days + ' day' + (days !== 1 ? 's' : '') + ').\n\nThe doctor will be blocked from new appointments during this time.');
}

// ── Delete Modal (strict name confirmation) ───────────────────
var _deleteTargetName = '';
function openDeleteModal(id, name) {
    _deleteTargetName = name;
    document.getElementById('delete_doctor_id').value = id;
    document.getElementById('delete_doctor_name_label').textContent = name;
    document.getElementById('delete_confirm_input').value = '';
    document.getElementById('delete_confirm_btn').disabled = true;
    document.getElementById('delete_name_feedback').textContent = '';
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
document.getElementById('delete_confirm_input').addEventListener('input', function() {
    var typed    = this.value.trim().toLowerCase();
    var expected = _deleteTargetName.toLowerCase();
    var btn      = document.getElementById('delete_confirm_btn');
    var fb       = document.getElementById('delete_name_feedback');
    if (typed === expected) {
        btn.disabled = false;
        fb.textContent = '✓ Name matches — you may proceed.';
        fb.style.color = '#16a34a';
    } else {
        btn.disabled = true;
        if (typed.length > 0) {
            fb.textContent = 'Name does not match yet…';
            fb.style.color = '#dc2626';
        } else {
            fb.textContent = '';
        }
    }
});

// ── Assemble schedule_days before submit ──────────────────────
document.getElementById('doctorForm').addEventListener('submit', function() {
    var checked = [];
    document.querySelectorAll('.day-cb:checked').forEach(function(cb) {
        checked.push(cb.value);
    });
    document.getElementById('f_schedule_days').value = checked.join(',');
});

// ── Photo preview ─────────────────────────────────────────────
function previewPhoto(input) {
    if (!input.files || !input.files[0]) return;
    var reader = new FileReader();
    reader.onload = function(e) {
        var preview = document.getElementById('photoPreview');
        preview.innerHTML = '<img src="' + e.target.result + '" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">';
    };
    reader.readAsDataURL(input.files[0]);
}

// ── Reset drawer on open-new ──────────────────────────────────
document.querySelectorAll('[onclick="openDrawer()"]').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('drawerTitle').textContent = 'Add Doctor';
        document.getElementById('drawerSubtitle').textContent = "Fill in the doctor's profile details";
        document.getElementById('doctorForm').reset();
        document.getElementById('f_doctor_id').value = '0';
        document.getElementById('photoPreview').innerHTML = '<i class="bi bi-person-circle" style="font-size:4rem;color:var(--gray-300);"></i>';
        document.querySelectorAll('.day-cb').forEach(function(cb) {
            cb.checked = !['sun'].includes(cb.value);
        });
    });
});
</script>
</div>
<?php include '../../includes/footer.php'; ?>
</body>
</html>