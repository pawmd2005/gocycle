<?php
require_once 'db.php';
require_once 'session.php';
require_once 'lockout.php';
require_once 'security.php';
if (empty($_SESSION['pending_register'])) {
    header('Location: register.php');
    exit();
}
$id = (int) $_SESSION['pending_register'];
$msg = '';
$u = $conn->prepare('SELECT email FROM users WHERE id=? LIMIT 1');
$u->bind_param('i', $id);
$u->execute();
$account = $u->get_result()->fetch_assoc();
$u->close();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $msg = 'Your form expired. Please refresh the page.';
    } else {
        $msg = otp_resend($conn, $id, 'register', $account['email'] ?? null, 'Verify your account');
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $msg = 'Your form expired. Please try again.';
    } elseif (verify_otp($conn, $id, 'register', (string) ($_POST['otp'] ?? ''))) {
        $s = $conn->prepare('UPDATE users SET verified_at=NOW() WHERE id=?');
        $s->bind_param('i', $id);
        $s->execute();
        $s->close();
        log_event($conn, 'ACCOUNT_VERIFIED', null, $id, '');
        unset($_SESSION['pending_register']);
        header('Location: login.php?verified=1');
        exit();
    } else {
        $msg = 'The code is invalid, expired, or already used.';
    }
}
require_once __DIR__ . '/public_layout.php';
gc_pub_head('GoCycle | Verify account', 'register', true, 'registration-complete is-auth');
?>
<main class="gc-stage is-single">
    <section class="gc-card" aria-labelledby="card-title">
        <div class="gc-badge"><?php echo gc_pub_icon('shield', 32); ?></div>
        <h1 id="card-title">Verify account</h1>
        <p class="gc-sub">Check your email for the six-digit code</p>

        <form method="post" class="gc-form" novalidate>
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <label class="sr-only" for="otp">One-time code</label>
            <div class="gc-input"><?php echo gc_pub_icon(
                'key',
                20,
                'lead',
            ); ?><input id="otp" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                    placeholder="6-digit code" autocomplete="one-time-code" required></div>
            <p class="form-message" role="alert"><?php echo e($msg); ?></p>
            <button class="gc-submit" type="submit"><span>Verify account</span><?php echo gc_pub_icon(
                'arrow',
                20,
            ); ?></button>
        </form>

        <?php echo otp_resend_form($conn, $id, 'register'); ?>
    </section>
</main>
<?php gc_pub_foot(); ?>
