<?php
session_start();
require_once 'includes/config.php';

// Only allow authenticated users
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Set content type to JSON
header('Content-Type: application/json');

$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $conn->real_escape_string($_GET['status']) : '';
$view = isset($_GET['view']) ? $_GET['view'] : '';

// Determine view type
$view_archived = $view === 'archived';
$view_released = $view === 'released';

// Build WHERE clause based on view
if ($view_released) {
    $where_clause = "WHERE is_archived = 1 AND status = 'released'";
} elseif ($view_archived) {
    $where_clause = "WHERE is_archived = 1 AND status != 'released'";
} else {
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

// Get requests
$sql = "SELECT r.*, u.username as requester_name 
        FROM requests r 
        LEFT JOIN users u ON r.requester_id = u.id 
        $where_clause 
        ORDER BY r.created_at DESC 
        LIMIT 50"; // Limit results for performance

$result = $conn->query($sql);
$requests = array();

if ($result) {
    while ($row = $result->fetch_assoc()) {
        // Format claiming date
        $claiming_date_formatted = '';
        $claiming_date_class = '';
        if (!empty($row['claiming_date'])) {
            $claiming_date = new DateTime($row['claiming_date']);
            $today = new DateTime();
            $today->setTime(0, 0, 0);
            $claiming_date_start = clone $claiming_date;
            $claiming_date_start->setTime(0, 0, 0);
            
            if ($claiming_date_start < $today) {
                $claiming_date_class = 'claiming-date-overdue';
            } elseif ($claiming_date_start == $today) {
                $claiming_date_class = 'claiming-date-today';
            } else {
                $claiming_date_class = 'claiming-date-upcoming';
            }
            $claiming_date_formatted = $claiming_date->format('M d, Y');
        }
        
        $row['claiming_date_formatted'] = $claiming_date_formatted;
        $row['claiming_date_class'] = $claiming_date_class;
        $requests[] = $row;
    }
}

echo json_encode($requests);
?>