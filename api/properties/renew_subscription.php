<?php
/**
 * Renew a client requirement's subscription (Rs.1000 -> SUBSCRIPTION_MATCH_LIMIT
 * fresh "Connect"s). Resets the used-match counter to 0 and brings a 'dead'
 * client back to 'active' so staff can connect matches for them again.
 */
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireValidRequest();

$db = getDB();
$clientId = (int)($_POST['client_id'] ?? 0);
if ($clientId <= 0) jsonResponse(false, null, 'Invalid request.', 422);

try {

$stmt = $db->prepare("SELECT * FROM properties WHERE id = ? AND is_stock = 0");
$stmt->execute([$clientId]);
$client = $stmt->fetch();
if (!$client) jsonResponse(false, null, 'Client requirement not found.', 404);

if (!userCan('properties.manage') && (int)$client['assigned_to'] !== currentUserId()) {
    jsonResponse(false, null, 'Not authorized.', 403);
}

$wasDead = $client['status'] === 'dead';
$newStatus = $wasDead ? 'active' : $client['status'];

$db->prepare("DELETE FROM property_connections WHERE client_id = ?")->execute([$clientId]);

$upd = $db->prepare("UPDATE properties SET subscription_matches_used = 0, status = ?, subscription_renewed_at = ? WHERE id = ?");
$upd->execute([$newStatus, date('Y-m-d H:i:s'), $clientId]);

logActivity('property', $clientId, 'subscription_renewed', null, null,
    'Subscription renewed for ₹' . number_format(SUBSCRIPTION_AMOUNT) . ' — ' . SUBSCRIPTION_MATCH_LIMIT . ' new matches available', currentUserId());
if ($wasDead) {
    logActivity('property', $clientId, 'status_changed', 'status', $client['status'], 'active', currentUserId());
}

jsonResponse(true, [
    'matches_used' => 0,
    'match_limit'  => SUBSCRIPTION_MATCH_LIMIT,
    'status'       => $newStatus,
], 'Subscription renewed. ' . SUBSCRIPTION_MATCH_LIMIT . ' new matches available.');

} catch (PDOException $e) {
    error_log('renew_subscription.php failed: ' . $e->getMessage());
    jsonResponse(false, null, 'Could not renew. This usually means the database needs a migration script run — check /database for the latest migration_v*.sql files (v12 and v13 add the subscription/connect feature). Contact your administrator.', 500);
}
