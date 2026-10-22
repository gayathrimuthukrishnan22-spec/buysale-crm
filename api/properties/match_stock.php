<?php
/**
 * Match Stock Suggestions
 * ------------------------
 * Given a client requirement row (properties.is_stock = 0 — a Buy/Rental/Lease/
 * Tenant-Rental client from the Properties module), find the best-matching
 * "Our Stock" rows (properties.is_stock = 1) — the owner-supplied inventory —
 * and score/rank them so staff can quickly see which stock to offer this client.
 *
 * A "Sale" row (is_stock = 0, category = 'sale') is a seller lead, not a buyer,
 * so there's no stock to suggest to them — they ARE the stock (once added to
 * "Our Stock" separately). We return an explanatory message instead of matches.
 *
 * Scoring (out of 100):
 *   City match          40 pts  (exact, case-insensitive)
 *   Property type match 30 pts  (word overlap, e.g. "2BHK Apartment" vs "Apartment")
 *   Budget fit          30 pts  (closer the stock price/rent is to the client's
 *                                 budget — without exceeding it by much — the higher)
 */
require_once __DIR__ . '/../../includes/auth.php';
requirePermissionApi('properties.view');

$db = getDB();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) jsonResponse(false, null, 'Invalid property id.', 422);

try {

$stmt = $db->prepare("SELECT * FROM properties WHERE id = ? AND is_stock = 0");
$stmt->execute([$id]);
$client = $stmt->fetch();
if (!$client) jsonResponse(false, null, 'Client requirement not found.', 404);

if (!userCan('properties.manage') && (int)$client['assigned_to'] !== currentUserId()) {
    jsonResponse(false, null, 'Not authorized.', 403);
}

// Which stock category satisfies each client requirement category.
$CATEGORY_MAP = [
    'buy'           => 'sale',           // wants to buy  -> our sale stock
    'rental'        => 'rental',         // wants to rent -> our rental stock
    'lease'         => 'lease',          // wants a lease -> our lease stock
    'rental_tenant' => 'rental_tenant',  // tenant rental -> matching stock
];

if (!isset($CATEGORY_MAP[$client['category']])) {
    jsonResponse(true, [
        'client' => $client,
        'matches' => [],
        'note' => 'Sale clients are sellers, not buyers — there is no stock to suggest here. Once their property is added under "Our Stock", buy clients will be matched to it instead.',
    ]);
}
$stockCategory = $CATEGORY_MAP[$client['category']];

// Matching used to require the ₹1000 subscription to be manually confirmed
// paid first (via a checkbox on Add/Edit). That gate is removed — payment
// is already confirmed upstream, either by Payment Verification (website
// bookings — see api/leads/verify-payment.php, which also sets
// subscription_paid=1 on verify) or by staff collecting it before adding
// the lead manually. Matching is now open to any active lead in this
// category by default; only the used-up-matches ('dead') check below still
// applies.
$subscription = [
    'matches_used' => (int)$client['subscription_matches_used'],
    'match_limit'  => SUBSCRIPTION_MATCH_LIMIT,
    'exhausted'    => (int)$client['subscription_matches_used'] >= SUBSCRIPTION_MATCH_LIMIT || $client['status'] === 'dead',
    'paid'         => true,
];

// Already-connected stock always shows (marked "Connected", no button). Fresh
// candidate suggestions are added on top of that as long as the subscription
// still has matches left — once it's exhausted, only the connected stock shows.
$connStmt = $db->prepare("SELECT p.*, u.name AS assigned_name, pc.connected_at FROM property_connections pc
                           JOIN properties p ON p.id = pc.stock_id
                           LEFT JOIN users u ON u.id = p.assigned_to
                           WHERE pc.client_id = ? ORDER BY pc.connected_at DESC");
$connStmt->execute([$id]);
$connectedRows = $connStmt->fetchAll();
$connectedStockIds = array_map(fn($c) => (int)$c['id'], $connectedRows);
foreach ($connectedRows as &$c) {
    $c['is_connected'] = true;
    $c['match_score'] = null;
    $c['match_reasons'] = [];
}
unset($c);

if ($subscription['exhausted']) {
    jsonResponse(true, [
        'client' => $client,
        'matches' => $connectedRows,
        'subscription' => $subscription,
        'note' => $connectedRows
            ? 'Subscription used up — new suggestions are hidden until the subscription is renewed.'
            : null,
    ]);
}

$where = ['p.category = ?', 'p.is_stock = 1', "p.status = 'active'"];
$params = [$stockCategory];
// Matching searches the WHOLE company's stock, not just stock assigned to the
// current employee -- a buyer can be matched to any available property in
// inventory, regardless of which employee originally added/manages that
// listing. (Contrast with the "Our Stock" browse list and the client-requirement
// list, which stay scoped to "my own" for day-to-day management.)
if ($connectedStockIds) {
    $where[] = 'p.id NOT IN (' . implode(',', array_fill(0, count($connectedStockIds), '?')) . ')';
    array_push($params, ...$connectedStockIds);
}
$whereSql = implode(' AND ', $where);

$stmt = $db->prepare("SELECT p.*, u.name AS assigned_name FROM properties p
                       LEFT JOIN users u ON u.id = p.assigned_to
                       WHERE $whereSql ORDER BY p.created_at DESC LIMIT 300");
$stmt->execute($params);
$candidates = $stmt->fetchAll();

function normWords(string $s): array {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9\s]/', ' ', $s);
    $words = preg_split('/\s+/', $s, -1, PREG_SPLIT_NO_EMPTY);
    // drop very generic filler words that would otherwise inflate the match
    $stop = ['for', 'sale', 'rent', 'the', 'a', 'an', 'bhk'];
    return array_values(array_diff($words, $stop));
}

$clientCity   = strtolower(trim((string)($client['city'] ?? '')));
$clientTypeW  = normWords((string)($client['property_type'] ?? ''));
$clientBudget = $client['budget'] !== null ? (float)$client['budget'] : null;

$results = [];
foreach ($candidates as $row) {
    $score = 0;
    $reasons = [];

    // -- City --
    $stockCity = strtolower(trim((string)($row['city'] ?? '')));
    if ($clientCity !== '' && $stockCity !== '') {
        if ($clientCity === $stockCity) {
            $score += 40;
            $reasons[] = 'Same city (' . $row['city'] . ')';
        } elseif (str_contains($stockCity, $clientCity) || str_contains($clientCity, $stockCity)) {
            $score += 22;
            $reasons[] = 'Nearby location match';
        }
    }

    // -- Property type --
    $stockTypeW = normWords((string)($row['property_type'] ?? ''));
    if ($clientTypeW && $stockTypeW) {
        $overlap = array_intersect($clientTypeW, $stockTypeW);
        if (count($overlap) > 0) {
            $ratio = count($overlap) / max(count($clientTypeW), 1);
            $score += (int)round(30 * min(1, $ratio));
            $reasons[] = 'Property type matches (' . $row['property_type'] . ')';
        }
    }

    // -- Budget --
    $stockBudget = $row['budget'] !== null ? (float)$row['budget'] : null;
    if ($clientBudget !== null && $clientBudget > 0 && $stockBudget !== null && $stockBudget > 0) {
        if ($stockBudget <= $clientBudget) {
            // Within budget — closer to the budget (not too far under) scores best.
            $diffRatio = ($clientBudget - $stockBudget) / $clientBudget;
            $score += (int)round(30 * (1 - min(1, $diffRatio)));
            $reasons[] = 'Within budget (₹' . number_format($stockBudget) . ')';
        } else {
            // Over budget — small tolerance (10%) still gets partial credit.
            $overRatio = ($stockBudget - $clientBudget) / $clientBudget;
            if ($overRatio <= 0.10) {
                $score += (int)round(15 * (1 - $overRatio / 0.10));
                $reasons[] = 'Slightly above budget (₹' . number_format($stockBudget) . ')';
            }
        }
    }

    if ($score <= 0) continue; // no meaningful overlap at all — skip

    $row['match_score'] = min(100, $score);
    $row['match_reasons'] = $reasons;
    $results[] = $row;
}

usort($results, fn($a, $b) => $b['match_score'] <=> $a['match_score']);
$results = array_slice($results, 0, 10);

jsonResponse(true, [
    'client' => $client,
    'matches' => array_merge($connectedRows, $results),
    'subscription' => $subscription,
]);

} catch (PDOException $e) {
    error_log('match_stock.php failed: ' . $e->getMessage());
    jsonResponse(false, null, 'Could not load matches. This usually means the database needs a migration script run — check /database for the latest migration_v*.sql files (v12 and v13 add the subscription/connect feature). Contact your administrator.', 500);
}
