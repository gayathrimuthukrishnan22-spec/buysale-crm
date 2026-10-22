<?php
require_once __DIR__ . '/../config/database.php';

/** Escape output for safe HTML rendering (XSS protection) */
function e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** Basic input sanitizer for strings coming from $_POST / $_GET */
function clean(?string $value): string {
    return trim($value ?? '');
}

/**
 * Save one uploaded image/video/audio file for a property/stock entry
 * under UPLOAD_DIR/properties/{propertyId}/ with a random safe filename.
 * Returns ['path' => ..., 'original_name' => ...] on success, or
 * ['error' => '...'] on failure. Caller decides what to do with either.
 */
function savePropertyMediaFile(array $file, int $propertyId, string $mediaType): ?array {
    $ALLOWED_EXT = [
        'image' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
        'video' => ['mp4', 'mov', 'webm', 'mkv', '3gp'],
        'audio' => ['mp3', 'wav', 'm4a', 'ogg', 'aac'],
    ];
    $MAX_SIZE = [
        'image' => MAX_UPLOAD_SIZE,
        'video' => MAX_VIDEO_UPLOAD_SIZE,
        'audio' => MAX_AUDIO_UPLOAD_SIZE,
    ];
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) return ['error' => 'Upload failed.'];
    if (!isset($ALLOWED_EXT[$mediaType])) return ['error' => 'Unknown media type.'];
    $maxSize = $MAX_SIZE[$mediaType];
    if ($file['size'] > $maxSize) {
        return ['error' => ucfirst($mediaType) . ' file is larger than ' . (int)($maxSize / (1024 * 1024)) . 'MB.'];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $ALLOWED_EXT[$mediaType], true)) {
        return ['error' => 'Unsupported ' . $mediaType . ' file type: .' . $ext];
    }
    $destDir = rtrim(UPLOAD_DIR, '/') . '/properties/' . $propertyId;
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);
    $safeName = bin2hex(random_bytes(8)) . '.' . $ext;
    $destPath = $destDir . '/' . $safeName;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) return ['error' => 'Could not save uploaded file.'];
    return ['path' => 'properties/' . $propertyId . '/' . $safeName, 'original_name' => $file['name']];
}

/** Normalize a mobile number for storage: strip spaces/dashes, keep leading + if present */
function normalizeMobileForStorage(string $mobile): string {
    return preg_replace('/[^0-9+]/', '', $mobile);
}

/** Validate Indian-style mobile number (10 digits, optionally +91) */
function isValidMobile(string $mobile): bool {
    $mobile = preg_replace('/[^0-9+]/', '', $mobile);
    return (bool) preg_match('/^(\+?91)?[6-9]\d{9}$/', $mobile);
}

/** Normalize mobile number to bare 10-digit (or with country code) for tel:/wa.me links */
function normalizeMobileForLink(string $mobile): string {
    $digits = preg_replace('/[^0-9]/', '', $mobile);
    if (strlen($digits) === 10) {
        $digits = '91' . $digits; // default India country code for WhatsApp deep links
    }
    return $digits;
}

function generateCode(string $prefix, int $id): string {
    return $prefix . '-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
}

/**
 * Insert a row into activity_log for audit trail / change log.
 */
function logActivity(string $entityType, int $entityId, string $action,
                      ?string $field = null, ?string $oldValue = null, ?string $newValue = null,
                      ?int $performedBy = null): void {
    try {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO activity_log
            (entity_type, entity_id, action, field_changed, old_value, new_value, performed_by, ip_address, created_at)
            VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $entityType, $entityId, $action, $field, $oldValue, $newValue,
            $performedBy, $_SERVER['REMOTE_ADDR'] ?? null,
            // Use PHP's own clock (Asia/Kolkata, set in config.php) instead of MySQL's NOW(),
            // which runs on the DB server's own timezone (often UTC) and was making history
            // timestamps show the wrong time / wrong "ago" value.
            date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        error_log('Activity log failed: ' . $e->getMessage());
    }
}

/** Standard JSON API response helper */
function jsonResponse(bool $success, $data = null, string $message = '', int $httpCode = 200): void {
    http_response_code($httpCode);
    header('Content-Type: application/json');
    $payload = ['success' => $success, 'message' => $message, 'data' => $data];
    // JSON_INVALID_UTF8_SUBSTITUTE keeps json_encode() from returning false
    // (=> an empty response body, which breaks the frontend's r.json() call)
    // when a text field contains malformed/invalid UTF-8 bytes.
    $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        error_log('jsonResponse: json_encode failed - ' . json_last_error_msg());
        http_response_code(500);
        $json = json_encode(['success' => false, 'message' => 'Server error while building response.', 'data' => null]);
    }
    echo $json;
    exit;
}

/** Require POST method + valid CSRF token for state-changing API calls */
function requireValidRequest(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, null, 'Invalid request method.', 405);
    }
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!verifyCsrf($token)) {
        jsonResponse(false, null, 'Invalid or expired security token. Please refresh the page.', 419);
    }
}

function formatMoney($amount): string {
    if ($amount === null || $amount === '') return '-';
    return '₹' . number_format((float)$amount, 0);
}

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('d M Y', strtotime($datetime));
}