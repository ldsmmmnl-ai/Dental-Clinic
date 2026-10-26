<?php
// auth.php — Session guard for every protected page.
// Compatible with Laragon, XAMPP, and standard PHP setups.

// ── Step 1: ini_set BEFORE session_name and session_start ─────────────────
// These must come before any session function is called.
ini_set('session.cookie_httponly',  1);
ini_set('session.use_strict_mode',  1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime',   28800);
ini_set('session.cookie_lifetime',  0);
ini_set('session.cookie_path',     '/');
ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 1 : 0);

// ── Step 2: Set session name — must be IDENTICAL on every page ────────────
session_name('dcms_session');

// ── Step 3: Start the session only if not already active ──────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Step 4: Timeout check (8-hour idle limit) ─────────────────────────────
$lifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 28800;
if (isset($_SESSION['last_activity'])) {
    if ((time() - $_SESSION['last_activity']) > $lifetime) {
        session_unset();
        session_destroy();
        $base = defined('BASE_URL') ? BASE_URL : 'http://localhost/cap/';
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Location: ' . $base . 'index.php?timeout=1');
        exit();
    }
}

// ── Step 5: Authentication check ──────────────────────────────────────────
if (empty($_SESSION['user_id'])) {
    $base = defined('BASE_URL') ? BASE_URL : 'http://localhost/cap/';
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Location: ' . $base . 'index.php');
    exit();
}

// ── Step 5b: Make sure the account is STILL active ────────────────────────
// The session only remembers who logged in. If an admin turns the account off
// (or deletes it, or changes its role) afterwards, the old session must not keep
// working. We ask the database, but only once every 60 seconds so pages stay fast.
$recheck_every = 60;
if (empty($_SESSION['acct_checked_at']) || (time() - (int)$_SESSION['acct_checked_at']) >= $recheck_every) {
    $acct       = null;
    $query_done = false;
    if (isset($conn) && $conn instanceof PDO) {
        try {
            $acct_q = $conn->prepare("SELECT is_active, role FROM users WHERE id = ? LIMIT 1");
            $acct_q->execute([(int) $_SESSION['user_id']]);
            $acct       = $acct_q->fetch(PDO::FETCH_ASSOC);
            $query_done = true;
        } catch (Throwable $e) {
            // Database hiccup: do not lock everyone out. We try again on the next request.
            error_log('[AUTH] account re-check failed: ' . $e->getMessage());
        }
    }

    if ($query_done) {
        if (!$acct || (int) $acct['is_active'] !== 1) {
            // Account was turned off or deleted — end this session now.
            if (function_exists('log_action') && !empty($_SESSION['full_name'])) {
                log_action($conn, (int) $_SESSION['user_id'], $_SESSION['full_name'],
                    'Session Ended', 'auth', (int) $_SESSION['user_id'],
                    'Account is no longer active');
            }
            $is_ajax_call = str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')
                         || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
                         || !empty($_POST['_ajax']);
            session_unset();
            session_destroy();
            if ($is_ajax_call) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Your account is no longer active. Please log in again.']);
                exit();
            }
            $base = defined('BASE_URL') ? BASE_URL : 'http://localhost/cap/';
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Location: ' . $base . 'index.php?disabled=1');
            exit();
        }
        // Keep the role up to date (for example, if an admin was changed to staff).
        $_SESSION['role']            = $acct['role'];
        $_SESSION['acct_checked_at'] = time();
    }
}

// Stamp last_activity only after auth is confirmed — prevents unauthenticated
// sessions from self-renewing by refreshing the timestamp on every request.
$_SESSION['last_activity'] = time();

// ── Step 6: Expose session data ───────────────────────────────────────────
$current_user_id   = (int) $_SESSION['user_id'];
$current_user_name = $_SESSION['full_name'] ?? 'Unknown';
$current_user_role = $_SESSION['role']      ?? 'staff';

// ── Helpers ───────────────────────────────────────────────────────────────
function validate_password(string $pw): ?string {
    if (strlen($pw) < 8 || strlen($pw) > 18)
        return 'Password must be between 8 and 18 characters.';
    if (!preg_match('/[A-Z]/', $pw))
        return 'Password must contain at least one uppercase letter (A-Z).';
    if (!preg_match('/[a-z]/', $pw))
        return 'Password must contain at least one lowercase letter (a-z).';
    if (!preg_match('/[0-9]/', $pw))
        return 'Password must contain at least one number (0-9).';
    if (!preg_match('/[^A-Za-z0-9]/', $pw))
        return 'Password must contain at least one special character (e.g. @, #, $, !).';
    return null;
}

function is_admin(): bool {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function require_admin(): void {
    if (!is_admin()) {
        $base = defined('BASE_URL') ? BASE_URL : 'http://localhost/cap/';
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Location: ' . $base . 'dashboard.php');
        exit();
    }
}