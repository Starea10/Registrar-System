<?php
session_start();
require_once 'config.php';

/** @var mysqli $conn */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $stmt = $conn->prepare(
        'SELECT id, username, password, role FROM users WHERE username = ? LIMIT 1'
    );
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {

        if (password_verify($password, $user['password'])) {

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            header('Location: ../dashboard.php');
            exit();

        }
    }

    $stmt->close();

    header('Location: ../login-page.php?error=1');
    exit();
}
?>