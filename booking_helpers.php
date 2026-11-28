<?php
declare(strict_types=1);

function ensure_booking_schema(mysqli $conn): bool
{
    static $readyByConnection = [];
    $connectionId = spl_object_id($conn);
    if (array_key_exists($connectionId, $readyByConnection)) {
        return $readyByConnection[$connectionId];
    }

    $create = "CREATE TABLE IF NOT EXISTS ride_bookings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        ride_date DATE NOT NULL,
        ride_time TIME NOT NULL,
        status ENUM('Pending','Confirmed','Cancelled') NOT NULL DEFAULT 'Pending',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ride_slot (ride_date, ride_time, status),
        INDEX idx_user_bookings (user_id, ride_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!$conn->query($create)) {
        error_log('GoCycle booking schema creation failed: ' . $conn->error);
        return $readyByConnection[$connectionId] = false;
    }

    $result = $conn->query('SHOW COLUMNS FROM ride_bookings');
    if (!$result) {
        error_log('GoCycle booking schema inspection failed: ' . $conn->error);
        return $readyByConnection[$connectionId] = false;
    }
    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[$row['Field']] = strtolower((string) $row['Type']);
    }
    $result->free();
    if (!isset($columns['status'])) {
        error_log('GoCycle booking schema is incompatible: ride_bookings.status is missing.');
        return $readyByConnection[$connectionId] = false;
    }

    $statusType = $columns['status'];
    $hasNewStatuses =
        strpos($statusType, 'pending') !== false &&
        strpos($statusType, 'confirmed') !== false &&
        strpos($statusType, 'cancelled') !== false;
    if (!$hasNewStatuses || strpos($statusType, 'booked') !== false) {
        // Widen first so existing 'Booked' rows can be mapped without data loss.
        if (strpos($statusType, 'booked') !== false) {
            $widen =
                "ALTER TABLE ride_bookings MODIFY status ENUM('Booked','Pending','Confirmed','Cancelled') NOT NULL DEFAULT 'Pending'";
            if (!$conn->query($widen)) {
                error_log(
                    'GoCycle booking status migration failed while widening the enum: ' .
                        $conn->error,
                );
                return $readyByConnection[$connectionId] = false;
            }
            if (
                !$conn->query("UPDATE ride_bookings SET status='Confirmed' WHERE status='Booked'")
            ) {
                error_log(
                    'GoCycle booking status migration failed while preserving old bookings: ' .
                        $conn->error,
                );
                return $readyByConnection[$connectionId] = false;
            }
        }
        $finalize =
            "ALTER TABLE ride_bookings MODIFY status ENUM('Pending','Confirmed','Cancelled') NOT NULL DEFAULT 'Pending'";
        if (!$conn->query($finalize)) {
            error_log(
                'GoCycle booking status migration failed while applying the new enum: ' .
                    $conn->error,
            );
            return $readyByConnection[$connectionId] = false;
        }
    }

    return $readyByConnection[$connectionId] = true;
}
