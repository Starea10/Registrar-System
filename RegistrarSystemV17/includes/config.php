<?php
/**
 * includes/config.php
 *
 * Configuration file for the Request System.
*/

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
if (is_readable($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        $separator = strpos($line, '=');
        if ($separator === false) {
            continue;
        }

        $key = trim(substr($line, 0, $separator));
        $value = trim(substr($line, $separator + 1));
        if (($value[0] ?? '') === '"' && substr($value, -1) === '"') {
            $value = substr($value, 1, -1);
        } elseif (($value[0] ?? '') === "'" && substr($value, -1) === "'") {
            $value = substr($value, 1, -1);
        }

        if (in_array($key, ['DB_HOST', 'DB_PORT', 'DB_USER', 'DB_PASS', 'DB_NAME', 'STORAGE_PATH'], true)
            && getenv($key) === false
        ) {
            putenv($key . '=' . $value);
        }
    }
}

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_USER', getenv('DB_USER') ?: 'rms_app');       
define('DB_PASS', getenv('DB_PASS') ?: '');               
define('DB_NAME', getenv('DB_NAME') ?: 'request_system');

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int) DB_PORT);

if ($conn->connect_error) {

    error_log('DB connection failed: ' . $conn->connect_error);
    http_response_code(503);
    die('The system is temporarily unavailable. Please try again shortly.');
}

$conn->set_charset('utf8mb4');