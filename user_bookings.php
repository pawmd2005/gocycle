<?php
require 'session.php';
require 'db.php';
require_once 'lockout.php';
require 'layout.php';

$user = require_login($conn);

if ($user['role'] === 'admin') {
    header('Location: dashboard.php');
    exit();
}

require 'ride_booking_data.php';

$notice = $_SESSION['ride_notice'] ?? '';
$notice_type = $_SESSION['ride_notice_type'] ?? 'success';

unset($_SESSION['ride_notice'], $_SESSION['ride_notice_type']);
?>

<!DOCTYPE html>
<html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>My Bookings | GoCycle</title>

        <link rel="stylesheet" href="css/style.css?v=20261110-9">
        <link rel="stylesheet" href="css/ui-fix.css?v=20261110-10">
        <link rel="stylesheet" href="css/workspace.css?v=20261112-7">
        <link rel="stylesheet" href="css/dashboard-motion.css?v=20261114-1">
    </head>

    <body class="dashboard-body">

        <div class="app-shell">

            <?php gc_sidebar($user, 'bookings'); ?>

            <main class="main-content">

                <?php gc_topbar($user, 'GOCYCLE / RESERVATIONS', 'My Bookings'); ?>

                <section class="content-card">

                    <div class="section-heading">
                        <div>
                            <h3>Ride history</h3>
                            <p>Track requests awaiting owner confirmation, confirmed rides, and
                                cancellations.</p>
                        </div>

                        <a class="primary-action" href="user_rides.php">
                            Book a ride
                        </a>
                    </div>

                    <?php if ($notice): ?>

                    <div class="booking-notice <?php echo $notice_type === 'error'
                        ? 'error'
                        : 'success'; ?>" data-toast>
                        <?php echo htmlspecialchars($notice); ?>
                    </div>

                    <?php endif; ?>

                    <div class="booking-table-wrap">

                        <table class="admin-table booking-table">

                            <thead>
                                <tr>
                                    <th>Ride date</th>
                                    <th>Time</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php if ($my_bookings && $my_bookings->num_rows > 0): ?>

                                <?php while ($booking = $my_bookings->fetch_assoc()): ?>

                                <?php $upcoming =
                                    in_array($booking['status'], ['Pending', 'Confirmed'], true) &&
                                    strtotime(
                                        $booking['ride_date'] . ' ' . $booking['ride_time'],
                                    ) >= time(); ?>

                                <tr>

                                    <td>
                                        <?php echo htmlspecialchars(
                                            date('F j, Y', strtotime($booking['ride_date'])),
                                        ); ?>
                                    </td>

                                    <td>
                                        <?php echo htmlspecialchars(
                                            date('g:i A', strtotime($booking['ride_time'])),
                                        ); ?>
                                    </td>

                                    <td>
                                        <span class="booking-status booking-status-<?php echo htmlspecialchars(
                                            strtolower($booking['status']),
                                            ENT_QUOTES,
                                            'UTF-8',
                                        ); ?>">
                                            <?php echo htmlspecialchars($booking['status']); ?>
                                        </span>
                                    </td>

                                    <td>

                                        <?php if ($upcoming): ?>

                                        <form method="post" action="ride_booking.php"
                                            data-confirm="Your <?php echo htmlspecialchars(
                                                date('M j', strtotime($booking['ride_date'])) .
                                                    ' at ' .
                                                    date('g:i A', strtotime($booking['ride_time'])),
                                                ENT_QUOTES,
                                                'UTF-8',
                                            ); ?> ride will be cancelled and the seat released for other riders." data-confirm-title="<?php echo $booking[
     'status'
 ] === 'Pending'
     ? 'Cancel this request?'
     : 'Cancel this booking?'; ?>"
                                            data-confirm-ok="Yes, cancel"
                                            data-confirm-cancel="Keep it"
                                            data-confirm-tone="danger">

                                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(
                                                csrf_token(),
                                            ); ?>">

                                            <input type="hidden" name="booking_action"
                                                value="cancel">

                                            <input type="hidden" name="booking_id"
                                                value="<?php echo (int) $booking['id']; ?>">

                                            <button class="cancel-booking-button" type="submit">
                                                <?php echo $booking['status'] === 'Pending'
                                                    ? 'Cancel request'
                                                    : 'Cancel booking'; ?>
                                            </button>

                                        </form>

                                        <?php else: ?>

                                        <span class="muted-text">—</span>

                                        <?php endif; ?>

                                    </td>

                                </tr>

                                <?php endwhile; ?>

                                <?php else: ?>

                                <tr>
                                    <td colspan="4" class="empty-bookings">
                                        No bookings yet.
                                        <a href="user_rides.php">Book your first ride</a>.
                                    </td>
                                </tr>

                                <?php endif; ?>

                            </tbody>

                        </table>

                    </div>

                </section>

                <footer class="page-footer">
                    © 2026 GoCycle
                    <span>Ride • Explore • Repeat</span>
                </footer>

            </main>

        </div>

    </body>

</html>
