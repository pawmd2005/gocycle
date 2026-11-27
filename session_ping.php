<?php
// Session helper for the "Your session is about to expire" reminder.
//   GET  -> report the seconds left, without extending the session.
//   POST -> extend the session ("Stay signed in"); needs the CSRF token.
// A session that already expired is never revived (session.php answers 401 first).
define('GC_JSON_REQUEST', true);
require_once 'db.php';
require_once 'session.php';
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo '{"ok":false,"remaining":0}';
    exit();
}
$timeout = gc_idle_timeout_seconds();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        http_response_code(403);
        echo '{"ok":false,"remaining":0}';
        exit();
    }
    $_SESSION['last_activity'] = time();
    echo json_encode(['ok' => true, 'remaining' => $timeout]);
    exit();
}
$left = $timeout - (time() - (int) ($_SESSION['last_activity'] ?? time()));
echo json_encode(['ok' => true, 'remaining' => max(0, $left)]);
