<?php
/**
 * Authentication login handler.
 *
 * Validates submitted staff/admin credentials against the users table,
 * populates the PHP session, and redirects the user to the dashboard on
 * success. On failure, it redirects back to the login page with an error.
 *
 * Function:
 * - loginUser(): verifies a username/password pair and assigns session data.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../helpers/redirect.php';

function loginUser(mysqli $conn, string $username, string $password): bool
{
    $stmt = $conn->prepare('SELECT id, username, password, role FROM users WHERE username = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();

    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        $stmt->close();
        return false;
    }

    $user = $result->fetch_assoc();
    $stmt->close();

    if (!password_verify($password, $user['password'])) {
        return false;
    }

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];

    return true;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectTo('/RegistrarSystemV7/login-page.php');
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if (loginUser($conn, $username, $password)) {
    redirectTo('/RegistrarSystemV7/dashboard.php');
}

redirectTo('/RegistrarSystemV7/login-page.php?error=1');
