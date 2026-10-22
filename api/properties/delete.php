<?php
require_once __DIR__ . '/../../includes/auth.php';
requirePermissionApi('properties.manage');
requireValidRequest();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) jsonResponse(false, null, 'Invalid property id.', 422);

$db = getDB();
$stmt = $db->prepare("SELECT id, status FROM properties WHERE id = ?");
$stmt->execute([$id]);
$prop = $stmt->fetch();
if (!$prop) jsonResponse(false, null, 'Property not found.', 404);

// A dead lead (subscription used up) can never be deleted, from any page —
// it can only be viewed/edited, or renewed back to active. This is enforced
// here (not just hidden in the UI) so it holds even if this endpoint is
// called directly.
if ($prop['status'] === 'dead') {
    jsonResponse(false, null, 'Dead leads cannot be deleted. Renew the subscription first if you no longer need it marked dead.', 422);
}

$db->prepare("DELETE FROM properties WHERE id = ?")->execute([$id]);
logActivity('property', $id, 'deleted', null, null, null, currentUserId());

jsonResponse(true, null, 'Property deleted successfully.');
