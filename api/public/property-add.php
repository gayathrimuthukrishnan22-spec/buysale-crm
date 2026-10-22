<?php
/**
 * Public "Add Property Stock" intake endpoint.
 * Receives the staff/admin stock form from add-property.html
 * (buysalerentalproperty.com) and stores it directly as a row in
 * `properties` with is_stock = 1 — this is owner-supplied inventory
 * ("Our Stock"), not a customer enquiry/lead like register.php handles.
 *
 * category is always sale / rental / lease here (never 'buy' — a stock
 * listing is something being offered, not something a customer wants
 * to buy). That keeps this endpoint from ever polluting the "buy" side
 * of the CRM, which only ever comes from register.php / manual add.
 *
 * This endpoint is intentionally NOT behind requireLogin() — the public
 * website has no employee session, same reasoning as register.php.
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/config.php';

// ---- CORS: allow only the public booking site to call this endpoint ----
// Update this to match wherever add-property.html is actually deployed.
$ALLOWED_ORIGINS = ['https://buysalerentalproperty.com', 'https://www.buysalerentalproperty.com',
    'http://localhost', 'http://127.0.0.1']; // localhost/127.0.0.1 kept for local XAMPP testing — remove before going live if you want to lock this down strictly
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $ALLOWED_ORIGINS, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, null, 'Invalid request method.', 405);
}

$db = getDB();

// ================= Step 1 & 2: category + purpose =================
// form field "category" = Residential / Commercial / Agricultural / Plot / Land
// form field "purpose"  = Sale / Rental / Lease
// Mirrors register.php's convention exactly: properties.category holds the
// business type (sale/rental/lease) and properties.property_type holds the
// broad category (Residential/Commercial/...), NOT the specific sub-type —
// that's what lets api/properties/match_stock.php compare stock rows against
// client requirement rows apples-to-apples.
$PURPOSE_MAP = ['Sale' => 'sale', 'Rental' => 'rental', 'Lease' => 'lease'];
$purposeRaw = clean($_POST['purpose'] ?? '');
if (!isset($PURPOSE_MAP[$purposeRaw])) {
    jsonResponse(false, null, 'Please choose a Listing Purpose (Sale, Rental or Lease).', 422);
}
$category = $PURPOSE_MAP[$purposeRaw];

$broadCategory = clean($_POST['category'] ?? '');
if (!in_array($broadCategory, ['Residential', 'Commercial', 'Agricultural', 'Plot / Land'], true)) {
    jsonResponse(false, null, 'Please choose a Property Category (Residential, Commercial, Agricultural, or Plot / Land).', 422);
}

// ================= Step 3 & 4: type, title & details =================
$specificType = clean($_POST['propertyType'] ?? '');
$title = clean($_POST['title'] ?? '');
$description = clean($_POST['description'] ?? '');
$price = !empty($_POST['price']) ? (float)$_POST['price'] : null;
$priceType = clean($_POST['priceType'] ?? '') ?: 'One-time';
$size = clean($_POST['size'] ?? '');
$sizeUnit = clean($_POST['sizeUnit'] ?? '');
$formStatus = clean($_POST['status'] ?? 'Available');
$bhk = clean($_POST['bhk'] ?? '');
$furnished = clean($_POST['furnished'] ?? '');
$parking = clean($_POST['parking'] ?? '');

if ($specificType === '' || $title === '' || $price === null) {
    jsonResponse(false, null, 'Property type, title and price are required.', 422);
}

// Form's "Available/Sold/Rented/On Hold" -> CRM's status enum
$STATUS_MAP = ['Available' => 'active', 'Sold' => 'closed', 'Rented' => 'closed', 'On Hold' => 'pending'];
$status = $STATUS_MAP[$formStatus] ?? 'active';

// ================= Step 5: location =================
$address = clean($_POST['address'] ?? '');
$city = clean($_POST['city'] ?? '');
$district = clean($_POST['district'] ?? '') ?: null;
$pincode = clean($_POST['pincode'] ?? '') ?: null;

if ($address === '' || $city === '') {
    jsonResponse(false, null, 'Address and city are required.', 422);
}
if ($pincode !== null && !preg_match('/^[0-9]{6}$/', $pincode)) {
    jsonResponse(false, null, 'Pincode must be 6 digits.', 422);
}

// ================= Step 6: owner / contact =================
$ownerName = clean($_POST['ownerName'] ?? '');
$ownerPhone = normalizeMobileForStorage(clean($_POST['ownerPhone'] ?? ''));
$addedBy = clean($_POST['addedBy'] ?? '') ?: null;

if ($ownerName === '' || $ownerPhone === '') {
    jsonResponse(false, null, 'Owner name and phone number are required.', 422);
}
if (!isValidMobile($ownerPhone)) {
    jsonResponse(false, null, 'Please enter a valid 10-digit owner phone number.', 422);
}

// Everything that doesn't have its own column gets folded into a
// human-readable notes block, same spirit as register.php's
// formatDetailsAsLines() for leads.
function formatStockNotes(array $rows): string {
    $lines = [];
    foreach ($rows as $label => $value) {
        if ($value === null || $value === '') continue;
        $lines[] = $label . ': ' . $value;
    }
    return implode("\n", $lines);
}

$notes = formatStockNotes([
    'Property Type' => $specificType,
    'Size' => $size !== '' ? ($size . ' ' . $sizeUnit) : null,
    'BHK' => $bhk,
    'Furnished' => $furnished,
    'Parking' => $parking,
    'District' => $district,
    'Pincode' => $pincode,
    'Listing Status' => $formStatus,
    'Added By (Staff)' => $addedBy,
]);

$fullDescription = trim($title . ($description !== '' ? ("\n\n" . $description) : ''));

// ================= Validate images up-front =================
$imageFiles = $_FILES['images'] ?? null; // expected: images[] => multiple files
if ($imageFiles && isset($imageFiles['error']) && is_array($imageFiles['error'])) {
    foreach ($imageFiles['error'] as $idx => $err) {
        if ($err === UPLOAD_ERR_OK && $imageFiles['size'][$idx] > MAX_UPLOAD_SIZE) {
            jsonResponse(false, null, 'One of the photos is larger than ' . (int)(MAX_UPLOAD_SIZE / (1024 * 1024)) . 'MB.', 422);
        }
    }
}

// ================= Persist =================
try {
    $db->beginTransaction();

    // is_stock = 1: this is owner-supplied inventory ("Our Stock"), matched
    // against buyer/renter/lessee requirement rows by match_stock.php —
    // never a requirement itself, and never category = 'buy'.
    $stmt = $db->prepare("INSERT INTO properties
        (property_code, category, is_stock, client_name, client_mobile, property_type,
         address, city, budget, description, notes, status, assigned_to, created_by, lead_id, source_channel, created_at)
        VALUES ('TEMP',?,1,?,?,?,?,?,?,?,?,?,NULL,NULL,NULL,'add-property.html',NOW())");
    $stmt->execute([
        $category, $ownerName, $ownerPhone, $broadCategory,
        $address, $city, $price, $fullDescription, $notes, $status,
    ]);
    $propertyId = (int)$db->lastInsertId();
    $db->prepare("UPDATE properties SET property_code = ? WHERE id = ?")
        ->execute([generateCode('PROP', $propertyId), $propertyId]);

    logActivity('property', $propertyId, 'created', null, null, 'via public add-property.html (stock)', null);

    // Photos (images[] -> property_images)
    if ($imageFiles && isset($imageFiles['error']) && is_array($imageFiles['error'])) {
        $mediaInsert = $db->prepare("INSERT INTO property_images (property_id, file_path, media_type, original_name) VALUES (?,?,'image',?)");
        foreach ($imageFiles['error'] as $idx => $err) {
            $single = [
                'name' => $imageFiles['name'][$idx],
                'type' => $imageFiles['type'][$idx],
                'tmp_name' => $imageFiles['tmp_name'][$idx],
                'error' => $imageFiles['error'][$idx],
                'size' => $imageFiles['size'][$idx],
            ];
            $saved = savePropertyMediaFile($single, $propertyId, 'image');
            if ($saved && !isset($saved['error'])) {
                $mediaInsert->execute([$propertyId, $saved['path'], $saved['original_name']]);
            }
        }
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    error_log('Property stock intake failed: ' . $e->getMessage());
    jsonResponse(false, null, 'Could not save. This usually means the database needs migration_v16 run — see /database. ' . $e->getMessage(), 422);
}

$codeStmt = $db->prepare("SELECT property_code FROM properties WHERE id = ?");
$codeStmt->execute([$propertyId]);
$code = $codeStmt->fetchColumn();

jsonResponse(true, ['property_id' => $propertyId, 'reference' => $code], 'Property saved to Property CRM stock successfully.');