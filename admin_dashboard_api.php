<?php
define('GC_JSON_REQUEST', true);
require_once 'db.php';
require_once 'session.php';
require_once 'security.php';
require_once 'lockout.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function gc_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    gc_json_response(['error' => 'Method not allowed'], 405);
}

$admin = null;
if (!empty($_SESSION['user_id'])) {
    $stmt = $conn->prepare('SELECT id,username,role FROM users WHERE id=? LIMIT 1');
    if ($stmt) {
        $userId = (int) $_SESSION['user_id'];
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }
}
if (!$admin || $admin['role'] !== 'admin') {
    log_event(
        $conn,
        'ADMIN_ACCESS_DENIED',
        $admin['username'] ?? null,
        isset($admin['id']) ? (int) $admin['id'] : null,
        'admin dashboard data endpoint',
    );
    gc_json_response(['error' => 'Access denied'], 403);
}

function gc_run_query(
    mysqli $conn,
    string $sql,
    string $types = '',
    array $params = []
): mysqli_result {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('query_prepare_failed');
    }
    if ($params) {
        $args = [$types];
        foreach ($params as &$value) {
            $args[] = &$value;
        }
        call_user_func_array([$stmt, 'bind_param'], $args);
        unset($value);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('query_execute_failed');
    }
    $result = $stmt->get_result();
    $stmt->close();
    return $result;
}

function gc_valid_date(string $value): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
        return false;
    }
    [$year, $month, $day] = array_map('intval', explode('-', $value));
    return checkdate($month, $day, $year);
}

try {
    ensure_attempts_table($conn);
    $summary = [
        'successfulLogins' => 0,
        'failedLogins' => 0,
        'lockedAccounts' => 0,
        'blockedIps' => 0,
        'otpsSent' => 0,
        'otpsFailed' => 0,
        'alertEmailsSent' => 0,
    ];
    $counts = gc_run_query(
        $conn,
        'SELECT event_type,COUNT(*) AS total FROM activity_log WHERE created_at>=CURDATE() GROUP BY event_type',
    );
    while ($row = $counts->fetch_assoc()) {
        $key =
            [
                'LOGIN_SUCCESS' => 'successfulLogins',
                'LOGIN_FAILED' => 'failedLogins',
                'OTP_SENT' => 'otpsSent',
                'OTP_FAILED' => 'otpsFailed',
                'ALERT_EMAIL_SENT' => 'alertEmailsSent',
            ][$row['event_type']] ?? null;
        if ($key !== null) {
            $summary[$key] = (int) $row['total'];
        }
    }
    $row = gc_run_query(
        $conn,
        'SELECT COUNT(*) AS total FROM login_attempts WHERE locked_until IS NOT NULL AND locked_until>UNIX_TIMESTAMP()',
    )->fetch_assoc();
    $summary['lockedAccounts'] = (int) ($row['total'] ?? 0);
    $row = gc_run_query(
        $conn,
        'SELECT COUNT(*) AS total FROM ip_blocks WHERE blocked_until IS NOT NULL AND blocked_until>NOW()',
    )->fetch_assoc();
    $summary['blockedIps'] = (int) ($row['total'] ?? 0);

    $hourStart = strtotime(date('Y-m-d H:00:00')) - 23 * 3600;
    $hourEnd = $hourStart + 24 * 3600;
    $startSql = date('Y-m-d H:i:s', $hourStart);
    $endSql = date('Y-m-d H:i:s', $hourEnd);
    $hourRows = gc_run_query(
        $conn,
        "SELECT DATE_FORMAT(created_at,'%Y-%m-%d %H:00:00') AS hour_key,COUNT(*) AS total FROM activity_log WHERE event_type='LOGIN_FAILED' AND created_at>=? AND created_at<? GROUP BY hour_key ORDER BY hour_key",
        'ss',
        [$startSql, $endSql],
    );
    $hourTotals = [];
    while ($row = $hourRows->fetch_assoc()) {
        $hourTotals[$row['hour_key']] = (int) $row['total'];
    }
    $hourly = ['labels' => [], 'values' => []];
    for ($i = 0; $i < 24; $i++) {
        $timestamp = $hourStart + $i * 3600;
        $hourly['labels'][] = date('H:i', $timestamp);
        $key = date('Y-m-d H:00:00', $timestamp);
        $hourly['values'][] = $hourTotals[$key] ?? 0;
    }

    $topIpRows = gc_run_query(
        $conn,
        "SELECT al.ip_address,COUNT(*) AS failures,MAX(CASE WHEN ib.blocked_until>NOW() THEN 1 ELSE 0 END) AS blocked FROM activity_log al LEFT JOIN ip_blocks ib ON ib.ip_address=al.ip_address WHERE al.event_type='LOGIN_FAILED' AND al.created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) GROUP BY al.ip_address ORDER BY failures DESC,al.ip_address ASC LIMIT 5",
    );
    $topIps = [];
    while ($row = $topIpRows->fetch_assoc()) {
        $topIps[] = [
            'ip' => (string) $row['ip_address'],
            'failures' => (int) $row['failures'],
            'blocked' => (bool) $row['blocked'],
        ];
    }

    $flagRows = gc_run_query(
        $conn,
        'SELECT severity,pattern_name,target,details,created_at FROM security_flags ORDER BY id DESC LIMIT 25',
    );
    $flags = [];
    while ($row = $flagRows->fetch_assoc()) {
        $flags[] = $row;
    }

    $from = trim((string) ($_GET['from'] ?? ''));
    $to = trim((string) ($_GET['to'] ?? ''));
    $eventType = strtoupper(trim((string) ($_GET['event_type'] ?? '')));
    $username = trim((string) ($_GET['username'] ?? ''));
    $ip = trim((string) ($_GET['ip'] ?? ''));
    $where = [];
    $types = '';
    $params = [];
    if ($from !== '' && gc_valid_date($from)) {
        $where[] = 'al.created_at>=?';
        $types .= 's';
        $params[] = $from . ' 00:00:00';
    }
    if ($to !== '' && gc_valid_date($to)) {
        $where[] = 'al.created_at<DATE_ADD(?,INTERVAL 1 DAY)';
        $types .= 's';
        $params[] = $to . ' 00:00:00';
    }
    if ($eventType !== '' && preg_match('/^[A-Z0-9_]{1,40}$/D', $eventType)) {
        $where[] = 'al.event_type=?';
        $types .= 's';
        $params[] = $eventType;
    }
    if ($username !== '') {
        $where[] = "COALESCE(NULLIF(al.username_typed,''),u.username,'') LIKE ?";
        $types .= 's';
        $params[] = '%' . addcslashes(mb_substr($username, 0, 100), '\\%_') . '%';
    }
    if ($ip !== '') {
        $where[] = 'al.ip_address LIKE ?';
        $types .= 's';
        $params[] = '%' . addcslashes(mb_substr($ip, 0, 45), '\\%_') . '%';
    }
    $filterSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $eventRows = gc_run_query(
        $conn,
        "SELECT al.event_type,COALESCE(NULLIF(al.username_typed,''),u.username,'') AS username,al.ip_address,al.user_agent,al.details,al.created_at FROM activity_log al LEFT JOIN users u ON u.id=al.user_id" .
            $filterSql .
            ' ORDER BY al.id DESC LIMIT 50',
        $types,
        $params,
    );
    $events = [];
    while ($row = $eventRows->fetch_assoc()) {
        $events[] = $row;
    }

    gc_json_response([
        'summary' => $summary,
        'hourlyFailures' => $hourly,
        'topIps' => $topIps,
        'flags' => $flags,
        'events' => $events,
        'generatedAt' => date(DATE_ATOM),
    ]);
} catch (Throwable $error) {
    error_log('GoCycle dashboard data error: ' . $error->getMessage());
    gc_json_response(['error' => 'Security data is temporarily unavailable.'], 503);
}
