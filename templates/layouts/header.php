/**
 * Shared page header template.
 *
 * Provides the common HTML head section and global stylesheet references for
 * the modular PHP pages built across the portal.
 */
<?php
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__, 2));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'Registrar System' ?></title>
    <link rel="icon" href="<?= APP_ROOT ?>/assets/images/logo.png" type="image/x-icon">
    <link rel="stylesheet" href="<?= APP_ROOT ?>/assets/css/variables.css">
    <link rel="stylesheet" href="<?= APP_ROOT ?>/assets/css/style.css">
</head>
<body>
