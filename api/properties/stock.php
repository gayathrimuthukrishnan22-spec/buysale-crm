<?php
/**
 * "Our Stock" read endpoint — a location-focused view of the existing
 * `properties` table, split into the stock types shown in the sidebar:
 *   rental_house      -> category = 'rental'
 *   land_sale         -> category = 'sale'  AND property_type looks like plot/land (not agricultural)
 *   agri_land         -> category = 'sale'  AND property_type looks like agricultural/farm land
 *   house_sale        -> category = 'sale'  AND property_type does NOT look like land/agri/commercial
 *   commercial_sale   -> category = 'sale'  AND property_type looks like commercial (shop/office/etc.)
 *   commercial_lease  -> category = 'lease' AND property_type looks like commercial (shop/office/etc.)
 *   residential_lease -> category = 'lease' AND property_type does NOT look like commercial
 *
 * Every row here also has is_stock = 1 — this is owner-supplied
 * inventory (their own stock to sell/rent out), kept separate from the
 * buyer/tenant requirement leads tracked in the Properties section
 * (which are is_stock = 0). A row's is_stock flag is set once at
 * creation and never flipped by an edit.
 *
 * Returns both the filtered rows (for the table) and a city-wise count
 * summary (for the "which place has what stock" strip), so the page can
 * render both from a single request.
 */
require_once __DIR__ . '/../../includes/auth.php';
requirePermissionApi('properties.view');

$db = getDB();

$STOCK_TYPES = [
    'rental_house'      => ['category' => 'rental', 'type_filter' => null],
    'land_sale'         => ['category' => 'sale',   'type_filter' => 'land'],
    'agri_land'         => ['category' => 'sale',   'type_filter' => 'agri'],
    'house_sale'        => ['category' => 'sale',   'type_filter' => 'house'],
    'commercial_sale'   => ['category' => 'sale',   'type_filter' => 'commercial'],
    'commercial_lease'  => ['category' => 'lease',  'type_filter' => 'commercial'],
    'residential_lease' => ['category' => 'lease',  'type_filter' => 'residential'],
];

$stockType = clean($_GET['stock_type'] ?? '');
if (!isset($STOCK_TYPES[$stockType])) {
    jsonResponse(false, null, 'Invalid stock type.', 422);
}
$def = $STOCK_TYPES[$stockType];

// Words that mark a Sale property as agricultural/farm land (its own bucket, separate from plain plots).
$AGRI_WORDS = ['agri', 'agricultural', 'farm', 'farmland'];

// Words that mark a Sale property as land/plot rather than a house/flat (agri words excluded — they get their own bucket above).
$LAND_WORDS = ['land', 'plot', 'site'];

// Words that mark a Sale/Lease property as commercial rather than residential.
$COMMERCIAL_WORDS = ['commercial', 'shop', 'office', 'showroom', 'warehouse', 'godown', 'shed', 'mall', 'complex'];

$search = clean($_GET['search'] ?? '');
$city   = clean($_GET['city'] ?? '');
$status = clean($_GET['status'] ?? 'active'); // stock defaults to what's currently available
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

$where = ['p.category = ?', 'p.is_stock = 1'];
$params = [$def['category']];

if (!userCan('properties.manage')) { $where[] = 'p.assigned_to = ?'; $params[] = currentUserId(); }

if ($def['type_filter'] === 'agri') {
    $ors = [];
    foreach ($AGRI_WORDS as $w) { $ors[] = 'p.property_type LIKE ?'; $params[] = "%$w%"; }
    $where[] = '(' . implode(' OR ', $ors) . ')';
} elseif ($def['type_filter'] === 'land') {
    $ors = [];
    foreach ($LAND_WORDS as $w) { $ors[] = 'p.property_type LIKE ?'; $params[] = "%$w%"; }
    $where[] = '(' . implode(' OR ', $ors) . ')';
    // Agricultural land ("Agricultural Land", "Farm Land"...) has its own bucket above — keep it out of here.
    foreach ($AGRI_WORDS as $w) { $where[] = 'p.property_type NOT LIKE ?'; $params[] = "%$w%"; }
} elseif ($def['type_filter'] === 'house' && $def['category'] === 'sale') {
    foreach (array_merge($LAND_WORDS, $AGRI_WORDS, $COMMERCIAL_WORDS) as $w) {
        $where[] = 'p.property_type NOT LIKE ?'; $params[] = "%$w%";
    }
} elseif ($def['type_filter'] === 'commercial') {
    $ors = [];
    foreach ($COMMERCIAL_WORDS as $w) { $ors[] = 'p.property_type LIKE ?'; $params[] = "%$w%"; }
    $where[] = '(' . implode(' OR ', $ors) . ')';
} elseif ($def['type_filter'] === 'residential') {
    foreach ($COMMERCIAL_WORDS as $w) { $where[] = 'p.property_type NOT LIKE ?'; $params[] = "%$w%"; }
}

if ($search !== '') {
    $where[] = '(p.client_name LIKE ? OR p.client_mobile LIKE ? OR p.property_code LIKE ? OR p.address LIKE ? OR p.city LIKE ?)';
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like, $like);
}
if ($city !== '')   { $where[] = 'p.city = ?'; $params[] = $city; }
if ($status !== '') { $where[] = 'p.status = ?'; $params[] = $status; }

$whereSql = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM properties p WHERE $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$stmt = $db->prepare("SELECT p.*, u.name AS assigned_name FROM properties p
                       LEFT JOIN users u ON u.id = p.assigned_to
                       WHERE $whereSql ORDER BY p.city IS NULL, p.city ASC, p.created_at DESC
                       LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// City-wise summary — same filters minus the city itself, so the strip
// always shows every place that has stock of this type.
$summaryWhere = array_filter($where, fn($clause) => $clause !== 'p.city = ?');
$summaryParams = [];
$paramIdx = 0;
foreach ($where as $clause) {
    if ($clause === 'p.city = ?') { $paramIdx++; continue; }
    $n = substr_count($clause, '?');
    for ($i = 0; $i < $n; $i++) { $summaryParams[] = $params[$paramIdx]; $paramIdx++; }
}
$summaryWhereSql = implode(' AND ', $summaryWhere);
$sumStmt = $db->prepare("SELECT COALESCE(NULLIF(TRIM(p.city), ''), 'Not specified') AS city, COUNT(*) AS total
                          FROM properties p WHERE $summaryWhereSql
                          GROUP BY city ORDER BY total DESC");
$sumStmt->execute($summaryParams);
$byCity = $sumStmt->fetchAll();

jsonResponse(true, [
    'rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage,
    'total_pages' => (int)ceil($total / max(1, $perPage)),
    'by_city' => $byCity,
]);
