<?php
/**
 * Lists every client requirement (properties.is_stock = 0) currently sitting
 * in status = 'dead' (subscription used up), across all categories that use
 * the subscription/match flow (buy, rental, lease, rental_tenant — 'sale' is
 * excluded since sellers don't have a subscription).
 *
 * This backs the "Dead Leads" menu: a record shows up here automatically the
 * moment connect_match.php flips it to 'dead', and disappears automatically
 * the moment renew_subscription.php flips it back to 'active' — there is no
 * separate table/flag to keep in sync, it's just a filtered view over the
 * same properties rows, so a renewed lead lands back on its own category
 * page (Buy/Rental/Lease/Tenant Rental) exactly as before it went dead.
 */
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$db = getDB();

$eligibleCategories = ['buy', 'rental', 'lease', 'rental_tenant'];

$category = clean($_GET['category'] ?? '');
if ($category !== '' && !in_array($category, $eligibleCategories, true)) {
    jsonResponse(false, null, 'Invalid category.', 422);
}

$search   = clean($_GET['search'] ?? '');
$employee = clean($_GET['employee'] ?? '');
$date     = clean($_GET['date'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 15;
$offset   = ($page - 1) * $perPage;

$where = ["p.is_stock = 0", "p.status = 'dead'"];
$params = [];

if ($category !== '') {
    $where[] = 'p.category = ?';
    $params[] = $category;
} else {
    $where[] = 'p.category IN (' . implode(',', array_fill(0, count($eligibleCategories), '?')) . ')';
    array_push($params, ...$eligibleCategories);
}

if (!userCan('properties.manage')) { $where[] = 'p.assigned_to = ?'; $params[] = currentUserId(); }
if ($search !== '') {
    $where[] = '(p.client_name LIKE ? OR p.client_mobile LIKE ? OR p.property_code LIKE ? OR p.address LIKE ?)';
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like);
}
if ($employee !== '' && userCan('properties.manage')) { $where[] = 'p.assigned_to = ?'; $params[] = $employee; }
if ($date !== '') { $where[] = 'DATE(p.created_at) = ?'; $params[] = $date; }

$whereSql = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM properties p WHERE $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$stmt = $db->prepare("SELECT p.*, u.name AS assigned_name FROM properties p
                       LEFT JOIN users u ON u.id = p.assigned_to
                       WHERE $whereSql ORDER BY p.updated_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();

jsonResponse(true, [
    'rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage,
    'total_pages' => (int)ceil($total / $perPage),
]);
