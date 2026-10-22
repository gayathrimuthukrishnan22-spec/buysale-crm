<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireValidRequest();

$db = getDB();
$id = (int)($_POST['id'] ?? 0);
$action = clean($_POST['action'] ?? ''); // 'done' | 'reschedule' | 'no_response' | 'cancelled'
$newDateTime = clean($_POST['scheduled_at'] ?? '');

if ($id <= 0) jsonResponse(false, null, 'Invalid follow-up id.', 422);

$stmt = $db->prepare("SELECT f.*, l.assigned_to FROM followups f JOIN leads l ON l.id = f.lead_id WHERE f.id = ?");
$stmt->execute([$id]);
$fu = $stmt->fetch();
if (!$fu) jsonResponse(false, null, 'Follow-up not found.', 404);
if (!userCan('leads.view_all') && (int)$fu['assigned_to'] !== currentUserId()) {
    jsonResponse(false, null, 'Not authorized.', 403);
}

if ($action === 'reschedule') {
    if ($newDateTime === '') jsonResponse(false, null, 'New date/time is required.', 422);
    $newDateTimeSql = str_replace('T', ' ', $newDateTime) . ':00';
    $db->prepare("UPDATE followups SET scheduled_at=?, outcome='pending', last_modified_by=?, updated_at=NOW() WHERE id=?")
       ->execute([$newDateTimeSql, currentUserId(), $id]);
    $db->prepare("UPDATE leads SET next_followup_at=? WHERE id=?")->execute([$newDateTimeSql, $fu['lead_id']]);
    logActivity('followup', $id, 'rescheduled', 'scheduled_at', $fu['scheduled_at'], $newDateTimeSql, currentUserId());
    jsonResponse(true, null, 'Follow-up rescheduled successfully.');
} elseif (in_array($action, ['done','no_response','cancelled'], true)) {
    $outcome = $action === 'done' ? 'done' : $action;
    $db->prepare("UPDATE followups SET outcome=?, last_modified_by=?, updated_at=NOW() WHERE id=?")
       ->execute([$outcome, currentUserId(), $id]);
    logActivity('followup', $id, 'outcome_updated', 'outcome', $fu['outcome'], $outcome, currentUserId());
    jsonResponse(true, null, 'Follow-up updated successfully.');
} else {
    jsonResponse(false, null, 'Invalid action.', 422);
}
