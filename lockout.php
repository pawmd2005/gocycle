<?php
// Account lockout: 5 failed logins => locked for 5 minutes, then auto-unlocks.

const MAX_ATTEMPTS    = 5;     // failed attempts allowed
const LOCKOUT_SECONDS = 300;   // 5 minutes

function ensure_attempts_table(mysqli $conn): void {
    $conn->query("
        CREATE TABLE IF NOT EXISTS login_attempts (
            username     VARCHAR(191) PRIMARY KEY,
            failed_count INT    NOT NULL DEFAULT 0,
            locked_until BIGINT NULL
        )
    ");
}

// Same key the users table matches on (trimmed, max 191 chars).
function attempt_key(string $username): string {
    return mb_substr(trim($username), 0, 191);
}

function get_attempt_row(mysqli $conn, string $key): ?array {
    $stmt = $conn->prepare(
        "SELECT failed_count, locked_until FROM login_attempts WHERE username = ?"
    );
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

// Is this account locked right now? Also clears an expired lock so the
// counter starts again from 0 (this is the automatic "unlock after 5 min").
function lock_seconds_left(mysqli $conn, string $key): int {
    $row = get_attempt_row($conn, $key);

    if (!$row || $row["locked_until"] === null) {
        return 0;
    }

    $left = (int)$row["locked_until"] - time();

    if ($left > 0) {
        return $left;
    }

    reset_attempts($conn, $key);    // lock expired -> fresh start
    return 0;
}

// One atomic statement, so parallel requests cannot slip past the counter.
// MySQL evaluates the SET list left to right: failed_count is updated first,
// then locked_until is decided from the new count.
function record_failed_attempt(mysqli $conn, string $key): array {
    $now   = time();
    $max   = MAX_ATTEMPTS;
    $until = $now + LOCKOUT_SECONDS;

    $stmt = $conn->prepare("
        INSERT INTO login_attempts (username, failed_count, locked_until)
        VALUES (?, 1, NULL)
        ON DUPLICATE KEY UPDATE
            failed_count = IF(locked_until IS NOT NULL AND locked_until <= ?, 1, failed_count + 1),
            locked_until = IF(failed_count >= ?, ?, NULL)
    ");
    $stmt->bind_param("siii", $key, $now, $max, $until);
    $stmt->execute();

    $row = get_attempt_row($conn, $key);

    return [
        "failed_count" => (int)($row["failed_count"] ?? 1),
        "locked_until" => isset($row["locked_until"]) ? (int)$row["locked_until"] : null,
    ];
}

function reset_attempts(mysqli $conn, string $key): void {
    $stmt = $conn->prepare("DELETE FROM login_attempts WHERE username = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
}

function lock_message(int $seconds_left): string {
    $m = intdiv($seconds_left, 60);
    $s = $seconds_left % 60;
    return "Account temporarily locked. Try again in {$m}m " . str_pad((string)$s, 2, "0", STR_PAD_LEFT) . "s.";
}
