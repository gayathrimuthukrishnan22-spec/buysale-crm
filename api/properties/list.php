<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$db = getDB();
$category = clean($_GET['category'] ?? '');
if (!in_array($category, ['buy','sale','rental','lease','rental_tenant'], true)) {
    jsonResponse(false, null, 'Invalid category.', 422);
}

$search   = clean($_GET['search'] ?? '');
$status   = clean($_GET['status'] ?? '');
$employee = clean($_GET['employee'] ?? '');
$date     = clean($_GET['date'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 15;
$offset   = ($page - 1) * $perPage;

// Dead leads (subscription used up) have their own dedicated "Dead Leads"
// menu now — once a lead goes dead it shifts there and stops appearing on
// its category page. It only comes back here automatically after being
// renewed on the Dead Leads page, at which point status is 'active' again
// and this exclusion no longer applies to it.
//
// 'payment_verification' rows are website bookings (see
// api/public/register.php) whose advance payment hasn't been checked yet —
// they live on the Payment Verification page instead and only appear here
// once verify-payment.php flips them to 'active' (a reject deletes them
// instead). This is separate from the ordinary user-selectable 'pending'
// business status, which stays visible here as before.
$where = ['p.category = ?', 'p.is_stock = 0', "p.status != 'dead'", "p.status != 'payment_verification'"];
$params = [$category];

if (!userCan('properties.manage')) { $where[] = 'p.assigned_to = ?'; $params[] = currentUserId(); }
if ($search !== '') {
    $where[] = '(p.client_name LIKE ? OR p.client_mobile LIKE ? OR p.property_code LIKE ? OR p.address LIKE ?)';
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like);
}
if ($status !== '') { $where[] = 'p.status = ?'; $params[] = $status; }
if ($employee !== '' && userCan('properties.manage')) { $where[] = 'p.assigned_to = ?'; $params[] = $employee; }
if ($date !== '') { $where[] = 'DATE(p.created_at) = ?'; $params[] = $date; }

$whereSql = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM properties p WHERE $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$stmt = $db->prepare("SELECT p.*, u.name AS assigned_name FROM properties p
                       LEFT JOIN users u ON u.id = p.assigned_to
                       WHERE $whereSql ORDER BY p.created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();

jsonResponse(true, [
    'rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage,
    'total_pages' => (int)ceil($total / $perPage),
]);
