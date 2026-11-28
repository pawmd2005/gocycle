<?php
require_once 'db.php';
require_once 'session.php';
require_once 'security.php';
require_once 'lockout.php';
require_once 'layout.php';
require_once 'booking_helpers.php';

$user = null;
if (!empty($_SESSION['user_id'])) {
    $stmt = $conn->prepare(
        'SELECT id,username,email,role,created_at FROM users WHERE id=? LIMIT 1',
    );
    if ($stmt) {
        $userId = (int) $_SESSION['user_id'];
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }
}

if (!$user || $user['role'] !== 'admin') {

    http_response_code(403);
    log_event(
        $conn,
        'ADMIN_ACCESS_DENIED',
        $user['username'] ?? null,
        isset($user['id']) ? (int) $user['id'] : null,
        'admin monitoring dashboard',
    );
    ?>
<!doctype html>
<html lang="en">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Access denied | GoCycle</title>
        <link rel="stylesheet" href="css/public.css">
    </head>

    <body class="gc-pub">
        <main class="gc-stage is-single">
            <section class="gc-card">
                <h1>Access denied</h1>
                <p class="gc-sub">The security monitoring dashboard is available to admin accounts
                    only.</p><a class="gc-submit" href="login.php">Return to log-in</a>
            </section>
        </main>
    </body>

</html><?php exit();
}

ensure_attempts_table($conn);
$pendingBookingCount = 0;
if (ensure_booking_schema($conn)) {
    $bookingCountStmt = $conn->prepare(
        "SELECT COUNT(*) AS total FROM ride_bookings WHERE status='Pending'",
    );
    if ($bookingCountStmt) {
        $bookingCountStmt->execute();
        $pendingBookingCount = (int) ($bookingCountStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $bookingCountStmt->close();
    }
}
log_event($conn, 'ADMIN_VIEWED_DASHBOARD', $user['username'], (int) $user['id'], '');
$eventTypes = [
    'REGISTERED',
    'ACCOUNT_VERIFIED',
    'LOGIN_SUCCESS',
    'LOGIN_FAILED',
    'OTP_SENT',
    'OTP_FAILED',
    'LOCKOUT_TIER_1',
    'LOCKOUT_TIER_2',
    'LOCKOUT_TIER_3',
    'IP_BLOCKED',
    'ALERT_EMAIL_SENT',
    'RESET_REQUESTED',
    'PASSWORD_CHANGED',
    'CAPTCHA_FAILED',
    'CSRF_REJECTED',
    'ADMIN_VIEWED_DASHBOARD',
    'ADMIN_ACCESS_DENIED',
    'BOOKING_REQUESTED',
    'BOOKING_CONFIRMED',
    'BOOKING_CANCELLED_BY_USER',
    'BOOKING_CANCELLED_BY_ADMIN',
];
?>
<!DOCTYPE html>
<html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Security Monitor | GoCycle</title>
        <link rel="stylesheet" href="css/style.css?v=20261110-8">
        <link rel="stylesheet" href="css/ui-fix.css?v=20261110-10">
        <link rel="stylesheet" href="css/admin-dashboard.css?v=20261110-2">
        <link rel="stylesheet" href="css/workspace.css?v=20261112-7">
        <link rel="stylesheet" href="css/dashboard-motion.css?v=20261114-1">
    </head>

    <body class="dashboard-body admin-monitor-page" data-endpoint="admin_dashboard_api.php">
        <div class="app-shell">
            <?php gc_sidebar($user, 'monitor'); ?>
            <main class="main-content">
                <?php gc_topbar($user, 'GOCYCLE / SECURITY', 'Security Monitor'); ?>
                <div class="monitor-intro">
                    <div>
                        <p>Read-only monitoring of authentication and security activity.</p>
                        <span>Summary counts are for today. Trends and top IPs cover the last 24
                            hours.</span>
                    </div>
                    <p class="monitor-refresh" id="monitor-refresh-status" role="status">Loading
                        security data…</p>
                </div>
                <section class="admin-booking-prompt" aria-label="Owner booking desk">
                    <div>
                        <p class="owner-kicker">OWNER BOOKING DESK</p>
                        <h2><?php echo $pendingBookingCount; ?> ride request<?php echo $pendingBookingCount ===
 1
     ? ''
     : 's'; ?> awaiting review</h2>
                        <p>Review rider details, confirm the ride, or cancel the request. Riders are
                            notified of your decision.</p>
                    </div><a class="primary-action" href="admin_bookings.php?status=Pending">Review
                        bookings →</a>
                </section>
                <section class="monitor-summary-grid" aria-label="Today's security summary">
                    <?php foreach (
                        [
                            ['Successful log-ins', 'successfulLogins'],
                            ['Failed log-ins', 'failedLogins'],
                            ['Accounts locked now', 'lockedAccounts'],
                            ['IP addresses blocked now', 'blockedIps'],
                            ['OTPs sent today', 'otpsSent'],
                            ['OTPs failed today', 'otpsFailed'],
                            ['Alert emails sent today', 'alertEmailsSent'],
                        ]
                        as [$label, $key]
                    ): ?>
                    <article class="monitor-metric">
                        <p><?php echo htmlspecialchars(
                            $label,
                            ENT_QUOTES,
                            'UTF-8',
                        ); ?></p><strong data-metric="<?php echo htmlspecialchars(
    $key,
    ENT_QUOTES,
    'UTF-8',
); ?>">—</strong><small>Today / current status</small>
                    </article>
                    <?php endforeach; ?>
                    <a class="monitor-metric is-link" href="admin_bookings.php?status=Pending">
                        <p>Pending ride requests</p>
                        <strong><?php echo (int) $pendingBookingCount; ?></strong><small>Awaiting
                            your review →</small>
                    </a>
                </section>
                <section class="monitor-columns">
                    <article class="content-card monitor-chart-card">
                        <div class="section-heading">
                            <div>
                                <h3>Failed log-ins per hour</h3>
                                <p>Hourly totals for the last 24 hours.</p>
                            </div><span class="status-pill">Last 24 hours</span>
                        </div>
                        <div class="monitor-chart-wrap"><canvas id="hourly-failures-chart"
                                width="1000" height="280" role="img"
                                aria-label="Bar chart of failed log-ins per hour for the last 24 hours"></canvas>
                        </div>
                        <p class="monitor-chart-summary" id="hourly-chart-summary">Chart data is
                            loading.</p>
                    </article>
                    <article class="content-card">
                        <div class="section-heading">
                            <div>
                                <h3>Top source IP addresses</h3>
                                <p>Five IPs with the most failed log-ins in 24 hours.</p>
                            </div>
                        </div>
                        <div class="booking-table-wrap">
                            <table class="admin-table monitor-table">
                                <thead>
                                    <tr>
                                        <th>IP address</th>
                                        <th>Failures</th>
                                        <th>Block status</th>
                                    </tr>
                                </thead>
                                <tbody id="top-ips-body">
                                    <tr>
                                        <td colspan="3">Loading…</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </article>
                    <article class="content-card monitor-flags-card">
                        <div class="section-heading">
                            <div>
                                <h3>Monitoring flags</h3>
                            </div><span class="status-pill">Read only</span>
                        </div>
                        <div class="booking-table-wrap monitor-flags-wrap">
                            <table class="admin-table monitor-table">
                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <th>Severity</th>
                                        <th>Finding</th>
                                        <th>Target</th>
                                        <th>Details</th>
                                    </tr>
                                </thead>
                                <tbody id="security-flags-body">
                                    <tr>
                                        <td colspan="5">Loading…</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </article>
                </section>
                <section class="content-card">
                    <div class="section-heading">
                        <div>
                            <h3>Recent security events</h3>
                            <p>Latest matching activity-log rows. Log entries cannot be edited or
                                deleted here.</p>
                        </div><span class="status-pill">Append-only view</span>
                    </div>
                    <form class="monitor-filters" id="event-filters">
                        <label>From date<input type="date" name="from"></label>
                        <label>To date<input type="date" name="to"></label>
                        <label>Event type<select name="event_type">
                                <option value="">All event types</option><?php foreach (
                                    $eventTypes
                                    as $eventType
                                ): ?><option value="<?php echo htmlspecialchars(
    $eventType,
    ENT_QUOTES,
    'UTF-8',
); ?>"><?php echo htmlspecialchars($eventType, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?>
                            </select></label>
                        <label>Username<input type="search" name="username" maxlength="100"
                                placeholder="Search username"></label>
                        <label>IP address<input type="search" name="ip" maxlength="45"
                                placeholder="Search IP"></label>
                        <div class="monitor-filter-actions"><button class="primary-action"
                                type="submit">Apply filters</button><button class="monitor-reset"
                                type="reset">Clear</button></div>
                    </form>
                    <div class="booking-table-wrap monitor-events-wrap">
                        <table class="admin-table monitor-table">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Event</th>
                                    <th>Username typed</th>
                                    <th>IP address</th>
                                    <th>Browser / device</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody id="recent-events-body">
                                <tr>
                                    <td colspan="6">Loading…</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="monitor-readonly-note">This screen is read-only. It provides no
                        controls to update or delete activity-log records.</p>
                </section>
                <footer class="page-footer">© 2026 GoCycle <span>Ride • Explore • Repeat</span>
                </footer>
            </main>
        </div>
        <script src="js/admin-dashboard.js?v=20261110-1" defer></script>
    </body>

</html>
