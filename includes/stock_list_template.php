<?php
/**
 * Shared template. Expects $stockType ('rental_house'|'land_sale'|'agri_land'|'house_sale'|
 * 'commercial_sale'|'commercial_lease'|'residential_lease') and $pageTitle set by caller.
 * Read-first "Our Stock" view: what's available, and in which place.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/quick_actions.php';
requireLogin();
requirePermission('properties.view');

$db = getDB();
$employees = $db->query("SELECT id, name FROM users WHERE role='employee' AND status='active' ORDER BY name")->fetchAll();

$STOCK_META = [
    'rental_house'      => ['category' => 'rental', 'icon' => 'bi-key',              'label' => 'Rental House',      'person' => 'Owner'],
    'land_sale'         => ['category' => 'sale',   'icon' => 'bi-map',              'label' => 'Land for Sale',     'person' => 'Owner'],
    'agri_land'         => ['category' => 'sale',   'icon' => 'bi-tree',             'label' => 'Agriculture Land',  'person' => 'Owner'],
    'house_sale'        => ['category' => 'sale',   'icon' => 'bi-house-door',       'label' => 'House for Sale',    'person' => 'Owner'],
    'commercial_sale'   => ['category' => 'sale',   'icon' => 'bi-building',         'label' => 'Commercial',        'person' => 'Owner'],
    'commercial_lease'  => ['category' => 'lease',  'icon' => 'bi-building',         'label' => 'Commercial Lease',  'person' => 'Owner'],
    'residential_lease' => ['category' => 'lease',  'icon' => 'bi-house-heart',      'label' => 'Residential Lease', 'person' => 'Owner'],
];
$meta = $STOCK_META[$stockType] ?? null;
if (!$meta) { http_response_code(404); die('Unknown stock type.'); }

include __DIR__ . '/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <div>
    <h4 class="fw-800 mb-0"><i class="bi <?= $meta['icon'] ?> me-1 text-accent"></i> <?= e($meta['label']) ?> — Our Stock</h4>
    <div class="text-muted-sm">What's currently available, place-wise — your own stock, not a lead</div>
  </div>
  <button class="btn btn-accent" data-bs-toggle="modal" data-bs-target="#stockModal" onclick="openStockModal()">
    <i class="bi bi-plus-lg me-1"></i> Add Stock
  </button>
</div>

<!-- Location summary strip -->
<div class="card-panel mb-3">
  <div class="card-body">
    <div class="fs-13 fw-600 text-muted-sm mb-2"><i class="bi bi-geo-alt me-1"></i>Place-wise availability</div>
    <div id="stockCityStrip" class="d-flex flex-wrap gap-2">
      <span class="text-muted-sm">Loading...</span>
    </div>
  </div>
</div>

<div class="card-panel mb-3">
  <div class="card-body">
    <div class="row g-2 align-items-end">
      <div class="col-md-4 col-6">
        <label class="form-label fs-13 fw-600 mb-1">Search</label>
        <input type="text" id="fSearch" class="form-control" autocomplete="off" placeholder="Owner name, mobile, ID or location">
      </div>
      <div class="col-md-3 col-6">
        <label class="form-label fs-13 fw-600 mb-1">Status</label>
        <select id="fStatus" class="form-select">
          <option value="active">Active (currently available)</option>
          <option value="">All Status</option>
          <option value="pending">Pending</option>
          <option value="closed">Closed</option>
          <option value="cancelled">Cancelled</option>
        </select>
      </div>
      <div class="col-md-3 col-6">
        <label class="form-label fs-13 fw-600 mb-1">Place</label>
        <select id="fCity" class="form-select">
          <option value="">All Places</option>
        </select>
      </div>
      <div class="col-md-2 col-6 text-md-end">
        <button type="button" class="btn btn-light w-100" id="fClear"><i class="bi bi-x-circle me-1"></i>Clear</button>
      </div>
    </div>
  </div>
</div>

<div class="table-responsive-modern">
  <table class="table table-modern align-middle" id="stockTableBody-wrap">
    <thead>
      <tr>
        <th>Property ID</th>
        <th>Owner</th>
        <th>Type</th>
        <th>Place</th>
        <th>Budget</th>
        <th>Assigned</th>
        <th>Status</th>
        <th class="text-center">Quick Actions</th>
        <th class="text-end">Manage</th>
      </tr>
    </thead>
    <tbody id="stockTableBody">
      <tr><td colspan="9" class="text-center py-4 text-muted-sm">Loading...</td></tr>
    </tbody>
  </table>
</div>
<nav class="mt-3"><ul class="pagination justify-content-end" id="stockPagination"></ul></nav>

<!-- Add/Edit Modal -->
<div class="modal fade" id="stockModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form id="stockForm" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="id" id="stockId">
        <input type="hidden" name="category" value="<?= e($meta['category']) ?>">
        <input type="hidden" name="is_stock" value="1">
        <div class="modal-header">
          <h5 class="modal-title fw-700" id="stockModalTitle">Add Stock</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-600 fs-13">Owner Name *</label>
              <input type="text" name="client_name" class="form-control" autocomplete="off" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600 fs-13">Owner Mobile *</label>
              <input type="text" name="client_mobile" class="form-control" autocomplete="off" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600 fs-13">Property Type *</label>
              <input type="text" name="property_type" class="form-control" autocomplete="off"
                     placeholder="<?php
                        echo match ($stockType) {
                            'land_sale'         => 'Plot, Land, Site',
                            'agri_land'          => 'Agricultural Land, Farm Land',
                            'house_sale'        => 'House, Villa, Apartment',
                            'commercial_sale'    => 'Shop, Office, Showroom, Warehouse',
                            'commercial_lease'  => 'Shop, Office, Showroom, Warehouse',
                            'residential_lease' => 'Apartment, House, Villa',
                            default             => 'Apartment, Villa, Commercial',
                        };
                     ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600 fs-13">Budget (₹)</label>
              <input type="number" name="budget" class="form-control" autocomplete="off">
            </div>
            <div class="col-md-8">
              <label class="form-label fw-600 fs-13">Address / Site Location *</label>
              <input type="text" name="address" class="form-control" autocomplete="off" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-600 fs-13">Place / City</label>
              <input type="text" name="city" class="form-control" autocomplete="off">
            </div>
            <div class="col-12">
              <label class="form-label fw-600 fs-13"><i class="bi bi-geo-alt-fill me-1"></i>Google Map Link</label>
              <input type="url" name="map_link" class="form-control" autocomplete="off" placeholder="https://maps.google.com/?q=...">
              <div class="form-text fs-12">Paste the "Share" link from Google Maps for this location's pin.</div>
            </div>
            <div class="col-12">
              <hr class="my-1">
              <label class="form-label fw-600 fs-13 mb-2"><i class="bi bi-images me-1"></i>Photos &amp; Videos</label>
              <div id="stockMediaExisting" class="stock-media-grid mb-2"></div>
              <div class="row g-2">
                <div class="col-md-6">
                  <label class="form-label fs-12 text-muted-sm mb-1">Add Images</label>
                  <input type="file" name="images[]" class="form-control form-control-sm" accept="image/*" multiple>
                </div>
                <div class="col-md-6">
                  <label class="form-label fs-12 text-muted-sm mb-1">Add Videos</label>
                  <input type="file" name="videos[]" class="form-control form-control-sm" accept="video/*" multiple>
                </div>
              </div>
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

<?php include __DIR__ . '/footer.php'; ?>
<script>
  window.STOCK_TYPE = "<?= e($stockType) ?>";
  window.STOCK_LABEL = "<?= e($meta['label']) ?>";
  window.STOCK_PERSON_LABEL = "<?= e($meta['person']) ?>";
</script>
<script src="<?= BASE_URL ?>/assets/js/stock.js?v=<?= @filemtime(__DIR__ . '/../assets/js/stock.js') ?>"></script>
