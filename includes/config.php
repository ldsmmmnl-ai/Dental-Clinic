<?php
// config.php — Loaded first on every page.
// Sets app constants, hides errors from browser (logs them silently),
// catches uncaught exceptions, and adds security HTTP headers.

// BASE_URL and APP_NAME come from .env — change them there, not here
// db.php loads .env before config.php constants are defined,
// so we re-read the env file here just for these two values.
(function() {
    $envPath = __DIR__ . '/../.env';
    if (file_exists($envPath)) {
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            if (!array_key_exists(trim($k), $_ENV)) {
                $_ENV[trim($k)] = trim($v);
                putenv(trim($k) . '=' . trim($v));
            }
        }
    }
})();

// ── Dynamic BASE_URL — works on XAMPP (/cap/), PHP built-in server (:8000/), or any host ──
// Auto-detects the app root from the current request so sidebar/header links
// NEVER send users back to the login page just because the .env BASE_URL has the
// wrong port or path for the current server setup.
if (!empty($_SERVER['HTTP_HOST'])) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https'
        : ((!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https'
        : ((!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') ? 'https'
        : ((!empty($_SERVER['HTTP_FORWARDED']) && str_contains($_SERVER['HTTP_FORWARDED'], 'proto=https')) ? 'https'
        : 'http')));
    $host    = $_SERVER['HTTP_HOST']; // includes :port if non-standard
    // Sanitise HTTP_HOST to prevent Host Header Injection — only allow safe hostname chars
    // and an optional port number. Fall back to .env value if the header looks malicious.
    if (!preg_match('/^[a-zA-Z0-9.\-]+(:\d+)?$/', $host)) {
        $host = parse_url($_ENV['BASE_URL'] ?? 'http://localhost/cap/', PHP_URL_HOST) ?: 'localhost';
    }
    $script  = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    // Find the app root by stripping known sub-directory segments
    $knownSubs = ['/modules/', '/includes/', '/assets/', '/api/', '/database/', '/logs/'];
    $root = $script;
    foreach ($knownSubs as $sub) {
        $pos = strpos($root, $sub);
        if ($pos !== false) { $root = substr($root, 0, $pos); break; }
    }
    if (substr($root, -4) === '.php') $root = rtrim(dirname($root), '/');
    $root = rtrim($root, '/') . '/';
    define('BASE_URL', $scheme . '://' . $host . $root);
} else {
    define('BASE_URL', rtrim($_ENV['BASE_URL'] ?? 'http://localhost/cap/', '/') . '/');
}
define('APP_NAME',        $_ENV['APP_NAME']       ?? 'Dental Clinic Management System');
define('APP_DEBUG',       ($_ENV['APP_DEBUG']      ?? 'false') === 'true');
define('SESSION_LIFETIME', 28800); // 8 hours

date_default_timezone_set('Asia/Manila');

// Create logs directory before pointing error_log at it —
// otherwise the very first error_log() call would silently fail.
if (!is_dir(__DIR__ . '/../logs')) {
    mkdir(__DIR__ . '/../logs', 0755, true);
}

// Hide PHP errors from visitors in production; show them in debug mode
ini_set('display_errors',         APP_DEBUG ? 1 : 0);
ini_set('display_startup_errors', APP_DEBUG ? 1 : 0);
ini_set('log_errors', 1);
// On Railway the container disk is wiped on every deploy and logs/error.log never shows in the Deploy Logs,
// so send errors to stderr there. Locally (Laragon/XAMPP) keep writing logs/error.log as before.
$__on_railway = getenv('RAILWAY_PROJECT_ID') !== false || getenv('RAILWAY_ENVIRONMENT_NAME') !== false || getenv('RAILWAY_ENVIRONMENT') !== false;
ini_set('error_log', ($__on_railway && is_writable('/dev/stderr')) ? '/dev/stderr' : __DIR__ . '/../logs/error.log');
unset($__on_railway);
error_reporting(E_ALL);

// Catch any unhandled exception — show friendly error page, never a stack trace
set_exception_handler(function ($e) {
    error_log('[EXCEPTION] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $uri   = $_SERVER['REQUEST_URI'] ?? '';
    $isApi = str_contains($uri, '/api/')
          || (str_contains($uri, '/modules/walkin/add.php')    && isset($_GET['action']))
          || (str_contains($uri, '/modules/appointments/')     && isset($_GET['action']))
          || (str_contains($uri, '/modules/schedule/manage.php') && isset($_GET['action']))
          || !empty($_POST['_ajax'])
          || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
    if (!headers_sent()) {
        http_response_code(500);
        if ($isApi) {
            header('Content-Type: application/json');
        }
    }
    if ($isApi) {
        echo json_encode(['status' => 'error', 'message' => 'A server error occurred.']);
    } else {
        if (defined('APP_DEBUG') && APP_DEBUG) {
            echo '<pre style="background:#1e1e2e;color:#f38ba8;padding:20px;">';
            echo htmlspecialchars($e->getMessage() . "\nFile: " . $e->getFile() . ':' . $e->getLine() . "\n\n" . $e->getTraceAsString());
            echo '</pre>';
        } else { include dirname(__DIR__) . '/error.php'; }
    }
    exit();
});

// Same for fatal errors (parse errors, out-of-memory, etc.)
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log('[FATAL] ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
        if (!headers_sent()) {
            $uri   = $_SERVER['REQUEST_URI'] ?? '';
            $isApi = str_contains($uri, '/api/')
                  || (str_contains($uri, '/modules/walkin/add.php')     && isset($_GET['action']))
                  || (str_contains($uri, '/modules/appointments/')      && isset($_GET['action']))
                  || (str_contains($uri, '/modules/schedule/manage.php') && isset($_GET['action']))
                  || !empty($_POST['_ajax'])
                  || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
            http_response_code(500);
            if ($isApi) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'A server error occurred.']);
            } else {
                include dirname(__DIR__) . '/error.php';
            }
        }
    }
});

// Security headers — sent on every response
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; " .
    "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; " .
    "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com https://api.fontshare.com; " .
    "font-src 'self' data: https://cdn.jsdelivr.net https://fonts.gstatic.com https://api.fontshare.com https://cdn.fontshare.com; " .
    "img-src 'self' data: blob:; " .
    "connect-src 'self';");
