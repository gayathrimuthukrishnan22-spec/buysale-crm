<?php
/**
 * Deletes one uploaded image/video/audio file that belongs to a
 * property/stock entry — used by the media manager in the Add/Edit
 * Stock modal. Same edit authorization as api/properties/save.php:
 * managers can delete any property's media, other employees only
 * media on properties assigned to them.
 */
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireValidRequest();

$mediaId = (int)($_POST['media_id'] ?? 0);
if ($mediaId <= 0) jsonResponse(false, null, 'Invalid media id.', 422);

$db = getDB();
$stmt = $db->prepare("SELECT pi.*, p.assigned_to FROM property_images pi
                       JOIN properties p ON p.id = pi.property_id WHERE pi.id = ?");
$stmt->execute([$mediaId]);
$media = $stmt->fetch();
if (!$media) jsonResponse(false, null, 'Media not found.', 404);

if (!userCan('properties.manage') && (int)$media['assigned_to'] !== currentUserId()) {
    jsonResponse(false, null, 'Not authorized to delete this file.', 403);
}

$db->prepare("DELETE FROM property_images WHERE id = ?")->execute([$mediaId]);

$fullPath = rtrim(UPLOAD_DIR, '/') . '/' . $media['file_path'];
if (is_file($fullPath)) @unlink($fullPath);

jsonResponse(true, null, 'File removed.');
