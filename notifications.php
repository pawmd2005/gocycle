<?php
require 'session.php';
require 'db.php';
require_once 'lockout.php';
require 'layout.php';
require_once __DIR__ . '/notification_helpers.php';

$user = require_login($conn);
if (!gc_notifications_ensure($conn)) {
    http_response_code(503);
    exit('Notifications are temporarily unavailable. Please contact the administrator.');
}
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $notice = 'Your form expired. Please refresh and try again.';
    } else {
        $isAdmin = $user['role'] === 'admin';
        if (($_POST['action'] ?? '') === 'mark_all') {
            $sql = $isAdmin
                ? "UPDATE notifications SET is_read=1 WHERE recipient_role='admin' AND recipient_user_id IS NULL AND is_read=0"
                : "UPDATE notifications SET is_read=1 WHERE recipient_role='user' AND recipient_user_id=? AND is_read=0";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                if (!$isAdmin) {
                    $stmt->bind_param('i', $user['id']);
                }
                $ok = $stmt->execute();
                $stmt->close();
                $notice = $ok
                    ? 'All notifications marked as read.'
                    : 'Notifications could not be updated.';
            }
        } elseif (($_POST['action'] ?? '') === 'mark_read') {
            $id = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT);
            if ($id) {
                $sql = $isAdmin
                    ? "UPDATE notifications SET is_read=1 WHERE id=? AND recipient_role='admin' AND recipient_user_id IS NULL"
                    : "UPDATE notifications SET is_read=1 WHERE id=? AND recipient_role='user' AND recipient_user_id=?";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    if ($isAdmin) {
                        $stmt->bind_param('i', $id);
                    } else {
                        $stmt->bind_param('ii', $id, $user['id']);
                    }
                    $ok = $stmt->execute();
                    $stmt->close();
                    $notice = $ok
                        ? 'Notification marked as read.'
                        : 'Notification could not be updated.';
                }
            }
        }
    }
}

if ($user['role'] === 'admin') {
    $stmt = $conn->prepare(
        "SELECT id,notification_type,title,message,link_path,is_read,created_at FROM notifications WHERE recipient_role='admin' AND recipient_user_id IS NULL ORDER BY created_at DESC,id DESC LIMIT 100",
    );
} else {
    $stmt = $conn->prepare(
        "SELECT id,notification_type,title,message,link_path,is_read,created_at FROM notifications WHERE recipient_role='user' AND recipient_user_id=? ORDER BY created_at DESC,id DESC LIMIT 100",
    );
    $stmt->bind_param('i', $user['id']);
}
$notifications = [];
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
    $stmt->close();
}
$allowedLinks =
    $user['role'] === 'admin'
        ? ['admin_bookings.php', 'admin_dashboard.php', 'notifications.php']
        : ['user_bookings.php', 'user_rides.php', 'notifications.php'];
?>
<!DOCTYPE html>
<html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Notifications | GoCycle</title>
        <link rel="stylesheet" href="css/style.css?v=20261110-9">
        <link rel="stylesheet" href="css/ui-fix.css?v=20261110-10">
        <link rel="stylesheet" href="css/workspace.css?v=20261112-7">
        <link rel="stylesheet" href="css/dashboard-motion.css?v=20261114-1">
    </head>

    <body class="dashboard-body">
        <div class="app-shell">
            <?php gc_sidebar($user, 'notifications'); ?>
            <main class="main-content">
                <?php gc_topbar($user, 'GOCYCLE / UPDATES', 'Notifications'); ?>
                <section class="content-card notifications-card">
                    <div class="section-heading">
                        <div>
                            <h3><?php echo $user['role'] === 'admin'
                                ? 'Owner inbox'
                                : 'Your ride updates'; ?></h3>
                            <p><?php echo $user['role'] === 'admin'
                                ? 'New ride requests and rider cancellations appear here.'
                                : 'Booking requests and owner decisions appear here.'; ?></p>
                        </div>
                        <?php if ($notifications): ?>
                        <form method="post"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars(
                            csrf_token(),
                            ENT_QUOTES,
                            'UTF-8',
                        ); ?>"><input type="hidden" name="action" value="mark_all"><button
                                class="secondary-action" type="submit">Mark all as read</button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php if (
                        $notice
                    ): ?><p class="notification-flash" role="status" data-toast><?php echo htmlspecialchars(
    $notice,
    ENT_QUOTES,
    'UTF-8',
); ?></p><?php endif; ?>
                    <?php if (!$notifications): ?>
                    <div class="notifications-empty"><span class="empty-icon">✓</span>
                        <h3>You're all caught up</h3>
                        <p>New booking updates will appear here.</p>
                    </div>
                    <?php else: ?>
                    <div class="notification-list">
                        <?php foreach ($notifications as $item): ?>
                        <?php $safeLink = in_array($item['link_path'], $allowedLinks, true)
                            ? $item['link_path']
                            : 'notifications.php'; ?>
                        <article class="notification-item<?php echo (int) $item['is_read'] === 0
                            ? ' is-unread'
                            : ''; ?>">
                            <span class="notification-dot" aria-hidden="true"></span>
                            <div class="notification-copy">
                                <div class="notification-heading">
                                    <h4><?php echo htmlspecialchars(
                                        $item['title'],
                                        ENT_QUOTES,
                                        'UTF-8',
                                    ); ?></h4><time datetime="<?php echo htmlspecialchars(
    date(DATE_ATOM, strtotime($item['created_at'])),
    ENT_QUOTES,
    'UTF-8',
); ?>"><?php echo htmlspecialchars(
    date('M j, Y · g:i A', strtotime($item['created_at'])),
    ENT_QUOTES,
    'UTF-8',
); ?></time>
                                </div>
                                <p><?php echo htmlspecialchars(
                                    $item['message'],
                                    ENT_QUOTES,
                                    'UTF-8',
                                ); ?></p>
                                <div class="notification-actions"><a href="<?php echo htmlspecialchars(
                                    $safeLink,
                                    ENT_QUOTES,
                                    'UTF-8',
                                ); ?>"><?php echo $user['role'] === 'admin'
    ? 'Open booking desk'
    : 'Open my bookings'; ?> →</a>
                                    <?php if (
                                        (int) $item['is_read'] === 0
                                    ): ?><form method="post"><input type="hidden" name="csrf"
                                            value="<?php echo htmlspecialchars(
                                                csrf_token(),
                                                ENT_QUOTES,
                                                'UTF-8',
                                            ); ?>"><input type="hidden" name="action" value="mark_read"><input type="hidden"
                                            name="notification_id" value="<?php echo (int) $item[
                                                'id'
                                            ]; ?>"><button type="submit" class="mark-read-button">Mark as read</button></form>
                                    <?php else: ?><span
                                        class="read-label">Read</span><?php endif; ?>
                                </div>
                            </div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </section>
                <footer class="page-footer">© 2026 GoCycle <span>Ride • Explore • Repeat</span>
                </footer>
            </main>
        </div>
    </body>

</html>
