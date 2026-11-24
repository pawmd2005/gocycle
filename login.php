<?php

require "session.php";
ob_start();   // lets us echo a message and still send a redirect header

include "db.php";
require "lockout.php";

ensure_attempts_table($conn);

$lockout_message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim((string)($_POST["username"] ?? ""));
    $password = (string)($_POST["password"] ?? "");
    $key      = attempt_key($username);

    $seconds_left = lock_seconds_left($conn, $key);

    if ($seconds_left > 0) {

        // Locked: do NOT check the password at all (even a correct one).
        $lockout_message = lock_message($seconds_left);
        header("Retry-After: " . $seconds_left);
        http_response_code(429);

    } else {

        $stmt = $conn->prepare(
            "SELECT id, username, password, role FROM users WHERE username = ?"
        );
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if ($user) {
            $login_ok = password_verify($password, $user["password"]);
        } else {
            // Unknown user: still do a hash so response time looks the same.
            password_hash($password, PASSWORD_DEFAULT);
            $login_ok = false;
        }

        if ($login_ok) {

            reset_attempts($conn, $key);

            session_regenerate_id(true);   // prevents session fixation
            $_SESSION["user_id"]  = (int)$user["id"];
            $_SESSION["username"] = $user["username"];
            $_SESSION["role"]     = $user["role"];

            echo "Login successful!";

            header("Location: dashboard.php");
            exit;

        } else {

            $updated = record_failed_attempt($conn, $key);

            $seconds_left = $updated["locked_until"] !== null
                ? $updated["locked_until"] - time()
                : 0;

            if ($seconds_left > 0) {

                $lockout_message = lock_message($seconds_left);
                header("Retry-After: " . $seconds_left);
                http_response_code(429);

            } else {

                $remaining = MAX_ATTEMPTS - $updated["failed_count"];

                // Same wording whether or not the username exists.
                $lockout_message = "Invalid username or password. "
                    . $remaining . " attempt(s) remaining before the account is locked.";
            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GoCycle</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <main class="login-page">
        <section class="hero-section">

            <div class="hero-content">
                <div class="brand">
                    GO<span>CYCLE</span>
                </div>

                <div class="hero-text">
                    <p class="small-title"> YOUR RIDE. YOUR WAY. </p>
                    <h1> Ride more.<br><span>Worry less.</span></h1>

                    <p class="description">Find your perfect bike and enjoy a simple, convenient way to explore the city.</p>
                </div>
            </div>

            <div class="hero-bottom">
                <span> © 2026 GoCycle</span>
                <span> Ride • Explore • Repeat </span>
            </div>
        </section>

        <section class="form-section">
            <div class="login-card">
                <div class="welcome">
                    <p class="welcome-label"> WELCOME BACK </p>
                    <h2> Let's get you a bike.</h2>
                    <p> Sign in to manage your rentals and start your next ride.</p>
                </div>

                <form id="loginForm" action="login.php" method="POST">
                    <div class="input-group">
                        <label for="username"> Username </label>

                        <div class="input-wrapper">
                            <input
                                type="text"
                                id="username"
                                name="username"
                                placeholder="Enter your username"
                                autocomplete="username"
                                required
                            >
                        </div>

                        <small class="error-message" id="username-error"></small>
                    </div>

                    <div class="input-group">
                        <div class="label-row">
                            <label for="password"> Password </label>
                            <a href="#" id="forgot-password"> Forgot password? </a>
                        </div>

                        <div class="input-wrapper">
                            <input type="password"
                                id="password"
                                name="password"
                                placeholder="Enter your password"
                                autocomplete="current-password"
                                required
                            >

                            <button type="button"
                                class="show-password"
                                id="show-password">
                                Show
                            </button>
                        </div>
                        <small class="error-message" id="password-error"></small>
                    </div>
                    <?php if (!empty($lockout_message)): ?>
                        <p class="form-message" id="login-message"><?php echo htmlspecialchars($lockout_message); ?></p>
                    <?php else: ?>
                        <p class="form-message" id="login-message"></p>
                    <?php endif; ?>

                    <button class="login-button"
                        type="submit">
                        <span> Sign In </span>
                    </button>

                </form>

                <div class="divider">
                    <span>OR</span>
                </div>


                <div class="create-account">
                    <p> New to GoCycle?</p>
                    <a href="register.php"> Create an account </a>
                </div>
            </div>
        </section>
    </main>

    <script>
        const passwordInput =
            document.getElementById("password");

        const showPassword =
            document.getElementById("show-password");

        showPassword.addEventListener("click", function () {

            if (passwordInput.type === "password") {
                passwordInput.type = "text";
                showPassword.textContent = "Hide";
            } else {
                passwordInput.type = "password";
                showPassword.textContent = "Show";
            }
        });
    </script>
</body>
</html>