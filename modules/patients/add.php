<?php
// Add a new patient to the system with full medical details.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

// Ensure session variables are available (defined in auth.php)
$current_user_id   = $current_user_id ?? 0;
$current_user_name = $current_user_name ?? 'Unknown';

$page_title = 'Add Patient';
$error   = '';
$success = '';
$old     = $_POST; // repopulate form on error

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validate_csrf();
        $first_name  = ucwords(strtolower(trim($_POST['first_name'] ?? '')));
    $last_name   = ucwords(strtolower(trim($_POST['last_name'] ?? '')));
    $middle_name = ucwords(strtolower(trim($_POST['middle_name'] ?? '')));
    $dob         = $_POST['date_of_birth'] ?? '';
    $gender      = $_POST['gender'] ?? '';
    $civil       = $_POST['civil_status'] ?? '';
    $blood       = trim($_POST['blood_type'] ?? '');
    $address     = trim($_POST['address'] ?? '');
    $phone       = trim($_POST['phone'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $ec_name     = trim($_POST['emergency_contact_name'] ?? '');
    $ec_phone    = trim($_POST['emergency_contact_phone'] ?? '');
    $occupation   = trim($_POST['occupation'] ?? '');
    $allergies    = trim($_POST['allergies'] ?? '');
    $med_notes    = trim($_POST['medical_notes'] ?? '');
    $ill_history  = trim($_POST['illness_history'] ?? '');

    $missing = [];
    if (!$first_name)  $missing[] = 'First Name';
    if (!$last_name)   $missing[] = 'Last Name';
    // Middle Name, Blood Type, Email, Medical Notes are now optional
    if (!$dob)         $missing[] = 'Date of Birth';
    if (!$gender)      $missing[] = 'Gender';
    if (!$civil)       $missing[] = 'Civil Status';
    // Blood Type is optional
    if (!$address)     $missing[] = 'Address';
    if (!$phone || strlen(preg_replace('/[^0-9]/', '', $phone)) < 9) $missing[] = 'Phone';
    // Email is optional
    // Emergency contact is optional
    if (!$allergies)   $missing[] = 'Known Allergies';
    // Medical Notes is optional

    // SECURITY #4 — Server-side field length caps (DB columns + sanity limits)
    $length_errors = [];
    if (strlen($first_name)  > 50)  $length_errors[] = 'First Name (max 50 chars)';
    if (strlen($last_name)   > 50)  $length_errors[] = 'Last Name (max 50 chars)';
    if (strlen($middle_name) > 50)  $length_errors[] = 'Middle Name (max 50 chars)';
    if (strlen($address)     > 500) $length_errors[] = 'Address (max 500 chars)';
    if (strlen($email)       > 100) $length_errors[] = 'Email (max 100 chars)';
    if (strlen($ec_name)     > 100) $length_errors[] = 'Emergency Contact Name (max 100 chars)';
    if (strlen($allergies)   > 2000)$length_errors[] = 'Allergies (max 2000 chars)';
    if (strlen($med_notes)   > 2000)$length_errors[] = 'Medical Notes (max 2000 chars)';

    if (!empty($missing)) {
        $error = 'Please fill in all required fields: ' . implode(', ', $missing) . '.';
    } elseif (!empty($length_errors)) {
        $error = 'Field(s) exceed maximum length: ' . implode(', ', $length_errors) . '.';
    } elseif ($email && !valid_email($email)) {
        $error = 'Please enter a valid email address.';
    } elseif (!valid_phone($phone)) {
        $error = 'Phone number is invalid. Select a country code and enter the local number.';
    } elseif ($ec_phone && !valid_phone($ec_phone)) {
        $error = 'Emergency contact phone is invalid. Select a country code and enter the local number.';
    } else {
        $patient_code = generate_code($conn, 'patients', 'PAT');
        $stmt = $conn->prepare("
            INSERT INTO patients
            (patient_code, first_name, last_name, middle_name, date_of_birth, gender, civil_status,
             address, occupation, phone, email, emergency_contact_name, emergency_contact_phone,
             blood_type, allergies, medical_notes, illness_history, registered_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        if (!$stmt) {
            $error = 'Database error preparing statement. Please ensure the database migration has been run.';
        } else {
            if ($stmt->execute([
                $patient_code, $first_name, $last_name, $middle_name, $dob, $gender, $civil,
                $address, $occupation, $phone, $email, $ec_name, $ec_phone,
                $blood, $allergies, $med_notes, $ill_history, $current_user_id
            ])) {
                $new_id = (int)$conn->lastInsertId();
                log_action($conn, $current_user_id, $current_user_name, 'Added Patient', 'patients', $new_id, "Patient: $first_name $last_name ($patient_code)");
                $success = "Patient $first_name $last_name ($patient_code) added successfully.";
                $old = []; // clear form on success
            } else {
                $error = 'Failed to save patient. Please try again.';
            }
            $stmt = null;
        }
    }
}

function old(string $key, string $default = ''): string {
    global $old;
    return htmlspecialchars($old[$key] ?? $default);
}
?><!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?></head>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">

        <div class="page-header">
            <div>
                <h5>Add New Patient</h5>
                <p>All fields marked <span style="color:var(--danger)">*</span> are required</p>
            </div>
            <a href="list.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to List
            </a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle-fill"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <i class="bi bi-person-plus" style="color:var(--blue-500)"></i>
                Patient Information
            </div>
            <div class="card-body">
                <form method="POST" id="patientForm" novalidate>
                    <?php echo csrf_field(); ?>

                    <!-- Section: Personal -->
                    <div style="background:var(--gray-50);border-radius:8px;padding:16px 18px;margin-bottom:22px;">
                        <p style="font-family:'Outfit',sans-serif;font-weight:600;font-size:0.85rem;color:var(--blue-600);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:14px;">
                            <i class="bi bi-person"></i> Personal Information
                        </p>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">First Name <span style="color:var(--danger)">*</span></label>
                                <input type="text" name="first_name" class="form-control" required value="<?php echo old('first_name'); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Last Name <span style="color:var(--danger)">*</span></label>
                                <input type="text" name="last_name" class="form-control" required value="<?php echo old('last_name'); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Middle Name <span style="font-size:0.75rem;color:var(--gray-400);">(optional)</span></label>
                                <input type="text" name="middle_name" class="form-control" value="<?php echo old('middle_name'); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Date of Birth <span style="color:var(--danger)">*</span></label>
                                <input type="date" name="date_of_birth" class="form-control" required value="<?php echo old('date_of_birth'); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Gender <span style="color:var(--danger)">*</span></label>
                                <select name="gender" class="form-select" required>
                                    <option value="">Select Gender</option>
                                    <option value="male"   <?php echo old('gender') === 'male'   ? 'selected' : ''; ?>>Male</option>
                                    <option value="female" <?php echo old('gender') === 'female' ? 'selected' : ''; ?>>Female</option>
                                    <option value="other"  <?php echo old('gender') === 'other'  ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Civil Status <span style="color:var(--danger)">*</span></label>
                                <select name="civil_status" class="form-select" required>
                                    <option value="">Select Status</option>
                                    <option value="single"    <?php echo old('civil_status') === 'single'    ? 'selected' : ''; ?>>Single</option>
                                    <option value="married"   <?php echo old('civil_status') === 'married'   ? 'selected' : ''; ?>>Married</option>
                                    <option value="widowed"   <?php echo old('civil_status') === 'widowed'   ? 'selected' : ''; ?>>Widowed</option>
                                    <option value="separated" <?php echo old('civil_status') === 'separated' ? 'selected' : ''; ?>>Separated</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Blood Type <span style="font-size:0.75rem;color:var(--gray-400);">(optional)</span></label>
                                <select name="blood_type" class="form-select">
                                    <option value="">Select</option>
                                    <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bt): ?>
                                        <option value="<?php echo $bt; ?>" <?php echo old('blood_type') === $bt ? 'selected' : ''; ?>><?php echo $bt; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Section: Contact -->
                    <div style="background:var(--gray-50);border-radius:8px;padding:16px 18px;margin-bottom:22px;">
                        <p style="font-family:'Outfit',sans-serif;font-weight:600;font-size:0.85rem;color:var(--blue-600);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:14px;">
                            <i class="bi bi-geo-alt"></i> Contact Details
                        </p>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Address <span style="color:var(--danger)">*</span></label>
                                <input type="text" name="address" class="form-control" required placeholder="House No., Street, Barangay, City" value="<?php echo old('address'); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Occupation</label>
                                <input type="text" name="occupation" class="form-control" placeholder="e.g. Student, Teacher, Engineer" value="<?php echo old('occupation'); ?>">
                            </div>
                            <div class="col-md-5">
                                <?php
                                    $phone_field_name     = 'phone';
                                    $phone_field_value    = old('phone');
                                    $phone_field_label    = 'Phone';
                                    $phone_field_required = true;
                                    include '../../includes/phone_input.php';
                                ?>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Email <span style="font-size:0.75rem;color:var(--gray-400);">(optional)</span></label>
                                <input type="email" name="email" class="form-control" placeholder="example@email.com" value="<?php echo old('email'); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Section: Emergency Contact -->
                    <div style="background:var(--gray-50);border-radius:8px;padding:16px 18px;margin-bottom:22px;">
                        <p style="font-family:'Outfit',sans-serif;font-weight:600;font-size:0.85rem;color:var(--blue-600);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:14px;">
                            <i class="bi bi-telephone"></i> Emergency Contact
                        </p>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Contact Name <span style="color:var(--gray-400);font-weight:400;">(optional)</span></label>
                                <input type="text" name="emergency_contact_name" class="form-control" value="<?php echo old('emergency_contact_name'); ?>">
                            </div>
                            <div class="col-md-6">
                                <?php
                                    $phone_field_name     = 'emergency_contact_phone';
                                    $phone_field_value    = old('emergency_contact_phone');
                                    $phone_field_label    = 'Contact Phone (optional)';
                                    $phone_field_required = false;
                                    include '../../includes/phone_input.php';
                                ?>
                            </div>
                        </div>
                    </div>

                    <!-- Section: Medical -->
                    <div style="background:var(--gray-50);border-radius:8px;padding:16px 18px;margin-bottom:22px;">
                        <p style="font-family:'Outfit',sans-serif;font-weight:600;font-size:0.85rem;color:var(--blue-600);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:14px;">
                            <i class="bi bi-heart-pulse"></i> Medical Background
                        </p>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Known Allergies <span style="color:var(--danger)">*</span></label>
                                <textarea name="allergies" class="form-control" rows="3" required placeholder="List any known drug or material allergies. Type 'None' if none."><?php echo old('allergies'); ?></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Medical Notes <span style="font-size:0.75rem;color:var(--gray-400);">(optional)</span></label>
                                <textarea name="medical_notes" class="form-control" rows="3" placeholder="Existing conditions, medications, etc."><?php echo old('medical_notes'); ?></textarea>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">History of Illness</label>
                                <textarea name="illness_history" class="form-control" rows="2" placeholder="Past illnesses, hospitalizations, or significant medical events (optional)"><?php echo old('illness_history'); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div style="display:flex;gap:10px;align-items:center;">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg"></i> Save Patient
                        </button>
                        <a href="list.php" class="btn btn-outline-secondary">Cancel</a>
                        <span style="font-size:0.78rem;color:var(--gray-400);margin-left:8px;">
                            <i class="bi bi-info-circle"></i> All fields are required
                        </span>
                    </div>

                </form>
            </div>
        </div>
    </div>

<script>
// Extra JS validation - highlight empty fields before submit
document.getElementById('patientForm').addEventListener('submit', function(e) {
    document.querySelectorAll('.phone-input-wrap').forEach(function(wrap) {
        phoneInputSync(wrap.id.replace('_wrap', ''));
    });
    var inputs = this.querySelectorAll('[required]');
    var hasEmpty = false;
    inputs.forEach(function(input) {
        if (!input.value.trim()) {
            input.style.borderColor = 'var(--danger)';
            input.style.boxShadow = '0 0 0 3px rgba(220,38,38,0.12)';
            hasEmpty = true;
        } else {
            input.style.borderColor = '';
            input.style.boxShadow = '';
        }
    });
    if (hasEmpty) {
        e.preventDefault();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
});

// Remove red highlight when user types
document.querySelectorAll('[required]').forEach(function(input) {
    input.addEventListener('input', function() {
        if (this.value.trim()) {
            this.style.borderColor = '';
            this.style.boxShadow = '';
        }
    });
});
</script>
</div>
<?php include '../../includes/footer.php'; ?>
</body>
</html>