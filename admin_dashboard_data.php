<?php
$total_users = 0;
$locked_accounts = 0;
$failed_attempts = 0;
if ($user['role'] === 'admin') {
    $result = $conn->query("SELECT COUNT(*) AS total FROM users");
    if ($result) $total_users = (int)$result->fetch_assoc()['total'];
    ensure_attempts_table($conn);
    $result = $conn->query("SELECT COUNT(*) AS total FROM login_attempts WHERE locked_until IS NOT NULL AND locked_until > UNIX_TIMESTAMP()");
    if ($result) $locked_accounts = (int)$result->fetch_assoc()['total'];
    $result = $conn->query("SELECT COALESCE(SUM(failed_count), 0) AS total FROM login_attempts");
    if ($result) $failed_attempts = (int)$result->fetch_assoc()['total'];
}
?>