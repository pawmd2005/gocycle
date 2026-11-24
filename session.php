<?php
// Include this instead of calling session_start() directly.

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        "lifetime" => 0,
        "path"     => "/",
        "httponly" => true,     // JS cannot read the session cookie
        "samesite" => "Lax",    // basic CSRF protection for the cookie
        "secure"   => !empty($_SERVER["HTTPS"]),
    ]);
    session_start();
}

function csrf_token(): string {
    if (empty($_SESSION["csrf"])) {
        $_SESSION["csrf"] = bin2hex(random_bytes(32));
    }
    return $_SESSION["csrf"];
}

function csrf_valid(?string $token): bool {
    return !empty($_SESSION["csrf"]) && is_string($token)
        && hash_equals($_SESSION["csrf"], $token);
}

// Returns the logged-in user row (fresh from the DB) or redirects to login.
function require_login(mysqli $conn): array {
    if (empty($_SESSION["user_id"])) {
        header("Location: login.php");
        exit;
    }

    $stmt = $conn->prepare(
        "SELECT id, username, email, role, created_at FROM users WHERE id = ?"
    );
    $stmt->bind_param("i", $_SESSION["user_id"]);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user) {               // account was deleted
        session_unset();
        session_destroy();
        header("Location: login.php");
        exit;
    }
    return $user;
}
