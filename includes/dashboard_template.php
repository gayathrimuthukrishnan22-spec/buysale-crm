<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/quick_actions.php';
requireLogin();

$db = getDB();
$canViewAll = userCan('leads.view_all');
$canRevenue = canViewRevenue();
$selfClause = $canViewAll ? '' : ' AND assigned_to = ' . (int)currentUserId();

// ---- Lead & deal stat widgets ----
$totalLeads     = (int)$db->query("SELECT COUNT(*) FROM leads WHERE 1=1 $selfClause")->fetchColumn();
$newLeads       = (int)$db->query("SELECT COUNT(*) FROM leads WHERE status='new' $selfClause")->fetchColumn();
$activeLeads    = (int)$db->query("SELECT COUNT(*) FROM leads WHERE status NOT IN ('deal_closed','cancelled','lost') $selfClause")->fetchColumn();
$salesLeads     = (int)$db->query("SELECT COUNT(*) FROM leads WHERE requirement='sale' $selfClause")->fetchColumn();
$buyLeads       = (int)$db->query("SELECT COUNT(*) FROM leads WHERE requirement='buy' $selfClause")->fetchColumn();
$rentalLeads    = (int)$db->query("SELECT COUNT(*) FROM leads WHERE requirement='rental' $selfClause")->fetchColumn();
$closedDeals    = (int)$db->query("SELECT COUNT(*) FROM leads WHERE status='deal_closed' $selfClause")->fetchColumn();
$cancelledDeals = (int)$db->query("SELECT COUNT(*) FROM leads WHERE status='cancelled' $selfClause")->fetchColumn();
$lostDeals      = (int)$db->query("SELECT COUNT(*) FROM leads WHERE status='lost' $selfClause")->fetchColumn();
$conversionRate = $totalLeads > 0 ? round(($closedDeals / $totalLeads) * 100, 1) : 0;

// ---- Meetings / follow-ups ----
$fuSelf = $canViewAll ? '' : ' AND l.assigned_to = ' . (int)currentUserId();
$todayFollowups = (int)$db->query("
    SELECT COUNT(*) FROM followups f JOIN leads l ON l.id=f.lead_id
    WHERE DATE(f.scheduled_at)=CURDATE() AND f.outcome='pending' $fuSelf
")->fetchColumn();
$todayMeetings = (int)$db->query("
    SELECT COUNT(*) FROM followups f JOIN leads l ON l.id=f.lead_id
    WHERE DATE(f.scheduled_at)=CURDATE() AND f.meeting_type IN ('site_visit','office_meeting') AND f.outcome='pending' $fuSelf
")->fetchColumn();
$upcomingMeetings = (int)$db->query("
    SELECT COUNT(*) FROM followups f JOIN leads l ON l.id=f.lead_id
    WHERE f.scheduled_at > NOW() AND f.outcome='pending' $fuSelf
")->fetchColumn();
$missedMeetings = (int)$db->query("
    SELECT COUNT(*) FROM followups f JOIN leads l ON l.id=f.lead_id
    WHERE f.scheduled_at < NOW() AND f.outcome='pending' $fuSelf
")->fetchColumn();

// ---- Revenue / profit (permission gated) ----
if ($canRevenue) {
    $totalRevenue = (float)$db->query("SELECT COALESCE(SUM(actual_revenue),0) FROM leads WHERE status='deal_closed' $selfClause")->fetchColumn();
    $expectedRevenue = (float)$db->query("SELECT COALESCE(SUM(expected_revenue),0) FROM leads WHERE status NOT IN ('deal_closed','cancelled','lost') $selfClause")->fetchColumn();
    $totalProfit = (float)$db->query("SELECT COALESCE(SUM(actual_profit),0) FROM leads WHERE status='deal_closed' $selfClause")->fetchColumn();
    $expectedProfit = (float)$db->query("SELECT COALESCE(SUM(expected_profit),0) FROM leads WHERE status NOT IN ('deal_closed','cancelled','lost') $selfClause")->fetchColumn();
    $totalLoss = (float)$db->query("SELECT COALESCE(SUM(expected_revenue),0) FROM leads WHERE status IN ('cancelled','lost') $selfClause")->fetchColumn();
}

$missedFollowupList = $db->query("
  SELECT f.*, l.client_name, l.client_mobile, l.requirement, l.lead_code, u.name AS assigned_name
  FROM followups f
  JOIN leads l ON l.id = f.lead_id
  LEFT JOIN users u ON u.id = l.assigned_to
  WHERE f.scheduled_at < NOW() AND f.outcome = 'pending' $fuSelf
  ORDER BY f.scheduled_at ASC LIMIT 5
")->fetchAll();

$tomorrowFollowupList = $db->query("
  SELECT f.*, l.client_name, l.client_mobile, l.requirement, l.lead_code, u.name AS assigned_name
  FROM followups f
  JOIN leads l ON l.id = f.lead_id
  LEFT JOIN users u ON u.id = l.assigned_to
  WHERE DATE(f.scheduled_at) = DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND f.outcome = 'pending' $fuSelf
  ORDER BY f.scheduled_at ASC LIMIT 5
")->fetchAll();
$tomorrowFollowups = (int)$db->query("
    SELECT COUNT(*) FROM followups f JOIN leads l ON l.id=f.lead_id
    WHERE DATE(f.scheduled_at) = DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND f.outcome='pending' $fuSelf
")->fetchColumn();

$todaysFollowupList = $db->query("
  SELECT f.*, l.client_name, l.client_mobile, l.requirement, u.name AS assigned_name
  FROM followups f
  JOIN leads l ON l.id = f.lead_id
  LEFT JOIN users u ON u.id = l.assigned_to
  WHERE DATE(f.scheduled_at) = CURDATE() AND f.outcome = 'pending' $fuSelf
  ORDER BY f.scheduled_at ASC LIMIT 8
")->fetchAll();

$recentActivity = $db->query("
  SELECT a.*, u.name AS performed_by_name
  FROM activity_log a LEFT JOIN users u ON u.id = a.performed_by
  ORDER BY a.created_at DESC LIMIT 10
")->fetchAll();

$pageTitle = 'Dashboard';
include __DIR__ . '/../includes/header.php';
?>

<h4 class="fw-800 mb-1">Welcome back, <?= e($_SESSION['name']) ?> 👋</h4>
<div class="text-muted-sm mb-4">Here's what's happening across your CRM today.</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card"><div class="stat-icon mb-2" style="background:#eef2ff;color:#4338ca;"><i class="bi bi-person-lines-fill"></i></div>
      <div class="stat-value"><?= $totalLeads ?></div><div class="stat-label">Total Leads</div></div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card"><div class="stat-icon mb-2" style="background:#e0f2fe;color:#0369a1;"><i class="bi bi-stars"></i></div>
      <div class="stat-value"><?= $newLeads ?></div><div class="stat-label">New Leads</div></div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card"><div class="stat-icon mb-2" style="background:#fce7f3;color:#be185d;"><i class="bi bi-activity"></i></div>
      <div class="stat-value"><?= $activeLeads ?></div><div class="stat-label">Active Leads</div></div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card"><div class="stat-icon mb-2" style="background:#fef3c7;color:#b45309;"><i class="bi bi-tag-fill"></i></div>
      <div class="stat-value"><?= $salesLeads ?></div><div class="stat-label">Sales Leads</div></div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card"><div class="stat-icon mb-2" style="background:#ecfdf5;color:#16a34a;"><i class="bi bi-cash-coin"></i></div>
      <div class="stat-value"><?= $buyLeads ?></div><div class="stat-label">Buy Leads</div></div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card"><div class="stat-icon mb-2" style="background:#dbeafe;color:#1d4ed8;"><i class="bi bi-key-fill"></i></div>
      <div class="stat-value"><?= $rentalLeads ?></div><div class="stat-label">Rental Leads</div></div>
  </div>

  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card"><div class="stat-icon mb-2" style="background:#dcfce7;color:#15803d;"><i class="bi bi-check-circle-fill"></i></div>
      <div class="stat-value"><?= $closedDeals ?></div><div class="stat-label">Closed Deals</div></div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card"><div class="stat-icon mb-2" style="background:#f3f4f6;color:#4b5563;"><i class="bi bi-x-circle"></i></div>
      <div class="stat-value"><?= $cancelledDeals ?></div><div class="stat-label">Cancelled</div></div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card"><div class="stat-icon mb-2" style="background:#fee2e2;color:#b91c1c;"><i class="bi bi-emoji-frown"></i></div>
      <div class="stat-value"><?= $lostDeals ?></div><div class="stat-label">Lost Deals</div></div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card"><div class="stat-icon mb-2" style="background:#ede9fe;color:#6d28d9;"><i class="bi bi-graph-up-arrow"></i></div>
      <div class="stat-value"><?= $conversionRate ?>%</div><div class="stat-label">Conversion Rate</div></div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card"><div class="stat-icon mb-2" style="background:#fef9c3;color:#a16207;"><i class="bi bi-calendar-check-fill"></i></div>
      <div class="stat-value"><?= $todayFollowups ?></div><div class="stat-label">Today's Follow-ups</div></div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card"><div class="stat-icon mb-2" style="background:#fae8ff;color:#a21caf;"><i class="bi bi-camera-video-fill"></i></div>
      <div class="stat-value"><?= $todayMeetings ?></div><div class="stat-label">Today's Meetings</div></div>
  </div>
</div>

<?php if ($canRevenue): ?>
<div class="row g-3 mb-4">
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card"><div class="stat-icon mb-2" style="background:#dcfce7;color:#15803d;"><i class="bi bi-cash-stack"></i></div>
      <div class="stat-value fs-6"><?= formatMoney($totalRevenue) ?></div><div class="stat-label">Total Revenue</div></div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card"><div class="stat-icon mb-2" style="background:#e0f2fe;color:#0369a1;"><i class="bi bi-hourglass-split"></i></div>
      <div class="stat-value fs-6"><?= formatMoney($expectedRevenue) ?></div><div class="stat-label">Expected Revenue</div></div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card"><div class="stat-icon mb-2" style="background:#eef2ff;color:#4338ca;"><i class="bi bi-piggy-bank-fill"></i></div>
      <div class="stat-value fs-6"><?= formatMoney($totalProfit) ?></div><div class="stat-label">Total Profit</div></div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card"><div class="stat-icon mb-2" style="background:#fef3c7;color:#b45309;"><i class="bi bi-graph-up"></i></div>
      <div class="stat-value fs-6"><?= formatMoney($expectedProfit) ?></div><div class="stat-label">Expected Profit</div></div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card"><div class="stat-icon mb-2" style="background:#fee2e2;color:#b91c1c;"><i class="bi bi-graph-down"></i></div>
      <div class="stat-value fs-6"><?= formatMoney($totalLoss) ?></div><div class="stat-label">Total Loss</div></div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card"><div class="stat-icon mb-2" style="background:#fee2e2;color:#b91c1c;"><i class="bi bi-exclamation-triangle-fill"></i></div>
      <div class="stat-value"><?= $missedMeetings ?></div><div class="stat-label">Missed Meetings</div></div>
  </div>
</div>
<?php endif; ?>

<?php if ($missedMeetings > 0 || $tomorrowFollowups > 0): ?>
<div class="card-panel mb-4">
  <div class="card-header d-flex align-items-center gap-2">
    <i class="bi bi-bell-fill text-accent"></i> Reminders
  </div>
  <div class="card-body">
    <ul class="list-unstyled mb-0">

      <?php if ($missedMeetings > 0): ?>
        <li class="d-flex gap-2 <?= $tomorrowFollowups > 0 ? 'mb-3 pb-3 border-bottom' : '' ?>">
          <div class="stat-icon flex-shrink-0" style="width:34px;height:34px;background:#fee2e2;color:#b91c1c;font-size:.9rem;">
            <i class="bi bi-exclamation-triangle-fill"></i>
          </div>
          <div class="flex-grow-1">
            <div class="fs-13">
              <strong class="text-danger">You've missed <?= $missedMeetings ?> follow-up<?= $missedMeetings > 1 ? 's' : '' ?> / meeting<?= $missedMeetings > 1 ? 's' : '' ?>!</strong>
            </div>
            <div class="mt-2">
              <?php foreach ($missedFollowupList as $mf): ?>
                <div class="fs-13 mb-1">
                  <strong><?= e($mf['client_name']) ?></strong> <span class="text-muted-sm">(<?= e($mf['lead_code']) ?>)</span>
                  — <?= date('d M, h:i A', strtotime($mf['scheduled_at'])) ?>
                  <?php if ($canViewAll): ?><span class="text-muted-sm">· <?= e($mf['assigned_name'] ?? 'Unassigned') ?></span><?php endif; ?>
                </div>
              <?php endforeach; ?>
              <?php if ($missedMeetings > count($missedFollowupList)): ?>
                <div class="text-muted-sm fs-13">+ <?= $missedMeetings - count($missedFollowupList) ?> more</div>
              <?php endif; ?>
            </div>
          </div>
          <a href="<?= BASE_URL ?>/<?= usesAdminLayout() ? 'admin' : 'employee' ?>/followups.php" class="btn btn-sm btn-danger flex-shrink-0 align-self-start">Review Now</a>
        </li>
      <?php endif; ?>

      <?php if ($tomorrowFollowups > 0): ?>
        <li class="d-flex gap-2">
          <div class="stat-icon flex-shrink-0" style="width:34px;height:34px;background:#fef3c7;color:#b45309;font-size:.9rem;">
            <i class="bi bi-calendar-event-fill"></i>
          </div>
          <div class="flex-grow-1">
            <div class="fs-13">
              <strong>You have <?= $tomorrowFollowups ?> follow-up<?= $tomorrowFollowups > 1 ? 's' : '' ?> / meeting<?= $tomorrowFollowups > 1 ? 's' : '' ?> tomorrow.</strong>
            </div>
            <div class="mt-2">
              <?php foreach ($tomorrowFollowupList as $tf): ?>
                <div class="fs-13 mb-1">
                  <strong><?= e($tf['client_name']) ?></strong> <span class="text-muted-sm">(<?= e($tf['lead_code']) ?>)</span>
                  — <?= date('d M, h:i A', strtotime($tf['scheduled_at'])) ?>
                  <?php if ($canViewAll): ?><span class="text-muted-sm">· <?= e($tf['assigned_name'] ?? 'Unassigned') ?></span><?php endif; ?>
                </div>
              <?php endforeach; ?>
              <?php if ($tomorrowFollowups > count($tomorrowFollowupList)): ?>
                <div class="text-muted-sm fs-13">+ <?= $tomorrowFollowups - count($tomorrowFollowupList) ?> more</div>
              <?php endif; ?>
            </div>
          </div>
          <a href="<?= BASE_URL ?>/<?= usesAdminLayout() ? 'admin' : 'employee' ?>/followups.php" class="btn btn-sm btn-soft flex-shrink-0 align-self-start">View</a>
        </li>
      <?php endif; ?>

    </ul>
  </div>
</div>
<?php endif; ?>

<!-- Charts -->
<div class="row g-3 mb-4">
  <?php if ($canRevenue): ?>
  <div class="col-lg-8">
    <div class="card-panel h-100">
      <div class="card-header">Revenue &amp; Profit Trend (Last 6 Months)</div>
      <div class="card-body"><canvas id="chartRevenueTrend" height="90"></canvas></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card-panel h-100">
      <div class="card-header">Sales vs Buy vs Rental</div>
      <div class="card-body"><canvas id="chartCategorySplit" height="90"></canvas></div>
    </div>
  </div>
  <?php else: ?>
  <div class="col-lg-6">
    <div class="card-panel h-100">
      <div class="card-header">Sales vs Buy vs Rental</div>
      <div class="card-body"><canvas id="chartCategorySplit" height="90"></canvas></div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card-panel h-100">
      <div class="card-header">Lead Source Analysis</div>
      <div class="card-body"><canvas id="chartLeadSource" height="90"></canvas></div>
    </div>
  </div>
  <?php endif; ?>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-6">
    <div class="card-panel h-100">
      <div class="card-header">Lead Conversion Funnel</div>
      <div class="card-body"><canvas id="chartFunnel" height="110"></canvas></div>
    </div>
  </div>
  <div class="col-lg-3 col-md-6">
    <div class="card-panel h-100">
      <div class="card-header">Closed vs Cancelled vs Lost</div>
      <div class="card-body"><canvas id="chartOutcomes" height="110"></canvas></div>
    </div>
  </div>
  <div class="col-lg-3 col-md-6">
    <?php if ($canRevenue): ?>
    <div class="card-panel h-100">
      <div class="card-header">Profit vs Loss (This Month)</div>
      <div class="card-body"><canvas id="chartProfitLoss" height="110"></canvas></div>
    </div>
    <?php else: ?>
    <div class="card-panel h-100">
      <div class="card-header">Lead Source Analysis</div>
      <div class="card-body"><canvas id="chartLeadSource2" height="110"></canvas></div>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="row g-3">
  <!-- Today's Follow-ups with Call/WhatsApp -->
  <div class="col-lg-7">
    <div class="card-panel mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        Today's Follow-ups
        <a href="<?= BASE_URL ?>/<?= usesAdminLayout() ? 'admin' : 'employee' ?>/followups.php" class="btn btn-sm btn-soft">View All</a>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-modern mb-0">
            <thead><tr><th>Client</th><th>Requirement</th><th>Time</th><th>Employee</th><th class="text-center">Contact</th></tr></thead>
            <tbody>
            <?php if (!$todaysFollowupList): ?>
              <tr><td colspan="5" class="text-center text-muted-sm py-4">No follow-ups scheduled for today 🎉</td></tr>
            <?php else: foreach ($todaysFollowupList as $f): ?>
              <tr>
                <td data-label="Client">
                  <div class="client-name"><?= e($f['client_name']) ?></div>
                  <div class="client-mobile"><?= e($f['client_mobile']) ?></div>
                </td>
                <td data-label="Requirement" class="text-capitalize"><?= e($f['requirement']) ?></td>
                <td data-label="Time"><?= date('h:i A', strtotime($f['scheduled_at'])) ?></td>
                <td data-label="Employee"><?= e($f['assigned_name'] ?? '-') ?></td>
                <td data-label="Contact" class="text-center"><?php renderQuickActions($f['client_mobile'], $f['client_name'], $f['requirement']); ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Recent Activity -->
  <div class="col-lg-5">
    <div class="card-panel">
      <div class="card-header">Recent Activities</div>
      <div class="card-body">
        <ul class="list-unstyled mb-0">
          <?php foreach ($recentActivity as $a): ?>
            <li class="d-flex gap-2 mb-3 pb-3 border-bottom">
              <div class="stat-icon flex-shrink-0" style="width:34px;height:34px;background:#eef2ff;color:#4338ca;font-size:.9rem;">
                <i class="bi bi-activity"></i>
              </div>
              <div>
                <div class="fs-13"><strong><?= e($a['performed_by_name'] ?? 'System') ?></strong> <?= e(str_replace('_',' ', $a['action'])) ?> a <?= e($a['entity_type']) ?></div>
                <div class="text-muted-sm"><?= timeAgo($a['created_at']) ?></div>
              </div>
            </li>
          <?php endforeach; ?>
          <?php if (!$recentActivity): ?>
            <li class="text-muted-sm text-center py-3">No recent activity yet.</li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>window.CAN_VIEW_REVENUE_DASH = <?= $canRevenue ? 'true' : 'false' ?>;</script>
<script src="<?= BASE_URL ?>/assets/js/dashboard-charts.js?v=<?= @filemtime(__DIR__ . '/../assets/js/dashboard-charts.js') ?>"></script>
