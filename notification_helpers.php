<?php
declare(strict_types=1);

function gc_notifications_ensure(mysqli $conn): bool
{
    static $readyByConnection = [];
    $connectionId = spl_object_id($conn);
    if (array_key_exists($connectionId, $readyByConnection)) {
        return $readyByConnection[$connectionId];
    }
    $sql = "CREATE TABLE IF NOT EXISTS notifications (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        recipient_role ENUM('user','admin') NOT NULL,
        recipient_user_id INT NULL,
        notification_type VARCHAR(40) NOT NULL,
        title VARCHAR(120) NOT NULL,
        message VARCHAR(500) NOT NULL,
        link_path VARCHAR(255) NOT NULL DEFAULT 'notifications.php',
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_notification_user (recipient_role, recipient_user_id, is_read, created_at),
        INDEX idx_notification_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!$conn->query($sql)) {
        error_log('GoCycle notification table setup failed: ' . $conn->error);
        return $readyByConnection[$connectionId] = false;
    }
    return $readyByConnection[$connectionId] = true;
}

function gc_notification_text(string $value, int $limit): string
{
    return function_exists('mb_substr')
        ? mb_substr($value, 0, $limit, 'UTF-8')
        : substr($value, 0, $limit);
}

function gc_notification_create(
    mysqli $conn,
    string $role,
    ?int $userId,
    string $type,
    string $title,
    string $message,
    string $link
): bool {
    if (!in_array($role, ['user', 'admin'], true) || !gc_notifications_ensure($conn)) {
        return false;
    }
    $stmt = $conn->prepare(
        'INSERT INTO notifications (recipient_role,recipient_user_id,notification_type,title,message,link_path) VALUES (?,?,?,?,?,?)',
    );
    if (!$stmt) {
        error_log('GoCycle notification insert prepare failed: ' . $conn->error);
        return false;
    }
    $type = gc_notification_text($type, 40);
    $title = gc_notification_text($title, 120);
    $message = gc_notification_text($message, 500);
    $link = gc_notification_text($link, 255);
    $stmt->bind_param('sissss', $role, $userId, $type, $title, $message, $link);
    $ok = $stmt->execute();
    if (!$ok) {
        error_log('GoCycle notification insert failed: ' . $stmt->error);
    }
    $stmt->close();
    return $ok;
}

function gc_notification_unread_count(mysqli $conn, array $user): int
{
    if (!gc_notifications_ensure($conn)) {
        return 0;
    }
    if (($user['role'] ?? '') === 'admin') {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total FROM notifications WHERE recipient_role='admin' AND recipient_user_id IS NULL AND is_read=0",
        );
        if (!$stmt) {
            return 0;
        }
        $stmt->execute();
    } else {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total FROM notifications WHERE recipient_role='user' AND recipient_user_id=? AND is_read=0",
        );
        if (!$stmt) {
            return 0;
        }
        $userId = (int) ($user['id'] ?? 0);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
    }
    $count = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $count;
}
