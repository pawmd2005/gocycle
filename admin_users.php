<?php
require_once 'db.php';
require_once 'session.php';
require_once 'layout.php';

$user = require_login($conn);
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Access denied.');
}

$users = [];
$stmt = $conn->prepare('SELECT id_number, first_name, middle_name, family_name, name_extension, username, email, role, created_at, verified_at FROM users ORDER BY created_at DESC, username ASC');
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $users[] = $row;
    $stmt->close();
}
function admin_user_name($row) {
    return trim(implode(' ', array_filter([$row['first_name'], $row['middle_name'], $row['family_name'], $row['name_extension']])));
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>User Directory | GoCycle</title>
    <link rel="stylesheet" href="css/style.css?v=20261111-3">
    <link rel="stylesheet" href="css/ui-fix.css?v=20261111-4">
    <link rel="stylesheet" href="css/workspace.css?v=20261112-7">
    <link rel="stylesheet" href="css/dashboard-motion.css?v=20261114-1">
</head>
<body class="dashboard-body admin-users-page">
<div class="app-shell">
    <?php gc_sidebar($user, 'users'); ?>
    <main class="main-content">
        <?php gc_topbar($user, 'GOCYCLE / ADMIN', 'User Directory'); ?>
        <section class="admin-users-summary" aria-label="User directory summary">
            <div class="admin-users-summary-icon"><?php echo gc_icon('users', 28); ?></div>
            <div class="admin-users-summary-copy"><p class="summary-kicker">USER DIRECTORY</p><h2>All registered GoCycle accounts</h2><p>Review account identity, role, verification and registration date.</p></div>
            <div class="admin-users-count"><strong><?php echo count($users); ?></strong><span>account<?php echo count($users) === 1 ? '' : 's'; ?></span></div>
        </section>
        <section class="content-card">
            <div class="section-heading"><div><h3>Registered users</h3><p>Passwords and security answers are never displayed.</p></div><span class="profile-badge">Admin only</span></div>
            <div class="booking-table-wrap">
                <table class="admin-table monitor-table">
                    <thead><tr><th>Name</th><th>ID number</th><th>Username</th><th>Email</th><th>Role</th><th>Verification</th><th>Registered</th></tr></thead>
                    <tbody>
                    <?php if (!$users): ?><tr><td colspan="7">No users found.</td></tr><?php endif; ?>
                    <?php foreach ($users as $row): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars(admin_user_name($row), ENT_QUOTES, 'UTF-8'); ?></strong></td>
                        <td><?php echo htmlspecialchars($row['id_number'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><span class="status-pill"><?php echo htmlspecialchars(ucfirst($row['role']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td><?php echo $row['verified_at'] ? '<span class="user-status-ok">Verified</span>' : '<span class="user-status-pending">Pending</span>'; ?></td>
                        <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($row['created_at'])), ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
</body>
</html>
