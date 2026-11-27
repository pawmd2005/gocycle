<?php
require_once 'db.php';
require_once 'session.php';
require_once 'lockout.php';
require_once 'security.php';

if (($_SESSION['reset_stage'] ?? '') !== 'change' || empty($_SESSION['reset_user_id'])) {
    http_response_code(403);
    exit('Access denied. Complete password recovery first.');
}

$id = (int) $_SESSION['reset_user_id'];
$v = seed_values();
$msg = '';

$userStmt = $conn->prepare('SELECT id,password,email,username FROM users WHERE id=? LIMIT 1');
if (!$userStmt) {
    http_response_code(503);
    exit('Password change is temporarily unavailable. Please try again later.');
}
$userStmt->bind_param('i', $id);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();
$userStmt->close();
if (!$user) {
    http_response_code(403);
    exit('Access denied. Complete password recovery again.');
}

// The reset OTP was already verified on forgot.php (reset_stage becomes 'change'
// only after the correct code), so the handout flow is: question -> OTP -> new
// password. No second code is requested here.
$otpVerified = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $msg = 'Your form expired. Please try again.';
    } elseif (!$otpVerified && ($_POST['action'] ?? '') === 'resend') {
        $lastResend = (int) ($_SESSION['otp_resend_at']['reset_change'] ?? 0);
        if ($lastResend > 0 && time() - $lastResend < 30) {
            $msg = 'Please wait 30 seconds before requesting another code.';
        } elseif (issue_otp($conn, $id, 'reset', $user['email'], 'Confirm your password change')) {
            $_SESSION['otp_resend_at']['reset_change'] = time();
            $_SESSION['reset_change_otp_started'] = time();
            $msg = 'A new verification code was sent to your registered email.';
        } else {
            $msg = 'The new code could not be sent. Please try again.';
        }
    } elseif (!$otpVerified && ($_POST['action'] ?? '') === 'verify_otp') {
        if (verify_otp($conn, $id, 'reset', (string) ($_POST['otp'] ?? ''))) {
            $_SESSION['reset_change_otp_verified_at'] = time();
            $verifiedAt = time();
            $otpVerified = true;
            $msg = '';
        } else {
            $msg = 'The code is invalid, expired, or already used.';
        }
    } elseif ($otpVerified) {
        $p = (string) ($_POST['password'] ?? '');
        $c = (string) ($_POST['confirm'] ?? '');
        if (strlen($p) < $v['min_pass'] || strlen($p) > 64) {
            $msg = 'Password must be ' . $v['min_pass'] . ' to 64 characters.';
        } elseif ($p !== $c) {
            $msg = 'Passwords do not match.';
        } elseif (!preg_match('/[A-Z]/', $p) || !preg_match('/[a-z]/', $p) || !preg_match('/[0-9]/', $p)) {
            $msg = 'Password must include uppercase, lowercase, and a number.';
        } else {
            $historyStmt = $conn->prepare(
                'SELECT password_hash FROM password_history WHERE user_id=? ORDER BY created_at DESC LIMIT 3'
            );
            if (!$historyStmt) {
                $msg = 'Password change is temporarily unavailable. Please try again later.';
            } else {
                $historyStmt->bind_param('i', $id);
                $historyStmt->execute();
                $history = $historyStmt->get_result();
                $reuse = password_verify($p, $user['password']);
                while ($h = $history->fetch_assoc()) {
                    if (password_verify($p, $h['password_hash'])) {
                        $reuse = true;
                    }
                }
                $historyStmt->close();
                if ($reuse) {
                    $msg = 'You cannot reuse a recent password.';
                } else {
                    $hash = password_hash($p, PASSWORD_DEFAULT);
                    $update = $conn->prepare('UPDATE users SET password=? WHERE id=?');
                    $insert = $conn->prepare('INSERT INTO password_history(user_id,password_hash) VALUES(?,?)');
                    if (!$update || !$insert) {
                        if ($update) $update->close();
                        if ($insert) $insert->close();
                        $msg = 'Password change is temporarily unavailable. Please try again later.';
                    } else {
                        $conn->begin_transaction();
                        $update->bind_param('si', $hash, $id);
                        $ok = $update->execute();
                        $update->close();
                        if ($ok) {
                            $insert->bind_param('is', $id, $hash);
                            $ok = $insert->execute();
                            $insert->close();
                        } else {
                            $insert->close();
                        }
                        if (!$ok) {
                            $conn->rollback();
                            $msg = 'Your password could not be saved. Please try again.';
                        } else {
                            $conn->commit();
                            log_event($conn, 'PASSWORD_CHANGED', $user['username'], $id, 'email OTP verified');
                            unset(
                                $_SESSION['reset_stage'],
                                $_SESSION['reset_user_id'],
                                $_SESSION['reset_username'],
                                $_SESSION['reset_change_otp_started'],
                                $_SESSION['reset_change_otp_verified_at'],
                                $_SESSION['otp_resend_at']['reset'],
                                $_SESSION['otp_resend_at']['reset_change']
                            );
                            header('Location: login.php?reset=1');
                            exit();
                        }
                    }
                }
            }
        }
    } else {
        $msg = $msg ?: 'Verify the email code before changing your password.';
    }
}

require_once __DIR__ . '/public_layout.php';
gc_pub_head('GoCycle | Change password', 'login', true, 'is-auth');
?>
<main class="gc-stage is-single">
    <section class="gc-card" aria-labelledby="card-title">
        <div class="gc-badge"><?php echo gc_pub_icon('lock', 32); ?></div>
        <h1 id="card-title">New password</h1>
        <p class="gc-sub">Code verified. Choose a password you have not used recently.</p>
        <form method="post" class="gc-form" novalidate>
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <label class="sr-only" for="password">New password</label>
            <?php echo gc_pub_password_field('password', 'password', 'New password', 'new-password'); ?>
            <label class="sr-only" for="confirm">Re-enter password</label>
            <?php echo gc_pub_password_field('confirm', 'confirm', 'Re-enter password', 'new-password'); ?>
            <p class="form-message" role="alert"><?php echo e($msg); ?></p>
            <button class="gc-submit" type="submit"><span>Change password</span><?php echo gc_pub_icon('arrow', 20); ?></button>
        </form>
        <p class="gc-sub gc-otp-note">Use at least 8 characters with upper case, lower case and a number.</p>
        <div class="gc-or"><span>or</span></div>
        <p class="gc-switch"><a href="login.php">Back to login</a></p>
    </section>
</main>
<?php gc_pub_foot(); ?>
