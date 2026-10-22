<?php
/**
 * Global App Configuration
 * config/config.php — App-wide constants and PHP runtime settings, loaded on
 * every request (via includes/auth.php or similar). Edit this file to change
 * app name, session timeout, upload limits, deployment path, and debug mode.
 */

// ---- Security / Session ----
define('APP_NAME', 'Property CRM');
define('SESSION_LIFETIME', 60 * 60 * 4);   // 4 hours idle timeout
// BASE_URL used to be hardcoded to '/PropertyCRM'. That only worked when the
// app happened to live in a folder called "PropertyCRM" right under the web
// root (e.g. localhost/PropertyCRM). On a real host the app is usually at a
// different path (often the domain root), so every link/image built from
// BASE_URL (uploaded documents, the description modal, CSS/JS files, etc.)
// broke — it worked on localhost and 404'd on the live site.
// Instead, detect the app's real URL path automatically from the server, so
// it's correct wherever the folder is deployed and never needs manual edits.
$__appRoot = realpath(__DIR__ . '/..');
$__docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
$__basePath = '';
if ($__appRoot && $__docRoot && strpos($__appRoot, $__docRoot) === 0) {
    $__basePath = substr($__appRoot, strlen($__docRoot));
    $__basePath = str_replace('\\', '/', $__basePath); // Windows paths use backslashes
    $__basePath = rtrim($__basePath, '/');
}
define('BASE_URL', $__basePath); // e.g. '' at domain root, '/PropertyCRM' on localhost, '/crm' etc. — detected automatically
define('UPLOAD_DIR', __DIR__ . '/../uploads/');   // filesystem path where uploaded files are stored
define('UPLOAD_URL', BASE_URL . '/uploads/');     // public URL used to display/link uploaded files
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB per image
define('MAX_VIDEO_UPLOAD_SIZE', 50 * 1024 * 1024); // 50MB per video
define('MAX_AUDIO_UPLOAD_SIZE', 15 * 1024 * 1024); // 15MB per audio file

// ---- Client subscription (Find Matching Stock) ----
// A Buy/Rental/Lease/Tenant-Rental client pays SUBSCRIPTION_AMOUNT to get
// SUBSCRIPTION_MATCH_LIMIT stock "Connect"s. Once used up, the client
// auto-becomes a 'dead' lead until the subscription is renewed.
define('SUBSCRIPTION_AMOUNT', 1000);
define('SUBSCRIPTION_MATCH_LIMIT', 2);

// ---- Secure session cookie settings (must run before session_start) ----
// Only run once per request; avoids "session already started" warnings if
// this file is ever included twice.
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', 1);   // rejects uninitialized session IDs (helps prevent session fixation)
    ini_set('session.cookie_httponly', 1);   // blocks JS access to the session cookie (helps prevent XSS cookie theft)
    ini_set('session.cookie_samesite', 'Lax'); // limits the cookie being sent on cross-site requests (CSRF mitigation)
    // ini_set('session.cookie_secure', 1); // enable once served over HTTPS
    session_set_cookie_params(SESSION_LIFETIME); // cookie/session expires after SESSION_LIFETIME seconds of inactivity
    session_start();
}

// ---- Error display (turn OFF in production) ----
define('APP_DEBUG', false);
if (APP_DEBUG) {
    // Development: show errors on-screen for easier debugging.
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    // Production: hide errors from visitors (they should go to a log instead).
    ini_set('display_errors', 0);
    error_reporting(0);
}

date_default_timezone_set('Asia/Kolkata'); // all date()/time() calls use this timezone
