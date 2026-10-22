<?php
/**
 * Database Connection (PDO + MySQLi safe wrapper)
 * config/database.php — Holds DB credentials and getDB(), the single function
 * every page uses to get a PDO connection.
 * Update credentials before deployment.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'propertycrm');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4'); // supports full Unicode, incl. emoji

/**
 * Returns a shared PDO connection, creating it on first call and reusing it
 * afterwards (the `static $pdo` keeps one connection per request instead of
 * reconnecting every time getDB() is called).
 */
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // throw exceptions on DB errors instead of silent failures
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // fetch() / fetchAll() return associative arrays
            PDO::ATTR_EMULATE_PREPARES   => false, // real prepared statements = SQL injection protection
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Never leak DB details to the browser; log server-side and return a generic error.
            error_log('DB Connection failed: ' . $e->getMessage());
            http_response_code(500);
            die(json_encode(['success' => false, 'message' => 'Database connection failed.']));
        }
    }
    return $pdo;
}
