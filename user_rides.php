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

        <title>Book a Ride | GoCycle</title>

        <link rel="stylesheet" href="css/style.css?v=20261106-4">
        <link rel="stylesheet" href="css/ui-fix.css?v=20261110-10">
        <link rel="stylesheet" href="css/workspace.css?v=20261112-7">
        <link rel="stylesheet" href="css/dashboard-motion.css?v=20261114-1">
    </head>

    <body class="dashboard-body">

        <div class="app-shell">

            <?php gc_sidebar($user, 'rides'); ?>

            <main class="main-content">

                <?php gc_topbar($user, 'GOCYCLE / SCHEDULE', 'Book a Ride'); ?>

                <section class="content-card">

                    <div class="section-heading">
                        <div>
                            <h3>Choose your schedule</h3>
                            <p>
                                Select a date to view ride times. Requests reserve a seat while
                                pending and are confirmed by the GoCycle owner.
                            </p>
                        </div>
                    </div>

                    <?php if ($notice): ?>

                    <div class="booking-notice <?php echo $notice_type === 'error'
                        ? 'error'
                        : 'success'; ?>" data-toast>
                        <?php echo htmlspecialchars($notice); ?>
                    </div>

                    <?php endif; ?>

                    <div class="booking-policy-note"><strong>Booking requests need owner
                            confirmation.</strong><span>After you request a ride, its status is
                            Pending. We will notify you when it is confirmed or cancelled. Pending
                            requests count toward seat availability.</span></div>

                    <form method="get" action="user_rides.php" class="booking-date-form">

                        <label for="ride_date">Ride date</label>

                        <div class="booking-date-controls">

                            <input type="date" id="ride_date" name="ride_date"
                                value="<?php echo htmlspecialchars($selected_date); ?>"
                                min="<?php echo date('Y-m-d'); ?>"
                                max="<?php echo date('Y-m-d', strtotime('+30 days')); ?>" required>

                            <button class="primary-action" type="submit">
                                Check availability
                            </button>

                        </div>

                    </form>

                    <div class="ride-slot-grid">

                        <?php foreach ($ride_slots as $slot): ?>

                        <?php
                        $taken = $slot_counts[$slot] ?? 0;
                        $full = $taken >= $slot_capacity;

                        $past = $selected_date === date('Y-m-d') && $slot <= date('H:i:s');
                        ?>

                        <article class="ride-slot <?php echo $full || $past
                            ? 'slot-unavailable'
                            : ''; ?>">

                            <div class="ride-slot-top">

                                <span class="ride-time">
                                    <?php echo date('g:i A', strtotime($slot)); ?>
                                </span>

                                <span class="slot-status <?php echo $full || $past
                                    ? 'full'
                                    : 'available'; ?>">
                                    <?php echo $full ? 'FULL' : ($past ? 'PASSED' : 'AVAILABLE'); ?>
                                </span>

                            </div>

                            <p>
                                <?php echo $full
                                    ? 'No seats left'
                                    : ($past
                                        ? 'This time has passed'
                                        : $slot_capacity - $taken . ' seat(s) remaining'); ?>
                            </p>

                            <form method="post" action="ride_booking.php" data-confirm="Send a booking request for <?php echo date(
                                'g:i A',
                                strtotime($slot),
                            ); ?> on <?php echo date(
     'F j, Y',
     strtotime($selected_date),
 ); ?>? The owner will confirm it and you will be notified."
                                data-confirm-title="Send ride request?"
                                data-confirm-ok="Send request" data-confirm-cancel="Not now"
                                data-confirm-tone="danger">

                                <input type="hidden" name="csrf"
                                    value="<?php echo htmlspecialchars(csrf_token()); ?>">

                                <input type="hidden" name="booking_action" value="book">

                                <input type="hidden" name="ride_date"
                                    value="<?php echo htmlspecialchars($selected_date); ?>">

                                <input type="hidden" name="ride_time"
                                    value="<?php echo htmlspecialchars($slot); ?>">

                                <button type="submit" class="book-slot-button"
                                    <?php echo $full || $past ? 'disabled' : ''; ?>>
                                    <?php echo $full
                                        ? 'Slot Full'
                                        : ($past
                                            ? 'Unavailable'
                                            : 'Request this ride'); ?>
                                </button>

                            </form>

                        </article>

                        <?php endforeach; ?>

                    </div>

                    <p class="booking-footnote">
                        Each slot has five seats. Pending requests and confirmed rides hold a seat.
                        Schedules are available up to 30 days ahead.
                    </p>

                </section>

                <footer class="page-footer">
                    © 2026 GoCycle
                    <span>Ride • Explore • Repeat</span>
                </footer>

            </main>

        </div>

    </body>

</html>
