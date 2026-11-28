<?php
require 'session.php';
require 'db.php';
require_once 'lockout.php';
require 'security.php';
require_once __DIR__ . '/notification_helpers.php';

$user = require_login($conn);
if ($user['role'] === 'admin') {
    header('Location: admin_bookings.php');
    exit();
}
require 'ride_booking_data.php';

if (!gc_notifications_ensure($conn)) {
    $_SESSION['ride_notice'] =
        'Booking notifications are unavailable. Please contact the administrator.';
    $_SESSION['ride_notice_type'] = 'error';
    header('Location: user_rides.php');
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid($_POST['csrf'] ?? null)) {
    $_SESSION['ride_notice'] = 'Invalid request. Please try again.';
    $_SESSION['ride_notice_type'] = 'error';
    header('Location: user_rides.php');
    exit();
}

$action = (string) ($_POST['booking_action'] ?? '');
$redirectDate = (string) ($_POST['ride_date'] ?? date('Y-m-d'));
$notice = '';
$type = 'error';

if ($action === 'cancel') {
    $id = filter_var($_POST['booking_id'] ?? null, FILTER_VALIDATE_INT);
    $booking = null;
    if ($id && $conn->begin_transaction()) {
        $stmt = $conn->prepare(
            'SELECT id,user_id,ride_date,ride_time,status FROM ride_bookings WHERE id=? AND user_id=? FOR UPDATE',
        );
        if ($stmt) {
            $stmt->bind_param('ii', $id, $user['id']);
            $stmt->execute();
            $booking = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
        }
        $future =
            $booking && strtotime($booking['ride_date'] . ' ' . $booking['ride_time']) >= time();
        $canCancel =
            $booking && in_array($booking['status'], ['Pending', 'Confirmed'], true) && $future;
        if ($canCancel) {
            $stmt = $conn->prepare(
                "UPDATE ride_bookings SET status='Cancelled' WHERE id=? AND user_id=? AND status IN ('Pending','Confirmed')",
            );
            $stmt->bind_param('ii', $id, $user['id']);
            $stmt->execute();
            $updated = $stmt->affected_rows > 0;
            $stmt->close();
            if ($updated) {
                $when =
                    date('F j, Y', strtotime($booking['ride_date'])) .
                    ' at ' .
                    date('g:i A', strtotime($booking['ride_time']));
                $okUser = gc_notification_create(
                    $conn,
                    'user',
                    (int) $user['id'],
                    'BOOKING_CANCELLED',
                    'Booking cancelled',
                    'Your ride for ' . $when . ' was cancelled.',
                    'user_bookings.php',
                );
                $okAdmin = gc_notification_create(
                    $conn,
                    'admin',
                    null,
                    'BOOKING_CANCELLED_BY_USER',
                    'Rider cancelled a booking',
                    $user['username'] . ' cancelled a ride for ' . $when . '.',
                    'admin_bookings.php',
                );
                if ($okUser && $okAdmin) {
                    $conn->commit();
                    log_event(
                        $conn,
                        'BOOKING_CANCELLED_BY_USER',
                        $user['username'],
                        (int) $user['id'],
                        'booking ' . (int) $id,
                    );
                    $notice = 'Your booking was cancelled. The seat is available again.';
                    $type = 'success';
                } else {
                    $conn->rollback();
                    $notice =
                        'The booking could not be cancelled because notifications could not be saved.';
                }
            } else {
                $conn->rollback();
                $notice = 'This booking has already changed. Refresh your bookings and try again.';
            }
        } else {
            $conn->rollback();
            $notice = 'This booking cannot be cancelled.';
        }
    } else {
        $notice = 'The cancellation could not be started. Please try again.';
    }
    $_SESSION['ride_notice'] = $notice;
    $_SESSION['ride_notice_type'] = $type;
    header('Location: user_bookings.php');
    exit();
}

if ($action === 'book') {
    $date = (string) ($_POST['ride_date'] ?? '');
    $time = (string) ($_POST['ride_time'] ?? '');
    $validDate =
        preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) &&
        $date >= date('Y-m-d') &&
        $date <= date('Y-m-d', strtotime('+30 days'));
    if (
        !$validDate ||
        !in_array($time, $ride_slots, true) ||
        ($date === date('Y-m-d') && $time <= date('H:i:s'))
    ) {
        $notice = 'Please choose a valid future date and time.';
    } else {
        $redirectDate = $date;
        $lockName = 'gocycle_' . $date . '_' . str_replace(':', '', $time);
        $lockStmt = $conn->prepare('SELECT GET_LOCK(?,5) AS got_lock');
        $lockStmt->bind_param('s', $lockName);
        $lockStmt->execute();
        $gotLock = (int) ($lockStmt->get_result()->fetch_assoc()['got_lock'] ?? 0) === 1;
        $lockStmt->close();

        if (!$gotLock) {
            $notice = 'This slot is being requested right now. Please try again.';
        } else {
            try {
                $stmt = $conn->prepare(
                    "SELECT COUNT(*) AS total FROM ride_bookings WHERE ride_date=? AND ride_time=? AND status IN ('Pending','Confirmed')",
                );
                $stmt->bind_param('ss', $date, $time);
                $stmt->execute();
                $count = (int) $stmt->get_result()->fetch_assoc()['total'];
                $stmt->close();

                $stmt = $conn->prepare(
                    "SELECT id FROM ride_bookings WHERE user_id=? AND ride_date=? AND ride_time=? AND status IN ('Pending','Confirmed') LIMIT 1",
                );
                $stmt->bind_param('iss', $user['id'], $date, $time);
                $stmt->execute();
                $duplicate = $stmt->get_result()->num_rows > 0;
                $stmt->close();

                if ($duplicate) {
                    $notice =
                        'You already have an active request or confirmed booking for this time slot.';
                } elseif ($count >= $slot_capacity) {
                    $notice = 'This time slot has no seats remaining. Please choose another.';
                } elseif (!$conn->begin_transaction()) {
                    $notice = 'The booking request could not be started. Please try again.';
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO ride_bookings (user_id,ride_date,ride_time,status) VALUES (?,?,?,'Pending')",
                    );
                    $stmt->bind_param('iss', $user['id'], $date, $time);
                    $inserted = $stmt->execute();
                    $bookingId = (int) $stmt->insert_id;
                    if (!$inserted) {
                        error_log('GoCycle booking insert failed: ' . $stmt->error);
                    }
                    $stmt->close();

                    if ($inserted) {
                        $when =
                            date('F j, Y', strtotime($date)) .
                            ' at ' .
                            date('g:i A', strtotime($time));
                        $userNotice = gc_notification_create(
                            $conn,
                            'user',
                            (int) $user['id'],
                            'BOOKING_REQUESTED',
                            'Booking request received',
                            'Your ride request for ' . $when . ' is pending owner confirmation.',
                            'user_bookings.php',
                        );
                        $adminNotice = gc_notification_create(
                            $conn,
                            'admin',
                            null,
                            'NEW_BOOKING_REQUEST',
                            'New booking request',
                            $user['username'] .
                                ' requested a ride for ' .
                                $when .
                                '. Review the request and confirm or cancel it.',
                            'admin_bookings.php',
                        );
                        if ($userNotice && $adminNotice) {
                            $conn->commit();
                            log_event(
                                $conn,
                                'BOOKING_REQUESTED',
                                $user['username'],
                                (int) $user['id'],
                                'booking ' . $bookingId . ' for ' . $date . ' ' . $time,
                            );
                            $notice =
                                'Booking request sent. It is pending admin confirmation; check Notifications for updates.';
                            $type = 'success';
                        } else {
                            $conn->rollback();
                            $notice =
                                'The request could not be saved with its notifications. Please try again.';
                        }
                    } else {
                        $conn->rollback();
                        $notice = 'Could not submit the booking request. Please try again.';
                    }
                }
            } catch (Throwable $error) {
                $conn->rollback();
                error_log('GoCycle booking request failed: ' . $error->getMessage());
                $notice = 'The booking request could not be completed. Please try again.';
            } finally {
                $release = $conn->prepare('SELECT RELEASE_LOCK(?)');
                if ($release) {
                    $release->bind_param('s', $lockName);
                    $release->execute();
                    $release->close();
                }
            }
        }
    }
    $_SESSION['ride_notice'] = $notice;
    $_SESSION['ride_notice_type'] = $type;
    header('Location: user_rides.php?ride_date=' . urlencode($redirectDate));
    exit();
}

$_SESSION['ride_notice'] = 'Unsupported booking action.';
$_SESSION['ride_notice_type'] = 'error';
header('Location: user_rides.php');
exit();
