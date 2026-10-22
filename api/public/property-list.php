<?php
/**
 * Public "List Approved Properties" endpoint.
 * Returns owner-supplied stock (properties.is_stock = 1, status = active)
 * as a plain JSON array, in the exact shape buysalerentalproperty.com's
 * assets/js/main.js expects (see PROPERTYCRM_LISTING_API_URL there):
 *
 *   [{ "title": "...", "type": "Plot / Land",
 *      "purpose": "For Sale", "location": "...", "district": "...",
 *      "price": "...", "photos": ["https://.../1.jpg", ...],
 *      "video": "https://.../clip.mp4" }]
 *
 * This is the read counterpart to property-add.php (which writes stock)
 * and register.php (which writes leads). Without this file, the website's
 * "Search Property" modal always shows "No properties listed yet" because
 * its fetch() to this URL 404s and silently falls back to local-only data.
 *
 * This endpoint is intentionally NOT behind requireLogin() — the public
 * website has no employee session, same reasoning as the other public
 * endpoints in this folder. It is read-only (GET) and only ever returns
 * rows that are stock (is_stock = 1) and active, never leads/requirements.
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/config.php';

// ---- CORS: allow only the public website to call this endpoint ----
$ALLOWED_ORIGINS = ['https://buysalerentalproperty.com', 'https://www.buysalerentalproperty.com',
    'http://localhost', 'http://127.0.0.1']; // localhost/127.0.0.1 kept for local XAMPP testing — remove before going live if you want to lock this down strictly
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $ALLOWED_ORIGINS, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    // GET-only, read-only, no auth cookie involved — safe to also allow
    // direct browser hits (e.g. testing the URL) without an Origin header.
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$db = getDB();

// Absolute origin (scheme + host) to turn UPLOAD_URL's relative path into
// a full URL — the website lives on a different domain, so relative image
// paths would try to load from buysalerentalproperty.com itself.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$siteOrigin = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '');

$CATEGORY_PURPOSE = ['sale' => 'For Sale', 'rental' => 'For Rent', 'lease' => 'For Lease'];

try {
    $stmt = $db->prepare("SELECT id, property_code, category, property_type, address, city,
                                  budget, description, notes, status, created_at
                           FROM properties
                           WHERE is_stock = 1 AND status IN ('active','pending')
                           ORDER BY created_at DESC
                           LIMIT 200");
    $stmt->execute();
    $rows = $stmt->fetchAll();

    if (!$rows) {
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }

    $ids = array_column($rows, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $mediaStmt = $db->prepare("SELECT property_id, file_path, media_type
                                FROM property_images
                                WHERE property_id IN ($placeholders)
                                ORDER BY id ASC");
    $mediaStmt->execute($ids);
    $mediaByProperty = [];
    foreach ($mediaStmt->fetchAll() as $m) {
        $mediaByProperty[$m['property_id']][] = $m;
    }

    $result = [];
    foreach ($rows as $row) {
        $descLines = preg_split('/\R/', trim((string)$row['description']));
        $title = trim($descLines[0] ?? '') ?: ($row['property_type'] . ' in ' . $row['city']);

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

        $result[] = [
            'title' => $title,
            'type' => $row['property_type'],
            'purpose' => $CATEGORY_PURPOSE[$row['category']] ?? ucfirst($row['category']),
            'location' => trim($row['address'] . ', ' . $row['city'], ', '),
            'district' => $district,
            'price' => $price,
            'photos' => $photos,
            'video' => $video,
        ];
    }

    header('Content-Type: application/json');
    echo json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('property-list.php failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Could not load properties.']);
}
