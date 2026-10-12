<?php
/**
 * online-request.php
 *
 * Staff-facing review page for student-submitted online requests
 * (online_requests table, populated by submit-online-request.php).
 *
 * This used to be a copy-paste of requests.php's logic file, which is why
 * it always showed "No requests found": it queried `requests` with a
 * `status IS NULL` condition that can never be true (status defaults to
 * 'pending' and is never actually NULL), and it still carried the entire
 * "create a new walk-in request" block, which doesn't belong on a review
 * page for online submissions. Both are gone now; this file has its own
 * logic against `online_requests`.
 *
 * Flow:
 *   online_requests.status = 'pending'
 *     -> staff Approve  -> INSERT INTO requests (source='online',
 *                          status='pending'); online_requests row is
 *                          stamped status='approved' + approved_request_id.
 *                          From there it's an ordinary request and flows
 *                          through requests.php's normal pipeline.
 *     -> staff Disapprove -> online_requests row is stamped
 *                            status='disapproved' (kept, not deleted).
 *
 * Requires the `online_requests` table from
 * online_requests_migration.sql.
 */

session_start();
require_once 'includes/config.php';
// "Submitted Documents" rendering for the uploaded images (read-only
// presentation helper; the files themselves are written by
// includes/upload-helper.php when the student submits).
require_once 'includes/document-images.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$is_admin_or_staff = ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'staff');

// ---------------------------------------------------------------------
// Small helpers (same as requests.php / archives.php, kept local here
// too rather than introducing a shared include as part of this change)
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

const ONLINE_REQUEST_STATUSES = ['pending', 'approved', 'disapproved'];

// ---------------------------------------------------------------------
// Approve action
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_online_request']) && $is_admin_or_staff) {
    $online_request_id = (int)($_POST['online_request_id'] ?? 0);
    $reviewer_id        = (int)($_POST['clerk'] ?? 0);
    $claiming_date_raw  = trim($_POST['claiming_date'] ?? '');
    $claiming_date      = $claiming_date_raw !== '' ? date('Y-m-d', strtotime($claiming_date_raw)) : null;

    if ($online_request_id <= 0 || $reviewer_id <= 0) {
        $_SESSION['error'] = 'Please select who is processing this request.';
        header('Location: ' . requests_redirect_url());
        exit();
    }

    $stmt = $conn->prepare("SELECT * FROM online_requests WHERE id = ? AND status = 'pending'");
    $stmt->bind_param('i', $online_request_id);
    $stmt->execute();
    $online_request = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$online_request) {
        $_SESSION['error'] = 'That online request was not found, or has already been reviewed.';
        header('Location: ' . requests_redirect_url());
        exit();
    }

    $conn->begin_transaction();
    try {
        // Mirrors the same column set requests.php's create-request path
        // uses, so this row is indistinguishable from a normal request
        // once it exists -- it goes through the usual pending ->
        // processing -> for_signature -> for_release -> released pipeline
        // unmodified.
        // The *_image paths are copied across as well, so the uploads the
        // student submitted stay attached to the request staff actually
        // work on -- without this they'd be stranded on the
        // `online_requests` row and invisible from requests.php.
        // Walk-in requests simply leave these columns NULL, as before.
        $stmt = $conn->prepare(
            "INSERT INTO requests
                (title, description, student_number, student_name, requester_id, source, status, email_address, claiming_date,
                student_id_image, registration_form_image, clearance_form_image,
                proof_of_graduation_image, id_picture_image, honorable_dismissal_image,
                government_id_image, original_document_image, photocopy_document_image,
                photocopy_document_image_2, tracking_code)
            VALUES (?, ?, ?, ?, ?, 'online', 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'ssssiss' . str_repeat('s', 11),
            $online_request['title'],
            $online_request['description'],
            $online_request['student_number'],
            $online_request['student_name'],
            $reviewer_id,
            $online_request['email_address'],
            $claiming_date,
            $online_request['student_id_image'],
            $online_request['registration_form_image'],
            $online_request['clearance_form_image'],
            $online_request['proof_of_graduation_image'],
            $online_request['id_picture_image'],
            $online_request['honorable_dismissal_image'],
            $online_request['government_id_image'],
            $online_request['original_document_image'],
            $online_request['photocopy_document_image'],
            $online_request['photocopy_document_image_2'],
            $online_request['tracking_code']
        );
  
        if (!$stmt->execute()) {
            throw new Exception('Failed to create request: ' . $conn->error);
        }
        $new_request_id = $stmt->insert_id;
        $stmt->close();

        $stmt = $conn->prepare(
            "UPDATE online_requests
             SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), approved_request_id = ?
             WHERE id = ?"
        );
        $stmt->bind_param('iii', $reviewer_id, $new_request_id, $online_request_id);
        if (!$stmt->execute()) {
            throw new Exception('Failed to update online request: ' . $conn->error);
        }
        $stmt->close();

        log_audit(
            $conn,
            $reviewer_id,
            'approve_online_request',
            "Approved online request #{$online_request_id} ({$online_request['title']}) for "
                . "{$online_request['student_name']} ({$online_request['student_number']}); created request #{$new_request_id}"
        );

        $conn->commit();
        $_SESSION['success'] = "Request approved. It now appears in Requests as #{$new_request_id}.";
    } catch (Exception $e) {
        $conn->rollback();
        error_log('Online request approval error: ' . $e->getMessage());
        $_SESSION['error'] = 'Error approving request: ' . $e->getMessage();
    }

    header('Location: ' . requests_redirect_url());
    exit();
}

// ---------------------------------------------------------------------
// Disapprove action
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disapprove_online_request']) && $is_admin_or_staff) {
    $online_request_id = (int)($_POST['online_request_id'] ?? 0);
    $reviewer_id        = (int)($_POST['clerk'] ?? 0);
    $reason             = trim($_POST['disapproval_reason'] ?? '');

    if ($online_request_id <= 0 || $reviewer_id <= 0) {
        $_SESSION['error'] = 'Please select who is processing this request.';
        header('Location: ' . requests_redirect_url());
        exit();
    }

    $stmt = $conn->prepare("SELECT * FROM online_requests WHERE id = ? AND status = 'pending'");
    $stmt->bind_param('i', $online_request_id);
    $stmt->execute();
    $online_request = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$online_request) {
        $_SESSION['error'] = 'That online request was not found, or has already been reviewed.';
        header('Location: ' . requests_redirect_url());
        exit();
    }

    try {
        $stmt = $conn->prepare(
            "UPDATE online_requests
             SET status = 'disapproved', reviewed_by = ?, reviewed_at = NOW(), disapproval_reason = ?
             WHERE id = ?"
        );
        $reason_or_null = $reason !== '' ? $reason : null;
        $stmt->bind_param('isi', $reviewer_id, $reason_or_null, $online_request_id);
        if (!$stmt->execute()) {
            throw new Exception('Failed to update online request: ' . $conn->error);
        }
        $stmt->close();

        $details = "Disapproved online request #{$online_request_id} ({$online_request['title']}) for "
            . "{$online_request['student_name']} ({$online_request['student_number']})";
        if ($reason !== '') {
            $details .= " -- reason: {$reason}";
        }
        log_audit($conn, $reviewer_id, 'disapprove_online_request', $details);

        $_SESSION['success'] = 'Request disapproved.';
    } catch (Exception $e) {
        error_log('Online request disapproval error: ' . $e->getMessage());
        $_SESSION['error'] = 'Error disapproving request: ' . $e->getMessage();
    }

    header('Location: ' . requests_redirect_url());
    exit();
}

// ---------------------------------------------------------------------
// Listing: pagination, search, sort, pending/disapproved view
// ---------------------------------------------------------------------
$page     = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 15;
$offset   = ($page - 1) * $per_page;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$view_disapproved = isset($_GET['view']) && $_GET['view'] === 'disapproved';
$status_filter     = $view_disapproved ? 'disapproved' : 'pending';

// $sort_column ends up interpolated directly into ORDER BY, so it MUST be
// checked against a whitelist rather than escaped -- you cannot bind an
// identifier as a query parameter.
$sortable_columns = ['id', 'student_number', 'student_name', 'created_at'];
$sort_column = (isset($_GET['sort']) && in_array($_GET['sort'], $sortable_columns, true)) ? $_GET['sort'] : 'created_at';
$sort_order  = (isset($_GET['order']) && $_GET['order'] === 'desc') ? 'desc' : 'asc';
$next_order  = ($sort_order === 'asc') ? 'desc' : 'asc';

$conditions = ['status = ?'];
$params = [$status_filter];
$types = 's';

if ($search !== '') {
    $conditions[] = '(id LIKE ? OR student_number LIKE ? OR student_name LIKE ? OR document_type LIKE ? OR title LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like);
    $types .= 'sssss';
}

$where_clause = 'WHERE ' . implode(' AND ', $conditions);

// Total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM online_requests {$where_clause}";
$stmt = $conn->prepare($count_sql);
stmt_execute_with_params($stmt, $types, $params);
$total = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();
$total_pages = (int)ceil($total / $per_page);

// Current page of online requests
$sql = "SELECT o.*, s.staff_name as reviewer_name
        FROM online_requests o
        LEFT JOIN staffs s ON o.reviewed_by = s.id
        {$where_clause}
        ORDER BY o.{$sort_column} {$sort_order}
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$select_params = $params;
$select_params[] = $per_page;
$select_params[] = $offset;
stmt_execute_with_params($stmt, $types . 'ii', $select_params);
$online_requests_result = $stmt->get_result();

$online_requests = [];
if ($online_requests_result) {
    while ($row = $online_requests_result->fetch_assoc()) {
        $online_requests[] = $row;
    }
}
$stmt->close();

$page_title = 'Online Requests';

// Fetched into a plain array (not left as a mysqli_result) because it
// gets re-rendered once per row below -- a mysqli_result's internal
// pointer doesn't reset between foreach passes, so only the first row's
// dropdown would have options if this stayed a raw result set.
$staffs = [];
$staffs_result = $conn->query('SELECT * FROM staffs WHERE is_deleted = 0');
if ($staffs_result) {
    while ($row = $staffs_result->fetch_assoc()) {
        $staffs[] = $row;
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Online Requests - RMS</title>
    <link rel="icon" href="assets/images/logo.png" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/styles2.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/admin.css">

    <style>
        /* Page-specific overrides (kept after admin.css so they win) */
        .btn-outline-secondary:hover {
            background-color: var(--green-main) !important;
            color: white !important;
            border-color: var(--green-main) !important;
            }

        .pagination a {
            padding: 8px 12px;
            border-radius: 6px;
            background-color: white;
            color: var(--green-main);
            text-decoration: none;
            border: 1px solid var(--border-color);
            }

        .view-toggle .btn.active-view {
            background-color: var(--green-dark);
            color: white;
            }

    </style>
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
                        <i class="fas fa-globe"></i>
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
                    <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>

                <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
                    <div>
                        <h2><?php echo $page_title; ?></h2>
                    </div>
                    <div class="view-toggle btn-group">
                        <a href="<?php echo $_SERVER['PHP_SELF']; ?>"
                           class="btn btn-secondary <?php echo !$view_disapproved ? 'active-view' : ''; ?>">
                            <i class="fas fa-inbox me-1"></i>Pending Requests
                        </a>
                        <a href="?view=disapproved"
                           class="btn btn-secondary <?php echo $view_disapproved ? 'active-view' : ''; ?>">
                            <i class="fas fa-ban me-1"></i>Disapproved Requests
                        </a>
                    </div>
                </div>

                <form class="mb-4" method="GET" onsubmit="return false;">
                    <?php if ($view_disapproved): ?>
                    <input type="hidden" name="view" value="disapproved">
                    <?php endif; ?>
                    <div class="row">
                        <div class="col">
                            <div class="input-group">
                                <input type="text" name="search" id="searchInput" class="form-control" placeholder="Search by ID, student number, student name, or document..."
                                       value="<?php echo htmlspecialchars($search); ?>">
                                <button class="btn btn-outline-secondary" type="submit">
                                    <i class="fas fa-search"></i> Search
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php if ($search !== ''): ?>
                    <div class="mt-2">
                        <a href="?<?php echo $view_disapproved ? 'view=disapproved' : ''; ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-times me-1"></i>Clear Search
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
                        <thead class="table-light">
                            <tr>
                                <th class="text-center"><a href="<?php echo getHeaderUrl('id', $sort_column, $next_order); ?>">ID<?php echo $sort_column === 'id' ? ($sort_order === 'asc' ? '▲' : '▼') : ''; ?></a></th>
                                <th class="text-center">Document Requested</th>
                                <th class="text-center"><a href="<?php echo getHeaderUrl('student_number', $sort_column, $next_order); ?>">Student No.<?php echo $sort_column === 'student_number' ? ($sort_order === 'asc' ? '▲' : '▼') : ''; ?></a></th>
                                <th class="text-center"><a href="<?php echo getHeaderUrl('student_name', $sort_column, $next_order); ?>">Student Name<?php echo $sort_column === 'student_name' ? ($sort_order === 'asc' ? '▲' : '▼') : ''; ?></a></th>
                                <th class="text-center">Program</th>
                                <th class="text-center">Purpose</th>
                                <?php if ($view_disapproved): ?>
                                <th class="text-center">Reviewed By</th>
                                <?php endif; ?>
                                <th class="text-center"><a href="<?php echo getHeaderUrl('created_at', $sort_column, $next_order); ?>">Submitted<?php echo $sort_column === 'created_at' ? ($sort_order === 'asc' ? '▲' : '▼') : ''; ?></a></th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="requestsTableBody">
                        <?php if (empty($online_requests)): ?>
                            <tr>
                                <td colspan="9" class="text-center">
                                    <?php echo $view_disapproved ? 'No disapproved requests.' : 'No pending online requests.'; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($online_requests as $request): ?>
                            <tr>
                                <td class="text-center"><?php echo $request['id']; ?></td>
                                <td class="text-center">
                                    <?php echo htmlspecialchars($request['title']); ?>
                                    <?php $doc_count = count_submitted_documents($request); ?>
                                    <?php if ($doc_count > 0): ?>
                                        <span class="badge bg-light text-dark border ms-1" title="Uploaded document images">
                                            <i class="fas fa-paperclip"></i> <?php echo $doc_count; ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?php echo htmlspecialchars($request['student_number']); ?></td>
                                <td class="text-center"><?php echo htmlspecialchars($request['student_name']); ?></td>
                                <td class="text-center"><?php echo htmlspecialchars($request['program']); ?></td>
                                <td class="text-center"><?php echo htmlspecialchars($request['purpose']); ?></td>
                                <?php if ($view_disapproved): ?>
                                <td class="text-center"><?php echo htmlspecialchars($request['reviewer_name'] ?? 'Unknown'); ?></td>
                                <?php endif; ?>
                                <td class="text-center"><?php echo date('Y-m-d H:i', strtotime($request['created_at'])); ?></td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-info" data-bs-toggle="modal"
                                            data-bs-target="#viewOnlineRequestModal<?php echo $request['id']; ?>" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if (!$view_disapproved && $is_admin_or_staff): ?>
                                    <button class="btn btn-sm btn-success" data-bs-toggle="modal"
                                            data-bs-target="#approveModal<?php echo $request['id']; ?>" title="Approve">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger" data-bs-toggle="modal"
                                            data-bs-target="#disapproveModal<?php echo $request['id']; ?>" title="Disapprove">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <!-- View Details modal -->
                            <div class="modal fade" id="viewOnlineRequestModal<?php echo $request['id']; ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Online Request Details</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <h6>Document Requested</h6>
                                            <p><?php echo htmlspecialchars($request['title']); ?></p>

                                            <h6>Student Information</h6>
                                            <div class="mb-2"><strong>Student Number:</strong> <?php echo htmlspecialchars($request['student_number']); ?></div>
                                            <div class="mb-2"><strong>Student Name:</strong> <?php echo htmlspecialchars($request['student_name']); ?></div>
                                            <div class="mb-2"><strong>Program:</strong> <?php echo htmlspecialchars($request['program']); ?></div>
                                            <div class="mb-2"><strong>Contact Number:</strong> <?php echo htmlspecialchars($request['contact_number']); ?></div>
                                            <div class="mb-3"><strong>Email:</strong> <?php echo htmlspecialchars($request['email_address']); ?></div>

                                            <h6>Request Information</h6>
                                            <div class="mb-2"><strong>Purpose:</strong> <?php echo htmlspecialchars($request['purpose']); ?></div>
                                            <div class="mb-2">
                                                <strong>Status:</strong>
                                                <span class="badge bg-<?php
                                                    echo match($request['status']) {
                                                        'pending' => 'warning',
                                                        'disapproved' => 'danger',
                                                        'approved' => 'success',
                                                        default => 'secondary'
                                                    };
                                                ?> status-badge"><?php echo htmlspecialchars($request['status']); ?></span>
                                            </div>
                                            <div class="mb-3"><strong>Submitted:</strong> <?php echo date('F d, Y H:i', strtotime($request['created_at'])); ?></div>

                                            <?php if (!empty($request['notes'])): ?>
                                            <h6>Additional Notes</h6>
                                            <p><?php echo nl2br(htmlspecialchars($request['notes'])); ?></p>
                                            <?php endif; ?>

                                            <?php
                                            // Empty string when this request has no uploads at
                                            // all (every request submitted before image uploads
                                            // existed), so nothing is printed in that case.
                                            echo render_submitted_documents($request);
                                            ?>

                                            <?php if ($request['status'] === 'disapproved' && !empty($request['disapproval_reason'])): ?>
                                            <h6>Disapproval Reason</h6>
                                            <p><?php echo nl2br(htmlspecialchars($request['disapproval_reason'])); ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <?php if (!$view_disapproved && $is_admin_or_staff): ?>
                            <!-- Approve modal -->
                            <div class="modal fade" id="approveModal<?php echo $request['id']; ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form method="POST">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Approve Request</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <p>This creates a new request from <strong><?php echo htmlspecialchars($request['student_name']); ?></strong>'s
                                                   submission for <strong><?php echo htmlspecialchars($request['title']); ?></strong>, which will then
                                                   appear in the normal Requests queue.</p>
                                                <input type="hidden" name="online_request_id" value="<?php echo $request['id']; ?>">
                                                <input type="hidden" name="approve_online_request" value="1">
                                                <div class="mb-3">
                                                    <label class="form-label"><strong>Processed By</strong></label>
                                                    <select name="clerk" class="dropdown" required>
                                                        <option value="">Select staff</option>
                                                        <?php foreach ($staffs as $staff): ?>
                                                        <option value="<?= $staff['id']; ?>"><?= htmlspecialchars($staff['staff_name']); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label"><strong>Claiming Date</strong> (optional, can be set later)</label>
                                                    <input type="date" name="claiming_date" class="form-control" min="<?php echo date('Y-m-d'); ?>">
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-success">Approve</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- Disapprove modal -->
                            <div class="modal fade" id="disapproveModal<?php echo $request['id']; ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form method="POST">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Disapprove Request</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <p class="text-danger"><strong>This request will not be created in the Requests queue.</strong> It stays on record under Disapproved Requests.</p>
                                                <input type="hidden" name="online_request_id" value="<?php echo $request['id']; ?>">
                                                <input type="hidden" name="disapprove_online_request" value="1">
                                                <div class="mb-3">
                                                    <label class="form-label"><strong>Processed By</strong></label>
                                                    <select name="clerk" class="dropdown" required>
                                                        <option value="">Select staff</option>
                                                        <?php foreach ($staffs as $staff): ?>
                                                        <option value="<?= $staff['id']; ?>"><?= htmlspecialchars($staff['staff_name']); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label"><strong>Reason</strong> (optional)</label>
                                                    <textarea name="disapproval_reason" rows="3" placeholder="e.g. missing/invalid student number"></textarea>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-danger">Disapprove</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

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
                        <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php if ($view_disapproved) echo '&view=disapproved'; ?>" aria-label="Previous">
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
                        <a class="page-link" href="?page=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php if ($view_disapproved) echo '&view=disapproved'; ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php if ($view_disapproved) echo '&view=disapproved'; ?>" aria-label="Next">
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/sidebar.js" defer></script>
<?php echo render_document_lightbox(); ?>
</body>
</html>