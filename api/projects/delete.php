<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requirePermissionApi('projects.manage');
requireValidRequest();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) jsonResponse(false, null, 'Invalid project id.', 422);

$db = getDB();
$stmt = $db->prepare("SELECT id FROM projects WHERE id = ?");
$stmt->execute([$id]);
if (!$stmt->fetch()) jsonResponse(false, null, 'Project not found.', 404);

// Unlink any leads pointing to this project rather than blocking deletion
$db->prepare("UPDATE leads SET project_id = NULL WHERE project_id = ?")->execute([$id]);
$db->prepare("DELETE FROM projects WHERE id = ?")->execute([$id]);
logActivity('project', $id, 'deleted', null, null, null, currentUserId());

jsonResponse(true, null, 'Project deleted successfully.');
