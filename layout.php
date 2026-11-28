<?php
require_once __DIR__ . '/notification_helpers.php';
if (!function_exists('gc_icon')) {
    function gc_icon($name, $size = 18)
    {
        static $paths = [
            'dashboard' =>
                '<rect x="3" y="3" width="7.5" height="7.5" rx="1.8"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.8"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.8"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.8"/>',
            'bike' =>
                '<circle cx="5.5" cy="16.5" r="3.5"/><circle cx="18.5" cy="16.5" r="3.5"/><path d="M5.5 16.5 9.5 8.5h6l3 8"/><path d="M9.5 8.5l3 8h-7"/><path d="M9.5 8.5V6.5M7.8 6.5h3.4M15.5 8.5l-.7-2.5M13.5 6h2.8"/>',
            'calendar' =>
                '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
            'user' =>
                '<circle cx="12" cy="8" r="4"/><path d="M4.5 20.5c.8-4 3.8-6 7.5-6s6.7 2 7.5 6"/>',
            'users' =>
                '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.4 3.2-5.2 6.5-5.2s5.9 1.8 6.5 5.2"/><path d="M15.5 5.5a3.5 3.5 0 0 1 0 6.2M17.5 14.5c2.1.7 3.5 2.3 4 5"/>',
            'logout' =>
                '<path d="M9.5 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3.5"/><path d="M15 8l4 4-4 4M19 12H9.5"/>',
            'shield' =>
                '<path d="M12 3l7.5 3v5.5c0 4.6-3.1 8.2-7.5 9.5-4.4-1.3-7.5-4.9-7.5-9.5V6L12 3z"/><path d="M9 12l2.2 2.2L15.5 10"/>',
            'route' =>
                '<circle cx="6" cy="18" r="2.5"/><circle cx="18" cy="6" r="2.5"/><path d="M8.5 18H15a3 3 0 0 0 0-6H9a3 3 0 0 1 0-6h6.5"/>',
            'bell' =>
                '<path d="M6 16.5V11a6 6 0 0 1 12 0v5.5l1.5 2h-15l1.5-2z"/><path d="M10 20.5a2 2 0 0 0 4 0"/>',
            'leaf' => '<path d="M5 19c0-8 5-13 14-14 0 9-5 14-13 14"/><path d="M5 19l8-8"/>',
        ];
        $inner = $paths[$name] ?? '';
        return '<svg class="gc-icon" width="' .
            (int) $size .
            '" height="' .
            (int) $size .
            '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' .
            $inner .
            '</svg>';
    }
}

if (!function_exists('gc_sidebar')) {
    // $active: dashboard | monitor | rides | bookings | account | notifications
    function gc_sidebar($user, $active = '')
    {
        global $conn;
        $isAdmin = $user['role'] === 'admin';
        $unreadCount = isset($conn) ? gc_notification_unread_count($conn, $user) : 0;
        if ($isAdmin) {
            $items = [
                ['monitor', 'admin_dashboard.php', 'Security Monitor', 'shield'],
                ['users', 'admin_users.php', 'All Users', 'users'],
                ['bookings', 'admin_bookings.php', 'Manage Bookings', 'calendar'],
                ['notifications', 'notifications.php', 'Notifications', 'bell'],
                ['account', 'user_account.php', 'My Profile', 'user'],
            ];
        } else {
            $items = [
                ['dashboard', 'dashboard.php', 'Dashboard', 'dashboard'],
                ['rides', 'user_rides.php', 'Book a Ride', 'bike'],
                ['bookings', 'user_bookings.php', 'My Bookings', 'calendar'],
                ['notifications', 'notifications.php', 'Notifications', 'bell'],
                ['account', 'user_account.php', 'My Account', 'user'],
            ];
        }
        $initial = strtoupper(substr((string) $user['username'], 0, 1));
        ?>
<aside class="sidebar">
    <a class="brand brand-link" href="../index.php">GO<span>CYCLE</span></a>
    <p class="sidebar-label"><?php echo $isAdmin ? 'OWNER WORKSPACE' : 'RIDER WORKSPACE'; ?></p>
    <?php foreach ($items as $item): ?>
    <a class="nav-link<?php echo $active === $item[0]
        ? ' active'
        : ''; ?>" href="<?php echo htmlspecialchars(
    $item[1],
    ENT_QUOTES,
    'UTF-8',
); ?>"><span class="nav-icon"><?php echo gc_icon(
    $item[3],
    16,
); ?></span><span class="nav-text"><?php echo htmlspecialchars(
    $item[2],
    ENT_QUOTES,
    'UTF-8',
); ?></span><?php if ($item[0] === 'notifications' && $unreadCount > 0): ?><span
            class="notification-badge"><?php echo min(
                $unreadCount,
                99,
            ); ?></span><?php endif; ?></a>
    <?php endforeach; ?>
    <div class="sidebar-bottom">
        <div class="sidebar-user">
            <div class="avatar"><?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?></div>
            <div>
                <strong><?php echo htmlspecialchars(
                    $user['username'],
                    ENT_QUOTES,
                    'UTF-8',
                ); ?></strong><small><?php echo ucfirst(htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8')); ?></small>
            </div>
        </div>
        <a class="nav-link logout-link" href="logout.php" data-confirm-link
            data-confirm-title="Log out of GoCycle?"
            data-confirm-message="You will be signed out of this device. To come back you will need to log in again with a new email code."
            data-confirm-ok="Log out" data-confirm-tone="danger"><span class="nav-icon"><?php echo gc_icon(
                'logout',
                16,
            ); ?></span>Log out</a>
    </div>
</aside>
<script src="js/ui.js?v=20261112-2" defer></script>
<script src="js/dashboard-motion.js?v=20261114-1" defer></script>
<?php if (function_exists('gc_idle_timeout_seconds') && !empty($_SESSION['user_id'])): ?>
<div id="gc-session" hidden data-timeout="<?php echo gc_idle_timeout_seconds(); ?>"
    data-remaining="<?php echo max(
        0,
        gc_idle_timeout_seconds() - (time() - (int) ($_SESSION['last_activity'] ?? time())),
    ); ?>"
    data-csrf="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>"
    data-ping="session_ping.php" data-login="login.php?expired=1"></div>
<script src="js/session-guard.js?v=20261114-2" defer></script>
<?php endif; ?>
<?php
    }
}

if (!function_exists('gc_topbar')) {
    function gc_topbar($user, $eyebrow, $title)
    {
        global $conn;
        $initial = strtoupper(substr((string) $user['username'], 0, 1));
        $unreadCount = isset($conn) ? gc_notification_unread_count($conn, $user) : 0;
        ?>
<header class="topbar">
    <div>
        <p class="eyebrow"><?php echo htmlspecialchars($eyebrow, ENT_QUOTES, 'UTF-8'); ?></p>
        <h1><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h1>
    </div>
    <div class="topbar-actions"><a class="notification-link" href="notifications.php"
            aria-label="Notifications<?php echo $unreadCount
                ? ' (' . $unreadCount . ' unread)'
                : ''; ?>"><span
                class="notification-icon"><?php echo gc_icon(
                    'bell',
                    17,
                ); ?></span><span>Notifications</span><?php if ($unreadCount > 0): ?><span
                class="notification-badge"><?php echo min(
                    $unreadCount,
                    99,
                ); ?></span><?php endif; ?></a>
        <div class="topbar-profile">
            <div class="avatar"><?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?></div>
            <div>
                <strong><?php echo htmlspecialchars(
                    $user['username'],
                    ENT_QUOTES,
                    'UTF-8',
                ); ?></strong><small><?php echo ucfirst(htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8')); ?>
                    account</small></div>
        </div>
    </div>
</header>
<?php
    }
}
