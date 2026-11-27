<?php
declare(strict_types=1);
require_once __DIR__ . '/session.php';
function client_ip(): string
{
    return mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}
function log_event(
    mysqli $conn,
    string $type,
    ?string $typed = null,
    ?int $userId = null,
    string $details = ''
): void {
    $ua = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $ip = client_ip();
    $sql =
        'INSERT INTO activity_log(event_type,username_typed,user_id,ip_address,user_agent,details,created_at) VALUES(?,?,?,?,?,?,NOW())';
    $s = $conn->prepare($sql);
    if ($s) {
        $s->bind_param('ssisss', $type, $typed, $userId, $ip, $ua, $details);
        if (!$s->execute()) {
            error_log('GoCycle activity log insert failed: ' . $s->error);
        }
        $s->close();
        return;
    }
    // Do not crash authentication if an older lab database has a smaller activity_log table.
    $fallback = $conn->prepare(
        'INSERT INTO activity_log(event_type,username_typed,details,created_at) VALUES(?,?,?,NOW())',
    );
    if ($fallback) {
        $fallback->bind_param('sss', $type, $typed, $details);
        $fallback->execute();
        $fallback->close();
        return;
    }
    $minimal = $conn->prepare('INSERT INTO activity_log(event_type) VALUES(?)');
    if ($minimal) {
        $minimal->bind_param('s', $type);
        $minimal->execute();
        $minimal->close();
        return;
    }
    error_log('GoCycle activity logging skipped: ' . $conn->error);
}
function captcha_new(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 6; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    $_SESSION['captcha_challenge'] = [
        'display' => $code,
        'hash' => hash('sha256', $code),
        'issued' => time(),
    ];
    return $code;
}
function captcha_valid(?string $answer): bool
{
    $clean = strtoupper(trim((string) $answer));
    $challenge = $_SESSION['captcha_challenge'] ?? null;
    $ok =
        is_array($challenge) &&
        $clean !== '' &&
        time() - (int) ($challenge['issued'] ?? 0) <= 900 &&
        hash_equals((string) ($challenge['hash'] ?? ''), hash('sha256', $clean));
    if ($ok) {
        unset($_SESSION['captcha_challenge']);
    }
    return $ok;
}
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function issue_otp(mysqli $conn, int $userId, string $purpose, string $email, string $subject): bool
{
    global $config;
    unset($_SESSION['demo_otp'][$purpose]);
    $code = (string) random_int(100000, 999999);
    $hash = hash('sha256', $code);
    $minutes = seed_values()['otp_minutes'];
    $expires = date('Y-m-d H:i:s', time() + $minutes * 60);
    $s = $conn->prepare(
        'UPDATE otp_codes SET used_at=NOW() WHERE user_id=? AND purpose=? AND used_at IS NULL',
    );
    if (!$s) {
        return false;
    }
    $s->bind_param('is', $userId, $purpose);
    $s->execute();
    $s->close();
    $s = $conn->prepare(
        'INSERT INTO otp_codes(user_id,purpose,code_hash,expires_at) VALUES(?,?,?,?)',
    );
    if (!$s) {
        return false;
    }
    $s->bind_param('isss', $userId, $purpose, $hash, $expires);
    $ok = $s->execute();
    $s->close();
    if ($ok) {
        $mail = $config['mail'] ?? [];
        $from = $mail['from'] ?? 'no-reply@example.test';
        $body = "GoCycle\n\n{$subject}\nYour code is {$code}. It expires at {$expires}.\n\nIf you did not request this code, ignore this email and consider changing your password.";
        $sent = smtp_send($mail, $email, $subject, $body);
        if (!$sent) {
            error_log(
                'GoCycle OTP email was not sent. Check SMTP host/port, Gmail address, and Google App Password.',
            );
        }
        log_event($conn, $sent ? 'OTP_SENT' : 'OTP_DELIVERY_FAILED', null, $userId, $purpose);
    }
    return $ok;
}
function demo_otp(string $purpose): ?string
{
    return isset($_SESSION['demo_otp'][$purpose]) ? (string) $_SESSION['demo_otp'][$purpose] : null;
}
function smtp_send(array $mail, string $to, string $subject, string $body): bool
{
    $host = (string) ($mail['host'] ?? '');
    $port = (int) ($mail['port'] ?? 587);
    $user = (string) ($mail['username'] ?? '');
    $pass = (string) ($mail['password'] ?? '');
    $from = trim((string) ($mail['from'] ?? ''));
    if ($from === '') {
        $from = $user;
    }
    if ($host === '' || ($port !== 1025 && ($user === '' || $pass === ''))) {
        return false;
    }
    $socket = @fsockopen($host, $port, $errno, $error, 15);
    if (!$socket) {
        error_log('GoCycle SMTP connection failed: ' . $error);
        return false;
    }
    stream_set_timeout($socket, 15);
    $read = function () use ($socket) {
        $out = '';
        while (($line = fgets($socket, 515)) !== false) {
            $out .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $out;
    };
    $send = function (string $line) use ($socket, $read) {
        fwrite($socket, $line . "\r\n");
        $reply = $read();
        return isset($reply[0]) &&
            in_array((int) substr($reply, 0, 3), [220, 221, 235, 250, 251, 334, 354], true);
    };
    $greet = $read();
    if ((int) substr($greet, 0, 3) !== 220) {
        fclose($socket);
        return false;
    }
    if (!$send('EHLO localhost')) {
        fclose($socket);
        return false;
    }
    if ($port === 587) {
        if (
            !$send('STARTTLS') ||
            !@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) ||
            !$send('EHLO localhost')
        ) {
            fclose($socket);
            return false;
        }
        if (!$send('AUTH LOGIN') || !$send(base64_encode($user)) || !$send(base64_encode($pass))) {
            fclose($socket);
            return false;
        }
    }
    if (!$send('MAIL FROM:<' . $from . '>') || !$send('RCPT TO:<' . $to . '>')) {
        fclose($socket);
        return false;
    }
    $headers =
        'From: ' .
        $from .
        "\r\n" .
        'To: ' .
        $to .
        "\r\n" .
        'Subject: ' .
        mb_encode_mimeheader($subject, 'UTF-8') .
        "\r\n" .
        'Content-Type: text/plain; charset=UTF-8';
    $payload = str_replace(["\r\n", "\r"], "\n", $headers . "\r\n\r\n" . $body);
    $payload = str_replace("\n", "\r\n", $payload);
    $payload = preg_replace('/\r\n\.\r\n/', '\r\n..\r\n', $payload);
    fwrite($socket, "DATA\r\n");
    $reply = $read();
    if ((int) substr($reply, 0, 3) !== 354) {
        fclose($socket);
        return false;
    }
    fwrite($socket, $payload . "\r\n.\r\n");
    $reply = $read();
    $send('QUIT');
    fclose($socket);
    return isset($reply[0]) && in_array((int) substr($reply, 0, 3), [250, 251], true);
}
function verify_otp(mysqli $conn, int $userId, string $purpose, string $code): bool
{
    $s = $conn->prepare(
        'SELECT id,code_hash,attempts FROM otp_codes WHERE user_id=? AND purpose=? AND used_at IS NULL AND expires_at>NOW() ORDER BY id DESC LIMIT 1',
    );
    if (!$s) {
        return false;
    }
    $s->bind_param('is', $userId, $purpose);
    $s->execute();
    $r = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$r || (int) $r['attempts'] >= 3) {
        log_event($conn, 'OTP_FAILED', null, $userId, $purpose);
        return false;
    }
    $hash = hash('sha256', trim($code));
    if (!hash_equals($r['code_hash'], $hash)) {
        $s = $conn->prepare('UPDATE otp_codes SET attempts=attempts+1 WHERE id=?');
        if ($s) {
            $s->bind_param('i', $r['id']);
            $s->execute();
            $s->close();
        }
        log_event($conn, 'OTP_FAILED', null, $userId, $purpose);
        return false;
    }
    $s = $conn->prepare('UPDATE otp_codes SET used_at=NOW() WHERE id=?');
    if ($s) {
        $s->bind_param('i', $r['id']);
        $s->execute();
        $s->close();
    }
    unset($_SESSION['demo_otp'][$purpose]);
    return true;
}

/* ---- OTP resend rules (handout 6.2 #6): 60 s cooldown, max 3 resends per 15 minutes,
        then the user must wait 30 minutes. Counted on the server from the append-only
        activity log, so reloading or clearing cookies does not reset it. ---- */
const GC_OTP_COOLDOWN = 60;
const GC_OTP_RESEND_LIMIT = 3;
const GC_OTP_RESEND_WINDOW = 900;
const GC_OTP_BLOCK_SECONDS = 1800;

function otp_resend_state(mysqli $conn, int $userId, string $purpose): array
{
    $state = ['cooldown' => 0, 'blocked' => 0, 'left' => GC_OTP_RESEND_LIMIT];
    $s = $conn->prepare(
        "SELECT event_type, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS ago FROM activity_log WHERE user_id=? AND details=? AND event_type IN ('OTP_SENT','OTP_DELIVERY_FAILED','OTP_RESEND','OTP_RESEND_BLOCK') AND created_at>=DATE_SUB(NOW(), INTERVAL 30 MINUTE) ORDER BY id DESC",
    );
    if (!$s) {
        return $state;
    }
    $s->bind_param('is', $userId, $purpose);
    $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();
    $lastSend = null;
    $blockAgo = null;
    $resends = [];
    foreach ($rows as $row) {
        $ago = max(0, (int) $row['ago']);
        if ($row['event_type'] === 'OTP_RESEND_BLOCK') {
            $blockAgo = $blockAgo === null ? $ago : min($blockAgo, $ago);
        } elseif ($row['event_type'] === 'OTP_RESEND') {
            $resends[] = $ago;
        }
        if ($row['event_type'] !== 'OTP_RESEND_BLOCK') {
            $lastSend = $lastSend === null ? $ago : min($lastSend, $ago);
        }
    }
    if ($blockAgo !== null && $blockAgo < GC_OTP_BLOCK_SECONDS) {
        $state['blocked'] = GC_OTP_BLOCK_SECONDS - $blockAgo;
    }
    $count = 0;
    foreach ($resends as $ago) {
        if ($ago < GC_OTP_RESEND_WINDOW && ($blockAgo === null || $ago < $blockAgo)) {
            $count++;
        }
    }
    $state['left'] = max(0, GC_OTP_RESEND_LIMIT - $count);
    if ($lastSend !== null && $lastSend < GC_OTP_COOLDOWN) {
        $state['cooldown'] = GC_OTP_COOLDOWN - $lastSend;
    }
    return $state;
}
function otp_block_message(int $seconds): string
{
    $minutes = max(1, (int) ceil($seconds / 60));
    return 'Too many code requests. Try again in ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.';
}
/** Sends a new OTP if the resend rules allow it. Returns the message to show the user. */
function otp_resend(mysqli $conn, int $userId, string $purpose, ?string $email, string $subject): string
{
    $state = otp_resend_state($conn, $userId, $purpose);
    if ($state['blocked'] > 0 || $state['left'] <= 0) {
        return otp_block_message($state['blocked'] > 0 ? $state['blocked'] : GC_OTP_BLOCK_SECONDS);
    }
    if ($state['cooldown'] > 0) {
        return 'Please wait ' . $state['cooldown'] . ' seconds before requesting another code.';
    }
    if ($email === null || !issue_otp($conn, $userId, $purpose, $email, $subject)) {
        return 'The new code could not be sent. Please try again.';
    }
    log_event($conn, 'OTP_RESEND', null, $userId, $purpose);
    if ($state['left'] - 1 <= 0) {
        log_event($conn, 'OTP_RESEND_BLOCK', null, $userId, $purpose);
    }
    return 'A new code was sent to your email.';
}
function otp_resend_form(mysqli $conn, int $userId, string $purpose, string $action = 'resend', string $buttonClass = 'resend-button'): string
{
    $state = otp_resend_state($conn, $userId, $purpose);
    $wait = $state['blocked'] > 0 ? $state['blocked'] : $state['cooldown'];
    $mode = $state['blocked'] > 0 ? 'blocked' : 'cooldown';
    $disabled = $wait > 0 ? ' disabled' : '';
    return '<form method="post" class="resend-form" data-resend-wait="' . (int) $wait . '" data-resend-mode="' . $mode . '">' .
        '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">' .
        '<input type="hidden" name="action" value="' . e($action) . '">' .
        '<button type="submit" class="' . e($buttonClass) . ' resend-button"' . $disabled . '>Send a new code</button>' .
        '<p class="resend-note" aria-live="polite"></p></form>';
}

/* ---- Encryption at rest (protection 13): AES-256-GCM with a random IV per value.
        The key comes from config.php (outside Git); values are decrypted only to show
        them to their owner. ---- */
function gc_enc_key(): string
{
    global $config;
    $secret = (string) ($config['app']['encryption_key'] ?? '');
    return hash('sha256', 'gocycle|' . $secret, true); // 32 bytes = AES-256
}
function gc_encrypt(string $plain): string
{
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', gc_enc_key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($cipher === false) {
        throw new RuntimeException('Encryption failed');
    }
    return base64_encode($iv . $tag . $cipher);
}
function gc_decrypt(?string $stored): ?string
{
    if ($stored === null || $stored === '') {
        return null;
    }
    $raw = base64_decode($stored, true);
    if ($raw === false || strlen($raw) < 29) {
        return null;
    }
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', gc_enc_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? null : $plain;
}
