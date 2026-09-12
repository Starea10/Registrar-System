<?php
/**
 * Application bootstrap.
 *
 * Loads the shared application root constant, initializes the database
 * connection, and starts the session helpers used by the rest of the app.
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__, 2));
}

require_once APP_ROOT . '/src/database/connection.php';
require_once APP_ROOT . '/src/auth/session.php';
