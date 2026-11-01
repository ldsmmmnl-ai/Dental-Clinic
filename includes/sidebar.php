<?php
// sidebar.php — Left navigation sidebar shown on every admin page.
// NOTE: Requires auth.php to be included first for $current_user_* variables

// Fallback for variables if auth.php wasn't included (safety check)
$current_user_name = $current_user_name ?? 'User';
$current_user_role = $current_user_role ?? 'staff';

$initials = strtoupper(substr($current_user_name, 0, 1));

// Clinic logo (uploaded in Settings → Clinic Settings). Empty string = show the default tooth icon.
$__brand_logo = '';
try {
    if (!function_exists('clinic_settings')) require_once __DIR__ . '/clinic_settings.php';
    if (isset($conn) && $conn instanceof PDO) {
        $__brand_logo = clinic_settings($conn)['logo_url'] ?? '';
    }
} catch (Throwable $e) { $__brand_logo = ''; }

// Returns 'active' CSS class if the current URL contains the given path segment
if (!function_exists('nav_active')) {
    function nav_active($path) {
        return strpos($_SERVER['PHP_SELF'], $path) !== false ? 'active' : '';
    }
}

// ── Navigation map ────────────────────────────────────────────────────────────
// To add, move or rename a menu item, edit this list — the sidebar builds itself.
//   'match' = URL fragments that mark the item as the current page
//   'not'   = URL fragments that cancel a match
//   group   = collapsible section ('open' => starts expanded, 'admin' => admins only)
$__is_admin = function_exists('is_admin') && is_admin();
$__self     = $_SERVER['PHP_SELF'] ?? '';

$__nav = [
    ['label' => 'Dashboard', 'icon' => 'bi-house-door-fill', 'href' => 'dashboard.php',
     'match' => ['/dashboard.php'], 'not' => ['/modules/']],

    ['group' => 'care', 'title' => 'Patient Care', 'open' => true, 'admin' => false, 'items' => [
        ['label' => 'Patient Records', 'icon' => 'bi-people-fill',         'href' => 'modules/patients/list.php',        'match' => ['/patients/', '/treatments/']],
        ['label' => 'Appointments',    'icon' => 'bi-calendar-check-fill', 'href' => 'modules/appointments/list.php',    'match' => ['/appointments/list', '/walkin/']],
        ['label' => 'Calendar',        'icon' => 'bi-calendar3',           'href' => 'modules/appointments/calendar.php','match' => ['/appointments/calendar']],
        ['label' => 'Schedule',        'icon' => 'bi-clock-history',       'href' => 'modules/schedule/manage.php',      'match' => ['/schedule/']],
    ]],

    ['label' => 'Billing', 'icon' => 'bi-receipt', 'href' => 'modules/billing/list.php', 'match' => ['/billing/']],

    ['group' => 'insights', 'title' => 'Insights', 'open' => false, 'admin' => true, 'items' => [
        ['label' => 'Analytics', 'icon' => 'bi-bar-chart-fill',                'href' => 'modules/analytics/dashboard.php', 'match' => ['/analytics/']],
        ['label' => 'Reports',   'icon' => 'bi-file-earmark-bar-graph-fill',   'href' => 'modules/reports/index.php',       'match' => ['/reports/']],
    ]],

    ['group' => 'manage', 'title' => 'Manage', 'open' => false, 'admin' => true, 'items' => [
        ['label' => 'Users',    'icon' => 'bi-person-gear',        'href' => 'modules/users/list.php',    'match' => ['/users/']],
        ['label' => 'Services', 'icon' => 'bi-grid-fill',          'href' => 'modules/services/list.php', 'match' => ['/services/']],
        ['label' => 'Doctors',  'icon' => 'bi-person-badge-fill',  'href' => 'modules/doctors/list.php',  'match' => ['/doctors/']],
    ]],

    ['group' => 'system', 'title' => 'System', 'open' => false, 'admin' => true, 'items' => [
        ['label' => 'Audit Logs',       'icon' => 'bi-shield-fill-check',    'href' => 'modules/logs/activity.php',   'match' => ['/logs/']],
        ['label' => 'Clinic Settings',  'icon' => 'bi-building',             'href' => 'modules/settings/clinic.php', 'match' => ['/settings/clinic']],
        ['label' => 'Database Backup',  'icon' => 'bi-database-fill-down',   'href' => 'modules/settings/backup.php', 'match' => ['/settings/backup']],
    ]],
];

// True when the menu item belongs to the page being viewed
if (!function_exists('sb_is_current')) {
    function sb_is_current(array $it, string $self): bool {
        foreach ($it['not'] ?? [] as $n) { if (strpos($self, $n) !== false) return false; }
        foreach ($it['match'] as $m)     { if (strpos($self, $m) !== false) return true; }
        return false;
    }
}
// Prints one menu link
if (!function_exists('sb_render_link')) {
    function sb_render_link(array $it, bool $current): void {
        $cls  = $current ? ' class="active"' : '';
        $aria = $current ? ' aria-current="page"' : '';
        echo '<li><a href="' . BASE_URL . htmlspecialchars($it['href'], ENT_QUOTES, 'UTF-8') . '"' . $cls . $aria . '>'
           . '<i class="bi ' . htmlspecialchars($it['icon'], ENT_QUOTES, 'UTF-8') . '" aria-hidden="true"></i>'
           . '<span class="nav-label">' . htmlspecialchars($it['label'], ENT_QUOTES, 'UTF-8') . '</span>'
           . '</a></li>' . "\n";
    }
}
?>
<!-- Skip-to-content link (accessibility) -->
<a class="skip-link" href="#main-content">Skip to main content</a>
<!-- Screen-reader live region for dynamic announcements -->
<div id="a11y-live-region" aria-live="polite" aria-atomic="true"></div>

<!-- Mobile sidebar backdrop — tap to close -->
<div id="sidebar-backdrop" onclick="closeMobileSidebar()" aria-hidden="true"></div>

<style>
/* ── Collapsible sidebar groups ─────────────────────────────────────────────── */
#sidebar .nav-group-toggle {
    display: flex; align-items: center; justify-content: space-between;
    width: calc(100% - 18px); min-width: 0; min-height: 36px;
    margin: 10px 9px 2px; padding: 6px 11px;
    background: transparent; border: 0; border-radius: 8px; cursor: pointer;
    font-family: inherit; font-size: 0.62rem; font-weight: 700;
    letter-spacing: 0.14em; text-transform: uppercase; text-align: left; white-space: nowrap;
    color: rgba(255, 255, 255, 0.60);
    transition: background 0.17s var(--ease, ease), color 0.17s var(--ease, ease);
}
#sidebar .nav-group-toggle:hover { background: rgba(255, 255, 255, 0.06); color: rgba(255, 255, 255, 0.92); }
#sidebar .nav-group-toggle:focus-visible { outline: 3px solid #60a5fa; outline-offset: -2px; }
#sidebar .nav-group.has-active .nav-group-title { color: rgba(255, 255, 255, 0.92); }
#sidebar .nav-group-chevron { font-size: 0.7rem; flex-shrink: 0; transition: transform 0.2s var(--ease, ease); }
#sidebar .nav-group.is-closed .nav-group-chevron { transform: rotate(-90deg); }
/* Gold dot: this closed group contains the page you are on */
#sidebar .nav-group.is-closed.has-active .nav-group-title::after {
    content: ""; display: inline-block; width: 6px; height: 6px; margin-left: 8px;
    border-radius: 50%; background: var(--accent-light, #e8b84b); vertical-align: middle;
}

/* Open / close — the row height animates between 0fr and 1fr */
#sidebar .nav-group-body { display: grid; grid-template-rows: 1fr; }
#sidebar .nav-group.is-closed .nav-group-body { grid-template-rows: 0fr; }
#sidebar .nav-group-list {
    min-height: 0; overflow: hidden; margin: 0; padding: 2px 0 8px; list-style: none;
}
#sidebar .nav-group.is-closed .nav-group-list { visibility: hidden; padding-top: 0; padding-bottom: 0; }
/* animations switch on after the first paint, so the saved state never "plays" on page load */
#sidebar.nav-ready .nav-group-body { transition: grid-template-rows 0.22s var(--ease, ease); }
#sidebar.nav-ready .nav-group-list { transition: padding 0.22s var(--ease, ease), visibility 0s linear 0.22s; }
#sidebar.nav-ready .nav-group:not(.is-closed) .nav-group-list { transition-delay: 0s; }

/* Icon-only (collapsed) sidebar: show every icon, with a thin divider between groups */
#sidebar.collapsed .nav-group-toggle { display: none; }
#sidebar.collapsed .nav-group::before {
    content: ""; display: block; height: 1px; margin: 6px 16px 4px; background: rgba(255, 255, 255, 0.08);
}
#sidebar.collapsed .nav-group.is-closed .nav-group-body { grid-template-rows: 1fr; }
#sidebar.collapsed .nav-group.is-closed .nav-group-list { visibility: visible; padding: 2px 0 8px; }

@media (prefers-reduced-motion: reduce) {
    #sidebar .nav-group-body, #sidebar .nav-group-list, #sidebar .nav-group-chevron { transition: none !important; }
}
</style>

<nav id="sidebar" aria-label="Main navigation">

    <div class="sidebar-brand">
    <div class="sidebar-brand-icon" id="brandIconWrap"<?php if ($__brand_logo !== ''): ?> style="background:#fff;padding:3px;"<?php endif; ?>>
        <?php if ($__brand_logo !== ''): ?>
        <img id="brandLogoImg" src="<?php echo htmlspecialchars($__brand_logo, ENT_QUOTES, 'UTF-8'); ?>" alt="Clinic logo"
             style="width:100%;height:100%;object-fit:contain;display:block;border-radius:10px;"
             onerror="this.style.display='none';var s=document.getElementById('brandLogoSvg');if(s)s.style.display='block';var w=document.getElementById('brandIconWrap');if(w){w.style.background='';w.style.padding='';}">
        <?php endif; ?>
        <svg id="brandLogoSvg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 44 44" width="32" height="32" aria-hidden="true"<?php if ($__brand_logo !== ''): ?> style="display:none;"<?php endif; ?>>
            <!-- Tooth shape: crown top, two roots bottom -->
            <path d="M10,10 C10,5 14,3 18,4 C20,4.5 21,5.5 22,5.5 C23,5.5 24,4.5 26,4 C30,3 34,5 34,10 C34,15 32,19 30,22 C28.5,24.5 28,26 27.5,29 C27,32 26.5,34 25.5,34 C24.5,34 24,31 23.5,28 C23,25 22,24 22,24 C22,24 21,25 20.5,28 C20,31 19.5,34 18.5,34 C17.5,34 17,32 16.5,29 C16,26 15.5,24.5 14,22 C12,19 10,15 10,10 Z"
                fill="white" opacity="0.95"/>
            <!-- Subtle shine highlight on crown -->
            <ellipse cx="18" cy="9" rx="3" ry="2" fill="white" opacity="0.3" transform="rotate(-15,18,9)"/>
            <!-- Center groove line on crown -->
            <path d="M22,6 C22,6 22,14 22,16" stroke="rgba(0,160,140,0.4)" stroke-width="1" stroke-linecap="round" fill="none"/>
        </svg>
    </div>
    <div class="sidebar-brand-text">
        <span class="brand-sub" id="brandSubDisplay" style="font-size:0.75rem;letter-spacing:0.12em;opacity:1;color:rgba(255,255,255,0.9);">CLINIC SYSTEM</span>
    </div>
</div>


    <ul class="sidebar-nav" role="list">
<?php foreach ($__nav as $__entry): ?>
<?php if (!isset($__entry['group'])): ?>
        <?php sb_render_link($__entry, sb_is_current($__entry, $__self)); ?>
<?php else: ?>
<?php
    if (!empty($__entry['admin']) && !$__is_admin) continue;
    $__gid    = $__entry['group'];
    $__active = false;
    foreach ($__entry['items'] as $__it) { if (sb_is_current($__it, $__self)) { $__active = true; break; } }
    $__open   = $__active || !empty($__entry['open']);
?>
        <li class="nav-group<?php echo $__open ? '' : ' is-closed'; ?><?php echo $__active ? ' has-active' : ''; ?>" data-group="<?php echo $__gid; ?>">
            <button type="button" class="nav-group-toggle" id="nav-btn-<?php echo $__gid; ?>"
                    aria-expanded="<?php echo $__open ? 'true' : 'false'; ?>" aria-controls="nav-group-<?php echo $__gid; ?>">
                <span class="nav-group-title"><?php echo htmlspecialchars($__entry['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                <i class="bi bi-chevron-down nav-group-chevron" aria-hidden="true"></i>
            </button>
            <div class="nav-group-body" id="nav-group-<?php echo $__gid; ?>" role="group" aria-labelledby="nav-btn-<?php echo $__gid; ?>">
                <ul class="nav-group-list" role="list">
<?php foreach ($__entry['items'] as $__it) { sb_render_link($__it, sb_is_current($__it, $__self)); } ?>
                </ul>
            </div>
        </li>
<?php endif; ?>
<?php endforeach; ?>
    </ul>

    <div class="sidebar-footer">
        <a href="<?php echo BASE_URL; ?>modules/settings/profile.php"
           style="text-decoration:none;display:block;"
           title="My Account">
        <div class="sidebar-user" style="cursor:pointer;">
            <?php
            // Show profile photo if set, otherwise show initials
            $sb_photo = '';
            if (!empty($current_user_id ?? $_SESSION['user_id'] ?? 0)) {
                static $_sb_photo_cache = null;
                if ($_sb_photo_cache === null) {
                    try {
                        $cols = $conn->query("SHOW COLUMNS FROM `users` LIKE 'profile_photo'")->fetchAll();
                        if (!empty($cols)) {
                            $sq = $conn->prepare("SELECT profile_photo FROM users WHERE id = ? LIMIT 1");
                            $sq->execute([$current_user_id ?? $_SESSION['user_id'] ?? 0]);
                            $_sb_photo_cache = $sq->fetchColumn() ?: '';
                        } else { $_sb_photo_cache = ''; }
                    } catch (Exception $e) { $_sb_photo_cache = ''; }
                }
                $sb_photo = $_sb_photo_cache;
            }
            ?>
            <?php if ($sb_photo): ?>
                <div class="user-avatar" style="overflow:hidden;padding:0;">
                    <img src="<?php echo BASE_URL . e($sb_photo); ?>"
                         style="width:100%;height:100%;object-fit:cover;border-radius:inherit;" alt="">
                </div>
            <?php else: ?>
                <div class="user-avatar"><?php echo $initials; ?></div>
            <?php endif; ?>
            <div class="user-info">
                <div class="user-name"><?php echo e($current_user_name); ?></div>
                <div class="user-role" style="display:flex;align-items:center;gap:4px;">
                    <?php echo ucfirst($current_user_role); ?>
                    <span style="font-size:0.65rem;opacity:0.7;">· My Account</span>
                </div>
            </div>
        </div>
        </a>
    </div>

</nav>
<script>
// Collapsible sidebar groups: remembers what you opened or closed.
// The group holding the current page always opens by itself.
(function () {
    var sb = document.getElementById('sidebar');
    if (!sb) return;
    var KEY = 'cap_nav_groups_v1', saved = {};
    try { saved = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { saved = {}; }

    function setOpen(group, open) {
        group.classList.toggle('is-closed', !open);
        var btn = group.querySelector('.nav-group-toggle');
        if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    sb.querySelectorAll('.nav-group').forEach(function (g) {
        var id = g.getAttribute('data-group');
        if (!g.classList.contains('has-active') && Object.prototype.hasOwnProperty.call(saved, id)) {
            setOpen(g, !!saved[id]);
        }
    });

    sb.addEventListener('click', function (e) {
        var btn = e.target.closest('.nav-group-toggle');
        if (!btn) return;
        var g = btn.closest('.nav-group');
        var open = g.classList.contains('is-closed');
        setOpen(g, open);
        saved[g.getAttribute('data-group')] = open ? 1 : 0;
        try { localStorage.setItem(KEY, JSON.stringify(saved)); } catch (err) { /* storage blocked: ignore */ }
    });

    requestAnimationFrame(function () {
        requestAnimationFrame(function () { sb.classList.add('nav-ready'); });
    });
})();
</script>
<?php include __DIR__ . '/bottom-nav.php'; ?>