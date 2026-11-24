<?php
require "session.php";
require "db.php";
require "lockout.php";
$user = require_login($conn);
require "admin_dashboard_data.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $user['role'] === 'admin' ? 'Admin Dashboard' : 'Dashboard'; ?> | GoCycle</title>
<link rel="stylesheet" href="style.css">
</head>
<body class="dashboard-body">
<div class="app-shell">
<aside class="sidebar">
  <a class="brand brand-link" href="dashboard.php">GO<span>CYCLE</span></a>
  <p class="sidebar-label">WORKSPACE</p>
  <a class="nav-link active" href="dashboard.php"><span class="nav-icon" aria-hidden="true"></span> Dashboard</a>
  <?php if ($user['role'] === 'admin'): ?>
    <a class="nav-link" href="admin.php"><span class="nav-icon" aria-hidden="true"></span> Manage Accounts</a>
  <?php else: ?>
    <a class="nav-link" href="user_rides.php"><span class="nav-icon"></span> Book a Ride</a>
    <a class="nav-link" href="user_bookings.php"><span class="nav-icon" aria-hidden="true"></span> My Bookings</a>
    <a class="nav-link" href="user_account.php"><span class="nav-icon" aria-hidden="true"></span> My Account</a>
  <?php endif; ?>
  <div class="sidebar-bottom">
    <div class="sidebar-user"><div class="avatar"><?php echo strtoupper(substr($user['username'], 0, 1)); ?></div><div><strong><?php echo htmlspecialchars($user['username']); ?></strong><small><?php echo ucfirst(htmlspecialchars($user['role'])); ?></small></div></div>
    <a class="nav-link logout-link" href="logout.php"><span class="nav-icon" aria-hidden="true"></span> Log out</a>
  </div>
</aside>
<main class="main-content">
<header class="topbar"><div><p class="eyebrow">GOCYCLE / WORKSPACE</p><h1><?php echo $user['role'] === 'admin' ? 'Admin Dashboard' : 'Dashboard'; ?></h1></div><div class="topbar-profile"><div class="avatar"><?php echo strtoupper(substr($user['username'], 0, 1)); ?></div><div><strong><?php echo htmlspecialchars($user['username']); ?></strong><small><?php echo ucfirst(htmlspecialchars($user['role'])); ?> account</small></div></div></header>
<?php if ($user['role'] === 'admin'): ?>
  <?php require 'admin_dashboard_view.php'; ?>
<?php else: ?>
<section class="welcome-banner"><div><p class="banner-kicker">WELCOME TO GOCYCLE</p><h2>Hello, <?php echo htmlspecialchars($user['username']); ?>!</h2><p>Your next ride is only a few clicks away. Check the schedule, reserve your time, and manage your bookings here.</p><a class="journey-link" style="color:#fff" href="user_rides.php">Plan a ride <span aria-hidden="true">→</span></a></div><div class="banner-art" aria-hidden="true"></div></section>
<section class="dashboard-action-grid" aria-label="Quick actions">
  <a class="stat-card dashboard-action-card" href="user_rides.php"><span class="action-symbol action-symbol-text"></span><p>START RIDING</p><strong>Book a ride</strong><small>Choose a date and time, then check which slots still have seats available.</small><span class="primary-action">Choose a schedule <b>→</b></span></a>
  <a class="stat-card dashboard-action-card" href="user_bookings.php"><span class="action-symbol action-symbol-text"></span><p>YOUR RESERVATIONS</p><strong>My bookings</strong><small>Review your upcoming reservations or cancel a booking when your plans change.</small><span class="primary-action">View bookings <b>→</b></span></a>
  <a class="stat-card dashboard-action-card" href="user_account.php"><span class="action-symbol action-symbol-text"></span><p>ACCOUNT SETTINGS</p><strong>My account</strong><small>Update your username, change your password, and review your profile information.</small><span class="primary-action">Edit profile <b>→</b></span></a>
</section>
<section class="dashboard-lower-grid">
  <div class="journey-panel"><div class="journey-copy"><span class="section-pill">YOUR NEXT ADVENTURE</span><h2>Make time for a ride.</h2><p>Pick a schedule that works for you. Slots marked full stay visible so you can easily choose another time.</p><a class="journey-link" href="user_rides.php">Explore available schedules <span aria-hidden="true">→</span></a></div><div class="journey-illustration" aria-hidden="true"><div class="sun-disc"></div><div class="road-line"></div><span class="big-bike"></span><span class="leaf leaf-one"></span><span class="leaf leaf-two"></span></div></div>
  <div class="dashboard-guide"><div class="guide-heading"><div><p class="eyebrow">QUICK GUIDE</p><h3>How GoCycle works</h3></div><span class="guide-icon"></span></div>
    <div class="guide-step"><span class="step-number">01</span><div><strong>Choose a schedule</strong><p>Open Book a Ride and select your preferred date.</p></div></div>
    <div class="guide-step"><span class="step-number">02</span><div><strong>Confirm your booking</strong><p>Check the time slot and confirm before submitting.</p></div></div>
    <div class="guide-step"><span class="step-number">03</span><div><strong>Manage your plans</strong><p>Visit My Bookings to review or cancel a reservation.</p></div></div>
  </div>
</section>
<div class="dashboard-note"><span class="note-icon">i</span><div><strong>Booking tip</strong><p>Availability can change as other users book. If a slot is full, choose another available time. Cancelled bookings return their seat to availability.</p></div><a href="user_rides.php">Check times →</a></div>
<?php endif; ?>
<footer class="page-footer">© 2026 GoCycle <span>Ride • Explore • Repeat</span></footer>
</main></div></body></html>
