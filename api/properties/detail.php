<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) jsonResponse(false, null, 'Invalid property id.', 422);

$db = getDB();
$stmt = $db->prepare("SELECT p.*, u.name AS assigned_name FROM properties p LEFT JOIN users u ON u.id=p.assigned_to WHERE p.id=?");
$stmt->execute([$id]);
$property = $stmt->fetch();
if (!$property) jsonResponse(false, null, 'Property not found.', 404);

if (!userCan('properties.manage') && (int)$property['assigned_to'] !== currentUserId()) {
    jsonResponse(false, null, 'Not authorized.', 403);
}

$imgStmt = $db->prepare("SELECT * FROM property_images WHERE property_id = ?");
$imgStmt->execute([$id]);
$images = $imgStmt->fetchAll();

jsonResponse(true, ['property' => $property, 'images' => $images]);
