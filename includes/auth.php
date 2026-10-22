<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/permissions.php';

/**
 * Attempt login. Returns user array on success, false on failure.
 * On failure due to a disabled account, sets $GLOBALS['login_error'] = 'disabled'
 * so the caller can show a distinct "contact admin" message.
 */
function attemptLogin(string $username, string $password) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) {
        logActivity('user', 0, 'login_failed', null, null, "unknown username: $username", null);
        return false;
    }
    if ($user['status'] !== 'active') {
        logActivity('user', $user['id'], 'login_blocked', null, null, 'account disabled', $user['id']);
        $GLOBALS['login_error'] = 'disabled';
        return false;
    }
    if (!password_verify($password, $user['password_hash'])) {
        logActivity('user', $user['id'], 'login_failed', null, null, 'bad password', $user['id']);
        return false;
    }

    // Regenerate session ID on privilege change (prevents session fixation)
    session_regenerate_id(true);

    $_SESSION['user_id']   = $user['id'];
    $_SESSION['username']  = $user['username'];
    $_SESSION['name']      = $user['name'];
    $_SESSION['role']      = $user['role'];
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
    logActivity('user', $user['id'], 'login', null, null, null, $user['id']);

    return $user;
}

function logoutUser(): void {
    if (isset($_SESSION['user_id'])) {
        logActivity('user', $_SESSION['user_id'], 'logout', null, null, null, $_SESSION['user_id']);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/** Require any logged-in user; redirect to login if not authenticated, disabled, or session expired. */
function requireLogin(): void {
    if (!isset($_SESSION['user_id'])) {
        redirectTo('/auth/login.php');
    }
    // Idle timeout check
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
        logoutUser();
        redirectTo('/auth/login.php?timeout=1');
    }
    // Live status check — if an admin disables this account while they're still
    // logged in, kick them out on their very next request instead of waiting
    // for the session to expire naturally.
    $db = getDB();
    $stmt = $db->prepare("SELECT status FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();
    if (!$row || $row['status'] !== 'active') {
        logActivity('user', $_SESSION['user_id'], 'login_blocked', null, null, 'account disabled mid-session', $_SESSION['user_id']);
        logoutUser();
        redirectTo('/auth/login.php?disabled=1');
    }
    $_SESSION['last_activity'] = time();
}

/** Require admin-tier role (super_admin or admin). Kept for backward compatibility
 *  with existing admin-only pages/actions built before the expanded role set. */
function requireAdmin(): void {
    requireLogin();
    if (!in_array($_SESSION['role'], ['super_admin', 'admin'], true)) {
        http_response_code(403);
        die('403 Forbidden: Admins only.');
    }
}

function currentUserId(): ?int {
    return $_SESSION['user_id'] ?? null;
}

function currentUserRole(): ?string {
    return $_SESSION['role'] ?? null;
}

/** True for super_admin or admin — the two "full access" tiers. */
function isAdmin(): bool {
    return in_array(currentUserRole(), ['super_admin', 'admin'], true);
}

function isSuperAdmin(): bool {
    return currentUserRole() === 'super_admin';
}

/** True for super_admin, admin, or manager — roles that can see org-wide (not just own) data. */
function isManagerOrAbove(): bool {
    return in_array(currentUserRole(), ['super_admin', 'admin', 'manager'], true);
}

/** Roles that use the "back office" admin/ section (broader visibility, reports, revenue).
 *  Field roles (sales_executive, telecaller, employee) use the employee/ section instead. */
function usesAdminLayout(): bool {
    return isManagerOrAbove() || currentUserRole() === 'accountant';
}

function redirectTo(string $path): void {
    header('Location: ' . BASE_URL . $path);
    exit;
}

/** CSRF token helpers */
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(?string $token): bool {
    return !empty($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
