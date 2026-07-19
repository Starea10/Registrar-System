<?php
session_start();
require_once 'includes/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

// Handle user creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    $username = $conn->real_escape_string($_POST['username']);
    $email = $conn->real_escape_string($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role = $conn->real_escape_string($_POST['role']);

    $sql = "INSERT INTO users (username, email, password, role) VALUES ('$username', '$email', '$password', '$role')";
    if ($conn->query($sql)) {
        // Log action
        $sql = "INSERT INTO audit_trail (user_id, action, details) 
                VALUES ({$_SESSION['user_id']}, 'create_user', 'Created new user: $username')";
        $conn->query($sql);
        
        $_SESSION['success'] = "User created successfully.";
    } else {
        $_SESSION['error'] = "Error creating user: " . $conn->error;
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Handle user deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $user_id = (int)$_POST['user_id'];
    
    // Prevent admin from deleting themselves
    if ($user_id == $_SESSION['user_id']) {
        $_SESSION['error'] = "You cannot delete your own account.";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
    
    // Get user info for logging before deletion
    $user_sql = "SELECT username, role FROM users WHERE id = $user_id";
    $user_result = $conn->query($user_sql);
    
    if ($user_result && $user_result->num_rows > 0) {
        $user_data = $user_result->fetch_assoc();
        
        // Prevent deletion of other admin accounts (optional - remove if you want admins to delete other admins)
        if ($user_data['role'] === 'admin') {
            $_SESSION['error'] = "Cannot delete admin accounts for security reasons.";
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
        
        // Delete the user
        $delete_sql = "DELETE FROM users WHERE id = $user_id";
        if ($conn->query($delete_sql)) {
            // Log action
            $audit_sql = "INSERT INTO audit_trail (user_id, action, details) 
                         VALUES ({$_SESSION['user_id']}, 'delete_user', 'Deleted user: {$user_data['username']} (Role: {$user_data['role']})')";
            $conn->query($audit_sql);
            
            $_SESSION['success'] = "User '{$user_data['username']}' has been deleted successfully.";
        } else {
            $_SESSION['error'] = "Error deleting user: " . $conn->error;
        }
    } else {
        $_SESSION['error'] = "User not found.";
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $user_id = (int)$_POST['user_id'];
    $new_password = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
    
    // Get username for logging
    $user_sql = "SELECT username FROM users WHERE id = $user_id";
    $user_result = $conn->query($user_sql);
    $user_data = $user_result->fetch_assoc();
    
    $sql = "UPDATE users SET password = '$new_password' WHERE id = $user_id";
    if ($conn->query($sql)) {
        // Log action
        $sql = "INSERT INTO audit_trail (user_id, action, details) 
                VALUES ({$_SESSION['user_id']}, 'change_password', 'Changed password for user: {$user_data['username']}')";
        $conn->query($sql);
        
        $_SESSION['success'] = "Password changed successfully for user: " . $user_data['username'];
    } else {
        $_SESSION['error'] = "Error changing password: " . $conn->error;
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Get users with pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;

$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$role_filter = isset($_GET['role']) ? $conn->real_escape_string($_GET['role']) : '';

// Build WHERE clause
$where_clause = "WHERE 1=1";
if ($search) {
    $where_clause .= " AND (username LIKE '%$search%' OR 
                       email LIKE '%$search%')";
}
if ($role_filter) {
    $where_clause .= " AND role = '$role_filter'";
}

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM users $where_clause";
$total_result = $conn->query($count_sql);
$total = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total / $per_page);

// Get users for current page
$sql = "SELECT id, username, email, role, created_at FROM users $where_clause ORDER BY created_at DESC LIMIT $offset, $per_page";
$users_result = $conn->query($sql);

// Store users in array for table display
$users = array();
if ($users_result) {
    while ($row = $users_result->fetch_assoc()) {
        $users[] = $row;
    }
}

if (isset($_GET['ajax'])) {
    ob_start();

    if (empty($users)) {
        echo '<tr><td colspan="7" class="text-center">No users found</td></tr>';
    } else {
        foreach ($users as $user) { ?>
            <tr>
                <td><?php echo $user['id']; ?></td>
                <td><?php echo htmlspecialchars($user['username']); ?></td>
                <td><?php echo htmlspecialchars($user['email']); ?></td>
                <td><span class="badge bg-<?php echo $user['role'] === 'admin' ? 'success' : 'info'; ?>"><?php echo ucfirst($user['role']); ?></span></td>
                <td><?php echo date('Y-m-d H:i', strtotime($user['created_at'])); ?></td>
                <td>
                    <button class="btn btn-sm btn-warning me-1" onclick="selectUserForPasswordChange(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')" data-bs-toggle="modal" data-bs-target="#changePasswordModal" title="Change Password">
                        <i class="fas fa-key"></i>
                    </button>
                    <?php if ($user['id'] != $_SESSION['user_id'] && $user['role'] !== 'admin'): ?>
                    <button class="btn btn-sm btn-danger" onclick="confirmDeleteUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')" title="Delete User">
                        <i class="fas fa-trash"></i>
                    </button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php }
    }
    $table_html = ob_get_clean();

    $pagination_info = '';
    if ($total > 0) {
        $pagination_info = 'Showing ' . ($offset + 1) . ' to ' . min($offset + $per_page, $total) . ' of ' . $total . ' entries';
    }

    header('Content-Type: application/json');
    echo json_encode(['html' => $table_html, 'info' => $pagination_info]);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - RMS</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <style>
        .sidebar {
            min-height: 100vh;
            background: #343a40;
            color: white;
        }
        .sidebar a {
            color: white;
            text-decoration: none;
            padding: 10px 20px;
            display: block;
        }
        .sidebar a:hover {
            background: #495057;
        }
        .pagination-info {
            font-size: 0.9em;
            color: #6c757d;
        }
        :root {
            --green-light: #d6e2d6ff;
            --green-main: #4caf50;
            --green-dark: #388e3c;
            --text-color: #2e2e2e;
            --border-color: #c8e6c9;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--green-light);
            color: var(--text-color);
            padding: 20px;
        }
        h1, h2 {
            text-align: center;
            margin-bottom: 20px;
            font-weight: 600;
            color: var(--green-dark);
        }
        .container {
            max-width: 1200px;
            margin: auto;
        }
        .card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .table-container {
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        th {
            background-color: var(--green-main);
            color: black;
            font-weight: 600;
        }
        tr:hover {
            background-color: #f1f8f4;
        }
        .btn {
            padding: 8px 14px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        .btn-primary {
            background-color: var(--green-main);
            color: white;
        }
        .btn-primary:hover {
            background-color: var(--green-dark);
        }
        .btn-secondary {
            background-color: white;
            border: 1px solid var(--green-main);
            color: var(--green-main);
        }
        .btn-secondary:hover {
            background-color: var(--green-main);
            color: white;
        }
        .btn-warning {
            background-color: #ff9800;
            color: white;
        }
        .btn-warning:hover {
            background-color: #f57c00;
        }
        .btn-danger {
            background-color: #dc3545;
            color: white;
        }
        .btn-danger:hover {
            background-color: #c82333;
        }
        input, select, textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            margin-bottom: 10px;
            font-size: 14px;
        }
        /* Enhanced search button styling for users page */
        .input-group {
            display: flex;
            align-items: stretch; /* Ensure all elements have same height */
        }

        .input-group .form-control {
            border-right: none;
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
            height: 40px; /* Fixed height for consistency */
            padding: 10px;
            border: 1px solid var(--border-color);
        }

        .input-group .btn-outline-secondary {
            padding: 10px 20px !important; /* Match input field height */
            border: 1px solid var(--green-main) !important;
            color: var(--green-main) !important;
            background-color: white !important;
            font-weight: 500;
            transition: all 0.3s ease;
            border-left: none !important; /* Remove left border to connect with input */
            height: 40px; /* Match input field height exactly */
            display: flex;
            align-items: center;
            justify-content: center;
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
            flex-shrink: 0; /* Prevent button from shrinking */
        }

        .input-group .btn-outline-secondary:hover {
            background-color: var(--green-main) !important;
            color: white !important;
            border-color: var(--green-main) !important;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(76, 175, 80, 0.2);
        }

        .input-group .btn-outline-secondary:focus {
            box-shadow: 0 0 0 0.2rem rgba(76, 175, 80, 0.25) !important;
            border-color: var(--green-main) !important;
        }

        .input-group .btn-outline-secondary:active {
            background-color: var(--green-dark) !important;
            border-color: var(--green-dark) !important;
            transform: translateY(0px);
        }

        /* Focus state for better UX */
        .input-group .form-control:focus {
            border-color: var(--green-main);
            box-shadow: none;
        }

        .input-group .form-control:focus + .btn-outline-secondary {
            border-color: var(--green-main);
        }

        /* Ensure form controls are consistent */
        .form-control, .form-select {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            transition: border-color 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--green-main);
            box-shadow: 0 0 0 0.25rem rgba(76, 175, 80, 0.15);
        }
        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 15px;
            gap: 5px;
        }
        .pagination a {
            padding: 8px 12px;
            border-radius: 6px;
            background-color: white;
            color: var(--green-main);
            text-decoration: none;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }
        .pagination a:hover,
        .pagination a.active {
            background-color: var(--green-main);
            color: white;
        }
        @media (max-width: 768px) {
            th, td {
                padding: 10px 8px;
                font-size: 14px;
            }
            h1, h2 {
                font-size: 1.4rem;
            }
        }
        .sidebar-logo {
                width: 85px;
                height: 85px;
                object-fit: contain;
                flex-shrink: 0;
            }

            .sidebar .p-3 h4 {
                white-space: nowrap;
                font-size: 1.1rem;
            }

            /* Responsive adjustments for smaller screens */
            @media (max-width: 992px) {
                .sidebar-logo {
                    width: 45px;
                    height: 45px;
                }
                
                .sidebar .p-3 h4 {
                    font-size: 1rem;
                }
            }

            @media (max-width: 768px) {
                .sidebar .p-3 {
                    padding: 0.75rem !important;
                }
                
                .sidebar-logo {
                    width: 35px;
                    height: 35px;
                }
                
                .sidebar .p-3 h4 {
                    font-size: 0.9rem;
                }
            }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <button class="mobile-menu-toggle d-md-none" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>

            <div class="sidebar-overlay" onclick="closeSidebar()"></div>
            
            <div class="col-md-2 sidebar p-0" id="sidebar">
                <div class="sidebar-header">
                    <div class="d-flex align-items-center">
                        <img src="assets/images/logo.png" alt="CvSU Logo" class="sidebar-logo">
                        <h4>Request<br>System</h4>
                    </div>
                </div>
                
                <nav class="sidebar-nav">
                    <a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
                        <i class="fas fa-chart-line"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="requests.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'requests.php' ? 'active' : ''; ?>">
                        <i class="fas fa-list"></i>
                        <span>Requests</span>
                    </a>
                    <?php if ($_SESSION['role'] === 'admin'): ?>
                    <a href="users.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : ''; ?>">
                        <i class="fas fa-users"></i>
                        <span>Users</span>
                    </a>
                    <a href="audit.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'audit.php' ? 'active' : ''; ?>">
                        <i class="fas fa-history"></i>
                        <span>Audit Trail</span>
                    </a>
                    <?php endif; ?>
                    <a href="includes/logout.php">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </nav>
                
                <div class="sidebar-footer">
                    <small>© 2025 CvSU RMS</small>
                </div>
            </div>

            <div class="col-md-10 p-4">
                <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php 
                    echo htmlspecialchars($_SESSION['error']); 
                    unset($_SESSION['error']);
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php 
                    echo htmlspecialchars($_SESSION['success']); 
                    unset($_SESSION['success']);
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>

                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>User Management</h2>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newUserModal">
                        <i class="fas fa-user-plus me-2"></i>New User
                    </button>
                </div>

                <form class="mb-4" method="GET" onsubmit="return false;">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="input-group">
                                <input type="text" name="search" id="searchInput" class="form-control" placeholder="Search users..." 
                                       value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                                <button class="btn btn-outline-secondary" type="submit">
                                    <i class="fas fa-search"></i> Search
                                </button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <select name="role" class="form-select" onchange="this.form.submit()">
                                <option value="">All Roles</option>
                                <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                <option value="staff" <?php echo $role_filter === 'staff' ? 'selected' : ''; ?>>Staff</option>
                            </select>
                        </div>
                    </div>
                    
                    <?php if ($search || $role_filter): ?>
                    <div class="mt-2">
                        <a href="?" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-times me-1"></i>Clear Filters
                        </a>
                    </div>
                    <?php endif; ?>
                </form>

                <?php if ($total > 0): ?>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="pagination-info" id="paginationInfo">
                        Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $per_page, $total); ?> of <?php echo $total; ?> entries
                    </div>
                </div>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="usersTableBody">
                            <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="7" class="text-center">No users found</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo $user['id']; ?></td>
                                <td><?php echo htmlspecialchars($user['username']); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td><span class="badge bg-<?php echo $user['role'] === 'admin' ? 'success' : 'info'; ?>"><?php echo ucfirst($user['role']); ?></span></td>
                                <td><?php echo date('Y-m-d H:i', strtotime($user['created_at'])); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-warning me-1" onclick="selectUserForPasswordChange(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')" data-bs-toggle="modal" data-bs-target="#changePasswordModal" title="Change Password">
                                        <i class="fas fa-key"></i>
                                    </button>
                                    <?php if ($user['id'] != $_SESSION['user_id'] && $user['role'] !== 'admin'): ?>
                                    <button class="btn btn-sm btn-danger" onclick="confirmDeleteUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')" title="Delete User">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                <nav id="paginationNav">
                    <ul class="pagination justify-content-center">
                        <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo $search ? "&search=" . urlencode($search) : ''; ?><?php echo $role_filter ? "&role=" . urlencode($role_filter) : ''; ?>" aria-label="Previous">
                                <span aria-hidden="true">&laquo;</span>
                            </a>
                        </li>
                        <?php endif; ?>

                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        
                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?><?php echo $search ? "&search=" . urlencode($search) : ''; ?><?php echo $role_filter ? "&role=" . urlencode($role_filter) : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo $search ? "&search=" . urlencode($search) : ''; ?><?php echo $role_filter ? "&role=" . urlencode($role_filter) : ''; ?>" aria-label="Next">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- New User Modal -->
    <div class="modal fade" id="newUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password <span class="text-danger">*</span></label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Role <span class="text-danger">*</span></label>
                            <select name="role" class="form-select" required>
                                <option value="">Select Role</option>
                                <option value="admin">Admin</option>
                                <option value="staff">Staff</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="create_user" class="btn btn-primary">Create User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div class="modal fade" id="changePasswordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Change User Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" onsubmit="return validatePasswordChange()">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Select User <span class="text-danger">*</span></label>
                            <select name="user_id" id="userSelect" class="form-select" required>
                                <option value="">Select a user...</option>
                                <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>">
                                    <?php echo htmlspecialchars($user['username']) . ' (' . htmlspecialchars($user['email']) . ')'; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password <span class="text-danger">*</span></label>
                            <input type="password" name="new_password" id="newPassword" class="form-control" required minlength="6">
                            <div class="form-text">Password must be at least 6 characters long.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                            <input type="password" id="confirmPassword" class="form-control" required>
                            <div id="passwordMismatch" class="text-danger small" style="display: none;">
                                Passwords do not match!
                            </div>
                        </div>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Warning:</strong> This will permanently change the user's password. Make sure to inform the user of their new password.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="change_password" class="btn btn-warning">Change Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete User Modal -->
    <div class="modal fade" id="deleteUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle me-2"></i>Delete User
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="deleteUserForm">
                    <div class="modal-body">
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Warning!</strong> This action cannot be undone.
                        </div>
                        <p class="mb-3">Are you sure you want to delete the user account:</p>
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6 class="card-title mb-1" id="deleteUserName"></h6>
                                <small class="text-muted" id="deleteUserEmail"></small>
                            </div>
                        </div>
                        <input type="hidden" name="user_id" id="deleteUserId">
                        <div class="mt-3">
                            <p class="text-danger small">
                                <i class="fas fa-info-circle me-1"></i>
                                This will permanently remove the user and all associated data.
                            </p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="delete_user" class="btn btn-danger">
                            <i class="fas fa-trash me-2"></i>Delete User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            const tableBody = document.getElementById('usersTableBody');
            const paginationInfo = document.getElementById('paginationInfo');
            const paginationNav = document.getElementById('paginationNav');
            const roleFilter = document.querySelector('select[name="role"]');
            let debounceTimer;

            const performSearch = async () => {
                const query = searchInput.value;
                const role = roleFilter.value;

                const fetchUrl = `?ajax=1&search=${encodeURIComponent(query)}&role=${encodeURIComponent(role)}`;

                try {
                    const response = await fetch(fetchUrl);
                    const data = await response.json();
                    tableBody.innerHTML = data.html;
                    if (paginationInfo) {
                        paginationInfo.innerHTML = data.info;
                    }
                    if (paginationNav) {
                        paginationNav.style.display = query ? 'none' : 'block';
                    }
                } catch (error) {
                    console.error('Error during live search:', error);
                    tableBody.innerHTML = '<tr><td colspan="7" class="text-center text-danger">Error loading results.</td></tr>';
                }
            };

            searchInput.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(performSearch, 300);
            });

            roleFilter.addEventListener('change', performSearch);
        });

        function selectUserForPasswordChange(userId, username) {
            document.getElementById('userSelect').value = userId;
            document.getElementById('newPassword').value = '';
            document.getElementById('confirmPassword').value = '';
            document.getElementById('passwordMismatch').style.display = 'none';
        }

        function confirmDeleteUser(userId, username) {
            // Get user email from the table row (optional enhancement)
            const userRow = event.target.closest('tr');
            const userEmail = userRow.cells[2].textContent;

            // Set modal content
            document.getElementById('deleteUserId').value = userId;
            document.getElementById('deleteUserName').textContent = username;
            document.getElementById('deleteUserEmail').textContent = userEmail;

            // Show the modal
            const deleteModal = new bootstrap.Modal(document.getElementById('deleteUserModal'));
            deleteModal.show();
        }

        function validatePasswordChange() {
            const newPassword = document.getElementById('newPassword').value;
            const confirmPassword = document.getElementById('confirmPassword').value;
            const passwordMismatch = document.getElementById('passwordMismatch');

            if (newPassword !== confirmPassword) {
                passwordMismatch.style.display = 'block';
                return false;
            }

            if (newPassword.length < 6) {
                alert('Password must be at least 6 characters long.');
                return false;
            }

            // Confirm the action
            const userSelect = document.getElementById('userSelect');
            const selectedUserText = userSelect.options[userSelect.selectedIndex].text;
            
            return confirm(`Are you sure you want to change the password for ${selectedUserText}?`);
        }

        // Real-time password confirmation check
        document.getElementById('confirmPassword').addEventListener('input', function() {
            const newPassword = document.getElementById('newPassword').value;
            const confirmPassword = this.value;
            const passwordMismatch = document.getElementById('passwordMismatch');

            if (confirmPassword && newPassword !== confirmPassword) {
                passwordMismatch.style.display = 'block';
            } else {
                passwordMismatch.style.display = 'none';
            }
        });

        // Reset modals when closed
        document.getElementById('changePasswordModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('userSelect').value = '';
            document.getElementById('newPassword').value = '';
            document.getElementById('confirmPassword').value = '';
            document.getElementById('passwordMismatch').style.display = 'none';
        });

        document.getElementById('deleteUserModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('deleteUserId').value = '';
            document.getElementById('deleteUserName').textContent = '';
            document.getElementById('deleteUserEmail').textContent = '';
        });

        // Enhanced delete confirmation
        document.getElementById('deleteUserForm').addEventListener('submit', function(e) {
            const username = document.getElementById('deleteUserName').textContent;
            if (!confirm(`Type "${username}" to confirm deletion:`)) {
                e.preventDefault();
                return false;
            }
            
            // Additional confirmation
            const userConfirmation = prompt(`To confirm deletion, please type the username "${username}" exactly:`);
            if (userConfirmation !== username) {
                alert('Username does not match. Deletion cancelled.');
                e.preventDefault();
                return false;
            }
            
            return true;
        });
    </script>
</body>
</html>