<?php
/**
 * Database connection. Provides $conn for every other PHP file.
 *
 * Credentials are read from environment variables (DB_HOST, DB_PORT, DB_NAME,
 * DB_USER, DB_PASS) and fall back to XAMPP defaults for local development.
 * See HOSTING.md for setting them in production.
 */

// Errors are logged, never printed into a page.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

/**
 * Read a config value from the environment, falling back to a default.
 * Checks getenv(), $_ENV and $_SERVER as different Apache setups expose
 * variables through different channels.
 */
function hbs_env(string $key, string $default = ''): string
{
    $value = getenv($key);

    if ($value === false && isset($_ENV[$key])) {
        $value = $_ENV[$key];
    }

    if ($value === false && isset($_SERVER[$key])) {
        $value = $_SERVER[$key];
    }

    if ($value === false || $value === '') {
        return $default;
    }

    return (string) $value;
}

// XAMPP factory defaults - local development only. Any deployment beyond
// localhost must supply real credentials through the environment.
$dbHost = hbs_env('DB_HOST', 'localhost');
$dbPort = (int) hbs_env('DB_PORT', '3306');
$dbName = hbs_env('DB_NAME', 'hotel_booking');
$dbUser = hbs_env('DB_USER', 'root');
$dbPass = hbs_env('DB_PASS', '');

// Make mysqli throw instead of returning false, so a database problem can
// never be silently ignored by calling code.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/** @var mysqli|null $conn Shared database connection used by the whole app. */
$conn = null;

try {
    $conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);

    // utf8mb4 matches the schema and closes a multi-byte SQL-injection vector.
    $conn->set_charset('utf8mb4');

} catch (mysqli_sql_exception $e) {

    // Full detail to the log (no credentials), generic message to the visitor.
    error_log(sprintf(
        '[Hotel Booking System] Database connection failed. host=%s port=%d db=%s error=%s',
        $dbHost,
        $dbPort,
        $dbName,
        $e->getMessage()
    ));

    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
    }

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
       . '<title>Service Unavailable</title></head><body>'
       . '<h1>Service temporarily unavailable</h1>'
       . '<p>We are unable to process your request at the moment. '
       . 'Please try again shortly.</p>'
       . '<p><a href="index.html">Return to the home page</a></p>'
       . '</body></html>';

    exit;
}
