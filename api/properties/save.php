<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireValidRequest();

$db = getDB();
$id = (int)($_POST['id'] ?? 0);
$category = clean($_POST['category'] ?? '');
if (!in_array($category, ['buy','sale','rental','lease','rental_tenant'], true)) {
    jsonResponse(false, null, 'Invalid category.', 422);
}

$clientName = clean($_POST['client_name'] ?? '');
$clientMobile = normalizeMobileForStorage(clean($_POST['client_mobile'] ?? ''));
$propertyType = clean($_POST['property_type'] ?? '');
$address = clean($_POST['address'] ?? '');
$city = clean($_POST['city'] ?? '') ?: null;
$mapLink = clean($_POST['map_link'] ?? '') ?: null;
if ($mapLink !== null && !preg_match('#^https?://#i', $mapLink)) {
    jsonResponse(false, null, 'Map link should be a valid http:// or https:// URL.', 422);
}
$budget = !empty($_POST['budget']) ? (float)$_POST['budget'] : null;
$assignedTo = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
$status = clean($_POST['status'] ?? 'active');
$description = clean($_POST['description'] ?? '') ?: null;
$notes = clean($_POST['notes'] ?? '') ?: null;
$isStock = !empty($_POST['is_stock']) ? 1 : 0; // only "Our Stock" forms send this; regular lead forms don't
// Manually confirmed at registration time (or later via "Mark Subscription
// Paid"): whether the client has actually paid the ₹1000 subscription.
// 'sale' clients don't have a subscription at all, so this is always 0 for them.
$subscriptionPaid = ($category !== 'sale' && !empty($_POST['subscription_paid'])) ? 1 : 0;

if ($clientName === '' || $clientMobile === '' || $propertyType === '' || $address === '') {
    jsonResponse(false, null, 'Please fill all required fields.', 422);
}
if (!isValidMobile($clientMobile)) {
    jsonResponse(false, null, 'Please enter a valid 10-digit mobile number.', 422);
}
if (!userCan('properties.manage')) { $assignedTo = currentUserId(); }

/**
 * Saves any uploaded image/video/audio files for this property.
 * Expects multi-file inputs named images[], videos[], audios[].
 * Returns a list of human-readable error strings (skipped files),
 * never throws — a bad file shouldn't block saving the record itself.
 */
function saveIncomingPropertyMedia(PDO $db, int $propertyId): array {
    $FIELD_TO_TYPE = ['images' => 'image', 'videos' => 'video', 'audios' => 'audio'];
    $errors = [];
    $insert = $db->prepare("INSERT INTO property_images (property_id, file_path, media_type, original_name) VALUES (?,?,?,?)");
    foreach ($FIELD_TO_TYPE as $field => $mediaType) {
        if (empty($_FILES[$field]) || !isset($_FILES[$field]['error'])) continue;
        $names = $_FILES[$field]['name'];
        $count = is_array($names) ? count($names) : 1;
        for ($i = 0; $i < $count; $i++) {
            $file = is_array($names) ? [
                'name' => $_FILES[$field]['name'][$i],
                'type' => $_FILES[$field]['type'][$i],
                'tmp_name' => $_FILES[$field]['tmp_name'][$i],
                'error' => $_FILES[$field]['error'][$i],
                'size' => $_FILES[$field]['size'][$i],
            ] : $_FILES[$field];
            $result = savePropertyMediaFile($file, $propertyId, $mediaType);
            if ($result === null) continue; // no file chosen for this slot
            if (isset($result['error'])) { $errors[] = $result['error']; continue; }
            $insert->execute([$propertyId, $result['path'], $mediaType, $result['original_name']]);
        }
    }
    return $errors;
}

try {
if ($id > 0) {
    $existing = $db->prepare("SELECT * FROM properties WHERE id = ?");
    $existing->execute([$id]);
    $old = $existing->fetch();
    if (!$old) jsonResponse(false, null, 'Property not found.', 404);
    if (!userCan('properties.manage') && (int)$old['assigned_to'] !== currentUserId()) {
        jsonResponse(false, null, 'Not authorized to edit this property.', 403);
    }

    $stmt = $db->prepare("UPDATE properties SET client_name=?, client_mobile=?, property_type=?, address=?, city=?, map_link=?,
        budget=?, assigned_to=?, status=?, subscription_paid=?, description=?, notes=?, updated_at=NOW() WHERE id=?");
    $stmt->execute([$clientName,$clientMobile,$propertyType,$address,$city,$mapLink,$budget,$assignedTo,$status,$subscriptionPaid,$description,$notes,$id]);

    if ($old['status'] !== $status) {
        logActivity('property', $id, 'status_changed', 'status', $old['status'], $status, currentUserId());
    }
    if ((int)$old['subscription_paid'] !== $subscriptionPaid) {
        logActivity('property', $id, 'subscription_paid_changed', 'subscription_paid', $old['subscription_paid'], (string)$subscriptionPaid, currentUserId());
    }
    logActivity('property', $id, 'updated', null, null, null, currentUserId());
    $mediaErrors = saveIncomingPropertyMedia($db, $id);
    jsonResponse(true, ['id' => $id], 'Property updated successfully.' . ($mediaErrors ? ' (' . implode(' ', $mediaErrors) . ')' : ''));
} else {
    $stmt = $db->prepare("INSERT INTO properties
        (property_code, category, is_stock, client_name, client_mobile, property_type, address, city, map_link, budget,
         assigned_to, status, subscription_paid, description, notes, created_by, created_at)
        VALUES ('TEMP',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
    $stmt->execute([$category,$isStock,$clientName,$clientMobile,$propertyType,$address,$city,$mapLink,$budget,$assignedTo,$status,$subscriptionPaid,$description,$notes,currentUserId()]);
    $newId = (int)$db->lastInsertId();
    $db->prepare("UPDATE properties SET property_code = ? WHERE id = ?")->execute([generateCode('PROP', $newId), $newId]);

    logActivity('property', $newId, 'created', null, null, null, currentUserId());
    $mediaErrors = saveIncomingPropertyMedia($db, $newId);
    jsonResponse(true, ['id' => $newId], 'Property added successfully.' . ($mediaErrors ? ' (' . implode(' ', $mediaErrors) . ')' : ''));
}
} catch (PDOException $e) {
    error_log('Property save failed: ' . $e->getMessage());
    jsonResponse(false, null, 'Could not save. This usually means the database needs a migration script run — check /database for the latest migration_v*.sql files. Contact your administrator.', 500);
}
