<?php
// Clinic Settings — admin-only. Controls the clinic name, subtitle, contact info,
// and logo that appear on every printed slip, receipt, certificate, and document.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/clinic_settings.php';
require_admin();

$current_user_id   = $current_user_id ?? ($_SESSION['user_id'] ?? 0);
$current_user_name = $current_user_name ?? ($_SESSION['full_name'] ?? 'System');

$page_title = 'Clinic Settings';
$error      = '';
$success    = '';

// ── Logo upload helpers ────────────────────────────────────────────────────────
function cs_allowed_image(array $file): bool {
    $allowed = ['image/jpeg','image/jpg','image/png','image/webp','image/gif'];
    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    return in_array($finfo->file($file['tmp_name']), $allowed, true);
}

function cs_logo_dir(): string {
    $abs = __DIR__ . '/../../uploads/logos';  // uploads/ is the persistent volume on Railway
    if (!is_dir($abs)) mkdir($abs, 0755, true);
    return $abs;
}

// ── Helper: upsert a single setting ───────────────────────────────────────────
function cs_save(PDO $conn, string $key, string $value): void {
    $stmt = $conn->prepare(
        "INSERT INTO settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->execute([$key, $value]);
}

// ── Self-healing: make sure the logo dir has .htaccess blocking PHP execution
$logo_dir = cs_logo_dir();
$htaccess  = $logo_dir . '/.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess,
        "# Block execution of scripts in the logo upload folder\n"
      . "<FilesMatch \"\\.php$\">\n"
      . "    Require all denied\n"
      . "</FilesMatch>\n"
    );
}

// ── Load current settings ──────────────────────────────────────────────────────
$rows = $conn->query(
    "SELECT setting_key, setting_value FROM settings WHERE setting_key IN (
        'clinic_name','clinic_subtitle','clinic_address',
        'clinic_phone','clinic_landline','clinic_email','clinic_logo'
    )"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$cur_name     = $rows['clinic_name']     ?? 'DentalCare Clinic';
$cur_subtitle = $rows['clinic_subtitle'] ?? 'Dental Clinic Management System';
$cur_address  = $rows['clinic_address']  ?? '';
$cur_phone    = $rows['clinic_phone']    ?? '';
$cur_landline = $rows['clinic_landline'] ?? '';
$cur_email    = $rows['clinic_email']    ?? '';
$cur_logo     = $rows['clinic_logo']     ?? '';

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? '';

    // ── Save text info ─────────────────────────────────────────────────────────
    if ($action === 'save_info') {
        $name     = trim($_POST['clinic_name']     ?? '');
        $subtitle = trim($_POST['clinic_subtitle'] ?? '');
        $address  = trim($_POST['clinic_address']  ?? '');
        $phone    = trim($_POST['clinic_phone']    ?? '');
        $landline = trim($_POST['clinic_landline'] ?? '');
        $email    = trim($_POST['clinic_email']    ?? '');

        if ($name === '') {
            $error = 'Clinic name cannot be empty.';
        } else {
            // Work out which fields really changed (for the activity log) before saving
            $changed = [];
            if ($name     !== $cur_name)     $changed[] = 'name';
            if ($subtitle !== $cur_subtitle) $changed[] = 'subtitle';
            if ($address  !== $cur_address)  $changed[] = 'address';
            if ($phone    !== $cur_phone)    $changed[] = 'phone';
            if ($landline !== $cur_landline) $changed[] = 'landline';
            if ($email    !== $cur_email)    $changed[] = 'email';

            cs_save($conn, 'clinic_name',     $name);
            cs_save($conn, 'clinic_subtitle', $subtitle);
            cs_save($conn, 'clinic_address',  $address);
            cs_save($conn, 'clinic_phone',    $phone);
            cs_save($conn, 'clinic_landline', $landline);
            cs_save($conn, 'clinic_email',    $email);

            // Reload for display
            $cur_name     = $name;
            $cur_subtitle = $subtitle;
            $cur_address  = $address;
            $cur_phone    = $phone;
            $cur_landline = $landline;
            $cur_email    = $email;
            $success      = 'Clinic information saved. All printed documents will now show the updated name.';
            log_action($conn, $current_user_id, $current_user_name, 'Updated Clinic Info', 'settings', null,
                $changed ? 'Changed: ' . implode(', ', $changed) : 'Saved with no changes');
        }
    }

    // ── Upload logo ────────────────────────────────────────────────────────────
    if ($action === 'upload_logo') {
        if (empty($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
            $err_map = [
                1 => 'File is too large (server limit).',
                2 => 'File exceeds the upload size limit.',
                3 => 'Upload was incomplete — please try again.',
                4 => 'No file was selected.',
            ];
            $error = $err_map[$_FILES['logo']['error'] ?? 4] ?? 'Upload error. Please try again.';
        } elseif ($_FILES['logo']['size'] > 2 * 1024 * 1024) {
            $error = 'Logo must be under 2 MB.';
        } elseif (!cs_allowed_image($_FILES['logo'])) {
            $error = 'Only JPEG, PNG, WebP, or GIF images are accepted.';
        } else {
            $file = $_FILES['logo'];
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp','gif'])) $ext = 'jpg';

            $fname    = 'clinic_logo_' . time() . '.' . $ext;
            $dest     = cs_logo_dir() . '/' . $fname;
            $rel_path = 'uploads/logos/' . $fname;

            // Delete old logo file if it exists
            if ($cur_logo !== '' && file_exists(__DIR__ . '/../../' . $cur_logo)) {
                @unlink(__DIR__ . '/../../' . $cur_logo);
            }

            if (move_uploaded_file($file['tmp_name'], $dest)) {
                cs_save($conn, 'clinic_logo', $rel_path);
                $cur_logo = $rel_path;
                $success  = 'Logo uploaded successfully. It will now appear on all printed documents.';
                log_action($conn, $current_user_id, $current_user_name, 'Uploaded Clinic Logo', 'settings', null, $fname);
            } else {
                $error = 'Failed to save the logo. Check that the uploads folder is writable.';
            }
        }
    }

    // ── Remove logo ────────────────────────────────────────────────────────────
    if ($action === 'remove_logo') {
        if ($cur_logo !== '' && file_exists(__DIR__ . '/../../' . $cur_logo)) {
            @unlink(__DIR__ . '/../../' . $cur_logo);
        }
        cs_save($conn, 'clinic_logo', '');
        $cur_logo = '';
        $success  = 'Logo removed. Printed documents will show the default 🦷 icon.';
        log_action($conn, $current_user_id, $current_user_name, 'Removed Clinic Logo', 'settings');
    }
}

// Build current logo URL for preview
$logo_preview_url = '';
if ($cur_logo !== '') {
    $logo_preview_url = BASE_URL . ltrim($cur_logo, '/');
}
?><!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?>
<style>
/* The main layout below is a raw 2-column CSS grid (form + live print
   preview) with a fixed 380px sidebar and no responsive handling at all —
   collapses it to a single stacked column on tablets/phones instead of
   forcing a 380px-wide panel onto a ~375px screen. */
@media (max-width: 900px) {
    [style*="grid-template-columns:1fr 380px"] { display: block !important; }
}
@media (max-width: 576px) {
    [style*="grid-template-columns:1fr 1fr"] { display: block !important; }
    [style*="grid-template-columns:1fr 1fr"] > div { margin-bottom: 12px; }
}
</style>
</head>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">

        <div class="page-header" style="margin-bottom:20px;">
            <div>
                <h5>Clinic Settings</h5>
                <small class="text-muted">Controls the clinic name, contact details, and logo shown on every printed document</small>
            </div>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-circle me-2"></i><?php echo e($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?php echo e($success); ?></div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:1fr 380px;gap:18px;align-items:start;">

            <!-- LEFT: Clinic Info Form + Print Preview -->
            <div style="display:flex;flex-direction:column;gap:18px;">

                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-building me-2" style="color:var(--primary);"></i>Clinic Information
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="save_info">
                            <div style="display:flex;flex-direction:column;gap:14px;">
                                <div>
                                    <label class="form-label fw-semibold" style="font-size:0.83rem;">Clinic Name <span style="color:var(--danger);">*</span></label>
                                    <input type="text" name="clinic_name" class="form-control"
                                           value="<?php echo e($cur_name); ?>"
                                           placeholder="e.g. Santos Dental Clinic"
                                           maxlength="120" required
                                           oninput="document.getElementById('prev-name').textContent=this.value||'Clinic Name'">
                                    <div class="form-text">This is the main heading on all printed documents.</div>
                                </div>
                                <div>
                                    <label class="form-label fw-semibold" style="font-size:0.83rem;">Subtitle</label>
                                    <input type="text" name="clinic_subtitle" class="form-control"
                                           value="<?php echo e($cur_subtitle); ?>"
                                           placeholder="e.g. General and Cosmetic Dentistry"
                                           maxlength="160"
                                           oninput="document.getElementById('prev-subtitle').textContent=this.value">
                                    <div class="form-text">Short tagline shown just below the clinic name.</div>
                                </div>
                                <div>
                                    <label class="form-label fw-semibold" style="font-size:0.83rem;">Address</label>
                                    <input type="text" name="clinic_address" class="form-control"
                                           value="<?php echo e($cur_address); ?>"
                                           placeholder="e.g. 123 Rizal St., Iloilo City"
                                           maxlength="200"
                                           oninput="updatePrevContact()">
                                </div>
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                                    <div>
                                        <label class="form-label fw-semibold" style="font-size:0.83rem;">Mobile / Phone</label>
                                        <input type="text" name="clinic_phone" class="form-control"
                                               value="<?php echo e($cur_phone); ?>"
                                               placeholder="e.g. +63 912 345 6789"
                                               maxlength="60"
                                               oninput="updatePrevContact()">
                                    </div>
                                    <div>
                                        <label class="form-label fw-semibold" style="font-size:0.83rem;">Landline <span class="text-muted fw-normal">(shown on prescriptions)</span></label>
                                        <input type="text" name="clinic_landline" class="form-control"
                                               value="<?php echo e($cur_landline); ?>"
                                               placeholder="e.g. (033) 509 68 46"
                                               maxlength="60">
                                    </div>
                                    <div>
                                        <label class="form-label fw-semibold" style="font-size:0.83rem;">Email</label>
                                        <input type="email" name="clinic_email" class="form-control"
                                               value="<?php echo e($cur_email); ?>"
                                               placeholder="e.g. info@clinic.ph"
                                               maxlength="120"
                                               oninput="updatePrevContact()">
                                    </div>
                                </div>
                            </div>
                            <div style="margin-top:18px;">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save me-1"></i> Save Clinic Information
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Print Header Preview -->
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-eye me-2" style="color:var(--primary);"></i>Print Header Preview
                        <span class="text-muted ms-1" style="font-size:0.78rem;">— updates as you type</span>
                    </div>
                    <div class="card-body" style="background:var(--gray-50);">
                        <div style="background:#fff;border:1px solid var(--border);border-radius:10px;padding:18px 22px;">
                            <div style="display:flex;align-items:center;gap:14px;">
                                <div id="prev-logo-wrap">
                                    <?php if ($logo_preview_url): ?>
                                        <img src="<?php echo e($logo_preview_url); ?>" alt="logo"
                                             style="max-height:50px;max-width:110px;object-fit:contain;">
                                    <?php else: ?>
                                        <span style="font-size:2rem;">&#x1F9B7;</span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <div id="prev-name" style="font-family:'Outfit',sans-serif;font-weight:700;font-size:1.05rem;color:#0f172a;">
                                        <?php echo e($cur_name); ?>
                                    </div>
                                    <div id="prev-subtitle" style="font-size:0.78rem;color:#64748b;">
                                        <?php echo e($cur_subtitle); ?>
                                    </div>
                                    <div id="prev-contact" style="font-size:0.7rem;color:#94a3b8;margin-top:2px;">
                                        <?php
                                        $parts = array_filter([$cur_address, $cur_phone, $cur_email]);
                                        echo e(implode(' · ', $parts));
                                        ?>
                                    </div>
                                </div>
                            </div>
                            <div style="border-top:2px solid #e2e8f0;margin:12px 0 8px;"></div>
                            <div style="font-size:0.72rem;color:#94a3b8;text-align:right;">
                                <strong>Appointment Slip</strong> &middot; Printed: <?php echo date('M d, Y'); ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div><!-- end left column -->

            <!-- RIGHT: Logo -->
            <div style="display:flex;flex-direction:column;gap:18px;">

                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-image me-2" style="color:var(--primary);"></i>Clinic Logo
                    </div>
                    <div class="card-body" style="text-align:center;">
                        <?php if ($logo_preview_url): ?>
                            <img src="<?php echo e($logo_preview_url); ?>"
                                 alt="Current clinic logo"
                                 style="max-width:160px;max-height:90px;object-fit:contain;border-radius:8px;border:1px solid var(--border);margin-bottom:10px;">
                            <div class="text-muted mb-3" style="font-size:0.78rem;">Current logo</div>
                            <form method="POST"
                                  onsubmit="return confirm('Remove the current logo?')">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="remove_logo">
                                <button type="submit" class="btn btn-sm btn-outline-danger w-100">
                                    <i class="bi bi-trash me-1"></i> Remove Logo
                                </button>
                            </form>
                        <?php else: ?>
                            <div style="width:90px;height:90px;background:var(--gray-100);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:2.6rem;margin:0 auto 10px;">&#x1F9B7;</div>
                            <div class="text-muted" style="font-size:0.78rem;">No logo — default icon shown</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-upload me-2" style="color:var(--primary);"></i><?php echo $logo_preview_url ? 'Replace Logo' : 'Upload Logo'; ?>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="upload_logo">
                            <input type="file" name="logo" id="logoFile"
                                   class="form-control mb-2" accept="image/*"
                                   onchange="previewLogo(this)">
                            <div class="form-text mb-3">JPEG, PNG, WebP, or GIF &middot; Max 2 MB</div>
                            <div id="logoPreviewWrap" style="display:none;text-align:center;margin-bottom:12px;">
                                <img id="logoPreviewImg" src="" alt="Preview"
                                     style="max-width:140px;max-height:70px;object-fit:contain;border-radius:6px;border:1px dashed var(--border);">
                                <div class="text-muted mt-1" style="font-size:0.73rem;">Preview</div>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-upload me-1"></i> Upload Logo
                            </button>
                        </form>
                    </div>
                </div>

                <div class="card" style="background:#eff6ff;border-color:#bfdbfe;">
                    <div class="card-body" style="font-size:0.82rem;color:#1e40af;">
                        <div style="font-weight:600;margin-bottom:8px;">
                            <i class="bi bi-info-circle-fill me-1"></i> Where this appears
                        </div>
                        <ul class="mb-0" style="padding-left:18px;line-height:2;">
                            <li>Appointment Slip</li>
                            <li>Walk-in Slip</li>
                            <li>Payment Receipt</li>
                            <li>Dental Record Print</li>
                            <li>Dental Certificate</li>
                            <li>Prescription</li>
                            <li>Daily Schedule Print</li>
                        </ul>
                    </div>
                </div>

            </div><!-- end right column -->
        </div><!-- end grid -->

    </div>
</div>
<?php include '../../includes/footer.php'; ?>
<script>
function previewLogo(input) {
    var wrap = document.getElementById('logoPreviewWrap');
    var img  = document.getElementById('logoPreviewImg');
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) { img.src = e.target.result; wrap.style.display = 'block'; };
        reader.readAsDataURL(input.files[0]);
    } else {
        wrap.style.display = 'none';
    }
}
function updatePrevContact() {
    var a = document.querySelector('[name=clinic_address]').value;
    var p = document.querySelector('[name=clinic_phone]').value;
    var e = document.querySelector('[name=clinic_email]').value;
    document.getElementById('prev-contact').textContent = [a,p,e].filter(Boolean).join(' · ');
}
</script>
</body>
</html>