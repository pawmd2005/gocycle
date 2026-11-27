<?php
require_once 'db.php';
require_once 'session.php';
require_once 'lockout.php';
require_once 'security.php';
ensure_attempts_table($conn);
$stage = $_SESSION['reset_stage'] ?? 'identify';
$msg = '';
$captcha = $_SESSION['captcha_challenge']['display'] ?? captcha_new();
$user = null;
$questions = [];
if ($stage !== 'identify' && isset($_SESSION['reset_user_id'])) {
    $id = (int) $_SESSION['reset_user_id'];
    if ($id > 0) {
        $s = $conn->prepare(
            'SELECT id,username,email FROM users WHERE id=? AND verified_at IS NOT NULL',
        );
        $s->bind_param('i', $id);
        $s->execute();
        $user = $s->get_result()->fetch_assoc();
        $s->close();
        $s = $conn->prepare(
            'SELECT question_no FROM security_answers WHERE user_id=? ORDER BY question_no',
        );
        $s->bind_param('i', $id);
        $s->execute();
        $r = $s->get_result();
        while ($q = $r->fetch_assoc()) {
            $questions[] = (int) $q['question_no'];
        }
        $s->close();
    }
    if (!$user) {
        // Unknown username: show three different questions that stay the same for that name,
        // so the screen looks exactly like a real account's.
        $n = hexdec(substr(hash('sha256', mb_strtolower((string) ($_SESSION['reset_username'] ?? ''))), 0, 8));
        $pool = [1, 2, 3, 4, 5, 6, 7, 8];
        $questions = [];
        for ($i = 0; $i < 3; $i++) {
            $pick = $n % count($pool);
            $n = intdiv($n, count($pool)) + 7 * ($i + 1);
            $questions[] = $pool[$pick];
            array_splice($pool, $pick, 1);
        }
        sort($questions);
    }
}
$questionText = [
    1 => 'What was the name of your first pet?',
    2 => 'Who was your best friend in elementary school?',
    3 => 'Who was your favorite teacher in high school?',
    4 => 'What is your mother’s maiden first name?',
    5 => 'What street did you live on in Grade 1?',
    6 => 'What was the first song you learned by heart?',
    7 => 'What was the name of your elementary school?',
    8 => 'What dish did you cook for the first time?',
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $msg = 'Your form expired. Please try again.';
    } elseif ($stage === 'identify') {
        $username = trim((string) ($_POST['username'] ?? ''));
        if (!captcha_valid($_POST['captcha'] ?? null)) {
            $msg = 'Please complete the CAPTCHA correctly.';
            log_event($conn, 'CAPTCHA_FAILED', $username);
        } else {
            $s = $conn->prepare(
                'SELECT id,username,email FROM users WHERE username=? AND verified_at IS NOT NULL LIMIT 1',
            );
            $s->bind_param('s', $username);
            $s->execute();
            $found = $s->get_result()->fetch_assoc();
            $s->close();
            $_SESSION['reset_user_id'] = $found ? (int) $found['id'] : 0;
            $_SESSION['reset_stage'] = 'answer';
            $_SESSION['reset_username'] = $found ? $found['username'] : $username;
            log_event($conn, 'RESET_REQUESTED', $username, $found ? (int) $found['id'] : null, '');
            header('Location: forgot.php');
            exit();
        }
    } elseif ($stage === 'answer') {
        $key = attempt_key((string) ($_SESSION['reset_username'] ?? ''));
        if (ip_is_blocked($conn, client_ip())) {
            $msg = 'Too many attempts from this network. Please try again later.';
        } elseif (($left = lock_seconds_left($conn, $key)) > 0) {
            $msg = lock_message($left);
        } else {
            $answer = trim((string) ($_POST['answer'] ?? ''));
            $confirm = trim((string) ($_POST['answer_confirm'] ?? ''));
            if ($answer === '' || $answer !== $confirm) {
                $msg = 'Security answers do not match.';
            } elseif (!preg_match('/^(?=.{2,50}$)[A-Za-z]+(?: [A-Za-z]+)*$/', $answer)) {
                $msg = 'Security answer must contain letters and spaces only.';
            } else {
                $q = (int) ($_POST['question_no'] ?? 0);
                $s = $conn->prepare(
                    'SELECT answer_hash FROM security_answers WHERE user_id=? AND question_no=?',
                );
                $s->bind_param('ii', $_SESSION['reset_user_id'], $q);
                $s->execute();
                $row = $s->get_result()->fetch_assoc();
                $s->close();
                $ok =
                    $row &&
                    hash_equals($row['answer_hash'], hash('sha256', mb_strtolower($answer)));
                if (!$ok) {
                    $failure = record_failed_attempt($conn, $key, client_ip(), $user);
                    $msg =
                        ($failure['locked_until'] ?? 0) > time()
                            ? lock_message((int) $failure['locked_until'] - time())
                            : 'The answer is incorrect.';
                } else {
                    issue_otp(
                        $conn,
                        (int) $_SESSION['reset_user_id'],
                        'reset',
                        $user['email'],
                        'Password reset code',
                    );
                    $_SESSION['reset_stage'] = 'otp';
                    $stage = 'otp';
                }
            }
        }
    } elseif ($stage === 'otp' && ($_POST['action'] ?? '') === 'resend') {
        $msg = otp_resend($conn, (int) $_SESSION['reset_user_id'], 'reset', $user['email'] ?? null, 'Password reset code');
    } elseif ($stage === 'otp') {
        if (
            verify_otp(
                $conn,
                (int) $_SESSION['reset_user_id'],
                'reset',
                (string) ($_POST['otp'] ?? ''),
            )
        ) {
            $_SESSION['reset_stage'] = 'change';
            header('Location: change_password.php');
            exit();
        }
        $msg = 'The code is invalid, expired, or already used.';
    }
}
$captcha = $_SESSION['captcha_challenge']['display'] ?? captcha_new();
$demoOtp = null;
?>
<?php
require_once __DIR__ . '/public_layout.php';
gc_pub_head('GoCycle | Forgot password', 'login', true, 'is-auth');
?>
<main class="gc-stage is-single">
    <section class="gc-card" aria-labelledby="card-title">
        <div class="gc-badge"><?php echo gc_pub_icon('key', 32); ?></div>
        <h1 id="card-title"><?php echo $stage === 'identify'
            ? 'Forgot password?'
            : ($stage === 'answer'
                ? 'Verify your identity'
                : 'Enter the reset code'); ?></h1>
        <p class="gc-sub"><?php echo $stage === 'identify'
            ? 'Enter your username to begin recovery'
            : ($stage === 'answer'
                ? 'Choose one registered question and enter the answer'
                : 'A six-digit code was sent to your registered email'); ?></p>

        <form method="post" class="gc-form" novalidate>
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <?php if ($stage === 'identify'): ?>
            <label class="sr-only" for="username">Username</label>
            <div class="gc-input"><?php echo gc_pub_icon(
                'user',
                20,
                'lead',
            ); ?><input id="username" name="username" placeholder="Username" autocomplete="username"
                    required></div>
            <label for="captcha">Security check <b class="required-mark">*</b></label>
            <div class="captcha-box"><img class="captcha-image" src="captcha_image.php"
                    alt="CAPTCHA image"><button type="button" class="captcha-refresh"
                    data-captcha-refresh>New code</button></div>
            <div class="gc-input"><?php echo gc_pub_icon(
                'shield',
                20,
                'lead',
            ); ?><input id="captcha" name="captcha" inputmode="text" pattern="[A-Za-z0-9]{6}"
                    placeholder="Type the code above" autocomplete="off" required></div>
            <?php elseif ($stage === 'answer'): ?>
            <label for="question_no">Choose a question <b class="required-mark">*</b></label>
            <select id="question_no" name="question_no" required><?php foreach (
                $questions
                as $q
            ): ?><option value="<?php echo $q; ?>"><?php echo e(
    $questionText[$q] ?? 'Security question',
); ?></option><?php endforeach; ?></select>
            <label for="answer">Your answer <b class="required-mark">*</b></label>
            <input id="answer" name="answer" required>
            <label for="answer_confirm">Re-enter answer <b class="required-mark">*</b></label>
            <input id="answer_confirm" name="answer_confirm" required>
            <?php else: ?>
            <label class="sr-only" for="otp">6-digit reset code</label>
            <div class="gc-input"><?php echo gc_pub_icon(
                'key',
                20,
                'lead',
            ); ?><input id="otp" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                    placeholder="6-digit reset code" autocomplete="one-time-code" required></div>
            <?php endif; ?>

            <p class="form-message" role="alert"><?php echo e($msg); ?></p>
            <button class="gc-submit" type="submit"><span>Continue</span><?php echo gc_pub_icon(
                'arrow',
                20,
            ); ?></button>
        </form>

        <?php if ($stage === 'otp'): ?>
        <?php echo otp_resend_form($conn, (int) $_SESSION['reset_user_id'], 'reset'); ?>
        <?php endif; ?>

        <div class="gc-or"><span>or</span></div>
        <p class="gc-switch"><a href="login.php">Back to login</a></p>
    </section>
</main>
<?php gc_pub_foot(); ?>
