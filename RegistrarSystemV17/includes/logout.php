<!-- /**
 * Logout
 *
 * Server-side page logic for the registrar system. This file handles
 * the request flow, validation rules, and presentation logic for the
 * related administrative or public-facing workflow.
 */ -->

<?php
session_start();

if (isset($_SESSION['user_id'])) {
    require_once 'config.php';
}

session_destroy();
header('Location: ../index.php');
exit();
?>
