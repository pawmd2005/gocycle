<?php
declare(strict_types=1);

/*
 * Project security settings (same for everyone, from the updated handout, section 2.3).
 * The old student-number formulas (D1 / D2 / mod) are no longer used.
 */
function seed_values(): array
{
    return [
        'min_user' => 6, // minimum username length
        'max_user' => 20, // maximum username length
        'min_pass' => 8, // minimum password length
        'max_name' => 50, // maximum length of each name field
        'forgot_after' => 2, // failed log-ins before "Forgot Password?" appears
        'tier1' => 15, // lockout after 3 failed log-ins (seconds)
        'tier2' => 30, // lockout after 6 failed log-ins (seconds)
        'tier3' => 60, // lockout after 9 failed log-ins (seconds)
        'otp_minutes' => 5, // OTP expiry
        'idle_minutes' => 15, // idle session timeout
        'ip_threshold' => 10, // failures in 10 minutes from one IP (any account)
    ];
}

function lockout_db_error(mysqli $conn, string $operation): void
{
    error_log('GoCycle lockout ' . $operation . ' failed: ' . $conn->error);
}

function ensure_attempts_table(mysqli $conn): bool
{
    static $readyByConnection = [];
    $connectionId = spl_object_id($conn);
    if (array_key_exists($connectionId, $readyByConnection)) {
        return $readyByConnection[$connectionId];
    }

    $create = [
        'login_attempts' =>
            'CREATE TABLE IF NOT EXISTS login_attempts (username VARCHAR(191) PRIMARY KEY, failed_count INT NOT NULL DEFAULT 0, locked_until BIGINT NULL, last_failed_at DATETIME NULL, last_locked_count INT NOT NULL DEFAULT 0)',
        'ip_blocks' =>
            'CREATE TABLE IF NOT EXISTS ip_blocks (ip_address VARCHAR(45) PRIMARY KEY, failed_count INT NOT NULL DEFAULT 0, window_started DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, blocked_until DATETIME NULL)',
    ];
    foreach ($create as $table => $sql) {
        if (!$conn->query($sql)) {
            lockout_db_error($conn, 'table creation for ' . $table);
            return $readyByConnection[$connectionId] = false;
        }
    }

    $required = [
        'login_attempts' => [
            'username' => 'VARCHAR(191) NOT NULL',
            'failed_count' => 'INT NOT NULL DEFAULT 0',
            'locked_until' => 'BIGINT NULL',
            'last_failed_at' => 'DATETIME NULL',
            'last_locked_count' => 'INT NOT NULL DEFAULT 0',
        ],
        'ip_blocks' => [
            'ip_address' => 'VARCHAR(45) NOT NULL',
            'failed_count' => 'INT NOT NULL DEFAULT 0',
            'window_started' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'blocked_until' => 'DATETIME NULL',
        ],
    ];
    foreach ($required as $table => $columns) {
        $result = $conn->query('SHOW COLUMNS FROM `' . $table . '`');
        if (!$result) {
            lockout_db_error($conn, 'schema inspection for ' . $table);
            return $readyByConnection[$connectionId] = false;
        }
        $present = [];
        $types = [];
        while ($row = $result->fetch_assoc()) {
            $present[$row['Field']] = true;
            $types[$row['Field']] = strtolower((string) $row['Type']);
        }
        $result->free();

        $primary = $table === 'login_attempts' ? 'username' : 'ip_address';
        if (!isset($present[$primary])) {
            error_log(
                'GoCycle lockout schema is incompatible: missing ' .
                    $table .
                    '.' .
                    $primary .
                    '. Re-run setup.sql after backing up the database.',
            );
            return $readyByConnection[$connectionId] = false;
        }
        foreach ($columns as $column => $definition) {
            if (isset($present[$column])) {
                continue;
            }
            $sql = 'ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition;
            if (!$conn->query($sql)) {
                lockout_db_error($conn, 'adding ' . $table . '.' . $column);
                return $readyByConnection[$connectionId] = false;
            }
        }

        // Older copies sometimes declared locked_until as DATETIME, but the application
        // stores Unix timestamps there. Clear incompatible legacy values before converting.
        if (
            $table === 'login_attempts' &&
            isset($types['locked_until']) &&
            strpos($types['locked_until'], 'bigint') !== 0
        ) {
            if (
                !$conn->query('UPDATE login_attempts SET locked_until=NULL') ||
                !$conn->query('ALTER TABLE login_attempts MODIFY locked_until BIGINT NULL')
            ) {
                lockout_db_error($conn, 'converting login_attempts.locked_until');
                return $readyByConnection[$connectionId] = false;
            }
        }
    }

    return $readyByConnection[$connectionId] = true;
}

function attempt_key(string $username): string
{
    // Registered usernames are restricted to ASCII letters and digits.
    return strtolower(substr(trim($username), 0, 191));
}

function ip_is_blocked(mysqli $conn, string $ip): bool
{
    $s = $conn->prepare(
        'SELECT 1 FROM ip_blocks WHERE ip_address=? AND blocked_until IS NOT NULL AND blocked_until>NOW() LIMIT 1',
    );
    if (!$s) {
        lockout_db_error($conn, 'checking IP block');
        return false;
    }
    $s->bind_param('s', $ip);
    $ok = $s->execute();
    $blocked = $ok && (bool) $s->get_result()->fetch_row();
    if (!$ok) {
        lockout_db_error($conn, 'checking IP block');
    }
    $s->close();
    return $blocked;
}

function lock_seconds_left(mysqli $conn, string $key): int
{
    $s = $conn->prepare('SELECT locked_until FROM login_attempts WHERE username=? LIMIT 1');
    if (!$s) {
        lockout_db_error($conn, 'checking account lock');
        return 0;
    }
    $s->bind_param('s', $key);
    $ok = $s->execute();
    $row = $ok ? $s->get_result()->fetch_assoc() : null;
    if (!$ok) {
        lockout_db_error($conn, 'checking account lock');
    }
    $s->close();
    return max(0, (int) ($row['locked_until'] ?? 0) - time());
}

function notify_lockout_tier(
    mysqli $conn,
    string $key,
    int $count,
    string $ip,
    ?array $account,
    int $tier
): void {
    if (
        !$account ||
        empty($account['email']) ||
        !function_exists('smtp_send') ||
        !function_exists('log_event')
    ) {
        return;
    }
    global $config;
    $subject = 'Failed sign-in attempts on your account';
    $when = date('Y-m-d H:i:s T');
    $browser = function_exists('mb_substr')
        ? mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'), 0, 500)
        : substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'), 0, 500);
    $baseUrl = rtrim((string) ($config['app']['base_url'] ?? 'http://localhost/gocycle2'), '/');
    $body = "GoCycle security alert\n\n";
    $body .= "Your account reached lockout tier {$tier} ({$count} failed attempts).\n";
    $body .= "Date and time: {$when}\nIP address: {$ip}\nBrowser/device: {$browser}\n\n";
    $body .= "If this was not you, reset your password now: {$baseUrl}/bruteforce_lab/forgot.php\n";
    $body .= 'Never share your password or verification code.';
    $mail = $config['mail'] ?? [];
    if (smtp_send($mail, (string) $account['email'], $subject, $body)) {
        log_event(
            $conn,
            'ALERT_EMAIL_SENT',
            $key,
            isset($account['id']) ? (int) $account['id'] : null,
            'lockout tier ' . $tier,
        );
    }
}

function record_failed_attempt(mysqli $conn, string $key, string $ip, ?array $account = null): array
{
    if (!ensure_attempts_table($conn)) {
        return [
            'failed_count' => 0,
            'locked_until' => null,
            'ip_blocked' => false,
            'storage_error' => true,
        ];
    }
    $seed = seed_values();

    // Counts survive timed locks. Successful OTP-verified login resets the counter.
    $s = $conn->prepare(
        'INSERT INTO login_attempts(username,failed_count,locked_until,last_failed_at) VALUES(?,1,NULL,NOW()) ON DUPLICATE KEY UPDATE failed_count=failed_count+1,locked_until=NULL,last_failed_at=NOW()',
    );
    if (!$s) {
        lockout_db_error($conn, 'preparing failed-attempt write');
        return [
            'failed_count' => 0,
            'locked_until' => null,
            'ip_blocked' => false,
            'storage_error' => true,
        ];
    }
    $s->bind_param('s', $key);
    if (!$s->execute()) {
        lockout_db_error($conn, 'writing failed attempt');
        $s->close();
        return [
            'failed_count' => 0,
            'locked_until' => null,
            'ip_blocked' => false,
            'storage_error' => true,
        ];
    }
    $s->close();

    $s = $conn->prepare(
        'SELECT failed_count,last_locked_count FROM login_attempts WHERE username=? LIMIT 1',
    );
    if (!$s) {
        lockout_db_error($conn, 'preparing failed-attempt read');
        return [
            'failed_count' => 0,
            'locked_until' => null,
            'ip_blocked' => false,
            'storage_error' => true,
        ];
    }
    $s->bind_param('s', $key);
    if (!$s->execute()) {
        lockout_db_error($conn, 'reading failed-attempt count');
        $s->close();
        return [
            'failed_count' => 0,
            'locked_until' => null,
            'ip_blocked' => false,
            'storage_error' => true,
        ];
    }
    $row = $s->get_result()->fetch_assoc() ?: [];
    $s->close();
    $count = (int) ($row['failed_count'] ?? 1);
    $lastLockedCount = (int) ($row['last_locked_count'] ?? 0);
    $thresholds = [1 => 3, 2 => 6, 3 => 9];
    $durations = [1 => $seed['tier1'], 2 => $seed['tier2'], 3 => $seed['tier3']];
    $tier =
        $count >= 9 && ($lastLockedCount < 9 || $count > $lastLockedCount)
            ? 3
            : ($count >= 6 && $lastLockedCount < 6
                ? 2
                : ($count >= 3 && $lastLockedCount < 3
                    ? 1
                    : 0));
    $lockedUntil = null;
    if ($tier > 0) {
        $lockedUntil = time() + $durations[$tier];
        $s = $conn->prepare(
            'UPDATE login_attempts SET locked_until=?,last_locked_count=? WHERE username=?',
        );
        if (!$s) {
            lockout_db_error($conn, 'preparing account lock');
            return [
                'failed_count' => $count,
                'locked_until' => null,
                'ip_blocked' => false,
                'storage_error' => true,
            ];
        }
        $s->bind_param('iis', $lockedUntil, $count, $key);
        if (!$s->execute()) {
            lockout_db_error($conn, 'setting account lock');
            $s->close();
            return [
                'failed_count' => $count,
                'locked_until' => null,
                'ip_blocked' => false,
                'storage_error' => true,
            ];
        }
        $s->close();
        $firstReached = in_array($count, array_values($thresholds), true);
        if ($firstReached && function_exists('log_event')) {
            log_event(
                $conn,
                'LOCKOUT_TIER_' . $tier,
                $key,
                isset($account['id']) ? (int) $account['id'] : null,
                $count . ' consecutive failures',
            );
        }
        if ($firstReached) {
            notify_lockout_tier($conn, $key, $count, $ip, $account, $tier);
        }
    }

    $s = $conn->prepare(
        'INSERT INTO ip_blocks(ip_address,failed_count,window_started,blocked_until) VALUES(?,1,NOW(),NULL) ON DUPLICATE KEY UPDATE failed_count=IF(window_started<=DATE_SUB(NOW(),INTERVAL 10 MINUTE) OR (blocked_until IS NOT NULL AND blocked_until<=NOW()),1,failed_count+1),window_started=IF(window_started<=DATE_SUB(NOW(),INTERVAL 10 MINUTE) OR (blocked_until IS NOT NULL AND blocked_until<=NOW()),NOW(),window_started),blocked_until=IF(blocked_until IS NOT NULL AND blocked_until<=NOW(),NULL,blocked_until)',
    );
    if (!$s) {
        lockout_db_error($conn, 'preparing IP failure write');
        return [
            'failed_count' => $count,
            'locked_until' => $lockedUntil,
            'ip_blocked' => false,
            'storage_error' => true,
        ];
    }
    $s->bind_param('s', $ip);
    if (!$s->execute()) {
        lockout_db_error($conn, 'writing IP failure');
        $s->close();
        return [
            'failed_count' => $count,
            'locked_until' => $lockedUntil,
            'ip_blocked' => false,
            'storage_error' => true,
        ];
    }
    $s->close();

    $s = $conn->prepare('SELECT failed_count FROM ip_blocks WHERE ip_address=? LIMIT 1');
    if (!$s) {
        lockout_db_error($conn, 'preparing IP failure read');
        return [
            'failed_count' => $count,
            'locked_until' => $lockedUntil,
            'ip_blocked' => false,
            'storage_error' => true,
        ];
    }
    $s->bind_param('s', $ip);
    if (!$s->execute()) {
        lockout_db_error($conn, 'reading IP failure count');
        $s->close();
        return [
            'failed_count' => $count,
            'locked_until' => $lockedUntil,
            'ip_blocked' => false,
            'storage_error' => true,
        ];
    }
    $ipRow = $s->get_result()->fetch_assoc() ?: [];
    $s->close();
    $ipCount = (int) ($ipRow['failed_count'] ?? 1);
    $ipBlocked = false;
    if ($ipCount >= $seed['ip_threshold']) {
        $s = $conn->prepare(
            'UPDATE ip_blocks SET blocked_until=DATE_ADD(NOW(),INTERVAL 10 MINUTE) WHERE ip_address=? AND (blocked_until IS NULL OR blocked_until<=NOW())',
        );
        if (!$s) {
            lockout_db_error($conn, 'preparing IP block');
            return [
                'failed_count' => $count,
                'locked_until' => $lockedUntil,
                'ip_blocked' => false,
                'storage_error' => true,
            ];
        }
        $s->bind_param('s', $ip);
        if (!$s->execute()) {
            lockout_db_error($conn, 'setting IP block');
            $s->close();
            return [
                'failed_count' => $count,
                'locked_until' => $lockedUntil,
                'ip_blocked' => false,
                'storage_error' => true,
            ];
        }
        $ipBlocked = $s->affected_rows > 0;
        $s->close();
        if ($ipBlocked && function_exists('log_event')) {
            log_event(
                $conn,
                'IP_BLOCKED',
                $key,
                isset($account['id']) ? (int) $account['id'] : null,
                $ipCount . ' failures in 10 minutes',
            );
        }
    }

    return [
        'failed_count' => $count,
        'locked_until' => $lockedUntil,
        'ip_blocked' => $ipBlocked,
        'storage_error' => false,
    ];
}

function reset_attempts(mysqli $conn, string $key): void
{
    $s = $conn->prepare(
        'UPDATE login_attempts SET failed_count=0,locked_until=NULL,last_locked_count=0 WHERE username=?',
    );
    if (!$s) {
        lockout_db_error($conn, 'preparing counter reset');
        return;
    }
    $s->bind_param('s', $key);
    if (!$s->execute()) {
        lockout_db_error($conn, 'resetting counter');
    }
    $s->close();
}

function lock_message(int $left): string
{
    return 'Account temporarily locked. Try again in ' .
        intdiv($left, 60) .
        'm ' .
        str_pad((string) ($left % 60), 2, '0', STR_PAD_LEFT) .
        's.';
}
