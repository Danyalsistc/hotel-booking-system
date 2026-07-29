<?php
/**
 * ===========================================================================
 *  Hotel Booking System - Database Connection
 *  ICT304 Capstone 2
 * ---------------------------------------------------------------------------
 *  Provides a configured mysqli connection in $conn for every other PHP file.
 *
 *  Design notes:
 *    - Uses mysqli (no Composer / no external packages required).
 *    - Real credentials are read from ENVIRONMENT VARIABLES when present.
 *    - Falls back to the standard XAMPP local-development defaults so the
 *      project runs out of the box on a marker's machine.
 *    - Connection failures are LOGGED for the developer but NEVER shown to a
 *      website visitor; visitors get a generic message only.
 *
 *  Environment variables recognised (all optional):
 *      DB_HOST   default: localhost
 *      DB_PORT   default: 3306
 *      DB_NAME   default: hotel_booking
 *      DB_USER   default: root
 *      DB_PASS   default: (empty)
 *
 *  To override the defaults, set those environment variables (for XAMPP, via
 *  SetEnv in httpd.conf or a system environment variable). Environment
 *  variables are the ONLY override mechanism this file supports - there is no
 *  local configuration include, so nothing here silently loads another file.
 * ===========================================================================
 */

// ---------------------------------------------------------------------------
//  Do not leak PHP warnings/notices into the HTML output of a live page.
//  Errors are still recorded in the server error log.
// ---------------------------------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

/**
 * Read a configuration value from the environment, falling back to a default.
 *
 * Checks getenv(), $_ENV and $_SERVER because different XAMPP/Apache setups
 * expose variables through different channels.
 *
 * @param  string $key      Environment variable name.
 * @param  string $default  Value used when the variable is not set.
 * @return string
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

// ---------------------------------------------------------------------------
//  Connection settings
//  ---------------------------------------------------------------------------
//  The defaults below are the standard XAMPP values for LOCAL DEVELOPMENT
//  ONLY (MySQL user "root" with a blank password is the XAMPP factory
//  setting, not a real secret). Any deployment beyond localhost must supply
//  proper credentials through the environment variables instead.
// ---------------------------------------------------------------------------
$dbHost = hbs_env('DB_HOST', 'localhost');       // XAMPP default
$dbPort = (int) hbs_env('DB_PORT', '3306');      // XAMPP default
$dbName = hbs_env('DB_NAME', 'hotel_booking');   // created by database.sql
$dbUser = hbs_env('DB_USER', 'root');            // XAMPP default
$dbPass = hbs_env('DB_PASS', '');                // XAMPP default (blank)

// ---------------------------------------------------------------------------
//  Strict internal error reporting.
//
//  MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT makes mysqli raise a
//  mysqli_sql_exception instead of silently returning false. That means a
//  database problem can never be quietly ignored by calling code.
//
//  NOTE FOR LATER PHASES: login.php and register.php still use the old
//  "if ($conn->query(...))" style, which relied on a false return value.
//  Those two files are scheduled to be rewritten with prepared statements in
//  the authentication phase; until then a failing query in them will surface
//  as a logged exception rather than a printed MySQL error string.
// ---------------------------------------------------------------------------
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/** @var mysqli|null $conn Shared database connection used by the whole app. */
$conn = null;

try {
    $conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);

    // Use full UTF-8 (utf8mb4) for the connection. This matches the schema in
    // database.sql, keeps non-English names and emoji intact, and removes a
    // known multi-byte SQL-injection vector.
    $conn->set_charset('utf8mb4');

} catch (mysqli_sql_exception $e) {

    // ----- Developer-facing: full detail, written to the server error log ---
    // Credentials are deliberately NOT included in this message.
    error_log(sprintf(
        '[Hotel Booking System] Database connection failed. host=%s port=%d db=%s error=%s',
        $dbHost,
        $dbPort,
        $dbName,
        $e->getMessage()
    ));

    // ----- Visitor-facing: generic message only, no technical detail --------
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

// No closing PHP tag: prevents accidental whitespace output, which would
// otherwise break header() redirects in files that include this one.
