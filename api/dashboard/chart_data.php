<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$db = getDB();
$scopeToSelf = !userCan('leads.view_all');
$selfClause = $scopeToSelf ? ' AND assigned_to = ' . (int)currentUserId() : '';
$canRevenue = canViewRevenue();

// ---- Revenue Trend: last 6 months of actual_revenue from closed deals ----
$revenueTrend = ['labels' => [], 'revenue' => [], 'profit' => []];
if ($canRevenue) {
    $rows = $db->query("
        SELECT DATE_FORMAT(closed_at, '%Y-%m') AS ym,
               SUM(actual_revenue) AS revenue, SUM(actual_profit) AS profit
        FROM leads
        WHERE status = 'deal_closed' AND closed_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) $selfClause
        GROUP BY ym ORDER BY ym
    ")->fetchAll();
    $byMonth = array_column($rows, null, 'ym');
    for ($i = 5; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime("-$i months"));
        $label = date('M Y', strtotime("-$i months"));
        $revenueTrend['labels'][] = $label;
        $revenueTrend['revenue'][] = (float)($byMonth[$ym]['revenue'] ?? 0);
        $revenueTrend['profit'][] = (float)($byMonth[$ym]['profit'] ?? 0);
    }
}

// ---- Sell vs Buy vs Rental (lead counts by category) ----
$categorySplit = $db->query("
    SELECT requirement, COUNT(*) AS cnt FROM leads WHERE 1=1 $selfClause GROUP BY requirement
")->fetchAll();
$categoryData = ['buy' => 0, 'sell' => 0, 'rental' => 0];
foreach ($categorySplit as $row) { $categoryData[$row['requirement']] = (int)$row['cnt']; }

// ---- Lead Conversion Funnel (counts per status, in workflow order) ----
$funnelOrder = ['new','contacted','follow_up','meeting_scheduled','site_visit','negotiation','documentation','booking','deal_closed'];
$funnelRows = $db->query("SELECT status, COUNT(*) AS cnt FROM leads WHERE 1=1 $selfClause GROUP BY status")->fetchAll();
$funnelByStatus = array_column($funnelRows, 'cnt', 'status');
$funnel = ['labels' => [], 'data' => []];
foreach ($funnelOrder as $s) {
    $funnel['labels'][] = ucwords(str_replace('_', ' ', $s));
    $funnel['data'][] = (int)($funnelByStatus[$s] ?? 0);
}

// ---- Closed vs Cancelled vs Lost ----
$outcomes = ['deal_closed' => 0, 'cancelled' => 0, 'lost' => 0];
foreach ($funnelByStatus as $status => $cnt) {
    if (isset($outcomes[$status])) $outcomes[$status] = (int)$cnt;
}

// ---- Profit vs Loss (this month) ----
$profitVsLoss = ['profit' => 0, 'loss' => 0];
if ($canRevenue) {
    $profitVsLoss['profit'] = (float)($db->query("
        SELECT COALESCE(SUM(actual_profit),0) FROM leads
        WHERE status='deal_closed' AND MONTH(closed_at)=MONTH(CURDATE()) AND YEAR(closed_at)=YEAR(CURDATE()) $selfClause
    ")->fetchColumn());
    // "Loss" = expected revenue that was forfeited on cancelled/lost leads this month
    $profitVsLoss['loss'] = (float)($db->query("
        SELECT COALESCE(SUM(expected_revenue),0) FROM leads
        WHERE status IN ('cancelled','lost') AND MONTH(updated_at)=MONTH(CURDATE()) AND YEAR(updated_at)=YEAR(CURDATE()) $selfClause
    ")->fetchColumn());
}

// ---- Employee Performance (top 8 by closed deals) — only meaningful for view-all users ----
$employeePerf = ['labels' => [], 'closed' => [], 'revenue' => []];
if (userCan('leads.view_all')) {
    $rows = $db->query("
        SELECT u.name,
            SUM(CASE WHEN l.status='deal_closed' THEN 1 ELSE 0 END) AS closed,
            SUM(CASE WHEN l.status='deal_closed' THEN l.actual_revenue ELSE 0 END) AS revenue
        FROM users u LEFT JOIN leads l ON l.assigned_to = u.id
        WHERE u.role NOT IN ('super_admin','admin')
        GROUP BY u.id, u.name
        ORDER BY closed DESC LIMIT 8
    ")->fetchAll();
    foreach ($rows as $r) {
        $employeePerf['labels'][] = $r['name'];
        $employeePerf['closed'][] = (int)$r['closed'];
        $employeePerf['revenue'][] = (float)$r['revenue'];
    }
}

// ---- Lead Source Analysis ----
$sourceRows = $db->query("
    SELECT COALESCE(NULLIF(source,''),'Unspecified') AS source, COUNT(*) AS cnt
    FROM leads WHERE 1=1 $selfClause GROUP BY source ORDER BY cnt DESC LIMIT 8
")->fetchAll();
$leadSource = ['labels' => array_column($sourceRows, 'source'), 'data' => array_map('intval', array_column($sourceRows, 'cnt'))];

jsonResponse(true, [
    'revenue_trend' => $canRevenue ? $revenueTrend : null,
    'category_split' => $categoryData,
    'funnel' => $funnel,
    'outcomes' => $outcomes,
    'profit_vs_loss' => $canRevenue ? $profitVsLoss : null,
    'employee_performance' => $employeePerf,
    'lead_source' => $leadSource,
]);
