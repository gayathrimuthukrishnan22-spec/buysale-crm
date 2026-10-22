<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireValidRequest();

$db = getDB();
$leadId = (int)($_POST['lead_id'] ?? 0);
$meetingType = clean($_POST['meeting_type'] ?? 'call');
$scheduledAt = clean($_POST['scheduled_at'] ?? '');
$remarks = clean($_POST['remarks'] ?? '') ?: null;

if ($leadId <= 0 || $scheduledAt === '') {
    jsonResponse(false, null, 'Lead and scheduled date/time are required.', 422);
}
if (!in_array($meetingType, ['call','site_visit','office_meeting','other'], true)) {
    jsonResponse(false, null, 'Invalid meeting type.', 422);
}

// Verify lead exists and access rights
$leadStmt = $db->prepare("SELECT * FROM leads WHERE id = ?");
$leadStmt->execute([$leadId]);
$lead = $leadStmt->fetch();
if (!$lead) jsonResponse(false, null, 'Lead not found.', 404);
if (!userCan('leads.view_all') && (int)$lead['assigned_to'] !== currentUserId()) {
    jsonResponse(false, null, 'Not authorized for this lead.', 403);
}

$scheduledAtSql = str_replace('T', ' ', $scheduledAt) . ':00';

$stmt = $db->prepare("INSERT INTO followups (lead_id, scheduled_at, meeting_type, remarks, outcome, created_by, created_at)
                       VALUES (?,?,?,?, 'pending', ?, NOW())");
$stmt->execute([$leadId, $scheduledAtSql, $meetingType, $remarks, currentUserId()]);
$newId = (int)$db->lastInsertId();

// Keep leads.next_followup_at in sync for dashboard queries
$db->prepare("UPDATE leads SET next_followup_at = ?, status = IF(status='new','follow_up',status) WHERE id = ?")
   ->execute([$scheduledAtSql, $leadId]);

logActivity('followup', $newId, 'created', null, null, $scheduledAtSql, currentUserId());
jsonResponse(true, ['id' => $newId], 'Follow-up scheduled successfully.');
