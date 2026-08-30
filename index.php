<?php
// ============================================================
// index.php — Admin Login
// Features: Login attempt limiting, OTP password reset
// Uses PDO with MySQL
// ============================================================

ini_set('session.cookie_httponly',  1);
ini_set('session.use_strict_mode',  1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime',   28800);
ini_set('session.cookie_lifetime',  0);
ini_set('session.cookie_path',     '/');
session_name('dcms_session');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'includes/config.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}
 
require_once 'includes/db.php';

function validate_password(string $pw): ?string {
    if (strlen($pw) < 8 || strlen($pw) > 18)
        return 'Password must be between 8 and 18 characters.';
    if (!preg_match('/[A-Z]/', $pw))
        return 'Password must contain at least one uppercase letter (A–Z).';
    if (!preg_match('/[a-z]/', $pw))
        return 'Password must contain at least one lowercase letter (a–z).';
    if (!preg_match('/[0-9]/', $pw))
        return 'Password must contain at least one number (0–9).';
    if (!preg_match('/[^A-Za-z0-9]/', $pw))
        return 'Password must contain at least one special character (e.g. @, #, $, !).';
    return null;
}

define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_SECONDS',    300);
define('RESET_TOKEN_TTL',    600);
define('OTP_TTL',            300);
define('OTP_MAX_ATTEMPTS',     5);



$view  = $_GET['view'] ?? 'login';
$token = trim($_GET['token'] ?? '');

$error           = '';
$success         = '';
$remembered_user = htmlspecialchars($_COOKIE['dcms_remember_user'] ?? '');

// ============================================================
// VIEW: LOGIN 2FA — OTP verify after password is correct
// ============================================================
if ($view === 'login_otp') {

    // Must have a pending 2FA session
    if (empty($_SESSION['login_2fa_user_id'])) {
        header('Location: index.php'); exit();
    }

    $demo_otp_login = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $entered = trim($_POST['otp'] ?? '');

        $uid = (int)$_SESSION['login_2fa_user_id'];
        $now = date('Y-m-d H:i:s');

        $lu = $conn->prepare("SELECT id, full_name, username, role, otp_code, otp_expires FROM users WHERE id = ? AND is_active = TRUE LIMIT 1");
        $lu->execute([$uid]);
        $otp_user = $lu->fetch(PDO::FETCH_ASSOC);

        if (!$otp_user) {
            unset($_SESSION['login_2fa_user_id']);
            header('Location: index.php'); exit();
        }

        // ── DB-based OTP rate limiting (not session-based, so clearing cookies won't bypass it) ──
        $otp_ip  = get_client_ip();
        $otp_ep  = 'otp_verify:' . $uid;
        $now_str = date('Y-m-d H:i:s');

        $rl = $conn->prepare("SELECT id, hits, window_start FROM rate_limits WHERE ip_address = ? AND endpoint = ? LIMIT 1");
        $rl->execute([$otp_ip, $otp_ep]);
        $rl_row   = $rl->fetch(PDO::FETCH_ASSOC);

        if (!$rl_row) {
            $conn->prepare("INSERT INTO rate_limits (ip_address, endpoint, hits, window_start) VALUES (?,?,1,?)")->execute([$otp_ip, $otp_ep, $now_str]);
            $otp_hits = 1;
        } else {
            $window_age = time() - strtotime($rl_row['window_start']);
            if ($window_age > OTP_TTL) {
                $conn->prepare("UPDATE rate_limits SET hits = 1, window_start = ? WHERE id = ?")->execute([$now_str, $rl_row['id']]);
                $otp_hits = 1;
            } else {
                $conn->prepare("UPDATE rate_limits SET hits = hits + 1 WHERE id = ?")->execute([$rl_row['id']]);
                $otp_hits = $rl_row['hits'] + 1;
            }
        }

        if ($otp_hits > OTP_MAX_ATTEMPTS) {
            $conn->prepare("UPDATE users SET otp_code = NULL, otp_expires = NULL WHERE id = ?")->execute([$uid]);
            $conn->prepare("DELETE FROM rate_limits WHERE ip_address = ? AND endpoint = ?")->execute([$otp_ip, $otp_ep]);
            unset($_SESSION['login_2fa_user_id']);
            $error = 'Too many incorrect attempts. Please log in again.';
            $view  = 'login';
        } elseif (time() > strtotime($otp_user['otp_expires'] ?? '0')) {
            $conn->prepare("UPDATE users SET otp_code = NULL, otp_expires = NULL WHERE id = ?")->execute([$uid]);
            unset($_SESSION['login_2fa_user_id']);
            $error = 'OTP expired. Please log in again.';
            $view  = 'login';
        } elseif ($entered !== $otp_user['otp_code']) {
            $remaining = OTP_MAX_ATTEMPTS - $otp_hits;
            $error = "Incorrect code. $remaining attempt(s) remaining.";
        } else {
            // ✅ OTP correct — reset the rate limit and complete login
            $conn->prepare("DELETE FROM rate_limits WHERE ip_address = ? AND endpoint = ?")->execute([$otp_ip, $otp_ep]);
            $conn->prepare("UPDATE users SET otp_code = NULL, otp_expires = NULL WHERE id = ?")->execute([$uid]);
            unset($_SESSION['login_2fa_user_id']);

            // Regenerate session ID for security — but preserve the CSRF token so
            // forms opened after login don't get a "token mismatch" on first submit.
            $keep_csrf = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
            session_regenerate_id(true);
            $_SESSION['csrf_token']    = $keep_csrf;
            $_SESSION['user_id']       = $otp_user['id'];
            $_SESSION['full_name']     = $otp_user['full_name'];
            $_SESSION['username']      = $otp_user['username'];
            $_SESSION['role']          = $otp_user['role'];
            $_SESSION['last_activity'] = time();

            log_action($conn, $otp_user['id'], $otp_user['full_name'], 'Logged In (2FA)', 'auth', $otp_user['id'],
                'Login completed via email OTP from IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

            header('Location: dashboard.php'); exit();
        }
    }

    // Show OTP in demo mode (no keys configured)
    if ($view === 'login_otp' && !empty($_SESSION['login_2fa_user_id'])) {
        $no_keys = empty($_ENV['SEMAPHORE_API_KEY']) && empty($_ENV['RESEND_API_KEY']);
        if ($no_keys) {
            $uid = (int)$_SESSION['login_2fa_user_id'];
            $otp_row = $conn->prepare("SELECT otp_code FROM users WHERE id = ? LIMIT 1");
            $otp_row->execute([$uid]);
            $demo_otp_login = $otp_row->fetchColumn() ?: '';
        }
    }
}

// ============================================================
// VIEW: OTP VERIFY
// ============================================================
if ($view === 'otp_reset') {

    $otp_token   = trim($_GET['t'] ?? $_POST['t'] ?? '');
    $otp_user    = null;
    $demo_otp    = '';

    if (!empty($otp_token)) {
        $now_str = date('Y-m-d H:i:s');
        $lu = $conn->prepare(
            "SELECT id, full_name, otp_code, otp_expires
             FROM users
             WHERE reset_token = ? AND reset_expires > ? AND is_active = TRUE
             LIMIT 1"
        );
        if ($lu) {
            $lu->execute([$otp_token, $now_str]);
            $otp_user = $lu->fetch(PDO::FETCH_ASSOC);
        }
    }

    if (empty($otp_user) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: index.php?view=forgot'); exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['resend_otp'])) {
            header('Location: index.php?view=forgot'); exit();
        }

        $entered = trim($_POST['otp'] ?? '');

        if (empty($otp_user)) {
            $error = 'Invalid or expired link. Please request a new OTP.';
            $view  = 'forgot';
        } else {
            $otp_exp_ts = strtotime($otp_user['otp_expires'] ?? '0');
            $_SESSION['otp_reset_attempts'] = ($_SESSION['otp_reset_attempts'] ?? 0) + 1;

            if ($_SESSION['otp_reset_attempts'] > OTP_MAX_ATTEMPTS) {
                $conn->prepare("UPDATE users SET otp_code = NULL, otp_expires = NULL WHERE id = ?")->execute([(int)$otp_user['id']]);
                unset($_SESSION['otp_reset_attempts']);
                $error = 'Too many incorrect attempts. Please request a new OTP.';
                $view  = 'forgot';
            } elseif (time() > $otp_exp_ts) {
                $conn->prepare("UPDATE users SET otp_code = NULL, otp_expires = NULL WHERE id = ?")->execute([(int)$otp_user['id']]);
                unset($_SESSION['otp_reset_attempts']);
                $error = 'Your OTP has expired. Please request a new one.';
                $view  = 'forgot';
            } elseif ($entered !== $otp_user['otp_code']) {
                $remaining = OTP_MAX_ATTEMPTS - $_SESSION['otp_reset_attempts'];
                $error = "Incorrect OTP. $remaining attempt(s) remaining.";
            } else {
                $conn->prepare("UPDATE users SET otp_code = NULL, otp_expires = NULL WHERE id = ?")->execute([(int)$otp_user['id']]);
                unset($_SESSION['otp_reset_attempts']);
                header('Location: index.php?view=reset&token=' . urlencode($otp_token)); exit();
            }
        }
    }

    // Show OTP on-screen only if email delivery failed (send_otp_email returned false).
    // When SMTP is working, $otp_delivered = true and nothing is shown here.
    $otp_delivered = $_SESSION['otp_delivered'] ?? false;
    if (!$otp_delivered && !empty($otp_user['otp_code'])) {
        $demo_otp = $otp_user['otp_code'];
    }

// ============================================================
// VIEW: RESET PASSWORD
// ============================================================
} elseif ($view === 'reset') {

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token      = trim($_POST['token'] ?? '');
        $new_pass   = $_POST['new_password'] ?? '';
        $conf_pass  = $_POST['confirm_password'] ?? '';

        if (empty($token) || empty($new_pass)) {
            $error = 'All fields are required.';
        } elseif ($pw_err = validate_password($new_pass)) {
            $error = $pw_err;
        } elseif ($new_pass !== $conf_pass) {
            $error = 'Passwords do not match.';
        } else {
            $now  = date('Y-m-d H:i:s');
            $stmt = $conn->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_expires > ? AND is_active = TRUE LIMIT 1");
            $stmt->execute([$token, $now]);
            $user_row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user_row) {
                $error = 'This reset link has expired or is invalid. Please request a new one.';
            } else {
                $hashed = password_hash($new_pass, PASSWORD_BCRYPT);
                $upd    = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
                $upd->execute([$hashed, $user_row['id']]);
                log_action($conn, $user_row['id'], 'System', 'Password Reset', 'auth', $user_row['id'], 'Password was reset via OTP.');
                $success = 'Password updated successfully. You can now log in.';
                $view    = 'login';
            }
        }
    }

// ============================================================
// VIEW: FORGOT PASSWORD
// ============================================================
} elseif ($view === 'forgot') {

    // Self-healing migration: make sure these columns exist on `users`.
    // Safe to run on every "forgot password" view — checks first, only
    // ALTERs when a column is actually missing.
    $needed = ['reset_token', 'reset_expires', 'otp_code', 'otp_expires'];
    foreach ($needed as $col) {
        $chk = $conn->prepare(
            "SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = ? LIMIT 1"
        );
        $chk->execute([$col]);
        if (!$chk->fetch()) {
            // Add missing column — MySQL syntax
            $type_map = [
                'reset_token'   => 'VARCHAR(64) DEFAULT NULL',
                'reset_expires' => 'DATETIME DEFAULT NULL',
                'otp_code'      => 'VARCHAR(6) DEFAULT NULL',
                'otp_expires'   => 'DATETIME DEFAULT NULL',
            ];
            $conn->exec("ALTER TABLE users ADD COLUMN $col " . $type_map[$col]);
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $identifier = trim($_POST['identifier'] ?? '');

        if (empty($identifier)) {
            $error = 'Please enter your username or email.';
        } else {
            $stmt = $conn->prepare("SELECT id, full_name, email, phone FROM users WHERE (username = ? OR email = ?) AND is_active = TRUE LIMIT 1");
            $stmt->execute([$identifier, $identifier]);
            $user_row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user_row) {
                $token      = bin2hex(random_bytes(32));
                $expires    = date('Y-m-d H:i:s', time() + RESET_TOKEN_TTL);
                $otp        = generate_otp();
                $otp_exp    = date('Y-m-d H:i:s', time() + OTP_TTL);

                $upd = $conn->prepare(
                    "UPDATE users SET reset_token = ?, reset_expires = ?, otp_code = ?, otp_expires = ? WHERE id = ?"
                );
                $upd->execute([$token, $expires, $otp, $otp_exp, $user_row['id']]);

                // FIX: if stored email is blank, use the identifier the user typed (if it is a valid email)
                // and also write it back to the DB so future sends work automatically.
                $send_to = $user_row['email'] ?? '';
                if (empty(trim($send_to)) && filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
                    $send_to = trim($identifier);
                    $conn->prepare("UPDATE users SET email = ? WHERE id = ?")
                         ->execute([$send_to, $user_row['id']]);
                    $user_row['email'] = $send_to; // keep in sync for the log below
                }

                $email_ok = send_otp_email($send_to, $otp, $user_row['full_name']);
                $_SESSION['otp_delivered'] = $email_ok;

                log_action($conn, $user_row['id'], $user_row['full_name'], 'Password Reset OTP Sent', 'auth', $user_row['id'],
                    'OTP email ' . ($email_ok ? 'sent' : 'failed') . ' to ' . $send_to);
                header('Location: index.php?view=otp_reset&t=' . urlencode($token)); exit();
            }

            if (empty($error)) {
                header('Location: index.php?view=forgot&sent=1'); exit();
            }
        }
    }

// ============================================================
// VIEW: LOGIN (default)
// ============================================================
} else {

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = !empty($_POST['remember_me']);

        if (!empty($_POST['website'])) {
            error_log('[HONEYPOT] Bot detected from IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            sleep(3);
            exit();
        }


        api_rate_limit($conn, 'login', MAX_LOGIN_ATTEMPTS, LOCKOUT_SECONDS);

        $attempts  = $_SESSION['login_attempts']  ?? 0;
        $last_fail = $_SESSION['last_fail_time']  ?? 0;

        if (empty($error)) {
            if (empty($username) || empty($password)) {
                $error = 'Please enter your username and password.';
            } else {
                $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? AND is_active = TRUE LIMIT 1");
                $stmt->execute([$username]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && password_verify($password, $user['password'])) {
                    unset($_SESSION['login_attempts'], $_SESSION['last_fail_time']);

                    // Remember Me cookie (set before 2FA so it works after redirect)
                    if ($remember) {
                        setcookie('dcms_remember_user', $user['username'], [
                            'expires'  => time() + (30 * 24 * 60 * 60),
                            'path'     => '/',
                            'httponly' => true,
                            'samesite' => 'Lax',
                        ]);
                    } else {
                        setcookie('dcms_remember_user', '', ['expires' => time() - 3600, 'path' => '/']);
                    }

                    // ── Direct login — no 2FA wall on every login ──
                    $keep_csrf = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
                    session_regenerate_id(true);
                    $_SESSION['csrf_token']    = $keep_csrf;
                    $_SESSION['user_id']       = $user['id'];
                    $_SESSION['full_name']     = $user['full_name'];
                    $_SESSION['username']      = $user['username'];
                    $_SESSION['role']          = $user['role'];
                    $_SESSION['last_activity'] = time();

                    log_action($conn, $user['id'], $user['full_name'], 'Logged In', 'auth', $user['id'],
                        'Login from IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

                    header('Location: dashboard.php'); exit();
                } else {
                    sleep(2);
                    $_SESSION['login_attempts'] = $attempts + 1;
                    $_SESSION['last_fail_time'] = time();
                    $remaining = MAX_LOGIN_ATTEMPTS - $_SESSION['login_attempts'];
                    $error = $remaining > 0
                        ? "Invalid username or password. {$remaining} attempt(s) remaining."
                        : 'Too many failed attempts. Account temporarily locked for 5 minutes.';
                }
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?php
        $titles = ['forgot'=>'Forgot Password','reset'=>'Reset Password','otp_reset'=>'Verify OTP'];
        echo htmlspecialchars($titles[$view] ?? 'Login') . ' | ' . APP_NAME;
        ?>
    </title>
    <link rel="icon"             type="image/x-icon"      href="<?php echo BASE_URL; ?>assets/images/favicon.ico">
    <link rel="icon"             type="image/svg+xml"      href="<?php echo BASE_URL; ?>assets/images/favicon.svg">
    <link rel="icon"             type="image/png" sizes="32x32" href="<?php echo BASE_URL; ?>assets/images/favicon-32.png">
    <link rel="icon"             type="image/png" sizes="16x16" href="<?php echo BASE_URL; ?>assets/images/favicon-16.png">
    <link rel="apple-touch-icon" sizes="180x180"           href="<?php echo BASE_URL; ?>assets/images/favicon.svg">
    <meta name="theme-color" content="#2563eb">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css">

    <script>
    (function(){
        if (localStorage.getItem('theme') === 'dark') {
            document.documentElement.setAttribute('data-theme','dark');
            document.documentElement.setAttribute('data-bs-theme','dark');
        }
    })();
    </script>
    <style>
    /* ── LOGIN PAGE ─────────────────────────────────────────── */
    body.login-page {
        display: flex; align-items: center; justify-content: center;
        min-height: 100vh; margin-left: 0;
        background-color: #1e3a8a;
        background-image:
            linear-gradient(135deg, rgba(15,23,42,0.72) 0%, rgba(30,64,175,0.65) 100%),
            url('assets/images/cap.jpg');
        background-size: cover;
        background-position: center;
        background-attachment: fixed;
        position: relative;
        overflow: hidden;
        padding: 16px;
    }

    /* Plain centered card */
    .login-wrap {
        width: 100%;
        max-width: 400px;
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.22);
        padding: 44px 40px 36px;
        animation: fadeUp 0.5s var(--ease-spring, ease);
        position: relative; z-index: 1;
    }
    @keyframes fadeUp {
        from { opacity:0; transform:translateY(22px) scale(0.97); }
        to   { opacity:1; transform:translateY(0) scale(1); }
    }
    /* Small staggered cascade for the brand + heading — card arrives,
       then the icon pops in, then the heading rises in behind it. */
    @keyframes popIn {
        from { opacity:0; transform:scale(0.4); }
        to   { opacity:1; transform:scale(1); }
    }
    @keyframes riseIn {
        from { opacity:0; transform:translateY(8px); }
        to   { opacity:1; transform:translateY(0); }
    }

    /* Icon + brand at top */
    .login-brand {
        display: flex; flex-direction: column; align-items: center;
        margin-bottom: 28px;
    }
    .login-brand-icon {
        width: 72px; height: 72px;
        background: linear-gradient(145deg, #2563eb 0%, #1d4ed8 100%);
        border-radius: 20px;
        display: flex; align-items: center; justify-content: center;
        margin-bottom: 14px;
        box-shadow: 0 4px 14px rgba(37,99,235,0.35);
        animation: popIn 0.45s var(--ease-spring, ease) 0.1s both;
    }
    .login-brand-icon svg {
        width: 40px; height: 40px;
    }
    .login-brand-sub {
        font-size: 0.7rem; color: #9ca3af; margin-top: 2px;
        text-transform: uppercase; letter-spacing: 0.1em;
        animation: riseIn 0.35s ease-out 0.3s both;
    }

    .form-heading  { font-size: 1rem; font-weight: 600; color: #374151; margin-bottom: 20px; text-align: center; animation: riseIn 0.35s ease-out 0.22s both; }
    .form-subheading { font-size: 0.8rem; color: #9ca3af; margin-bottom: 22px; text-align: center; }

    /* Remember me row */
    .remember-row {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 20px; font-size: 0.82rem;
    }
    .remember-row label {
        display: flex; align-items: center; gap: 7px;
        color: #4b5563; cursor: pointer; user-select: none;
        font-weight: 400; margin: 0;
    }
    .remember-row input[type="checkbox"] {
        width: 15px; height: 15px; accent-color: #2563eb;
        cursor: pointer; flex-shrink: 0;
    }
    .remember-row a { color: #2563eb; text-decoration: none; font-size: 0.82rem; }
    .remember-row a:hover { text-decoration: underline; }

    @media (max-width: 480px) {
        .login-wrap { padding: 32px 22px 28px; }
    }

    /* ── DARK MODE ──────────────────────────────────────────── */
    [data-theme="dark"] body.login-page {
        background-color: #0f172a;
        background-image:
            linear-gradient(135deg, rgba(5,8,20,0.55) 0%, rgba(10,20,50,0.50) 100%),
            url('assets/images/cap.jpg');
        background-size: cover;
        background-position: center;
        background-attachment: fixed;
    }
    [data-theme="dark"] .login-wrap        { background: #1e293b; }
    [data-theme="dark"] .login-brand-icon  { background: linear-gradient(145deg, #1e40af 0%, #1e3a8a 100%); }
    [data-theme="dark"] .form-heading      { color: #e2e8f0; }
    [data-theme="dark"] .remember-row label { color: #94a3b8; }
    </style>
</head>
<body class="login-page">

<div class="login-wrap">

    <!-- BRAND HEADER -->
    <div class="login-brand">
        <div class="login-brand-icon">
            <!-- Clean tooth SVG -->
            <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M22 8C17 8 10 12 10 22C10 28 11 33 13 38C15 43 17 52 20 54C21.5 55 23 54 24 52C25.5 49 26 44 28 42C29 41 30 41 32 41C34 41 35 41 36 42C38 44 38.5 49 40 52C41 54 42.5 55 44 54C47 52 49 43 51 38C53 33 54 28 54 22C54 12 47 8 42 8C38 8 35 10 32 11C29 10 26 8 22 8Z" fill="white" opacity="0.95"/>
                <path d="M28 42C28 38 29.5 35 32 35C34.5 35 36 38 36 42" stroke="rgba(37,99,235,0.3)" stroke-width="1.5" stroke-linecap="round" fill="none"/>
            </svg>
        </div>
        <div class="login-brand-sub"></div>
    </div>

    <!-- FORM AREA -->

    <?php if ($view === 'otp_reset'): ?>
    <div class="form-heading"><i class="bi bi-shield-lock" style="color:#1d4ed8;"></i> Verify OTP</div>
    <div class="form-subheading">Enter the 6-digit code sent to your email</div>
    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if (!empty($demo_otp)): ?>
    <div style="background:#fefce8;border:2px dashed #f59e0b;border-radius:12px;padding:14px 18px;margin-bottom:18px;text-align:center;">
        <div style="font-size:0.72rem;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:6px;">
            <i class="bi bi-exclamation-triangle-fill"></i> Email delivery failed — code shown here
        </div>
        <div style="font-size:0.8rem;color:#78350f;margin-bottom:8px;">Check your SMTP settings in .env — your one-time code is:</div>
        <div style="font-size:2rem;font-weight:800;letter-spacing:0.45em;color:#1d4ed8;
                    background:#eff6ff;padding:10px 20px;border-radius:8px;display:inline-block;">
            <?php echo htmlspecialchars($demo_otp); ?>
        </div>
        <div style="font-size:0.72rem;color:#92400e;margin-top:8px;">
            <i class="bi bi-clock"></i> Expires in 5 minutes &nbsp;·&nbsp; Enter this code below.
        </div>
    </div>
    <?php endif; ?>
    <form method="POST" action="index.php?view=otp_reset">
        <input type="hidden" name="t" value="<?php echo htmlspecialchars($otp_token ?? ''); ?>">
        <div style="margin-bottom:20px;">
            <label class="form-label">Verification Code</label>
            <input type="text" name="otp" class="form-control"
                   maxlength="6" placeholder="• • • • • •"
                   style="text-align:center;font-size:1.8rem;font-weight:700;letter-spacing:0.4em;padding:12px;"
                   required autofocus autocomplete="one-time-code">
            <div style="font-size:0.75rem;color:#9ca3af;margin-top:6px;text-align:center;">
                <i class="bi bi-clock"></i> Code expires in 5 minutes
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-lg" style="width:100%;justify-content:center;margin-bottom:12px;">
            <i class="bi bi-shield-check"></i> Verify Code
        </button>
    </form>
    <div style="display:flex;gap:12px;margin-top:4px;">
        <a href="index.php" style="flex:1;text-align:center;font-size:0.82rem;color:#6b7280;text-decoration:none;padding:8px;border:1px solid #e5e7eb;border-radius:8px;display:block;">
            <i class="bi bi-arrow-left"></i> Back to Login
        </a>
        <a href="index.php?view=forgot" style="flex:1;text-align:center;font-size:0.82rem;color:#1d4ed8;text-decoration:none;padding:8px;border:1px solid #dbeafe;border-radius:8px;display:block;background:#eff6ff;">
            <i class="bi bi-arrow-repeat"></i> Request New OTP
        </a>
    </div>

    <?php elseif ($view === 'login_otp'): ?>
    <div class="form-heading"><i class="bi bi-shield-lock-fill" style="color:#1d4ed8;"></i> Two-Factor Verification</div>
    <div class="form-subheading">A 6-digit code was sent to your email and phone</div>
    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if (!empty($demo_otp_login)): ?>
    <div style="background:#fefce8;border:2px dashed #f59e0b;border-radius:12px;padding:14px 18px;margin-bottom:18px;text-align:center;">
        <div style="font-size:0.72rem;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:6px;">
            <i class="bi bi-info-circle-fill"></i> Demo Mode — No Email/SMS Configured
        </div>
        <div style="font-size:0.8rem;color:#78350f;margin-bottom:8px;">Your one-time login code:</div>
        <div style="font-size:2rem;font-weight:800;letter-spacing:0.45em;color:#1d4ed8;background:#eff6ff;padding:10px 20px;border-radius:8px;display:inline-block;">
            <?php echo htmlspecialchars($demo_otp_login); ?>
        </div>
        <div style="font-size:0.72rem;color:#92400e;margin-top:8px;">
            <i class="bi bi-clock"></i> Expires in 5 minutes
        </div>
    </div>
    <?php endif; ?>
    <form method="POST" action="index.php?view=login_otp">
        <div style="margin-bottom:20px;">
            <label class="form-label">Verification Code</label>
            <input type="text" name="otp" class="form-control"
                   maxlength="6" placeholder="• • • • • •"
                   style="text-align:center;font-size:1.8rem;font-weight:700;letter-spacing:0.4em;padding:12px;"
                   required autofocus autocomplete="one-time-code" inputmode="numeric">
            <div style="font-size:0.75rem;color:#9ca3af;margin-top:6px;text-align:center;">
                <i class="bi bi-clock"></i> Code expires in 5 minutes
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-lg" style="width:100%;justify-content:center;margin-bottom:12px;">
            <i class="bi bi-shield-check"></i> Verify &amp; Sign In
        </button>
    </form>
    <div style="text-align:center;margin-top:8px;">
        <a href="index.php" style="font-size:0.82rem;color:#6b7280;">
            <i class="bi bi-arrow-left"></i> Cancel &amp; go back to login
        </a>
    </div>

    <?php elseif ($view === 'forgot'): ?>
    <div class="form-heading"><i class="bi bi-key" style="color:#1d4ed8;"></i> Forgot Password</div>
    <div class="form-subheading">Enter your username or email to receive an OTP</div>
    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if (!empty($_GET['sent'])): ?>
        <div class="alert alert-info"><i class="bi bi-info-circle"></i> If that account exists, an OTP has been sent.</div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> <?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <form method="POST" action="index.php?view=forgot">
        <div style="margin-bottom:18px;">
            <label class="form-label">Username or Email</label>
            <div style="position:relative;">
                <i class="bi bi-person" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af;"></i>
                <input type="text" name="identifier" class="form-control"
                    placeholder="Enter your username or email"
                    style="padding-left:36px;" required autofocus>
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-lg" style="width:100%;justify-content:center;">
            <i class="bi bi-send"></i> Send OTP
        </button>
    </form>
    <p style="text-align:center;margin-top:18px;">
        <a href="index.php" style="font-size:0.82rem;color:#6b7280;">
            <i class="bi bi-arrow-left"></i> Back to Login
        </a>
    </p>

    <?php elseif ($view === 'reset'): ?>
    <div class="form-heading"><i class="bi bi-lock-fill" style="color:#1d4ed8;"></i> Set New Password</div>
    <div class="form-subheading">Choose a strong new password for your account</div>
    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php
    $now = date('Y-m-d H:i:s');
    $chk = $conn->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_expires > ? AND is_active = TRUE LIMIT 1");
    $chk->execute([$token, $now]);
    $token_valid = (bool)$chk->fetch(PDO::FETCH_ASSOC);
    $chk = null;
    ?>
    <?php if (!$token_valid && !$error): ?>
        <div class="alert alert-danger">
            <i class="bi bi-x-circle-fill"></i>
            This reset link is <strong>invalid or has expired</strong>.
            <a href="index.php?view=forgot" style="color:var(--danger);font-weight:600;">Request a new one</a>.
        </div>
    <?php else: ?>
    <form method="POST" action="index.php?view=reset">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
        <div style="margin-bottom:14px;">
            <label class="form-label">New Password</label>
            <div style="position:relative;">
                <i class="bi bi-lock" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af;"></i>
                <input type="password" name="new_password" id="newPassInput" class="form-control"
                       placeholder="Enter new password" style="padding-left:36px;" required minlength="8" autofocus>
                <i class="bi bi-eye" id="toggleNew" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);color:#9ca3af;cursor:pointer;"></i>
            </div>
        </div>
        <div style="margin-bottom:22px;">
            <label class="form-label">Confirm New Password</label>
            <div style="position:relative;">
                <i class="bi bi-lock-fill" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af;"></i>
                <input type="password" name="confirm_password" id="confPassInput" class="form-control"
                       placeholder="Repeat new password" style="padding-left:36px;" required>
            </div>
            <div id="matchMsg" style="font-size:0.75rem;margin-top:4px;display:none;"></div>
        </div>
        <button type="submit" class="btn btn-primary btn-lg" style="width:100%;justify-content:center;">
            <i class="bi bi-shield-lock-fill"></i> Set New Password
        </button>
    </form>
    <?php endif; ?>
    <p style="text-align:center;margin-top:16px;">
        <a href="index.php" style="font-size:0.82rem;color:#6b7280;">
            <i class="bi bi-arrow-left"></i> Back to Login
        </a>
    </p>

    <?php else: ?>
    <!-- LOGIN VIEW -->
    <div class="form-heading">Sign in to your account</div>
    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> <?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['timeout'])): ?>
        <div class="alert alert-warning"><i class="bi bi-clock"></i> Your session expired. Please log in again.</div>
    <?php endif; ?>
    <?php
    $attempts  = $_SESSION['login_attempts']  ?? 0;
    $last_fail = $_SESSION['last_fail_time']  ?? 0;
    $locked    = ($attempts >= MAX_LOGIN_ATTEMPTS) && ((time() - $last_fail) < LOCKOUT_SECONDS);
    ?>
    <?php if ($locked): ?>
        <?php $wait_min = ceil((LOCKOUT_SECONDS - (time() - $last_fail)) / 60); ?>
        <div class="alert alert-warning">
            <i class="bi bi-shield-exclamation"></i>
            Account locked. Wait <strong><?php echo $wait_min; ?> minute(s)</strong>.
        </div>
    <?php endif; ?>

    <form method="POST" action="index.php" <?php echo $locked ? 'style="opacity:0.5;pointer-events:none;"' : ''; ?>>
        <!-- Honeypot — invisible to humans, bots fill it -->
        <div style="display:none;visibility:hidden;position:absolute;left:-9999px;" aria-hidden="true">
            <label for="website">Leave this empty</label>
            <input type="text" name="website" id="website" tabindex="-1" autocomplete="off">
        </div>

        <div style="margin-bottom:14px;">
            <label class="form-label">Username</label>
            <div style="position:relative;">
                <i class="bi bi-person" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af;"></i>
                <input type="text" name="username" class="form-control"
                    placeholder="Enter username" style="padding-left:36px;"
                    required autofocus
                    value="<?php echo $remembered_user ?: htmlspecialchars($_POST['username'] ?? ''); ?>">
            </div>
        </div>

        <div style="margin-bottom:14px;">
            <label class="form-label">Password</label>
            <div style="position:relative;">
                <i class="bi bi-lock" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af;"></i>
                <input type="password" name="password" id="passwordInput" class="form-control"
                    placeholder="Enter password" style="padding-left:36px;" required>
                <i class="bi bi-eye" id="togglePw"
                style="position:absolute;right:12px;top:50%;transform:translateY(-50%);color:#9ca3af;cursor:pointer;"></i>
            </div>
        </div>

        <!-- Remember me + Forgot password row -->
        <div class="remember-row">
            <label>
                <input type="checkbox" name="remember_me" value="1"
                    <?php echo (isset($_POST['remember_me']) || !empty($_COOKIE['dcms_remember_user'])) ? 'checked' : ''; ?>>
                Remember me
            </label>
            <a href="index.php?view=forgot">Forgot password?</a>
        </div>

        <button type="submit" class="btn btn-primary btn-lg" style="width:100%;justify-content:center;">
            <i class="bi bi-box-arrow-in-right"></i> Sign In
        </button>
    </form>

    <?php endif; ?>

    <p style="text-align:center;font-size:0.72rem;color:#9ca3af;margin-top:24px;">
        <?php echo APP_NAME; ?> &copy; <?php echo date('Y'); ?>
    </p>

</div><!-- end .login-wrap -->

<script>
var togPw = document.getElementById('togglePw');
if (togPw) togPw.addEventListener('click', function() {
    var inp = document.getElementById('passwordInput');
    var txt = inp.type === 'text';
    inp.type = txt ? 'password' : 'text';
    this.className = txt ? 'bi bi-eye' : 'bi bi-eye-slash';
    this.style.cssText = 'position:absolute;right:12px;top:50%;transform:translateY(-50%);color:#9ca3af;cursor:pointer;';
});

var togNew = document.getElementById('toggleNew');
if (togNew) togNew.addEventListener('click', function() {
    var inp = document.getElementById('newPassInput');
    var txt = inp.type === 'text';
    inp.type = txt ? 'password' : 'text';
    this.className = txt ? 'bi bi-eye' : 'bi bi-eye-slash';
    this.style.cssText = 'position:absolute;right:12px;top:50%;transform:translateY(-50%);color:#9ca3af;cursor:pointer;';
});

var confPass = document.getElementById('confPassInput');
var newPass  = document.getElementById('newPassInput');
var matchMsg = document.getElementById('matchMsg');
if (confPass && newPass && matchMsg) {
    function checkMatch() {
        var match = newPass.value === confPass.value;
        matchMsg.style.display = confPass.value ? 'block' : 'none';
        matchMsg.textContent   = match ? '✓ Passwords match' : '✗ Passwords do not match';
        matchMsg.style.color   = match ? 'var(--success)' : 'var(--danger)';
        matchMsg.style.fontWeight = '600';
    }
    confPass.addEventListener('input', checkMatch);
    newPass.addEventListener('input', checkMatch);
}
</script>
</body>
</html>