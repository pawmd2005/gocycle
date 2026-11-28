<?php

// Shared booking data also upgrades the old Booked status to Confirmed without losing rows.
require_once __DIR__ . '/booking_helpers.php';
if (!ensure_booking_schema($conn)) {
    http_response_code(503);
    exit(
        'Booking service is temporarily unavailable. Ask the administrator to check the booking schema.'
    );
}

$ride_slots = [
    '07:00:00',
    '08:00:00',
    '09:00:00',
    '10:00:00',
    '11:00:00',
    '13:00:00',
    '14:00:00',
    '15:00:00',
    '16:00:00',
    '17:00:00',
];

$slot_capacity = 5;

$selected_date = $_GET['ride_date'] ?? date('Y-m-d');

if (
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date) ||
    $selected_date < date('Y-m-d') ||
    $selected_date > date('Y-m-d', strtotime('+30 days'))
) {
    $selected_date = date('Y-m-d');
}

$slot_counts = [];
$my_booking_count = 0;
$my_next_booking = null;
$my_bookings = null;

if ($user['role'] !== 'admin') {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM ride_bookings
        WHERE user_id = ?
        AND status IN ('Pending','Confirmed')
        AND ride_date >= CURDATE()
    ");

    if (!$stmt) {
        die(
            'Booking database error. Please check that the ride_bookings table was created using setup.sql.'
        );
    }

    $stmt->bind_param('i', $user['id']);
    $stmt->execute();

    $my_booking_count = (int) $stmt->get_result()->fetch_assoc()['total'];

    $stmt->close();

    $stmt = $conn->prepare("
        SELECT ride_date, ride_time
        FROM ride_bookings
        WHERE user_id = ?
        AND status IN ('Pending','Confirmed')
        AND TIMESTAMP(ride_date, ride_time) >= NOW()
        ORDER BY ride_date, ride_time
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param('i', $user['id']);
        $stmt->execute();

        $my_next_booking = $stmt->get_result()->fetch_assoc();

        $stmt->close();
    }

    $stmt = $conn->prepare("
        SELECT id, ride_date, ride_time, status, created_at
        FROM ride_bookings
        WHERE user_id = ?
        ORDER BY ride_date DESC, ride_time DESC
        LIMIT 50
    ");

    if ($stmt) {
        $stmt->bind_param('i', $user['id']);
        $stmt->execute();

        $my_bookings = $stmt->get_result();
    }

    $stmt = $conn->prepare("
        SELECT ride_time, COUNT(*) AS total
        FROM ride_bookings
        WHERE ride_date = ?
        AND status IN ('Pending','Confirmed')
        GROUP BY ride_time
    ");

    if ($stmt) {
        $stmt->bind_param('s', $selected_date);
        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $slot_counts[$row['ride_time']] = (int) $row['total'];
        }

        $stmt->close();
    }
}

?>
