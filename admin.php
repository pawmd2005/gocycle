<?php

require "session.php";
include "db.php";
require "lockout.php";

ensure_attempts_table($conn);

$user = require_login($conn);

if ($user["role"] !== "admin") {
    http_response_code(403);
    die("Forbidden: admins only.");
}

$notice = "";

// Manual early unlock (the lock also expires by itself after 5 minutes).
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!csrf_valid($_POST["csrf"] ?? null)) {
        http_response_code(400);
        die("Invalid request token.");
    }

    if (($_POST["action"] ?? "") === "unlock") {
        $target = attempt_key((string)($_POST["username"] ?? ""));
        reset_attempts($conn, $target);
        $notice = "Unlocked: " . $target;
    }
}

// Users + their attempt rows (merged in PHP to avoid collation mismatches).
$attempts = [];
$res = $conn->query("SELECT username, failed_count, locked_until FROM login_attempts");
while ($row = $res->fetch_assoc()) {
    $attempts[mb_strtolower($row["username"])] = $row;
}

$users = $conn->query("SELECT id, username, email, role, created_at FROM users ORDER BY id");
$now = time();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin | GoCycle</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <main class="admin-wrap">

        <div class="welcome">
            <p class="welcome-label">ADMIN</p>
            <h2>Account Lockouts</h2>
            <p>Accounts lock for 5 minutes after 5 failed logins. You can unlock one early here.</p>
        </div>

        <?php if ($notice): ?>
            <p class="notice"><?php echo htmlspecialchars($notice); ?></p>
        <?php endif; ?>

        <table class="admin-table">
            <tr>
                <th>ID</th><th>Username</th><th>Email</th><th>Role</th>
                <th>Failed</th><th>Status</th><th></th>
            </tr>
            <?php while ($u = $users->fetch_assoc()):
                $a = $attempts[mb_strtolower($u["username"])] ?? null;
                $failed = $a ? (int)$a["failed_count"] : 0;
                $left = ($a && $a["locked_until"] !== null) ? (int)$a["locked_until"] - $now : 0;
            ?>
            <tr>
                <td><?php echo (int)$u["id"]; ?></td>
                <td><?php echo htmlspecialchars($u["username"]); ?></td>
                <td><?php echo htmlspecialchars((string)$u["email"]); ?></td>
                <td><?php echo htmlspecialchars($u["role"]); ?></td>
                <td><?php echo $failed; ?></td>
                <td>
                    <?php if ($left > 0): ?>
                        <span class="badge locked">Locked (<?php echo ceil($left / 60); ?> min left)</span>
                    <?php else: ?>
                        <span class="badge ok">Active</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($failed > 0 || $left > 0): ?>
                    <form method="POST" class="inline-form">
                        <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="action" value="unlock">
                        <input type="hidden" name="username" value="<?php echo htmlspecialchars($u["username"]); ?>">
                        <button type="submit" class="small-button">Unlock</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endwhile; ?>
        </table>

        <p class="admin-links">
            <a href="dashboard.php">&larr; Dashboard</a> &nbsp;|&nbsp; <a href="logout.php">Logout</a>
        </p>

    </main>

</body>
</html>
