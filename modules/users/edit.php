<?php
// Edit an existing user account (name, email, role, password).

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_admin();

$page_title = 'Edit User';

$id = secure_int($_GET['id'] ?? 0);
if (!$id) { header('Location: list.php'); exit(); }

$ue_stmt = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$ue_stmt->execute([$id]);
$user = $ue_stmt->fetch(PDO::FETCH_ASSOC);
$ue_stmt->closeCursor();
if (!$user) { header('Location: list.php'); exit(); }

$current_user_id = $current_user_id ?? ($_SESSION['user_id'] ?? 0);
$current_user_name = $current_user_name ?? ($_SESSION['full_name'] ?? 'System');

$error   = '';
$success = '';

// ── Self-healing migration: add profile_photo column if missing ──
try {
    $cols = $conn->query("SHOW COLUMNS FROM `users` LIKE 'profile_photo'")->fetchAll();
    if (empty($cols)) {
        $conn->exec("ALTER TABLE `users` ADD COLUMN `profile_photo` VARCHAR(500) DEFAULT NULL");
    }
} catch (PDOException $e) { /* already exists */ }

// ── Handle AJAX photo upload ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_photo_action'])) {
    validate_csrf();
    header('Content-Type: application/json');
    $action = $_POST['_photo_action'];

    if ($action === 'upload') {
        if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['ok' => false, 'error' => 'No file received or upload error.']);
            exit;
        }
        $file = $_FILES['photo'];
        if ($file['size'] > 3 * 1024 * 1024) {
            echo json_encode(['ok' => false, 'error' => 'Photo must be under 3 MB.']);
            exit;
        }
        $allowed_mime = ['image/jpeg','image/jpg','image/png','image/webp'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        if (!in_array($finfo->file($file['tmp_name']), $allowed_mime, true)) {
            echo json_encode(['ok' => false, 'error' => 'Only JPEG, PNG, or WebP images allowed.']);
            exit;
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp'])) $ext = 'jpg';
        $fname = 'user_' . $id . '_' . time() . '.' . $ext;
        $dir   = __DIR__ . '/../../uploads/profiles';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $dest  = $dir . '/' . $fname;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            echo json_encode(['ok' => false, 'error' => 'Failed to save file. Check uploads/profiles/ is writable.']);
            exit;
        }
        // Delete old photo
        $old_stmt = $conn->prepare("SELECT profile_photo FROM users WHERE id = ? LIMIT 1");
        $old_stmt->execute([$id]);
        $old_photo = $old_stmt->fetchColumn();
        if ($old_photo) {
            $old_path = __DIR__ . '/../../' . $old_photo;
            if (file_exists($old_path)) @unlink($old_path);
        }
        $rel = 'uploads/profiles/' . $fname;
        $conn->prepare("UPDATE users SET profile_photo = ? WHERE id = ?")->execute([$rel, $id]);
        log_action($conn, $current_user_id, $current_user_name, 'Updated User Photo', 'users', $id, $fname);
        echo json_encode(['ok' => true, 'path' => BASE_URL . $rel]);
        exit;

    } elseif ($action === 'remove') {
        $old_stmt = $conn->prepare("SELECT profile_photo FROM users WHERE id = ? LIMIT 1");
        $old_stmt->execute([$id]);
        $old_photo = $old_stmt->fetchColumn();
        if ($old_photo) {
            $old_path = __DIR__ . '/../../' . $old_photo;
            if (file_exists($old_path)) @unlink($old_path);
        }
        $conn->prepare("UPDATE users SET profile_photo = NULL WHERE id = ?")->execute([$id]);
        log_action($conn, $current_user_id, $current_user_name, 'Removed User Photo', 'users', $id, '');
        echo json_encode(['ok' => true]);
        exit;
    }
    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validate_csrf();
        $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $role      = $_POST['role'] ?? 'staff';
    $password  = trim($_POST['password'] ?? '');
    $confirm   = trim($_POST['confirm_password'] ?? '');

    // SECURITY: the role field is only locked client-side (see the form below).
    // Force it back to the existing value here so a hand-crafted POST can't
    // change your own role even if the hidden input is edited or skipped.
    if ($id === $current_user_id) {
        $role = $user['role'];
    }

    if (empty($full_name)) {
        $error = 'Full name is required.';
    } elseif (empty($email)) {
        $error = 'Email address is required.';
    } elseif (!valid_email($email)) {
        $error = 'Please enter a valid email address.';
    } elseif (empty($phone)) {
        $error = 'Phone number is required.';
    } elseif (!valid_phone($phone)) {
        $error = 'Please enter a valid phone number.';
    } elseif (!empty($password) && $password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (!empty($password) && ($pw_err = validate_password($password))) {
        $error = $pw_err;
    } else {
        if (!empty($password)) {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET full_name=?, email=?, phone=?, role=?, password=? WHERE id=?");
            
        } else {
            $stmt = $conn->prepare("UPDATE users SET full_name=?, email=?, phone=?, role=? WHERE id=?");
        }

        $params = !empty($password)
            ? [$full_name, $email, $phone, $role, $hashed, $id]
            : [$full_name, $email, $phone, $role, $id];
        if ($stmt->execute($params)) {
            log_action(
                $conn,
                $current_user_id ?? ($_SESSION['user']['id'] ?? 0),
                $current_user_name ?? ($_SESSION['user']['username'] ?? 'System'),
                'Edited User',
                'users',
                $id,
                "Updated user: {$user['username']}"
            );
            $success = 'User updated successfully.';
            $ue2_stmt = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
            $ue2_stmt->execute([$id]);
            $user = $ue2_stmt->fetch(PDO::FETCH_ASSOC);
            $ue2_stmt->closeCursor();
        } else {
            $error = 'Failed to update. Please try again.';
        }
        $stmt = null;
    }
}
?><!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?></head>
<style>
/* ── Edit User — redesigned ───────────────────────── */
.eu-wrap        { max-width: 760px; margin: 0 auto; }

/* Profile hero card */
.eu-hero {
    background: linear-gradient(135deg, #0d6e6e 0%, #095454 60%, #072040 100%);
    border-radius: 16px;
    padding: 28px 32px;
    display: flex;
    align-items: center;
    gap: 22px;
    margin-bottom: 24px;
    position: relative;
    overflow: hidden;
}
.eu-hero::before {
    content: '';
    position: absolute;
    width: 200px; height: 200px;
    background: rgba(255,255,255,0.06);
    border-radius: 50%;
    top: -60px; right: -40px;
}
.eu-avatar {
    width: 68px; height: 68px;
    border-radius: 50%;
    background: rgba(255,255,255,0.18);
    border: 3px solid rgba(255,255,255,0.35);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.6rem; font-weight: 800;
    color: #fff;
    flex-shrink: 0;
    letter-spacing: -1px;
}
.eu-hero-info { flex: 1; }
.eu-hero-name  { font-size: 1.25rem; font-weight: 700; color: #fff; margin: 0 0 4px; }
.eu-hero-meta  { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.eu-badge {
    font-size: 0.7rem; font-weight: 700; padding: 3px 10px;
    border-radius: 20px; text-transform: uppercase; letter-spacing: 0.06em;
}
.eu-badge-role   { background: rgba(255,255,255,0.2); color: #fff; }
.eu-badge-active { background: #22c55e; color: #fff; }
.eu-badge-user   { background: rgba(255,255,255,0.1); color: rgba(255,255,255,0.8); }
.eu-back-btn {
    position: absolute; top: 20px; right: 24px;
    background: rgba(255,255,255,0.15);
    border: 1px solid rgba(255,255,255,0.25);
    color: #fff;
    padding: 6px 16px;
    border-radius: 8px;
    font-size: 0.78rem;
    font-weight: 600;
    text-decoration: none;
    transition: background 0.2s;
    display: flex; align-items: center; gap: 6px;
}
.eu-back-btn:hover { background: rgba(255,255,255,0.25); color: #fff; }

/* Section cards */
.eu-section {
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 14px;
    margin-bottom: 18px;
    overflow: hidden;
}
.eu-section-header {
    padding: 16px 24px 14px;
    border-bottom: 1px solid var(--border-color, #e5e7eb);
    display: flex;
    align-items: center;
    gap: 10px;
}
.eu-section-icon {
    width: 32px; height: 32px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.9rem;
    flex-shrink: 0;
}
.eu-section-icon.blue  { background: var(--primary-bg); color: var(--primary); }
.eu-section-icon.green { background: #f0fdf4; color: #15803d; }
.eu-section-icon.amber { background: #fffbeb; color: #d97706; }
.eu-section-title { font-size: 0.9rem; font-weight: 700; color: var(--gray-800, #1f2937); margin: 0; }
.eu-section-sub   { font-size: 0.75rem; color: var(--gray-400, #9ca3af); margin: 0; }

.eu-section-body  { padding: 22px 24px; }

/* Field labels */
.eu-label {
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--gray-600, #4b5563);
    margin-bottom: 6px;
    display: block;
    letter-spacing: 0.01em;
}
.eu-label .req { color: var(--danger, #ef4444); margin-left: 2px; }

/* Readonly field pill */
.eu-readonly {
    background: var(--gray-50, #f9fafb);
    border: 1px solid var(--gray-200, #e5e7eb);
    border-radius: 8px;
    padding: 9px 14px;
    font-size: 0.875rem;
    color: var(--gray-500, #6b7280);
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Password strength bar */
#strengthBar  { height: 5px; border-radius: 4px; margin-top: 8px; background: var(--gray-100, #f3f4f6); }
#strengthFill { height: 100%; border-radius: 4px; width: 0%; transition: width .3s, background .3s; }
#strengthText { font-size: 0.72rem; margin-top: 4px; min-height: 14px; font-weight: 700; }

/* Password rules grid */
.eu-rules {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 6px;
    margin-top: 12px;
}
.eu-rules span {
    font-size: 0.72rem;
    padding: 5px 10px;
    border-radius: 6px;
    background: var(--gray-50, #f9fafb);
    border: 1px solid var(--gray-100, #f3f4f6);
    color: var(--gray-500, #6b7280);
    transition: all 0.2s;
}

/* Action footer */
.eu-footer {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 20px 24px;
    background: var(--gray-50, #f9fafb);
    border-top: 1px solid var(--border-color, #e5e7eb);
    border-radius: 0 0 14px 14px;
}
.eu-footer .btn-save {
    background: linear-gradient(135deg, var(--primary-light, #14a8a8), var(--primary));
    color: #fff;
    border: none;
    padding: 10px 28px;
    border-radius: 9px;
    font-weight: 700;
    font-size: 0.875rem;
    display: flex; align-items: center; gap: 7px;
    cursor: pointer;
    transition: opacity 0.2s, transform 0.1s;
    box-shadow: 0 2px 8px rgba(29,78,216,0.3);
}
.eu-footer .btn-save:hover  { opacity: 0.92; transform: translateY(-1px); }
.eu-footer .btn-save:active { transform: translateY(0); }
.eu-footer .btn-cancel {
    color: var(--gray-500, #6b7280);
    font-size: 0.875rem;
    font-weight: 600;
    text-decoration: none;
    padding: 10px 18px;
    border-radius: 9px;
    border: 1px solid var(--gray-200, #e5e7eb);
    background: #fff;
    transition: border-color 0.2s, color 0.2s;
}
.eu-footer .btn-cancel:hover { border-color: var(--gray-400,#9ca3af); color: var(--gray-700,#374151); }

[data-theme="dark"] .eu-section { background: var(--card-bg); }
[data-theme="dark"] .eu-readonly { background: var(--gray-150); color: var(--gray-600); }
[data-theme="dark"] .eu-footer   { background: var(--gray-900,#111827); }
[data-theme="dark"] .eu-footer .btn-cancel { background: transparent; }
[data-theme="dark"] .eu-rules span { background: var(--gray-150); border-color: var(--gray-200); }
#euAvatarCircle:hover #euAvatarOverlay { opacity: 1 !important; }
#euAvatarCircle:hover ~ #euRemoveBtn,
div:hover > #euRemoveBtn { opacity: 1 !important; pointer-events: auto !important; }
</style>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">
    <div class="eu-wrap">

        <?php if ($error): ?>
            <div class="alert alert-danger" style="border-radius:10px;margin-bottom:16px;">
                <i class="bi bi-exclamation-circle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success" style="border-radius:10px;margin-bottom:16px;">
                <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- HERO PROFILE BANNER -->
        <?php
            $initials = strtoupper(substr($user['full_name'], 0, 1));
            $words    = explode(' ', trim($user['full_name']));
            if (count($words) > 1) $initials = strtoupper($words[0][0] . end($words)[0]);
            $role_label = ucfirst($user['role'] ?? 'staff');

            // Load current profile photo
            $eu_photo_stmt = $conn->prepare("SELECT profile_photo FROM users WHERE id = ? LIMIT 1");
            $eu_photo_stmt->execute([$id]);
            $eu_photo_rel  = $eu_photo_stmt->fetchColumn() ?: '';
            $eu_has_photo  = $eu_photo_rel && file_exists(__DIR__ . '/../../' . $eu_photo_rel);
            $eu_photo_url  = $eu_has_photo ? BASE_URL . $eu_photo_rel : '';
        ?>
        <div class="eu-hero">
            <!-- Clickable avatar: shows photo or initials, click to upload -->
            <div style="position:relative;flex-shrink:0;">
                <div class="eu-avatar" id="euAvatarCircle"
                     onclick="document.getElementById('euPhotoInput').click()"
                     title="Click to upload or change profile photo"
                     style="cursor:pointer;overflow:hidden;position:relative;<?php echo $eu_has_photo ? 'background:transparent;' : ''; ?>">
                    <?php if ($eu_has_photo): ?>
                        <img id="euAvatarImg" src="<?php echo htmlspecialchars($eu_photo_url); ?>"
                             style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;" alt="">
                    <?php else: ?>
                        <img id="euAvatarImg" src="" style="display:none;width:100%;height:100%;object-fit:cover;border-radius:50%;" alt="">
                        <span id="euAvatarInitials"><?php echo htmlspecialchars($initials); ?></span>
                    <?php endif; ?>
                    <!-- Hover overlay: camera icon to upload -->
                    <div style="position:absolute;inset:0;background:rgba(0,0,0,0.45);border-radius:50%;display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity 0.18s;" id="euAvatarOverlay">
                        <i class="bi bi-camera-fill" style="color:#fff;font-size:1.1rem;"></i>
                    </div>
                </div>
                <!-- Remove button: only shown below the avatar when a photo exists, hidden by default, revealed on hover of the wrapper -->
                <button type="button" id="euRemoveBtn" onclick="euRemovePhoto(event)"
                        title="Remove photo"
                        style="display:<?php echo $eu_has_photo ? 'flex' : 'none'; ?>;position:absolute;bottom:-6px;left:50%;transform:translateX(-50%);white-space:nowrap;background:rgba(239,68,68,0.92);border:none;color:#fff;font-size:0.6rem;font-weight:700;padding:2px 8px;border-radius:20px;align-items:center;gap:3px;cursor:pointer;opacity:0;transition:opacity 0.18s;pointer-events:none;" id="euRemoveBtn">
                    <i class="bi bi-trash3"></i> Remove
                </button>
            </div>
            <input type="file" id="euPhotoInput" accept="image/jpeg,image/png,image/webp" style="display:none" onchange="euUploadPhoto(this)">
            <div class="eu-hero-info">
                <p class="eu-hero-name"><?php echo htmlspecialchars($user['full_name']); ?></p>
                <div class="eu-hero-meta">
                    <span class="eu-badge eu-badge-user">
                        <i class="bi bi-at" style="font-size:0.7rem;"></i> <?php echo htmlspecialchars($user['username']); ?>
                    </span>
                    <span class="eu-badge eu-badge-role">
                        <i class="bi bi-shield-fill" style="font-size:0.65rem;"></i> <?php echo htmlspecialchars($role_label); ?>
                    </span>
                    <?php if (!empty($user['is_active'])): ?>
                    <span class="eu-badge eu-badge-active">
                        <i class="bi bi-circle-fill" style="font-size:0.5rem;"></i> Active
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <a href="list.php" class="eu-back-btn">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>

        <form method="POST">
            <?php echo csrf_field(); ?>

            <!-- SECTION 1: Personal Info -->
            <div class="eu-section">
                <div class="eu-section-header">
                    <div class="eu-section-icon blue"><i class="bi bi-person-fill"></i></div>
                    <div>
                        <p class="eu-section-title">Personal Information</p>
                        <p class="eu-section-sub">Name, username, and system role</p>
                    </div>
                </div>
                <div class="eu-section-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="eu-label">Full Name <span class="req">*</span></label>
                            <input type="text" name="full_name" class="form-control" required
                                   value="<?php echo htmlspecialchars($user['full_name']); ?>"
                                   placeholder="Enter full name">
                        </div>
                        <div class="col-md-6">
                            <label class="eu-label">Username</label>
                            <div class="eu-readonly">
                                <i class="bi bi-at" style="color:var(--primary);font-size:1rem;"></i>
                                <?php echo htmlspecialchars($user['username']); ?>
                            </div>
                            <div style="font-size:0.72rem;color:var(--gray-400);margin-top:4px;">
                                <i class="bi bi-lock-fill" style="font-size:0.65rem;"></i> Username cannot be changed
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="eu-label">Role</label>
                            <?php if ($id === $current_user_id): ?>
                                <input type="hidden" name="role" value="<?php echo htmlspecialchars($user['role']); ?>">
                                <div class="eu-readonly">
                                    <i class="bi bi-shield-fill" style="color:var(--primary);"></i>
                                    <?php echo htmlspecialchars($role_label); ?>
                                </div>
                                <div style="font-size:0.72rem;color:var(--gray-400);margin-top:4px;">
                                    <i class="bi bi-lock-fill" style="font-size:0.65rem;"></i> Cannot change your own role
                                </div>
                            <?php else: ?>
                                <select name="role" class="form-select">
                                    <option value="staff" <?php echo $user['role'] === 'staff' ? 'selected' : ''; ?>>
                                        Staff
                                    </option>
                                    <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>
                                        Admin
                                    </option>
                                </select>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SECTION 2: Contact Details -->
            <div class="eu-section">
                <div class="eu-section-header">
                    <div class="eu-section-icon green"><i class="bi bi-envelope-fill"></i></div>
                    <div>
                        <p class="eu-section-title">Contact Details</p>
                        <p class="eu-section-sub">Email address and phone number</p>
                    </div>
                </div>
                <div class="eu-section-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="eu-label">Email Address <span class="req">*</span></label>
                            <div style="position:relative;">
                                <i class="bi bi-envelope" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--gray-400);font-size:0.9rem;"></i>
                                <input type="email" name="email" class="form-control" required
                                       style="padding-left:36px;"
                                       placeholder="user@example.com"
                                       value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <?php
                                $phone_field_name     = 'phone';
                                $phone_field_value    = $user['phone'] ?? '';
                                $phone_field_label    = 'Phone';
                                $phone_field_required = true;
                                include '../../includes/phone_input.php';
                            ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SECTION 3: Security -->
            <div class="eu-section">
                <div class="eu-section-header">
                    <div class="eu-section-icon amber"><i class="bi bi-shield-lock-fill"></i></div>
                    <div>
                        <p class="eu-section-title">Change Password</p>
                        <p class="eu-section-sub">Leave blank to keep the current password</p>
                    </div>
                </div>
                <div class="eu-section-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="eu-label">New Password</label>
                            <div style="position:relative;">
                                <i class="bi bi-lock" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--gray-400);"></i>
                                <input type="password" name="password" id="pw" class="form-control"
                                       style="padding-left:36px;"
                                       autocomplete="new-password" placeholder="Enter new password"
                                       oninput="checkStrength(this.value)">
                                <i class="bi bi-eye" id="togPw" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--gray-400);cursor:pointer;"></i>
                            </div>
                            <div id="strengthBar"><div id="strengthFill"></div></div>
                            <div id="strengthText"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="eu-label">Confirm New Password</label>
                            <div style="position:relative;">
                                <i class="bi bi-lock-fill" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--gray-400);"></i>
                                <input type="password" name="confirm_password" id="pwConf" class="form-control"
                                       style="padding-left:36px;"
                                       autocomplete="new-password"
                                       placeholder="Repeat new password"
                                       oninput="checkMatch()">
                                <i class="bi bi-eye" id="togConf" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--gray-400);cursor:pointer;"></i>
                            </div>
                            <div id="matchText" style="font-size:0.72rem;margin-top:4px;min-height:14px;font-weight:700;"></div>
                        </div>
                        <div class="col-12">
                            <div class="eu-rules">
                                <span id="rule_len">⬜ 8–18 chars</span>
                                <span id="rule_upper">⬜ uppercase (A–Z)</span>
                                <span id="rule_lower">⬜ lowercase (a–z)</span>
                                <span id="rule_num">⬜ number (0–9)</span>
                                <span id="rule_spec">⬜ special (@#$!...)</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="eu-footer">
                    <button type="submit" class="btn-save">
                        <i class="bi bi-check-circle-fill"></i> Save Changes
                    </button>
                    <a href="list.php" class="btn-cancel">Cancel</a>
                </div>
            </div>

        </form>

    </div><!-- end eu-wrap -->
    </div>
<script>
function togglePw(id, icon) {
    var i = document.getElementById(id), t = i.type === 'text';
    i.type = t ? 'password' : 'text';
    icon.className = (t ? 'bi bi-eye' : 'bi bi-eye-slash') + ' eu-eye';
    icon.style.cssText = 'position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--gray-400);cursor:pointer;';
}
document.getElementById('togPw').onclick   = function(){ togglePw('pw', this); };
document.getElementById('togConf').onclick = function(){ togglePw('pwConf', this); };

function setRule(id, ok) {
    var e = document.getElementById(id);
    e.textContent = (ok ? '✅' : '⬜') + e.textContent.slice(2);
    e.style.color      = ok ? 'var(--success)' : '';
    e.style.fontWeight = ok ? '700' : '400';
    e.style.background = ok ? 'var(--green-50,#f0fdf4)' : '';
    e.style.borderColor= ok ? '#86efac' : '';
}
function checkStrength(v) {
    var rl  = v.length >= 8 && v.length <= 18;
    var ru  = /[A-Z]/.test(v);
    var rlw = /[a-z]/.test(v);
    var rn  = /[0-9]/.test(v);
    var rs  = /[^A-Za-z0-9]/.test(v);
    setRule('rule_len',   rl);
    setRule('rule_upper', ru);
    setRule('rule_lower', rlw);
    setRule('rule_num',   rn);
    setRule('rule_spec',  rs);
    var score = [rl, ru, rlw, rn, rs].filter(Boolean).length;
    var fills = ['0%','20%','40%','60%','80%','100%'];
    var cols  = ['','#ef4444','#f97316','#eab308','#84cc16','#22c55e'];
    var labs  = ['','Weak','Fair','Moderate','Good','Strong ✓'];
    document.getElementById('strengthFill').style.width      = fills[score];
    document.getElementById('strengthFill').style.background = cols[score] || '';
    document.getElementById('strengthText').textContent      = v.length ? labs[score] : '';
    document.getElementById('strengthText').style.color      = cols[score] || '';
    checkMatch();
}
function checkMatch() {
    var pw = document.getElementById('pw').value;
    var cf = document.getElementById('pwConf').value;
    var mt = document.getElementById('matchText');
    if (!cf) { mt.textContent = ''; return; }
    mt.textContent = pw === cf ? '✓ Passwords match' : '✗ Passwords do not match';
    mt.style.color = pw === cf ? 'var(--success)' : 'var(--danger)';
}

// ── User profile photo upload ──────────────────────────────────────
const EU_CSRF    = '<?php echo generate_csrf_token(); ?>';
const EU_ACTION  = 'edit.php?id=<?php echo $id; ?>';

function euShowToast(msg, type) {
    var t = document.createElement('div');
    var c = type === 'success' ? '#22c55e' : '#ef4444';
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:99999;background:'+c+';color:#fff;padding:10px 18px;border-radius:10px;font-size:0.84rem;font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,0.2);display:flex;align-items:center;gap:8px;min-width:180px;animation:fadeIn 0.25s ease;';
    t.innerHTML = (type === 'success' ? '<i class="bi bi-check-circle-fill"></i>' : '<i class="bi bi-exclamation-circle-fill"></i>') + msg;
    document.body.appendChild(t);
    setTimeout(function(){ t.style.opacity='0'; t.style.transition='0.3s'; setTimeout(function(){ t.remove(); }, 300); }, 2800);
}

function euUploadPhoto(input) {
    var file = input.files[0];
    if (!file) return;
    var fd = new FormData();
    fd.append('_photo_action', 'upload');
    fd.append('_csrf', EU_CSRF);
    fd.append('photo', file);
    fetch(EU_ACTION, { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (!data.ok) { euShowToast(data.error || 'Upload failed.', 'error'); return; }
            var img = document.getElementById('euAvatarImg');
            var ini = document.getElementById('euAvatarInitials');
            img.src = data.path + '?t=' + Date.now();
            img.style.display = 'block';
            if (ini) ini.style.display = 'none';
            var rb = document.getElementById('euRemoveBtn');
            if (rb) rb.style.display = 'flex';
            euShowToast('Photo updated!', 'success');
        })
        .catch(function(){ euShowToast('Upload failed. Try again.', 'error'); });
    input.value = '';
}

function euRemovePhoto(e) {
    e.stopPropagation();
    if (!confirm('Remove this user\'s profile photo?')) return;
    var fd = new FormData();
    fd.append('_photo_action', 'remove');
    fd.append('_csrf', EU_CSRF);
    fetch(EU_ACTION, { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (!data.ok) { euShowToast(data.error || 'Remove failed.', 'error'); return; }
            var img = document.getElementById('euAvatarImg');
            var ini = document.getElementById('euAvatarInitials');
            if (img) { img.src = ''; img.style.display = 'none'; }
            if (ini) ini.style.display = '';
            var rb = document.getElementById('euRemoveBtn');
            if (rb) rb.style.display = 'none';
            euShowToast('Photo removed.', 'success');
        })
        .catch(function(){ euShowToast('Remove failed. Try again.', 'error'); });
}
</script>
</div>
<?php include '../../includes/footer.php'; ?>
</body>
</html>