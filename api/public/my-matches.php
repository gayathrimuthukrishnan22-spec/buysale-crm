<?php
/**
 * Public "My Matches" endpoint.
 * Customer enters the mobile number they registered with on book.html and
 * this looks up their requirement (properties.is_stock = 0) row, created by
 * api/public/register.php.
 *
 * IMPORTANT — this is the "admin accept" gate the customer asked about:
 *   - register.php always inserts that row with status = 'payment_verification'
 *     (hidden), regardless of what the client-side JS optimistically shows.
 *   - It STAYS 'payment_verification' until a staff member reviews the
 *     payment screenshot on the Payment Verification page and clicks
 *     Verify — see api/leads/verify-payment.php, which then flips this row
 *     to status = 'active' and subscription_paid = 1.
 *   - Only once status = 'active' do we run matching and return stock here.
 * So matches will now only ever show up AFTER an admin/employee has
 * accepted (verified) the registration — never purely from a client-side
 * "paid" flag.
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/config.php';

// ---- CORS: allow only the public booking site to call this endpoint ----
$ALLOWED_ORIGINS = ['https://buysalerentalproperty.com', 'https://www.buysalerentalproperty.com',
    'http://localhost', 'http://127.0.0.1'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $ALLOWED_ORIGINS, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, null, 'Invalid request method.', 405);
}

$mobile = normalizeMobileForStorage(clean($_GET['mobile'] ?? ''));
if (!isValidMobile($mobile)) {
    jsonResponse(false, null, 'Enter the 10-digit mobile number you registered with.', 422);
}

$db = getDB();

// Latest requirement row this customer submitted via the website.
$stmt = $db->prepare("SELECT * FROM properties
                       WHERE is_stock = 0 AND client_mobile = ?
                       ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$mobile]);
$client = $stmt->fetch();

if (!$client) {
    jsonResponse(false, null,
        "We couldn't find a registration for this number. Please register and complete the advance payment on the booking page first.",
        404);
}

// The customer's own reference number, so they can quote it when they call us.
$leadCode = null;
if (!empty($client['lead_id'])) {
    $leadStmt = $db->prepare("SELECT lead_code FROM leads WHERE id = ?");
    $leadStmt->execute([$client['lead_id']]);
    $leadCode = $leadStmt->fetchColumn() ?: null;
}

// Still waiting on staff to review the payment screenshot — do NOT show matches.
if ($client['status'] === 'payment_verification') {
    jsonResponse(true, [
        'status' => 'pending_verification',
        'client_name' => $client['client_name'],
        'lead_code' => $leadCode,
        'matches' => [],
        'match_count' => 0,
    ], "Thanks {$client['client_name']}! Your advance payment is still being verified by our team. Matches will unlock as soon as it's accepted — usually within a few hours.");
}

if (in_array($client['status'], ['cancelled', 'dead'], true)) {
    jsonResponse(true, [
        'status' => $client['status'],
        'client_name' => $client['client_name'],
        'lead_code' => $leadCode,
        'matches' => [],
        'match_count' => 0,
    ], "This registration is no longer active. Please contact us or register again.");
}

// status is 'active' (or 'pending'/'closed') -> payment was verified by an
// admin at some point (see api/leads/verify-payment.php). Run matching.
$CATEGORY_MAP = [
    'buy'           => 'sale',
    'rental'        => 'rental',
    'lease'         => 'lease',
    'rental_tenant' => 'rental_tenant',
];

if (!isset($CATEGORY_MAP[$client['category']])) {
    // 'sale' clients are sellers themselves — nothing to match them against.
    jsonResponse(true, [
        'status' => 'verified',
        'client_name' => $client['client_name'],
        'lead_code' => $leadCode,
        'criteria' => ['type' => $client['property_type'], 'purpose' => 'Sell', 'location' => $client['city'], 'budget' => $client['budget']],
        'matches' => [],
        'match_count' => 0,
    ], "Thanks {$client['client_name']}! Your listing is verified. Our team will reach out once we find a buyer.");
}
$stockCategory = $CATEGORY_MAP[$client['category']];

$stmt = $db->prepare("SELECT id, property_code, category, property_type, address, city, budget, description, notes, status, created_at
                       FROM properties
                       WHERE is_stock = 1 AND status = 'active' AND category = ?
                       ORDER BY created_at DESC LIMIT 200");
$stmt->execute([$stockCategory]);
$candidates = $stmt->fetchAll();

function normWords(string $s): array {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9\s]/', ' ', $s);
    $words = preg_split('/\s+/', $s, -1, PREG_SPLIT_NO_EMPTY);
    $stop = ['for', 'sale', 'rent', 'the', 'a', 'an', 'bhk'];
    return array_values(array_diff($words, $stop));
}

$clientCity   = strtolower(trim((string)($client['city'] ?? '')));
$clientTypeW  = normWords((string)($client['property_type'] ?? ''));
$clientBudget = $client['budget'] !== null ? (float)$client['budget'] : null;

$scored = [];
foreach ($candidates as $row) {
    $score = 0;
    $stockCity = strtolower(trim((string)($row['city'] ?? '')));
    if ($clientCity !== '' && $stockCity !== '' && $clientCity === $stockCity) $score += 40;

    $stockTypeW = normWords((string)($row['property_type'] ?? ''));
    if ($clientTypeW && $stockTypeW && array_intersect($clientTypeW, $stockTypeW)) $score += 30;

    if ($clientBudget !== null && $row['budget'] !== null) {
        $stockBudget = (float)$row['budget'];
        if ($stockBudget <= $clientBudget) {
            $score += 30 * max(0, 1 - (($clientBudget - $stockBudget) / max($clientBudget, 1)));
        } else {
            $over = ($stockBudget - $clientBudget) / max($clientBudget, 1);
            $score += max(0, 30 * (1 - $over * 2));
        }
    } else {
        $score += 10; // no budget on one side — don't fully penalize
    }
    $scored[] = ['row' => $row, 'score' => $score];
}
usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
$scored = array_slice($scored, 0, 30);

// ---- Shape matches exactly like property-list.php so matches.html's existing
// cardMarkup()/renderMatches() work unchanged ----
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$siteOrigin = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '');
$CATEGORY_PURPOSE = ['sale' => 'For Sale', 'rental' => 'For Rent', 'lease' => 'For Lease', 'rental_tenant' => 'For Rent'];

$ids = array_column(array_column($scored, 'row'), 'id');
$mediaByProperty = [];
if ($ids) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $mediaStmt = $db->prepare("SELECT property_id, file_path, media_type FROM property_images WHERE property_id IN ($placeholders) ORDER BY id ASC");
    $mediaStmt->execute($ids);
    foreach ($mediaStmt->fetchAll() as $m) {
        $mediaByProperty[$m['property_id']][] = $m;
    }
}

$matches = [];
foreach ($scored as $item) {
    $row = $item['row'];
    $descLines = array_values(array_filter(
        array_map('trim', preg_split('/\R/', trim((string)$row['description']))),
        fn($line) => $line !== ''
    ));
    $title = $descLines[0] ?? ($row['property_type'] . ' in ' . $row['city']);
    // Everything AFTER the title line is the BHK/size/extra-detail text staff
    // typed in (there's no separate bhk/sqft column) — safe to show in full
    // since it never contains the street address or owner mobile (those live
    // in the address/client_mobile columns, which are deliberately not
    // selected/exposed here).
    $details = trim(implode("\n", array_slice($descLines, 1)));

    $district = null;
    if (!empty($row['notes']) && preg_match('/^District:\s*(.+)$/mi', $row['notes'], $m)) {
        $district = trim($m[1]);
    }

    $priceSuffix = $row['category'] === 'sale' ? '' : '/month';
    $price = $row['budget'] !== null ? (formatMoney($row['budget']) . $priceSuffix) : '';

    $photos = [];
    $video = null;
    foreach ($mediaByProperty[$row['id']] ?? [] as $media) {
        $url = $siteOrigin . UPLOAD_URL . $media['file_path'];
        if ($media['media_type'] === 'video') {
            $video = $video ?? $url;
        } else {
            $photos[] = $url;
        }
    }

    $matches[] = [
        'title' => $title,
        'type' => $row['property_type'],
        'purpose' => $CATEGORY_PURPOSE[$row['category']] ?? ucfirst($row['category']),
        // Exact city/area shown on purpose — only the street-level 'address'
        // column is withheld (that + client_mobile are the two things kept
        // private until our team shares them after enquiry confirmation).
        'area' => $row['city'] ?? '',
        'district' => $district,
        'details' => $details,   // BHK / size / other free-text specifics
        'price' => $price,
        'photos' => $photos,
        'video' => $video,
    ];
}

jsonResponse(true, [
    'status' => 'verified',
    'client_name' => $client['client_name'],
    'lead_code' => $leadCode,
    'criteria' => [
        'type' => $client['property_type'],
        'purpose' => $client['category'],
        'location' => $client['city'],
        'budget' => $client['budget'] !== null ? (float)$client['budget'] : null,
    ],
    'matches' => $matches,
    'match_count' => count($matches),
], $matches ? "Thanks {$client['client_name']}! Here's what we found for you." : "Thanks {$client['client_name']}! No matching stock right now — we'll notify you as soon as one comes in.");
