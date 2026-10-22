<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/quick_actions.php';
requireLogin();

$db = getDB();
$uid = currentUserId();

$where = userCan('leads.view_all') ? '1=1' : 'l.assigned_to = ' . (int)$uid;
$followups = $db->query("
  SELECT f.*, l.client_name, l.client_mobile, l.requirement, l.lead_code, u.name AS assigned_name
  FROM followups f
  JOIN leads l ON l.id = f.lead_id
  LEFT JOIN users u ON u.id = l.assigned_to
  WHERE $where AND f.outcome = 'pending'
  ORDER BY f.scheduled_at ASC
")->fetchAll();

$leadsForDropdown = $db->query("
  SELECT id, lead_code, client_name FROM leads " . (userCan("leads.view_all") ? "" : "WHERE assigned_to = $uid") . " ORDER BY client_name
")->fetchAll();

$pageTitle = 'Meetings & Follow-ups';
include __DIR__ . '/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <div>
    <h4 class="fw-800 mb-0">Meetings &amp; Follow-ups</h4>
    <div class="text-muted-sm">Scheduled call-backs, site visits, and meetings</div>
  </div>
  <button class="btn btn-accent" data-bs-toggle="modal" data-bs-target="#fuModal">
    <i class="bi bi-calendar-plus me-1"></i> Schedule Follow-up
  </button>
</div>

<div class="table-responsive-modern">
  <table class="table table-modern align-middle">
    <thead>
      <tr><th>Client</th><th>Requirement</th><th>Type</th><th>Scheduled</th><th>Assigned</th><th class="text-center">Contact</th><th class="text-end">Manage</th></tr>
    </thead>
    <tbody>
      <?php if (!$followups): ?>
        <tr><td colspan="7" class="text-center py-4 text-muted-sm">No pending follow-ups. 🎉</td></tr>
      <?php endif; ?>
      <?php foreach ($followups as $f):
        $isOverdue = strtotime($f['scheduled_at']) < time();
      ?>
        <tr class="<?= $isOverdue ? 'table-warning' : '' ?>">
          <td data-label="Client">
            <div class="client-name"><?= e($f['client_name']) ?></div>
            <div class="client-mobile"><?= e($f['client_mobile']) ?> · <?= e($f['lead_code']) ?></div>
          </td>
          <td data-label="Requirement" class="text-capitalize"><?= e($f['requirement']) ?></td>
          <td data-label="Type" class="text-capitalize"><?= e(str_replace('_',' ',$f['meeting_type'])) ?></td>
          <td data-label="Scheduled"><?= date('d M Y, h:i A', strtotime($f['scheduled_at'])) ?><?= $isOverdue ? ' <span class="badge-status badge-lost">Overdue</span>' : '' ?></td>
          <td data-label="Assigned"><?= e($f['assigned_name'] ?? '-') ?></td>
          <td data-label="Contact" class="text-center"><?php renderQuickActions($f['client_mobile'], $f['client_name'], $f['requirement']); ?></td>
          <td data-label="Manage" class="text-end">
            <div class="dropdown">
              <button class="btn btn-sm btn-light" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
              <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="#" onclick="markDone(<?= $f['id'] ?>, '<?= e(addslashes($f['client_name'])) ?>');return false;"><i class="bi bi-check2-circle me-2"></i>Mark Done</a></li>
                <li><a class="dropdown-item" href="#" onclick="reschedule(<?= $f['id'] ?>, '<?= e($f['scheduled_at']) ?>');return false;"><i class="bi bi-calendar-event me-2"></i>Reschedule</a></li>
                <li><a class="dropdown-item" href="#" onclick="openDescriptionBox('followup', <?= $f['id'] ?>, '<?= e(addslashes($f['client_name'])) ?>');return false;"><i class="bi bi-card-text me-2"></i>Description</a></li>
                <li><a class="dropdown-item" href="#" onclick="markOutcome(<?= $f['id'] ?>, 'no_response');return false;"><i class="bi bi-telephone-x me-2"></i>No Response</a></li>
                <li><a class="dropdown-item text-danger" href="#" onclick="markOutcome(<?= $f['id'] ?>, 'cancelled');return false;"><i class="bi bi-x-circle me-2"></i>Cancel</a></li>
              </ul>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Schedule Modal -->
<div class="modal fade" id="fuModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-scrollable">
    <div class="modal-content">
      <form id="fuForm">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="id" id="fuId">
        <div class="modal-header">
          <h5 class="modal-title fw-700" id="fuModalTitle">Schedule Follow-up</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-600 fs-13">Lead *</label>
            <select name="lead_id" id="fuLeadId" class="form-select" required>
              <option value="">Select a lead...</option>
              <?php foreach ($leadsForDropdown as $l): ?>
                <option value="<?= $l['id'] ?>"><?= e($l['lead_code']) ?> — <?= e($l['client_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-600 fs-13">Meeting Type</label>
            <select name="meeting_type" class="form-select">
              <option value="call">Call</option>
              <option value="site_visit">Site Visit</option>
              <option value="office_meeting">Office Meeting</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-600 fs-13">Date &amp; Time *</label>
            <input type="datetime-local" name="scheduled_at" id="fuScheduledAt" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-600 fs-13">Remarks</label>
            <textarea name="remarks" class="form-control" rows="2"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-accent">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Mark Done Modal: a description is required to save as Done.
     Leaving it blank instead marks the meeting as Not Attended. -->
<div class="modal fade" id="fuDoneModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-scrollable">
    <div class="modal-content">
      <input type="hidden" id="fuDoneId">
      <div class="modal-header">
        <h5 class="modal-title fw-700" id="fuDoneModalTitle">Meeting Outcome</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label class="form-label fw-600 fs-13">What happened in the meeting? *</label>
        <textarea id="fuDoneDescription" class="form-control" rows="4" autocomplete="off"
                  placeholder="e.g. Client visited the site, discussed budget, will confirm by Friday..."></textarea>
        <div class="form-text fs-12">
          Fill this in to mark the meeting <strong>Done</strong>. Leaving it blank and saving will instead mark it as
          <strong>Not Attended</strong>.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-accent" id="fuDoneSaveBtn"><i class="bi bi-check2 me-1"></i>Save</button>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
<script src="<?= BASE_URL ?>/assets/js/followups.js?v=<?= @filemtime(__DIR__ . '/../assets/js/followups.js') ?>"></script>
