<?php
/**
 * requests_logic.php
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

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Unique token to prevent resubmission
if (empty($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
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

// ---------------------------------------------------------------------
// Create a new request
// ---------------------------------------------------------------------
$is_create_request = (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && !isset($_POST['update_status'])
    && !isset($_POST['update_claiming_date'])
    && !isset($_POST['archive_action'])
    && !isset($_POST['update_released_date'])
    && $is_admin_or_staff
);

$disable_input = false;

if ($is_create_request) {
    $disable_input = true;
    $required_fields = ['student_number', 'student_name', 'program', 'year_graduation', 'contact', 'purpose'];
    $has_all_required = true;
    foreach ($required_fields as $field) {
        if (!isset($_POST[$field])) {
            $has_all_required = false;
            break;
        }
    }

    if ($has_all_required && !empty($_POST['document']) && is_array($_POST['document'])) {
        try {
            $postToken = $_POST['submit_token'] ?? '';
            $sessionToken = $_SESSION['form_token'] ?? '';

            if (empty($postToken) || empty($sessionToken) || !hash_equals($sessionToken, $postToken)) {
                $_SESSION['error'] = 'Error creating request: Duplicate submission or invalid request.';
            }

            unset($_SESSION['form_token']);

            $student_number  = $_POST['student_number'];
            $student_name    = $_POST['student_name'];
            $program         = ($_POST['program'] === 'others') ? ($_POST['others_program'] ?? '') : $_POST['program'];
            $year_graduation = $_POST['year_graduation'];
            $contact         = $_POST['contact'];
            $purpose         = ($_POST['purpose'] === 'Others') ? ($_POST['others_purpose'] ?? '') : $_POST['purpose'];
            $staff_id        = (int)($_POST['clerk'] ?? 0);
            $contact_is_email = (($_POST['contact_choice'] ?? '') === 'email');

            // One request row is created per checked document, since each
            // document line has its own quantity and claiming date.
            foreach ($_POST['document'] as $document) {
                $claiming_date  = $_POST[$document . '_claiming_date'] ?? '';
                $document_qty   = $_POST[$document . '_quantity'] ?? '';
                
                for($i = 0; $i < $document_qty; $i++){
                    if ($document === 'certification') {
                    $cert_type = $_POST['certification_type'] ?? '';
                    $doc_label = 'Certification: ' . $cert_type;
                } elseif ($document === 'others') {
                    $docs_type = $_POST['others_type'] ?? '';
                    $doc_label = $docs_type;
                } elseif (isset(DOCUMENT_LABELS[$document])) {
                    $doc_label = DOCUMENT_LABELS[$document];
                } else {
                    continue; // unrecognized document key, skip it
                }

                $title = $doc_label;

                $description = "Student Number: {$student_number}\n"
                    . "Student Name: {$student_name}\n"
                    . "Program: {$program}\n"
                    . "Year of Graduation: {$year_graduation}\n"
                    . "Contact Information: {$contact}\n"
                    . "Purpose: {$purpose}\n"
                    . "Requested Documents: {$doc_label}";

                $claiming_date_value = null;
                if ($claiming_date !== '') {
                    $claiming_date_value = date('Y-m-d', strtotime($claiming_date));
                    $description .= "\nScheduled Claiming Date: " . $claiming_date_value;
                }

                $email_address = $contact_is_email ? $contact : null;

                $stmt = $conn->prepare(
                    'INSERT INTO requests (title, student_number, student_name, description, requester_id, claiming_date, email_address)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->bind_param(
                    'ssssiss',
                    $title,
                    $student_number,
                    $student_name,
                    $description,
                    $staff_id,
                    $claiming_date_value,
                    $email_address
                );
                $stmt->execute();
                $stmt->close();

                log_audit($conn, $staff_id, 'create_request', "Created new request: {$title}");
                }

            }

            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
            $disable_input = false;
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error creating request: ' . $e->getMessage();
            $disable_input = false;
        }
    }
}

// ---------------------------------------------------------------------
// Permanent deletion (archived items only, per the UI)
// ---------------------------------------------------------------------
if (isset($_POST['delete']) && $is_admin_or_staff) {
    $request_id = (int)($_POST['request_id'] ?? 0);

    $stmt = $conn->prepare('SELECT id, document_path, requester_id FROM requests WHERE id = ?');
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $check_result = $stmt->get_result();
    $stmt->close();

    if ($check_result && $check_result->num_rows > 0) {
        $request = $check_result->fetch_assoc();

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('DELETE FROM requests WHERE id = ?');
            $stmt->bind_param('i', $request_id);
            $stmt->execute();
            $stmt->close();

            log_audit($conn, (int)$request['requester_id'], 'delete_request', "Permanently deleted request #{$request_id}");

            if (!empty($request['document_path']) && file_exists($request['document_path'])) {
                unlink($request['document_path']);
            }

            $conn->commit();
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = 'Error deleting request: ' . $e->getMessage();
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
    }
}

// ---------------------------------------------------------------------
// Inline status update
// ---------------------------------------------------------------------
if (isset($_POST['update_status']) && isset($_POST['request_id']) && isset($_POST['new_status']) && $is_admin_or_staff) {
    $request_id = (int)$_POST['request_id'];
    $new_status = $_POST['new_status'];
    $is_ajax    = !empty($_POST['is_ajax']);

    $respond_error = function (string $message) use ($is_ajax) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => $message]);
        } else {
            $_SESSION['error'] = $message;
            header('Location: ' . requests_redirect_url());
        }
        exit();
    };

    if (!in_array($new_status, ALLOWED_STATUSES, true)) {
        $respond_error('Invalid status.');
    }

    $stmt = $conn->prepare('SELECT id, status, requester_id, updated_at FROM requests WHERE id = ?');
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $check_result = $stmt->get_result();
    $stmt->close();

    if (!$check_result || $check_result->num_rows === 0) {
        $respond_error('Request not found.');
    }

    $current_request = $check_result->fetch_assoc();
    $old_status = $current_request['status'];

    $conn->begin_transaction();
    try {
        $is_archived = ($new_status === 'released') ? 1 : 0;
        $updated_at = $current_request['updated_at'];

        if ($new_status === 'released') {
            $stmt = $conn->prepare('UPDATE requests SET status = ?, is_archived = ?, released_at = NOW() WHERE id = ?');
            $stmt->bind_param('sii', $new_status, $is_archived, $request_id);
        } else {
            $stmt = $conn->prepare('UPDATE requests SET status = ?, is_archived = ?, updated_at = ? WHERE id = ?');
            $stmt->bind_param('sisi', $new_status, $is_archived, $updated_at, $request_id);
        }
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to update request: ' . $conn->error);
        }
        $stmt->close();

        $log_details = "Updated request #{$request_id} status from '{$old_status}' to '{$new_status}'";

        $archived = 'archived';
        $staff_id = (int)$current_request['requester_id'];

        if ($new_status === 'released') {
            $log_details .= ' and automatically archived';
            $archive_sql = "INSERT INTO archive_history (request_id, action, actioned_by) 
                                   VALUES (?, ?, ?)";
            $stmt = $conn->prepare($archive_sql);
            $stmt->bind_param('isi', $request_id, $archived, $staff_id);
            $stmt->execute();
            $stmt->close();
        }

        log_audit($conn, (int)$current_request['requester_id'], 'update_request', $log_details);

        $conn->commit();

        if ($is_ajax) {
            header('Content-Type: application/json');
            $response = ['status' => 'success', 'message' => 'Status updated successfully.'];
            if ($new_status === 'released') {
                $response['message'] = 'Request marked as released and automatically archived.';
                $response['action'] = 'remove_row';
            }
            echo json_encode($response);
            exit();
        }

        $_SESSION['success'] = ($new_status === 'released')
            ? 'Request marked as released and automatically archived.'
            : 'Request status updated successfully.';
        header('Location: ' . requests_redirect_url());
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        error_log('Request update error: ' . $e->getMessage());
        $respond_error('Error updating request: ' . $e->getMessage());
    }
}

// Are you bored looking at this codes? Me too.

// ---------------------------------------------------------------------
// Claiming date update only
// ---------------------------------------------------------------------
if (isset($_POST['update_claiming_date']) && isset($_POST['request_id']) && $is_admin_or_staff) {
    $request_id = (int)$_POST['request_id'];

    $claiming_date = null;
    if (!empty($_POST['claiming_date'])) {
        $claiming_date = $_POST['claiming_date'];
    }

    $stmt = $conn->prepare('SELECT id, claiming_date, requester_id FROM requests WHERE id = ?');
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $check_result = $stmt->get_result();
    $stmt->close();

    if ($check_result && $check_result->num_rows > 0) {
        $current_request = $check_result->fetch_assoc();
        $old_claiming_date = $current_request['claiming_date'];

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('UPDATE requests SET claiming_date = ? WHERE id = ?');
            $stmt->bind_param('si', $claiming_date, $request_id);

            if ($stmt->execute()) {
                $stmt->close();

                $log_details = "Updated request #{$request_id}";
                if ($old_claiming_date !== $claiming_date) {
                    if ($claiming_date && $old_claiming_date) {
                        $log_details .= ' claiming date from ' . date('Y-m-d', strtotime($old_claiming_date))
                            . ' to ' . date('Y-m-d', strtotime($claiming_date));
                    } elseif ($claiming_date && !$old_claiming_date) {
                        $log_details .= ' added claiming date ' . date('Y-m-d', strtotime($claiming_date));
                    } elseif (!$claiming_date && $old_claiming_date) {
                        $log_details .= ' removed claiming date';
                    }
                }

                log_audit($conn, (int)$current_request['requester_id'], 'update_request', $log_details);

                $conn->commit();
                $_SESSION['success'] = 'Claiming date updated successfully.';
                header('Location: ' . requests_redirect_url());
                exit();
            }

            throw new Exception('Failed to update request: ' . $conn->error);
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = 'Error updating claiming date: ' . $e->getMessage();
            error_log('Claiming date update error: ' . $e->getMessage());
            header('Location: ' . requests_redirect_url());
            exit();
        }
    } else {
        $_SESSION['error'] = 'Request not found.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
}

// ---------------------------------------------------------------------
// Released date update
// ---------------------------------------------------------------------
if (isset($_POST['update_released_date']) && isset($_POST['request_id']) && $is_admin_or_staff) {
    $request_id = (int)$_POST['request_id'];
    $new_released_date = $_POST['released_date'] ?? '';

    if ($new_released_date === '') {
        $_SESSION['error'] = 'Released date cannot be empty.';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?view=released');
        exit();
    }

    $stmt = $conn->prepare("SELECT id, released_at, requester_id FROM requests WHERE id = ? AND status = 'released'");
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
$sort_column = (isset($_GET['sort']) && in_array($_GET['sort'], $sortable_columns, true)) ? $_GET['sort'] : 'claiming_date';
$sort_order  = (isset($_GET['order']) && $_GET['order'] === 'desc') ? 'desc' : 'asc';
$next_order  = ($sort_order === 'asc') ? 'desc' : 'asc';

$view_archived = isset($_GET['view']) && $_GET['view'] === 'archived';
$view_released = isset($_GET['view']) && $_GET['view'] === 'released';

$conditions = [];
$params = [];
$types = '';

$conditions[] = "(status IS NOT NULL OR status != 'released')";
$conditions[] = 'is_archived = 0';


if ($search !== '') {
    $conditions[] = '(title LIKE ? OR student_number LIKE ? OR student_name LIKE ? OR description LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}

if ($status_filter !== '') {
    $conditions[] = 'status = ?';
    $params[] = $status_filter;
    $types .= 's';
}

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

// Full set for this view (used to render modals for every row)
$all_requests_sql = "SELECT r.*, u.staff_name as requester_name
                     FROM requests r
                     LEFT JOIN staffs u ON r.requester_id = u.id
                     {$where_clause}
                     ORDER BY r.created_at DESC";
$stmt = $conn->prepare($all_requests_sql);
stmt_execute_with_params($stmt, $types, $params);
$all_requests_result = $stmt->get_result();

$all_requests = [];
if ($all_requests_result) {
    while ($row = $all_requests_result->fetch_assoc()) {
        $all_requests[] = $row;
    }
}
$stmt->close();

$page_title = 'Active Requests';

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
        .status-badge {
            text-transform: capitalize;
        }
        .claiming-date-overdue {
            color: #dc3545;
            font-weight: bold;
        }
        .claiming-date-today {
            color: #fd7e14;
            font-weight: bold;
        }
        .claiming-date-upcoming {
            color: #198754;
        }
        .pagination-info {
            font-size: 0.9em;
            color: #6c757d;
        }
        
        /* Status dropdown styling */
        .status-dropdown {
            border: none;
            background: transparent;
            font-size: 0.875rem;
            padding: 2px 8px;
            border-radius: 4px;
            color: white;
            cursor: pointer;
            min-width: 120px;
        }
        
        .status-dropdown:focus {
            outline: 2px solid rgba(255, 255, 255, 0.5);
        }
        
        .status-dropdown.status-pending {
            background-color: #ffc107;
            color: #000;
        }
        
        .status-dropdown.status-processing {
            background-color: #0dcaf0;
            color: #000;
        }
        
        .status-dropdown.status-for_signature {
            background-color: #0d6efd;
        }
        
        .status-dropdown.status-for_release {
            background-color: #6c757d;
        }
        
        .status-dropdown.status-released {
            background-color: #198754;
        }
        
        /* View buttons styling */
        .view-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .view-buttons .btn {
            font-size: 0.875rem;
            padding: 6px 12px;
        }
        
        @media (max-width: 768px) {
            .view-buttons {
                flex-direction: column;
                width: 100%;
            }
            
            .view-buttons .btn {
                width: 100%;
                margin-bottom: 4px;
            }
        }
        
        a {
            color: #000;
            text-decoration: none;
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
        .table-responsive{
            overflow: auto;
            height: 70vh;
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
        /* Enhanced search button styling */
        .btn-outline-secondary {
            padding: 10px 20px !important;
            border: 1px solid var(--green-main) !important;
            color: var(--green-main) !important;
            background-color: white !important;
            font-weight: 500;
            transition: all 0.3s ease;
            border-left: none !important;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-outline-secondary:hover {
            background-color: var(--green-main) !important;
            color: white !important;
            border-color: var(--green-main) !important;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(76, 175, 80, 0.2);
        }

        .btn-outline-secondary:focus {
            box-shadow: 0 0 0 0.2rem rgba(76, 175, 80, 0.25) !important;
            border-color: var(--green-main) !important;
        }

        .btn-outline-secondary:active {
            background-color: var(--green-dark) !important;
            border-color: var(--green-dark) !important;
            transform: translateY(0px);
        }

        .input-group {
            display: flex;
            align-items: stretch;
        }

        .input-group .form-control {
            border-right: none;
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
            height: 40px;
            padding: 10px;
        }

        .input-group .btn {
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
            border-left: none;
            flex-shrink: 0;
        }

        .input-group .form-control:focus {
            border-color: var(--green-main);
            box-shadow: none;
        }

        .input-group .form-control:focus + .btn-outline-secondary {
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
                     <a href="online_requests.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'online_requests.php' ? 'active' : ''; ?>">
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
                    <?php if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'staff'): ?>
                    <div class="d-flex gap-2 flex-wrap">
                        <?php if (!$view_archived && !$view_released): ?>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newRequestModal">
                            <i class="fas fa-plus me-2"></i>New Request
                        </button>
                        <?php endif; ?>
                        
                    </div>
                    <?php endif; ?>
                </div>

                <form class="mb-4" method="GET" onsubmit="return false;"> <?php if ($view_archived): ?>
                    <input type="hidden" name="view" value="archived">
                    <?php elseif ($view_released): ?>
                    <input type="hidden" name="view" value="released">
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-8">
                            <div class="input-group">
                                <input type="text" name="search" id="searchInput" class="form-control" placeholder="Search requests..." 
                                       value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                                <button class="btn btn-outline-secondary" type="submit">
                                    <i class="fas fa-search"></i> Search
                                </button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <select name="status" class="form-select" onchange="this.form.submit()">
                                <option value="">All Status</option>
                                <?php if (!$view_released): ?>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="processing" <?php echo $status_filter === 'processing' ? 'selected' : ''; ?>>Processing</option>
                                <option value="for_signature" <?php echo $status_filter === 'for_signature' ? 'selected' : ''; ?>>For Signature</option>
                                <option value="for_release" <?php echo $status_filter === 'for_release' ? 'selected' : ''; ?>>For Release</option>
                                <?php endif; ?>
                                <?php if ($view_released || $view_archived): ?>
                                <option value="released" <?php echo $status_filter === 'released' ? 'selected' : ''; ?>>Released</option>
                                <?php endif; ?>
                            </select>
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
                                    <form method="POST" style="display: inline;" class="status-update-form">
                                        <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                        <input type="hidden" name="update_status" value="1">
                                        <select name="new_status" class="status-dropdown status-select status-<?php echo $request['status']; ?>" data-original-status="<?php echo $request['status']; ?>">
                                            <option value="pending" <?php echo $request['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="processing" <?php echo $request['status'] === 'processing' ? 'selected' : ''; ?>>Processing</option>
                                            <option value="for_signature" <?php echo $request['status'] === 'for_signature' ? 'selected' : ''; ?>>For Signature</option>
                                            <option value="for_release" <?php echo $request['status'] === 'for_release' ? 'selected' : ''; ?>>For Release</option>
                                            <option value="released" <?php echo $request['status'] === 'released' ? 'selected' : ''; ?>>Released</option>
                                        </select>
                                    </form>
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
                                    <?php if (!empty($request['requester_name'])): ?>
                                        <span class="badge bg-info">
                                            <i classstyle="display: none;"="fas fa-user me-1"></i>
                                            <?php echo htmlspecialchars($request['requester_name']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">Unknown</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?php echo date('Y-m-d H:i', strtotime($request['created_at'])); ?></td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-info" data-bs-toggle="modal" 
                                            data-bs-target="#viewRequestModal<?php echo $request['id']; ?>">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'staff'): ?>
                                    <?php if (!$view_released): ?>
                                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" 
                                            data-bs-target="#updateClaimingDateModal<?php echo $request['id']; ?>"
                                            title="Update Claiming Date">
                                        <i class="fas fa-calendar-alt"></i>
                                    </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'staff'): ?>

                                            <button class="btn btn-sm btn-danger" data-bs-toggle="modal" 
                                                    data-bs-target="#deleteModal<?php echo $request['id']; ?>"
                                                    title="Delete Permanently">
                                                <i class="fas fa-trash"></i>
                                            </button>

                                    <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <div class="modal fade" id="viewRequestModal<?php echo $request['id']; ?>" tabindex="-1">
                                <div class="modal-dialog">
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
                                                <?php echo !empty($request['requester_name']) ? htmlspecialchars($request['requester_name']) : 'Unknown'; ?>
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
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <?php if (($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'staff') && !$view_released): ?>
                            <div class="modal fade" id="updateClaimingDateModal<?php echo $request['id']; ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Update Claiming Date</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <div class="modal-body">
                                                <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                                
                                                <div class="mb-3">
                                                    <label class="form-label">Student:</label>
                                                    <p class="form-text"><?php echo htmlspecialchars($request['student_name']); ?> (<?php echo htmlspecialchars($request['student_number']); ?>)</p>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label">Current Status:</label>
                                                    <p class="form-text">
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
                                                    </p>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label">Claiming Date</label>
                                                    <input type="date" name="claiming_date" class="form-control" 
                                                           value="<?php echo !empty($request['claiming_date']) ? date('Y-m-d', strtotime($request['claiming_date'])) : ''; ?>"
                                                           min="<?php echo date('Y-m-d'); ?>">
                                                    <small class="form-text text-muted">Leave empty to remove the claiming date</small>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" name="update_claiming_date" class="btn btn-primary">Update Claiming Date</button>
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

    <?php if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'staff'): ?>
    <div class="modal fade" id="newRequestModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Request Slip</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="row mb-3">
                            <div class="col-md-8">
                                <label class="form-label">Student Name <span class="text-danger">*</span></label>
                                <input type="text" name="student_name" class="form-control" required placeholder="Enter full name">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Student No. <span class="text-danger">*</span></label>
                                <input type="text" name="student_number" class="form-control numerical-only" required placeholder="Enter student number" 
                                       pattern="[0-9]*" inputmode="numeric" title="Please enter numbers only">
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-8">
                                <label class="form-label">Program <span class="text-danger">*</span></label>
                                <select name="program" id='program' class="dropdown" required onchange="toggleProgramOthersInput()">
                                    <?php foreach ($programs as $program): ?>
                                    <option value="<?= $program['program']; ?>"><?= htmlspecialchars($program['program_name']); ?></option>
                                    <?php endforeach; ?>
                                    <option value='others'>Others</option> 
                                </select>
                                <input type="text" style="display: none;" name="others_program" id="others_program" class="form-control" 
                                           placeholder="Specify program">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Year of Graduation</label>
                                <input type="text" name="year_graduation" class="form-control numerical-only" 
                                       pattern="[0-9]*" inputmode="numeric" placeholder="e.g. 2024" title="Please enter numbers only">
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Contact Information <span class="text-danger">*</span></label>
                                <select name="contact_choice" id='contact_choice' class="dropdown" required onchange="toggleContactInput()">
                                <option value='email'>Email</option>    
                                <option value='phone'>Phone Number</option> 
                                </select>
                                <input type="text" name="contact" id='contact' class="form-control" required placeholder="Enter email address" 
                                    inputmode="email" title="Please enter a valid email address (e.g., user@example.com)">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Clerk/Staff <span class="text-danger">*</span></label>
                                <select name="clerk" id ="clerk" class="dropdown" required>
                                    <?php foreach ($staffs_result as $staff): ?>
                                        <option value="<?= $staff['id']; ?>"><?= htmlspecialchars($staff['staff_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Please check your request: <span class="text-danger">*</span></label>
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th scope="col" class="text-center"> </th>    
                                        <th scope="col" class="text-center">Request</th>
                                        <th scope="col" class="text-center">Quantity</th>
                                        <th scope="col" class="text-center">Claiming Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td scope="row" class="text-center"><input class="form-check-input" type="checkbox" name="document[]" id="tor" value="tor" onchange="toggleQuantityAndDateInput('tor')"></td>
                                        <td>Transcript of Record (TOR)</td>
                                        <td><input disabled type="number" id="tor_quantity" name="tor_quantity" min="1" max="99"/></td>
                                        <td><input disabled id="tor_claiming_date" type="date" name="tor_claiming_date" class="form-control" 
                                       min="<?php echo date('Y-m-d'); ?>"></td>
                                    </tr>
                                    <tr>
                                        <td scope="row" class="text-center"><input class="form-check-input" type="checkbox" name="document[]" id="diploma" value="diploma" onchange="toggleQuantityAndDateInput('diploma')"></td>
                                        <td>Diploma</td>
                                        <td><input disabled type="number" id="diploma_quantity" name="diploma_quantity" min="1" max="99"/></td>
                                        <td><input disabled type="date" id="diploma_claiming_date" name="diploma_claiming_date" class="form-control" 
                                       min="<?php echo date('Y-m-d'); ?>"></td>
                                    </tr>
                                    <tr>
                                        <td scope="row" class="text-center"><input class="form-check-input" type="checkbox" name="document[]" id="cog" value="cog" onchange="toggleQuantityAndDateInput('cog')"></td>
                                        <td>Certificate of Grades (COG)</td>
                                        <td><input disabled type="number" id="cog_quantity" name="cog_quantity" min="1" max="99"/></td>
                                        <td><input disabled type="date" id="cog_claiming_date" name="cog_claiming_date" class="form-control" 
                                       min="<?php echo date('Y-m-d'); ?>"></td>
                                    </tr>
                                    <tr>
                                        <td scope="row" class="text-center"><input class="form-check-input" type="checkbox" name="document[]" id="coe" value="coe" onchange="toggleQuantityAndDateInput('coe')"></td>
                                        <td>Certificate of Enrollment (COE)</td>
                                        <td><input disabled type="number" id="coe_quantity" name="coe_quantity" min="1" max="99"/></td>
                                        <td><input disabled type="date" id="coe_claiming_date" name="coe_claiming_date" class="form-control" 
                                       min="<?php echo date('Y-m-d'); ?>"></td>
                                    </tr>
                                    <tr>
                                        <td scope="row" class="text-center"><input class="form-check-input" type="checkbox" name="document[]" id="form_137a" value="form_137a" onchange="toggleQuantityAndDateInput('form_137a')"></td>
                                        <td>Form 137A</td>
                                        <td><input disabled type="number" id="form_137a_quantity" name="form_137a_quantity" min="1" max="99"/></td>
                                        <td><input disabled type="date" id="form_137a_claiming_date" name="form_137a_claiming_date" class="form-control" 
                                       min="<?php echo date('Y-m-d'); ?>"></td>
                                    </tr>
                                    <tr>
                                        <td scope="row" class="text-center"><input class="form-check-input" type="checkbox" name="document[]" id="cav" value="cav" onchange="toggleQuantityAndDateInput('cav')"></td>
                                        <td>Certification Authentication and Verification (CAV)</td>
                                        <td><input disabled type="number" id="cav_quantity" name="cav_quantity" min="1" max="99"/></td>
                                        <td><input disabled type="date" id="cav_claiming_date" name="cav_claiming_date" class="form-control" 
                                       min="<?php echo date('Y-m-d'); ?>"></td>
                                    </tr>
                                    <tr>
                                        <td scope="row" class="text-center"><input class="form-check-input" type="checkbox" name="document[]" id="certification" value="certification" onchange="toggleCertificationInput()"></td>
                                        <td>Certification<input type="text" name="certification_type" id="certification_type" class="form-control mt-2" 
                                           placeholder="Specify certification type" style="display: none;"></td>
                                        <td><input disabled type="number" id="certification_quantity" name="certification_quantity" min="1" max="99"/></td>
                                        <td><input disabled type="date" id="certification_claiming_date" name="certification_claiming_date" class="form-control" 
                                       min="<?php echo date('Y-m-d'); ?>"></td>
                                    </tr>
                                    <tr>
                                        <th scope="row" class="text-center"><input class="form-check-input" type="checkbox" name="document[]" id="others" value="others" onchange="toggleOthersInput()"></th>
                                        <td>Others<input type="text" name="others_type" id="others_type" class="form-control mt-2" 
                                           placeholder="Specify document here" style="display: none;"></td>
                                        <td><input disabled type="number" id="others_quantity" name="others_quantity" min="1" max="99"/></td>
                                        <td><input disabled type="date" id="others_claiming_date" name="others_claiming_date" class="form-control" 
                                       min="<?php echo date('Y-m-d'); ?>"></td>
                                    </tr>
                                </tbody>
                            </table>
                            <small class="text-muted">Please select at least one document type.</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Purpose <span class="text-danger">*</span></label>
                                <select name="purpose" id="purpose" class="dropdown" required onchange="togglePurposeOthersInput()">
                                    <option value="Employment">Employment</option>
                                    <option value="Board Exam">Board Exam</option>
                                    <option value="Scholarship">Scholarship</option>
                                    <option value="Transfer">Transfer</option>
                                    <option value="Others">Others</option>
                                </select>
                            <textarea name="others_purpose" id="others_purpose" style="display: none;" class="form-control" rows="3" placeholder="Enter the purpose of your request"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" <?= $disable_input ? 'readonly' : '';?>>Create Request</button>
                        <input type="hidden" name="submit_token" value="<?php echo htmlspecialchars($_SESSION['form_token']); ?>">
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'staff'): ?>
    <?php foreach ($all_requests as $request): ?>
    
    <?php if (!$view_released): ?>
    <div class="modal fade" id="archiveModal<?php echo $request['id']; ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><?php echo ($view_archived || $view_released) ? 'Restore Request' : 'Archive Request'; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <p>Are you sure you want to <?php echo ($view_archived || $view_released) ? 'restore' : 'archive'; ?> this request?</p>
                        <p><strong>Title:</strong> <?php echo htmlspecialchars($request['title']); ?></p>
                        <p><strong>Student:</strong> <?php echo htmlspecialchars($request['student_name']); ?></p>
                        <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                        <input type="hidden" name="archive_action" value="<?php echo ($view_archived || $view_released) ? 'restore' : 'archive'; ?>">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-<?php echo ($view_archived || $view_released) ? 'success' : 'warning'; ?>">
                            <?php echo ($view_archived || $view_released) ? 'Restore' : 'Archive'; ?> Request
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="modal fade" id="deleteModal<?php echo $request['id']; ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Delete Request Permanently</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <p class="text-danger"><strong>Warning:</strong> This action cannot be undone!</p>
                        <p>Are you sure you want to permanently delete this request?</p>
                        <p><strong>Title:</strong> <?php echo htmlspecialchars($request['title']); ?></p>
                        <p><strong>Student:</strong> <?php echo htmlspecialchars($request['student_name']); ?></p>
                        <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                        <input type="hidden" name="delete" value="1">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete Permanently</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($view_released): ?>
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
    <?php endif; ?>
    <?php endforeach; ?>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script src="assets/js/request.js" defer></script>
</body>
</html>