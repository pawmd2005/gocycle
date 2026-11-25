<?php
require_once 'db.php';
require_once 'session.php';
require_once 'lockout.php';
require_once 'security.php';

$lockoutReady = ensure_attempts_table($conn);
$lockLeft = 0;
$msg = '';
$captcha = $_SESSION['captcha_challenge']['display'] ?? captcha_new();
$ip = client_ip();
$otpStep = !empty($_SESSION['pending_login']) && ($_GET['step'] ?? '') === 'otp';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$otpStep) {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        log_event($conn, 'CSRF_REJECTED', $_POST['username'] ?? null, null, 'login');
        $msg = 'Your form expired. Please try again.';
    } elseif (!captcha_valid($_POST['captcha'] ?? null)) {
        log_event($conn, 'CAPTCHA_FAILED', $_POST['username'] ?? null, null, 'login');
        $msg =
            'Please complete the CAPTCHA correctly. CAPTCHA errors do not count as password attempts.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $key = attempt_key($username);

        if (!$lockoutReady) {
            $msg =
                'Sign-in is temporarily unavailable because the security lockout table needs repair. Please contact the administrator.';
        } elseif (ip_is_blocked($conn, $ip)) {
            $msg = 'Too many failed attempts from this network. Sign-in is blocked for 10 minutes.';
        } elseif (($left = lock_seconds_left($conn, $key)) > 0) {
            $lockLeft = $left;
            $msg = lock_message($left);
        } else {
            $stmt = $conn->prepare(
                'SELECT id,username,email,password,role,verified_at FROM users WHERE username=? LIMIT 1',
            );
            if (!$stmt) {
                $msg = 'Sign-in is temporarily unavailable. Please try again later.';
            } else {
                $stmt->bind_param('s', $username);
                $stmt->execute();
                $account = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                {
                    // Same code path whether or not the username exists (no username enumeration).
                    $dummyHash = '$2y$10$Z0S2pFeTHnimv7c5GRs.Tu3vKIiNT9EQBmRbB0MYUwPHnMX9cJRCa';
                    $passwordOk = password_verify($password, $account ? $account['password'] : $dummyHash) && $account !== null;
                    if ($account && $passwordOk && $account['verified_at']) {
                        if (
                            issue_otp(
                                $conn,
                                (int) $account['id'],
                                'login',
                                $account['email'],
                                'Your log-in code',
                            )
                        ) {
                            $_SESSION['pending_login'] = (int) $account['id'];
                            header('Location: login.php?step=otp');
                            exit();
                        }
                        $msg = 'The verification code could not be prepared. Please try again later.';
                    } else {
                        $_SESSION['login_fail_streak'] = (int) ($_SESSION['login_fail_streak'] ?? 0) + 1;
                        $failure = record_failed_attempt($conn, $key, $ip, $account);
                        log_event($conn, 'LOGIN_FAILED', $username, $account ? (int) $account['id'] : null, $account ? '' : 'unknown username');
                        if (!empty($failure['storage_error'])) {
                            $msg = 'Sign-in is temporarily unavailable because the security lockout could not be recorded. Please contact the administrator.';
                        } else {
                            $msg = 'Invalid username or password.';
                            if (!empty($failure['ip_blocked'])) {
                                $msg = 'Too many failed attempts from this network. Sign-in is blocked for 10 minutes.';
                            } elseif (($failure['locked_until'] ?? 0) > time()) {
                                $lockLeft = (int) $failure['locked_until'] - time();
                                $msg = lock_message($lockLeft);
                            } else {
                                $count = (int) ($failure['failed_count'] ?? 0);
                                $nextThreshold = $count < 3 ? 3 : ($count < 6 ? 6 : ($count < 9 ? 9 : 0));
                                if ($nextThreshold > 0) {
                                    $remaining = $nextThreshold - $count;
                                    $durations = [3 => seed_values()['tier1'], 6 => seed_values()['tier2'], 9 => seed_values()['tier3']];
                                    $msg .= ' ' . $remaining . ' more incorrect password ' . ($remaining === 1 ? 'attempt' : 'attempts') . ' will trigger a ' . $durations[$nextThreshold] . '-second temporary lock.';
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

if (isset($_GET['step']) && $_GET['step'] === 'otp' && !empty($_SESSION['pending_login'])) {
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        ($_POST['action'] ?? '') === 'resend' &&
        csrf_valid($_POST['csrf'] ?? null)
    ) {
        $userId = (int) $_SESSION['pending_login'];
        $stmt = $conn->prepare('SELECT email FROM users WHERE id=? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $account = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $msg = otp_resend($conn, $userId, 'login', $account['email'] ?? null, 'Your log-in code');
    } elseif (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        csrf_valid($_POST['csrf'] ?? null) &&
        verify_otp($conn, (int) $_SESSION['pending_login'], 'login', (string) ($_POST['otp'] ?? ''))
    ) {
        $stmt = $conn->prepare('SELECT id,username,role FROM users WHERE id=?');
        $stmt->bind_param('i', $_SESSION['pending_login']);
        $stmt->execute();
        $account = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($account) {
            reset_attempts($conn, attempt_key($account['username']));
            session_regenerate_id(true);
            $_SESSION = [
                'user_id' => (int) $account['id'],
                'username' => $account['username'],
                'role' => $account['role'],
                'csrf' => bin2hex(random_bytes(32)),
                'last_activity' => time(),
            ];
            log_event($conn, 'LOGIN_SUCCESS', $account['username'], (int) $account['id'], '');
            unset($_SESSION['pending_login']);
            header('Location: dashboard.php');
            exit();
        }
        $msg = 'The account could not be loaded. Please try again.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $msg = $msg ?: 'The code is invalid or expired.';
    }
}

$captcha = $_SESSION['captcha_challenge']['display'] ?? captcha_new();
$showForgot = (int) ($_SESSION['login_fail_streak'] ?? 0) >= seed_values()['forgot_after'];
$demoOtp = null;
?>
<?php
require_once __DIR__ . '/public_layout.php';
if ($msg === '' && $_SERVER['REQUEST_METHOD'] !== 'POST' && ($_GET['expired'] ?? '') === '1') {
    $msg = 'Your session expired because you were inactive. Please log in again.';
}
gc_pub_head($otpStep ? 'GoCycle | Verify sign-in' : 'GoCycle | Login', 'login', true, 'is-auth');
?>
<main class="gc-stage">
    <section class="gc-welcome">
        <h1><?php echo $otpStep ? 'Almost there!' : 'Welcome Back!'; ?></h1>
        <p><?php echo $otpStep
            ? 'Enter your code to continue your ride.'
            : 'Log in to continue your ride.'; ?></p>
        <span class="streak" aria-hidden="true"></span>
        <ul class="gc-perks">
            <li><?php echo gc_pub_icon('calendar', 22); ?><span>Book a ride in a few taps</span></li>
            <li><?php echo gc_pub_icon(
                'shield',
                22,
            ); ?><span>Protected sign-in with an email code</span></li>
            <li><?php echo gc_pub_icon('leaf', 22); ?><span>A greener way to get around</span></li>
        </ul>
    </section>

    <section class="gc-card" aria-labelledby="card-title">
        <div class="gc-badge"><?php echo gc_pub_icon('bike', 34); ?></div>
        <h2 id="card-title"><?php echo $otpStep ? 'Verify sign-in' : 'Login'; ?></h2>
        <p class="gc-sub"><?php echo $otpStep
            ? 'Enter the 6-digit code sent to your registered email'
            : 'Access your GoCycle account'; ?></p>

        <form method="post" class="gc-form" novalidate>
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <?php if ($otpStep): ?>
            <label class="sr-only" for="otp">6-digit code</label>
            <div class="gc-input"><?php echo gc_pub_icon(
                'key',
                20,
                'lead',
            ); ?><input id="otp" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                    placeholder="6-digit code" autocomplete="one-time-code" required></div>
            <?php else: ?>
            <label class="sr-only" for="username">Username</label>
            <div class="gc-input"><?php echo gc_pub_icon(
                'user',
                20,
                'lead',
            ); ?><input id="username" name="username" maxlength="<?php echo (int) seed_values()['max_user']; ?>" placeholder="Username"
                    autocomplete="username" required></div>

            <label class="sr-only" for="password">Password</label>
            <?php echo gc_pub_password_field(
                'password',
                'password',
                'Password',
                'current-password',
            ); ?>

            <label class="sr-only" for="captcha">CAPTCHA code</label>
            <div class="captcha-box"><img class="captcha-image" src="captcha_image.php"
                    alt="CAPTCHA image"><button type="button" class="captcha-refresh"
                    data-captcha-refresh>New code</button></div>
            <div class="gc-input"><?php echo gc_pub_icon(
                'shield',
                20,
                'lead',
            ); ?><input id="captcha" name="captcha" inputmode="text" pattern="[A-Za-z0-9]{6}"
                    placeholder="Type the code above" autocomplete="off" required></div>

            <?php if ($showForgot): ?>
            <p class="gc-forgot">Forgot password? <a href="forgot.php">Reset here</a></p>
            <?php endif; ?>
            <p class="lockout-hint">Three incorrect passwords start a <?php echo (int) seed_values()[
                'tier1'
            ]; ?>-second account lock. Repeated failures raise the lock duration; CAPTCHA errors do not
                count. Too many failures from one network can block that IP for 10 minutes.</p>
            <?php endif; ?>

            <p class="form-message" role="alert"<?php echo $lockLeft > 0 ? ' data-lock-left="' . (int) $lockLeft . '"' : ''; ?>><?php echo e($msg); ?></p>

            <button class="gc-submit" type="submit"><span><?php echo $otpStep
                ? 'Verify code'
                : 'Login'; ?></span><?php echo gc_pub_icon('arrow', 20); ?></button>
        </form>

        <?php if ($otpStep): ?>
        <?php echo otp_resend_form($conn, (int) $_SESSION['pending_login'], 'login'); ?>
        <?php else: ?>
        <div class="gc-or"><span>or</span></div>
        <p class="gc-switch">Don’t have an account? <a href="register.php">Register here</a></p>
        <?php endif; ?>
    </section>
</main>
<?php gc_pub_foot(); ?>
