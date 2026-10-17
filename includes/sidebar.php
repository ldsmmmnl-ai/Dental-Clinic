<?php
// sidebar.php — Left navigation sidebar shown on every admin page.
// NOTE: Requires auth.php to be included first for $current_user_* variables

// Fallback for variables if auth.php wasn't included (safety check)
$current_user_name = $current_user_name ?? 'User';
$current_user_role = $current_user_role ?? 'staff';

$initials = strtoupper(substr($current_user_name, 0, 1));

// Returns 'active' CSS class if the current URL contains the given path segment
if (!function_exists('nav_active')) {
    function nav_active($path) {
        return strpos($_SERVER['PHP_SELF'], $path) !== false ? 'active' : '';
    }
}
?>
<!-- Skip-to-content link (accessibility) -->
<a class="skip-link" href="#main-content">Skip to main content</a>
<!-- Screen-reader live region for dynamic announcements -->
<div id="a11y-live-region" aria-live="polite" aria-atomic="true"></div>

<!-- Mobile sidebar backdrop — tap to close -->
<div id="sidebar-backdrop" onclick="closeMobileSidebar()" aria-hidden="true"></div>

<nav id="sidebar" aria-label="Main navigation">

    <div class="sidebar-brand">
    <div class="sidebar-brand-icon" id="brandIconWrap">
        <img id="brandLogoImg" src="" alt="" style="display:none;width:36px;height:36px;border-radius:50%;object-fit:cover;">
        <svg id="brandLogoSvg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 44 44" width="32" height="32" aria-hidden="true">
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

        <li role="presentation"><span class="nav-section-label" aria-hidden="true">Main</span></li>
        <li role="presentation">
            <a href="<?php echo BASE_URL; ?>dashboard.php" class="<?php echo nav_active('dashboard'); ?>"
               <?php echo nav_active('dashboard') ? 'aria-current="page"' : ''; ?>>
                <i class="bi bi-house-door-fill" aria-hidden="true"></i><span class="nav-label">Dashboard</span>
            </a>
        </li>

        <li role="presentation"><span class="nav-section-label" aria-hidden="true">Patients</span></li>
        <li role="presentation">
            <a href="<?php echo BASE_URL; ?>modules/patients/list.php" class="<?php echo nav_active('/patients/list'); ?>"
               <?php echo nav_active('/patients/list') ? 'aria-current="page"' : ''; ?>>
                <i class="bi bi-people-fill" aria-hidden="true"></i><span class="nav-label">Patient Records</span>
            </a>
        </li>


        <li role="presentation"><span class="nav-section-label" aria-hidden="true">Appointments</span></li>
        <li role="presentation">
            <a href="<?php echo BASE_URL; ?>modules/appointments/list.php" class="<?php echo nav_active('/appointments/list'); ?>"
               <?php echo nav_active('/appointments/list') ? 'aria-current="page"' : ''; ?>>
                <i class="bi bi-calendar-check-fill" aria-hidden="true"></i><span class="nav-label">Appointments</span>
            </a>
        </li>
        <li role="presentation">
            <a href="<?php echo BASE_URL; ?>modules/appointments/calendar.php" class="<?php echo nav_active('/appointments/calendar'); ?>"
               <?php echo nav_active('/appointments/calendar') ? 'aria-current="page"' : ''; ?>>
                <i class="bi bi-calendar3" aria-hidden="true"></i><span class="nav-label">Calendar</span>
            </a>
        </li>
        <li role="presentation">
            <a href="<?php echo BASE_URL; ?>modules/schedule/manage.php" class="<?php echo nav_active('/schedule/'); ?>"
               <?php echo nav_active('/schedule/') ? 'aria-current="page"' : ''; ?>>
                <i class="bi bi-clock-history" aria-hidden="true"></i><span class="nav-label">Schedule</span>
            </a>
        </li>

        <li role="presentation"><span class="nav-section-label" aria-hidden="true">Billing</span></li>
        <li role="presentation">
            <a href="<?php echo BASE_URL; ?>modules/billing/list.php" class="<?php echo nav_active('/billing/'); ?>"
               <?php echo nav_active('/billing/') ? 'aria-current="page"' : ''; ?>>
                <i class="bi bi-receipt" aria-hidden="true"></i><span class="nav-label">Billing</span>
            </a>
        </li>

        <?php if (is_admin()): ?>
        <li role="presentation"><span class="nav-section-label" aria-hidden="true">Insights</span></li>
        <li role="presentation">
            <a href="<?php echo BASE_URL; ?>modules/analytics/dashboard.php" class="<?php echo nav_active('/analytics/dashboard'); ?>"
               <?php echo nav_active('/analytics/dashboard') ? 'aria-current="page"' : ''; ?>>
                <i class="bi bi-bar-chart-fill" aria-hidden="true"></i><span class="nav-label">Analytics</span>
            </a>
        </li>
        <li role="presentation">
            <a href="<?php echo BASE_URL; ?>modules/reports/index.php" class="<?php echo nav_active('/reports/'); ?>"
               <?php echo nav_active('/reports/') ? 'aria-current="page"' : ''; ?>>
                <i class="bi bi-file-earmark-bar-graph-fill" aria-hidden="true"></i><span class="nav-label">Reports</span>
            </a>
        </li>

        <li role="presentation"><span class="nav-section-label" aria-hidden="true">Manage</span></li>
        <li role="presentation">
            <a href="<?php echo BASE_URL; ?>modules/users/list.php" class="<?php echo nav_active('/users/'); ?>"
               <?php echo nav_active('/users/') ? 'aria-current="page"' : ''; ?>>
                <i class="bi bi-person-gear" aria-hidden="true"></i><span class="nav-label">Users</span>
            </a>
        </li>
        <li role="presentation">
            <a href="<?php echo BASE_URL; ?>modules/services/list.php" class="<?php echo nav_active('/services/'); ?>"
               <?php echo nav_active('/services/') ? 'aria-current="page"' : ''; ?>>
                <i class="bi bi-grid-fill" aria-hidden="true"></i><span class="nav-label">Services</span>
            </a>
        </li>
        <li role="presentation">
            <a href="<?php echo BASE_URL; ?>modules/doctors/list.php" class="<?php echo nav_active('/doctors/'); ?>"
               <?php echo nav_active('/doctors/') ? 'aria-current="page"' : ''; ?>>
                <i class="bi bi-person-badge-fill" aria-hidden="true"></i><span class="nav-label">Doctors</span>
            </a>
        </li>

        <li role="presentation"><span class="nav-section-label" aria-hidden="true">System</span></li>
        <li role="presentation">
            <a href="<?php echo BASE_URL; ?>modules/logs/activity.php" class="<?php echo nav_active('/logs/'); ?>"
               <?php echo nav_active('/logs/') ? 'aria-current="page"' : ''; ?>>
                <i class="bi bi-shield-fill-check" aria-hidden="true"></i><span class="nav-label">Audit Logs</span>
            </a>
        </li>
        <li role="presentation">
            <a href="<?php echo BASE_URL; ?>modules/settings/clinic.php" class="<?php echo nav_active('/settings/clinic') ? 'active' : ''; ?>"
               <?php echo (strpos($_SERVER['REQUEST_URI'] ?? '', '/settings/clinic') !== false) ? 'aria-current="page"' : ''; ?>>
                <i class="bi bi-building" aria-hidden="true"></i><span class="nav-label">Clinic Settings</span>
            </a>
        </li>
        <?php endif; ?>

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
