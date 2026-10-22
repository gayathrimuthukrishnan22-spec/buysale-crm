<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requirePermissionApi('projects.manage');
requireValidRequest();

$db = getDB();
$id = (int)($_POST['id'] ?? 0);

$name = clean($_POST['name'] ?? '');
$builder = clean($_POST['builder'] ?? '') ?: null;
$location = clean($_POST['location'] ?? '') ?: null;
$totalUnits = (int)($_POST['total_units'] ?? 0);
$availableUnits = (int)($_POST['available_units'] ?? 0);
$soldUnits = (int)($_POST['sold_units'] ?? 0);
$rentalUnits = (int)($_POST['rental_units'] ?? 0);
$status = clean($_POST['status'] ?? 'active');
$category = clean($_POST['category'] ?? 'general');

if ($name === '') {
    jsonResponse(false, null, 'Project name is required.', 422);
}
if (!in_array($status, ['active','completed','on_hold'], true)) {
    jsonResponse(false, null, 'Invalid status.', 422);
}
$VALID_CATEGORIES = ['general','house_rent_requirement','commercial_space_requirement','flat_lease','flat_selling','land_selling'];
if (!in_array($category, $VALID_CATEGORIES, true)) {
    jsonResponse(false, null, 'Invalid category.', 422);
}

if ($id > 0) {
    $existing = $db->prepare("SELECT id FROM projects WHERE id = ?");
    $existing->execute([$id]);
    if (!$existing->fetch()) jsonResponse(false, null, 'Project not found.', 404);

    $stmt = $db->prepare("UPDATE projects SET name=?, builder=?, location=?, category=?, total_units=?, available_units=?,
        sold_units=?, rental_units=?, status=?, updated_at=NOW() WHERE id=?");
    $stmt->execute([$name,$builder,$location,$category,$totalUnits,$availableUnits,$soldUnits,$rentalUnits,$status,$id]);

    logActivity('project', $id, 'updated', null, null, null, currentUserId());
    jsonResponse(true, ['id' => $id], 'Project updated successfully.');
} else {
    $stmt = $db->prepare("INSERT INTO projects
        (project_code, name, builder, location, category, total_units, available_units, sold_units, rental_units, status, created_by, created_at)
        VALUES ('TEMP',?,?,?,?,?,?,?,?,?,?,NOW())");
    $stmt->execute([$name,$builder,$location,$category,$totalUnits,$availableUnits,$soldUnits,$rentalUnits,$status,currentUserId()]);
    $newId = (int)$db->lastInsertId();
    $db->prepare("UPDATE projects SET project_code = ? WHERE id = ?")->execute([generateCode('PRJ', $newId), $newId]);

    logActivity('project', $newId, 'created', null, null, null, currentUserId());
    jsonResponse(true, ['id' => $newId], 'Project added successfully.');
}
