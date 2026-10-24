<?php
// =============================================
// db.php — Database connection
//
// Credentials are NOT stored in this file. They are read from, in order:
//   1. Environment variables: DB_HOST, DB_USER, DB_PASS, DB_NAME
//   2. A config file kept OUTSIDE the web root (see config.sample.php):
//        - path in the DEMO_COACHES_CONFIG environment variable, or
//        - two folders above this file:  <this folder>/../../demo_coaches_config.php
//
// Local development convenience: if nothing is configured AND the request comes from
// this same machine (or the command line), the XAMPP defaults (root / no password) are used.
// On a real server with nothing configured, the site refuses to start instead.
// =============================================

// Report DB errors through return values (and our own handling), never as raw PHP fatals
mysqli_report(MYSQLI_REPORT_OFF);

$cfg = [];
$cfg_file = getenv('DEMO_COACHES_CONFIG') ?: dirname(__DIR__, 2) . '/demo_coaches_config.php';
if (!is_file($cfg_file) && is_file(__DIR__ . '/demo_coaches_config.php')) {
    $cfg_file = __DIR__ . '/demo_coaches_config.php';   // shared-hosting fallback (protected by .htaccess, ignored by Git)
}
if (is_file($cfg_file)) {
    $loaded = require $cfg_file;
    if (is_array($loaded)) $cfg = $loaded;
}

$host     = getenv('DB_HOST') ?: ($cfg['host']     ?? null);
$user     = getenv('DB_USER') ?: ($cfg['user']     ?? null);
$password = getenv('DB_PASS') !== false ? getenv('DB_PASS') : ($cfg['password'] ?? null);
$database = getenv('DB_NAME') ?: ($cfg['database'] ?? null);

if ($host === null || $user === null || $password === null || $database === null) {
    $is_local = PHP_SAPI === 'cli'
        || in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    if ($is_local) {
        $host = 'localhost'; $user = 'root'; $password = ''; $database = 'demo_coaches';   // XAMPP defaults (local only)
    } else {
        error_log('DB config missing: set DB_* environment variables or create demo_coaches_config.php outside the web root.');
        http_response_code(503);
        die("<div style='font-family:sans-serif;background:#ffebee;border-left:5px solid red;padding:20px;margin:30px;border-radius:8px;max-width:600px;'>
            <h2>Service Temporarily Unavailable</h2>
            <p>We are unable to process your request right now. Please try again later or contact support.</p></div>");
    }
}

$conn = @mysqli_connect($host, $user, $password, $database);

if (!$conn) {
    error_log("DB connection failed: " . mysqli_connect_error());
    http_response_code(503);
    die("
    <div style='font-family:sans-serif;background:#ffebee;border-left:5px solid red;
                padding:20px;margin:30px;border-radius:8px;max-width:600px;'>
        <h2>Service Temporarily Unavailable</h2>
        <p>We are unable to process your request right now. Please try again later or contact support.</p>
    </div>");
}

// Enforce strict charset to prevent encoding-based injection
mysqli_set_charset($conn, 'utf8mb4');
