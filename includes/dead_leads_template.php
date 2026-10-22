<?php
/**
 * Dead Leads — shows every client requirement (Buy/Rental/Lease/Tenant Rental)
 * currently marked 'dead' because its subscription match-limit was used up.
 * A lead appears here automatically the moment it goes dead, and disappears
 * automatically the moment its subscription is renewed — it then simply shows
 * up again on its own category page (Buy/Rental/Lease/Tenant Rental), status
 * active, same as before it went dead. There is no separate "Add" here since
 * these rows aren't created directly — they arrive from the category pages.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/quick_actions.php';
requireLogin();

$pageTitle = 'Dead Leads';
$db = getDB();
$employees = $db->query("SELECT id, name FROM users WHERE role='employee' AND status='active' ORDER BY name")->fetchAll();

include __DIR__ . '/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <div>
    <h4 class="fw-800 mb-0"><i class="bi bi-emoji-frown me-1 text-accent"></i> Dead Leads</h4>
    <div class="text-muted-sm">Leads whose subscription is used up — renew to send them back to their original list</div>
  </div>
</div>

<div class="card-panel mb-3">
  <div class="card-body">
    <div class="row g-2 align-items-end">
      <div class="col-md-3 col-6">
        <label class="form-label fs-13 fw-600 mb-1">Search</label>
        <input type="text" id="fSearch" class="form-control" placeholder="Client name, mobile, ID or location">
      </div>
      <div class="col-md-3 col-6">
        <label class="form-label fs-13 fw-600 mb-1">Category</label>
        <select id="fCategory" class="form-select">
          <option value="">All Categories</option>
          <option value="buy">Buy</option>
          <option value="rental">Rental</option>
          <option value="lease">Lease</option>
          <option value="rental_tenant">Tenant Rental</option>
        </select>
      </div>
      <?php if (userCan('properties.manage')): ?>
      <div class="col-md-3 col-6">
        <label class="form-label fs-13 fw-600 mb-1">Employee</label>
        <select id="fEmployee" class="form-select">
          <option value="">All Employees</option>
          <?php foreach ($employees as $emp): ?>
            <option value="<?= $emp['id'] ?>"><?= e($emp['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="col-md-3 col-6">
        <label class="form-label fs-13 fw-600 mb-1">Date</label>
        <input type="date" id="fDate" class="form-control">
      </div>
    </div>
  </div>
</div>

<div class="table-responsive-modern">
  <table class="table table-modern align-middle" id="deadTable">
    <thead>
      <tr>
        <th>Property ID</th>
        <th>Client</th>
        <th>Category</th>
        <th>Type</th>
        <th>Location</th>
        <th>Budget</th>
        <th>Assigned</th>
        <th>Last Updated</th>
        <th class="text-center">Quick Actions</th>
        <th class="text-end">Manage</th>
      </tr>
    </thead>
    <tbody id="deadTableBody">
      <tr><td colspan="10" class="text-center py-4 text-muted-sm">Loading...</td></tr>
    </tbody>
  </table>
</div>
<nav class="mt-3"><ul class="pagination justify-content-end" id="deadPagination"></ul></nav>

<!-- Edit/View Modal (category comes from the row itself, not a fixed page) -->
<div class="modal fade" id="propModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form id="propForm">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="id" id="propId">
        <input type="hidden" name="category" id="propCategory">
        <div class="modal-header">
          <h5 class="modal-title fw-700" id="propModalTitle">Lead Details</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-600 fs-13">Client Name *</label>
              <input type="text" name="client_name" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600 fs-13">Client Mobile *</label>
              <input type="text" name="client_mobile" class="form-control" autocomplete="off" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600 fs-13">Property Type *</label>
              <input type="text" name="property_type" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600 fs-13">Budget (₹)</label>
              <input type="number" name="budget" class="form-control">
            </div>
            <div class="col-md-8">
              <label class="form-label fw-600 fs-13">Address / Location *</label>
              <input type="text" name="address" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-600 fs-13">City</label>
              <input type="text" name="city" class="form-control">
            </div>
            <?php if (userCan('properties.manage')): ?>
            <div class="col-md-6">
              <label class="form-label fw-600 fs-13">Assign To</label>
              <select name="assigned_to" class="form-select">
                <option value="">Unassigned</option>
                <?php foreach ($employees as $emp): ?>
                  <option value="<?= $emp['id'] ?>"><?= e($emp['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endif; ?>
            <div class="col-md-6">
              <label class="form-label fw-600 fs-13">Status</label>
              <select name="status" class="form-select">
                <option value="active">Active</option>
                <option value="pending">Pending</option>
                <option value="closed">Closed</option>
                <option value="cancelled">Cancelled</option>
                <!-- Kept out of view on purpose: this option only exists so saving the
                     form without touching Status doesn't silently un-dead the lead.
                     Use "Renew Subscription" to bring a dead lead back properly (it
                     also resets the match counter) instead of picking Active here. -->
                <option value="dead" style="display:none;">Dead (subscription used up)</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-600 fs-13">Description</label>
              <textarea name="description" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-12">
              <label class="form-label fw-600 fs-13">Internal Notes</label>
              <textarea name="notes" class="form-control" rows="2"></textarea>
            </div>
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

<!-- Match Stock Modal -->
<div class="modal fade" id="matchModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-700"><i class="bi bi-stars me-1 text-accent"></i> Matching Stock Suggestions</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="matchModalBody">
        <div class="text-center py-4 text-muted-sm">Loading...</div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
<script>
  window.SUBSCRIPTION_AMOUNT = <?= (int)SUBSCRIPTION_AMOUNT ?>;
  window.SUBSCRIPTION_MATCH_LIMIT = <?= (int)SUBSCRIPTION_MATCH_LIMIT ?>;
</script>
<script src="<?= BASE_URL ?>/assets/js/dead-leads.js?v=<?= @filemtime(__DIR__ . '/../assets/js/dead-leads.js') ?>"></script>
