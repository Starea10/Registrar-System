/**
 * Legacy logout compatibility entry.
 *
 * Preserves the old include path while delegating session termination into
 * the new modular auth logout handler.
 */
<?php
require_once dirname(__DIR__) . '/src/auth/logout.php';
?>
