<?php
/**
 * "Mark Subscription Paid" quick action — confirms the client has paid the
 * ₹1000 subscription without needing to open the full Edit form. Once this
 * is set, "Find Matching Stock" / "Connect" become available for them
 * (see match_stock.php / connect_match.php, which both require this flag).
 */
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireValidRequest();

$db = getDB();
$clientId = (int)($_POST['client_id'] ?? 0);
if ($clientId <= 0) jsonResponse(false, null, 'Invalid request.', 422);

$stmt = $db->prepare("SELECT * FROM properties WHERE id = ? AND is_stock = 0");
$stmt->execute([$clientId]);
$client = $stmt->fetch();
if (!$client) jsonResponse(false, null, 'Client requirement not found.', 404);

if (!userCan('properties.manage') && (int)$client['assigned_to'] !== currentUserId()) {
    jsonResponse(false, null, 'Not authorized.', 403);
}
if ($client['category'] === 'sale') {
    jsonResponse(false, null, 'Sale clients do not have a subscription.', 422);
}
if ((int)$client['subscription_paid'] === 1) {
    jsonResponse(true, ['subscription_paid' => 1], 'Already marked as paid.');
}

$db->prepare("UPDATE properties SET subscription_paid = 1 WHERE id = ?")->execute([$clientId]);
logActivity('property', $clientId, 'subscription_paid_changed', 'subscription_paid', '0', '1', currentUserId());

jsonResponse(true, ['subscription_paid' => 1], 'Subscription marked as paid. Find Matching Stock is now available.');
