<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireValidRequest();

$db = getDB();
$id = (int)($_POST['id'] ?? 0);

// Gate on the correct permission depending on create vs edit
requirePermissionApi($id > 0 ? 'leads.edit' : 'leads.create');

$VALID_STATUSES = ['new','contacted','follow_up','meeting_scheduled','site_visit','negotiation',
    'documentation','booking','deal_closed','cancelled','lost'];
$VALID_CANCEL_REASONS = ['customer_rejected','budget_issue','competitor_won','property_not_available',
    'loan_rejected','employee_delay','no_response','price_issue','documentation_issue','other'];

// A Lead saved here also keeps a linked copy on the matching Buy/Sale/Rental/Lease
// page (the `properties` table), the same way a website booking does. The link is
// leads.property_id -> properties.id (already in the schema, previously unused).
function mapLeadStatusToPropertyStatus(string $leadStatus): string {
    if ($leadStatus === 'deal_closed') return 'closed';
    if (in_array($leadStatus, ['cancelled', 'lost'], true)) return 'cancelled';
    return 'active';
}

/**
 * Create or update the properties row linked to this lead so the Buy/Sale/Rental/Lease
 * pages always reflect the lead's latest details. Returns the property id (or null if
 * this couldn't be synced, e.g. an older DB that predates a needed column) without
 * ever failing the lead save itself.
 */
function syncLeadToProperty(PDO $db, int $leadId, ?int $existingPropertyId, array $d): ?int {
    try {
        $propStatus = mapLeadStatusToPropertyStatus($d['status']);
        $addr = $d['address'] ?: ($d['location'] ?: '-');
        $propType = $d['propertyType'] ?: 'Not specified';

        if ($existingPropertyId) {
            $chk = $db->prepare("SELECT id FROM properties WHERE id = ?");
            $chk->execute([$existingPropertyId]);
            if (!$chk->fetch()) $existingPropertyId = null; // was deleted separately; fall through to re-create
        }

        if ($existingPropertyId) {
            $stmt = $db->prepare("UPDATE properties SET
                category=?, client_name=?, client_mobile=?, client_email=?, property_type=?,
                address=?, city=?, budget=?, assigned_to=?, status=?, notes=?, updated_at=NOW()
                WHERE id=?");
            $stmt->execute([
                $d['requirement'], $d['clientName'], $d['clientMobile'], $d['clientEmail'], $propType,
                $addr, $d['city'], $d['budget'], $d['assignedTo'], $propStatus, $d['notes'], $existingPropertyId,
            ]);
            return $existingPropertyId;
        }

        $stmt = $db->prepare("INSERT INTO properties
            (property_code, category, client_name, client_mobile, client_email, property_type,
             address, city, budget, notes, status, assigned_to, created_by, source_channel, created_at)
            VALUES ('TEMP',?,?,?,?,?,?,?,?,?,?,?,?, 'crm_lead', NOW())");
        $stmt->execute([
            $d['requirement'], $d['clientName'], $d['clientMobile'], $d['clientEmail'], $propType,
            $addr, $d['city'], $d['budget'], $d['notes'], $propStatus, $d['assignedTo'], currentUserId(),
        ]);
        $newPropId = (int)$db->lastInsertId();
        $db->prepare("UPDATE properties SET property_code = ? WHERE id = ?")->execute([generateCode('PROP', $newPropId), $newPropId]);
        $db->prepare("UPDATE leads SET property_id = ? WHERE id = ?")->execute([$newPropId, $leadId]);
        return $newPropId;
    } catch (PDOException $e) {
        // Don't let a sync problem (e.g. a column from an older DB) fail the lead save.
        error_log('Lead->Property sync skipped: ' . $e->getMessage());
        return $existingPropertyId;
    }
}

// ---- Contact ----
$clientName = clean($_POST['client_name'] ?? '');
$clientMobile = normalizeMobileForStorage(clean($_POST['client_mobile'] ?? ''));
$whatsapp = clean($_POST['whatsapp_number'] ?? '');
$whatsapp = $whatsapp !== '' ? normalizeMobileForStorage($whatsapp) : null;
$clientEmail = clean($_POST['client_email'] ?? '') ?: null;
$address = clean($_POST['address'] ?? '') ?: null;
$city = clean($_POST['city'] ?? '') ?: null;
$state = clean($_POST['state'] ?? '') ?: null;
$country = clean($_POST['country'] ?? '') ?: 'India';
$customerType = clean($_POST['customer_type'] ?? 'house_rent_requirement');
if (!in_array($customerType, ['house_rent_requirement','commercial_space_requirement','flat_lease','flat_selling','land_selling'], true)) $customerType = 'house_rent_requirement';

// ---- Requirement ----
$requirement = clean($_POST['requirement'] ?? '');
$propertyType = clean($_POST['property_type'] ?? '') ?: null;
$budget = !empty($_POST['budget']) ? (float)$_POST['budget'] : null;
$location = clean($_POST['preferred_location'] ?? '') ?: null;
$projectId = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;

// ---- Assignment / status ----
$assignedTo = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
$status = clean($_POST['status'] ?? 'new');
$source = clean($_POST['source'] ?? '') ?: null;
$notes = clean($_POST['notes'] ?? '') ?: null;

// ---- Deal closure / revenue (raw input; recomputed authoritatively below) ----
$propertyValue = !empty($_POST['property_value']) ? (float)$_POST['property_value'] : null;
// Commission % can only be set/changed by Admin (super_admin/admin). Non-admin users
// (managers, sales executives, etc.) can still close deals, but the rate they submit
// is ignored server-side -- we keep whatever rate an admin already set on the lead.
$commissionRateInput = isset($_POST['commission_rate']) && $_POST['commission_rate'] !== '' ? (float)$_POST['commission_rate'] : null;
$commissionRate = isAdmin() ? $commissionRateInput : null; // resolved to existing value for updates below
$employeeIncentive = !empty($_POST['employee_incentive']) ? (float)$_POST['employee_incentive'] : null;
$monthlyRent = !empty($_POST['monthly_rent']) ? (float)$_POST['monthly_rent'] : null;
$securityDeposit = !empty($_POST['security_deposit']) ? (float)$_POST['security_deposit'] : null;
$leaseDuration = !empty($_POST['lease_duration_months']) ? (int)$_POST['lease_duration_months'] : null;
$ownerName = clean($_POST['owner_name'] ?? '') ?: null;
$ownerMobile = clean($_POST['owner_mobile'] ?? '') ?: null;

// ---- Cancelled / lost ----
$cancelledReason = clean($_POST['cancelled_reason'] ?? '') ?: null;
$cancelledNotes = clean($_POST['cancelled_reason_notes'] ?? '') ?: null;

// ================= Validation =================
if ($clientName === '' || $clientMobile === '') {
    jsonResponse(false, null, 'Client name and mobile number are required.', 422);
}
if (!isValidMobile($clientMobile)) {
    jsonResponse(false, null, 'Please enter a valid 10-digit mobile number.', 422);
}
if ($whatsapp !== null && !isValidMobile($whatsapp)) {
    jsonResponse(false, null, 'Please enter a valid WhatsApp number.', 422);
}
if (!in_array($requirement, ['buy','sale','rental','lease'], true)) {
    jsonResponse(false, null, 'Invalid requirement type.', 422);
}
if (!in_array($status, $VALID_STATUSES, true)) {
    jsonResponse(false, null, 'Invalid status.', 422);
}
if (in_array($status, ['cancelled', 'lost'], true)) {
    if (!userCan('deals.cancel')) {
        jsonResponse(false, null, 'You do not have permission to cancel or mark leads as lost.', 403);
    }
    if (!$cancelledReason || !in_array($cancelledReason, $VALID_CANCEL_REASONS, true)) {
        jsonResponse(false, null, 'Please select a reason for cancelling/losing this lead.', 422);
    }
} else {
    // Only persist a cancel reason when the lead is actually cancelled/lost
    $cancelledReason = null;
    $cancelledNotes = null;
}
if ($status === 'deal_closed' && !userCan('deals.close')) {
    jsonResponse(false, null, 'You do not have permission to close deals.', 403);
}

// Users without assign rights can't hand a lead to someone else
if (!userCan('leads.assign')) {
    $assignedTo = currentUserId();
}

// Non-admin: keep whatever commission rate an admin already set on this lead (if editing).
// A brand-new lead created by a non-admin simply has no rate yet until an admin sets one.
if (!isAdmin() && $id > 0) {
    $rateStmt = $db->prepare("SELECT commission_rate FROM leads WHERE id = ?");
    $rateStmt->execute([$id]);
    $existingRateRow = $rateStmt->fetch();
    $commissionRate = $existingRateRow ? $existingRateRow['commission_rate'] : null;
}

// ================= Server-authoritative revenue calculation =================
// Formula (documented): commission_amount = base * commission_rate / 100
//   base = monthly_rent for rental leads, property_value otherwise.
// expected_revenue = commission_amount (set any time during the pipeline)
// actual_revenue / actual_profit / closed_at are only finalized when status = deal_closed.
// profit = revenue - employee_incentive (company margin after paying the closing employee).
$commissionAmount = null;
$expectedRevenue = null;
$expectedProfit = null;
$actualRevenue = null;
$actualProfit = null;
$closedAt = null;

if (userCan('deals.close')) {
    $base = $requirement === 'rental' ? ($monthlyRent ?? 0) : ($propertyValue ?? 0);
    $rate = $commissionRate ?? 0;
    $commissionAmount = round($base * ($rate / 100), 2);
    $expectedRevenue = $commissionAmount;
    $expectedProfit = round($expectedRevenue - ($employeeIncentive ?? 0), 2);

    if ($status === 'deal_closed') {
        $actualRevenue = $commissionAmount;
        $actualProfit = $expectedProfit;
        $closedAt = date('Y-m-d H:i:s');
    }
}

if ($id > 0) {
    // ---- UPDATE ----
    try {
    $existing = $db->prepare("SELECT * FROM leads WHERE id = ?");
    $existing->execute([$id]);
    $old = $existing->fetch();
    if (!$old) jsonResponse(false, null, 'Lead not found.', 404);

    if (!userCan('leads.view_all') && (int)$old['assigned_to'] !== currentUserId()) {
        jsonResponse(false, null, 'You are not authorized to edit this lead.', 403);
    }
    if (!userCan('leads.edit')) {
        jsonResponse(false, null, 'You do not have permission to edit leads.', 403);
    }

    // Preserve closed_at once set, even if the record is edited again later
    if ($old['closed_at'] && !$closedAt) $closedAt = $old['closed_at'];
    // Preserve actual_revenue/profit once a deal has been closed, unless recalculated above
    if ($old['status'] === 'deal_closed' && $status === 'deal_closed' && $actualRevenue === null) {
        $actualRevenue = $old['actual_revenue'];
        $actualProfit = $old['actual_profit'];
    }

    $stmt = $db->prepare("UPDATE leads SET
        client_name=?, client_mobile=?, whatsapp_number=?, client_email=?, address=?, city=?, state=?, country=?, customer_type=?,
        requirement=?, property_type=?, budget=?, preferred_location=?, project_id=?,
        property_value=?, commission_rate=?, commission_amount=?, expected_revenue=?, actual_revenue=?,
        expected_profit=?, actual_profit=?, employee_incentive=?, closed_at=?,
        monthly_rent=?, security_deposit=?, lease_duration_months=?, owner_name=?, owner_mobile=?,
        cancelled_reason=?, cancelled_reason_notes=?,
        assigned_to=?, status=?, source=?, notes=?, updated_at=NOW()
        WHERE id=?");
    $stmt->execute([
        $clientName,$clientMobile,$whatsapp,$clientEmail,$address,$city,$state,$country,$customerType,
        $requirement,$propertyType,$budget,$location,$projectId,
        $propertyValue,$commissionRate,$commissionAmount,$expectedRevenue,$actualRevenue,
        $expectedProfit,$actualProfit,$employeeIncentive,$closedAt,
        $monthlyRent,$securityDeposit,$leaseDuration,$ownerName,$ownerMobile,
        $cancelledReason,$cancelledNotes,
        $assignedTo,$status,$source,$notes,$id
    ]);

    if ($old['status'] !== $status) {
        logActivity('lead', $id, 'status_changed', 'status', $old['status'], $status, currentUserId());
        if ($status === 'deal_closed') {
            logActivity('lead', $id, 'deal_closed', 'actual_revenue', null, (string)$actualRevenue, currentUserId());
        }
    }
    if ((int)$old['assigned_to'] !== (int)$assignedTo) {
        logActivity('lead', $id, 'reassigned', 'assigned_to', (string)$old['assigned_to'], (string)$assignedTo, currentUserId());
    }
    logActivity('lead', $id, 'updated', null, null, null, currentUserId());

    syncLeadToProperty($db, $id, $old['property_id'] ? (int)$old['property_id'] : null, [
        'requirement' => $requirement, 'clientName' => $clientName, 'clientMobile' => $clientMobile,
        'clientEmail' => $clientEmail, 'propertyType' => $propertyType, 'address' => $address,
        'location' => $location, 'city' => $city, 'budget' => $budget, 'assignedTo' => $assignedTo,
        'status' => $status, 'notes' => $notes,
    ]);

    jsonResponse(true, ['id' => $id], 'Lead updated successfully.');
    } catch (PDOException $e) {
        error_log('Lead update failed: ' . $e->getMessage());
        jsonResponse(false, null, 'Could not save the lead. This usually means the database needs a migration script run (see /database/migration_v4_customer_type.sql and migration_v5_website_booking.sql). Contact your administrator.', 500);
    }
} else {
    // ---- CREATE ----
    if (!userCan('leads.create')) {
        jsonResponse(false, null, 'You do not have permission to create leads.', 403);
    }
    try {
    $stmt = $db->prepare("INSERT INTO leads
        (lead_code, client_name, client_mobile, whatsapp_number, client_email, address, city, state, country, customer_type,
         requirement, property_type, budget, preferred_location, project_id,
         property_value, commission_rate, commission_amount, expected_revenue, actual_revenue,
         expected_profit, actual_profit, employee_incentive, closed_at,
         monthly_rent, security_deposit, lease_duration_months, owner_name, owner_mobile,
         cancelled_reason, cancelled_reason_notes,
         assigned_to, status, source, notes, created_by, created_at)
        VALUES ('TEMP',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
    $stmt->execute([
        $clientName,$clientMobile,$whatsapp,$clientEmail,$address,$city,$state,$country,$customerType,
        $requirement,$propertyType,$budget,$location,$projectId,
        $propertyValue,$commissionRate,$commissionAmount,$expectedRevenue,$actualRevenue,
        $expectedProfit,$actualProfit,$employeeIncentive,$closedAt,
        $monthlyRent,$securityDeposit,$leaseDuration,$ownerName,$ownerMobile,
        $cancelledReason,$cancelledNotes,
        $assignedTo,$status,$source,$notes,currentUserId()
    ]);
    $newId = (int)$db->lastInsertId();
    $db->prepare("UPDATE leads SET lead_code = ? WHERE id = ?")->execute([generateCode('LEAD', $newId), $newId]);

    logActivity('lead', $newId, 'created', null, null, null, currentUserId());

    syncLeadToProperty($db, $newId, null, [
        'requirement' => $requirement, 'clientName' => $clientName, 'clientMobile' => $clientMobile,
        'clientEmail' => $clientEmail, 'propertyType' => $propertyType, 'address' => $address,
        'location' => $location, 'city' => $city, 'budget' => $budget, 'assignedTo' => $assignedTo,
        'status' => $status, 'notes' => $notes,
    ]);

    jsonResponse(true, ['id' => $newId], 'Lead created successfully.');
    } catch (PDOException $e) {
        error_log('Lead create failed: ' . $e->getMessage());
        jsonResponse(false, null, 'Could not save the lead. This usually means the database needs a migration script run (see /database/migration_v4_customer_type.sql and migration_v5_website_booking.sql). Contact your administrator.', 500);
    }
}
