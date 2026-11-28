<?php
require 'session.php';
require 'db.php';
require_once 'lockout.php';
require 'security.php';
require 'layout.php';
require 'booking_helpers.php';
require_once __DIR__ . '/notification_helpers.php';

$user = require_login($conn);
if ($user['role'] !== 'admin') {
    header('Location: dashboard.php');
    exit();
}
if (!ensure_booking_schema($conn) || !gc_notifications_ensure($conn)) {
    http_response_code(503);
    exit(
        'Booking management is temporarily unavailable. Check the database schema and permissions.'
    );
}

$notice = '';
$noticeType = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['booking_action'] ?? '');
    $bookingId = filter_var($_POST['booking_id'] ?? null, FILTER_VALIDATE_INT);
    $returnStatus = (string) ($_POST['status_filter'] ?? 'Pending');
    if (!in_array($returnStatus, ['Pending', 'Confirmed', 'Cancelled', 'All'], true)) {
        $returnStatus = 'Pending';
    }
    if (
        !csrf_valid($_POST['csrf'] ?? null) ||
        !$bookingId ||
        !in_array($action, ['confirm', 'cancel'], true)
    ) {
        $notice = 'The action was not valid. Refresh the page and try again.';
        $noticeType = 'error';
    } elseif (!$conn->begin_transaction()) {
        $notice = 'The booking action could not be started.';
        $noticeType = 'error';
    } else {
        $stmt = $conn->prepare(
            'SELECT id,user_id,ride_date,ride_time,status FROM ride_bookings WHERE id=? FOR UPDATE',
        );
        if ($stmt) {
            $stmt->bind_param('i', $bookingId);
            $stmt->execute();
            $booking = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
        } else {
            $booking = null;
        }
        $future =
            $booking && strtotime($booking['ride_date'] . ' ' . $booking['ride_time']) >= time();
        $allowed = $booking && in_array($booking['status'], ['Pending', 'Confirmed'], true);
        $canConfirm =
            $action === 'confirm' && $booking && $booking['status'] === 'Pending' && $future;
        $canCancel = $action === 'cancel' && $allowed;
        $changed = false;
        if ($canConfirm) {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS total FROM ride_bookings WHERE ride_date=? AND ride_time=? AND status IN ('Pending','Confirmed') AND id<>?",
            );
            $stmt->bind_param('ssi', $booking['ride_date'], $booking['ride_time'], $bookingId);
            $stmt->execute();
            $activeAtSlot = (int) $stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();
            if ($activeAtSlot >= 5) {
                $notice =
                    'This slot has no spare capacity. Cancel another request before confirming this one.';
                $noticeType = 'error';
            } else {
                $stmt = $conn->prepare(
                    "UPDATE ride_bookings SET status='Confirmed' WHERE id=? AND status='Pending'",
                );
                $stmt->bind_param('i', $bookingId);
                $stmt->execute();
                $changed = $stmt->affected_rows === 1;
                $stmt->close();
            }
        } elseif ($canCancel) {
            $stmt = $conn->prepare(
                "UPDATE ride_bookings SET status='Cancelled' WHERE id=? AND status IN ('Pending','Confirmed')",
            );
            $stmt->bind_param('i', $bookingId);
            $stmt->execute();
            $changed = $stmt->affected_rows === 1;
            $stmt->close();
        }

        if ($changed) {
            $when =
                date('F j, Y', strtotime($booking['ride_date'])) .
                ' at ' .
                date('g:i A', strtotime($booking['ride_time']));
            $title = $action === 'confirm' ? 'Ride booking confirmed' : 'Ride booking cancelled';
            $message =
                $action === 'confirm'
                    ? 'The GoCycle owner confirmed your ride for ' . $when . '.'
                    : 'The GoCycle owner cancelled your ride request for ' .
                        $when .
                        '. Please choose another slot if needed.';
            $ok = gc_notification_create(
                $conn,
                'user',
                (int) $booking['user_id'],
                $action === 'confirm' ? 'BOOKING_CONFIRMED' : 'BOOKING_CANCELLED_BY_ADMIN',
                $title,
                $message,
                'user_bookings.php',
            );
            if ($ok) {
                $conn->commit();
                $notice =
                    $action === 'confirm'
                        ? 'Booking confirmed. The rider has been notified.'
                        : 'Booking cancelled. The rider has been notified.';
                log_event(
                    $conn,
                    $action === 'confirm' ? 'BOOKING_CONFIRMED' : 'BOOKING_CANCELLED_BY_ADMIN',
                    $user['username'],
                    (int) $user['id'],
                    'booking ' . (int) $bookingId,
                );
            } else {
                $conn->rollback();
                $notice =
                    'The booking change was rolled back because the rider notification could not be saved.';
                $noticeType = 'error';
            }
        } elseif ($notice === '') {
            $conn->rollback();
            $notice =
                $action === 'confirm'
                    ? 'This request cannot be confirmed now. It may already have changed or the ride time has passed.'
                    : 'This booking cannot be cancelled because it is no longer active.';
            $noticeType = 'error';
        } else {
            $conn->rollback();
        }
    }
    $_SESSION['admin_booking_notice'] = $notice;
    $_SESSION['admin_booking_notice_type'] = $noticeType;
    header('Location: admin_bookings.php?status=' . urlencode($returnStatus));
    exit();
}

$notice = $_SESSION['admin_booking_notice'] ?? '';
$noticeType = $_SESSION['admin_booking_notice_type'] ?? 'success';
unset($_SESSION['admin_booking_notice'], $_SESSION['admin_booking_notice_type']);
$statusFilter = (string) ($_GET['status'] ?? 'Pending');
if (!in_array($statusFilter, ['Pending', 'Confirmed', 'Cancelled', 'All'], true)) {
    $statusFilter = 'Pending';
}

$summary = ['Pending' => 0, 'Confirmed' => 0, 'Cancelled' => 0];
$result = $conn->query('SELECT status,COUNT(*) AS total FROM ride_bookings GROUP BY status');
if ($result) {
    while ($row = $result->fetch_assoc()) {
        if (isset($summary[$row['status']])) {
            $summary[$row['status']] = (int) $row['total'];
        }
    }
    $result->free();
}

if ($statusFilter === 'All') {
    $stmt = $conn->prepare(
        "SELECT b.id,b.user_id,b.ride_date,b.ride_time,b.status,b.created_at,u.username,u.email,u.first_name,u.middle_name,u.family_name,u.name_extension FROM ride_bookings b LEFT JOIN users u ON u.id=b.user_id ORDER BY CASE b.status WHEN 'Pending' THEN 0 WHEN 'Confirmed' THEN 1 ELSE 2 END,b.ride_date,b.ride_time,b.id DESC LIMIT 200",
    );
} else {
    $stmt = $conn->prepare(
        'SELECT b.id,b.user_id,b.ride_date,b.ride_time,b.status,b.created_at,u.username,u.email,u.first_name,u.middle_name,u.family_name,u.name_extension FROM ride_bookings b LEFT JOIN users u ON u.id=b.user_id WHERE b.status=? ORDER BY b.ride_date,b.ride_time,b.id DESC LIMIT 200',
    );
    $stmt->bind_param('s', $statusFilter);
}
$bookings = [];
if ($stmt) {
    $stmt->execute();
    $rows = $stmt->get_result();
    while ($row = $rows->fetch_assoc()) {
        $bookings[] = $row;
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Manage Bookings | GoCycle</title>
        <link rel="stylesheet" href="css/style.css?v=20261110-9">
        <link rel="stylesheet" href="css/ui-fix.css?v=20261110-10">
        <link rel="stylesheet" href="css/workspace.css?v=20261112-7">
        <link rel="stylesheet" href="css/dashboard-motion.css?v=20261114-1">
    </head>

    <body class="dashboard-body admin-bookings-page">
        <div class="app-shell">
            <?php gc_sidebar($user, 'bookings'); ?>
            <main class="main-content">
                <?php gc_topbar($user, 'GOCYCLE / OWNER DESK', 'Manage Bookings'); ?>
                <section class="owner-intro">
                    <div>
                        <p class="owner-kicker">OWNER RESPONSIBILITY</p>
                        <h2>Review every ride request</h2>
                        <p>Pending requests temporarily hold a seat. Confirm a request to approve
                            the ride, or cancel it to release that seat. Riders receive an in-app
                            notification after your decision.</p>
                    </div><span class="owner-mark"><?php echo gc_icon('calendar', 28); ?></span>
                </section>
                <section class="booking-summary-grid" aria-label="Booking totals">
                    <a href="admin_bookings.php?status=Pending"
                        class="booking-summary pending"><span>Awaiting review</span><strong><?php echo $summary[
                            'Pending'
                        ]; ?></strong><small>Pending requests</small></a>
                    <a href="admin_bookings.php?status=Confirmed"
                        class="booking-summary confirmed"><span>Approved</span><strong><?php echo $summary[
                            'Confirmed'
                        ]; ?></strong><small>Confirmed bookings</small></a>
                    <a href="admin_bookings.php?status=Cancelled"
                        class="booking-summary cancelled"><span>Closed</span><strong><?php echo $summary[
                            'Cancelled'
                        ]; ?></strong><small>Cancelled bookings</small></a>
                </section>
                <section class="content-card owner-booking-card">
                    <div class="section-heading">
                        <div>
                            <h3>Rider booking requests</h3>
                            <p>Review the rider, contact email, selected ride, and request time.</p>
                        </div><span class="status-pill">Owner controls</span>
                    </div>
                    <?php if ($notice): ?><div class="booking-notice <?php echo $noticeType ===
'error'
    ? 'error'
    : 'success'; ?>" role="status" data-toast><?php echo htmlspecialchars(
    $notice,
    ENT_QUOTES,
    'UTF-8',
); ?></div><?php endif; ?>
                    <form class="owner-booking-filters" method="get">
                        <label for="status-filter">Show bookings</label>
                        <select id="status-filter" name="status">
                            <?php foreach (
                                [
                                    'Pending' => 'Pending review',
                                    'Confirmed' => 'Confirmed',
                                    'Cancelled' => 'Cancelled',
                                    'All' => 'All bookings',
                                ]
                                as $value => $label
                            ): ?><option value="<?php echo htmlspecialchars(
    $value,
    ENT_QUOTES,
    'UTF-8',
); ?>" <?php echo $statusFilter === $value ? ' selected' : ''; ?>><?php
echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
echo $value === 'Pending' ? ' (' . $summary['Pending'] . ')' : '';
?></option><?php endforeach; ?>
                        </select>
                        <button class="primary-action" type="submit">Apply filter</button>
                    </form>
                    <div class="booking-table-wrap">
                        <table class="admin-table owner-booking-table">
                            <thead>
                                <tr>
                                    <th>Rider</th>
                                    <th>Email</th>
                                    <th>Ride schedule</th>
                                    <th>Requested</th>
                                    <th>Status</th>
                                    <th>Owner action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$bookings): ?><tr>
                                    <td class="empty-bookings" colspan="6">No <?php echo strtolower(
                                        $statusFilter,
                                    ); ?> bookings to show.</td>
                                </tr><?php else: ?>
                                <?php foreach ($bookings as $booking): ?>
                                <?php
                                $fullName = trim(
                                    implode(
                                        ' ',
                                        array_filter([
                                            $booking['first_name'] ?? '',
                                            $booking['middle_name'] ?? '',
                                            $booking['family_name'] ?? '',
                                            $booking['name_extension'] ?? '',
                                        ]),
                                    ),
                                );
                                $future =
                                    strtotime(
                                        $booking['ride_date'] . ' ' . $booking['ride_time'],
                                    ) >= time();
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars(
                                        $fullName ?: $booking['username'] ?? 'Deleted account',
                                        ENT_QUOTES,
                                        'UTF-8',
                                    ); ?></strong><small class="table-subtext"><?php echo htmlspecialchars(
    $booking['username'] ?? 'Account unavailable',
    ENT_QUOTES,
    'UTF-8',
); ?></small></td>
                                    <td><?php echo htmlspecialchars(
                                        $booking['email'] ?? '—',
                                        ENT_QUOTES,
                                        'UTF-8',
                                    ); ?></td>
                                    <td><strong><?php echo htmlspecialchars(
                                        date('M j, Y', strtotime($booking['ride_date'])),
                                        ENT_QUOTES,
                                        'UTF-8',
                                    ); ?></strong><small class="table-subtext"><?php echo htmlspecialchars(
    date('g:i A', strtotime($booking['ride_time'])),
    ENT_QUOTES,
    'UTF-8',
); ?></small></td>
                                    <td><?php echo htmlspecialchars(
                                        date('M j, Y · g:i A', strtotime($booking['created_at'])),
                                        ENT_QUOTES,
                                        'UTF-8',
                                    ); ?></td>
                                    <td><span class="booking-status booking-status-<?php echo strtolower(
                                        htmlspecialchars($booking['status'], ENT_QUOTES, 'UTF-8'),
                                    ); ?>"><?php echo htmlspecialchars(
    $booking['status'],
    ENT_QUOTES,
    'UTF-8',
); ?></span></td>
                                    <td>
                                        <div class="owner-booking-actions">
                                            <?php if (
                                                $booking['status'] === 'Pending' &&
                                                $future
                                            ): ?>
                                            <form method="post"
                                                data-confirm="The rider will be notified that this ride is confirmed."
                                                data-confirm-title="Confirm this booking?"
                                                data-confirm-ok="Yes, confirm"
                                                data-confirm-cancel="Not yet"
                                                data-confirm-tone="ok"><input type="hidden"
                                                    name="csrf" value="<?php echo htmlspecialchars(
                                                        csrf_token(),
                                                        ENT_QUOTES,
                                                        'UTF-8',
                                                    ); ?>"><input type="hidden" name="booking_id" value="<?php echo (int) $booking[
    'id'
]; ?>"><input type="hidden" name="status_filter" value="<?php echo htmlspecialchars(
    $statusFilter,
    ENT_QUOTES,
    'UTF-8',
); ?>"><input type="hidden" name="booking_action" value="confirm"><button
                                                    class="owner-confirm-button"
                                                    type="submit">Confirm</button></form>
                                            <?php endif; ?>
                                            <?php if (
                                                in_array(
                                                    $booking['status'],
                                                    ['Pending', 'Confirmed'],
                                                    true,
                                                )
                                            ): ?>
                                            <form method="post" data-confirm="This cancels the booking for <?php echo htmlspecialchars(
                                                $fullName ?: $booking['username'] ?? 'the rider',
                                                ENT_QUOTES,
                                                'UTF-8',
                                            ); ?> on <?php echo htmlspecialchars(
     date('M j', strtotime($booking['ride_date'])) .
         ' at ' .
         date('g:i A', strtotime($booking['ride_time'])),
     ENT_QUOTES,
     'UTF-8',
 ); ?> and releases the seat. The rider is notified." data-confirm-title="Cancel this booking?"
                                                data-confirm-ok="Yes, cancel booking"
                                                data-confirm-cancel="Keep booking"
                                                data-confirm-tone="danger"><input type="hidden"
                                                    name="csrf" value="<?php echo htmlspecialchars(
                                                        csrf_token(),
                                                        ENT_QUOTES,
                                                        'UTF-8',
                                                    ); ?>"><input type="hidden" name="booking_id" value="<?php echo (int) $booking[
    'id'
]; ?>"><input type="hidden" name="status_filter" value="<?php echo htmlspecialchars(
    $statusFilter,
    ENT_QUOTES,
    'UTF-8',
); ?>"><input type="hidden" name="booking_action" value="cancel"><button
                                                    class="owner-cancel-button"
                                                    type="submit">Cancel</button></form>
                                            <?php endif; ?>
                                            <?php if (
                                                $booking['status'] === 'Pending' &&
                                                !$future
                                            ): ?><span class="table-subtext">Ride time
                                                passed</span><?php endif; ?>
                                            <?php if (
                                                in_array($booking['status'], ['Cancelled'], true)
                                            ): ?><span class="table-subtext">No action</span><?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="owner-booking-note">Each time slot has five seats. Pending requests
                        count against capacity so the owner cannot confirm more rides than seats
                        available.</p>
                </section>
                <footer class="page-footer">© 2026 GoCycle <span>Ride • Explore • Repeat</span>
                </footer>
            </main>
        </div>
    </body>

</html>
