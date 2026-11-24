<?php
require 'session.php';
require 'db.php';
require 'lockout.php';
$user = require_login($conn);
if ($user['role'] === 'admin') { header('Location: dashboard.php'); exit; }
require 'ride_booking_data.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid($_POST['csrf'] ?? null)) {
    $_SESSION['ride_notice'] = 'Invalid request. Please try again.'; $_SESSION['ride_notice_type'] = 'error';
    header('Location: user_rides.php'); exit;
}
$action = $_POST['booking_action'] ?? '';
$redirect_date = $_POST['ride_date'] ?? date('Y-m-d');
if ($action === 'cancel') {
    $id = filter_var($_POST['booking_id'] ?? null, FILTER_VALIDATE_INT);
    if ($id) {
        $stmt = $conn->prepare("UPDATE ride_bookings SET status='Cancelled' WHERE id=? AND user_id=? AND status='Booked' AND TIMESTAMP(ride_date, ride_time) >= NOW()");
        if ($stmt) { $stmt->bind_param('ii', $id, $user['id']); $stmt->execute(); $ok = $stmt->affected_rows > 0; $stmt->close(); } else $ok = false;
        $_SESSION['ride_notice'] = $ok ? 'Your booking has been cancelled.' : 'This booking cannot be cancelled.';
        $_SESSION['ride_notice_type'] = $ok ? 'success' : 'error';
    }
} elseif ($action === 'book') {
    $date = $_POST['ride_date'] ?? ''; $time = $_POST['ride_time'] ?? '';
    $valid_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && $date >= date('Y-m-d') && $date <= date('Y-m-d', strtotime('+30 days'));
    if (!$valid_date || !in_array($time, $ride_slots, true) || ($date === date('Y-m-d') && $time <= date('H:i:s'))) {
        $notice = 'Please choose a valid future date and time.'; $type = 'error';
    } else {
        $lock = 'gocycle_' . $date . '_' . str_replace(':', '', $time);
        $stmt = $conn->prepare('SELECT GET_LOCK(?, 5) AS got_lock'); $stmt->bind_param('s', $lock); $stmt->execute();
        $got = (int)($stmt->get_result()->fetch_assoc()['got_lock'] ?? 0); $stmt->close();
        if (!$got) { $notice = 'This slot is being booked right now. Please try again.'; $type = 'error'; }
        else {
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM ride_bookings WHERE ride_date=? AND ride_time=? AND status='Booked'"); $stmt->bind_param('ss', $date, $time); $stmt->execute(); $count = (int)$stmt->get_result()->fetch_assoc()['total']; $stmt->close();
            $stmt = $conn->prepare("SELECT id FROM ride_bookings WHERE user_id=? AND ride_date=? AND ride_time=? AND status='Booked' LIMIT 1"); $stmt->bind_param('iss', $user['id'], $date, $time); $stmt->execute(); $duplicate = $stmt->get_result()->num_rows > 0; $stmt->close();
            if ($duplicate) { $notice = 'You already booked this time slot.'; $type = 'error'; }
            elseif ($count >= $slot_capacity) { $notice = 'This time slot is full. Please choose another.'; $type = 'error'; }
            else { $stmt = $conn->prepare("INSERT INTO ride_bookings (user_id, ride_date, ride_time, status) VALUES (?, ?, ?, 'Booked')"); $stmt->bind_param('iss', $user['id'], $date, $time); $ok = $stmt->execute(); $stmt->close(); $notice = $ok ? 'Ride booked successfully!' : 'Could not complete the booking. Please try again.'; $type = $ok ? 'success' : 'error'; }
            $stmt = $conn->prepare('SELECT RELEASE_LOCK(?)'); $stmt->bind_param('s', $lock); $stmt->execute(); $stmt->close();
        }
    }
    $_SESSION['ride_notice'] = $notice; $_SESSION['ride_notice_type'] = $type ?? 'error'; $redirect_date = $date ?: date('Y-m-d');
}
$redirect_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $redirect_date) ? $redirect_date : date('Y-m-d');
header('Location: ' . ($action === 'cancel' ? 'user_bookings.php' : 'user_rides.php?ride_date=' . urlencode($redirect_date))); exit;
?>
