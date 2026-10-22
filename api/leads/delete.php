<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requirePermissionApi('leads.delete');
requireValidRequest();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) jsonResponse(false, null, 'Invalid lead id.', 422);

$db = getDB();
$stmt = $db->prepare("SELECT id FROM leads WHERE id = ?");
$stmt->execute([$id]);
if (!$stmt->fetch()) jsonResponse(false, null, 'Lead not found.', 404);

$db->prepare("DELETE FROM leads WHERE id = ?")->execute([$id]);
logActivity('lead', $id, 'deleted', null, null, null, currentUserId());

jsonResponse(true, null, 'Lead deleted successfully.');
