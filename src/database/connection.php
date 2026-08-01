<?php
/**
 * Database bootstrap.
 *
 * Defines the database credentials and creates the expected database if it
 * does not already exist. This file exposes the shared $conn mysqli instance
 * for the rest of the application.
 */

declare(strict_types=1);

if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost');
}
if (!defined('DB_USER')) {
    define('DB_USER', 'root');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', '');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', 'request_system');
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS);

if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}

$sql = 'CREATE DATABASE IF NOT EXISTS ' . DB_NAME;
if ($conn->query($sql) !== TRUE) {
    die('Error creating database: ' . $conn->error);
}

$conn->select_db(DB_NAME);
