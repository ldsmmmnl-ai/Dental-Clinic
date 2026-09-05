<?php
// header.php — Top navigation bar shown on every admin page.
// Displays the page title, dark mode toggle, notification dropdown, user info, and logout.
// NOTE: Requires auth.php to be included first for $current_user_* variables

// Fallback for variables if auth.php wasn't included (safety check)
$current_user_id   = $current_user_id   ?? 0;
$current_user_name = $current_user_name ?? 'User';
$current_user_role = $current_user_role ?? 'staff';

$initials    = strtoupper(substr($current_user_name, 0, 1));
$notif_count   = 0;
$recent_notifs = [];

// Cache notifications in session for 30 seconds
// This means DB is only hit once every 30 seconds instead of on every single page load
$cache_key  = 'notif_cache_' . $current_user_id;
$cache_time = $_SESSION[$cache_key . '_time'] ?? 0;

if (isset($conn) && (time() - $cache_time) > 30) {
    // Cache expired — hit the DB and store results
    $nr = $conn->prepare("SELECT COUNT(*) as c FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND is_read = FALSE");
    if ($nr) {
        $nr->execute([$current_user_id]);
        $notif_count = (int) $nr->fetch(PDO::FETCH_ASSOC)['c'];
        $nr = null;
    }
    $nq = $conn->prepare("SELECT id, type, title, message, link, is_read, created_at FROM notifications WHERE (user_id = ? OR user_id IS NULL) ORDER BY created_at DESC LIMIT 8");
    if ($nq) {
        $nq->execute([$current_user_id]);
        $recent_notifs = $nq->fetchAll(PDO::FETCH_ASSOC);
        $nq = null;
    }
    // Save to session cache
    $_SESSION[$cache_key . '_time']   = time();
    $_SESSION[$cache_key . '_count']  = $notif_count;
    $_SESSION[$cache_key . '_recent'] = $recent_notifs;
} else {
    // Use cached values — zero DB queries!
    $notif_count   = $_SESSION[$cache_key . '_count']  ?? 0;
    $recent_notifs = $_SESSION[$cache_key . '_recent'] ?? [];
}
?>
<div id="topbar" role="banner">
<?php if (!empty($_GET['session_expired'])): ?>
<div style="background:#fef3c7;border-left:4px solid #f59e0b;padding:10px 20px;font-size:0.84rem;display:flex;align-items:center;gap:10px;width:100%;">
    <i class="bi bi-clock-history" style="color:#d97706;font-size:1rem;"></i>
    <span><strong>Form session refreshed.</strong> Please fill in the form again and click Save — your work was not lost, only the security token needed renewal.</span>
</div>
<?php endif; ?>

    <div class="topbar-left">
        <button id="sidebar-toggle" title="Toggle Sidebar"
                aria-label="Toggle navigation sidebar"
                aria-controls="sidebar"
                aria-expanded="true">
            <i class="bi bi-layout-sidebar" aria-hidden="true"></i>
        </button>
        <div class="topbar-title-block">
            <span class="page-title"><?php echo e($page_title ?? APP_NAME); ?></span>
        </div>
    </div>
    <div class="topbar-right">
        <button id="theme-toggle" class="notif-btn" title="Toggle dark mode"
                aria-label="Toggle dark mode"
                aria-pressed="false"
                onclick="toggleTheme()">
            <i class="bi bi-moon-fill" id="theme-icon" aria-hidden="true"></i>
        </button>

        <!-- Notification Bell with Dropdown -->
        <div class="notif-wrapper" id="notifWrapper">
            <button class="notif-btn" id="notifBell"
                    title="Notifications"
                    aria-label="Notifications<?php echo $notif_count > 0 ? ', ' . $notif_count . ' unread' : ''; ?>"
                    aria-haspopup="true"
                    aria-expanded="false"
                    aria-controls="notifPanel"
                    onclick="toggleNotifPanel(event)">
                <i class="bi bi-bell" aria-hidden="true"></i>
                <span class="notif-badge" id="notifBadge" aria-hidden="true"
                      <?php if ($notif_count <= 0): ?>style="display:none;"<?php endif; ?>>
                    <?php echo $notif_count > 99 ? '99+' : max(0, $notif_count); ?>
                </span>
            </button>

            <div class="notif-panel" id="notifPanel"
                 role="dialog"
                 aria-label="Notifications"
                 aria-modal="false">
                <div class="notif-panel-head">
                    <span style="font-weight:700;font-size:0.88rem;">Notifications</span>
                    <?php if ($notif_count > 0): ?>
                    <button class="notif-mark-all" onclick="markAllRead()" title="Mark all as read">
                        <i class="bi bi-check2-all"></i> Mark all read
                    </button>
                    <?php endif; ?>
                </div>
                <div class="notif-panel-body" id="notifList">
                    <?php if (empty($recent_notifs)): ?>
                    <div class="notif-empty">
                        <i class="bi bi-bell-slash" style="font-size:1.6rem;color:var(--gray-300);display:block;margin-bottom:8px;"></i>
                        No notifications yet.
                    </div>
                    <?php else: ?>
                    <?php foreach ($recent_notifs as $n):
                        $icon_map = [
                            'appointment' => ['bi-calendar-check-fill', '#2563eb'],
                            'payment'     => ['bi-cash-coin',           '#16a34a'],
                            'system'      => ['bi-gear-fill',           '#6b7280'],
                            'reminder'    => ['bi-alarm-fill',          '#d97706'],
                        ];
                        [$icon, $color] = $icon_map[$n['type']] ?? ['bi-bell-fill', '#6b7280'];
                        // Override icon for cancelled / no-show so they don't look like confirmations
                        $title_lower = strtolower($n['title']);
                        if (str_contains($title_lower, 'cancel')) {
                            $icon  = 'bi-calendar-x-fill';
                            $color = '#dc2626';
                        } elseif (str_contains($title_lower, 'no-show') || str_contains($title_lower, 'no show')) {
                            $icon  = 'bi-calendar-minus-fill';
                            $color = '#f59e0b';
                        }
                        $is_unread = !$n['is_read'];
                        $link = $n['link'] ? BASE_URL . ltrim($n['link'], '/') : '#';
                        $time_ago = '';
                        $diff = time() - strtotime($n['created_at']);
                        if ($diff < 60)          $time_ago = 'just now';
                        elseif ($diff < 3600)    $time_ago = floor($diff/60)  . 'm ago';
                        elseif ($diff < 86400)   $time_ago = floor($diff/3600). 'h ago';
                        else                     $time_ago = date('M d', strtotime($n['created_at']));
                    ?>
                    <div class="notif-item <?php echo $is_unread ? 'unread' : ''; ?>"
                         id="notif-<?php echo $n['id']; ?>"
                         onclick="openNotif(<?php echo $n['id']; ?>, '<?php echo addslashes($link); ?>')">
                        <div class="notif-icon" style="background:<?php echo $color; ?>18;color:<?php echo $color; ?>;">
                            <i class="bi <?php echo $icon; ?>"></i>
                        </div>
                        <div class="notif-content">
                            <div class="notif-title"><?php echo e($n['title']); ?></div>
                            <div class="notif-msg"><?php echo e($n['message']); ?></div>
                            <div class="notif-time"><?php echo $time_ago; ?></div>
                        </div>
                        <?php if ($is_unread): ?>
                        <span class="notif-dot"></span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="topbar-user">
            <div class="mini-avatar" style="<?php echo !empty($sb_photo ?? '') ? 'overflow:hidden;padding:0;' : ''; ?>">
                <?php if (!empty($sb_photo ?? '')): ?>
                    <img src="<?php echo BASE_URL . e($sb_photo); ?>"
                         style="width:100%;height:100%;object-fit:cover;border-radius:inherit;" alt="">
                <?php else: ?>
                    <?php echo $initials; ?>
                <?php endif; ?>
            </div>
            <span class="topbar-user-name"><?php echo e($current_user_name); ?></span>
            <span class="topbar-user-role badge bg-<?php echo $current_user_role === 'admin' ? 'primary' : 'secondary'; ?> ms-1">
                <?php echo ucfirst($current_user_role); ?>
            </span>
        </div>
        <a href="<?php echo BASE_URL; ?>logout.php" class="btn-logout"
           aria-label="Logout">
            <i class="bi bi-box-arrow-right" aria-hidden="true"></i><span class="btn-logout-text"> Logout</span>
        </a>
    </div>
</div>

<style>
.notif-wrapper { position: relative; }
.notif-panel {
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    width: 300px;
    background: var(--white);
    border: var(--border);
    border-radius: 12px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.13);
    z-index: 1039;
    overflow: hidden;
    /* Animation: hidden by default, fades+slides in when .open is added */
    opacity: 0;
    transform: translateY(-6px);
    pointer-events: none;
    transition: opacity 0.18s ease, transform 0.18s ease;
}
.notif-panel.open {
    opacity: 1;
    transform: translateY(0);
    pointer-events: auto;
}
.notif-panel-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 13px 16px 11px;
    border-bottom: var(--border);
    background: var(--gray-50);
}
.notif-mark-all {
    background: none;
    border: none;
    font-size: 0.75rem;
    color: var(--primary);
    cursor: pointer;
    padding: 2px 6px;
    border-radius: 5px;
    transition: background 0.15s;
}
.notif-mark-all:hover { background: var(--primary-bg); }
.notif-panel-body { max-height: 360px; overflow-y: auto; }
.notif-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 11px 14px;
    cursor: pointer;
    transition: background 0.12s;
    border-bottom: 1px solid var(--gray-100);
    position: relative;
}
.notif-item:last-child { border-bottom: none; }
.notif-item:hover { background: var(--gray-50); }
.notif-item.unread { background: var(--primary-bg); }
.notif-item.unread:hover { background: #c8f0f0; }
.notif-icon {
    width: 34px; height: 34px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.88rem;
    flex-shrink: 0;
}
.notif-content { flex: 1; min-width: 0; }
.notif-title { font-size: 0.8rem; font-weight: 600; color: var(--gray-700); margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.notif-msg   { font-size: 0.74rem; color: var(--gray-500); line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.notif-time  { font-size: 0.68rem; color: var(--gray-400); margin-top: 3px; }
.notif-dot   { width: 8px; height: 8px; border-radius: 50%; background: var(--primary); flex-shrink: 0; margin-top: 5px; }
.notif-empty { text-align: center; padding: 32px 20px; font-size: 0.82rem; color: var(--gray-400); }
[data-theme="dark"] .notif-panel { background: var(--gray-100); border-color: var(--gray-200); }
[data-theme="dark"] .notif-panel-head { background: var(--gray-150); border-color: var(--gray-200); }
[data-theme="dark"] .notif-item { border-color: var(--gray-200); }
[data-theme="dark"] .notif-item:hover { background: var(--gray-150); }
[data-theme="dark"] .notif-item.unread { background: rgba(37,99,235,0.15); }
[data-theme="dark"] .notif-item.unread:hover { background: rgba(37,99,235,0.22); }
[data-theme="dark"] .notif-title { color: var(--gray-900); }
[data-theme="dark"] .notif-msg   { color: var(--gray-600); }

/* ── Notification panel — mobile ───────────────────────── */
@media (max-width: 768px) {
    .notif-panel {
        /* Pin to right edge of screen, not the bell button */
        position: fixed;
        top: 64px;
        right: 12px;
        left: 12px;
        width: auto;
        max-height: 70vh;
        overflow-y: auto;
        border-radius: 14px;
        box-shadow: 0 12px 40px rgba(0,0,0,0.18);
    }
    .notif-panel-body {
        max-height: calc(70vh - 52px);
    }
    /* Slightly more compact items on mobile */
    .notif-item {
        padding: 10px 12px;
        gap: 8px;
    }
    .notif-icon {
        width: 30px;
        height: 30px;
        font-size: 0.8rem;
    }
    .notif-title { font-size: 0.79rem; }
    .notif-msg   { font-size: 0.72rem; line-clamp: 2; -webkit-line-clamp: 2; }
}
</style>

<script>
// Sidebar collapse — runs right away so there's no layout flash on page load
(function () {
    var sidebar   = document.getElementById('sidebar');
    var main      = document.querySelector('.main-content');
    var toggle    = document.getElementById('sidebar-toggle');
    var collapsed = localStorage.getItem('sidebar_collapsed') === 'true';

    // Desktop only: restore collapsed state from localStorage
    if (window.innerWidth > 992 && collapsed) {
        sidebar.classList.add('collapsed');
        if (main) main.classList.add('expanded');
    }

    window.closeMobileSidebar = function() {
        sidebar.classList.remove('mobile-open');
        var bd = document.getElementById('sidebar-backdrop');
        if (bd) bd.classList.remove('active');
    };

    toggle && toggle.addEventListener('click', function () {
        if (window.innerWidth <= 992) {
            // Mobile + tablet (≤992px): toggle the slide-in overlay + backdrop
            var isOpen = sidebar.classList.toggle('mobile-open');
            var bd = document.getElementById('sidebar-backdrop');
            if (bd) bd.classList.toggle('active', isOpen);
        } else {
            // Desktop only (>992px): toggle the icon-only collapsed state
            sidebar.classList.toggle('collapsed');
            if (main) main.classList.toggle('expanded');
            localStorage.setItem('sidebar_collapsed', sidebar.classList.contains('collapsed'));
        }
    });

    // Close sidebar when navigating (mobile + tablet link tap)
    document.addEventListener('click', function(e) {
        if (window.innerWidth > 992) return;
        var link = e.target.closest('#sidebar a');
        if (link) window.closeMobileSidebar();
    });
})();

// Dark mode toggle — smooth transition
function toggleTheme() {
    var html   = document.documentElement;
    var isDark = html.getAttribute('data-theme') === 'dark';
    var next   = isDark ? 'light' : 'dark';
    html.classList.add('theme-transitioning');
    html.setAttribute('data-theme',    next);
    html.setAttribute('data-bs-theme', next);
    localStorage.setItem('theme', next);
    updateThemeIcon();
    setTimeout(function () { html.classList.remove('theme-transitioning'); }, 400);
}
function updateThemeIcon() {
    var icon = document.getElementById('theme-icon');
    if (icon) icon.className = document.documentElement.getAttribute('data-theme') === 'dark'
        ? 'bi bi-sun-fill' : 'bi bi-moon-fill';
}
document.addEventListener('DOMContentLoaded', updateThemeIcon);

// ── Notification panel ──────────────────────────────────────
function toggleNotifPanel(e) {
    e.stopPropagation();
    var panel = document.getElementById('notifPanel');
    var isOpen = panel.classList.contains('open');
    // Close any open panels first, then toggle
    document.querySelectorAll('.notif-panel.open').forEach(function(p){ p.classList.remove('open'); });
    if (!isOpen) panel.classList.add('open');
}
// Auto-close notification panel when any Bootstrap modal opens
document.addEventListener('show.bs.modal', function() {
    var panel = document.getElementById('notifPanel');
    if (panel) panel.classList.remove('open');
});
// Close on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        var panel = document.getElementById('notifPanel');
        if (panel) panel.classList.remove('open');
    }
});
// Close when clicking anywhere outside the notification wrapper
document.addEventListener('click', function(e) {
    var wrapper = document.getElementById('notifWrapper');
    if (!wrapper) return;
    if (!wrapper.contains(e.target)) {
        var panel = document.getElementById('notifPanel');
        if (panel) panel.classList.remove('open');
    }
});
// Close on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        var panel = document.getElementById('notifPanel');
        if (panel) panel.classList.remove('open');
    }
});

function openNotif(id, link) {
    // Mark as read visually
    var item = document.getElementById('notif-' + id);
    if (item && item.classList.contains('unread')) {
        item.classList.remove('unread');
        var dot = item.querySelector('.notif-dot');
        if (dot) dot.remove();
        // Decrement badge
        var badge = document.getElementById('notifBadge');
        if (badge) {
            var c = parseInt(badge.textContent) - 1;
            if (c <= 0) { badge.style.display = 'none'; }
            else { badge.textContent = c; }
        }
        // Fire API call
        fetch('<?php echo BASE_URL; ?>api/notifications.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'mark_read', id: id })
        });
    }
    document.getElementById('notifPanel').classList.remove('open');
    if (link && link !== '#') window.location.href = link;
}

function markAllRead() {
    fetch('<?php echo BASE_URL; ?>api/notifications.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'mark_all_read' })
    }).then(function() {
        // Clear all unread indicators
        document.querySelectorAll('.notif-item.unread').forEach(function(el) {
            el.classList.remove('unread');
            var dot = el.querySelector('.notif-dot');
            if (dot) dot.remove();
        });
        var badge = document.getElementById('notifBadge');
        if (badge) badge.style.display = 'none';
        var btn = document.querySelector('.notif-mark-all');
        if (btn) btn.style.display = 'none';
    });
}
</script>
