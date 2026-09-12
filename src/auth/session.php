<?php
/**
 * Session helpers.
 *
 * Starts the PHP session when needed and provides small helper functions to
 * read the authenticated user and enforce access control on protected pages.
 *
 * Functions:
 * - currentUser(): returns the active session user payload or null.
 * - requireAuth(): redirects unauthenticated users to the login page.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function currentUser(): ?array
{
    if (!isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'])) {
        return null;
    }

    return [
        'id' => (int) $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'role' => $_SESSION['role'],
    ];
}

function requireAuth(): void
{
    if (!currentUser()) {
        header('Location: ../login-page.php');
        exit();
    }
}
