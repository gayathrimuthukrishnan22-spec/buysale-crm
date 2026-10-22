<?php
require_once __DIR__ . '/../../includes/auth.php';
requirePermission('leads.view');

$db = getDB();

$search     = clean($_GET['search'] ?? '');
$status     = clean($_GET['status'] ?? '');
$requirement= clean($_GET['requirement'] ?? '');
$employee   = clean($_GET['employee'] ?? '');
$date       = clean($_GET['date'] ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 15;
$offset     = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

// Users without "view all" (e.g. sales_executive, telecaller, employee) only see their own assigned leads
if (!userCan('leads.view_all')) {
    $where[] = 'l.assigned_to = ?';
    $params[] = currentUserId();
}

if ($search !== '') {
    $where[] = '(l.client_name LIKE ? OR l.client_mobile LIKE ? OR l.lead_code LIKE ?)';
    $like = "%$search%";
    array_push($params, $like, $like, $like);
}
if ($status !== '') { $where[] = 'l.status = ?'; $params[] = $status; }
if ($requirement !== '') { $where[] = 'l.requirement = ?'; $params[] = $requirement; }
if ($employee !== '' && userCan('leads.view_all')) { $where[] = 'l.assigned_to = ?'; $params[] = $employee; }
if ($date !== '') { $where[] = 'DATE(l.created_at) = ?'; $params[] = $date; }

// Website bookings (book.html) come in with a 'pending' advance-payment proof.
// Those must NOT show up in the normal leads pipeline until an admin/manager
// has manually checked the payment came in (see api/leads/verify-payment.php
// and the Payment Verification page). Leads created directly inside the CRM
// have no lead_payments row at all, so they're unaffected by this filter.
$where[] = "NOT EXISTS (
    SELECT 1 FROM lead_payments lp
    WHERE lp.lead_id = l.id
    AND lp.status <> 'verified'
    AND lp.paid_at = (SELECT MAX(lp2.paid_at) FROM lead_payments lp2 WHERE lp2.lead_id = l.id)
)";

$whereSql = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM leads l WHERE $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$sql = "SELECT l.*, u.name AS assigned_name
        FROM leads l
        LEFT JOIN users u ON u.id = l.assigned_to
        WHERE $whereSql
        ORDER BY l.created_at DESC
        LIMIT $perPage OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Strip sensitive financial fields for users without revenue visibility
if (!canViewRevenue()) {
    $financialFields = ['property_value','commission_rate','commission_amount','expected_revenue',
        'actual_revenue','expected_profit','actual_profit','employee_incentive'];
    foreach ($rows as &$row) {
        foreach ($financialFields as $f) unset($row[$f]);
    }
    unset($row);
}

jsonResponse(true, [
    'rows' => $rows,
    'total' => $total,
    'page' => $page,
    'per_page' => $perPage,
    'total_pages' => (int)ceil($total / $perPage),
]);
