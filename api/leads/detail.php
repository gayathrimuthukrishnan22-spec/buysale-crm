<?php
require_once __DIR__ . '/../../includes/auth.php';
requirePermission('leads.view');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) jsonResponse(false, null, 'Invalid lead id.', 422);

$db = getDB();
$stmt = $db->prepare("SELECT l.*, u.name AS assigned_name FROM leads l LEFT JOIN users u ON u.id=l.assigned_to WHERE l.id = ?");
$stmt->execute([$id]);
$lead = $stmt->fetch();
if (!$lead) jsonResponse(false, null, 'Lead not found.', 404);

if (!userCan('leads.view_all') && (int)$lead['assigned_to'] !== currentUserId()) {
    jsonResponse(false, null, 'Not authorized.', 403);
}

if (!canViewRevenue()) {
    foreach (['property_value','commission_rate','commission_amount','expected_revenue',
              'actual_revenue','expected_profit','actual_profit','employee_incentive'] as $f) {
        unset($lead[$f]);
    }
}

$fu = $db->prepare("SELECT f.*, cu.name AS created_by_name, mu.name AS modified_by_name
                     FROM followups f
                     LEFT JOIN users cu ON cu.id = f.created_by
                     LEFT JOIN users mu ON mu.id = f.last_modified_by
                     WHERE f.lead_id = ? ORDER BY f.scheduled_at DESC");
$fu->execute([$id]);
$followups = $fu->fetchAll();

$log = $db->prepare("SELECT a.*, u.name AS performed_by_name FROM activity_log a
                      LEFT JOIN users u ON u.id = a.performed_by
                      WHERE a.entity_type='lead' AND a.entity_id = ? ORDER BY a.created_at DESC LIMIT 50");
$log->execute([$id]);
$history = $log->fetchAll();

$docStmt = $db->prepare("SELECT id, doc_type, original_name, file_path, uploaded_at FROM lead_documents WHERE lead_id = ? ORDER BY uploaded_at");
$docStmt->execute([$id]);
$documents = $docStmt->fetchAll();

$payStmt = $db->prepare("SELECT id, amount, method, screenshot_path, status, paid_at FROM lead_payments WHERE lead_id = ? ORDER BY paid_at DESC");
$payStmt->execute([$id]);
$payments = $payStmt->fetchAll();

// Property photos/video the customer uploaded via the "Give my own Property"
// (Step 4) flow on book.html. These live on a `properties` row that
// register.php auto-creates and links back with properties.lead_id = this
// lead's id (see register.php's "Also create a matching Property record"
// block) — they are NOT in lead_documents, so they need their own fetch here
// to show up in the Lead Detail modal's "Property Requirement" step.
$propertyPhotos = [];
$propStmt = $db->prepare("SELECT id FROM properties WHERE lead_id = ?");
$propStmt->execute([$id]);
$linkedPropertyIds = $propStmt->fetchAll(PDO::FETCH_COLUMN);
if ($linkedPropertyIds) {
    $placeholders = implode(',', array_fill(0, count($linkedPropertyIds), '?'));
    $mediaStmt = $db->prepare("SELECT id, property_id, file_path, media_type, original_name
                                FROM property_images
                                WHERE property_id IN ($placeholders)
                                ORDER BY id ASC");
    $mediaStmt->execute($linkedPropertyIds);
    $propertyPhotos = $mediaStmt->fetchAll();
}

jsonResponse(true, ['lead' => $lead, 'followups' => $followups, 'history' => $history, 'documents' => $documents, 'payments' => $payments, 'propertyPhotos' => $propertyPhotos]);
