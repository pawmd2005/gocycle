<?php
require_once 'db.php';
require_once 'session.php';
require_once 'security.php';

$user = null;
if (!empty($_SESSION['user_id'])) {
    $stmt = $conn->prepare(
        'SELECT id,username,email,role,created_at FROM users WHERE id=? LIMIT 1',
    );
    if ($stmt) {
        $userId = (int) $_SESSION['user_id'];
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }
}
if (!$user || $user['role'] !== 'admin') {
    http_response_code(403);
    log_event(
        $conn,
        'ADMIN_ACCESS_DENIED',
        $user['username'] ?? null,
        isset($user['id']) ? (int) $user['id'] : null,
        'legacy admin route',
    );
    exit('Access denied');
}
header('Location: admin_dashboard.php', true, 302);
exit();
