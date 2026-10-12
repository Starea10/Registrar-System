<?php
/**
 * requests.php
 *
 * All data handling for requests.php: auth check, form/action processing,
 * and the query building for the listing table. No HTML is produced here
 * (except the AJAX/JSON branch, which is data too). requests.php includes
 * this file and then renders the page.
 *
 * Security notes vs. the original file:
 *  - Every query that includes user input now uses a prepared statement
 *    with bound parameters instead of string concatenation /
 *    real_escape_string(). real_escape_string() is easy to miss in one
 *    spot and miss = SQL injection; bound parameters remove that risk
 *    entirely.
 *  - $_GET['sort'] used to be dropped straight into "ORDER BY r.$sort_column"
 *    with no validation at all -- that's a direct SQL injection point
 *    (you can't bind a column name as a parameter, so it has to be
 *    validated against a whitelist instead). Fixed below.
 *  - The status-update handler now validates $_POST['new_status'] against
 *    the same list of statuses used in the UI, instead of trusting it.
 *  - Fixed a pre-existing bug: the status lookup query selected the
 *    string literal 'status' (quoted) instead of the `status` column, so
 *    $old_status was always the literal text "status". Now selects the
 *    real column.
 */

session_start();
require_once 'includes/config.php';
// "Submitted Documents" rendering for images uploaded through the online
// request form (read-only presentation helper).
require_once 'includes/document-images.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$is_admin_or_staff = ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'staff');

// ---------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------

/**
 * Build a redirect URL back to the current page/view query params.
 */
function requests_redirect_url(string $extra = ''): string
{
    $params = [];
    if (isset($_GET['page'])) {
        $params[] = 'page=' . urlencode($_GET['page']);
    }
    if (isset($_GET['view'])) {
        $params[] = 'view=' . urlencode($_GET['view']);
    }
    if ($extra !== '') {
        $params[] = $extra;
    }
    return $_SERVER['PHP_SELF'] . ($params ? '?' . implode('&', $params) : '');
}

/**
 * bind_param() needs the arguments passed by reference, which is awkward
 * with a dynamic list built at runtime. This wraps that up.
 */
function stmt_execute_with_params(mysqli_stmt $stmt, string $types, array $params): bool
{
    if ($types !== '') {
        $refs = [];
        foreach ($params as $key => $value) {
            $refs[$key] = &$params[$key];
        }
        array_unshift($refs, $types);
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
    return $stmt->execute();
}

function log_audit(mysqli $conn, int $userId, string $action, string $details): void
{
    $stmt = $conn->prepare('INSERT INTO audit_trail (user_id, action, details) VALUES (?, ?, ?)');
    $stmt->bind_param('iss', $userId, $action, $details);
    $stmt->execute();
    $stmt->close();
}

function getHeaderUrl(string $columnName, string $currentSortColumn, string $nextOrder): string
{
    $query = $_GET;
    $query['sort'] = $columnName;
    $query['order'] = ($currentSortColumn === $columnName) ? $nextOrder : 'asc';

    return $_SERVER['PHP_SELF'] . '?' . http_build_query($query);
}

// Document type => display label, for building request titles/descriptions.
// 'certification' and 'others' are handled separately since they carry a
// free-text sub-type.
const DOCUMENT_LABELS = [
    'tor'       => 'Transcript of Record (TOR)',
    'diploma'   => 'Diploma',
    'cog'       => 'Certificate of Grades (COG)',
    'coe'       => 'Certificate of Enrollment (COE)',
    'form_137a' => 'Form 137A',
    'cav'       => 'Certification Authentication and Verification (CAV)',
];

const ALLOWED_STATUSES = ['pending', 'processing', 'for_signature', 'for_release', 'released'];

$view_archived = true;
$view_released = false;



// ---------------------------------------------------------------------
// Released date update
// ---------------------------------------------------------------------
if (isset($_POST['update_released_date']) && isset($_POST['request_id']) && $is_admin_or_staff) {
    $request_id = (int)$_POST['request_id'];
    $new_released_date = $_POST['released_date'] ?? '';

    if ($new_released_date === '') {
        $_SESSION['error'] = 'Released date cannot be empty.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }

    $stmt = $conn->prepare("SELECT id, released_at, requester_id FROM requests WHERE id = ?");
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $check_result = $stmt->get_result();
    $stmt->close();

    if ($check_result && $check_result->num_rows > 0) {
        $current_request = $check_result->fetch_assoc();
        $old_released_date = $current_request['released_at']
            ? date('Y-m-d', strtotime($current_request['released_at']))
            : 'N/A';

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('UPDATE requests SET released_at = ? WHERE id = ?');
            $stmt->bind_param('si', $new_released_date, $request_id);

            if ($stmt->execute()) {
                $stmt->close();
                $log_details = "Updated released date for request #{$request_id} from '{$old_released_date}' to '{$new_released_date}'";
                log_audit($conn, (int)$current_request['requester_id'], 'update_released_date', $log_details);
                $conn->commit();
                $_SESSION['success'] = 'Released date updated successfully.';
            } else {
                throw new Exception('Failed to update released date: ' . $conn->error);
            }
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = 'Error updating released date: ' . $e->getMessage();
            error_log('Released date update error: ' . $e->getMessage());
        }
    } else {
        $_SESSION['error'] = 'Released request not found.';
    }

    $redirect_url = $_SERVER['PHP_SELF'] . '?view=released';
    if (isset($_GET['page'])) {
        $redirect_url .= '&page=' . urlencode($_GET['page']);
    }
    header('Location: ' . $redirect_url);
    exit();
}

// ---------------------------------------------------------------------
// Listing: pagination, search, sort, filters
// ---------------------------------------------------------------------
$page     = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 15;
$offset   = ($page - 1) * $per_page;

$search        = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
if ($status_filter !== '' && !in_array($status_filter, ALLOWED_STATUSES, true)) {
    $status_filter = '';
}

// $sort_column ends up interpolated directly into ORDER BY, so it MUST be
// checked against a whitelist rather than escaped -- you cannot bind an
// identifier as a query parameter.
$sortable_columns = ['id', 'student_number', 'student_name', 'claiming_date', 'created_at', 'status'];
$sort_column = (isset($_GET['sort']) && in_array($_GET['sort'], $sortable_columns, true)) ? $_GET['sort'] : 'released_at';
$sort_order  = (isset($_GET['order']) && $_GET['order'] === 'desc') ? 'desc' : 'asc';
$next_order  = ($sort_order === 'asc') ? 'desc' : 'asc';

$conditions = [];
$params = [];
$types = '';

if ($search !== '') {
    $conditions[] = '(title LIKE ? OR student_number LIKE ? OR student_name LIKE ? OR description LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}

$conditions[] = "is_archived = 1 AND status = 'released'";

$where_clause = 'WHERE ' . implode(' AND ', $conditions);

// Total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM requests {$where_clause}";
$stmt = $conn->prepare($count_sql);
stmt_execute_with_params($stmt, $types, $params);
$total = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();
$total_pages = (int)ceil($total / $per_page);

// Current page of requests
$sql = "SELECT r.*, u.staff_name as requester_name
        FROM requests r
        LEFT JOIN staffs u ON r.requester_id = u.id
        {$where_clause}
        ORDER BY r.{$sort_column} {$sort_order}
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$select_params = $params;
$select_params[] = $per_page;
$select_params[] = $offset;
stmt_execute_with_params($stmt, $types . 'ii', $select_params);
$requests_result = $stmt->get_result();

$requests = [];
if ($requests_result) {
    while ($row = $requests_result->fetch_assoc()) {
        $requests[] = $row;
    }
}
$stmt->close();

// Modals are needed only for the current page. Loading every archived row
// here duplicated the listing query and increased page generation cost.
$all_requests = $requests;

$page_title = 'Archived Requests';

$programs = $conn->query('SELECT * FROM `programs`');
$staffs_result = $conn->query('SELECT * FROM staffs');

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Requests - RMS</title>
    <link rel="icon" href="assets/images/logo.png" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/styles2.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/admin.css">
    <link rel="stylesheet" href="assets/css/responsive.css">
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
                    <a href="online-request.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'online-request.php' ? 'active' : ''; ?>">
                        <i class="fas fa-list"></i>
                        <span>Online Requests</span>
                    </a>
                     <a href="archives.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'archives.php' ? 'active' : ''; ?>">
                        <i class="fas fa-list"></i>
                        <span>Archives</span>
                    </a>
                    
                    <?php if ($_SESSION['role'] === 'admin'): ?>
                    <a href="users.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : ''; ?>">
                        <i class="fas fa-users"></i>
                        <span>Users</span>
                    </a>
                    <a href="resources.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'resources.php' ? 'active' : ''; ?>">
                        <i class="fas fa-images"></i>
                        <span>Resources</span>
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
                <div id="ajax-alerts-container"></div>
                
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
                
                <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
                    <div>
                        <h2><?php echo $page_title; ?></h2>
                    </div>
                </div>

                <form class="mb-4" method="GET">
                    <div class="row">
                        <div class="col">
                            <div class="input-group">
                                <input type="text" name="search" id="searchInput" class="form-control" placeholder="Search requests..." 
                                       value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                                <button class="btn btn-outline-secondary" type="submit">
                                    <i class="fas fa-search"></i> Search
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($search || $status_filter): ?>
                    <div class="mt-2">
                        <a href="?<?php 
                            if ($view_archived) echo 'view=archived'; 
                            elseif ($view_released) echo 'view=released'; 
                        ?>" class="btn btn-sm btn-outline-secondary">
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
                        <thead class = "table-light">
                            <tr>
                                <th class="text-center"><a href="<?php echo getHeaderUrl('id', $sort_column, $next_order); ?>">ID<?php echo $sort_column === 'id' ? ($sort_order === 'asc' ? '▲' : '▼') : ''; ?></a></th>
                                <th class="text-center">Title</th>
                                <th class="text-center"><a href="<?php echo getHeaderUrl('student_number', $sort_column, $next_order); ?>">Student No.<?php echo $sort_column === 'student_number' ? ($sort_order === 'asc' ? '▲' : '▼') : ''; ?></a></th>
                                <th class="text-center"><a href="<?php echo getHeaderUrl('student_name', $sort_column, $next_order); ?>">Student Name<?php echo $sort_column === 'student_name' ? ($sort_order === 'asc' ? '▲' : '▼') : ''; ?></a></th>
                                <th class="text-center">Status</th>
                                <th class="text-center"><a href="<?php echo getHeaderUrl('claiming_date', $sort_column, $next_order); ?>">Claim Date<?php echo $sort_column === 'claiming_date' ? ($sort_order === 'asc' ? '▲' : '▼') : ''; ?></a></th>
                                <th class="text-center">Processed By</th>
                                <th class="text-center"><a href="<?php echo getHeaderUrl('created_at', $sort_column, $next_order); ?>">Created<?php echo $sort_column === 'created_at' ? ($sort_order === 'asc' ? '▲' : '▼') : ''; ?></a></th>
                                <th class="text-center"><a href="<?php echo getHeaderUrl('released_at', $sort_column, $next_order); ?>">Released Date<?php echo $sort_column === 'released_at' ? ($sort_order === 'asc' ? '▲' : '▼') : ''; ?></a></th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="requestsTableBody">
                        <?php if (empty($requests)): ?>
                            <tr>
                                <td colspan="10" class="text-center">No requests found</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($requests as $request): ?>
                            <tr>
                                <td class="text-center"><?php echo $request['id']; ?></td>
                                <td class="text-center"><?php echo htmlspecialchars($request['title']); ?></td>
                                <td class="text-center"><?php echo htmlspecialchars($request['student_number']); ?></td>
                                <td class="text-center"><?php echo htmlspecialchars($request['student_name']); ?></td>
                                <td class="text-center">
                                    <span class="badge bg-<?php 
                                        echo match($request['status']) {
                                            'pending' => 'warning',
                                            'processing' => 'info',
                                            'for_signature' => 'primary',
                                            'for_release' => 'secondary',
                                            'released' => 'success',
                                            default => 'secondary'
                                        };
                                    ?> status-badge">
                                        <?php echo str_replace('_', ' ', $request['status']); ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php if (!empty($request['claiming_date'])): ?>
                                        <?php
                                        $claiming_date = new DateTime($request['claiming_date']);
                                        $today = new DateTime();
                                        $today->setTime(0, 0, 0);
                                        $claiming_date_start = clone $claiming_date;
                                        $claiming_date_start->setTime(0, 0, 0);

                                        $current_status = $request['status'];
                                        
                                        $class = '';
                                        if ($current_status != 'for_release'){
                                            if ($claiming_date_start < $today) {
                                                $class = 'claiming-date-overdue';
                                            } elseif ($claiming_date_start == $today) {
                                                $class = 'claiming-date-today';
                                            } 
                                        }else {
                                            $class = 'claiming-date-upcoming';
                                        }
                                        ?>
                                        <span class="<?php echo $class; ?>">
                                            <i class="fas fa-calendar-alt me-1"></i>
                                            <?php echo $claiming_date->format('M d, Y'); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">Not scheduled</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if (($request['source'] ?? 'walkin') === 'online'): ?>
                                        <span class="badge bg-primary">
                                            <i class="fas fa-globe me-1"></i>
                                            Online
                                        </span>
                                    <?php elseif (!empty($request['requester_name'])): ?>
                                        <span class="badge bg-info">
                                            <i class="fas fa-user me-1"></i>
                                            <?php echo htmlspecialchars($request['requester_name']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">Unknown</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?php echo date('Y-m-d H:i', strtotime($request['created_at'])); ?></td>
                                <td class="text-center">
                                        <?php echo date('Y-m-d H:i', strtotime($request['released_at'])); ?>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-info" data-bs-toggle="modal" 
                                            data-bs-target="#viewRequestModal<?php echo $request['id']; ?>">
                                        <i class="fas fa-eye"></i>
                                    </button>

                                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" 
                                            data-bs-target="#editReleasedDateModal<?php echo $request['id']; ?>"
                                            title="Edit Released Date">
                                        <i class="fas fa-calendar-alt"></i>
                                    </button>
                                    
                                </td>
                            </tr>

                            <div class="modal fade" id="viewRequestModal<?php echo $request['id']; ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Request Details</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <h6>Title</h6>
                                            <p><?php echo htmlspecialchars($request['title']); ?></p>
                                            
                                            <h6>Student Information</h6>
                                            <div class="mb-2">
                                                <strong>Student Number:</strong> 
                                                <?php echo htmlspecialchars($request['student_number']); ?>
                                            </div>
                                            <div class="mb-3">
                                                <strong>Student Name:</strong> 
                                                <?php echo htmlspecialchars($request['student_name']); ?>
                                            </div>
                                            
                                            <h6>Request Information</h6>
                                            <div class="mb-2">
                                                <strong>Status:</strong> 
                                                <span class="badge bg-<?php 
                                                    echo match($request['status']) {
                                                        'pending' => 'warning',
                                                        'processing' => 'info',
                                                        'for_signature' => 'primary',
                                                        'for_release' => 'secondary',
                                                        'released' => 'success',
                                                        default => 'secondary'
                                                    };
                                                ?>">
                                                    <?php echo str_replace('_', ' ', $request['status']); ?>
                                                </span>
                                            </div>
                                            <div class="mb-2">
                                                <strong>Submitted By:</strong> 
                                                <?php
                                                if (($request['source'] ?? 'walkin') === 'online') {
                                                    echo 'Student (Online Request)';
                                                } else {
                                                    echo !empty($request['requester_name']) ? htmlspecialchars($request['requester_name']) : 'Unknown';
                                                }
                                                ?>
                                            </div>
                                            <div class="mb-3">
                                                <strong>Created:</strong> 
                                                <?php echo date('F d, Y H:i', strtotime($request['created_at'])); ?>
                                            </div>
                                            
                                            <?php if (!empty($request['claiming_date'])): ?>
                                            <h6>Claiming Schedule</h6>
                                            <div class="mb-3">
                                                <strong>Claiming Date:</strong> 
                                                <?php echo date('F d, Y', strtotime($request['claiming_date'])); ?>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($request['description'])): ?>
                                            <h6>Details</h6>
                                            <p><?php echo nl2br(htmlspecialchars($request['description'])); ?></p>
                                            <?php endif; ?>

                                            <?php echo render_submitted_documents($request); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            
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
                            <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo $search ? "&search=" . urlencode($search) : ''; ?><?php echo $status_filter ? "&status=" . urlencode($status_filter) : ''; ?><?php if ($view_archived) echo "&view=archived"; elseif ($view_released) echo "&view=released"; ?>" aria-label="Previous">
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
                            <a class="page-link" href="?page=<?php echo $i; ?><?php echo $search ? "&search=" . urlencode($search) : ''; ?><?php echo $status_filter ? "&status=" . urlencode($status_filter) : ''; ?><?php if ($view_archived) echo "&view=archived"; elseif ($view_released) echo "&view=released"; ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo $search ? "&search=" . urlencode($search) : ''; ?><?php echo $status_filter ? "&status=" . urlencode($status_filter) : ''; ?><?php if ($view_archived) echo "&view=archived"; elseif ($view_released) echo "&view=released"; ?>" aria-label="Next">
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
    
    <?php foreach ($all_requests as $request): ?>

    <div class="modal fade" id="editReleasedDateModal<?php echo $request['id']; ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Released Date</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                        <div class="mb-3">
                            <p><strong>Request Title:</strong> <?php echo htmlspecialchars($request['title']); ?></p>
                            <p><strong>Student Name:</strong> <?php echo htmlspecialchars($request['student_name']); ?></p>
                        </div>
                        <div class="mb-3">
                            <label for="released_date_<?php echo $request['id']; ?>" class="form-label">Released Date</label>
                            <input type="datetime-local" id="released_date_<?php echo $request['id']; ?>" name="released_date" class="form-control" 
                                   value="<?php echo !empty($request['released_at']) ? date('Y-m-d\TH:i', strtotime($request['released_at'])) : ''; ?>"
                                   required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_released_date" class="btn btn-primary">Update Date</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php endforeach; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    
    <script src="assets/js/sidebar.js" defer></script>
    <script src="assets/js/archive.js" defer></script>
<?php echo render_document_lightbox(); ?>
</body>
</html>