<?php
ob_start(); // Buffer output — prevents PHP warnings from corrupting JSON responses
// Register a walk-in patient and auto-assign the next available time slot for today.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/clinic_settings.php';
$_cs = clinic_settings($conn);

// Variables from auth.php for static analysis
/** @var int $current_user_id */
/** @var string $current_user_name */
/** @var string $current_user_role */

$page_title        = 'Walk-in Registration';
$error             = '';
$success           = '';
$new_appt          = null;
$duplicate_warning = '';

$services = sc_get('svc_walkin') ?? sc_set('svc_walkin', $conn->query(
    "SELECT id, service_name, price, duration_minutes FROM services WHERE is_active = TRUE ORDER BY service_name"
)->fetchAll(PDO::FETCH_ASSOC), 300);

$walkin_doctors = sc_get('doc_walkin') ?? sc_set('doc_walkin', $conn->query(
    "SELECT id, full_name, specialization FROM doctors WHERE is_active = TRUE ORDER BY full_name ASC"
)->fetchAll(PDO::FETCH_ASSOC), 300);

// WALK-IN NEXT SLOT LOGIC
//
// Correct approach:
//   1. Get clinic schedule for today (open_time, close_time, duration)
//   2. Get ALL appointments already booked today
//   3. Generate every possible slot from open_time → close_time
//      using the configured duration interval (e.g. every 30 mins)
//   4. For each slot, check if it is already taken by an appointment
//   5. Return the FIRST slot that is free AND is not in the past
//
// Example:
//   Schedule: 8:00 AM – 5:00 PM, 30-min slots
//   Booked:   9:00, 9:30, 10:00
//   Current time: 9:50 AM
//   → Skips 8:00,8:30 (past), 9:00,9:30,10:00 (taken)
//   → Returns 10:30 AM as the next available slot
//
// If NO open schedule exists today → staff can enter manual time
// If schedule is full → show message but still allow manual time
function find_next_available_slot($conn) {
    $today = date('Y-m-d');
    $now   = time();
    $day   = strtolower(date('l'));

    // Check if today is blocked
    $bl = $conn->prepare("SELECT id FROM blocked_dates WHERE blocked_date = ? LIMIT 1");
    $bl->execute([$today]);
    $blocked_row = $bl->fetch(PDO::FETCH_ASSOC);
    $bl->closeCursor();

    if ($blocked_row) {
        return [
            'slot'      => null,
            'label'     => null,
            'is_closed' => true,
            'reason'    => 'Today is a blocked date (holiday or clinic closed).',
            'all_slots' => [],
        ];
    }

    // Get today's schedule
    $ss = $conn->prepare("SELECT * FROM schedules WHERE day_of_week = ? AND is_open = TRUE LIMIT 1");
    $ss->execute([$day]);
    $sched = $ss->fetch(PDO::FETCH_ASSOC);
    $ss->closeCursor();

    if (!$sched) {
        return [
            'slot'      => null,
            'label'     => null,
            'is_closed' => true,
            'reason'    => 'No schedule configured for ' . ucfirst($day) . '. Use the manual time field below.',
            'all_slots' => [],
        ];
    }

    $open_ts  = strtotime($today . ' ' . $sched['open_time']);
    $close_ts = strtotime($today . ' ' . $sched['close_time']);
    $step     = intval($sched['slot_duration_minutes'] ?? 30) * 60;

    // Get all booked appointments today WITH durations (not cancelled/no-show).
    // We need duration_minutes so we block slots that fall *inside* an existing
    // appointment's window, not just slots that share the exact same start time.
    // e.g. A 90-min root canal at 13:00 must also block the 13:30 slot.
    $br = $conn->prepare("
        SELECT a.appointment_time,
               COALESCE((SELECT SUM(sv.duration_minutes) FROM appointment_services x JOIN services sv ON sv.id=x.service_id WHERE x.appointment_id=a.id), s.duration_minutes, ?) AS duration_minutes
        FROM   appointments a
        LEFT JOIN services s ON s.id = a.service_id
        WHERE  a.appointment_date = ?
        AND    a.status NOT IN ('cancelled','no-show')
        ORDER BY a.appointment_time ASC
    ");
    $br->execute([intval($sched['slot_duration_minutes']), $today]);
    $booked_rows = $br->fetchAll(PDO::FETCH_ASSOC);
    $br->closeCursor();
    $booked_windows = [];
    $booked_count   = 0;
    foreach ($booked_rows as $row) {
        $appt_start = strtotime($today . ' ' . $row['appointment_time']);
        $booked_windows[] = [
            'start' => $appt_start,
            'end'   => $appt_start + (intval($row['duration_minutes']) * 60),
        ];
        $booked_count++;
    }

    // Build list of all slots and find the first free one after current time.
    $all_slots  = [];
    $next_slot  = null;
    $next_label = null;

    for ($t = $open_ts; $t < $close_ts; $t += $step) {
        $slot_time  = date('H:i', $t);
        $slot_label = date('h:i A', $t);
        $is_past    = $t < $now;

        // A slot is taken if it falls inside any existing appointment's time window.
        // Condition: appt_start <= slot_time < appt_end
        $is_taken = false;
        foreach ($booked_windows as $win) {
            if ($t >= $win['start'] && $t < $win['end']) {
                $is_taken = true;
                break;
            }
        }

        $all_slots[] = [
            'time'  => $slot_time,
            'label' => $slot_label,
            'taken' => $is_taken,
            'past'  => $is_past,
        ];

        // First slot that is free AND not in the past
        if (!$next_slot && !$is_taken && !$is_past) {
            $next_slot  = $slot_time . ':00';
            $next_label = $slot_label;
        }
    }

    $is_full = ($next_slot === null);

    return [
        'slot'        => $next_slot,
        'label'       => $next_label,
        'is_closed'   => false,
        'is_full'     => $is_full,
        'reason'      => $is_full
            ? 'Schedule is full for today (no more slots before ' . date('h:i A', $close_ts) . ').'
            : null,
        'all_slots'   => $all_slots,
        'open_label'  => date('h:i A', $open_ts),
        'close_label' => date('h:i A', $close_ts),
        'booked_count'=> $booked_count,
        'total_slots' => count($all_slots),
    ];
}

// ── AJAX: slot + doctor data for any date (used by the drawer) ──────────────
function get_slots_for_date_any($conn, $date) {
    $today    = date('Y-m-d');
    $day      = strtolower(date('l', strtotime($date)));
    $is_today = ($date === $today);

    // Check blocked date
    $bst = $conn->prepare("SELECT id FROM blocked_dates WHERE blocked_date = ? LIMIT 1");
    $bst->execute([$date]);
    $blocked_row = $bst->fetch(PDO::FETCH_ASSOC);
    $bst->closeCursor();
    if ($blocked_row) return ['is_closed'=>true,'reason'=>'This date is blocked.','all_slots'=>[],'slot'=>null,'label'=>null];

    // Check schedule
    $sst = $conn->prepare("SELECT * FROM schedules WHERE day_of_week = ? AND is_open = TRUE LIMIT 1");
    $sst->execute([$day]);
    $sched = $sst->fetch(PDO::FETCH_ASSOC);
    $sst->closeCursor();
    if (!$sched) return ['is_closed'=>true,'reason'=>'Clinic is closed on '.ucfirst($day).'s.','all_slots'=>[],'slot'=>null,'label'=>null];

    $open_ts  = strtotime($date.' '.$sched['open_time']);
    $close_ts = strtotime($date.' '.$sched['close_time']);
    $step     = intval($sched['slot_duration_minutes'] ?? 30) * 60;
    $def_dur  = intval($sched['slot_duration_minutes'] ?? 30);

    // Get booked appointments
    $ast = $conn->prepare("SELECT a.appointment_time, COALESCE((SELECT SUM(sv.duration_minutes) FROM appointment_services x JOIN services sv ON sv.id=x.service_id WHERE x.appointment_id=a.id), s.duration_minutes, ?) AS duration_minutes FROM appointments a LEFT JOIN services s ON s.id=a.service_id WHERE a.appointment_date=? AND a.status NOT IN ('cancelled','no-show')");
    $ast->execute([$def_dur, $date]);
    $arows = $ast->fetchAll(PDO::FETCH_ASSOC);
    $ast->closeCursor();

    $booked=[]; foreach ($arows as $r) { $s=strtotime($date.' '.$r['appointment_time']); $booked[]=['start'=>$s,'end'=>$s+intval($r['duration_minutes'])*60]; }
    $all_slots=[]; $next_slot=$next_label=null; $now=time();
    for ($t=$open_ts; $t<$close_ts; $t+=$step) {
        $taken=false; $past=$is_today&&($t<$now);
        foreach ($booked as $w){if($t>=$w['start']&&$t<$w['end']){$taken=true;break;}}
        $all_slots[]=['time'=>date('H:i',$t),'label'=>date('h:i A',$t),'taken'=>$taken,'past'=>$past];
        if(!$next_slot&&!$taken&&!$past){$next_slot=date('H:i',$t).':00';$next_label=date('h:i A',$t);}
    }
    $is_full=($next_slot===null);
    return ['is_closed'=>false,'is_full'=>$is_full,'slot'=>$next_slot,'label'=>$next_label,
        'reason'=>$is_full?'No available slots on '.date('M d,Y',strtotime($date)).'.':null,
        'all_slots'=>$all_slots,'total_slots'=>count($all_slots),'booked_count'=>count($arows),'is_today'=>$is_today];
}
function get_doctors_for_date_any($conn, $date) {
    $abbr=strtolower(substr(date('l',strtotime($date)),0,3));
    $res=$conn->query("SELECT id,full_name,specialization,schedule_days FROM doctors WHERE is_active=TRUE ORDER BY full_name ASC");
    $docs=[];
    while($d=$res->fetch(PDO::FETCH_ASSOC)){$days=array_map('trim',explode(',',$d['schedule_days']??''));if(in_array($abbr,$days))$docs[]=['id'=>$d['id'],'full_name'=>$d['full_name'],'specialization'=>$d['specialization']];}
    return $docs;
}
// ── AJAX: search existing patients by name or phone ─────────────────────────
if (isset($_GET['action']) && $_GET['action']==='search_patient') {
    ob_clean();
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo json_encode(['status'=>'ok','patients'=>[]]); exit(); }
    $like = '%' . $q . '%';
    $res = $conn->prepare("
        SELECT id, patient_code, first_name, last_name, phone,
               (SELECT COUNT(*) FROM appointments WHERE patient_id=patients.id) as appt_count
        FROM patients
        WHERE CONCAT(first_name,' ',last_name) LIKE ?
           OR phone LIKE ?
           OR patient_code LIKE ?
        ORDER BY last_name, first_name
        LIMIT 8
    ");
    $res->execute([$like, $like, $like]);
    $patients = $res->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status'=>'ok','patients'=>$patients]);
    exit();
}

if (isset($_GET['action']) && $_GET['action']==='get_slots') {
    ob_clean(); // discard any stray output before JSON
    header('Content-Type: application/json');
    $date = trim($_GET['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(422);
        echo json_encode(['status'=>'error','message'=>'Invalid date format. Expected YYYY-MM-DD.']);
        exit();
    }
    // Allow today and future dates only
    $today_ph = date('Y-m-d'); // Asia/Manila set in config.php
    if ($date < $today_ph) {
        http_response_code(422);
        echo json_encode(['status'=>'error','message'=>'Cannot load slots for a past date.']);
        exit();
    }
    try {
        $slot_data   = get_slots_for_date_any($conn, $date);
        $doctors     = get_doctors_for_date_any($conn, $date);
        $day_name    = date('l, F j Y', strtotime($date));
        echo json_encode(['status'=>'success','slot_data'=>$slot_data,'doctors'=>$doctors,'day_name'=>$day_name]);
    } catch (Exception $ex) {
        error_log('[get_slots] ' . $ex->getMessage());
        http_response_code(500);
        echo json_encode(['status'=>'error','message'=>'Failed to load slot data. Please try again.']);
    }
    exit();
}

$slot_data  = find_next_available_slot($conn);
$next_slot  = $slot_data['slot'];
$next_label = $slot_data['label'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $first_name  = ucwords(strtolower(trim($_POST['first_name']  ?? '')));
    $last_name   = ucwords(strtolower(trim($_POST['last_name']   ?? '')));
    $phone       = trim($_POST['phone']       ?? '');

    // FIX #1: Use null instead of 0 when no service/doctor is selected.
    // A value of 0 breaks the FK constraint on the appointments table.
    // One or MANY services (check-boxes). service_id keeps the FIRST one for reports / older pages.
    $service_ids = array_map(fn($r) => (int)$r['id'], ms_fetch_services($conn, ms_post_ids()));
    $service_id  = $service_ids[0] ?? null;
    $doctor_id   = !empty($_POST['doctor_id'])  ? intval($_POST['doctor_id'])  : null;

    $notes         = trim($_POST['notes']         ?? '');
    $selected_time = trim($_POST['selected_time'] ?? '');
    $manual_time   = trim($_POST['manual_time']   ?? ''); // fallback (legacy)
    $today         = date('Y-m-d');

    // ── MODE: 'walkin' (this page) vs 'appointment' (calendar/list drawer) ──
    // The mode is fixed by which form submitted — never inferred from the
    // date — so a walk-in can never become a scheduled appointment and
    // vice versa.
    $mode = ($_POST['mode'] ?? 'walkin') === 'appointment' ? 'appointment' : 'walkin';

    if ($mode === 'walkin') {
        // WALK-IN: always today. Date field is ignored even if sent.
        $appointment_date = $today;
        $is_today_appt    = true;
    } else {
        // APPOINTMENT: always a future date (tomorrow or later).
        $appointment_date = trim($_POST['appointment_date'] ?? '');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $appointment_date) || $appointment_date < $tomorrow) {
            $error = 'Please choose a future date for the appointment.';
        }
        $is_today_appt = false;
    }

    // Phone is only required for NEW patients on advance bookings (needed for reminders).
    // Existing/returning patients already have their phone on record.
    $existing_patient_id_pre = intval($_POST['existing_patient_id'] ?? 0);
    if (empty($error) && $mode === 'appointment' && $existing_patient_id_pre === 0 && empty($phone)) {
        $error = 'Phone number is required for new patients making advance bookings (used for appointment reminders).';
    }
    // Service is required for all walk-ins
    if (empty($error) && $mode === 'walkin' && empty($service_id)) {
        $error = 'Please tick at least one service for this walk-in.';
    }
    // Prefer selected_time (slot picker) over manual_time
    $slot_input = !empty($selected_time) ? $selected_time : $manual_time;

    // Appointments always require a chosen slot — never auto-assigned.
    if (empty($error) && $mode === 'appointment' && empty($slot_input)) {
        $error = 'Please select a time slot for the appointment.';
    }

    $existing_patient_id_check = intval($_POST['existing_patient_id'] ?? 0);
    if (empty($error) && $existing_patient_id_check === 0) {
        if (empty($first_name) || empty($last_name)) {
            $error = 'First name and last name are required.';
        } elseif (strlen($first_name) < 2 || strlen($last_name) < 2) {
            $error = 'First and last name must each be at least 2 characters.';
        }
    }
    if (empty($error) && !empty($phone) && !valid_phone($phone)) {
        $error = 'Please enter a valid phone number using the country code selector.';
    }
    if (empty($error) && strlen($notes) > 500) {
        $error = 'Notes must be 500 characters or fewer.';
    }
    
    $assigned_time = null;
    if (empty($error)) {
        // Determine assigned time
        if (!empty($slot_input)) {
            // Staff picked a slot — validate format AND check conflicts on the correct date
            if (!preg_match('/^\d{2}:\d{2}$/', $slot_input)) {
                $error = 'Please enter time in HH:MM format (e.g. 14:30).';
            } else {
                $sel_ts = strtotime($appointment_date . ' ' . $slot_input);
                $conf_stmt = $conn->prepare("
                    SELECT a.appointment_time, COALESCE((SELECT SUM(sv.duration_minutes) FROM appointment_services x JOIN services sv ON sv.id=x.service_id WHERE x.appointment_id=a.id), s.duration_minutes, 30) AS duration_minutes
                    FROM appointments a LEFT JOIN services s ON s.id=a.service_id
                    WHERE a.appointment_date=? AND a.status NOT IN ('cancelled','no-show')
                ");
                $conf_stmt->execute([$appointment_date]);
                $conf_rows = $conf_stmt->fetchAll(PDO::FETCH_ASSOC); $conf_stmt->closeCursor();
                $has_conflict=false; $conflict_label='';
                foreach ($conf_rows as $crow) {
                    $ex_start=strtotime($appointment_date.' '.$crow['appointment_time']);
                    $ex_end=$ex_start+(intval($crow['duration_minutes'])*60);
                    if ($sel_ts>=$ex_start&&$sel_ts<$ex_end){$has_conflict=true;$conflict_label=date('h:i A',$ex_start).' – '.date('h:i A',$ex_end);break;}
                }
                if ($has_conflict) {
                    $error = "That time ($slot_input) conflicts with an existing appointment ($conflict_label). Please choose a different time.";
                } else {
                    $assigned_time = $slot_input . ':00';
                }
            }
        } else {
            // Auto-assign: first free slot on the selected date
            $fresh = get_slots_for_date_any($conn, $appointment_date);
            if (!$fresh['slot']) {
                $error = $fresh['reason'] ?? 'No available slot found. Please pick a time manually.';
            } else {
                $assigned_time = $fresh['slot'];
            }
        }

        if (empty($error)) {
            // ── RETURNING PATIENT CHECK ────────────────────────────────────────
            // If patient_id was passed (staff selected an existing patient), use it.
            // Otherwise try to find a match by name + phone before creating new.
            $existing_patient_id = intval($_POST['existing_patient_id'] ?? 0);
            $patient_id          = 0;
            $is_returning        = false;

            if ($existing_patient_id > 0) {
                // Staff explicitly selected a returning patient from the search
                $chk = $conn->prepare("SELECT id, patient_code FROM patients WHERE id = ? LIMIT 1");
                $chk->execute([$existing_patient_id]);
                $chk_row = $chk->fetch(PDO::FETCH_ASSOC);
                $chk->closeCursor();
                if ($chk_row) {
                    $patient_id   = $chk_row['id'];
                    $patient_code = $chk_row['patient_code'];
                    $is_returning = true;
                }
            }

            if (!$patient_id) {
                // Auto-match: same first+last name AND same phone (non-empty)
                if (!empty($phone)) {
                    $match = $conn->prepare("SELECT id, patient_code FROM patients WHERE first_name=? AND last_name=? AND phone=? LIMIT 1");
                    $match->execute([$first_name, $last_name, $phone]);
                    $match_row = $match->fetch(PDO::FETCH_ASSOC);
                    $match->closeCursor();
                    if ($match_row) {
                        $patient_id   = $match_row['id'];
                        $patient_code = $match_row['patient_code'];
                        $is_returning = true;
                    }
                }
            }

            if (!$patient_id) {
                // No match — create new patient.
                // is_incomplete = TRUE: this is a quick-entry record (name +
                // phone only). Patient Records will show an "Incomplete
                // profile" badge until staff fill in the full chart.
                $patient_code = generate_code($conn, 'patients', 'PAT');
                $stmt = $conn->prepare(
                    "INSERT INTO patients (patient_code, first_name, last_name, phone, registered_by, is_incomplete)
                     VALUES (?,?,?,?,?,TRUE)"
                );
                $stmt->execute([$patient_code, $first_name, $last_name, $phone, $current_user_id]);
                $patient_id = $conn->lastInsertId();
                $stmt->closeCursor();
            }

            // ── DUPLICATE GUARD ──────────────────────────────────────────────
            // Warn (via $duplicate_warning) if this patient already has a
            // non-cancelled appointment on the same date. We warn, not block,
            // so staff can still proceed if it's intentional (two services).
            $dup_chk = $conn->prepare("
                SELECT appointment_code, appointment_time, status
                FROM appointments
                WHERE patient_id = ?
                  AND appointment_date = ?
                  AND status NOT IN ('cancelled','no-show')
                LIMIT 1
            ");
            $dup_chk->execute([$patient_id, $appointment_date]);
            $dup_row = $dup_chk->fetch(PDO::FETCH_ASSOC);
            $dup_chk->closeCursor();
            $duplicate_warning = $dup_row
                ? 'Note: This patient already has appointment ' . $dup_row['appointment_code']
                  . ' (' . ucfirst($dup_row['status']) . ') on this date at '
                  . date('h:i A', strtotime($dup_row['appointment_time'])) . '.'
                : '';

            // Create appointment
            $type      = ($mode === 'appointment') ? 'scheduled' : 'walk-in';
            $status    = 'pending'; // Staff must click Confirm — never auto-confirmed

            // appointment_code is NOT NULL + UNIQUE with no DB default, so the INSERT
            // must supply *something* even though the real code (based on the new
            // auto-increment id) isn't known until right after. A random placeholder
            // satisfies both constraints and is immediately overwritten below —
            // it is never seen by a user. (Without this, every insert throws
            // SQLSTATE[HY000] 1364 "Field 'appointment_code' doesn't have a default
            // value" under MySQL/MariaDB's default strict SQL mode.)
            $temp_code = 'TMP-' . bin2hex(random_bytes(8));

            $stmt2 = $conn->prepare("
                INSERT INTO appointments
                (appointment_code, patient_id, service_id, doctor_id, appointment_date,
                 appointment_time, type, status, notes, handled_by)
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt2->execute([$temp_code, $patient_id, $service_id, $doctor_id, $appointment_date, $assigned_time, $type, $status, $notes, $current_user_id]);
            $new_appt_id = (int)$conn->lastInsertId(); // capture immediately before other queries
            // Generate code from the actual auto-increment ID — zero race condition
            $appt_code = generate_code_after_insert($new_appt_id, 'APT');
            $upd = $conn->prepare("UPDATE appointments SET appointment_code = ? WHERE id = ?");
            $upd->execute([$appt_code, $new_appt_id]);
            $upd->closeCursor();
            $stmt2->closeCursor();

            // Save every ticked service on this appointment + build the slip text
            $svc_name  = '—';
            $svc_price = 0;
            if ($service_ids) {
                $saved_svcs = ms_save($conn, 'appointment_services', 'appointment_id', $new_appt_id, $service_ids);
                if ($saved_svcs) {
                    $svc_name  = implode(', ', array_column($saved_svcs, 'service_name'));
                    $svc_price = array_sum(array_map(fn($r) => (float)$r['price'], $saved_svcs));
                }
            }

            // FIX #3: Look up doctor name so it can be included in the AJAX response.
            $doc_name = '—';
            if ($doctor_id) {
                $doc_stmt = $conn->prepare("SELECT full_name FROM doctors WHERE id = ? LIMIT 1");
                $doc_stmt->execute([$doctor_id]);
                $doc_row  = $doc_stmt->fetch(PDO::FETCH_ASSOC);
                $doc_stmt->closeCursor();
                $doc_name = $doc_row['full_name'] ?? '—';
            }

            log_action($conn, $current_user_id, $current_user_name,
                $mode === 'appointment' ? 'Booked Appointment' : 'Walk-in Registration',
                $mode === 'appointment' ? 'appointments' : 'walkin',
                $patient_id,
                "Patient: $first_name $last_name | Appt: $appt_code | " . ($mode === 'appointment' ? "Date: $appointment_date $assigned_time" : "Slot: $assigned_time")
            );

            // ── Auto-notification: alert staff about the new walk-in / booking ──
            if ($mode === 'walkin') {
                notify($conn, 'appointment',
                    'New Walk-in Added',
                    "$first_name $last_name has been registered as a walk-in ($appt_code). Assigned slot: " . date('h:i A', strtotime($assigned_time)) . ".",
                    'modules/appointments/list.php'
                );
            } else {
                $fmt_date = date('M d, Y', strtotime($appointment_date));
                notify($conn, 'appointment',
                    'New Appointment Booked',
                    "$first_name $last_name has been booked for $fmt_date at " . date('h:i A', strtotime($assigned_time)) . ". ($appt_code)",
                    'modules/appointments/list.php'
                );
            }
            // ─────────────────────────────────────────────────────────────────

            $new_appt = [
                'appt_code'    => $appt_code,
                'appt_id'      => $new_appt_id,
                'patient_id'   => $patient_id,
                'patient_code' => $patient_code,
                'patient_name' => "$first_name $last_name",
                'phone'        => $phone,
                'service_name' => $svc_name,
                'service'      => $svc_name,
                'doctor_name'  => $doc_name,   // FIX #3: included in response
                'price'        => $svc_price,
                'date'         => $appointment_date,
                'time'         => $assigned_time,
                'notes'        => $notes,
                'staff'        => $current_user_name,
            ];

            $success = $mode === 'appointment'
                ? 'Appointment booked for ' . date('M d, Y', strtotime($appointment_date)) . ' at ' . date('h:i A', strtotime($assigned_time)) . '.'
                : 'Walk-in registered! Assigned time slot: ' . date('h:i A', strtotime($assigned_time));

            // If called from drawer (AJAX), return JSON and exit
            if (!empty($_POST['_ajax'])) {
                ob_clean(); // Wipe any buffered warnings
                header('Content-Type: application/json');
                echo json_encode([
                    'status'            => 'success',
                    'message'           => $success,
                    'appt'              => $new_appt,
                    'is_returning'      => $is_returning,
                    'duplicate_warning' => $duplicate_warning,
                ]);
                exit();
            }

            // Refresh slot data
            $slot_data  = find_next_available_slot($conn);
            $next_slot  = $slot_data['slot'];
            $next_label = $slot_data['label'];
        }
    }
}

// If AJAX request with an error, return JSON and stop
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['_ajax']) && $error) {
    ob_clean(); // Wipe any buffered warnings
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => $error]);
    exit();
}
?><!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?>
<style>
/* ── Walk-in page: phone input fix ── */
.phone-input-wrap .form-select {
    border-radius: var(--border-radius-md) 0 0 var(--border-radius-md) !important;
    border-right: 0 !important;
    font-size: 0.82rem;
}
.phone-input-wrap .form-control {
    border-radius: 0 var(--border-radius-md) var(--border-radius-md) 0 !important;
}
.phone-input-wrap > div { display: flex !important; }

/* ── Walk-in page: next-slot alert banner ── */
.walkin-slot-banner {
    display: flex;
    align-items: center;
    gap: 14px;
    background: linear-gradient(135deg, var(--primary-bg), #e0f2fe);
    border: 1.5px solid var(--blue-200);
    border-radius: 12px;
    padding: 14px 20px;
    margin-bottom: 20px;
}
.walkin-slot-banner .slot-time {
    font-size: 1.5rem;
    font-weight: 900;
    color: var(--blue-600, #2563eb);
    font-family: var(--font-display);
    letter-spacing: -0.02em;
    white-space: nowrap;
}
.walkin-slot-banner .slot-label {
    font-size: 0.78rem;
    color: var(--gray-500);
    margin-top: 2px;
}

/* ── slot timeline pill ── */
.slot-pill {
    display: flex; align-items: center; gap: 8px;
    padding: 7px 12px; border-radius: 9px; font-size: 0.8rem;
    font-weight: 600; border: 1.5px solid transparent;
    transition: all 0.15s;
}
.slot-pill.free    { background: var(--success-bg); color: var(--success); border-color: var(--success-border); }
.slot-pill.booked  { background: var(--danger-bg);  color: var(--danger);  border-color: var(--danger-border);  opacity:.8; }
.slot-pill.past    { background: var(--gray-100);   color: var(--gray-400); border-color: var(--gray-200); opacity:.6; }
.slot-pill.next    { background: linear-gradient(135deg,var(--blue-500),var(--blue-400)); color:#fff; border-color: var(--blue-500); box-shadow: 0 2px 10px rgba(37,99,235,.3); }

/* ── Walk-in slip ── */
.slip-box {
    background: #fff;
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    padding: 22px 24px;
    max-width: 420px;
    font-family: 'DM Sans', sans-serif;
}
.slip-header {
    text-align: center;
    border-bottom: 2px solid #2563eb;
    padding-bottom: 14px;
    margin-bottom: 14px;
}
.slip-time {
    text-align: center;
    background: #eff6ff;
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 14px;
}
.tl { font-size: 0.68rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.06em; font-weight: 700; }
.tv { font-size: 1.8rem; font-weight: 900; color: #2563eb; line-height: 1.1; font-family: 'Outfit', sans-serif; }
.slip-row {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 12px;
    padding: 6px 0;
    border-bottom: 1px dashed #e2e8f0;
    font-size: 0.82rem;
}
.slip-row:last-of-type { border-bottom: none; }
.sl { color: #64748b; font-weight: 500; flex-shrink: 0; }
.sv { font-weight: 700; color: #0f172a; text-align: right; }

/* ════════════════════════════════════════════════════
   Walk-in — Mobile Responsiveness
   Grid was 1fr 380px with only a @media print rule.
   ════════════════════════════════════════════════════ */
@media (max-width: 820px) {
    /* Collapse two-column form + sidebar layout to single column */
    .walkin-layout {
        grid-template-columns: 1fr !important;
    }
    /* Un-sticky the schedule sidebar so it appears BELOW the form */
    .walkin-sidebar-card {
        position: static !important;
        top: auto !important;
    }
    /* Page header: stack info + slot indicator */
    .page-header-bar {
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 10px !important;
    }
    .page-header-bar > div:last-child {
        margin-left: 0 !important;
        width: 100%;
    }
}

/* ── Print media ── */
@media print {
    /* Hide everything that's not the slip */
    #sidebar, #topbar, .no-print { display: none !important; }
    .main-content { margin: 0 !important; }
    .page-content { padding: 0 !important; }

    /* Slip goes full page */
    #printSlip {
        position: fixed;
        top: 0; left: 0;
        width: 100%;
        padding: 24px;
    }
    .slip-box {
        max-width: 100%;
        border: none;
        border-radius: 0;
        padding: 0;
        box-shadow: none;
    }
    /* Two-column grid collapses to single column */
    [style*="grid-template-columns"] {
        display: block !important;
    }
}
</style>
</head>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">

        <!-- Page Header Bar -->
        <div class="page-header-bar no-print" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:20px;background:var(--white);border:var(--border);border-radius:14px;padding:12px 18px;box-shadow:0 1px 6px rgba(0,0,0,0.05);">
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:40px;height:40px;background:linear-gradient(135deg,var(--blue-500),var(--blue-400));border-radius:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="bi bi-person-walking" style="color:#fff;font-size:1.1rem;"></i>
                </div>
                <div>
                    <div style="font-size:1rem;font-weight:800;color:var(--gray-900);line-height:1.1;">Walk-in Registration</div>
                    <div style="font-size:0.72rem;color:var(--gray-400);font-weight:500;">
                        <?php if (!empty($slot_data['open_label'])): ?>
                        Clinic hours: <strong><?php echo $slot_data['open_label']; ?></strong> – <strong><?php echo $slot_data['close_label']; ?></strong>
                        · <?php echo ($slot_data['total_slots'] ?? 0) - ($slot_data['booked_count'] ?? 0); ?> slots free
                        <?php else: ?>
                        Assigns the next available time slot automatically
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php if (!$slot_data['is_closed'] && !empty($next_label)): ?>
            <div style="margin-left:auto;display:flex;align-items:center;gap:10px;background:var(--primary-bg);border:1.5px solid var(--blue-200);border-radius:10px;padding:8px 16px;">
                <i class="bi bi-clock-fill" style="color:var(--blue-500);font-size:1rem;"></i>
                <div>
                    <div style="font-size:0.65rem;color:var(--gray-500);text-transform:uppercase;font-weight:700;letter-spacing:.06em;">Next available slot</div>
                    <div style="font-size:1.15rem;font-weight:900;color:var(--blue-600,#2563eb);font-family:var(--font-display);line-height:1.1;"><?php echo $next_label; ?></div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Status Banner (closed / full only — green slot shown in header above) -->
        <?php if ($slot_data['is_closed']): ?>
        <div class="alert alert-warning no-print" style="display:flex;align-items:flex-start;gap:12px;margin-bottom:20px;border-radius:12px;">
            <i class="bi bi-calendar-x" style="font-size:1.2rem;flex-shrink:0;margin-top:2px;"></i>
            <div>
                <strong>Clinic closed today</strong> — <?php echo e($slot_data['reason']); ?><br>
                <span style="font-size:0.82rem;">You can still register a patient using the manual time field below.</span>
            </div>
        </div>
        <?php elseif (!empty($slot_data['is_full'])): ?>
        <div class="alert alert-warning no-print" style="display:flex;align-items:flex-start;gap:12px;margin-bottom:20px;border-radius:12px;">
            <i class="bi bi-calendar-check" style="font-size:1.2rem;flex-shrink:0;margin-top:2px;"></i>
            <div>
                <strong>All slots booked</strong> — <?php echo e($slot_data['reason']); ?><br>
                <span style="font-size:0.82rem;">You can still register a patient using the manual time field below.</span>
            </div>
        </div>
        <?php endif; ?>

        <!-- Error -->
        <?php if ($error): ?>
        <div class="alert alert-danger no-print"><i class="bi bi-x-circle-fill"></i> <?php echo e($error); ?></div>
        <?php endif; ?>

        <!-- TWO-COLUMN LAYOUT: Form left, Schedule right -->
        <div class="walkin-layout" style="display:grid;grid-template-columns:1fr 380px;gap:22px;align-items:start;">

            <!-- LEFT: Form or Slip -->
            <div>
            <?php if ($success && $new_appt): ?>
            <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> <?php echo e($success); ?></div>
            <?php if ($duplicate_warning): ?>
            <div class="alert alert-warning" style="display:flex;align-items:flex-start;gap:10px;margin-bottom:12px;"><i class="bi bi-exclamation-triangle-fill" style="flex-shrink:0;margin-top:2px;"></i><span><?php echo e($duplicate_warning); ?></span></div>
            <?php endif; ?>
            <div id="printSlip" style="margin-bottom:20px;">
                <div class="slip-box">
                    <div class="slip-header">
                        <div><?php echo $_cs['logo_html']; ?></div>
                        <div style="font-family:'Outfit',sans-serif;font-weight:700;font-size:1rem;margin:4px 0 2px;"><?php echo e($_cs['name']); ?></div>
                        <div style="font-size:0.75rem;color:var(--gray-500);">Walk-in Appointment Slip</div>
                        <?php if ($_cs['address'] || $_cs['phone']): ?>
                        <div style="font-size:0.7rem;color:var(--gray-400);margin-top:2px;"><?php
                            echo e(implode(' · ', array_filter([$_cs['address'], $_cs['phone']])));
                        ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="slip-time">
                        <div class="tl">Assigned Time Slot</div>
                        <div class="tv"><?php echo date('h:i A', strtotime($new_appt['time'])); ?></div>
                        <div style="font-size:0.82rem;color:var(--gray-500);margin-top:4px;"><?php echo date('F d, Y', strtotime($new_appt['date'])); ?></div>
                    </div>
                    <div class="slip-row"><span class="sl">Appointment Code</span><span class="sv"><?php echo e($new_appt['appt_code']); ?></span></div>
                    <div class="slip-row"><span class="sl">Patient Code</span><span class="sv"><?php echo e($new_appt['patient_code']); ?></span></div>
                    <div class="slip-row"><span class="sl">Patient Name</span><span class="sv"><?php echo e($new_appt['patient_name']); ?></span></div>
                    <div class="slip-row"><span class="sl">Phone</span><span class="sv"><?php echo e($new_appt['phone'] ?: '—'); ?></span></div>
                    <div class="slip-row"><span class="sl">Service</span><span class="sv"><?php echo e($new_appt['service']); ?></span></div>
                    <div class="slip-row"><span class="sl">Served by</span><span class="sv"><?php echo e($new_appt['staff']); ?></span></div>
                    <div style="text-align:center;margin-top:14px;font-size:0.72rem;color:var(--gray-400);">
                        Please present this slip at the reception.<br>Generated: <?php echo date('M d, Y h:i A'); ?>
                    </div>
                </div>
            </div>
            <div style="display:flex;gap:10px;">
                <button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer"></i> Print Slip</button>
                <a href="add.php" class="btn btn-outline-secondary"><i class="bi bi-plus"></i> Register Another</a>
            </div>

            <?php else: ?>
            <div class="card" style="border-radius:14px;overflow:hidden;">
                <div class="card-header" style="background:var(--white);border-bottom:var(--border);padding:14px 20px;display:flex;align-items:center;gap:10px;">
                    <div style="width:32px;height:32px;background:linear-gradient(135deg,var(--blue-500),var(--blue-400));border-radius:8px;display:flex;align-items:center;justify-content:center;">
                        <i class="bi bi-person-walking" style="color:#fff;font-size:0.9rem;"></i>
                    </div>
                    <div>
                        <div style="font-weight:700;font-size:0.9rem;color:var(--gray-900);">Patient Details</div>
                        <div style="font-size:0.7rem;color:var(--gray-400);">Search for a returning patient, or fill in the fields to register a new one</div>
                    </div>
                </div>
                <div class="card-body" style="padding:20px;">
                    <form method="POST" id="walkinStandaloneForm">
                    <?php echo csrf_field(); ?>
                        <input type="hidden" name="mode" value="walkin">
                        <input type="hidden" name="existing_patient_id" id="standalone_existing_patient_id" value="">

                        <!-- ── Returning Patient Search ──────────────────── -->
                        <div style="margin-bottom:18px;padding:14px 16px;background:var(--gray-50);border:1.5px solid var(--gray-200);border-radius:10px;">
                            <label style="font-size:0.78rem;font-weight:700;color:var(--gray-600);margin-bottom:7px;display:block;"><i class="bi bi-search"></i> Search Returning Patient <span style="font-weight:400;color:var(--gray-400);">(by name, phone, or code)</span></label>
                            <div style="position:relative;">
                                <input type="text" id="standalonePatientSearch" autocomplete="off" placeholder="e.g. Maria Santos or 0917…"
                                    style="width:100%;padding:9px 14px 9px 36px;border:1.5px solid var(--gray-200);border-radius:8px;font-size:0.85rem;background:var(--white);color:var(--gray-900);outline:none;transition:border 0.15s;"
                                    oninput="standaloneSearchPatient(this.value)"
                                    onfocus="this.style.borderColor='var(--primary)'" onblur="setTimeout(()=>this.style.borderColor='var(--gray-200)',200)">
                                <i class="bi bi-search" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--gray-400);font-size:0.8rem;pointer-events:none;"></i>
                            </div>
                            <div id="standalonePatientResults" style="display:none;margin-top:6px;border:1.5px solid var(--gray-200);border-radius:8px;background:var(--white);overflow:hidden;box-shadow:0 4px 14px rgba(0,0,0,0.08);"></div>
                            <div id="standaloneSelectedPatient" style="display:none;margin-top:8px;padding:10px 14px;background:var(--primary-bg);border:1.5px solid var(--blue-200);border-radius:8px;display:flex;align-items:center;justify-content:space-between;gap:10px;">
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <i class="bi bi-person-check-fill" style="color:var(--primary);font-size:1rem;"></i>
                                    <div>
                                        <div id="standaloneSelectedName" style="font-weight:700;font-size:0.85rem;color:var(--gray-900);"></div>
                                        <div id="standaloneSelectedMeta" style="font-size:0.72rem;color:var(--gray-500);"></div>
                                    </div>
                                </div>
                                <button type="button" onclick="standaloneClearPatient()" style="background:none;border:none;cursor:pointer;color:var(--gray-400);font-size:1rem;padding:0;" title="Clear selection"><i class="bi bi-x-circle"></i></button>
                            </div>
                            <div id="standaloneNewBadge" style="display:none;margin-top:8px;padding:6px 12px;background:rgba(21,128,61,0.08);border:1.5px solid rgba(21,128,61,0.2);border-radius:8px;font-size:0.75rem;color:#15803d;font-weight:600;">
                                <i class="bi bi-person-plus-fill"></i> New patient — a new record will be created
                            </div>
                        </div>

                        <!-- ── Patient Name Fields ────────────────────────── -->
                        <div id="standaloneNameFields">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">First Name <span style="color:var(--danger)">*</span></label>
                                <input type="text" name="first_name" id="sf_first_name" class="form-control" required autofocus>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Last Name <span style="color:var(--danger)">*</span></label>
                                <input type="text" name="last_name" id="sf_last_name" class="form-control" required>
                            </div>
                        </div>
                        </div>
                        <div class="row g-3" style="margin-top:0;">
                            <div class="col-md-6">
                                <?php
                                    $phone_field_name     = 'phone';
                                    $phone_field_value    = '';
                                    $phone_field_label    = 'Phone';
                                    $phone_field_required = false;
                                    include '../../includes/phone_input.php';
                                ?>
                                <div id="phone_hint" style="font-size:0.72rem;margin-top:3px;color:var(--gray-400);">Optional for today's walk-ins</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Services <span style="color:var(--danger)">*</span> <span style="font-size:0.72rem;color:var(--gray-400)">(tick all that apply)</span></label>
                                <?php echo ms_checkbox_list($services, [], ['prefix' => 'wk_svc']); ?>
                            </div>
                            <?php if (!empty($walkin_doctors)): ?>
                            <div class="col-md-6">
                                <label class="form-label">Doctor <span style="font-size:0.72rem;color:var(--gray-400)">(optional)</span></label>
                                <select name="doctor_id" class="form-select">
                                    <option value="">Any Available Doctor</option>
                                    <?php foreach ($walkin_doctors as $d): ?>
                                    <option value="<?php echo $d['id']; ?>">
                                        <?php echo e($d['full_name']); ?>
                                        <?php if ($d['specialization']): ?> — <?php echo e($d['specialization']); ?><?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div class="col-12">
                                <label class="form-label">Notes / Chief Complaint <span style="font-size:0.72rem;color:var(--gray-400)">(optional)</span></label>
                                <textarea name="notes" class="form-control" rows="2" placeholder="Describe the patient's concern..."></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label">
                                    Override Time
                                    <span style="font-size:0.72rem;color:var(--gray-400);">
                                        — Leave blank to auto-assign<?php if ($next_label): ?> <strong style="color:var(--blue-500);">(<?php echo $next_label; ?>)</strong><?php endif; ?>
                                    </span>
                                </label>
                                <input type="time" name="manual_time" class="form-control" style="max-width:200px;" step="1800">
                                <div style="font-size:0.72rem;color:var(--gray-400);margin-top:4px;">Leave blank to auto-assign the next available slot.</div>
                            </div>
                        </div>
                        <div class="mt-4" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding-top:8px;border-top:var(--border);">
                            <button type="submit" class="btn btn-success" style="padding:10px 24px;font-weight:700;font-size:0.9rem;border-radius:10px;">
                                <i class="bi bi-person-check-fill"></i> Register Walk-in
                            </button>
                            <?php if ($next_label): ?>
                            <div style="display:flex;align-items:center;gap:7px;background:var(--primary-bg);border:1.5px solid var(--blue-200);border-radius:8px;padding:6px 14px;">
                                <i class="bi bi-arrow-right-circle-fill" style="color:var(--blue-500);"></i>
                                <span style="font-size:0.82rem;color:var(--gray-600);">Auto-slot: <strong style="color:var(--blue-600);"><?php echo $next_label; ?></strong></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
            </div><!-- /left col -->

            <!-- RIGHT: Today's slot timeline — always visible -->
            <div class="no-print">
                <div class="card walkin-sidebar-card" style="position:sticky;top:82px;border-radius:14px;overflow:hidden;">
                    <div class="card-header" style="background:var(--white);border-bottom:var(--border);padding:12px 16px;display:flex;align-items:center;gap:8px;">
                        <i class="bi bi-calendar-day" style="color:var(--blue-500);font-size:1rem;"></i>
                        <div>
                            <div style="font-weight:700;font-size:0.85rem;color:var(--gray-900);">Today — <?php echo date('D, M d'); ?></div>
                            <div style="font-size:0.68rem;color:var(--gray-400);">Schedule overview</div>
                        </div>
                    </div>
                    <div class="card-body" style="padding:14px 16px;">
                        <?php if (empty($slot_data['all_slots'])): ?>
                        <div style="text-align:center;padding:24px;color:var(--gray-400);font-size:0.82rem;">
                            <i class="bi bi-calendar-x" style="font-size:2rem;display:block;margin-bottom:8px;opacity:.5;"></i>
                            <?php echo e($slot_data['reason'] ?? 'No schedule today.'); ?>
                        </div>
                        <?php else: ?>

                        <!-- Stats row -->
                        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:14px;text-align:center;">
                            <div style="background:var(--gray-50);border-radius:10px;padding:10px 4px;border:1.5px solid var(--gray-100);">
                                <div style="font-family:var(--font-display);font-weight:800;font-size:1.4rem;color:var(--gray-700);"><?php echo $slot_data['total_slots']; ?></div>
                                <div style="font-size:0.62rem;color:var(--gray-400);text-transform:uppercase;letter-spacing:0.05em;font-weight:700;">Total</div>
                            </div>
                            <div style="background:var(--danger-bg);border-radius:10px;padding:10px 4px;border:1.5px solid var(--danger-border);">
                                <div style="font-family:var(--font-display);font-weight:800;font-size:1.4rem;color:var(--danger);"><?php echo $slot_data['booked_count']; ?></div>
                                <div style="font-size:0.62rem;color:var(--danger);text-transform:uppercase;letter-spacing:0.05em;font-weight:700;">Booked</div>
                            </div>
                            <div style="background:var(--success-bg);border-radius:10px;padding:10px 4px;border:1.5px solid var(--success-border);">
                                <div style="font-family:var(--font-display);font-weight:800;font-size:1.4rem;color:var(--success);"><?php echo max(0, $slot_data['total_slots'] - $slot_data['booked_count']); ?></div>
                                <div style="font-size:0.62rem;color:var(--success);text-transform:uppercase;letter-spacing:0.05em;font-weight:700;">Free</div>
                            </div>
                        </div>

                        <!-- Slot pills -->
                        <div class="slot-timeline" style="display:flex;flex-wrap:wrap;gap:6px;">
                        <?php foreach ($slot_data['all_slots'] as $s):
                            if ($s['past'] && $s['taken']) $cls = 'taken past';
                            elseif ($s['past'])              $cls = 'past';
                            elseif ($s['taken'])             $cls = 'booked';
                            elseif ($s['time'].':00' === $next_slot) $cls = 'next';
                            else                             $cls = 'free';
                        ?>
                        <span class="slot-pill <?php echo $cls; ?>" title="<?php
                            if ($s['taken']) echo 'Booked';
                            elseif ($s['past']) echo 'Past';
                            elseif ($cls === 'next') echo 'Next → will be auto-assigned';
                            else echo 'Available';
                        ?>">
                            <?php if ($cls === 'next'): ?><i class="bi bi-arrow-right-circle-fill" style="font-size:.7rem;"></i><?php elseif ($s['taken']): ?><i class="bi bi-x-circle-fill" style="font-size:.7rem;"></i><?php else: ?><i class="bi bi-circle" style="font-size:.7rem;"></i><?php endif; ?>
                            <?php echo $s['label']; ?>
                        </span>
                        <?php endforeach; ?>
                        </div>

                        <!-- Legend -->
                        <div style="margin-top:12px;font-size:0.68rem;color:var(--gray-400);display:flex;gap:10px;flex-wrap:wrap;padding-top:10px;border-top:var(--border);">
                            <span style="display:flex;align-items:center;gap:4px;"><span style="display:inline-block;width:8px;height:8px;background:var(--blue-500);border-radius:50%;"></span>Next</span>
                            <span style="display:flex;align-items:center;gap:4px;"><span style="display:inline-block;width:8px;height:8px;background:var(--success);border-radius:50%;"></span>Free</span>
                            <span style="display:flex;align-items:center;gap:4px;"><span style="display:inline-block;width:8px;height:8px;background:var(--danger);border-radius:50%;"></span>Booked</span>
                            <span style="display:flex;align-items:center;gap:4px;"><span style="display:inline-block;width:8px;height:8px;background:var(--gray-300);border-radius:50%;"></span>Past</span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div><!-- /right col -->

        </div><!-- /grid -->

    </div>

<script>
/* ── Standalone Walk-in Page — Returning Patient Search ─────────── */
var _ssDebounce = null;
var _ssSelected = false;

function standaloneSearchPatient(q) {
    clearTimeout(_ssDebounce);
    var results = document.getElementById('standalonePatientResults');
    var newBadge = document.getElementById('standaloneNewBadge');
    if (q.length < 2) {
        results.style.display = 'none';
        if (!_ssSelected) newBadge.style.display = 'none';
        return;
    }
    _ssDebounce = setTimeout(function () {
        fetch('<?php echo BASE_URL; ?>modules/walkin/add.php?action=search_patient&q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(function (res) {
                results.innerHTML = '';
                if (!res.patients || res.patients.length === 0) {
                    results.innerHTML = '<div style="padding:10px 14px;font-size:0.82rem;color:var(--gray-400);">No existing patient found — new record will be created.</div>';
                    results.style.display = 'block';
                    if (!_ssSelected) {
                        newBadge.style.display = 'block';
                        enableNameFields(true);
                    }
                    return;
                }
                res.patients.forEach(function (p) {
                    var fullName = p.first_name + ' ' + p.last_name;
                    var meta     = p.patient_code + (p.phone ? ' · ' + p.phone : '') + ' · ' + (p.appt_count || 0) + ' visit(s)';
                    var row = document.createElement('div');
                    row.style.cssText = 'padding:9px 14px;cursor:pointer;border-bottom:1px solid var(--gray-100);transition:background 0.1s;font-size:0.83rem;';
                    row.innerHTML = '<div style="font-weight:700;color:var(--gray-900);">' + fullName + '</div><div style="font-size:0.72rem;color:var(--gray-400);">' + meta + '</div>';
                    row.onmouseover = function () { this.style.background = 'var(--gray-50)'; };
                    row.onmouseout  = function () { this.style.background = ''; };
                    row.onclick = function () { standaloneSelectPatient(p); };
                    results.appendChild(row);
                });
                results.style.display = 'block';
                newBadge.style.display = 'none';
            })
            .catch(function () {
                results.style.display = 'none';
            });
    }, 280);
}

function standaloneSelectPatient(p) {
    _ssSelected = true;
    document.getElementById('standalone_existing_patient_id').value = p.id;
    document.getElementById('sf_first_name').value = p.first_name;
    document.getElementById('sf_last_name').value  = p.last_name;

    var fullName = p.first_name + ' ' + p.last_name;
    var meta     = p.patient_code + (p.phone ? ' · ' + p.phone : '') + ' · ' + (p.appt_count || 0) + ' visit(s)';
    document.getElementById('standaloneSelectedName').textContent = fullName;
    document.getElementById('standaloneSelectedMeta').textContent = meta;
    document.getElementById('standaloneSelectedPatient').style.display = 'flex';
    document.getElementById('standalonePatientResults').style.display  = 'none';
    document.getElementById('standaloneNewBadge').style.display        = 'none';
    document.getElementById('standalonePatientSearch').value           = '';

    // Lock the name fields — patient is selected
    enableNameFields(false);
}

function standaloneClearPatient() {
    _ssSelected = false;
    document.getElementById('standalone_existing_patient_id').value    = '';
    document.getElementById('standaloneSelectedPatient').style.display = 'none';
    document.getElementById('standaloneNewBadge').style.display        = 'none';
    document.getElementById('sf_first_name').value = '';
    document.getElementById('sf_last_name').value  = '';
    enableNameFields(true);
    document.getElementById('standalonePatientSearch').focus();
}

function enableNameFields(on) {
    var fields = ['sf_first_name', 'sf_last_name'];
    fields.forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.readOnly = !on;
        el.style.background = on ? '' : 'var(--gray-50)';
        el.style.color      = on ? '' : 'var(--gray-500)';
        el.required = on;
    });
}
</script>
</div>
<?php include '../../includes/footer.php'; ?>
</body>
</html> 