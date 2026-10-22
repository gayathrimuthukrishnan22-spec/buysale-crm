<?php
/**
 * Lists website-booking leads (book.html) whose advance-payment proof has
 * NOT yet been manually verified — i.e. the queue an admin/manager works
 * through before that registration is allowed to become a real Lead.
 * See api/leads/verify-payment.php for the verify/reject action, and
 * api/leads/list.php which excludes anything still sitting in this queue.
 */
require_once __DIR__ . '/../../includes/auth.php';
requirePermission('leads.view_all');

$db = getDB();

$search  = clean($_GET['search'] ?? '');
$payStat = clean($_GET['payment_status'] ?? 'pending'); // pending | rejected | all
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

$where  = ["lp.paid_at = (SELECT MAX(lp2.paid_at) FROM lead_payments lp2 WHERE lp2.lead_id = l.id)"];
$params = [];

if ($payStat === 'pending' || $payStat === 'rejected') {
    $where[] = 'lp.status = ?';
    $params[] = $payStat;
} elseif ($payStat !== 'all') {
    $where[] = "lp.status IN ('pending','rejected')";
}

if ($search !== '') {
    $where[] = '(l.client_name LIKE ? OR l.client_mobile LIKE ? OR l.lead_code LIKE ?)';
    $like = "%$search%";
    array_push($params, $like, $like, $like);
}

$whereSql = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM leads l JOIN lead_payments lp ON lp.lead_id = l.id WHERE $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$sql = "SELECT l.id, l.lead_code, l.client_name, l.client_mobile, l.requirement, l.property_type,
                l.budget, l.city, l.source, l.created_at,
                lp.id AS payment_id, lp.amount, lp.method, lp.screenshot_path, lp.status AS payment_status, lp.paid_at
        FROM leads l
        JOIN lead_payments lp ON lp.lead_id = l.id
        WHERE $whereSql
        ORDER BY l.created_at DESC
        LIMIT $perPage OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

jsonResponse(true, [
    'rows' => $rows,
    'total' => $total,
    'page' => $page,
    'per_page' => $perPage,
    'total_pages' => (int)ceil($total / $perPage),
]);
