<?php
/**
 * Shared template. Expects $category ('buy'|'sale'|'rental'|'lease'|'rental_tenant') and $pageTitle set by caller.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/quick_actions.php';
requireLogin();

$db = getDB();
$employees = $db->query("SELECT id, name FROM users WHERE role='employee' AND status='active' ORDER BY name")->fetchAll();

$icons = ['buy' => 'bi-cash-coin', 'sale' => 'bi-tag', 'rental' => 'bi-key', 'lease' => 'bi-file-earmark-text', 'rental_tenant' => 'bi-house-gear'];
$icon = $icons[$category] ?? 'bi-houses';

// Display label shown to the user for this category (falls back to the raw category text).
$categoryLabels = ['buy' => 'Buy', 'sale' => 'Sale', 'rental' => 'Rental', 'lease' => 'Lease', 'rental_tenant' => 'Tenant Rental'];
$categoryLabel = $categoryLabels[$category] ?? $category;

// Full page heading/subtitle per category — most follow "<Label> Properties", but
// Tenant Rental drops the "Properties"/"property" wording per request.
$categoryHeadings = ['rental_tenant' => 'Tenant Rental'];
$heading = $categoryHeadings[$category] ?? ($categoryLabel . ' Properties');
$categorySubtitles = ['rental_tenant' => 'Manage Tenant Rental listings'];
$subtitle = $categorySubtitles[$category] ?? ('Manage ' . $categoryLabel . ' property listings');

// The person being added differs by category: a Buy listing is really a Buyer,
// a Sale listing a Seller, and Rental/Lease/Tenant Rental listings a Tenant.
$personLabels = ['buy' => 'Buyer', 'sale' => 'Seller', 'rental' => 'Rental Property', 'lease' => 'Tenant', 'rental_tenant' => 'Tenant'];
$personLabel = $personLabels[$category] ?? 'Property';

include __DIR__ . '/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <div>
    <h4 class="fw-800 mb-0"><i class="bi <?= $icon ?> me-1 text-accent"></i> <?= e($heading) ?></h4>
    <div class="text-muted-sm"><?= e($subtitle) ?></div>
  </div>
  <button class="btn btn-accent" data-bs-toggle="modal" data-bs-target="#propModal" onclick="openPropModal()">
    <i class="bi bi-plus-lg me-1"></i> Add <?= e($personLabel) ?>
  </button>
</div>

<div class="card-panel mb-3">
  <div class="card-body">
    <div class="row g-2 align-items-end">
      <div class="col-md-4 col-6">
        <label class="form-label fs-13 fw-600 mb-1">Search</label>
        <input type="text" id="fSearch" class="form-control" placeholder="Client name, mobile, ID or location">
      </div>
      <div class="col-md-3 col-6">
        <label class="form-label fs-13 fw-600 mb-1">Status</label>
        <select id="fStatus" class="form-select">
          <option value="">All Status</option>
          <option value="active">Active</option>
          <option value="pending">Pending</option>
          <option value="closed">Closed</option>
          <option value="cancelled">Cancelled</option>
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
      <div class="col-md-2 col-6">
        <label class="form-label fs-13 fw-600 mb-1">Date</label>
        <input type="date" id="fDate" class="form-control">
      </div>
    </div>
  </div>
</div>

<div class="table-responsive-modern">
  <table class="table table-modern align-middle" id="propTable">
    <thead>
      <tr>
        <th>Property ID</th>
        <th>Client</th>
        <th>Type</th>
        <th>Location</th>
        <th>Budget</th>
        <th>Assigned</th>
        <th>Status</th>
        <th>Created</th>
        <th class="text-center">Quick Actions</th>
        <th class="text-end">Manage</th>
      </tr>
    </thead>
    <tbody id="propTableBody">
      <tr><td colspan="10" class="text-center py-4 text-muted-sm">Loading...</td></tr>
    </tbody>
  </table>
</div>
<nav class="mt-3"><ul class="pagination justify-content-end" id="propPagination"></ul></nav>

<!-- Property Modal -->
<div class="modal fade" id="propModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form id="propForm">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="id" id="propId">
        <input type="hidden" name="category" value="<?= e($category) ?>">
        <div class="modal-header">
          <h5 class="modal-title fw-700" id="propModalTitle">Add <?= e($personLabel) ?></h5>
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
              <input type="text" name="property_type" class="form-control" placeholder="Apartment, Villa, Plot, Commercial" required>
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
                <?php if ($category !== 'sale'): ?>
                <!-- Kept out of view on purpose: this option only exists so saving the
                     form on an already-dead lead without touching Status doesn't
                     silently un-dead it. Use "Renew Subscription" instead (it also
                     resets the match counter properly). -->
                <option value="dead" style="display:none;">Dead (subscription used up)</option>
                <?php endif; ?>
              </select>
            </div>
            <?php if ($category !== 'sale'): ?>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="subscription_paid" value="1" id="subscriptionPaidChk">
                <label class="form-check-label fs-13 fw-600" for="subscriptionPaidChk">
                  Client has paid ₹<?= (int)SUBSCRIPTION_AMOUNT ?> subscription
                </label>
                <div class="text-muted-sm fs-13">Confirm this only once payment is actually collected — Find Matching Stock stays off for this lead until it's checked.</div>
              </div>
            </div>
            <?php endif; ?>
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
          <button type="submit" class="btn btn-accent">Save Property</button>
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
  window.PROPERTY_CATEGORY = "<?= e($category) ?>";
  window.PROPERTY_PERSON_LABEL = "<?= e($personLabel) ?>";
  window.SUBSCRIPTION_AMOUNT = <?= (int)SUBSCRIPTION_AMOUNT ?>;
  window.SUBSCRIPTION_MATCH_LIMIT = <?= (int)SUBSCRIPTION_MATCH_LIMIT ?>;
</script>
<script src="<?= BASE_URL ?>/assets/js/properties.js?v=<?= @filemtime(__DIR__ . '/../assets/js/properties.js') ?>"></script>
