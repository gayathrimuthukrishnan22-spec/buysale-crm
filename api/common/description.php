<?php
/**
 * Generic "Description" quick-edit box, usable from the 3-dot Manage menu on
 * any list table (Stock, Properties, Leads, Follow-ups, Employees).
 *
 * GET  ?entity_type=&entity_id=   -> current description + full change history
 * POST entity_type, entity_id, description, csrf_token -> saves a new value
 *
 * History is kept entirely in activity_log (field_changed='description'), so
 * the "current" value is simply the most recent entry — every save is
 * automatically timestamped (created_at) and attributed (performed_by).
 */
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$ALLOWED_TYPES = ['property', 'lead', 'followup', 'user'];

/**
 * Look up the record and decide who's allowed to view / edit its description,
 * mirroring the ownership rules already used by each module's save endpoint.
 */
function resolveDescriptionMeta(string $entityType, int $entityId, PDO $db): ?array {
    switch ($entityType) {
        case 'property':
            $stmt = $db->prepare("SELECT id, assigned_to FROM properties WHERE id = ?");
            $stmt->execute([$entityId]);
            $row = $stmt->fetch();
            if (!$row) return null;
            return [
                'canView' => userCan('properties.view'),
                'canEdit' => userCan('properties.manage') || (int)$row['assigned_to'] === currentUserId(),
            ];

        case 'lead':
            $stmt = $db->prepare("SELECT id, assigned_to FROM leads WHERE id = ?");
            $stmt->execute([$entityId]);
            $row = $stmt->fetch();
            if (!$row) return null;
            $owns = userCan('leads.view_all') || (int)$row['assigned_to'] === currentUserId();
            return [
                'canView' => userCan('leads.view') && $owns,
                'canEdit' => userCan('leads.edit') && $owns,
            ];

        case 'followup':
            $stmt = $db->prepare("SELECT f.id, l.assigned_to FROM followups f
                                   JOIN leads l ON l.id = f.lead_id WHERE f.id = ?");
            $stmt->execute([$entityId]);
            $row = $stmt->fetch();
            if (!$row) return null;
            $owns = userCan('leads.view_all') || (int)$row['assigned_to'] === currentUserId();
            return [
                'canView' => $owns,
                'canEdit' => userCan('followups.manage') && $owns,
            ];

        case 'user':
            $stmt = $db->prepare("SELECT id FROM users WHERE id = ?");
            $stmt->execute([$entityId]);
            if (!$stmt->fetch()) return null;
            return [
                'canView' => userCan('employees.manage'),
                'canEdit' => userCan('employees.manage'),
            ];

        default:
            return null;
    }
}

$entityType = clean($_GET['entity_type'] ?? ($_POST['entity_type'] ?? ''));
$entityId   = (int)($_GET['entity_id'] ?? ($_POST['entity_id'] ?? 0));

if (!in_array($entityType, $ALLOWED_TYPES, true) || $entityId <= 0) {
    jsonResponse(false, null, 'Invalid request.', 422);
}

$db = getDB();
$meta = resolveDescriptionMeta($entityType, $entityId, $db);
if (!$meta) jsonResponse(false, null, 'Record not found.', 404);
if (!$meta['canView']) jsonResponse(false, null, 'Not authorized.', 403);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidRequest();
    if (!$meta['canEdit']) jsonResponse(false, null, 'Not authorized to edit this.', 403);

    $description = clean($_POST['description'] ?? '');

    $prevStmt = $db->prepare("SELECT new_value FROM activity_log
                               WHERE entity_type = ? AND entity_id = ? AND field_changed = 'description'
                               ORDER BY created_at DESC, id DESC LIMIT 1");
    $prevStmt->execute([$entityType, $entityId]);
    $prevRow = $prevStmt->fetch();
    $oldValue = $prevRow ? $prevRow['new_value'] : null;

    if (($oldValue ?? '') === $description) {
        jsonResponse(true, ['unchanged' => true], 'No changes to save.');
    }

    logActivity($entityType, $entityId, 'description_updated', 'description',
        $oldValue, $description !== '' ? $description : null, currentUserId());

    // properties.description is also shown on the Add/Edit form — keep it in sync.
    if ($entityType === 'property') {
        $db->prepare("UPDATE properties SET description = ? WHERE id = ?")
           ->execute([$description !== '' ? $description : null, $entityId]);
    }

    jsonResponse(true, ['saved_at' => date('d M Y, h:i A')], 'Description saved.');
}

// ---- GET: current value + history ----
$log = $db->prepare("SELECT a.old_value, a.new_value, a.created_at, u.name AS performed_by_name
                      FROM activity_log a LEFT JOIN users u ON u.id = a.performed_by
                      WHERE a.entity_type = ? AND a.entity_id = ? AND a.field_changed = 'description'
                      ORDER BY a.created_at DESC, a.id DESC LIMIT 50");
$log->execute([$entityType, $entityId]);
$rows = $log->fetchAll();

$history = array_map(function ($h) {
    return [
        'value'     => $h['new_value'],
        'old_value' => $h['old_value'],
        'by'        => $h['performed_by_name'] ?? 'System',
        'at'        => date('d M Y, h:i A', strtotime($h['created_at'])),
        'ago'       => timeAgo($h['created_at']),
    ];
}, $rows);

jsonResponse(true, [
    'description' => $rows ? ($rows[0]['new_value'] ?? '') : '',
    'history'      => $history,
    'can_edit'     => $meta['canEdit'],
]);
