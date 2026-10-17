<?php
$title = ($page_title ?? 'Page') . ' | ' . APP_NAME;
$_script = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
$_app_root = '';
foreach (['/modules/', '/includes/', '/api/'] as $_sub) {
    $_pos = strpos($_script, $_sub);
    if ($_pos !== false) { $_app_root = substr($_script, 0, $_pos); break; }
}
if (!$_app_root) $_app_root = dirname($_script);
$_app_root = rtrim($_app_root, '/');
$_bootstrap_css_local   = file_exists($_app_root . '/assets/css/bootstrap.min.css');
$_bootstrap_js_local    = file_exists($_app_root . '/assets/js/bootstrap.bundle.min.js');
$_bootstrap_icons_local = file_exists($_app_root . '/assets/css/bootstrap-icons.css');
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $title; ?></title>

<!-- Favicon -->
<link rel="icon"             type="image/x-icon"     href="<?php echo BASE_URL; ?>assets/images/favicon.ico">
<link rel="icon"             type="image/svg+xml"     href="<?php echo BASE_URL; ?>assets/images/favicon.svg">
<link rel="icon"             type="image/png" sizes="32x32" href="<?php echo BASE_URL; ?>assets/images/favicon-32.png">
<link rel="icon"             type="image/png" sizes="16x16" href="<?php echo BASE_URL; ?>assets/images/favicon-16.png">
<link rel="apple-touch-icon" sizes="180x180"         href="<?php echo BASE_URL; ?>assets/images/favicon.svg">
<meta name="theme-color" content="#0d6e6e">

<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="DentalCare">

<?php if ($_bootstrap_css_local): ?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/bootstrap.min.css">
<?php else: ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<?php endif; ?>

<?php if ($_bootstrap_icons_local): ?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/bootstrap-icons.css">
<?php else: ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
<?php endif; ?>

<!-- Font preconnect hints (actual @import is in style.css — no double-load) -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://api.fontshare.com" crossorigin>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/enhanced.css">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/accessibility.css">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/mobile.css">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/phone.css">
<script>
(function () {
    var t = localStorage.getItem('theme');
    if (t === 'dark') {
        document.documentElement.setAttribute('data-theme', 'dark');
        document.documentElement.setAttribute('data-bs-theme', 'dark');
    }
})();
</script>
<?php
$GLOBALS['_bootstrap_js_local'] = $_bootstrap_js_local;
?>
