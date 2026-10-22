<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requirePermissionApi('employees.manage');
requireValidRequest();

$db = getDB();
$id = (int)($_POST['id'] ?? 0);
$name = clean($_POST['name'] ?? '');
$username = clean($_POST['username'] ?? '');
$mobile = clean($_POST['mobile'] ?? '') ?: null;
$email = clean($_POST['email'] ?? '') ?: null;
$password = $_POST['password'] ?? '';
$role = clean($_POST['role'] ?? 'employee');
$reportsTo = !empty($_POST['reports_to']) ? (int)$_POST['reports_to'] : null;
$grantedPermissions = $_POST['granted_permissions'] ?? [];

if ($name === '' || $username === '') {
    jsonResponse(false, null, 'Name and username are required.', 422);
}
if (!preg_match('/^[a-zA-Z0-9_.]{3,50}$/', $username)) {
    jsonResponse(false, null, 'Username must be 3-50 chars, letters/numbers/dot/underscore only.', 422);
}
if (!in_array($role, allRoles(), true)) {
    jsonResponse(false, null, 'Invalid role.', 422);
}
// Only a super_admin can create/edit other super_admins or admins
if (in_array($role, ['super_admin', 'admin'], true) && !isSuperAdmin()) {
    jsonResponse(false, null, 'Only a Super Admin can assign the Super Admin or Admin role.', 403);
}
if ($id === currentUserId()) {
    jsonResponse(false, null, 'You cannot edit your own account from this screen.', 422);
}

// Uniqueness check
$check = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
$check->execute([$username, $id]);
if ($check->fetch()) {
    jsonResponse(false, null, 'This username is already taken.', 422);
}

// Compute permission override JSON ({"grant":[...],"revoke":[...]}) as a diff against the role's defaults.
// IMPORTANT: only touch permissions if the permission-editor UI was actually part of this submission
// (marked by permissions_editor_present). Unchecked checkboxes are indistinguishable from "field absent"
// in HTML forms, so without this marker we can't tell "user unchecked everything" from "editor wasn't
// shown / this was a raw API call" — treating the latter as "revoke everything" would silently strip
// all of a role's default permissions from any account created without the JS-rendered grid.
$permissionsJson = null;
if (userCan('employees.permissions') && isset($_POST['permissions_editor_present'])) {
    $catalogueStmt = $db->query("SELECT `key` FROM permissions");
    $validKeys = array_column($catalogueStmt->fetchAll(), 'key');
    $granted = array_values(array_intersect((array)$grantedPermissions, $validKeys));

    $roleDefaultStmt = $db->prepare("SELECT permission_key FROM role_permissions WHERE role = ?");
    $roleDefaultStmt->execute([$role]);
    $roleDefaults = array_column($roleDefaultStmt->fetchAll(), 'permission_key');

    $grant = array_values(array_diff($granted, $roleDefaults));   // checked but not default
    $revoke = array_values(array_diff($roleDefaults, $granted));  // default but unchecked

    if ($grant || $revoke) {
        $permissionsJson = json_encode(['grant' => $grant, 'revoke' => $revoke]);
    }
}

if ($id > 0) {
    $existing = $db->prepare("SELECT role FROM users WHERE id = ?");
    $existing->execute([$id]);
    $oldRole = $existing->fetchColumn();
    if ($oldRole === false) jsonResponse(false, null, 'Team member not found.', 404);
    if (in_array($oldRole, ['super_admin', 'admin'], true) && !isSuperAdmin()) {
        jsonResponse(false, null, 'Only a Super Admin can modify this account.', 403);
    }

    if ($password !== '') {
        if (strlen($password) < 6) jsonResponse(false, null, 'Password must be at least 6 characters.', 422);
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $db->prepare("UPDATE users SET name=?, username=?, mobile=?, email=?, password_hash=?, role=?, reports_to=?, permissions=?, updated_at=NOW() WHERE id=?");
        $stmt->execute([$name,$username,$mobile,$email,$hash,$role,$reportsTo,$permissionsJson,$id]);
    } else {
        $stmt = $db->prepare("UPDATE users SET name=?, username=?, mobile=?, email=?, role=?, reports_to=?, permissions=?, updated_at=NOW() WHERE id=?");
        $stmt->execute([$name,$username,$mobile,$email,$role,$reportsTo,$permissionsJson,$id]);
    }
    if ($oldRole !== $role) {
        logActivity('user', $id, 'role_changed', 'role', $oldRole, $role, currentUserId());
    }
    logActivity('user', $id, 'updated', null, null, null, currentUserId());
    jsonResponse(true, ['id' => $id], 'Team member updated successfully.');
} else {
    if (strlen($password) < 6) {
        jsonResponse(false, null, 'Password must be at least 6 characters.', 422);
    }
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $db->prepare("INSERT INTO users (name, username, mobile, email, password_hash, role, reports_to, permissions, status, created_at)
                           VALUES (?,?,?,?,?,?,?,?, 'active', NOW())");
    $stmt->execute([$name,$username,$mobile,$email,$hash,$role,$reportsTo,$permissionsJson]);
    $newId = (int)$db->lastInsertId();
    logActivity('user', $newId, 'created', null, null, null, currentUserId());
    jsonResponse(true, ['id' => $newId], 'Team member created successfully.');
}
