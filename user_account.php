<?php
require 'session.php'; require 'db.php'; require 'lockout.php';
$user = require_login($conn);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) { $_SESSION['account_notice']='Invalid request. Please try again.'; $_SESSION['account_notice_type']='error'; header('Location: user_account.php'); exit; }
    $username = trim($_POST['username'] ?? '');
    $current = $_POST['current_password'] ?? '';
    $newpass = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    if (strlen($username) < 3 || strlen($username) > 40 || !preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) { $msg='Username must be 3–40 characters and use letters, numbers, dots, underscores, or hyphens.'; $type='error'; }
    else {
        $stmt=$conn->prepare('SELECT password FROM users WHERE id=?'); $stmt->bind_param('i',$user['id']); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
        $valid = $row && (password_verify($current,$row['password']) || hash_equals((string)$row['password'], $current));
        if (!$valid) { $msg='Your current password is incorrect.'; $type='error'; }
        elseif ($newpass !== '' && (strlen($newpass)<8 || $newpass !== $confirm)) { $msg='New password must be at least 8 characters and both password fields must match.'; $type='error'; }
        else {
            $stmt=$conn->prepare('SELECT id FROM users WHERE username=? AND id<>? LIMIT 1'); $stmt->bind_param('si',$username,$user['id']); $stmt->execute(); $exists=$stmt->get_result()->num_rows>0; $stmt->close();
            if ($exists) { $msg='That username is already taken.'; $type='error'; }
            else {
                if ($newpass !== '') { $hash=password_hash($newpass,PASSWORD_DEFAULT); $stmt=$conn->prepare('UPDATE users SET username=?, password=? WHERE id=?'); $stmt->bind_param('ssi',$username,$hash,$user['id']); }
                else { $stmt=$conn->prepare('UPDATE users SET username=? WHERE id=?'); $stmt->bind_param('si',$username,$user['id']); }
                $ok=$stmt->execute(); $stmt->close();
                if ($ok) { $_SESSION['account_notice']='Your account details have been updated.'; $_SESSION['account_notice_type']='success'; }
                else { $_SESSION['account_notice']='Could not update your account. Please try again.'; $_SESSION['account_notice_type']='error'; }
                header('Location: user_account.php'); exit;
            }
        }
    }
    $_SESSION['account_notice']=$msg; $_SESSION['account_notice_type']=$type; header('Location: user_account.php'); exit;
}
$notice=$_SESSION['account_notice']??''; $notice_type=$_SESSION['account_notice_type']??'success'; unset($_SESSION['account_notice'],$_SESSION['account_notice_type']);
$user = require_login($conn);
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>My Account | GoCycle</title><link rel="stylesheet" href="style.css"></head><body class="dashboard-body"><div class="app-shell"><aside class="sidebar"><a class="brand brand-link" href="dashboard.php">GO<span>CYCLE</span></a><p class="sidebar-label">MENU</p><a class="nav-link" href="dashboard.php">Dashboard</a><?php if($user['role']==='admin'): ?><a class="nav-link" href="admin.php">Manage Accounts</a><?php else: ?><a class="nav-link" href="user_rides.php">Book a Ride</a><a class="nav-link" href="user_bookings.php">My Bookings</a><a class="nav-link active" href="user_account.php">My Account</a><?php endif; ?><div class="sidebar-bottom"><div class="sidebar-user"><div class="avatar"><?php echo strtoupper(substr($user['username'],0,1)); ?></div><div><strong><?php echo htmlspecialchars($user['username']); ?></strong><small><?php echo ucfirst(htmlspecialchars($user['role'])); ?></small></div></div><a class="nav-link logout-link" href="logout.php">→&nbsp;&nbsp; Log out</a></div></aside><main class="main-content"><header class="topbar"><div><p class="eyebrow">GOCYCLE / PROFILE</p><h1>My Account</h1></div></header>
<?php if($notice): ?><div class="notice <?php echo $notice_type==='error'?'notice-error':'notice-success'; ?>"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
<section class="content-card account-card"><div class="section-heading"><div><h3>Profile details</h3><p>Update your username or change your password.</p></div><span class="profile-badge">Account</span></div><div class="profile-details"><div><span>Email address</span><strong><?php echo htmlspecialchars($user['email']?:'Not provided'); ?></strong></div><div><span>Account role</span><strong><?php echo ucfirst(htmlspecialchars($user['role'])); ?></strong></div></div>
<form method="post" class="account-form"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>"><label>Username<input type="text" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" minlength="3" maxlength="40" required></label><label>Current password <small>(required to save changes)</small><input type="password" name="current_password" autocomplete="current-password" required></label><div class="form-divider"></div><h3>Change password <small>— optional</small></h3><p class="form-help">Leave these two fields blank if you only want to change your username.</p><div class="form-two-col"><label>New password<input type="password" name="new_password" minlength="8" autocomplete="new-password" placeholder="At least 8 characters"></label><label>Confirm new password<input type="password" name="confirm_password" minlength="8" autocomplete="new-password" placeholder="Re-enter new password"></label></div><button class="primary-action form-submit" type="submit">Save changes →</button></form></section><footer class="page-footer">© 2026 GoCycle <span>Ride • Explore • Repeat</span></footer></main></div></body></html>
