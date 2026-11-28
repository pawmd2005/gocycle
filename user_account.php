<?php
require_once 'session.php';
require_once 'db.php';
require_once 'lockout.php';
require_once 'security.php';
require_once 'layout.php';
$user = require_login($conn);
$v = seed_values();
$msg = '';
$type = 'success';
$otpWindow = (int) $v['otp_minutes'] * 60;

$pending = $_SESSION['account_password_otp'] ?? null;
if (is_array($pending) && time() - (int) ($pending['issued_at'] ?? 0) > $otpWindow) {
    unset($_SESSION['account_password_otp']);
    $pending = null;
    $msg = 'The password-change code expired. Start the password change again.';
    $type = 'error';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $msg = 'Invalid request. Please try again.';
        $type = 'error';
    } else {
        $action = (string) ($_POST['action'] ?? 'save_profile');
        if ($action === 'verify_password_otp') {
            $pending = $_SESSION['account_password_otp'] ?? null;
            if (!is_array($pending) || (int) ($pending['user_id'] ?? 0) !== (int) $user['id']) {
                $msg = 'Your password-change request expired. Please start again.';
                $type = 'error';
                unset($_SESSION['account_password_otp']);
            } elseif (!verify_otp($conn, (int) $user['id'], 'profile', (string) ($_POST['otp'] ?? ''))) {
                $msg = 'The code is invalid, expired, or already used.';
                $type = 'error';
            } else {
                $stmt = $conn->prepare('SELECT password FROM users WHERE id=? LIMIT 1');
                if (!$stmt) {
                    $msg = 'Your password could not be verified. Please try again.';
                    $type = 'error';
                } else {
                    $stmt->bind_param('i', $user['id']);
                    $stmt->execute();
                    $stored = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if (!$stored || !hash_equals((string) $pending['current_hash'], (string) $stored['password'])) {
                        $msg = 'Your password changed in another session. Start the request again.';
                        $type = 'error';
                        unset($_SESSION['account_password_otp']);
                    } else {
                        $stmt = $conn->prepare('SELECT id FROM users WHERE username=? AND id<>? LIMIT 1');
                        if (!$stmt) {
                            $msg = 'Your account could not be updated. Please try again.';
                            $type = 'error';
                        } else {
                            $stmt->bind_param('si', $pending['username'], $user['id']);
                            $stmt->execute();
                            $exists = $stmt->get_result()->num_rows > 0;
                            $stmt->close();
                            if ($exists) {
                                $msg = 'That username is already taken. Start the request again.';
                                $type = 'error';
                                unset($_SESSION['account_password_otp']);
                            } else {
                                // The new password is kept only as a server-side hash while OTP verification is pending.
                                $conn->begin_transaction();
                                $update = $conn->prepare('UPDATE users SET username=?,password=? WHERE id=?');
                                $insert = $conn->prepare('INSERT INTO password_history(user_id,password_hash) VALUES(?,?)');
                                    $ok = false;
                                    if ($update && $insert) {
                                        $update->bind_param('ssi', $pending['username'], $pending['new_hash'], $user['id']);
                                        $ok = $update->execute();
                                        $update->close();
                                        if ($ok) {
                                            $insert->bind_param('is', $user['id'], $pending['new_hash']);
                                            $ok = $insert->execute();
                                        }
                                        $insert->close();
                                    } else {
                                        if ($update) $update->close();
                                        if ($insert) $insert->close();
                                    }
                                    if ($ok && $conn->commit()) {
                                        log_event($conn, 'PASSWORD_CHANGED', $pending['username'], (int) $user['id'], 'dashboard email OTP verified');
                                        unset($_SESSION['account_password_otp'], $_SESSION['account_password_otp_sent_at']);
                                        session_regenerate_id(true);
                                        $_SESSION['account_notice'] = 'Your username and password have been updated.';
                                        $_SESSION['account_notice_type'] = 'success';
                                        header('Location: user_account.php');
                                        exit();
                                    }
                                $conn->rollback();
                                $msg = 'Your password could not be saved. Please try again.';
                                $type = 'error';
                            }
                        }
                    }
                }
            }
        } elseif ($action === 'resend_password_otp') {
            $pending = $_SESSION['account_password_otp'] ?? null;
            if (!is_array($pending) || (int) ($pending['user_id'] ?? 0) !== (int) $user['id']) {
                $msg = 'Your password-change request expired. Start the change again.';
                $type = 'error';
                unset($_SESSION['account_password_otp']);
            } else {
                $msg = otp_resend($conn, (int) $user['id'], 'profile', $user['email'] ?? null, 'Verify your password change');
                $type = strpos($msg, 'A new code was sent') === 0 ? 'success' : 'error';
                if ($type === 'success') {
                    $_SESSION['account_password_otp_sent_at'] = time();
                    $_SESSION['account_password_otp']['issued_at'] = time();
                }
            }
        } elseif ($action === 'cancel_password_otp') {
            $stmt = $conn->prepare("UPDATE otp_codes SET used_at=NOW() WHERE user_id=? AND purpose='profile' AND used_at IS NULL");
            if ($stmt) {
                $stmt->bind_param('i', $user['id']);
                $stmt->execute();
                $stmt->close();
            }
            unset($_SESSION['account_password_otp'], $_SESSION['account_password_otp_sent_at']);
            $msg = 'Password change cancelled. No account changes were made.';
            $type = 'success';
        } else {
            $username = trim((string) ($_POST['username'] ?? ''));
            $current = (string) ($_POST['current_password'] ?? '');
            $newpass = (string) ($_POST['new_password'] ?? '');
            $confirm = (string) ($_POST['confirm_password'] ?? '');

            if (!preg_match('/^[A-Za-z][A-Za-z0-9]{' . ($v['min_user'] - 1) . ',' . ($v['max_user'] - 1) . '}$/', $username)) {
                $msg = 'Username must be ' . $v['min_user'] . '–' . $v['max_user'] . ' characters, start with a letter, and use letters and numbers only.';
                $type = 'error';
            } elseif ($newpass !== '' && (strlen($newpass) < $v['min_pass'] || strlen($newpass) > 64 || !preg_match('/[A-Z]/', $newpass) || !preg_match('/[a-z]/', $newpass) || !preg_match('/[0-9]/', $newpass))) {
                $msg = 'Password must be ' . $v['min_pass'] . '–64 characters and include uppercase, lowercase, and a number.';
                $type = 'error';
            } elseif ($newpass !== '' && $newpass !== $confirm) {
                $msg = 'New passwords do not match.';
                $type = 'error';
            } else {
                $stmt = $conn->prepare('SELECT password FROM users WHERE id=? LIMIT 1');
                if (!$stmt) {
                    $msg = 'Your account could not be verified. Please try again.';
                    $type = 'error';
                } else {
                    $stmt->bind_param('i', $user['id']);
                    $stmt->execute();
                    $stored = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if (!$stored || !password_verify($current, $stored['password'])) {
                        $msg = 'Your current password is incorrect.';
                        $type = 'error';
                    } else {
                        $stmt = $conn->prepare('SELECT id FROM users WHERE username=? AND id<>? LIMIT 1');
                        if (!$stmt) {
                            $msg = 'Your account could not be updated. Please try again.';
                            $type = 'error';
                        } else {
                            $stmt->bind_param('si', $username, $user['id']);
                            $stmt->execute();
                            $exists = $stmt->get_result()->num_rows > 0;
                            $stmt->close();
                            if ($exists) {
                                $msg = 'That username is already taken.';
                                $type = 'error';
                            } elseif ($newpass !== '') {
                                $reuse = password_verify($newpass, $stored['password']);
                                $history = $conn->prepare('SELECT password_hash FROM password_history WHERE user_id=? ORDER BY created_at DESC LIMIT 3');
                                if (!$history) {
                                    $msg = 'Password change is temporarily unavailable. Please try again later.';
                                    $type = 'error';
                                } else {
                                    $history->bind_param('i', $user['id']);
                                    $history->execute();
                                    $rows = $history->get_result();
                                    while ($row = $rows->fetch_assoc()) {
                                        if (password_verify($newpass, $row['password_hash'])) $reuse = true;
                                    }
                                    $history->close();
                                }
                                if (!$history) {
                                    // Fail closed when password-history storage cannot be checked.
                                } elseif ($reuse) {
                                    $msg = 'You cannot reuse your current or a recent password.';
                                    $type = 'error';
                                } elseif (empty($user['email'])) {
                                    $msg = 'A registered email address is required for password-change verification.';
                                    $type = 'error';
                                } elseif (issue_otp($conn, (int) $user['id'], 'profile', $user['email'], 'Verify your password change')) {
                                    $_SESSION['account_password_otp'] = [
                                        'user_id' => (int) $user['id'],
                                        'username' => $username,
                                        'current_hash' => (string) $stored['password'],
                                        'new_hash' => password_hash($newpass, PASSWORD_DEFAULT),
                                        'issued_at' => time(),
                                    ];
                                    $_SESSION['account_password_otp_sent_at'] = time();
                                    log_event($conn, 'PASSWORD_CHANGE_OTP_REQUESTED', $username, (int) $user['id'], 'dashboard');
                                    $msg = 'A verification code was sent to your registered email. Enter it below to finish changing your password.';
                                    $type = 'success';
                                } else {
                                    $msg = 'A verification code could not be sent. Please try again.';
                                    $type = 'error';
                                }
                            } else {
                                $stmt = $conn->prepare('UPDATE users SET username=? WHERE id=?');
                                if (!$stmt) {
                                    $msg = 'Your account could not be updated. Please try again.';
                                    $type = 'error';
                                } else {
                                    $stmt->bind_param('si', $username, $user['id']);
                                    if ($stmt->execute()) {
                                        $_SESSION['account_notice'] = 'Your username has been updated.';
                                        $_SESSION['account_notice_type'] = 'success';
                                        $stmt->close();
                                        header('Location: user_account.php');
                                        exit();
                                    }
                                    $stmt->close();
                                    $msg = 'Could not update your username. Please try again.';
                                    $type = 'error';
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

if (isset($_SESSION['account_notice'])) {
    $msg = (string) $_SESSION['account_notice'];
    $type = (string) ($_SESSION['account_notice_type'] ?? 'success');
    unset($_SESSION['account_notice'], $_SESSION['account_notice_type']);
}
$pending = $_SESSION['account_password_otp'] ?? null;
$user = require_login($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Account | GoCycle</title>
    <link rel="stylesheet" href="css/style.css?v=20261106-4">
    <link rel="stylesheet" href="css/ui-fix.css?v=20261110-10">
    <link rel="stylesheet" href="css/workspace.css?v=20261112-7">
    <link rel="stylesheet" href="css/dashboard-motion.css?v=20261114-1">
</head>
<body class="dashboard-body">
    <div class="app-shell">
        <?php gc_sidebar($user, 'account'); ?>
        <main class="main-content">
            <?php gc_topbar($user, 'GOCYCLE / PROFILE', 'My Account'); ?>
            <?php if ($msg): ?><div class="notice <?php echo $type === 'error' ? 'notice-error' : 'notice-success'; ?>" data-toast><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <section class="content-card account-card">
                <div class="section-heading"><div><h3>Profile details</h3><p>Update your username or change your password.</p></div><span class="profile-badge">Account</span></div>
                <div class="profile-details">
                    <div><span>Email address</span><strong><?php echo htmlspecialchars($user['email'] ?: 'Not provided', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    <div><span>Account role</span><strong><?php echo htmlspecialchars(ucfirst($user['role']), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                </div>
                <?php if (is_array($pending)): ?>
                <div class="account-otp-panel">
                    <h3>Verify password change</h3>
                    <p>Enter the six-digit code sent to your registered email. The code expires in <?php echo (int) $v['otp_minutes']; ?> minutes.</p>
                    <form method="post" class="account-form account-otp-form">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="verify_password_otp">
                        <label for="account-otp">Email verification code<input id="account-otp" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></label>
                        <button class="primary-action form-submit account-submit-button" type="submit">Verify code</button>
                    </form>
                    <div class="account-otp-actions">
                        <?php echo otp_resend_form($conn, (int) $user['id'], 'profile', 'resend_password_otp', 'account-secondary-button'); ?>
                        <form method="post"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="cancel_password_otp"><button class="account-secondary-button" type="submit">Cancel</button></form>
                    </div>
                </div>
                <?php else: ?>
                <form method="post" class="account-form">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="save_profile">
                    <label>Username<input type="text" name="username" value="<?php echo htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8'); ?>" minlength="3" maxlength="40" required></label>
                    <label>Current password <small>(required to save changes)</small><span class="workspace-password-wrap"><input id="account-current-password" type="password" name="current_password" autocomplete="current-password" required><button type="button" class="workspace-password-toggle" data-workspace-password-toggle="account-current-password" aria-label="Show password" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"></path><circle cx="12" cy="12" r="3"></circle></svg></button></span></label>
                    <div class="form-divider"></div>
                    <h3>Change password <small>— optional</small></h3>
                    <p class="form-help">Leave both new-password fields blank if you only want to change your username. Password changes require an email code.</p>
                    <div class="form-two-col">
                        <label>New password<span class="workspace-password-wrap"><input id="account-new-password" type="password" name="new_password" maxlength="64" data-min-password="<?php echo (int) $v['min_pass']; ?>" autocomplete="new-password" placeholder="At least <?php echo (int) $v['min_pass']; ?> characters"><button type="button" class="workspace-password-toggle" data-workspace-password-toggle="account-new-password" aria-label="Show password" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18.5 18.5 12 18.5 2.5 12 2.5 12z"></path><circle cx="12" cy="12" r="3"></circle></svg></button></span><small class="account-strength" id="account-password-strength">Password strength: <b>Not checked</b></small></label>
                        <label>Confirm new password<span class="workspace-password-wrap"><input id="account-confirm-password" type="password" name="confirm_password" maxlength="64" autocomplete="new-password" placeholder="Re-enter new password"><button type="button" class="workspace-password-toggle" data-workspace-password-toggle="account-confirm-password" aria-label="Show password" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18.5 18.5 12 18.5 2.5 12 2.5 12z"></path><circle cx="12" cy="12" r="3"></circle></svg></button></span></label>
                    </div>
                    <p class="form-help">Use <?php echo (int) $v['min_pass']; ?>–64 characters with at least one uppercase letter, one lowercase letter, and one number.</p>
                    <button class="primary-action form-submit account-submit-button" type="submit">Save changes →</button>
                </form>
                <?php endif; ?>
            </section>
            <footer class="page-footer">© 2026 GoCycle <span>Ride • Explore • Repeat</span></footer>
        </main>
    </div>
</body>
</html>
