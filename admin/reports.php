<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('reports.view');

$canRevenue = canViewRevenue();
$db = getDB();

$range = clean($_GET['range'] ?? 'month');
function dateConditionFor(string $range, string $column): string {
    return match($range) {
        'today' => "DATE($column) = CURDATE()",
        'week'  => "$column >= CURDATE() - INTERVAL 7 DAY",
        default => "$column >= CURDATE() - INTERVAL 30 DAY",
    };
}
$leadsDateCondition = dateConditionFor($range, 'l.created_at');
$propsDateCondition = dateConditionFor($range, 'created_at');

// ---- Employee performance ----
$empReport = $db->query("
  SELECT u.name, u.role,
    COUNT(l.id) AS total_leads,
    SUM(CASE WHEN l.status='deal_closed' THEN 1 ELSE 0 END) AS closed,
    SUM(CASE WHEN l.status='lost' THEN 1 ELSE 0 END) AS lost,
    SUM(CASE WHEN l.status='cancelled' THEN 1 ELSE 0 END) AS cancelled,
    SUM(CASE WHEN l.status NOT IN ('deal_closed','cancelled','lost') THEN 1 ELSE 0 END) AS active
    " . ($canRevenue ? ", SUM(CASE WHEN l.status='deal_closed' THEN l.actual_revenue ELSE 0 END) AS revenue" : "") . "
  FROM users u LEFT JOIN leads l ON l.assigned_to = u.id AND $leadsDateCondition
  WHERE u.role NOT IN ('super_admin','admin') GROUP BY u.id, u.name, u.role ORDER BY closed DESC
")->fetchAll();

// ---- Property report ----
$propReport = $db->query("
  SELECT category, status, COUNT(*) AS cnt FROM properties WHERE $propsDateCondition GROUP BY category, status
")->fetchAll();

// ---- Lead conversion funnel ----
$conversionFunnel = $db->query("
  SELECT status, COUNT(*) AS cnt FROM leads l WHERE $leadsDateCondition GROUP BY status
")->fetchAll();

// ---- Lead source report ----
$sourceReport = $db->query("
  SELECT COALESCE(NULLIF(source,''),'Unspecified') AS source, COUNT(*) AS cnt,
    SUM(CASE WHEN status='deal_closed' THEN 1 ELSE 0 END) AS closed
  FROM leads l WHERE $leadsDateCondition GROUP BY source ORDER BY cnt DESC
")->fetchAll();

// ---- Project performance ----
$projectReport = $db->query("
  SELECT p.name, p.status, p.total_units, p.sold_units, p.available_units, p.rental_units,
    COUNT(l.id) AS lead_count,
    SUM(CASE WHEN l.status='deal_closed' THEN 1 ELSE 0 END) AS closed_count
  FROM projects p LEFT JOIN leads l ON l.project_id = p.id
  GROUP BY p.id ORDER BY closed_count DESC
")->fetchAll();

// ---- Revenue / Profit / Loss summary ----
if ($canRevenue) {
    $revenueSummary = $db->query("
      SELECT
        COALESCE(SUM(CASE WHEN status='deal_closed' THEN actual_revenue ELSE 0 END),0) AS total_revenue,
        COALESCE(SUM(CASE WHEN status='deal_closed' THEN actual_profit ELSE 0 END),0) AS total_profit,
        COALESCE(SUM(CASE WHEN status IN ('cancelled','lost') THEN expected_revenue ELSE 0 END),0) AS total_loss,
        COALESCE(SUM(CASE WHEN status NOT IN ('deal_closed','cancelled','lost') THEN expected_revenue ELSE 0 END),0) AS pipeline_expected_revenue
      FROM leads l WHERE $leadsDateCondition
    ")->fetch();

    $revenueByCategory = $db->query("
      SELECT requirement,
        COALESCE(SUM(CASE WHEN status='deal_closed' THEN actual_revenue ELSE 0 END),0) AS revenue,
        COALESCE(SUM(CASE WHEN status='deal_closed' THEN actual_profit ELSE 0 END),0) AS profit
      FROM leads l WHERE $leadsDateCondition GROUP BY requirement
    ")->fetchAll();

    // ---- Commission Report ----
    // Only deals that have actually been CLOSED (status = deal_closed) show up here.
    // Commission % and amount were auto-calculated and locked in at close time
    // (see api/leads/save.php: commission_amount = base value x commission_rate / 100).
    // This list is what payouts should be based on -- leads still in the pipeline,
    // cancelled, or lost never appear here and are never payable.
    $commissionDateCondition = dateConditionFor($range, 'l.closed_at');
    $commissionReport = $db->query("
      SELECT l.lead_code, l.client_name, l.requirement,
        l.property_value, l.monthly_rent, l.commission_rate, l.commission_amount,
        l.actual_revenue, l.closed_at,
        u.name AS employee_name
      FROM leads l
      LEFT JOIN users u ON u.id = l.assigned_to
      WHERE l.status = 'deal_closed' AND $commissionDateCondition
      ORDER BY l.closed_at DESC
    ")->fetchAll();

    $commissionTotal = 0;
    foreach ($commissionReport as $cr) { $commissionTotal += (float)$cr['actual_revenue']; }

    $commissionByEmployee = $db->query("
      SELECT u.name AS employee_name,
        COUNT(l.id) AS deals_closed,
        COALESCE(SUM(l.actual_revenue),0) AS total_commission
      FROM leads l
      JOIN users u ON u.id = l.assigned_to
      WHERE l.status = 'deal_closed' AND $commissionDateCondition
      GROUP BY u.id, u.name
      ORDER BY total_commission DESC
    ")->fetchAll();
}

$pageTitle = 'Reports';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <div>
    <h4 class="fw-800 mb-0">Reports</h4>
    <div class="text-muted-sm">Employee, property, project, and revenue reports</div>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <select class="form-select" onchange="location.href='?range='+this.value">
      <option value="today" <?= $range==='today'?'selected':'' ?>>Today</option>
      <option value="week" <?= $range==='week'?'selected':'' ?>>This Week</option>
      <option value="month" <?= $range==='month'?'selected':'' ?>>This Month</option>
    </select>
    <button class="btn btn-soft" onclick="exportTableToCSV('empReportTable','employee_performance.csv')"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel</button>
    <button class="btn btn-soft" onclick="window.print()"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</button>
  </div>
</div>

<?php if ($canRevenue): ?>
<!-- Revenue / Profit / Loss summary -->
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3">
    <div class="stat-card"><div class="stat-icon mb-2" style="background:#dcfce7;color:#15803d;"><i class="bi bi-cash-stack"></i></div>
      <div class="stat-value fs-5"><?= formatMoney($revenueSummary['total_revenue']) ?></div><div class="stat-label">Total Revenue</div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card"><div class="stat-icon mb-2" style="background:#eef2ff;color:#4338ca;"><i class="bi bi-piggy-bank-fill"></i></div>
      <div class="stat-value fs-5"><?= formatMoney($revenueSummary['total_profit']) ?></div><div class="stat-label">Total Profit</div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card"><div class="stat-icon mb-2" style="background:#fee2e2;color:#b91c1c;"><i class="bi bi-graph-down"></i></div>
      <div class="stat-value fs-5"><?= formatMoney($revenueSummary['total_loss']) ?></div><div class="stat-label">Total Loss</div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card"><div class="stat-icon mb-2" style="background:#fef3c7;color:#b45309;"><i class="bi bi-hourglass-split"></i></div>
      <div class="stat-value fs-5"><?= formatMoney($revenueSummary['pipeline_expected_revenue']) ?></div><div class="stat-label">Pipeline (Expected)</div></div>
  </div>
</div>

<div class="card-panel mb-3">
  <div class="card-header">Revenue &amp; Profit by Category</div>
  <div class="card-body p-0">
    <table class="table table-modern mb-0">
      <thead><tr><th>Category</th><th>Revenue</th><th>Profit</th></tr></thead>
      <tbody>
        <?php foreach ($revenueByCategory as $r): ?>
          <tr>
            <td data-label="Category" class="text-capitalize"><?= e($r['requirement']) ?></td>
            <td data-label="Revenue"><?= formatMoney($r['revenue']) ?></td>
            <td data-label="Profit"><?= formatMoney($r['profit']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$revenueByCategory): ?><tr><td colspan="3" class="text-center text-muted-sm py-3">No data for this period.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Commission Report: closed deals only. This drives commission payouts -->
<div class="card-panel mb-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center card-header">
    <span>Commission Report <span class="text-muted-sm fw-normal">(closed deals only — payable)</span></span>
    <div class="d-flex align-items-center gap-3">
      <span class="fw-700"><?= formatMoney($commissionTotal) ?> <span class="text-muted-sm fw-normal">total payable</span></span>
      <button class="btn btn-soft btn-sm" onclick="exportTableToCSV('commissionReportTable','commission_report.csv')"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel</button>
    </div>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-modern mb-0" id="commissionReportTable">
        <thead><tr>
          <th>Lead Code</th><th>Client</th><th>Employee</th><th>Type</th>
          <th>Deal Value</th><th>Commission %</th><th>Commission Amount</th><th>Closed On</th>
        </tr></thead>
        <tbody>
          <?php foreach ($commissionReport as $r):
            $dealValue = $r['requirement'] === 'rental' ? $r['monthly_rent'] : $r['property_value'];
          ?>
          <tr>
            <td data-label="Lead Code"><?= e($r['lead_code']) ?></td>
            <td data-label="Client" class="fw-600"><?= e($r['client_name']) ?></td>
            <td data-label="Employee"><?= e($r['employee_name'] ?? 'Unassigned') ?></td>
            <td data-label="Type" class="text-capitalize"><?= e($r['requirement']) ?></td>
            <td data-label="Deal Value"><?= formatMoney($dealValue) ?></td>
            <td data-label="Commission %"><?= $r['commission_rate'] !== null ? rtrim(rtrim(number_format((float)$r['commission_rate'],2),'0'),'.').'%' : '-' ?></td>
            <td data-label="Commission Amount" class="fw-700"><?= formatMoney($r['actual_revenue']) ?></td>
            <td data-label="Closed On"><?= $r['closed_at'] ? date('d M Y', strtotime($r['closed_at'])) : '-' ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$commissionReport): ?><tr><td colspan="8" class="text-center text-muted-sm py-3">No deals closed in this period yet. Commission only shows once a lead's status is set to "Deal Closed".</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if ($commissionByEmployee): ?>
<div class="card-panel mb-3">
  <div class="card-header">Commission Payable by Employee</div>
  <div class="card-body p-0">
    <table class="table table-modern mb-0" id="commissionByEmpTable">
      <thead><tr><th>Employee</th><th>Deals Closed</th><th>Total Commission Payable</th></tr></thead>
      <tbody>
        <?php foreach ($commissionByEmployee as $r): ?>
          <tr>
            <td data-label="Employee" class="fw-600"><?= e($r['employee_name']) ?></td>
            <td data-label="Deals Closed"><?= (int)$r['deals_closed'] ?></td>
            <td data-label="Total Commission Payable" class="fw-700"><?= formatMoney($r['total_commission']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<div class="card-panel mb-3">
  <div class="card-header">Employee Performance Report</div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-modern mb-0" id="empReportTable">
        <thead><tr>
          <th>Employee</th><th>Role</th><th>Total Leads</th><th>Active</th><th>Closed</th><th>Cancelled</th><th>Lost</th><th>Conversion Rate</th>
          <?php if ($canRevenue): ?><th>Revenue</th><?php endif; ?>
        </tr></thead>
        <tbody>
          <?php foreach ($empReport as $r):
            $rate = $r['total_leads'] > 0 ? round(($r['closed'] / $r['total_leads']) * 100, 1) : 0;
          ?>
          <tr>
            <td data-label="Employee" class="fw-600"><?= e($r['name']) ?></td>
            <td data-label="Role"><?= e(roleLabel($r['role'])) ?></td>
            <td data-label="Total Leads"><?= (int)$r['total_leads'] ?></td>
            <td data-label="Active"><?= (int)$r['active'] ?></td>
            <td data-label="Closed"><?= (int)$r['closed'] ?></td>
            <td data-label="Cancelled"><?= (int)$r['cancelled'] ?></td>
            <td data-label="Lost"><?= (int)$r['lost'] ?></td>
            <td data-label="Conversion Rate"><?= $rate ?>%</td>
            <?php if ($canRevenue): ?><td data-label="Revenue"><?= formatMoney($r['revenue']) ?></td><?php endif; ?>
          </tr>
          <?php endforeach; ?>
          <?php if (!$empReport): ?><tr><td colspan="9" class="text-center text-muted-sm py-3">No team members yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-6">
    <div class="card-panel h-100">
      <div class="card-header">Property Report (by category &amp; status)</div>
      <div class="card-body p-0">
        <table class="table table-modern mb-0">
          <thead><tr><th>Category</th><th>Status</th><th>Count</th></tr></thead>
          <tbody>
            <?php foreach ($propReport as $r): ?>
              <tr><td data-label="Category" class="text-capitalize"><?= e($r['category']) ?></td><td data-label="Status" class="text-capitalize"><?= e($r['status']) ?></td><td data-label="Count"><?= (int)$r['cnt'] ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$propReport): ?><tr><td colspan="3" class="text-center text-muted-sm py-3">No data for this period.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card-panel h-100">
      <div class="card-header">Lead Conversion Funnel</div>
      <div class="card-body p-0">
        <table class="table table-modern mb-0">
          <thead><tr><th>Status</th><th>Count</th></tr></thead>
          <tbody>
            <?php foreach ($conversionFunnel as $r): ?>
              <tr><td data-label="Status"><span class="badge-status badge-<?= e($r['status']) ?>"><?= e(str_replace('_',' ',$r['status'])) ?></span></td><td data-label="Count"><?= (int)$r['cnt'] ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$conversionFunnel): ?><tr><td colspan="2" class="text-center text-muted-sm py-3">No data for this period.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card-panel h-100">
      <div class="card-header">Lead Source Report</div>
      <div class="card-body p-0">
        <table class="table table-modern mb-0">
          <thead><tr><th>Source</th><th>Leads</th><th>Closed</th></tr></thead>
          <tbody>
            <?php foreach ($sourceReport as $r): ?>
              <tr><td data-label="Source"><?= e($r['source']) ?></td><td data-label="Leads"><?= (int)$r['cnt'] ?></td><td data-label="Closed"><?= (int)$r['closed'] ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$sourceReport): ?><tr><td colspan="3" class="text-center text-muted-sm py-3">No data for this period.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card-panel h-100">
      <div class="card-header">Project Performance</div>
      <div class="card-body p-0">
        <table class="table table-modern mb-0">
          <thead><tr><th>Project</th><th>Units (Sold/Total)</th><th>Leads</th><th>Closed</th></tr></thead>
          <tbody>
            <?php foreach ($projectReport as $r): ?>
              <tr>
                <td data-label="Project"><?= e($r['name']) ?></td>
                <td data-label="Units"><?= (int)$r['sold_units'] ?> / <?= (int)$r['total_units'] ?></td>
                <td data-label="Leads"><?= (int)$r['lead_count'] ?></td>
                <td data-label="Closed"><?= (int)$r['closed_count'] ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$projectReport): ?><tr><td colspan="4" class="text-center text-muted-sm py-3">No projects added yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
<script>
function exportTableToCSV(tableId, filename) {
  const table = document.getElementById(tableId);
  let csv = [];
  for (const row of table.rows) {
    const cols = Array.from(row.cells).map(c => '"' + c.innerText.replace(/"/g,'""') + '"');
    csv.push(cols.join(','));
  }
  const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = filename;
  link.click();
}
</script>
