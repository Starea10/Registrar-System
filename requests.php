<?php
session_start();
require_once 'includes/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Handle file upload and request creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['update_status']) && !isset($_POST['update_claiming_date']) &&
    !isset($_POST['archive_action']) && !isset($_POST['update_released_date']) && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'staff')) {
    
    // Verify all required fields are present
    if (isset($_POST['student_number'], $_POST['student_name'], $_POST['program'], $_POST['year_graduation'], $_POST['contact_no'], $_POST['purpose'])) {
        try{
            foreach($_POST['document'] as $document){
            $student_number = $conn->real_escape_string($_POST['student_number']);
            $student_name = $conn->real_escape_string($_POST['student_name']);
            $program = $conn->real_escape_string($_POST['program']);
            $year_graduation = $conn->real_escape_string($_POST['year_graduation']);
            $contact_no = $conn->real_escape_string($_POST['contact_no']);
            $purpose = $conn->real_escape_string($_POST['purpose']);

            // Handle claiming date
            $claiming_date_post = $document . '_claiming_date';
            $claiming_date = $conn->real_escape_string($_POST[$claiming_date_post]);

            // Handle document type checkboxes
            $document_qty_post = $document . '_quantity';
            $document_qty = $conn->real_escape_string($_POST[$document_qty_post]);
            $document_types = array();
            if ($document == 'tor') $document_types[] = $document_qty . 'x Transcript of Record (TOR)';
            if ($document == 'diploma') $document_types[] = $document_qty . 'x Diploma';
            if ($document == 'cog') $document_types[] = $document_qty . 'x Certificate of Grades (COG)';
            if ($document == 'coe') $document_types[] = $document_qty . 'x Certificate of Enrollment (COE)';
            if ($document == 'form_137a') $document_types[] = $document_qty . 'x Form 137A';
            if ($document == 'cav') $document_types[] = $document_qty . 'x Certification Authentication and Verification (CAV)';
            if ($document == 'certification') {
                $cert_type = $conn->real_escape_string($_POST['certification_type']);
                $document_types[] = $document_qty . 'x Certification: ' . $cert_type;
            }
            if ($document == 'others') {
                $docs_type = $conn->real_escape_string($_POST['others_type']);
                $document_types[] = $document_qty . 'x ' . $docs_type;
            }
            // Create title from document types
            $title = 'Request for : ' . implode(', ', $document_types);
        
            // Create description with all details
            $description = "Student Number: " . $student_number . "\n";
            $description .= "Student Name: " . $student_name . "\n";
            $description .= "Program: " . $program . "\n";
            $description .= "Year of Graduation: " . $year_graduation . "\n";
            $description .= "Contact Number: " . $contact_no . "\n";
            $description .= "Purpose: " . $purpose . "\n";
            $description .= "Requested Documents: " . implode(', ', $document_types);
            $description .= "\nScheduled Claiming Date: " . date('Y-m-d', strtotime($claiming_date));
            
            $claiming_date_sql = $claiming_date ? "'$claiming_date'" : "NULL";
            $sql = "INSERT INTO requests (title, student_number, student_name, description, requester_id, claiming_date) 
                    VALUES ('$title', '$student_number', '$student_name', '$description', {$_SESSION['user_id']}, $claiming_date_sql)";
            
            if ($conn->query($sql)) {
                // Log action
                $sql = "INSERT INTO audit_trail (user_id, action, details) 
                        VALUES ({$_SESSION['user_id']}, 'create_request', 'Created new request: $title')";
                $conn->query($sql);
                
            }
        }
        
        //Redirect to prevent form resubmission
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
        }
        catch (Exception $e){
            $_SESSION['error'] = "Error deleting request: " . $e->getMessage();
        }
        
    }
}

// Handle permanent deletion
if (isset($_POST['delete']) && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'staff')) {
    $request_id = (int)$_POST['request_id'];
    
    // Only allow deletion of archived items
    $check_sql = "SELECT id, document_path FROM requests WHERE id = $request_id";
    $check_result = $conn->query($check_sql);
    
    if ($check_result && $check_result->num_rows > 0) {
        $request = $check_result->fetch_assoc();
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Delete the request
            $sql = "DELETE FROM requests WHERE id = $request_id";
            $conn->query($sql);
            
            // Log the deletion
            $sql = "INSERT INTO audit_trail (user_id, action, details) 
                    VALUES ({$_SESSION['user_id']}, 'delete_request', 'Permanently deleted request #$request_id')";
            $conn->query($sql);
            
            // Delete the physical file if it exists
            if (!empty($request['document_path']) && file_exists($request['document_path'])) {
                unlink($request['document_path']);
            }
            
            // Commit the transaction
            $conn->commit();
            
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } catch (Exception $e) {
            // Rollback on error
            $conn->rollback();
            $_SESSION['error'] = "Error deleting request: " . $e->getMessage();
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
    }
}

// Handle archive/restore requests
if (isset($_POST['archive_action']) && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'staff')) {
    $request_id = (int)$_POST['request_id'];
    $action = $_POST['archive_action'];
    
    if ($action === 'archive' || $action === 'restore') {
        $is_archived = ($action === 'archive') ? 1 : 0;
        $sql = "UPDATE requests SET is_archived = $is_archived WHERE id = $request_id";
        
        if ($conn->query($sql)) {
            // Log the archive action
            $sql = "INSERT INTO archive_history (request_id, action, actioned_by) 
                    VALUES ($request_id, 'action', {$_SESSION['user_id']})";
            $conn->query($sql);
            
            // Log in audit trail
            $action_details = ($action === 'archive') ? 'Archived' : 'Restored';
            $sql = "INSERT INTO audit_trail (user_id, action, details) 
                    VALUES ({$_SESSION['user_id']}, '{$action}_request', '$action_details request #$request_id')";
            $conn->query($sql);
            
            header('Location: ' . $_SERVER['PHP_SELF'] . (isset($_GET['view']) ? '?view=' . $_GET['view'] : ''));
            exit();
        }
    }
}

// Handle inline status update
if (isset($_POST['update_status']) && isset($_POST['request_id']) && isset($_POST['new_status']) && 
    ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'staff')) {
    
    $request_id = (int)$_POST['request_id'];
    $new_status = $conn->real_escape_string($_POST['new_status']);
    
    // First verify the request exists and get current status for logging
    $check_sql = "SELECT id, status FROM requests WHERE id = $request_id";
    $check_result = $conn->query($check_sql);
    
    if ($check_result && $check_result->num_rows > 0) {
        $current_request = $check_result->fetch_assoc();
        $old_status = $current_request['status'];
        
        $conn->begin_transaction();
        
        try {
            $is_archived = ($new_status === 'released') ? 1 : 0;
            $released_at_sql = ($new_status === 'released') ? ", released_at = NOW()" : "";
            
            $update_sql = "UPDATE requests SET status = '$new_status', is_archived = $is_archived $released_at_sql WHERE id = $request_id";
            
            if ($conn->query($update_sql)) {
                $log_details = "Updated request #$request_id status from '$old_status' to '$new_status'";
                
                if ($new_status === 'released') {
                    $log_details .= " and automatically archived";
                    $archive_sql = "INSERT INTO archive_history 
                    SELECT * FROM requests 
                    WHERE id = $request_id;";
                    $conn->query($archive_sql);
                }

                //Audit the following changes
                $log_details_escaped = $conn->real_escape_string($log_details);
                $audit_sql = "INSERT INTO audit_trail (user_id, action, details) 
                             VALUES ({$_SESSION['user_id']}, 'update_request', '$log_details_escaped')";
                
                if ($conn->query($audit_sql)) {
                    $conn->commit();
                    
                    // Respond to AJAX request
                    if (!empty($_POST['is_ajax'])) {
                        header('Content-Type: application/json');
                        $response = [
                            'status' => 'success',
                            'message' => 'Status updated successfully.',
                        ];
                        if ($new_status === 'released') {
                            $response['message'] = 'Request marked as released and automatically archived.';
                            $response['action'] = 'remove_row';
                        }
                        echo json_encode($response);
                        exit();
                    }

                    // Fallback for non-AJAX
                    $_SESSION['success'] = ($new_status === 'released') ? "Request marked as released and automatically archived." : "Request status updated successfully.";
                    $redirect_url = $_SERVER['PHP_SELF'] . (isset($_GET['page']) ? '?page=' . $_GET['page'] : '') . (isset($_GET['view']) ? (strpos($_SERVER['PHP_SELF'], '?') ? '&' : '?') . 'view=' . $_GET['view'] : '');
                    
                    //Delete the row from the active requests table
                    $delete_sql = "DELETE FROM requests
                    WHERE requests . id = $request_id;";
                    $conn->query($delete_sql);
                    $conn->commit();

                    header('Location: ' . $redirect_url);
                    exit();
                } else {
                    throw new Exception("Failed to log audit trail: " . $conn->error);
                }
            } else {
                throw new Exception("Failed to update request: " . $conn->error);
            }
        } catch (Exception $e) {
            $conn->rollback();
            
            // Respond to AJAX request
            if (!empty($_POST['is_ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                exit();
            }

            // Fallback for non-AJAX
            $_SESSION['error'] = "Error updating request: " . $e->getMessage();
            error_log("Request update error: " . $e->getMessage());
            $redirect_url = $_SERVER['PHP_SELF'] . (isset($_GET['page']) ? '?page=' . $_GET['page'] : '') . (isset($_GET['view']) ? (strpos($_SERVER['PHP_SELF'], '?') ? '&' : '?') . 'view=' . $_GET['view'] : '');
            header('Location: ' . $redirect_url);
            exit();
        }
    } else {
        // Respond to AJAX request
        if (!empty($_POST['is_ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Request not found.']);
            exit();
        }
        
        // Fallback for non-AJAX
        $_SESSION['error'] = "Request not found.";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Handle claiming date update only
if (isset($_POST['update_claiming_date']) && isset($_POST['request_id']) && 
    ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'staff')) {
    
    $request_id = (int)$_POST['request_id'];
    
    // Handle claiming date update
    $claiming_date = null;
    if (isset($_POST['claiming_date']) && !empty($_POST['claiming_date'])) {
        $claiming_date = $conn->real_escape_string($_POST['claiming_date']);
    }
    
    // First verify the request exists and get current claiming date for logging
    $check_sql = "SELECT id, claiming_date FROM requests WHERE id = $request_id";
    $check_result = $conn->query($check_sql);
    
    if ($check_result && $check_result->num_rows > 0) {
        $current_request = $check_result->fetch_assoc();
        $old_claiming_date = $current_request['claiming_date'];
        
        // Start transaction to ensure data consistency
        $conn->begin_transaction();
        
        try {
            // Update claiming date only
            $claiming_date_sql = $claiming_date ? "'$claiming_date'" : "NULL";
            $update_sql = "UPDATE requests SET claiming_date = $claiming_date_sql WHERE id = $request_id";
            
            if ($conn->query($update_sql)) {
                // Create detailed log message
                $log_details = "Updated request #$request_id";
                
                // Track claiming date change
                if ($old_claiming_date !== $claiming_date) {
                    if ($claiming_date && $old_claiming_date) {
                        $log_details .= " claiming date from " . date('Y-m-d', strtotime($old_claiming_date)) . " to " . date('Y-m-d', strtotime($claiming_date));
                    } elseif ($claiming_date && !$old_claiming_date) {
                        $log_details .= " added claiming date " . date('Y-m-d', strtotime($claiming_date));
                    } elseif (!$claiming_date && $old_claiming_date) {
                        $log_details .= " removed claiming date";
                    }
                }
                
                // Escape the log details for SQL injection prevention
                $log_details_escaped = $conn->real_escape_string($log_details);
                
                // Log action in audit trail
                $audit_sql = "INSERT INTO audit_trail (user_id, action, details) 
                             VALUES ({$_SESSION['user_id']}, 'update_request', '$log_details_escaped')";
                
                if ($conn->query($audit_sql)) {
                    // Commit the transaction
                    $conn->commit();
                    
                    // Set success message
                    $_SESSION['success'] = "Claiming date updated successfully.";
                    
                    // Redirect to prevent form resubmission
                    $redirect_url = $_SERVER['PHP_SELF'];
                    if (isset($_GET['page'])) {
                        $redirect_url .= '?page=' . $_GET['page'];
                    }
                    if (isset($_GET['view'])) {
                        $redirect_url .= (strpos($redirect_url, '?') !== false ? '&' : '?') . 'view=' . $_GET['view'];
                    }
                    
                    header('Location: ' . $redirect_url);
                    exit();
                } else {
                    // Audit trail insertion failed
                    throw new Exception("Failed to log audit trail: " . $conn->error);
                }
            } else {
                // Request update failed
                throw new Exception("Failed to update request: " . $conn->error);
            }
        } catch (Exception $e) {
            // Rollback the transaction
            $conn->rollback();
            $_SESSION['error'] = "Error updating claiming date: " . $e->getMessage();
            
            // Log the error for debugging
            error_log("Claiming date update error: " . $e->getMessage());
            
            // Redirect back with error
            $redirect_url = $_SERVER['PHP_SELF'];
            if (isset($_GET['page'])) {
                $redirect_url .= '?page=' . $_GET['page'];
            }
            if (isset($_GET['view'])) {
                $redirect_url .= (strpos($redirect_url, '?') !== false ? '&' : '?') . 'view=' . $_GET['view'];
            }
            
            header('Location: ' . $redirect_url);
            exit();
        }
    } else {
        $_SESSION['error'] = "Request not found.";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
}

// *** NEW *** Handle released date update
if (isset($_POST['update_released_date']) && isset($_POST['request_id']) && 
    ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'staff')) {
    
    $request_id = (int)$_POST['request_id'];
    $new_released_date = $conn->real_escape_string($_POST['released_date']);
    
    if (empty($new_released_date)) {
        $_SESSION['error'] = "Released date cannot be empty.";
        header('Location: ' . $_SERVER['PHP_SELF'] . '?view=released');
        exit();
    }
    
    // Verify the request exists and get the current released date for logging
    $check_sql = "SELECT id, released_at FROM requests WHERE id = $request_id AND status = 'released'";
    $check_result = $conn->query($check_sql);
    
    if ($check_result && $check_result->num_rows > 0) {
        $current_request = $check_result->fetch_assoc();
        $old_released_date = $current_request['released_at'] ? date('Y-m-d', strtotime($current_request['released_at'])) : 'N/A';
        
        $conn->begin_transaction();
        
        try {
            // Update the released_at timestamp
            $update_sql = "UPDATE requests SET released_at = '$new_released_date' WHERE id = $request_id";
            
            if ($conn->query($update_sql)) {
                // Log the action in the audit trail
                $log_details = "Updated released date for request #$request_id from '$old_released_date' to '$new_released_date'";
                $log_details_escaped = $conn->real_escape_string($log_details);
                
                $audit_sql = "INSERT INTO audit_trail (user_id, action, details) 
                             VALUES ({$_SESSION['user_id']}, 'update_released_date', '$log_details_escaped')";
                
                if ($conn->query($audit_sql)) {
                    $conn->commit();
                    $_SESSION['success'] = "Released date updated successfully.";
                } else {
                    throw new Exception("Failed to log action to audit trail: " . $conn->error);
                }
            } else {
                throw new Exception("Failed to update released date: " . $conn->error);
            }
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Error updating released date: " . $e->getMessage();
            error_log("Released date update error: " . $e->getMessage());
        }
    } else {
        $_SESSION['error'] = "Released request not found.";
    }
    
    // Redirect back to the released archive view
    $redirect_url = $_SERVER['PHP_SELF'] . '?view=released';
    if (isset($_GET['page'])) {
        $redirect_url .= '&page=' . $_GET['page'];
    }
    header('Location: ' . $redirect_url);
    exit();
}

// Get requests with pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 15; // Fixed to show exactly 15 rows per page
$offset = ($page - 1) * $per_page;

$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $conn->real_escape_string($_GET['status']) : '';

// Determine view type
$view_archived = isset($_GET['view']) && $_GET['view'] === 'archived';
$view_released = isset($_GET['view']) && $_GET['view'] === 'released';

// Build WHERE clause based on view
if ($view_released) {
    // Show only released (archived) requests
    $where_clause = "WHERE is_archived = 1 AND status = 'released'";
} elseif ($view_archived) {
    // Show all archived requests except released ones
    $where_clause = "WHERE is_archived = 1 AND status != 'released'";
} else {
    // Show only active (non-archived) requests
    $where_clause = "WHERE is_archived = 0";
}

// Add search and status filters
if ($search) {
    $where_clause .= " AND (title LIKE '%$search%' OR 
                       student_number LIKE '%$search%' OR 
                       student_name LIKE '%$search%' OR 
                       description LIKE '%$search%')";
}
if ($status_filter) {
    $where_clause .= " AND status = '$status_filter'";
}

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM requests $where_clause";
$total_result = $conn->query($count_sql);
$total = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total / $per_page);

// Get requests for current page
$sql = "SELECT r.*, u.username as requester_name 
        FROM requests r 
        LEFT JOIN users u ON r.requester_id = u.id 
        $where_clause 
        ORDER BY r.created_at DESC 
        LIMIT $offset, $per_page";
$requests_result = $conn->query($sql);

// Store requests in array for table display
$requests = array();
if ($requests_result) {
    while ($row = $requests_result->fetch_assoc()) {
        $requests[] = $row;
    }
}

// Get all requests for modals (needed for archive/delete modals)
$all_requests_sql = "SELECT r.*, u.username as requester_name 
                     FROM requests r 
                     LEFT JOIN users u ON r.requester_id = u.id 
                     $where_clause 
                     ORDER BY r.created_at DESC";
$all_requests_result = $conn->query($all_requests_sql);

$all_requests = array();
if ($all_requests_result) {
    while ($row = $all_requests_result->fetch_assoc()) {
        $all_requests[] = $row;
    }
}

if (isset($_GET['ajax'])) {
    ob_start(); // Start output buffering to capture HTML

    if (empty($requests)) {
        echo '<tr><td colspan="10" class="text-center">No requests found</td></tr>';
    } else {
        foreach ($requests as $request) { ?>
                            <tr>
                                <td><?php echo $request['id']; ?></td>
                                <td><?php echo htmlspecialchars($request['title']); ?></td>
                                <td><?php echo htmlspecialchars($request['student_number']); ?></td>
                                <td><?php echo htmlspecialchars($request['student_name']); ?></td>
                                <td>
                                    <?php if (($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'staff')): ?>
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
                                    <?php else: ?>
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
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($request['claiming_date'])): ?>
                                        <?php
                                        $claiming_date = new DateTime($request['claiming_date']);
                                        $today = new DateTime();
                                        $today->setTime(0, 0, 0);
                                        $claiming_date_start = clone $claiming_date;
                                        $claiming_date_start->setTime(0, 0, 0);
                                        
                                        $class = '';
                                        if ($claiming_date_start < $today) {
                                            $class = 'claiming-date-overdue';
                                        } elseif ($claiming_date_start == $today) {
                                            $class = 'claiming-date-today';
                                        } else {
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
                                <td>
                                    <?php if (!empty($request['requester_name'])): ?>
                                        <span class="badge bg-info">
                                            <i class="fas fa-user me-1"></i>
                                            <?php echo htmlspecialchars($request['requester_name']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">Unknown</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('Y-m-d H:i', strtotime($request['created_at'])); ?></td>
                                <td>
                                    <?php if ($view_released && !empty($request['released_at'])): ?>
                                        <?php echo date('Y-m-d H:i', strtotime($request['released_at'])); ?>
                                    <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
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
        <?php }
    }
    $table_html = ob_get_clean();

    $pagination_info = '';
    if ($total > 0) {
        $pagination_info = 'Showing ' . ($offset + 1) . ' to ' . min($offset + $per_page, $total) . ' of ' . $total . ' entries';
    }

    header('Content-Type: application/json');
    echo json_encode([
        'html' => $table_html,
        'info' => $pagination_info
    ]);
    exit();
}

// Determine page title based on view
$page_title = 'Active Requests';

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Requests - RMS</title>
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
                        <thead>
                            <tr>
                                <th class="text-center">ID</th>
                                <th class="text-center">Title</th>
                                <th class="text-center">Student No.</th>
                                <th class="text-center">Student Name</th>
                                <th class="text-center">Status</th>
                                <th class="text-center">Claim Date</th>
                                <th class="text-center">Processed By</th>
                                <th class="text-center">Created</th>
                                <th class="text-center">Released Date</th>
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
                                    <?php if (($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'staff') && !$view_archived && !$view_released): ?>
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
                                    <?php else: ?>
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
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if (!empty($request['claiming_date'])): ?>
                                        <?php
                                        $claiming_date = new DateTime($request['claiming_date']);
                                        $today = new DateTime();
                                        $today->setTime(0, 0, 0);
                                        $claiming_date_start = clone $claiming_date;
                                        $claiming_date_start->setTime(0, 0, 0);
                                        
                                        $class = '';
                                        if ($claiming_date_start < $today) {
                                            $class = 'claiming-date-overdue';
                                        } elseif ($claiming_date_start == $today) {
                                            $class = 'claiming-date-today';
                                        } else {
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
                                            <i class="fas fa-user me-1"></i>
                                            <?php echo htmlspecialchars($request['requester_name']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">Unknown</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?php echo date('Y-m-d H:i', strtotime($request['created_at'])); ?></td>
                                <td class="text-center">
                                    <?php if ($view_released && !empty($request['released_at'])): ?>
                                        <?php echo date('Y-m-d H:i', strtotime($request['released_at'])); ?>
                                    <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
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
                                <select name="program" class="dropdown" required>
                                    <option value="BSCS">BSCS</option>
                                    <option value="BSIT">BSIT</option>
                                    <option value="BSEd-Sci">BSEd-Sci</option>
                                    <option value="BSEd-Eng">BSEd-Eng</option>
                                    <option value="BEEd">BEEd</option>
                                    <option value="BSHM">BSHM</option>
                                    <option value="BSBA">BSBA</option>
                                    <option value="BSFAS">BSFAS</option>
                                    <option value="TCP">TCP</option>
                                    <option value="LSHS">LSHS</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Year of Graduation</label>
                                <input type="text" name="year_graduation" class="form-control numerical-only" 
                                       pattern="[0-9]*" inputmode="numeric" placeholder="e.g. 2024" title="Please enter numbers only">
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Contact No. <span class="text-danger">*</span></label>
                                <input type="text" name="contact_no" class="form-control numerical-only" required placeholder="Enter contact number" 
                                       pattern="[0-9]*" inputmode="numeric" title="Please enter numbers only">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Clerk/Staff <span class="text-danger">*</span></label>
                                <select name="clerk" class="dropdown" required>
                                    <option value="Admin">admin</option>
                                    <option value="Clerk 1">Clerk 1</option>
                                    <option value="Clerk 2">Clerk 2</option>
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
                                        <th scope="col" class="text-center">Qty</th>
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
                            <textarea name="purpose" class="form-control" rows="3" required placeholder="Enter the purpose of your request"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Request</button>
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