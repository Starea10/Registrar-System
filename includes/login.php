/**
 * Legacy login compatibility entry.
 *
 * Preserves the old include path while delegating authentication into the
 * new modular auth handler.
 */
<?php
require_once dirname(__DIR__) . '/src/auth/login.php';
?>