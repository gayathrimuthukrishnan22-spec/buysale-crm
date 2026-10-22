<?php
require_once __DIR__ . '/../config/database.php';

/**
 * Returns the full set of permission keys granted to a user:
 * role defaults (from role_permissions) plus their personal
 * overrides (users.permissions JSON: {"grant":[...], "revoke":[...]}).
 * Cached per-request since this can be called many times per page.
 */
function getUserPermissions(int $userId): array {
    static $cache = [];
    if (isset($cache[$userId])) return $cache[$userId];

    $db = getDB();
    $stmt = $db->prepare("SELECT role, permissions FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) return $cache[$userId] = [];

    $roleStmt = $db->prepare("SELECT permission_key FROM role_permissions WHERE role = ?");
    $roleStmt->execute([$user['role']]);
    $granted = array_column($roleStmt->fetchAll(), 'permission_key');

    $overrides = $user['permissions'] ? json_decode($user['permissions'], true) : null;
    if (is_array($overrides)) {
        $grant = $overrides['grant'] ?? [];
        $revoke = $overrides['revoke'] ?? [];
        $granted = array_values(array_diff(array_unique(array_merge($granted, $grant)), $revoke));
    }

    return $cache[$userId] = $granted;
}

/** Does the current logged-in user (or a specific user id) have this permission? */
function userCan(string $permissionKey, ?int $userId = null): bool {
    $userId = $userId ?? currentUserId();
    if (!$userId) return false;
    // super_admin / admin always pass, even if role_permissions seed is somehow incomplete
    if (in_array(currentUserRole(), ['super_admin', 'admin'], true) && $userId === currentUserId()) {
        return true;
    }
    return in_array($permissionKey, getUserPermissions($userId), true);
}

/** Guard a page/endpoint behind a permission key. */
function requirePermission(string $permissionKey): void {
    requireLogin();
    if (!userCan($permissionKey)) {
        http_response_code(403);
        die('403 Forbidden: You do not have permission to access this (' . htmlspecialchars($permissionKey) . ').');
    }
}

/** Same as requirePermission but returns a JSON 403 for API endpoints instead of dying with HTML. */
function requirePermissionApi(string $permissionKey): void {
    requireLogin();
    if (!userCan($permissionKey)) {
        jsonResponse(false, null, 'You do not have permission to perform this action.', 403);
    }
}

/** Human-readable role label for display. */
function roleLabel(string $role): string {
    $labels = [
        'super_admin'     => 'Super Admin',
        'admin'           => 'Admin',
        'manager'         => 'Manager',
        'sales_executive' => 'Sales Executive',
        'telecaller'      => 'Telecaller',
        'employee'        => 'Employee',
        'accountant'      => 'Accountant',
    ];
    return $labels[$role] ?? ucfirst($role);
}

function allRoles(): array {
    return ['super_admin','admin','manager','sales_executive','telecaller','employee','accountant'];
}

/** Roles that are allowed to view revenue/profit/commission figures, for quick UI checks. */
function canViewRevenue(): bool {
    return userCan('revenue.view');
}
