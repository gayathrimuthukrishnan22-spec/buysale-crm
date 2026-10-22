<?php
/**
 * Payment Verification — website bookings (book.html) land here first with
 * their advance-payment proof still 'pending'. They stay OUT of the normal
 * Leads pipeline (see api/leads/list.php) until someone here checks the
 * bank/UPI account and marks the payment Verified — only then does the
 * registration turn into a real Lead. Gated to leads.view_all (admin/manager)
 * since that's who actually checks the account.
 */
require_once __DIR__ . '/auth.php';
requireLogin();
requirePermission('leads.view_all');

$pageTitle = 'Payment Verification';
include __DIR__ . '/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <div>
    <h4 class="fw-800 mb-0"><i class="bi bi-shield-check me-1 text-accent"></i> Payment Verification</h4>
    <div class="text-muted-sm">Website registrations wait here until their advance payment is manually confirmed — only then do they become a Lead.</div>
  </div>
</div>

<div class="card-panel mb-3">
  <div class="card-body">
    <div class="row g-2 align-items-end">
      <div class="col-md-4 col-6">
        <label class="form-label fs-13 fw-600 mb-1">Search</label>
        <input type="text" id="pvSearch" class="form-control" placeholder="Name, mobile or Lead ID">
      </div>
      <div class="col-md-3 col-6">
        <label class="form-label fs-13 fw-600 mb-1">Payment Status</label>
        <select id="pvStatus" class="form-select">
          <option value="pending">Pending Verification</option>
          <option value="rejected">Rejected</option>
          <option value="all">Pending + Rejected</option>
        </select>
      </div>
    </div>
  </div>
</div>

<div class="table-responsive-modern">
  <table class="table table-modern align-middle" id="pvTable">
    <thead>
      <tr>
        <th>Lead ID</th>
        <th>Client</th>
        <th>Requirement</th>
        <th>Amount</th>
        <th>Proof</th>
        <th>Submitted</th>
        <th>Status</th>
        <th class="text-end">Action</th>
      </tr>
    </thead>
    <tbody id="pvTableBody">
      <tr><td colspan="8" class="text-center py-4 text-muted-sm">Loading...</td></tr>
    </tbody>
  </table>
</div>
<nav class="mt-3"><ul class="pagination justify-content-end" id="pvPagination"></ul></nav>

<?php include __DIR__ . '/footer.php'; ?>
<script src="<?= BASE_URL ?>/assets/js/payment-verification.js?v=<?= @filemtime(__DIR__ . '/../assets/js/payment-verification.js') ?>"></script>
