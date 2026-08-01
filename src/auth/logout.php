<?php
/**
 * Authentication logout handler.
 *
 * Destroys the current PHP session and redirects the user to the public home
 * page so the application can safely end the authenticated state.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../helpers/redirect.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

session_destroy();
redirectTo('/RegistrarSystemV7/index.php');
