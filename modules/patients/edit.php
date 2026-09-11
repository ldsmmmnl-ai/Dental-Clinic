<?php
// Edit an existing patient record. Also handles soft-delete (deactivation).

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

$page_title = 'Edit Patient';

// Get current user info from session
$current_user_id   = $_SESSION['user_id']   ?? null;
// $current_user_name set by auth.php

$id = secure_int($_GET['id'] ?? 0);
if (!$id) { header('Location: list.php'); exit(); }

$ep_stmt = $conn->prepare("SELECT * FROM patients WHERE id = ? AND is_active = TRUE LIMIT 1");
$ep_stmt->execute([$id]);
$patient = $ep_stmt->fetch(PDO::FETCH_ASSOC);
$ep_stmt->closeCursor();
if (!$patient) { header('Location: list.php'); exit(); }

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validate_csrf();
        $first_name  = ucwords(strtolower(trim($_POST['first_name'] ?? '')));
    $last_name   = ucwords(strtolower(trim($_POST['last_name'] ?? '')));
    $middle_name = ucwords(strtolower(trim($_POST['middle_name'] ?? '')));
    $dob         = $_POST['date_of_birth'] ?? '';
    $gender      = $_POST['gender'] ?? '';
    $civil       = $_POST['civil_status'] ?? 'single';
    $address     = trim($_POST['address'] ?? '');
    $occupation  = trim($_POST['occupation'] ?? '');
    $phone       = trim($_POST['phone'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $ec_name     = trim($_POST['emergency_contact_name'] ?? '');
    $ec_phone    = trim($_POST['emergency_contact_phone'] ?? '');
    $blood       = trim($_POST['blood_type'] ?? '');
    $med_notes      = trim($_POST['medical_notes'] ?? '');
    $ill_history    = trim($_POST['illness_history'] ?? '');
    $treatment_plan = trim($_POST['treatment_plan'] ?? '');

    // SECURITY #4 — Server-side field length caps
    $length_errors = [];
    if (strlen($first_name)  > 50)  $length_errors[] = 'First Name (max 50 chars)';
    if (strlen($last_name)   > 50)  $length_errors[] = 'Last Name (max 50 chars)';
    if (strlen($middle_name) > 50)  $length_errors[] = 'Middle Name (max 50 chars)';
    if (strlen($address)     > 500) $length_errors[] = 'Address (max 500 chars)';
    if (strlen($email)       > 100) $length_errors[] = 'Email (max 100 chars)';
    if (strlen($ec_name)     > 100) $length_errors[] = 'Emergency Contact Name (max 100 chars)';
    if (strlen($med_notes)      > 2000)$length_errors[] = 'Medical Notes (max 2000 chars)';
    if (strlen($treatment_plan) > 2000)$length_errors[] = 'Treatment Plan (max 2000 chars)';

    if (!empty($length_errors)) {
        $error = 'Field(s) exceed maximum length: ' . implode(', ', $length_errors) . '.';
    } elseif (empty($first_name) || empty($last_name)) {
        $error = 'First name and last name are required.';
    } elseif (strlen($first_name) < 2 || strlen($last_name) < 2) {
        $error = 'First and last name must each be at least 2 characters.';
    } elseif (!empty($email) && !valid_email($email)) {
        $error = 'Please enter a valid email address.';
    } elseif (!empty($phone) && !valid_phone($phone)) {
        $error = 'Phone number is invalid. Please select a country code and enter the local number.';
    } elseif (!empty($ec_phone) && !valid_phone($ec_phone)) {
        $error = 'Emergency contact phone is invalid. Please select a country code and enter the local number.';
    } else {
        // Auto-clear the "incomplete profile" flag once staff fill in the
        // core fields a quick-entry record skips (DOB, gender, address).
        // A patient created via the full Add Patient form already has
        // is_incomplete = FALSE, so this is a no-op for them.
        $now_complete = (!empty($dob) && !empty($gender) && !empty($address));
        $clear_incomplete = ($now_complete && !empty($patient['is_incomplete']));

        $stmt = $conn->prepare("
            UPDATE patients SET
                first_name=?, last_name=?, middle_name=?, date_of_birth=?, gender=?, civil_status=?,
                address=?, occupation=?, phone=?, email=?, emergency_contact_name=?, emergency_contact_phone=?,
                blood_type=?, medical_notes=?, illness_history=?, treatment_plan=?" .
                ($clear_incomplete ? ", is_incomplete=FALSE" : "") . "
            WHERE id=?
        ");
        if (!$stmt) {
            $error = 'Database error preparing statement. Please ensure the database migration has been run.';
        } else {
        if ($stmt->execute([
            $first_name, $last_name, $middle_name, $dob, $gender, $civil,
            $address, $occupation, $phone, $email, $ec_name, $ec_phone,
            $blood, $med_notes, $ill_history, $treatment_plan, $id
        ])) {
            log_action($conn, $current_user_id, $current_user_name, 'Edited Patient', 'patients', $id, "Updated: $first_name $last_name");
            $success = $clear_incomplete
                ? 'Patient record updated successfully. Profile is now complete.'
                : 'Patient record updated successfully.';
            // Refresh patient data
            $ep2_stmt = $conn->prepare("SELECT * FROM patients WHERE id = ? LIMIT 1");
            $ep2_stmt->execute([$id]);
            $patient = $ep2_stmt->fetch(PDO::FETCH_ASSOC);
            $ep2_stmt->closeCursor();
        } else {
            $error = 'Failed to update. Please try again.';
        }
        } // end if($stmt)
    }
}

// Soft delete
if (isset($_GET['delete']) && is_admin()) {
    $conn->prepare("UPDATE patients SET is_active = FALSE WHERE id = ?")->execute([$id]);
    log_action($conn, $current_user_id, $current_user_name, 'Deleted Patient', 'patients', $id, "Soft deleted patient ID: $id");
    header('Location: list.php');
    exit();
}
?><!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?></head>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5>Edit Patient — <?php echo htmlspecialchars($patient['patient_code']); ?></h5>
            <div class="d-flex gap-2">
                <a href="view.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-info">View Profile</a>
                <?php if (is_admin()): ?>
                <a href="edit.php?id=<?php echo $id; ?>&delete=1"
                   class="btn btn-sm btn-outline-danger"
                   onclick="return confirm('Archive this patient? They will not appear in the list.')">
                    <i class="bi bi-archive"></i> Archive
                </a>
                <?php endif; ?>
                <a href="list.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <form method="POST">
                    <?php echo csrf_field(); ?>

                    <h6 class="mb-3">Personal Information</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">First Name <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" class="form-control" required value="<?php echo htmlspecialchars($patient['first_name']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" class="form-control" required value="<?php echo htmlspecialchars($patient['last_name']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Middle Name</label>
                            <input type="text" name="middle_name" class="form-control" value="<?php echo htmlspecialchars($patient['middle_name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" name="date_of_birth" class="form-control" value="<?php echo $patient['date_of_birth']; ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Gender</label>
                            <select name="gender" class="form-select">
                                <option value="">Select</option>
                                <option value="male"   <?php echo $patient['gender'] === 'male'   ? 'selected' : ''; ?>>Male</option>
                                <option value="female" <?php echo $patient['gender'] === 'female' ? 'selected' : ''; ?>>Female</option>
                                <option value="other"  <?php echo $patient['gender'] === 'other'  ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Civil Status</label>
                            <select name="civil_status" class="form-select">
                                <option value="single"    <?php echo $patient['civil_status'] === 'single'    ? 'selected' : ''; ?>>Single</option>
                                <option value="married"   <?php echo $patient['civil_status'] === 'married'   ? 'selected' : ''; ?>>Married</option>
                                <option value="widowed"   <?php echo $patient['civil_status'] === 'widowed'   ? 'selected' : ''; ?>>Widowed</option>
                                <option value="separated" <?php echo $patient['civil_status'] === 'separated' ? 'selected' : ''; ?>>Separated</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Blood Type</label>
                            <input type="text" name="blood_type" class="form-control" value="<?php echo htmlspecialchars($patient['blood_type'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Address</label>
                            <input type="text" name="address" class="form-control" value="<?php echo htmlspecialchars($patient['address'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Occupation</label>
                            <input type="text" name="occupation" class="form-control" placeholder="e.g. Student, Teacher, Engineer" value="<?php echo htmlspecialchars($patient['occupation'] ?? ''); ?>">
                        </div>
                        <div class="col-md-5">
                            <?php
                                $phone_field_name     = 'phone';
                                $phone_field_value    = $patient['phone'] ?? '';
                                $phone_field_label    = 'Phone';
                                $phone_field_required = false;
                                include '../../includes/phone_input.php';
                            ?>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($patient['email'] ?? ''); ?>">
                        </div>
                    </div>

                    <h6 class="mb-3">Emergency Contact</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Contact Name</label>
                            <input type="text" name="emergency_contact_name" class="form-control" value="<?php echo htmlspecialchars($patient['emergency_contact_name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <?php
                                $phone_field_name     = 'emergency_contact_phone';
                                $phone_field_value    = $patient['emergency_contact_phone'] ?? '';
                                $phone_field_label    = 'Contact Phone';
                                $phone_field_required = false;
                                include '../../includes/phone_input.php';
                            ?>
                        </div>
                    </div>

                    <h6 class="mb-3">Dental Background</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Medical Notes</label>
                            <textarea name="medical_notes" class="form-control" rows="2" placeholder="Existing conditions, known allergies, current medications, etc."><?php echo htmlspecialchars($patient['medical_notes'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">History of Illness</label>
                            <textarea name="illness_history" class="form-control" rows="2" placeholder="Past illnesses, hospitalizations, or significant medical events (optional)"><?php echo htmlspecialchars($patient['illness_history'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Treatment Plan</label>
                            <textarea name="treatment_plan" class="form-control" rows="3" placeholder="Treatment plan and medications prescribed"><?php echo htmlspecialchars($patient['treatment_plan'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <a href="view.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary ms-2">Cancel</a>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>
</body>
</html>
