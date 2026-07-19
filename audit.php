<?php
session_start();
require_once 'includes/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

// Get search and filter parameters
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$action_filter = isset($_GET['action']) ? $conn->real_escape_string($_GET['action']) : '';
$date_filter = isset($_GET['date']) ? $conn->real_escape_string($_GET['date']) : '';

// Get audit trail with pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 15; // Changed to show 15 records per page
$offset = ($page - 1) * $per_page;

// Build WHERE clause for filtering
$where_conditions = array();
$where_conditions[] = "a.action IN ('create_request', 'update_request', 'archive_request', 'restore_request', 'delete_request')";

if ($search) {
    $where_conditions[] = "(u.username LIKE '%$search%' OR a.details LIKE '%$search%')";
}

if ($action_filter) {
    $where_conditions[] = "a.action = '$action_filter'";
}

if ($date_filter) {
    $where_conditions[] = "DATE(a.created_at) = '$date_filter'";
}

$where_clause = "WHERE " . implode(' AND ', $where_conditions);

// Get audit entries
$sql = "SELECT a.*, u.username 
        FROM audit_trail a 
        LEFT JOIN users u ON a.user_id = u.id 
        $where_clause
        ORDER BY a.created_at DESC 
        LIMIT $offset, $per_page";
$audit_entries = $conn->query($sql);

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM audit_trail a LEFT JOIN users u ON a.user_id = u.id $where_clause";
$total_result = $conn->query($count_sql);
$total = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total / $per_page);

// Get unique actions for filter dropdown
$actions_sql = "SELECT DISTINCT action FROM audit_trail WHERE action IN ('create_request', 'update_request', 'archive_request', 'restore_request', 'delete_request') ORDER BY action";
$actions_result = $conn->query($actions_sql);
$available_actions = array();
if ($actions_result) {
    while ($row = $actions_result->fetch_assoc()) {
        $available_actions[] = $row['action'];
    }
}


if (isset($_GET['ajax'])) {
    ob_start();
    
    if ($audit_entries && $audit_entries->num_rows > 0) {
        while ($entry = $audit_entries->fetch_assoc()) { ?>
            <tr>
                <td><?php echo $entry['id']; ?></td>
                <td>
                    <i class="fas fa-user me-1"></i>
                    <?php echo htmlspecialchars($entry['username']); ?>
                </td>
                <td>
                    <span class="badge bg-<?php 
                        echo match($entry['action']) {
                            'create_request' => 'success', 'update_request' => 'info', 'archive_request' => 'warning',
                            'restore_request' => 'primary', 'delete_request' => 'danger', default => 'secondary'
                        };
                    ?> action-badge">
                        <i class="fas <?php 
                            echo match($entry['action']) {
                                'create_request' => 'fa-plus', 'update_request' => 'fa-edit', 'archive_request' => 'fa-archive',
                                'restore_request' => 'fa-trash-restore', 'delete_request' => 'fa-trash', default => 'fa-cog'
                            };
                        ?> me-1"></i>
                        <?php echo ucwords(str_replace('_', ' ', $entry['action'])); ?>
                    </span>
                </td>
                <td>
                    <div class="text-break">
                        <?php echo htmlspecialchars($entry['details']); ?>
                    </div>
                </td>
                <td>
                    <small>
                        <i class="fas fa-calendar me-1"></i>
                        <?php echo date('M d, Y', strtotime($entry['created_at'])); ?><br>
                        <i class="fas fa-clock me-1"></i>
                        <?php echo date('h:i A', strtotime($entry['created_at'])); ?>
                    </small>
                </td>
            </tr>
        <?php }
    } else {
        echo '<tr><td colspan="5" class="text-center text-muted"><i class="fas fa-search me-2"></i>No audit entries found matching your filters</td></tr>';
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
    <title>Audit Trail - RMS</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --green-light: #d6e2d6ff;
            --green-main: #4caf50;
            --green-dark: #388e3c;
            --text-color: #2e2e2e;
            --border-color: #c8e6c9;
        }
        
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--green-light);
            color: var(--text-color);
            padding: 20px;
        }
        
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
            border: none;
        }
        
        .table-container {
            overflow-x: auto;
        }
        
        .table-responsive {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            padding: 15px;
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
            border: none;
        }
        
        .btn-primary:hover {
            background-color: var(--green-dark);
            color: white;
        }
        
        .btn-secondary, .btn-outline-secondary {
            background-color: white;
            border: 1px solid var(--green-main);
            color: var(--green-main);
        }
        
        .btn-secondary:hover, .btn-outline-secondary:hover {
            background-color: var(--green-main);
            color: white;
            border-color: var(--green-main);
        }
        
        input, select, textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            margin-bottom: 10px;
            font-size: 14px;
        }
        
        .form-control, .form-select {
            border: 1px solid var(--border-color);
            border-radius: 8px;
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
        
        .pagination .page-link {
            padding: 8px 12px;
            border-radius: 6px;
            background-color: white;
            color: var(--green-main);
            text-decoration: none;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }
        
        .pagination .page-link:hover,
        .pagination .page-item.active .page-link {
            background-color: var(--green-main);
            color: white;
            border-color: var(--green-main);
        }
        
        .action-badge {
            text-transform: capitalize;
        }
        
        .pagination-info {
            font-size: 0.9em;
            color: #6c757d;
        }
        
        .filter-input {
            transition: all 0.3s ease;
        }
        
        .filter-input:focus {
            box-shadow: 0 0 0 0.25rem rgba(76, 175, 80, 0.15);
            border-color: var(--green-main);
        }
        /* Enhanced search button styling for audit page */
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
        }

        .input-group .btn {
            padding: 10px 20px !important; /* Match input field height */
            border: 1px solid var(--green-main) !important;
            color: white !important;
            background-color: var(--green-main) !important;
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

        .input-group .btn:hover {
            background-color: var(--green-dark) !important;
            border-color: var(--green-dark) !important;
            color: white !important;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(76, 175, 80, 0.2);
        }

        .input-group .btn:focus {
            box-shadow: 0 0 0 0.2rem rgba(76, 175, 80, 0.25) !important;
            border-color: var(--green-main) !important;
        }

        .input-group .btn:active {
            background-color: var(--green-dark) !important;
            border-color: var(--green-dark) !important;
            transform: translateY(0px);
        }

        /* Focus state for better UX */
        .input-group .form-control:focus {
            border-color: var(--green-main);
            box-shadow: none;
        }

        .input-group .form-control:focus + .btn {
            border-color: var(--green-main);
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
                <h2 class="mb-4">Audit Trail</h2>

                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3" id="filterForm" onsubmit="return false;">
                            <div class="col-md-4">
                                <label class="form-label">Search</label>
                                <div class="input-group">
                                    <input type="text" name="search" id="searchInput" class="form-control" 
                                           placeholder="Search by username or details..." 
                                           value="<?php echo htmlspecialchars($search); ?>">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Action</label>
                                <select name="action" class="form-select filter-input">
                                    <option value="">All Actions</option>
                                    <?php foreach ($available_actions as $action): ?>
                                    <option value="<?php echo $action; ?>" <?php echo $action_filter === $action ? 'selected' : ''; ?>>
                                        <?php echo ucwords(str_replace('_', ' ', $action)); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Date</label>
                                <input type="date" name="date" class="form-control filter-input" 
                                       value="<?php echo htmlspecialchars($date_filter); ?>">
                            </div>
                        </form>
                        
                        <?php if ($search || $action_filter || $date_filter): ?>
                        <div class="mt-3">
                            <a href="audit.php" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-times me-1"></i>Clear Filters
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

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
                                <th>User</th>
                                <th>Action</th>
                                <th>Details</th>
                                <th>Timestamp</th>
                            </tr>
                        </thead>
                        <tbody id="auditTableBody">
                            <?php if ($audit_entries && $audit_entries->num_rows > 0): ?>
                            <?php while ($entry = $audit_entries->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $entry['id']; ?></td>
                                <td>
                                    <i class="fas fa-user me-1"></i>
                                    <?php echo htmlspecialchars($entry['username']); ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo match($entry['action']) {
                                            'create_request' => 'success',
                                            'update_request' => 'info',
                                            'archive_request' => 'warning',
                                            'restore_request' => 'primary',
                                            'delete_request' => 'danger',
                                            default => 'secondary'
                                        };
                                    ?> action-badge">
                                        <i class="fas <?php 
                                            echo match($entry['action']) {
                                                'create_request' => 'fa-plus',
                                                'update_request' => 'fa-edit',
                                                'archive_request' => 'fa-archive',
                                                'restore_request' => 'fa-trash-restore',
                                                'delete_request' => 'fa-trash',
                                                default => 'fa-cog'
                                            };
                                        ?> me-1"></i>
                                        <?php echo ucwords(str_replace('_', ' ', $entry['action'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="text-break">
                                        <?php echo htmlspecialchars($entry['details']); ?>
                                    </div>
                                </td>
                                <td>
                                    <small>
                                        <i class="fas fa-calendar me-1"></i>
                                        <?php echo date('M d, Y', strtotime($entry['created_at'])); ?><br>
                                        <i class="fas fa-clock me-1"></i>
                                        <?php echo date('h:i A', strtotime($entry['created_at'])); ?>
                                    </small>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted">
                                    <i class="fas fa-search me-2"></i>
                                    No audit entries found
                                    <?php if ($search || $action_filter || $date_filter): ?>
                                    matching your filters
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                <nav id="paginationNav">
                    <ul class="pagination justify-content-center">
                        <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo $search ? "&search=" . urlencode($search) : ''; ?><?php echo $action_filter ? "&action=" . urlencode($action_filter) : ''; ?><?php echo $date_filter ? "&date=" . urlencode($date_filter) : ''; ?>" aria-label="Previous">
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
                            <a class="page-link" href="?page=<?php echo $i; ?><?php echo $search ? "&search=" . urlencode($search) : ''; ?><?php echo $action_filter ? "&action=" . urlencode($action_filter) : ''; ?><?php echo $date_filter ? "&date=" . urlencode($date_filter) : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo $search ? "&search=" . urlencode($search) : ''; ?><?php echo $action_filter ? "&action=" . urlencode($action_filter) : ''; ?><?php echo $date_filter ? "&date=" . urlencode($date_filter) : ''; ?>" aria-label="Next">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </nav>
                <?php endif; ?>

                <div class="row mt-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header" style="background-color: var(--green-main); color: white; border-radius: 12px 12px 0 0;">
                                <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Audit Trail Information</h6>
                            </div>
                            <div class="card-body">
                                <p class="mb-1"><strong>Total Entries:</strong> <?php echo $total; ?></p>
                                <p class="mb-0"><small class="text-muted">This audit trail tracks all request-related activities including creation, updates, archiving, restoration, and deletion of requests.</small></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('searchInput');
        const tableBody = document.getElementById('auditTableBody');
        const paginationInfo = document.getElementById('paginationInfo');
        const paginationNav = document.getElementById('paginationNav');
        const actionFilter = document.querySelector('select[name="action"]');
        const dateFilter = document.querySelector('input[name="date"]');
        let debounceTimer;

        const performSearch = async () => {
            const query = searchInput.value;
            const action = actionFilter.value;
            const date = dateFilter.value;

            const fetchUrl = `?ajax=1&search=${encodeURIComponent(query)}&action=${encodeURIComponent(action)}&date=${encodeURIComponent(date)}`;

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
                tableBody.innerHTML = '<tr><td colspan="5" class="text-center text-danger">Error loading results.</td></tr>';
            }
        };

        searchInput.addEventListener('input', () => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(performSearch, 300);
        });

        // Add event listeners to filters to trigger the search
        actionFilter.addEventListener('change', performSearch);
        dateFilter.addEventListener('change', performSearch);

    });
    </script>
</body>
</html>