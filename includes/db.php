<?php
// db.php — Database connection and shared helper functions.
// Credentials are loaded from the .env file (never hardcoded here).
// Uses PDO with MySQL (modern replacement for the old mysqli calls —
// same prepared-statement safety, consistent ? placeholders everywhere).

// --- Load .env file -----------------------------------------------------------
function load_env($path) {
    if (!file_exists($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim($v);
        if (!array_key_exists($k, $_ENV)) { $_ENV[$k] = $v; putenv("$k=$v"); }
    }
}
load_env(__DIR__ . '/../.env');

// --- Connect to database (PDO + MySQL) -----------------------------------------
try {
    $db_host = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? 'localhost');
    $db_port = getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? '3306');
    $db_name = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'cap');
    $db_user = getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? 'root');
    $db_pass = getenv('DB_PASS') ?: ($_ENV['DB_PASS'] ?? '');

    $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";

    $conn = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

} catch (PDOException $e) {
    error_log('[DB ERROR] ' . $e->getMessage());
    $isApi = str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/');
    if ($isApi) {
        header('Content-Type: application/json');
        http_response_code(503);
        echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    } else {
        $db_host_safe = $_ENV['DB_HOST'] ?? 'localhost';
        $db_name_safe = $_ENV['DB_NAME'] ?? 'cap';
        $db_user_safe = $_ENV['DB_USER'] ?? 'root';
        http_response_code(503);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
        <title>Database Error</title>
        <style>
            body{font-family:sans-serif;background:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}
            .box{background:#fff;border-radius:12px;padding:40px;max-width:500px;width:100%;box-shadow:0 4px 24px rgba(0,0,0,0.10);border-top:4px solid #dc2626;}
            h2{color:#dc2626;margin:0 0 12px;}
            p{color:#475569;line-height:1.7;margin:0 0 10px;}
            code{background:#f1f5f9;padding:2px 8px;border-radius:4px;font-size:0.9em;color:#0f172a;}
            .step{background:#fafafa;border:1px solid #e2e8f0;border-radius:8px;padding:14px 18px;margin:10px 0;font-size:0.88em;color:#334155;}
            .step strong{display:block;margin-bottom:4px;color:#0f172a;}
        </style></head><body><div class="box">
        <h2>&#9888; Cannot connect to database</h2>
        <p>The system cannot reach MySQL. This is almost always one of these three things:</p>
        <div class="step"><strong>1. MySQL is not running</strong>
            Open Laragon (or XAMPP) and click <strong>Start</strong> next to MySQL.</div>
        <div class="step"><strong>2. Wrong credentials in .env</strong>
            Check your <code>.env</code> file — make sure these match your local setup:<br><br>
            <code>DB_HOST=localhost</code><br>
            <code>DB_PORT=3306</code><br>
            <code>DB_USER=root</code><br>
            <code>DB_PASS=</code> &nbsp;(blank by default in Laragon/XAMPP)<br>
            <code>DB_NAME=cap</code></div>
        <div class="step"><strong>3. Database not imported yet</strong>
            Open <strong>phpMyAdmin</strong> or <strong>HeidiSQL</strong> → create database <code>cap</code> → import <code>database/cap.sql</code></div>
        ' . (defined('APP_DEBUG') && APP_DEBUG ? '<p style="margin-top:16px;font-size:0.8em;color:#94a3b8;">Attempted: <code>' . htmlspecialchars($db_user_safe) . '@' . htmlspecialchars($db_host_safe) . '/' . htmlspecialchars($db_name_safe) . '</code></p>' : '') . '
        </div></body></html>';
    }
    exit();
}

// PDO compatibility shim: add a close() method so existing $stmt->close() calls work
// without errors (PDO statements don't have close(), but setting to null frees resources)
class CompatPDOStatement extends PDOStatement {
    public function close(): void { /* no-op — PDO frees on unset/reassign */ }
    public function get_result(): static { return $this; }
    public function num_rows(): int { return $this->rowCount(); }
    public function fetch_assoc(): ?array {
        $r = $this->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }
    public function fetch_all_assoc(): array { return $this->fetchAll(PDO::FETCH_ASSOC); }
}
$conn->setAttribute(PDO::ATTR_STATEMENT_CLASS, ['CompatPDOStatement']);

// ── Self-healing schema migration ─────────────────────────────────────────────
// Adds columns that may be missing in older database installs.
// Safe to run on every request — SHOW COLUMNS is fast and cached by MySQL.
(function (PDO $db) {
    try {
        $existing = [];
        $res = $db->query("SHOW COLUMNS FROM `dental_records`");
        if ($res) {
            foreach ($res->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $existing[$col['Field']] = true;
            }
        }
        // treatment_plan — added in v2 of the schema
        if (!isset($existing['treatment_plan'])) {
            $db->exec("ALTER TABLE `dental_records` ADD COLUMN `treatment_plan` TEXT DEFAULT NULL AFTER `treatment_done`");
        }
        // materials_used — own column so view can show it labeled separately
        if (!isset($existing['materials_used'])) {
            $db->exec("ALTER TABLE `dental_records` ADD COLUMN `materials_used` TEXT DEFAULT NULL AFTER `treatment_plan`");
            // If there are existing rows where materials were appended inside treatment_done,
            // leave them as-is (they'll still show). New saves will use the proper column.
        }
        // fee_charged — amount collected on this visit
        if (!isset($existing['fee_charged'])) {
            $db->exec("ALTER TABLE `dental_records` ADD COLUMN `fee_charged` DECIMAL(10,2) NULL DEFAULT NULL AFTER `visit_date`");
        }
        // profile_photo on users
        $res2 = $db->query("SHOW COLUMNS FROM `users` LIKE 'profile_photo'");
        if ($res2 && empty($res2->fetchAll())) {
            $db->exec("ALTER TABLE `users` ADD COLUMN `profile_photo` VARCHAR(500) DEFAULT NULL");
        }
    } catch (PDOException $e) {
        // Non-fatal — log and continue. The page will still load.
        error_log('[MIGRATION] ' . $e->getMessage());
    }
})($conn);
// ─────────────────────────────────────────────────────────────────────────────

// --- Helper functions ---------------------------------------------------------

// Return the real client IP, accounting for reverse proxies.
function get_client_ip(): string {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $xff    = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    $is_proxy = (
        $remote === '127.0.0.1' ||
        str_starts_with($remote, '10.')    ||
        str_starts_with($remote, '172.')   ||
        str_starts_with($remote, '192.168.')
    );
    if ($is_proxy && !empty($xff)) {
        $ip = trim(explode(',', $xff)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return $remote;
}

// Cast a GET/POST value to a safe positive integer
function secure_int($value) {
    $v = intval($value);
    return $v > 0 ? $v : 0;
}

// Safely echo user-supplied data in HTML
function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// Like e() but outputs an em-dash for empty/null instead of blank
// Use this wherever the fallback is '&mdash;' to avoid double-escaping
function em($value) {
    return ($value !== null && $value !== '') ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : '&mdash;';
}

// Check if an email address is valid
function valid_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}



// Validate a phone number (E.164 or PH local format)
function valid_phone($phone) {
    $phone = trim($phone);
    if (preg_match('/^\+[1-9]\d{6,14}$/', $phone)) return true;
    if (preg_match('/^09\d{9}$/', $phone)) return true;
    return false;
}

// Write an entry to the audit_logs table
function log_action($conn, $user_id, $user_name, $action, $module, $record_id = null, $details = '') {
    $ip   = get_client_ip();
    $stmt = $conn->prepare(
        "INSERT INTO audit_logs (user_id, user_name, action, module, record_id, details, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    if ($stmt) {
        $stmt->execute([$user_id, $user_name, $action, $module, $record_id, $details, $ip]);
    }
}

// ============================================================
// SECURITY #1 — CSRF PROTECTION
// ============================================================
function generate_csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    $token = generate_csrf_token();
    $safe  = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="_csrf" value="' . $safe . '">';
}

function validate_csrf(): void {
    $submitted = $_POST['_csrf'] ?? '';
    $expected  = $_SESSION['csrf_token'] ?? '';
    if (empty($submitted) || empty($expected) || !hash_equals($expected, $submitted)) {
        error_log('[CSRF] Token mismatch from IP: ' . get_client_ip() . ' | URI: ' . ($_SERVER['REQUEST_URI'] ?? ''));
        $isApi = str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')
              || !empty($_POST['_ajax'])
              || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
        if ($isApi) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Session expired. Please refresh and try again.']);
        } else {
            // Redirect back with a user-friendly error instead of the blank error page.
            // The redirect reloads the form with a fresh CSRF token so they can re-submit immediately.
            $ref  = $_SERVER['HTTP_REFERER'] ?? '';
            $base = defined('BASE_URL') ? BASE_URL : '';
            if ($ref && $base && str_starts_with($ref, $base)) {
                $sep = str_contains($ref, '?') ? '&' : '?';
                header('Location: ' . $ref . $sep . 'session_expired=1');
            } else {
                header('Location: ' . $base . 'dashboard.php?session_expired=1');
            }
        }
        exit();
    }
    // Token is intentionally kept the same across multiple form submissions on the same session.
    // The main security is: session ID regeneration on login (already preserving this token),
    // and the httponly + SameSite=Lax session cookie preventing CSRF from other origins.
}

// ============================================================
// NOTIFICATION TRIGGER FUNCTION
// ============================================================
function notify(PDO $conn, string $type, string $title, string $message, string $link = '', ?int $user_id = null): void {
    $allowed = ['appointment', 'payment', 'system', 'reminder'];
    if (!in_array($type, $allowed)) $type = 'system';
    $stmt = $conn->prepare(
        "INSERT INTO notifications (user_id, title, message, type, is_read, link)
         VALUES (?, ?, ?, ?, false, ?)"
    );
    if ($stmt) {
        $stmt->execute([$user_id, $title, $message, $type, $link]);
    }
}

// ============================================================
// OTP FUNCTIONS
// ============================================================
function generate_otp() {
    return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

// Send OTP via email.
// Primary:  Resend HTTP API — works on Railway/Heroku/Vercel where Gmail SMTP is blocked.
//           Google blocks SMTP connections originating from cloud IPs (GCP, AWS, etc.)
//           to prevent spam. Railway runs on GCP, so Gmail SMTP always fails there.
//           Resend uses HTTPS (not SMTP) so it is never blocked. Free tier: 100/day.
//           → Set RESEND_API_KEY in Railway env to enable. https://resend.com
// Fallback: PHPMailer + Gmail SMTP — used on local XAMPP/Laragon when Resend isn't set.
function send_otp_email($email, $otp, $name = '') {
    if (empty($email)) return false;

    $greeting = $name ?: 'User';

    $html_body =
        "<!DOCTYPE html><html><body style='font-family:sans-serif;max-width:480px;margin:auto;padding:32px;'>" .
        "<h2 style='color:#0d6e6e;'>DentalCare Verification</h2>" .
        "<p>Hello <strong>" . htmlspecialchars($greeting) . "</strong>,</p>" .
        "<p>Your verification code is:</p>" .
        "<div style='font-size:2rem;font-weight:700;letter-spacing:8px;color:#0d6e6e;" .
        "background:#f1f5f9;padding:16px 24px;border-radius:8px;display:inline-block;margin:8px 0;'>" .
        htmlspecialchars($otp) . "</div>" .
        "<p style='color:#64748b;font-size:0.9rem;'>This code expires in <strong>5 minutes</strong>." .
        " Do not share it with anyone.</p>" .
        "<hr style='border:none;border-top:1px solid #e2e8f0;margin:24px 0;'>" .
        "<p style='color:#94a3b8;font-size:0.8rem;'>DentalCare Clinic Management System</p>" .
        "</body></html>";

    $text_body = "Hello $greeting,\n\nYour DentalCare verification code is: $otp\n\nThis code expires in 5 minutes. Do not share it with anyone.\n\n- DentalCare System";

    // ── PRIMARY: Resend HTTP API ──────────────────────────────────────────────
    $resend_key     = getenv('RESEND_API_KEY')  ?: ($_ENV['RESEND_API_KEY']  ?? '');
    $mail_from      = getenv('MAIL_FROM')        ?: ($_ENV['MAIL_FROM']        ?? '');
    $mail_from_name = getenv('MAIL_FROM_NAME')   ?: ($_ENV['MAIL_FROM_NAME']   ?? (defined('APP_NAME') ? APP_NAME : 'DentalCare'));

    // Only use Resend if the key looks real (not the placeholder 're_xxx…')
    $resend_active = !empty($resend_key)
                  && strlen($resend_key) > 10
                  && strpos($resend_key, 'xxx') === false;

    if ($resend_active && function_exists('curl_init')) {
        // If MAIL_FROM is still the placeholder, fall back to Resend's test sender.
        // Note: 'onboarding@resend.dev' can only deliver to the email registered on resend.com.
        // For production (sending to patients), verify your own domain on resend.com
        // and set MAIL_FROM=noreply@yourdomain.com in Railway env.
        $is_placeholder_from = empty($mail_from)
                             || $mail_from === 'no-reply@yourdomain.com'
                             || strpos($mail_from, 'yourdomain') !== false;

        $from_address = $is_placeholder_from
            ? "DentalCare <onboarding@resend.dev>"
            : "{$mail_from_name} <{$mail_from}>";

        $payload = json_encode([
            'from'    => $from_address,
            'to'      => [$email],
            'subject' => 'Your DentalCare Verification Code',
            'html'    => $html_body,
            'text'    => $text_body,
        ]);

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $resend_key,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($http_code === 200 || $http_code === 201) {
            error_log('[MAIL] OTP sent via Resend → ' . $email);
            return true;
        }

        // Log and fall through to SMTP fallback
        error_log('[MAIL] Resend failed (HTTP ' . $http_code . '): ' . $response
            . ($curl_err ? ' | cURL: ' . $curl_err : ''));
    }

    // ── FALLBACK: PHPMailer + Gmail SMTP (local dev only) ────────────────────
    // This works on XAMPP/Laragon. On Railway it will fail because Google blocks
    // SMTP from GCP IP addresses. Use Resend above for cloud deployments.
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) {
        error_log('[MAIL] vendor/autoload.php not found — run: composer require phpmailer/phpmailer');
        return false;
    }
    require_once $autoload;

    // Resolve credentials — getenv() is unreliable on some Windows/Laragon setups,
    // so we fall back to $_ENV (which load_env() always populates).
    $smtp_user = (getenv('SMTP_USER') ?: ($_ENV['SMTP_USER'] ?? ''));
    // Gmail App Passwords are shown with spaces for readability — strip them for SMTP auth.
    $smtp_pass = str_replace(' ', '', (getenv('SMTP_PASS') ?: ($_ENV['SMTP_PASS'] ?? '')));

    if (empty($smtp_user) || empty($smtp_pass)) {
        error_log('[MAIL] SMTP credentials missing — SMTP_USER or SMTP_PASS not set in .env');
        return false;
    }

    $debug_log = __DIR__ . '/../logs/smtp_debug.log';
    $debug_buf = '';

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtp_user;
        $mail->Password   = $smtp_pass;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->Timeout    = 15;

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ];

        $mail->SMTPDebug  = 3;
        $mail->Debugoutput = function(string $str, int $level) use (&$debug_buf) {
            $debug_buf .= "[SMTP lvl$level] $str\n";
        };

        $mail->setFrom($smtp_user, APP_NAME);
        $mail->addAddress($email, $greeting);
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = 'Your DentalCare Verification Code';
        $mail->Body    = $html_body;
        $mail->AltBody = $text_body;

        $mail->send();
        error_log('[MAIL] OTP sent via SMTP → ' . $email . ' via ' . $smtp_user);
        return true;
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        $msg = '[MAIL] PHPMailer FAILED sending to ' . $email . ': ' . $e->getMessage();
        error_log($msg);
        if (!empty($debug_buf) && is_dir(dirname($debug_log))) {
            file_put_contents($debug_log, date('[Y-m-d H:i:s] ') . $msg . "\n" . $debug_buf . "\n", FILE_APPEND);
        }
        return false;
    }
}

// generate_code: race-condition-safe version.
// Uses SELECT MAX(id)+1 for pre-insert code generation, but the UNIQUE constraint
// on the code column acts as the final safety net — duplicate inserts will throw
// a PDOException that the caller can catch and retry.
// For true zero-collision safety in high-concurrency, use generate_code_after_insert().
function generate_code($conn, $table, $prefix) {
    $res = $conn->query("SELECT COALESCE(MAX(id), 0) + 1 as next_id FROM `$table`");
    $row = $res ? $res->fetch(PDO::FETCH_ASSOC) : null;
    $next = intval($row['next_id'] ?? 1);
    return $prefix . '-' . str_pad($next, 4, '0', STR_PAD_LEFT);
}

// generate_code_after_insert: zero-race-condition version.
// Call AFTER INSERT using lastInsertId(), then UPDATE the code column.
// Usage: generate_code_after_insert($conn, $lastId, 'APT') → 'APT-0042'
function generate_code_after_insert(int $id, string $prefix): string {
    return $prefix . '-' . str_pad($id, 4, '0', STR_PAD_LEFT);
}

// ============================================================
// SECURITY #2 — API RATE LIMITING
// ============================================================
function api_rate_limit($conn, string $endpoint, int $max_hits = 60, int $window_sec = 60): void {
    $ip  = get_client_ip();
    $now = date('Y-m-d H:i:s');

    try {
        $stmt = $conn->prepare(
            "SELECT id, hits, window_start FROM rate_limits WHERE ip_address = ? AND endpoint = ? LIMIT 1"
        );
        $stmt->execute([$ip, $endpoint]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $ins = $conn->prepare("INSERT INTO rate_limits (ip_address, endpoint, hits, window_start) VALUES (?,?,1,?)");
            $ins->execute([$ip, $endpoint, $now]);
            return;
        }

        $window_age = time() - strtotime($row['window_start']);

        if ($window_age > $window_sec) {
            $upd = $conn->prepare("UPDATE rate_limits SET hits = 1, window_start = ? WHERE id = ?");
            $upd->execute([$now, $row['id']]);
            return;
        }

        if ($row['hits'] >= $max_hits) {
            $retry_after = $window_sec - $window_age;
            http_response_code(429);
            header('Retry-After: ' . $retry_after);
            header('Content-Type: application/json');
            echo json_encode([
                'status'  => 'error',
                'message' => 'Too many requests. Please slow down.',
                'retry_after_seconds' => $retry_after,
            ]);
            exit();
        }

        $upd = $conn->prepare("UPDATE rate_limits SET hits = hits + 1 WHERE id = ?");
        $upd->execute([$row['id']]);
    } catch (PDOException $e) {
        // If rate-limit table is down, fail open rather than blocking all requests
        error_log('[RATE LIMIT] DB error: ' . $e->getMessage());
    }
}

// ============================================================
// SECURITY #3 — API TOKEN AUTHENTICATION
// ============================================================
function get_api_token_user($conn): ?array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($header, 'Bearer ')) return null;

    $raw_token = trim(substr($header, 7));
    if (empty($raw_token)) return null;

    $hash = hash('sha256', $raw_token);
    $now  = date('Y-m-d H:i:s');

    $stmt = $conn->prepare("
        SELECT t.id as token_id, t.user_id, u.full_name, u.username, u.role, u.is_active,
               t.expires_at, t.is_active as token_active
        FROM   api_tokens t
        JOIN   users u ON u.id = t.user_id
        WHERE  t.token_hash = ?
        AND    t.is_active = TRUE
        AND    u.is_active = TRUE
        AND    (t.expires_at IS NULL OR t.expires_at > ?)
        LIMIT  1
    ");
    $stmt->execute([$hash, $now]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $upd = $conn->prepare("UPDATE api_tokens SET last_used = ? WHERE id = ?");
        $upd->execute([$now, $user['token_id']]);
    }

    return $user ?: null;
}

// ============================================================
// SESSION CACHE HELPERS — reduce round trips for static data
// sc_get / sc_set cache DB results in $_SESSION for a given TTL.
// Usage:
//   $services = sc_get('services') ?? sc_set('services',
//       $conn->query("SELECT ...")->fetchAll(), 300);
// ============================================================
function sc_get(string $key): mixed {
    if (!isset($_SESSION['_sc'][$key])) return null;
    if (time() > $_SESSION['_sc'][$key]['exp']) {
        unset($_SESSION['_sc'][$key]);
        return null;
    }
    return $_SESSION['_sc'][$key]['d'];
}

function sc_set(string $key, mixed $data, int $ttl = 300): mixed {
    $_SESSION['_sc'][$key] = ['d' => $data, 'exp' => time() + $ttl];
    return $data;
}
