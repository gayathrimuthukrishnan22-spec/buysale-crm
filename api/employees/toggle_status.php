<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requirePermissionApi('employees.manage');
requireValidRequest();

$id = (int)($_POST['id'] ?? 0);
$status = clean($_POST['status'] ?? '');
if (!in_array($status, ['active','disabled'], true) || $id <= 0) {
    jsonResponse(false, null, 'Invalid request.', 422);
}
if ($id === currentUserId()) {
    jsonResponse(false, null, 'You cannot disable your own account.', 422);
}

$db = getDB();
$stmt = $db->prepare("SELECT status, role FROM users WHERE id = ?");
$stmt->execute([$id]);
$target = $stmt->fetch();
if (!$target) jsonResponse(false, null, 'Team member not found.', 404);
if (in_array($target['role'], ['super_admin', 'admin'], true) && !isSuperAdmin()) {
    jsonResponse(false, null, 'Only a Super Admin can change this account\'s status.', 403);
}

$db->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$status, $id]);
logActivity('user', $id, 'status_changed', 'status', $target['status'], $status, currentUserId());

jsonResponse(true, null, 'Team member ' . ($status === 'active' ? 'enabled' : 'disabled') . ' successfully.');
