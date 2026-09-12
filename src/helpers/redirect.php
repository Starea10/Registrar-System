<?php
/**
 * Redirect helper.
 *
 * Centralizes HTTP redirects so controllers and handlers can send users to
 * the correct page without repeating the same header/exit pattern.
 *
 * Function:
 * - redirectTo(): sends a Location header and exits immediately.
 */

declare(strict_types=1);

function redirectTo(string $path): void
{
    header('Location: ' . $path);
    exit();
}
