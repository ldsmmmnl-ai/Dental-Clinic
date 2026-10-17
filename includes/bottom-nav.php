<?php
// bottom-nav.php — phone-only bottom bar + "More" sheet. Hidden on laptops by phone.css.
$_pn_admin = function_exists('is_admin') && is_admin();
// [label, icon, link, match, adminOnly]
$_pn_items = [
    ['Schedule',       'bi-clock-history',            'modules/schedule/manage.php',   '/schedule/',  false],
    ['Billing',        'bi-receipt',                  'modules/billing/list.php',      '/billing/',   false],
    ['Analytics',      'bi-bar-chart-fill',           'modules/analytics/dashboard.php','/analytics/',true],
    ['Reports',        'bi-file-earmark-bar-graph-fill','modules/reports/index.php',   '/reports/',   true],
    ['Users',          'bi-person-gear',              'modules/users/list.php',        '/users/',     true],
    ['Services',       'bi-grid-fill',                'modules/services/list.php',     '/services/',  true],
    ['Doctors',        'bi-person-badge-fill',        'modules/doctors/list.php',      '/doctors/',   true],
    ['Audit Logs',     'bi-shield-fill-check',        'modules/logs/activity.php',     '/logs/',      true],
    ['Clinic Settings','bi-gear-fill',                 'modules/settings/clinic.php',   '/settings/clinic', true],
];
$_pn_more_match = [];
foreach ($_pn_items as $it) { if (!$it[4] || $_pn_admin) $_pn_more_match[] = $it[3]; }
$_pn_more_match[] = '/settings/profile';
?>
<nav id="phone-nav" aria-label="Quick navigation">
    <a href="<?php echo BASE_URL; ?>dashboard.php" data-match="/dashboard.php">
        <i class="bi bi-house-door" aria-hidden="true"></i><span>Home</span>
    </a>
    <a href="<?php echo BASE_URL; ?>modules/appointments/list.php" data-match="/appointments/list,/walkin/">
        <i class="bi bi-calendar-check" aria-hidden="true"></i><span>Appts</span>
    </a>
    <a href="<?php echo BASE_URL; ?>modules/appointments/calendar.php" data-match="/appointments/calendar">
        <i class="bi bi-calendar3" aria-hidden="true"></i><span>Calendar</span>
    </a>
    <a href="<?php echo BASE_URL; ?>modules/patients/list.php" data-match="/patients/">
        <i class="bi bi-people" aria-hidden="true"></i><span>Patients</span>
    </a>
    <button type="button" id="phone-more-btn" aria-haspopup="dialog" aria-expanded="false"
            data-match="<?php echo htmlspecialchars(implode(',', $_pn_more_match)); ?>"
            onclick="phoneMore(true)">
        <i class="bi bi-grid-3x3-gap" aria-hidden="true"></i><span>More</span>
    </button>
</nav>

<!-- "More" sheet: slides up from the bottom on phones -->
<div id="phone-more-backdrop" onclick="phoneMore(false)" aria-hidden="true"></div>
<div id="phone-more" role="dialog" aria-label="More options" aria-modal="true">
    <div class="pm-handle" aria-hidden="true"></div>
    <a class="pm-user" href="<?php echo BASE_URL; ?>modules/settings/profile.php" data-match="/settings/profile" onclick="phoneMore(false)">
        <div class="pm-avatar"><?php echo e(strtoupper(substr($current_user_name ?? 'U', 0, 1))); ?></div>
        <div style="flex:1;">
            <div class="pm-name"><?php echo e($current_user_name ?? 'User'); ?></div>
            <div class="pm-role"><?php echo e(ucfirst($current_user_role ?? 'staff')); ?> &middot; My Account</div>
        </div>
        <i class="bi bi-chevron-right" aria-hidden="true"></i>
    </a>
    <div class="pm-grid">
        <?php foreach ($_pn_items as $it): if ($it[4] && !$_pn_admin) continue; ?>
        <a href="<?php echo BASE_URL . $it[2]; ?>" data-match="<?php echo $it[3]; ?>" onclick="phoneMore(false)">
            <i class="bi <?php echo $it[1]; ?>" aria-hidden="true"></i><span><?php echo $it[0]; ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<script>
function phoneMore(open) {
    var s = document.getElementById('phone-more'), b = document.getElementById('phone-more-backdrop'),
        btn = document.getElementById('phone-more-btn');
    if (!s) return;
    s.classList.toggle('open', open);
    b.classList.toggle('open', open);
    if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
}
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') phoneMore(false); });
(function () {
    var last = '';
    function hit(el, p) {
        return (el.getAttribute('data-match') || '').split(',').some(function (m) { return m && p.indexOf(m) !== -1; });
    }
    function mark() {
        var p = location.pathname;
        if (p === last) return;
        last = p;
        document.querySelectorAll('#phone-nav a, #phone-nav button[data-match], #phone-more a[data-match]')
            .forEach(function (el) { el.classList.toggle('active', hit(el, p)); });
    }
    mark();
    setInterval(mark, 400);   // pages change without a full reload, so keep checking
})();
</script>
