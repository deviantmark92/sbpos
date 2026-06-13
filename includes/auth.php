<?php
/**
 * Authentication & role helpers.
 */

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    // cache for the request
    static $user = null;
    if ($user !== null) {
        return $user;
    }
    $stmt = db()->prepare('SELECT id, username, full_name, role, is_active FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
    if ($user && !$user['is_active']) {
        $user = null;
        session_destroy();
    }
    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_owner(): bool
{
    $u = current_user();
    return $u && $u['role'] === 'owner';
}

/** Attempt login. Returns true on success. */
function attempt_login(string $username, string $password): bool
{
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? AND is_active = TRUE');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        return true;
    }
    return false;
}

function logout(): void
{
    $_SESSION = [];
    session_destroy();
}

/** Require a logged-in user, else send to login page. */
function require_login(): void
{
    if (!is_logged_in()) {
        redirect('login');
    }
}

/** Require owner role, else show an access-denied message. */
function require_owner(): void
{
    require_login();
    if (!is_owner()) {
        http_response_code(403);
        $GLOBALS['__forbidden'] = true;
    }
}
