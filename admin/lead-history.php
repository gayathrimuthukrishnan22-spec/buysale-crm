<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/quick_actions.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
$db = getDB();
$stmt = $db->prepare("SELECT l.*, u.name AS assigned_name, cu.name AS created_by_name
                       FROM leads l LEFT JOIN users u ON u.id=l.assigned_to
                       LEFT JOIN users cu ON cu.id = l.created_by WHERE l.id=?");
$stmt->execute([$id]);
$lead = $stmt->fetch();
if (!$lead) { http_response_code(404); die('Lead not found.'); }
if (!userCan('leads.view_all') && (int)$lead['assigned_to'] !== currentUserId()) { http_response_code(403); die('Not authorized.'); }

$fu = $db->prepare("SELECT f.*, cu.name AS created_by_name, mu.name AS modified_by_name
                     FROM followups f LEFT JOIN users cu ON cu.id=f.created_by LEFT JOIN users mu ON mu.id=f.last_modified_by
                     WHERE f.lead_id=? ORDER BY f.scheduled_at DESC");
$fu->execute([$id]);
$followups = $fu->fetchAll();

$log = $db->prepare("SELECT a.*, u.name AS performed_by_name FROM activity_log a
                      LEFT JOIN users u ON u.id=a.performed_by
                      WHERE a.entity_type IN ('lead','followup') AND a.entity_id IN (
                        ?, (SELECT id FROM followups WHERE lead_id = ? LIMIT 1)
                      ) OR (a.entity_type='lead' AND a.entity_id=?)
                      ORDER BY a.created_at DESC LIMIT 100");
// simpler: fetch all lead-entity logs + all followup logs whose lead_id matches
$log = $db->prepare("SELECT a.*, u.name AS performed_by_name FROM activity_log a
                      LEFT JOIN users u ON u.id=a.performed_by
                      WHERE (a.entity_type='lead' AND a.entity_id=?)
                         OR (a.entity_type='followup' AND a.entity_id IN (SELECT id FROM followups WHERE lead_id=?))
                      ORDER BY a.created_at DESC LIMIT 100");
$log->execute([$id, $id]);
$history = $log->fetchAll();

// ---------------- Suggested matching stock ----------------
// Compare this lead's requirement (category, place, budget) against "Our Stock"
// items (owner-supplied inventory) in the same category, so the employee can see
// what's available to offer this client without hunting through the stock list.
$stockMatches = [];
$locationTerms = array_filter([$lead['city'] ?? null, $lead['preferred_location'] ?? null]);
if ($lead['requirement'] && ($locationTerms || $lead['budget'])) {
    $where = ['p.is_stock = 1', "p.status = 'active'", 'p.category = ?'];
    $params = [$lead['requirement']];
    if ($locationTerms) {
        $locConds = [];
        foreach ($locationTerms as $term) {
            $locConds[] = '(p.city LIKE ? OR p.address LIKE ?)';
            $params[] = "%$term%";
            $params[] = "%$term%";
        }
        $where[] = '(' . implode(' OR ', $locConds) . ')';
    }
    $whereSql = implode(' AND ', $where);
    $stockStmt = $db->prepare("SELECT p.*, u.name AS owner_assigned_name FROM properties p
                                LEFT JOIN users u ON u.id = p.assigned_to
                                WHERE $whereSql ORDER BY p.created_at DESC LIMIT 20");
    $stockStmt->execute($params);
    $stockMatches = $stockStmt->fetchAll();

    // Closer budget match first when the lead gave a budget.
    if ($lead['budget']) {
        $leadBudget = (float)$lead['budget'];
        usort($stockMatches, function ($a, $b) use ($leadBudget) {
            $da = $a['budget'] !== null ? abs((float)$a['budget'] - $leadBudget) : PHP_INT_MAX;
            $db_ = $b['budget'] !== null ? abs((float)$b['budget'] - $leadBudget) : PHP_INT_MAX;
            return $da <=> $db_;
        });
    }
    $stockMatches = array_slice($stockMatches, 0, 8);
}

$pageTitle = 'Lead History';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="fw-800 mb-0"><?= e($lead['client_name']) ?> <span class="text-muted-sm fw-400">(<?= e($lead['lead_code']) ?>)</span></h4>
    <div class="text-muted-sm"><?= e($lead['client_mobile']) ?> · <span class="text-capitalize"><?= e($lead['requirement']) ?></span> requirement</div>
  </div>
  <div class="d-flex align-items-center gap-2">
    <?php renderQuickActions($lead['client_mobile'], $lead['client_name'], $lead['requirement']); ?>
    <a href="javascript:history.back()" class="btn btn-light"><i class="bi bi-arrow-left"></i> Back</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card-panel mb-3">
      <div class="card-header">Lead Details</div>
      <div class="card-body">
        <table class="table table-borderless mb-0 fs-13">
          <tr><td class="text-muted-sm">Status</td><td><span class="badge-status badge-<?= e($lead['status']) ?>"><?= e(str_replace('_',' ',$lead['status'])) ?></span></td></tr>
          <tr><td class="text-muted-sm">Budget</td><td><?= formatMoney($lead['budget']) ?></td></tr>
          <tr><td class="text-muted-sm">Property Type</td><td><?= e($lead['property_type'] ?? '-') ?></td></tr>
          <tr><td class="text-muted-sm">Location</td><td><?= e($lead['preferred_location'] ?? '-') ?></td></tr>
          <tr><td class="text-muted-sm">Source</td><td><?= e($lead['source'] ?? '-') ?></td></tr>
          <tr><td class="text-muted-sm">Assigned To</td><td><?= e($lead['assigned_name'] ?? 'Unassigned') ?></td></tr>
          <tr><td class="text-muted-sm">Created By</td><td><?= e($lead['created_by_name'] ?? '-') ?></td></tr>
          <tr><td class="text-muted-sm">Created</td><td><?= date('d M Y, h:i A', strtotime($lead['created_at'])) ?></td></tr>
        </table>
        <?php if ($lead['notes']): ?>
          <hr>
          <div class="text-muted-sm fw-600 mb-1">Notes</div>
          <div class="fs-13"><?= nl2br(e($lead['notes'])) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card-panel mb-3">
      <div class="card-header"><i class="bi bi-stars me-1 text-accent"></i> Suggested Stock</div>
      <div class="card-body p-0">
        <?php if (!$stockMatches): ?>
          <div class="text-center text-muted-sm py-3 px-3">
            <?= $locationTerms || $lead['budget']
                ? 'No matching stock right now for this place/budget.'
                : 'Add a place or budget to this lead to see matching stock.' ?>
          </div>
        <?php else: ?>
          <div class="list-group list-group-flush">
            <?php foreach ($stockMatches as $s): ?>
              <div class="list-group-item">
                <div class="d-flex justify-content-between align-items-start gap-2">
                  <div>
                    <div class="fw-600 fs-13"><?= e($s['property_type']) ?> — <?= e($s['city'] ?: $s['address']) ?></div>
                    <div class="text-muted-sm fs-13"><?= e($s['address']) ?></div>
                    <div class="fs-13 mt-1"><?= formatMoney($s['budget']) ?> · Owner: <?= e($s['client_name']) ?></div>
                  </div>
                  <?php renderQuickActions($s['client_mobile'], $s['client_name']); ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card-panel mb-3">
      <div class="card-header">Follow-up History</div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-modern mb-0">
            <thead><tr><th>Type</th><th>Scheduled</th><th>Outcome</th><th>Last Modified By</th></tr></thead>
            <tbody>
              <?php if (!$followups): ?>
                <tr><td colspan="4" class="text-center text-muted-sm py-3">No follow-ups recorded yet.</td></tr>
              <?php endif; ?>
              <?php foreach ($followups as $f): ?>
                <tr>
                  <td data-label="Type" class="text-capitalize"><?= e(str_replace('_',' ',$f['meeting_type'])) ?></td>
                  <td data-label="Scheduled"><?= date('d M Y, h:i A', strtotime($f['scheduled_at'])) ?></td>
                  <td data-label="Outcome"><span class="badge-status badge-<?= $f['outcome']==='done'?'converted':($f['outcome']==='pending'?'new':'lost') ?>"><?= e(str_replace('_',' ',$f['outcome'])) ?></span></td>
                  <td data-label="Last Modified By" class="text-muted-sm"><?= e($f['modified_by_name'] ?? $f['created_by_name'] ?? '-') ?> <br><?= timeAgo($f['updated_at']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="card-panel">
      <div class="card-header">Change Log / Audit Trail</div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-modern mb-0">
            <thead><tr><th>Action</th><th>Field</th><th>Old → New</th><th>By</th><th>When</th></tr></thead>
            <tbody>
              <?php if (!$history): ?>
                <tr><td colspan="5" class="text-center text-muted-sm py-3">No activity recorded yet.</td></tr>
              <?php endif; ?>
              <?php foreach ($history as $h): ?>
                <tr>
                  <td data-label="Action" class="text-capitalize"><?= e(str_replace('_',' ',$h['action'])) ?></td>
                  <td data-label="Field" class="text-muted-sm"><?= e($h['field_changed'] ?? '-') ?></td>
                  <td data-label="Old → New" class="fs-13"><?= $h['old_value'] || $h['new_value'] ? e($h['old_value']).' → '.e($h['new_value']) : '-' ?></td>
                  <td data-label="By"><?= e($h['performed_by_name'] ?? 'System') ?></td>
                  <td data-label="When" class="text-muted-sm"><?= timeAgo($h['created_at']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
