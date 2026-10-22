<?php
/**
 * Public booking intake endpoint.
 * Receives the multi-step registration form from book.html
 * (buysalerentalproperty.com) and stores it as a Lead in Property CRM,
 * along with its uploaded documents and payment proof.
 *
 * This endpoint is intentionally NOT behind requireLogin() — the public
 * website has no employee session. It relies on server-side validation
 * + prepared statements instead of a CSRF token.
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/config.php';

// ---- CORS: allow only the public booking site to call this endpoint ----
// Update this to match wherever book.html is actually deployed.
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

// ================= Step 1: service =================
$serviceType = clean($_POST['serviceType'] ?? '');
$SERVICE_MAP = ['Buy' => 'buy', 'Sell' => 'sale', 'Rental' => 'rental', 'Lease' => 'lease', 'TenantRental' => 'rental_tenant'];
$ADVANCE_FEE = ['Buy' => 1000, 'Sell' => 1000, 'Rental' => 500, 'Lease' => 1000, 'TenantRental' => 500, 'Property' => 500];

if ($serviceType === 'Property') {
    // Step 1 = "Property" means the visitor is giving out their OWN
    // property (with photos/video, collected in Step 4) rather than
    // asking us to find one. Its buy/sale/rental/lease category comes
    // from the purpose they pick in Step 4 ("Give for Sale/Rent/Lease"),
    // not from SERVICE_MAP.
    $PURPOSE_MAP = ['For Sale' => 'sale', 'For Rent' => 'rental', 'For Lease' => 'lease'];
    $propPurposeRaw = clean($_POST['propPurpose'] ?? '');
    if (!isset($PURPOSE_MAP[$propPurposeRaw])) {
        jsonResponse(false, null, 'Please choose whether you want to give this property for Sale, Rent or Lease.', 422);
    }
    $requirement = $PURPOSE_MAP[$propPurposeRaw];
} elseif (isset($SERVICE_MAP[$serviceType])) {
    $requirement = $SERVICE_MAP[$serviceType];
} else {
    jsonResponse(false, null, 'Please choose Buy, Sell, Rental, Lease, Tenant Rental or Property.', 422);
}

// ================= Step 2: personal details =================
$clientName = clean($_POST['fullName'] ?? '');
$clientMobile = normalizeMobileForStorage(clean($_POST['mobileNumber'] ?? ''));
$whatsapp = clean($_POST['whatsappNumber'] ?? '');
$whatsapp = $whatsapp !== '' ? normalizeMobileForStorage($whatsapp) : $clientMobile;
$clientEmail = clean($_POST['email'] ?? '') ?: null;
$dob = clean($_POST['dob'] ?? '') ?: null;
$gender = clean($_POST['gender'] ?? '') ?: null;
$occupation = clean($_POST['occupation'] ?? '') ?: null;

if ($clientName === '' || $clientMobile === '') {
    jsonResponse(false, null, 'Full name and mobile number are required.', 422);
}
if (!isValidMobile($clientMobile)) {
    jsonResponse(false, null, 'Please enter a valid 10-digit mobile number.', 422);
}

// ================= Step 3: identity & address =================
$religion = clean($_POST['religion'] ?? '') ?: null;
$caste = clean($_POST['caste'] ?? '') ?: null;
$aadhaar = clean($_POST['aadhaar'] ?? '') ?: null;
$pan = strtoupper(clean($_POST['pan'] ?? '')) ?: null;
$address = clean($_POST['address'] ?? '') ?: null;
$city = clean($_POST['city'] ?? '') ?: null;
$district = clean($_POST['district'] ?? '') ?: null;
$state = clean($_POST['state'] ?? '') ?: null;
$pincode = clean($_POST['pincode'] ?? '') ?: null;

if ($aadhaar !== null && !preg_match('/^[0-9]{12}$/', $aadhaar)) {
    jsonResponse(false, null, 'Aadhaar number must be 12 digits.', 422);
}
if ($pan !== null && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $pan)) {
    jsonResponse(false, null, 'PAN format looks incorrect (e.g. ABCDE1234F).', 422);
}

// ================= Step 4: property requirement =================
$propertyType = clean($_POST['propertyType'] ?? '') ?: null;
$minBudget = !empty($_POST['minBudget']) ? (float)$_POST['minBudget'] : null;
$maxBudget = !empty($_POST['maxBudget']) ? (float)$_POST['maxBudget'] : null;
$preferredArea = clean($_POST['preferredArea'] ?? '') ?: null;
$preferredCity = clean($_POST['preferredCity'] ?? '') ?: null;
$preferredLocation = trim(implode(', ', array_filter([$preferredArea, $preferredCity])));
$budget = $maxBudget ?? $minBudget;

// Fields that vary per service type — kept together as JSON rather than
// dozens of mostly-empty columns.
$requirementDetails = [
    'bhk' => clean($_POST['bhk'] ?? '') ?: null,
    'property_size_sqft' => clean($_POST['propSize'] ?? '') ?: null,
    'furnished_status' => clean($_POST['furnished'] ?? '') ?: null,
    'parking_required' => clean($_POST['parking'] ?? '') ?: null,
    'construction_status' => clean($_POST['constructionStatus'] ?? '') ?: null,
    'min_budget' => $minBudget,
    'max_budget' => $maxBudget,
    'additional_requirements' => clean($_POST['additionalRequirements'] ?? '') ?: null,
];
if ($requirement === 'buy') {
    $requirementDetails['buy'] = [
        'loan_required' => clean($_POST['loanRequired'] ?? '') ?: null,
        'loan_approved' => clean($_POST['loanApproved'] ?? '') ?: null,
        'bank_name' => clean($_POST['bankName'] ?? '') ?: null,
        'preferred_property_type' => clean($_POST['preferredPropType'] ?? '') ?: null,
    ];
} elseif ($requirement === 'sale') {
    $requirementDetails['sell'] = [
        'property_location' => clean($_POST['sellLocation'] ?? '') ?: null,
        'property_size' => clean($_POST['sellSize'] ?? '') ?: null,
        'expected_price' => !empty($_POST['expectedPrice']) ? (float)$_POST['expectedPrice'] : null,
        'property_age' => clean($_POST['propertyAge'] ?? '') ?: null,
        'ownership_details' => clean($_POST['ownershipDetails'] ?? '') ?: null,
    ];
} elseif ($requirement === 'rental') {
    $requirementDetails['rental'] = [
        'monthly_rent_budget' => !empty($_POST['monthlyRentBudget']) ? (float)$_POST['monthlyRentBudget'] : null,
        'preferred_area' => clean($_POST['rentalPreferredArea'] ?? '') ?: null,
        'family_members' => clean($_POST['familyMembers'] ?? '') ?: null,
        'pets' => clean($_POST['pets'] ?? '') ?: null,
        'move_in_date' => clean($_POST['moveInDate'] ?? '') ?: null,
    ];
} elseif ($requirement === 'lease') {
    $requirementDetails['lease'] = [
        'lease_budget' => !empty($_POST['leaseBudget']) ? (float)$_POST['leaseBudget'] : null,
        'lease_duration' => clean($_POST['leaseDuration'] ?? '') ?: null,
        'business_type' => clean($_POST['businessType'] ?? '') ?: null,
        'property_requirement' => clean($_POST['leasePropertyRequirement'] ?? '') ?: null,
    ];
} elseif ($requirement === 'rental_tenant') {
    // Was previously not captured at all — these fields were submitted by
    // book.html but silently dropped here, so nothing about the tenant's
    // actual requirement ever reached the CRM. Mirrors the 'rental' branch.
    $requirementDetails['rental_tenant'] = [
        'monthly_rent_budget' => !empty($_POST['tenantMonthlyRentBudget']) ? (float)$_POST['tenantMonthlyRentBudget'] : null,
        'preferred_area' => clean($_POST['tenantPreferredArea'] ?? '') ?: null,
        'family_members' => clean($_POST['tenantFamilyMembers'] ?? '') ?: null,
        'pets' => clean($_POST['tenantPets'] ?? '') ?: null,
        'move_in_date' => clean($_POST['tenantMoveInDate'] ?? '') ?: null,
    ];
}

// customer_type: best-effort mapping from the booking selections onto the
// CRM's requirement-category dropdown.
if ($propertyType === 'Commercial') {
    $customerType = 'commercial_space_requirement';
} elseif ($requirement === 'sale' && in_array($propertyType, ['Plot / Land', 'Agricultural'], true)) {
    $customerType = 'land_selling';
} elseif ($requirement === 'lease') {
    $customerType = 'flat_lease';
} else {
    $customerType = 'house_rent_requirement';
}

// Build a plain, human-readable "key: value" (one per line) version of
// $requirementDetails for the Property.description field, instead of dumping
// raw JSON into it. requirement_details itself stays JSON — the Lead detail
// popup parses and formats that separately.
function formatDetailsAsLines(array $details): string {
    $flat = [];
    foreach ($details as $k => $v) {
        if (is_array($v)) {
            foreach ($v as $k2 => $v2) {
                if ($v2 !== null && $v2 !== '') $flat[$k2] = $v2;
            }
        } elseif ($v !== null && $v !== '') {
            $flat[$k] = $v;
        }
    }
    $lines = [];
    foreach ($flat as $k => $v) {
        $label = ucwords(str_replace('_', ' ', $k));
        $lines[] = $label . ': ' . $v;
    }
    return implode("\n", $lines);
}

// ================= Step 6: declaration =================
if (($_POST['declaration'] ?? '') !== 'true' && ($_POST['declaration'] ?? '') !== '1') {
    jsonResponse(false, null, 'Please accept the declaration before submitting.', 422);
}

// ================= File upload handling =================
$ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'pdf'];
$MAX_DOC_SIZE = 10 * 1024 * 1024; // 10MB, matches the booking form's stated limit

function saveUploadedFile(array $file, string $destDir): ?array {
    global $ALLOWED_EXT, $MAX_DOC_SIZE;
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) return ['error' => 'Upload failed.'];
    if ($file['size'] > $MAX_DOC_SIZE) return ['error' => 'File is larger than 10MB.'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $ALLOWED_EXT, true)) return ['error' => 'Only JPG, PNG or PDF files are allowed.'];
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);
    $safeName = bin2hex(random_bytes(8)) . '.' . $ext;
    $destPath = $destDir . '/' . $safeName;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        $reason = error_get_last()['message'] ?? 'unknown reason';
        error_log("saveUploadedFile move_uploaded_file failed for $destPath: $reason");
        return ['error' => 'Could not save uploaded file.'];
    }
    return ['path' => $safeName, 'original_name' => $file['name']];
}

// Validate document uploads up-front (before writing anything to the DB)
$documentFiles = $_FILES['documents'] ?? null; // expected: documents[doc_key] => file
$paymentFile = $_FILES['paymentScreenshot'] ?? null;
if ($documentFiles && isset($documentFiles['error']) && is_array($documentFiles['error'])) {
    foreach ($documentFiles['error'] as $docKey => $err) {
        if ($err === UPLOAD_ERR_OK && $documentFiles['size'][$docKey] > $MAX_DOC_SIZE) {
            jsonResponse(false, null, "Document \"$docKey\" is larger than 10MB.", 422);
        }
    }
}

// ================= Persist =================
try {
    $db->beginTransaction();

    $stmt = $db->prepare("INSERT INTO leads
        (lead_code, client_name, client_mobile, whatsapp_number, client_email, address, city, state, country, customer_type,
         dob, gender, occupation, religion, caste, aadhaar_number, pan_number, district, pincode,
         requirement, property_type, budget, preferred_location, requirement_details, source_channel,
         status, source, created_by, created_at)
        VALUES ('TEMP',?,?,?,?,?,?,?,'India',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'new','Website Booking',NULL,NOW())");
    $stmt->execute([
        $clientName, $clientMobile, $whatsapp, $clientEmail, $address, $city, $state, $customerType,
        $dob, $gender, $occupation, $religion, $caste, $aadhaar, $pan, $district, $pincode,
        $requirement, $propertyType, $budget, $preferredLocation, json_encode($requirementDetails), 'book.html',
    ]);
    $leadId = (int)$db->lastInsertId();
    $leadCodeForNote = generateCode('LEAD', $leadId);
    $db->prepare("UPDATE leads SET lead_code = ? WHERE id = ?")->execute([$leadCodeForNote, $leadId]);

    $destDir = rtrim(UPLOAD_DIR, '/') . '/leads/' . $leadId;

    // Documents (dynamic per service type, e.g. documents[up_aadhaar])
    if ($documentFiles && isset($documentFiles['error']) && is_array($documentFiles['error'])) {
        $docStmt = $db->prepare("INSERT INTO lead_documents (lead_id, doc_type, original_name, file_path) VALUES (?,?,?,?)");
        foreach ($documentFiles['error'] as $docKey => $err) {
            $single = [
                'name' => $documentFiles['name'][$docKey],
                'type' => $documentFiles['type'][$docKey],
                'tmp_name' => $documentFiles['tmp_name'][$docKey],
                'error' => $documentFiles['error'][$docKey],
                'size' => $documentFiles['size'][$docKey],
            ];
            $saved = saveUploadedFile($single, $destDir);
            if ($saved && !isset($saved['error'])) {
                $docStmt->execute([$leadId, $docKey, $saved['original_name'], 'leads/' . $leadId . '/' . $saved['path']]);
            }
        }
    }

    // Payment proof + amount
    $amount = $ADVANCE_FEE[$serviceType] ?? null;
    $screenshotPath = null;
    if ($paymentFile) {
        $saved = saveUploadedFile($paymentFile, $destDir);
        if ($saved && !isset($saved['error'])) {
            $screenshotPath = 'leads/' . $leadId . '/' . $saved['path'];
        } elseif ($saved && isset($saved['error'])) {
            throw new RuntimeException($saved['error']);
        } else {
            throw new RuntimeException('Payment screenshot is required.');
        }
    } else {
        throw new RuntimeException('Payment screenshot is required.');
    }
    $db->prepare("INSERT INTO lead_payments (lead_id, amount, method, screenshot_path, status) VALUES (?,?,?,?,'pending')")
        ->execute([$leadId, $amount, 'UPI/Card', $screenshotPath]);

    logActivity('lead', $leadId, 'created', null, null, 'via website booking form', null);

    // ================= Also create a matching Property record =================
    // Mirrors this lead onto the Buy/Sale/Rental/Lease admin pages, which read
    // from `properties` (not `leads`). $requirement is already one of
    // buy/sale/rental/lease, matching properties.category exactly.
    //
    // status starts as 'payment_verification' (not 'active') and stays
    // hidden from the Buy/Sale/Rental admin pages — same as the lead
    // itself — until the advance payment is checked on the Payment
    // Verification page. This is a distinct status from the ordinary
    // user-selectable 'pending' business status, on purpose.
    //   verified -> api/leads/verify-payment.php flips this row to 'active'
    //               and sets subscription_paid = 1
    //   rejected -> api/leads/verify-payment.php deletes this row
    // lead_id is what lets that verify step find this row again.
    //
    // is_stock: only the "Property" step-1 choice (customer GIVING their own
    // property, with photos collected below) is inventory we should ever
    // show on the public "Search Property" listing (property-list.php only
    // returns is_stock = 1 rows). Buy/Sell/Rental/Lease/TenantRental are the
    // customer's own requirement — never publicly listed stock — so they
    // must stay is_stock = 0 even though $requirement can equal the same
    // 'sale'/'rental'/'lease' values as a Property submission.
    $isStock = ($serviceType === 'Property') ? 1 : 0;
    $propStmt = $db->prepare("INSERT INTO properties
        (property_code, category, is_stock, client_name, client_mobile, client_email, property_type,
         address, city, budget, description, notes, status, assigned_to, created_by, lead_id, source_channel, created_at)
        VALUES ('TEMP',?,?,?,?,?,?,?,?,?,?,?, 'payment_verification', NULL, NULL, ?, 'book.html', NOW())");
    $propStmt->execute([
        $requirement, $isStock, $clientName, $clientMobile, $clientEmail, $propertyType ?: 'Not specified',
        $address ?: $preferredLocation ?: '-', $city ?: $preferredCity, $budget,
        formatDetailsAsLines($requirementDetails), 'Auto-created from website booking (Lead ' . ($leadCodeForNote ?? '') . ')',
        $leadId,
    ]);
    $propertyId = (int)$db->lastInsertId();
    $db->prepare("UPDATE properties SET property_code = ? WHERE id = ?")
        ->execute([generateCode('PROP', $propertyId), $propertyId]);

    // For the "Property" (give my own property) flow, also save the
    // photos/video collected in Step 4 — this is what feeds the public
    // "Search Property" listings on the website once it's connected to
    // a real listing endpoint.
    if ($serviceType === 'Property') {
        $mediaInsert = $db->prepare("INSERT INTO property_images (property_id, file_path, media_type, original_name) VALUES (?,?,?,?)");

        $imageFiles = $_FILES['propertyImages'] ?? null;
        if ($imageFiles && isset($imageFiles['error']) && is_array($imageFiles['error'])) {
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
                    $mediaInsert->execute([$propertyId, $saved['path'], 'image', $saved['original_name']]);
                }
            }
        }

        $videoFile = $_FILES['propertyVideo'] ?? null;
        if ($videoFile) {
            $saved = savePropertyMediaFile($videoFile, $propertyId, 'video');
            if ($saved && !isset($saved['error'])) {
                $mediaInsert->execute([$propertyId, $saved['path'], 'video', $saved['original_name']]);
            }
        }
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    error_log('Booking intake failed: ' . $e->getMessage());
    jsonResponse(false, null, $e->getMessage() ?: 'Could not submit registration. Please try again.', 422);
}

$leadCode = $db->prepare("SELECT lead_code FROM leads WHERE id = ?");
$leadCode->execute([$leadId]);
$code = $leadCode->fetchColumn();

jsonResponse(true, ['lead_id' => $leadId, 'reference' => $code], 'Registration submitted successfully.');