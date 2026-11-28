<?php
require_once 'db.php';
require_once 'session.php';
require_once 'lockout.php';
require_once 'layout.php';

$user = require_login($conn);
if ($user['role'] === 'admin') {
    header('Location: admin_dashboard.php', true, 302);
    exit();
}
require 'ride_booking_data.php'; // $my_booking_count, $slot_counts (for today), $ride_slots, $slot_capacity

$h = static function ($v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};

// Counts by status for this rider, and the next few upcoming rides.
$counts = ['Pending' => 0, 'Confirmed' => 0, 'Cancelled' => 0];
if (
    $stmt = $conn->prepare(
        'SELECT status,COUNT(*) AS total FROM ride_bookings WHERE user_id=? GROUP BY status',
    )
) {
    $uid = (int) $user['id'];
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        if (isset($counts[$row['status']])) {
            $counts[$row['status']] = (int) $row['total'];
        }
    }
    $stmt->close();
}
$upcoming = [];
if (
    $stmt = $conn->prepare(
        "SELECT ride_date,ride_time,status FROM ride_bookings WHERE user_id=? AND status IN ('Pending','Confirmed') AND TIMESTAMP(ride_date,ride_time)>=NOW() ORDER BY ride_date,ride_time LIMIT 4",
    )
) {
    $uid = (int) $user['id'];
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $upcoming[] = $row;
    }
    $stmt->close();
}
$unread = gc_notification_unread_count($conn, $user);
$today = date('Y-m-d');
$todayCounts = [];
if ($selected_date === $today) {
    $todayCounts = $slot_counts;
} elseif (
    $stmt = $conn->prepare(
        "SELECT ride_time,COUNT(*) AS total FROM ride_bookings WHERE ride_date=CURDATE() AND status IN ('Pending','Confirmed') GROUP BY ride_time",
    )
) {
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $todayCounts[$row['ride_time']] = (int) $row['total'];
    }
    $stmt->close();
}
$nowTime = date('H:i:s');
?>
<!DOCTYPE html>
<html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Dashboard | GoCycle</title>
        <link rel="stylesheet" href="css/style.css?v=20261110-8">
        <link rel="stylesheet" href="css/ui-fix.css?v=20261110-10">
        <link rel="stylesheet" href="css/workspace.css?v=20261112-7">
        <link rel="stylesheet" href="css/dashboard-motion.css?v=20261114-1">
    </head>

    <body class="dashboard-body user-dashboard-page">
        <div class="app-shell">
            <?php gc_sidebar($user, 'dashboard'); ?>
            <main class="main-content">
                <?php gc_topbar($user, 'GOCYCLE / RIDER WORKSPACE', 'Your Dashboard'); ?>

                <section class="ws-hero" aria-label="Welcome">
                    <div>
                        <p class="ws-kicker">YOUR GOCYCLE ACCOUNT</p>
                        <h2>Hello, <?php echo $h($user['username']); ?>!</h2>
                        <p class="ws-sub">Pick a time, send a ride request and track it here. The
                            owner confirms each request and you are notified right away.</p>
                    </div>
                    <div class="ws-hero-actions">
                        <a class="ws-btn ws-btn-solid" href="user_rides.php">Book a ride →</a>
                        <a class="ws-btn ws-btn-ghost" href="user_bookings.php">My bookings</a>
                    </div>
                </section>

                <section class="ws-grid ws-kpis" aria-label="Your summary">
                    <a class="ws-kpi" href="user_bookings.php"><span class="ws-kpi-ico"><?php echo gc_icon(
                        'bike',
                        17,
                    ); ?></span>
                        <p>Upcoming rides</p>
                        <strong><?php echo (int) $my_booking_count; ?></strong><small>Pending +
                            confirmed</small>
                    </a>
                    <a class="ws-kpi is-warn" href="user_bookings.php"><span class="ws-kpi-ico"><?php echo gc_icon(
                        'calendar',
                        17,
                    ); ?></span>
                        <p>Awaiting owner</p><strong><?php echo $counts[
                            'Pending'
                        ]; ?></strong><small>Pending requests</small>
                    </a>
                    <a class="ws-kpi is-good" href="user_bookings.php"><span class="ws-kpi-ico"><?php echo gc_icon(
                        'shield',
                        17,
                    ); ?></span>
                        <p>Confirmed</p><strong><?php echo $counts[
                            'Confirmed'
                        ]; ?></strong><small>Approved rides</small>
                    </a>
                    <a class="ws-kpi<?php echo $unread
                        ? ' is-alert'
                        : ''; ?>" href="notifications.php"><span class="ws-kpi-ico"><?php echo gc_icon(
    'bell',
    17,
); ?></span>
                        <p>Notifications</p><strong><?php echo (int) $unread; ?></strong><small><?php echo $unread
    ? 'Unread messages'
    : 'You are all caught up'; ?></small>
                    </a>
                </section>

                <section class="ws-grid ws-split" aria-label="Rides and availability">
                    <article class="ws-card">
                        <div class="ws-card-head">
                            <div>
                                <h3>Your next rides</h3>
                                <p>Your nearest pending and confirmed bookings.</p>
                            </div><span class="ws-pill"><?php echo count($upcoming); ?> shown</span>
                        </div>
                        <?php if ($upcoming): ?>
                        <ul class="ws-list">
                            <?php foreach ($upcoming as $b): ?>
                            <li>
                                <div><strong><?php echo $h(
                                    date('l, F j', strtotime($b['ride_date'])),
                                ); ?></strong><small><?php echo $h(
    date('g:i A', strtotime($b['ride_time'])),
); ?></small></div>
                                <span class="booking-status booking-status-<?php echo $h(
                                    strtolower($b['status']),
                                ); ?>"><?php echo $h($b['status']); ?></span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <a class="ws-more" href="user_bookings.php">Manage all bookings →</a>
                        <?php else: ?>
                        <div class="ws-empty"><strong>No upcoming rides yet</strong><span>Choose a
                                time and send your first request.</span><a
                                href="user_rides.php">Book a ride →</a></div>
                        <?php endif; ?>
                    </article>

                    <article class="ws-card">
                        <div class="ws-card-head">
                            <div>
                                <h3>Today's availability</h3>
                                <p>Seats left per time slot (<?php echo (int) $slot_capacity; ?>
                                    seats each).</p>
                            </div><span class="ws-pill"><?php echo $h(date('M j')); ?></span>
                        </div>
                        <div class="ws-bars">
                            <?php foreach ($ride_slots as $slot):

                                $taken = $todayCounts[$slot] ?? 0;
                                $full = $taken >= $slot_capacity;
                                $past = $slot <= $nowTime;
                                ?>
                            <div class="ws-bar<?php
                            echo $full ? ' is-full' : '';
                            echo $past ? ' is-past' : '';
                            ?>">
                                <span><?php echo $h(date('g:i A', strtotime($slot))); ?></span>
                                <i><b class="ws-w-<?php echo (int) (round(
                                    min(100, ($taken / max(1, $slot_capacity)) * 100) / 10,
                                ) * 10); ?>"></b></i>
                                <em><?php echo $past
                                    ? '—'
                                    : ($full
                                        ? 'Full'
                                        : $slot_capacity - $taken . ' left'); ?></em>
                            </div>
                            <?php
                            endforeach; ?>
                        </div>
                    </article>
                </section>

                <section class="ws-grid ws-actions" aria-label="Quick actions">
                    <a class="ws-action" href="user_rides.php"><span class="ws-kpi-ico"><?php echo gc_icon(
                        'bike',
                        18,
                    ); ?></span>
                        <p>START RIDING</p><strong>Book a ride</strong><small>Choose a date and time
                            and send a request for a free seat.</small><span class="ws-go">Choose a
                            schedule →</span>
                    </a>
                    <a class="ws-action" href="user_bookings.php"><span class="ws-kpi-ico"><?php echo gc_icon(
                        'calendar',
                        18,
                    ); ?></span>
                        <p>YOUR RESERVATIONS</p><strong>My bookings</strong><small>See which
                            requests are confirmed, or cancel one when plans change.</small><span
                            class="ws-go">View bookings →</span>
                    </a>
                    <a class="ws-action" href="user_account.php"><span class="ws-kpi-ico"><?php echo gc_icon(
                        'user',
                        18,
                    ); ?></span>
                        <p>ACCOUNT SETTINGS</p><strong>My account</strong><small>Review your profile
                            details and keep your account up to date.</small><span
                            class="ws-go">Open account →</span>
                    </a>
                </section>

                <footer class="page-footer">© 2026 GoCycle <span>Ride • Explore • Repeat</span>
                </footer>
            </main>
        </div>
    </body>

</html>
