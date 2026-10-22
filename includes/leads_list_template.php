<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/quick_actions.php';
requireLogin();
requirePermission('leads.view');

$db = getDB();
$employees = $db->query("SELECT id, name FROM users WHERE role NOT IN ('super_admin') AND status='active' ORDER BY name")->fetchAll();
try {
    $projects = $db->query("SELECT id, name, category FROM projects WHERE status != 'completed' ORDER BY name")->fetchAll();
} catch (PDOException $e) {
    // 'category' column missing = migration_v8_project_category.sql hasn't been run yet.
    // Fall back so the page still loads; every project is treated as 'general' (shows for every requirement category).
    $projects = $db->query("SELECT id, name FROM projects WHERE status != 'completed' ORDER BY name")->fetchAll();
    foreach ($projects as &$p) { $p['category'] = 'general'; }
    unset($p);
}
$canViewAll = userCan('leads.view_all');
$canAssign = userCan('leads.assign');
$canCloseDeals = userCan('deals.close');
$canViewRevenue = canViewRevenue();

$pageTitle = 'Leads';
include __DIR__ . '/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <div>
    <h4 class="fw-800 mb-0">Leads Management</h4>
    <div class="text-muted-sm">All buy / sale / rental leads across employees</div>
  </div>
  <button class="btn btn-accent" data-bs-toggle="modal" data-bs-target="#leadModal" onclick="openLeadModal()">
    <i class="bi bi-plus-lg me-1"></i> Add Lead
  </button>
</div>

<!-- Filters -->
<div class="card-panel mb-3">
  <div class="card-body">
    <div class="row g-2 align-items-end">
      <div class="col-md-3 col-6">
        <label class="form-label fs-13 fw-600 mb-1">Search</label>
        <input type="text" id="fSearch" class="form-control" placeholder="Name or mobile number">
      </div>
      <div class="col-md-2 col-6">
        <label class="form-label fs-13 fw-600 mb-1">Status</label>
        <select id="fStatus" class="form-select">
          <option value="">All Status</option>
          <option value="new">New</option>
          <option value="contacted">Contacted</option>
          <option value="follow_up">Follow-up</option>
          <option value="meeting_scheduled">Meeting Scheduled</option>
          <option value="site_visit">Site Visit</option>
          <option value="negotiation">Negotiation</option>
          <option value="documentation">Documentation</option>
          <option value="booking">Booking</option>
          <option value="deal_closed">Deal Closed</option>
          <option value="cancelled">Cancelled</option>
          <option value="lost">Lost</option>
        </select>
      </div>
      <div class="col-md-2 col-6">
        <label class="form-label fs-13 fw-600 mb-1">Requirement</label>
        <select id="fRequirement" class="form-select">
          <option value="">Buy / Sale / Rental</option>
          <option value="buy">Buy</option>
          <option value="sale">Sale</option>
          <option value="rental">Rental</option>
          <option value="lease">Lease</option>
        </select>
      </div>
      <?php if ($canViewAll): ?>
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

<!-- Leads Table -->
<div class="table-responsive-modern">
  <table class="table table-modern align-middle" id="leadsTable">
    <thead>
      <tr>
        <th>Lead ID</th>
        <th>Client</th>
        <th>Location</th>
        <th>Requirement</th>
        <th>Budget</th>
        <?php if ($canViewRevenue): ?><th>Revenue</th><?php endif; ?>
        <th>Assigned To</th>
        <th>Status</th>
        <th>Next Follow-up</th>
        <th>Created</th>
        <th class="text-center">Details</th>
        <th class="text-center">Quick Actions</th>
        <th class="text-end">Manage</th>
      </tr>
    </thead>
    <tbody id="leadsTableBody">
      <tr><td colspan="12" class="text-center py-4 text-muted-sm">Loading leads...</td></tr>
    </tbody>
  </table>
</div>
<nav class="mt-3"><ul class="pagination justify-content-end" id="leadsPagination"></ul></nav>

<!-- Lead Add/Edit Modal -->
<div class="modal fade" id="leadModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form id="leadForm">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="id" id="leadId">
        <div class="modal-header">
          <h5 class="modal-title fw-700" id="leadModalTitle">Add Lead</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">

          <div class="form-section-label">Contact Details</div>
          <div class="row g-3 mb-2">
            <div class="col-md-6">
              <label class="form-label fw-600 fs-13">Client Name *</label>
              <input type="text" name="client_name" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600 fs-13">Mobile Number *</label>
              <input type="text" name="client_mobile" class="form-control" autocomplete="off" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600 fs-13">WhatsApp Number</label>
              <input type="text" name="whatsapp_number" class="form-control" autocomplete="off" placeholder="Leave blank to use mobile number">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600 fs-13">Email</label>
              <input type="email" name="client_email" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600 fs-13">Address</label>
              <input type="text" name="address" class="form-control">
            </div>
            <div class="col-md-2">
              <label class="form-label fw-600 fs-13">City</label>
              <input type="text" name="city" class="form-control">
            </div>
            <div class="col-md-2">
              <label class="form-label fw-600 fs-13">State</label>
              <input type="text" name="state" class="form-control">
            </div>
            <div class="col-md-2">
              <label class="form-label fw-600 fs-13">Country</label>
              <input type="text" name="country" class="form-control" value="India">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600 fs-13">Requirement Category</label>
              <select name="customer_type" id="leadCustomerType" class="form-select">
                <option value="house_rent_requirement">House Rent Requirement</option>
                <option value="commercial_space_requirement">Commercial Space Requirement</option>
                <option value="flat_lease">Flat Lease</option>
                <option value="flat_selling">Flat Selling</option>
                <option value="land_selling">Land Selling</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600 fs-13">Lead Source</label>
              <input type="text" name="source" class="form-control" placeholder="Website, Referral, Walk-in, Portal...">
            </div>
          </div>

          <div class="form-section-label">Requirement</div>
          <div class="row g-3 mb-2">
            <div class="col-md-4">
              <label class="form-label fw-600 fs-13">Requirement *</label>
              <select name="requirement" id="leadRequirement" class="form-select" required>
                <option value="buy">Buy</option>
                <option value="sale">Sale</option>
                <option value="rental">Rental</option>
                <option value="lease">Lease</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-600 fs-13">Property Type</label>
              <div id="ptDefaultGroup">
                <input type="text" name="property_type" id="propertyTypeInput" class="form-control" placeholder="Apartment, Villa, Plot...">
              </div>
              <div id="ptBhkGroup" class="d-none">
                <select id="bhkSelect" class="form-select">
                  <option value="">Select BHK</option>
                  <option value="1 RK">1 RK</option>
                  <option value="1 BHK">1 BHK</option>
                  <option value="2 BHK">2 BHK</option>
                  <option value="3 BHK">3 BHK</option>
                  <option value="4 BHK">4 BHK</option>
                  <option value="4+ BHK">4+ BHK</option>
                </select>
              </div>
              <div id="ptSqftGroup" class="d-none">
                <div class="input-group">
                  <input type="number" id="sqftInput" class="form-control" placeholder="e.g. 1200" min="0">
                  <span class="input-group-text">sqft</span>
                </div>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-600 fs-13">Budget (₹)</label>
              <input type="number" name="budget" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600 fs-13">Preferred Location</label>
              <input type="text" name="preferred_location" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600 fs-13">Project</label>
              <select name="project_id" id="leadProjectSelect" class="form-select">
                <option value="">— None —</option>
                <?php foreach ($projects as $p): ?>
                  <option value="<?= $p['id'] ?>" data-category="<?= e($p['category'] ?: 'general') ?>"><?= e($p['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-section-label">Assignment &amp; Status</div>
          <div class="row g-3 mb-2">
            <?php if ($canAssign): ?>
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
              <select name="status" id="leadStatus" class="form-select">
                <option value="new">New</option>
                <option value="contacted">Contacted</option>
                <option value="follow_up">Follow-up</option>
                <option value="meeting_scheduled">Meeting Scheduled</option>
                <option value="site_visit">Site Visit</option>
                <option value="negotiation">Negotiation</option>
                <option value="documentation">Documentation</option>
                <option value="booking">Booking</option>
                <?php if ($canCloseDeals): ?><option value="deal_closed">Deal Closed</option><?php endif; ?>
                <option value="cancelled">Cancelled</option>
                <option value="lost">Lost</option>
              </select>
            </div>
          </div>

          <?php if ($canCloseDeals): ?>
          <!-- Deal Closure & Revenue section - shown when status = Deal Closed -->
          <div id="dealClosureSection" class="deal-section d-none">
            <div class="form-section-label text-success"><i class="bi bi-cash-stack me-1"></i>Deal Closure &amp; Revenue</div>
            <div class="row g-3 mb-2">
              <div class="col-md-4" id="propertyValueField">
                <label class="form-label fw-600 fs-13">Property Value (₹)</label>
                <input type="number" name="property_value" id="propertyValue" class="form-control">
              </div>
              <div class="col-md-4">
                <label class="form-label fw-600 fs-13">Commission Rate (%)</label>
                <input type="number" step="0.01" name="commission_rate" id="commissionRate" class="form-control" <?= !isAdmin() ? 'readonly title="Only Admin can set the commission %"' : '' ?>>
                <?php if (!isAdmin()): ?><div class="form-text">Only Admin can set this.</div><?php endif; ?>
              </div>
              <div class="col-md-4">
                <label class="form-label fw-600 fs-13">Employee Incentive (₹)</label>
                <input type="number" name="employee_incentive" id="employeeIncentive" class="form-control">
              </div>
              <!-- Rental-only fields -->
              <div class="col-md-4 rental-only d-none">
                <label class="form-label fw-600 fs-13">Monthly Rent (₹)</label>
                <input type="number" name="monthly_rent" id="monthlyRent" class="form-control">
              </div>
              <div class="col-md-4 rental-only d-none">
                <label class="form-label fw-600 fs-13">Security Deposit (₹)</label>
                <input type="number" name="security_deposit" class="form-control">
              </div>
              <div class="col-md-4 rental-only d-none">
                <label class="form-label fw-600 fs-13">Lease Duration (months)</label>
                <input type="number" name="lease_duration_months" class="form-control">
              </div>
              <div class="col-md-6 rental-only d-none">
                <label class="form-label fw-600 fs-13">Owner Name</label>
                <input type="text" name="owner_name" class="form-control">
              </div>
              <div class="col-md-6 rental-only d-none">
                <label class="form-label fw-600 fs-13">Owner Mobile</label>
                <input type="text" name="owner_mobile" class="form-control" autocomplete="off">
              </div>
              <div class="col-12">
                <div class="revenue-preview">
                  <div><span>Commission Amount</span><strong id="previewCommission">₹0</strong></div>
                  <div><span>Expected Revenue</span><strong id="previewRevenue">₹0</strong></div>
                  <div><span>Expected Profit</span><strong id="previewProfit">₹0</strong></div>
                </div>
                <div class="form-text">Revenue = Commission (Property/Rent Value × Commission %). Profit = Revenue − Employee Incentive. Final figures are recalculated and locked in when you save.</div>
              </div>
            </div>
          </div>
          <?php endif; ?>

          <!-- Cancelled / Lost Reason section -->
          <div id="cancelledReasonSection" class="deal-section d-none">
            <div class="form-section-label text-danger"><i class="bi bi-x-octagon me-1"></i>Cancellation / Loss Reason</div>
            <div class="row g-3 mb-2">
              <div class="col-md-6">
                <label class="form-label fw-600 fs-13">Reason *</label>
                <select name="cancelled_reason" id="cancelledReason" class="form-select">
                  <option value="">Select a reason...</option>
                  <option value="customer_rejected">Customer Rejected</option>
                  <option value="budget_issue">Budget Issue</option>
                  <option value="competitor_won">Competitor Won</option>
                  <option value="property_not_available">Property Not Available</option>
                  <option value="loan_rejected">Loan Rejected</option>
                  <option value="employee_delay">Employee Delay</option>
                  <option value="no_response">No Response</option>
                  <option value="price_issue">Price Issue</option>
                  <option value="documentation_issue">Documentation Issue</option>
                  <option value="other">Other</option>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-600 fs-13">Additional Notes</label>
                <input type="text" name="cancelled_reason_notes" class="form-control">
              </div>
            </div>
          </div>

          <div class="form-section-label">Internal Notes</div>
          <textarea name="notes" class="form-control" rows="2"></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-accent">Save Lead</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Lead Detail Modal (Personal / Identity / Requirement / Documents / Payment) -->
<div class="modal fade" id="leadDetailModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-700">Registration Detail <span id="ldRef" class="text-muted-sm fw-400"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="lead-step-dots" id="ldDots">
          <div class="lead-step-dot active" data-step="0"><span class="dot">1</span><span class="lbl">Personal Details</span></div>
          <div class="lead-step-dot" data-step="1"><span class="dot">2</span><span class="lbl">Identity &amp; Address</span></div>
          <div class="lead-step-dot" data-step="2"><span class="dot">3</span><span class="lbl">Property Requirement</span></div>
          <div class="lead-step-dot" data-step="3"><span class="dot">4</span><span class="lbl">Document</span></div>
          <div class="lead-step-dot" data-step="4"><span class="dot">5</span><span class="lbl">Payment</span></div>
        </div>
        <hr>
        <div id="ldStepBody" class="lead-step-body"></div>
      </div>
      <div class="modal-footer justify-content-between">
        <button type="button" class="btn btn-light" id="ldBackBtn"><i class="bi bi-arrow-left me-1"></i>Back</button>
        <span class="text-muted-sm" id="ldStepCount">1 / 5</span>
        <button type="button" class="btn btn-accent" id="ldNextBtn">Next<i class="bi bi-arrow-right ms-1"></i></button>
      </div>
    </div>
  </div>
</div>

<!-- Find Matching Stock Modal (shows "Our Stock" properties that fit this lead's requirement) -->
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
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/lead-detail.css">
<script>
  window.SUBSCRIPTION_AMOUNT = <?= (int)SUBSCRIPTION_AMOUNT ?>;
  window.SUBSCRIPTION_MATCH_LIMIT = <?= (int)SUBSCRIPTION_MATCH_LIMIT ?>;
</script>
<script src="<?= BASE_URL ?>/assets/js/leads.js?v=<?= @filemtime(__DIR__ . '/../assets/js/leads.js') ?>"></script>
<script src="<?= BASE_URL ?>/assets/js/lead-detail.js?v=<?= @filemtime(__DIR__ . '/../assets/js/lead-detail.js') ?>"></script>


