<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
function csrf_valid(?string $token): bool
{
    return is_string($token) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}
function gc_idle_timeout_seconds(): int
{
    if (!function_exists('seed_values')) {
        require_once __DIR__ . '/lockout.php';
    }
    return seed_values()['idle_minutes'] * 60; // 15 minutes
}
function enforce_idle_timeout(): void
{
    if (empty($_SESSION['user_id'])) {
        return;
    }
    $timeout = gc_idle_timeout_seconds();
    $apiActivity =
        defined('GC_JSON_REQUEST') &&
        GC_JSON_REQUEST &&
        ($_SERVER['HTTP_X_GC_USER_ACTIVITY'] ?? '') === '1';
    if (
        isset($_SESSION['last_activity']) &&
        time() - (int) $_SESSION['last_activity'] > $timeout &&
        !$apiActivity
    ) {
        $_SESSION = [];
        session_destroy();
        if (defined('GC_JSON_REQUEST') && GC_JSON_REQUEST) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo '{}';
            exit();
        }
        header('Location: login.php?expired=1');
        exit();
    }
    if (!defined('GC_JSON_REQUEST') || !GC_JSON_REQUEST || $apiActivity) {
        $_SESSION['last_activity'] = time();
    }
}
enforce_idle_timeout();
function require_login(mysqli $conn): array
{
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit();
    }
    $s = $conn->prepare('SELECT id,username,email,role,created_at FROM users WHERE id=? LIMIT 1');
    $id = (int) $_SESSION['user_id'];
    $s->bind_param('i', $id);
    $s->execute();
    $u = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$u) {
        $_SESSION = [];
        session_destroy();
        header('Location: login.php');
        exit();
    }
    return $u;
}
