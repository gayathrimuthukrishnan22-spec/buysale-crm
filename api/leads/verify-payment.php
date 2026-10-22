<?php
/**
 * Verify or reject the advance-payment proof on a website-booking lead.
 * Only once a payment is marked 'verified' here does the lead stop being
 * hidden from api/leads/list.php and start showing up in the normal
 * Leads pipeline for employees to work.
 */
require_once __DIR__ . '/../../includes/auth.php';
requirePermissionApi('leads.view_all');
requireValidRequest();

$paymentId = (int)($_POST['payment_id'] ?? 0);
$action    = clean($_POST['action'] ?? ''); // 'verify' | 'reject'

if ($paymentId <= 0) jsonResponse(false, null, 'Invalid payment id.', 422);
if (!in_array($action, ['verify', 'reject'], true)) {
    jsonResponse(false, null, 'Action must be verify or reject.', 422);
}
$newStatus = $action === 'verify' ? 'verified' : 'rejected';

$db = getDB();
$stmt = $db->prepare("SELECT lp.id, lp.lead_id, lp.status, l.lead_code, l.client_name
                       FROM lead_payments lp JOIN leads l ON l.id = lp.lead_id
                       WHERE lp.id = ?");
$stmt->execute([$paymentId]);
$payment = $stmt->fetch();
if (!$payment) jsonResponse(false, null, 'Payment record not found.', 404);

$db->prepare("UPDATE lead_payments SET status = ? WHERE id = ?")->execute([$newStatus, $paymentId]);

// The matching properties row (see api/public/register.php) was created
// with status = 'payment_verification' and lead_id = this lead, and stays
// hidden from the Buy/Sale/Rental admin pages until this decision is made:
//   verify -> activate it AND mark subscription_paid = 1. The website
//             advance fee the client already paid IS the ₹1000/₹500
//             subscription fee, so verifying it here immediately unlocks
//             "Find Matching Stock" too — no separate manual step needed.
//   reject -> delete it outright (does not go to Dead Leads)
if ($action === 'verify') {
    $db->prepare("UPDATE properties SET status = 'active', subscription_paid = 1 WHERE lead_id = ? AND status = 'payment_verification'")
       ->execute([$payment['lead_id']]);
} else {
    $db->prepare("DELETE FROM properties WHERE lead_id = ? AND status = 'payment_verification'")
       ->execute([$payment['lead_id']]);
}

logActivity(
    'lead',
    (int)$payment['lead_id'],
    $action === 'verify' ? 'payment_verified' : 'payment_rejected',
    'payment_status',
    $payment['status'],
    $newStatus,
    currentUserId()
);

$message = $action === 'verify'
    ? $payment['client_name'] . "'s advance payment was verified — this registration now appears in Leads."
    : $payment['client_name'] . "'s advance payment was rejected — this registration will stay out of Leads.";

jsonResponse(true, ['lead_id' => (int)$payment['lead_id'], 'status' => $newStatus], $message);
