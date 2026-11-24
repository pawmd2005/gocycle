<?php

include "db.php";

$register_message = "";
$old_username = "";
$old_email = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim((string)($_POST["username"] ?? ""));
    $email    = trim((string)($_POST["email"] ?? ""));
    $password = (string)($_POST["password"] ?? "");
    $confirm  = (string)($_POST["confirm-password"] ?? "");

    $old_username = $username;
    $old_email    = $email;

    if (!preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $username)) {
        $register_message = "Username must be 3-30 characters: letters, numbers, . _ - only.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        $register_message = "Please enter a valid email address.";
    } elseif (strlen($password) < 8) {
        $register_message = "Password must be at least 8 characters.";
    } elseif (strlen($password) > 72) {
        $register_message = "Password must be 72 characters or fewer.";   // bcrypt limit
    } elseif ($password !== $confirm) {
        $register_message = "Passwords do not match.";
    } else {

        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare(
            "INSERT INTO users (username, email, password) VALUES (?, ?, ?)"
        );
        $stmt->bind_param("sss", $username, $email, $hashed_password);

        if ($stmt->execute()) {
            header("Location: login.php");
            exit;
        }

        if ($stmt->errno === 1062) {          // duplicate username or email
            $register_message = "That username or email is already registered.";
        } else {
            error_log("Register failed: " . $stmt->error);
            $register_message = "Registration failed. Please try again.";
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | GoCycle</title>
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
                    <h1>
                        Ride more.<br>
                        <span>Worry less.</span>
                    </h1>
                    <p class="description">
                        Find your perfect bike and enjoy a simple,
                        convenient way to explore the city.
                    </p>
                </div>
            </div>

            <div class="hero-bottom">
                <span>© 2026 GoCycle</span>
                <span>Ride • Explore • Repeat</span>
            </div>
        </section>

        <section class="form-section">
            <div class="login-card">
                <div class="welcome">
                    <p class="welcome-label"> CREATE ACCOUNT </p>
                    <h2> Join GoCycle.</h2>
                    <p> Create your account and start your next ride. </p>
                </div>

                <form id="registerForm" action="register.php" method="POST">
                    <div class="input-group">
                        <label for="username"> Username </label>

                        <div class="input-wrapper">
                            <input
                                type="text"
                                id="username"
                                name="username"
                                placeholder="Enter your username"
                                autocomplete="username"
                                value="<?php echo htmlspecialchars($old_username); ?>"
                                maxlength="30"
                                required
                            >
                        </div>
                        <small class="error-message" id="username-error"></small>
                    </div>

                    <div class="input-group">
                        <label for="register-email">
                            Email Address
                        </label>

                        <div class="input-wrapper">
                            <input type="email"
                                id="register-email"
                                name="email"
                                placeholder="you@example.com"
                                autocomplete="email"
                                value="<?php echo htmlspecialchars($old_email); ?>"
                                required
                            >
                        </div>

                        <small
                            class="error-message"
                            id="register-email-error">
                        </small>
                    </div>

                    <div class="input-group">
                        <label for="register-password">
                            Password
                        </label>
                        <div class="input-wrapper">

                            <input type="password"
                                id="register-password"
                                name="password"
                                placeholder="Create a password"
                                autocomplete="new-password"
                                minlength="8" maxlength="72"
                                required
                            >

                            <button type="button"
                                class="show-password"
                                id="show-register-password">
                                Show
                            </button>
                        </div>

                        <small
                            class="error-message"
                            id="register-password-error">
                        </small>
                    </div>

                    <div class="input-group">
                        <label for="confirm-password">  Confirm Password </label>

                        <div class="input-wrapper">
                            <input type="password"
                                id="confirm-password"
                                name="confirm-password"
                                placeholder="Confirm your password"
                                autocomplete="new-password"
                                required
                            >

                            <button type="button"
                                class="show-password"
                                id="show-confirm-password">
                                Show
                            </button>
                        </div>

                        <small
                            class="error-message"
                            id="confirm-password-error">
                        </small>
                    </div>

                    <p class="form-message" id="register-message"><?php echo htmlspecialchars($register_message); ?></p>

                    <button class="register-button"
                        type="submit">

                        <span> Create Account </span>
                    </button>
                </form>

                <div class="login-link">
                    <p> Already have an account? </p>
                    <a href="login.php"> Sign in</a>
                </div>
            </div>
        </section>
    </main>
    <script>
        function setupPasswordToggle(inputId, buttonId) {
            const input =
                document.getElementById(inputId);
            const button =
                document.getElementById(buttonId);

            button.addEventListener("click", function () {
                if (input.type === "password") {
                    input.type = "text";
                    button.textContent = "Hide";
                } else {
                    input.type = "password";
                    button.textContent = "Show";
                }
            });
        }

        setupPasswordToggle(
            "register-password",
            "show-register-password"
        );

        setupPasswordToggle(
            "confirm-password",
            "show-confirm-password"
        );

        const form = document.getElementById("registerForm");

        form.addEventListener("submit", function (event) {
            const password =
                document.getElementById("register-password").value;
            const confirmPassword =
                document.getElementById("confirm-password").value;
            const error =
                document.getElementById("confirm-password-error");
            if (password !== confirmPassword) {
                event.preventDefault();
                error.textContent = "Passwords do not match.";
            } else {
                error.textContent = "";
            }
        });
    </script>
</body>
</html>