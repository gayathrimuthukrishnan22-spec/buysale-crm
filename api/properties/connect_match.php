<?php
/**
 * "Connect" a client requirement (properties.is_stock = 0) to one of the
 * stock matches suggested in match_stock.php.
 *
 * Each connect uses up one unit of the client's subscription
 * (SUBSCRIPTION_MATCH_LIMIT per SUBSCRIPTION_AMOUNT paid — see config.php).
 * Once the limit is reached, the client's status auto-flips to 'dead' and
 * no further connects are allowed until the subscription is renewed
 * (see renew_subscription.php).
 */
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireValidRequest();

$db = getDB();
$clientId = (int)($_POST['client_id'] ?? 0);
$stockId  = (int)($_POST['stock_id'] ?? 0);
if ($clientId <= 0 || $stockId <= 0) jsonResponse(false, null, 'Invalid request.', 422);

try {

$stmt = $db->prepare("SELECT * FROM properties WHERE id = ? AND is_stock = 0");
$stmt->execute([$clientId]);
$client = $stmt->fetch();
if (!$client) jsonResponse(false, null, 'Client requirement not found.', 404);

if (!userCan('properties.manage') && (int)$client['assigned_to'] !== currentUserId()) {
    jsonResponse(false, null, 'Not authorized.', 403);
}

$stockStmt = $db->prepare("SELECT id, property_code, client_name FROM properties WHERE id = ? AND is_stock = 1");
$stockStmt->execute([$stockId]);
$stock = $stockStmt->fetch();
if (!$stock) jsonResponse(false, null, 'Stock property not found.', 404);

// Matching no longer requires the ₹1000 subscription checkbox to be ticked
// (payment is confirmed upstream — via Payment Verification for website
// leads, or by staff before adding manually). Only the match-limit /
// already-dead check below still blocks a connect.
if ((int)$client['subscription_matches_used'] >= SUBSCRIPTION_MATCH_LIMIT || $client['status'] === 'dead') {
    jsonResponse(false, null, 'Subscription exhausted — renew the subscription to connect more matches.', 422);
}

$usedNow = (int)$client['subscription_matches_used'] + 1;
$goesDead = $usedNow >= SUBSCRIPTION_MATCH_LIMIT;
$newStatus = $goesDead ? 'dead' : $client['status'];

$db->prepare("INSERT INTO property_connections (client_id, stock_id, connected_by) VALUES (?,?,?)")
   ->execute([$clientId, $stockId, currentUserId()]);

$upd = $db->prepare("UPDATE properties SET subscription_matches_used = ?, status = ? WHERE id = ?");
$upd->execute([$usedNow, $newStatus, $clientId]);

logActivity('property', $clientId, 'match_connected', 'subscription_matches_used',
    (string)$client['subscription_matches_used'], (string)$usedNow, currentUserId());
logActivity('property', $clientId, 'match_connected',
    null, null, 'Connected to stock ' . $stock['property_code'] . ' (' . $stock['client_name'] . ')', currentUserId());

if ($goesDead) {
    logActivity('property', $clientId, 'status_changed', 'status', $client['status'], 'dead', currentUserId());
}

jsonResponse(true, [
    'matches_used' => $usedNow,
    'match_limit'  => SUBSCRIPTION_MATCH_LIMIT,
    'status'       => $newStatus,
    'went_dead'    => $goesDead,
], $goesDead
    ? 'Connected. Subscription used up — this lead is now marked dead until renewed.'
    : 'Connected.');

} catch (PDOException $e) {
    error_log('connect_match.php failed: ' . $e->getMessage());
    jsonResponse(false, null, 'Could not connect. This usually means the database needs a migration script run — check /database for the latest migration_v*.sql files (v12 and v13 add the subscription/connect feature). Contact your administrator.', 500);
}
