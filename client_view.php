<?php
require_once 'includes/config.php';

// Get requests with pagination (only non-archived requests)
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 15; // Set to show exactly 15 rows per page
$offset = ($page - 1) * $per_page;

$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $conn->real_escape_string($_GET['status']) : '';

// Build where clause (exclude archived requests and don't show student names)
$where_clause = "WHERE is_archived = 0";
if ($search) {
    // Search only in title, student_number, and status - not in student_name
    $where_clause .= " AND (title LIKE '%$search%' OR 
                       student_number LIKE '%$search%' OR 
                       status LIKE '%$search%')";
}
if ($status_filter) {
    $where_clause .= " AND status = '$status_filter'";
}

// Ensure valid page number based on total results
$count_sql = "SELECT COUNT(*) as total FROM requests $where_clause";
$count_result = $conn->query($count_sql);
$total_count = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_count / $per_page);
$page = min($page, max(1, $total_pages)); // Ensure page is within valid range
$offset = ($page - 1) * $per_page;

// Get the total count first
$count_sql = "SELECT COUNT(*) as total FROM requests $where_clause";
$count_result = $conn->query($count_sql);
$total = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total / $per_page);

// Get paginated results
$sql = "SELECT id, title, student_number, status, created_at, claiming_date 
        FROM requests 
        $where_clause 
        ORDER BY created_at DESC 
        LIMIT $per_page OFFSET $offset"; // Ensure exactly 15 rows per page
$requests_result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Tracker - Public View</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-green: #5D8736;
            --secondary-green: #A9C46C;
            --dark-green: #0A400C;
            --light-green: #E8F5E8;
            --accent-green: #809D3C;
            --text-dark: #2c3e50;
            --text-muted: #6c757d;
            --border-color: #e9ecef;
            --shadow-light: 0 2px 10px rgba(0,0,0,0.05);
            --shadow-medium: 0 5px 25px rgba(0,0,0,0.1);
            --shadow-heavy: 0 10px 40px rgba(0,0,0,0.15);
            --border-radius: 12px;
            --border-radius-large: 20px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, var(--secondary-green) 0%, var(--accent-green) 50%, var(--primary-green) 100%);
            min-height: 100vh;
            line-height: 1.6;
            color: var(--text-dark);
        }

        /* Loading Spinner */
        .loading-spinner {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 9999;
        }

        .spinner-border {
            width: 3rem;
            height: 3rem;
            color: var(--primary-green);
        }

        /* Main Container */
        .main-container {
            background: white;
            border-radius: var(--border-radius-large);
            box-shadow: var(--shadow-heavy);
            margin: 1rem;
            overflow: hidden;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        /* Header Section */
        .header-section {
            background: linear-gradient(135deg, var(--primary-green) 0%, var(--dark-green) 100%);
            color: white;
            padding: 2rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .header-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000"><circle cx="200" cy="200" r="100" fill="rgba(255,255,255,0.05)"/><circle cx="800" cy="300" r="150" fill="rgba(255,255,255,0.03)"/><circle cx="600" cy="700" r="80" fill="rgba(255,255,255,0.07)"/></svg>');
            pointer-events: none;
        }

        .header-section .container {
            position: relative;
            z-index: 2;
        }

        .header-section h1 {
            font-size: clamp(1.5rem, 4vw, 2.5rem);
            margin-bottom: 0.5rem;
            font-weight: 600;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }

        .header-section p {
            font-size: clamp(0.9rem, 2vw, 1.1rem);
            opacity: 0.95;
            margin-bottom: 0;
            font-weight: 300;
        }

        /* Content Section */
        .content-section {
            padding: clamp(1rem, 4vw, 2rem);
        }

        /* Public Notice */
        .public-notice {
            background: linear-gradient(135deg, #e3f2fd 0%, #f0f8ff 100%);
            border: 1px solid #bbdefb;
            border-radius: var(--border-radius);
            padding: 1.25rem;
            margin-bottom: 2rem;
            border-left: 4px solid #1976d2;
            box-shadow: var(--shadow-light);
            transition: var(--transition);
        }

        .public-notice:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }

        .public-notice i {
            color: #1976d2;
            font-size: 1.25rem;
        }

        /* Search Section */
        .search-section {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: var(--border-radius);
            padding: clamp(1rem, 3vw, 1.5rem);
            margin-bottom: 2rem;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
        }

        .search-section .form-label {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.75rem;
            font-size: 0.95rem;
        }

        .form-control, .form-select {
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius);
            padding: 0.75rem 1rem;
            font-size: 0.95rem;
            transition: var(--transition);
            background: white;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 0.25rem rgba(93, 135, 54, 0.15);
            transform: translateY(-1px);
        }

        .input-group-text {
            background: var(--primary-green);
            color: white;
            border: 2px solid var(--primary-green);
            border-radius: var(--border-radius) 0 0 var(--border-radius);
        }

        /* Buttons */
        .btn {
            border-radius: var(--border-radius);
            padding: 0.75rem 1.5rem;
            font-weight: 500;
            font-size: 0.95rem;
            transition: var(--transition);
            border: none;
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-green) 0%, var(--accent-green) 100%);
            box-shadow: 0 4px 15px rgba(93, 135, 54, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 25px rgba(93, 135, 54, 0.4);
        }

        .btn-outline-secondary {
            border: 2px solid var(--border-color);
            color: var(--text-muted);
            background: white;
        }

        .btn-outline-secondary:hover {
            background: var(--text-muted);
            border-color: var(--text-muted);
            color: white;
            transform: translateY(-1px);
        }

        /* Table Container */
        .table-container {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-medium);
            overflow: hidden;
            border: 1px solid var(--border-color);
        }

        .table-info {
            background: var(--light-green);
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.9rem;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
        }

        /* Responsive Table */
        .table-responsive {
            border-radius: var(--border-radius);
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .table {
            margin-bottom: 0;
            font-size: clamp(0.8rem, 2vw, 0.9rem);
        }

        .table thead th {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: none;
            font-weight: 600;
            padding: 1.25rem 1rem;
            color: var(--text-dark);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .table tbody tr {
            transition: var(--transition);
            border-bottom: 1px solid var(--border-color);
        }

        .table tbody tr:hover {
            background: var(--light-green);
            transform: scale(1.005);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .table tbody td {
            padding: 1.25rem 1rem;
            vertical-align: middle;
            border: none;
        }

        /* Status Badges */
        .status-badge {
            font-size: 0.8rem;
            padding: 0.5rem 0.75rem;
            border-radius: 20px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            border: 2px solid transparent;
            transition: var(--transition);
        }

        .status-badge:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }

        /* Claiming Date Styles */
        .claiming-date-overdue {
            color: #dc3545;
            font-weight: 600;
        }

        .claiming-date-today {
            color: #fd7e14;
            font-weight: 600;
        }

        .claiming-date-upcoming {
            color: #198754;
            font-weight: 500;
        }

        .claiming-date-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.6rem;
            border-radius: 15px;
            display: inline-block;
            margin-top: 0.25rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        /* Card View for Mobile */
        .card-view {
            display: none;
        }

        @media (max-width: 991.98px) {
            .table-responsive {
                display: none;
            }
            
            .card-view {
                display: block;
            }
            
            .request-card {
                background: white;
                border: 1px solid var(--border-color);
                border-radius: var(--border-radius);
                padding: 1.5rem;
                margin-bottom: 1rem;
                box-shadow: var(--shadow-light);
                transition: var(--transition);
            }
            
            .request-card:hover {
                transform: translateY(-2px);
                box-shadow: var(--shadow-medium);
            }
            
            .request-card-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-bottom: 1rem;
                flex-wrap: wrap;
                gap: 0.5rem;
            }
            
            .request-id {
                background: var(--primary-green);
                color: white;
                padding: 0.25rem 0.75rem;
                border-radius: 15px;
                font-size: 0.8rem;
                font-weight: 600;
            }
            
            .request-title {
                font-weight: 600;
                color: var(--text-dark);
                margin: 0.5rem 0;
                font-size: 1.1rem;
            }
            
            .request-info {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 1rem;
                margin-top: 1rem;
            }
            
            .info-item {
                display: flex;
                flex-direction: column;
                gap: 0.25rem;
            }
            
            .info-label {
                font-size: 0.8rem;
                color: var(--text-muted);
                text-transform: uppercase;
                font-weight: 600;
                letter-spacing: 0.5px;
            }
            
            .info-value {
                font-weight: 500;
                color: var(--text-dark);
            }
        }

        /* Pagination */
        .pagination {
            justify-content: center;
            margin-top: 2rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .pagination .page-item .page-link {
            border-radius: var(--border-radius);
            border: 2px solid var(--border-color);
            color: var(--primary-green);
            padding: 0.75rem 1rem;
            font-weight: 500;
            transition: var(--transition);
            margin: 0 0.25rem;
            background: white;
        }

        .pagination .page-item.active .page-link {
            background: linear-gradient(135deg, var(--primary-green) 0%, var(--accent-green) 100%);
            border-color: var(--primary-green);
            color: white;
            box-shadow: 0 4px 15px rgba(93, 135, 54, 0.3);
        }

        .pagination .page-item .page-link:hover {
            background: var(--primary-green);
            border-color: var(--primary-green);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(93, 135, 54, 0.3);
        }

        /* Footer */
        .footer {
            background: rgba(10, 64, 12, 0.9);
            color: white;
            padding: 2rem 0;
            margin-top: 2rem;
            backdrop-filter: blur(10px);
        }

        .footer a {
            color: var(--secondary-green);
            text-decoration: none;
            transition: var(--transition);
        }

        .footer a:hover {
            color: white;
            text-decoration: underline;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            opacity: 0.5;
        }

        .empty-state h5 {
            color: var(--text-dark);
            margin-bottom: 1rem;
        }

        /* Responsive Design */
        @media (max-width: 576px) {
            .main-container {
                margin: 0.5rem;
                border-radius: var(--border-radius);
            }
            
            .header-section {
                padding: 1.5rem 1rem;
            }
            
            .content-section {
                padding: 1rem;
            }
            
            .search-section {
                padding: 1rem;
            }
            
            .search-section .row {
                flex-direction: column;
            }
            
            .search-section .col-md-5,
            .search-section .col-md-2 {
                margin-bottom: 1rem;
            }
            
            .table-info {
                flex-direction: column;
                text-align: center;
                gap: 0.5rem;
            }
            
            .pagination .page-item .page-link {
                padding: 0.5rem 0.75rem;
                font-size: 0.9rem;
            }
        }

        @media (min-width: 992px) {
            .main-container {
                margin: 2rem auto;
                max-width: 1400px;
            }
        }

        /* Accessibility Improvements */
        .visually-hidden {
            position: absolute !important;
            width: 1px !important;
            height: 1px !important;
            padding: 0 !important;
            margin: -1px !important;
            overflow: hidden !important;
            clip: rect(0,0,0,0) !important;
            white-space: nowrap !important;
            border: 0 !important;
        }

        /* Focus states for keyboard navigation */
        .btn:focus,
        .form-control:focus,
        .form-select:focus,
        .page-link:focus {
            outline: 2px solid var(--primary-green);
            outline-offset: 2px;
        }

        /* Loading state */
        .loading {
            opacity: 0.6;
            pointer-events: none;
            transition: opacity 0.3s ease;
        }

        /* Smooth animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .fade-in {
            animation: fadeIn 0.6s ease-out;
        }

        /* Print styles */
        @media print {
            body {
                background: white;
            }
            
            .main-container {
                box-shadow: none;
                border: 1px solid #ddd;
            }
            
            .btn, .pagination, .search-section {
                display: none;
            }
        }
    </style>
</head>
<body>
    <!-- Loading Spinner -->
    <div class="loading-spinner" id="loadingSpinner">
        <div class="spinner-border" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>

    <div class="container-fluid">
        <div class="main-container fade-in">
            <!-- Header Section -->
            <div class="header-section row">
                <div class="container col-md-1">
                    <button onclick="window.location.href='index.php'" class="btn btn-sm btn-light">
                        <i class="fa-solid fa-house"></i>
                    </button>
                </div>
                <div class="container col">
                    <h1><i class="fas fa-clipboard-list me-3"></i>Cavite State University Naic - Office of the Campus Registrar</h1>
                    <p>Real-time Document Request Tracking System</p>                   
                </div>
            </div>

            <!-- Content Section -->
            <div class="content-section">
                <div class="container">
                    <!-- Public Notice -->
                    <div class="public-notice mb-4">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Public View:</strong> This page shows all active document requests. Student names are hidden for privacy protection. 
                        Use the student number to track specific requests and check claiming schedules.
                    </div>

                    <!-- Search and Filter Section -->
                    <div class="search-section">
                        <form method="GET" class="row g-3" id="filterForm">
                            <div class="col-lg-5 col-md-6">
                                <label class="form-label">
                                    <i class="fas fa-search me-1"></i>
                                    Search by Student Number or Request Title
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                                    <input type="text" name="search" class="form-control" 
                                           placeholder="Enter student number or request title..." 
                                           value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>"
                                           autocomplete="off">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-search me-1"></i>
                                        <span class="d-none d-sm-inline">Search</span>
                                    </button>
                                </div>
                            </div>
                            <div class="col-lg-4 col-md-6">
                                <label class="form-label">
                                    <i class="fas fa-filter me-1"></i>
                                    Filter by Status
                                </label>
                                <select name="status" class="form-select filter-input">
                                    <option value="">All Status</option>
                                    <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="processing" <?php echo $status_filter === 'processing' ? 'selected' : ''; ?>>Processing</option>
                                    <option value="for_signature" <?php echo $status_filter === 'for_signature' ? 'selected' : ''; ?>>For Signature</option>
                                    <option value="for_release" <?php echo $status_filter === 'for_release' ? 'selected' : ''; ?>>For Release</option>
                                    <option value="released" <?php echo $status_filter === 'released' ? 'selected' : ''; ?>>Released</option>
                                </select>
                            </div>
                            <div class="col-lg-3 col-md-12">
                                <?php if ($search || $status_filter): ?>
                                <label class="form-label d-none d-md-block">&nbsp;</label>
                                <a href="?" class="btn btn-outline-secondary d-block">
                                    <i class="fas fa-times me-1"></i> Clear Filters
                                </a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>

                    <!-- Requests Table -->
                    <div class="table-container">
                        <div class="table-info">
                            <div>
                                <i class="fas fa-info-circle me-1"></i>
                                <span class="fw-bold">
                                    Showing <?php echo min($per_page, $requests_result->num_rows); ?> of <?php echo $total; ?> requests
                                </span>
                            </div>
                            <div class="text-muted">
                                Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                            </div>
                        </div>
                        
                        <!-- Desktop Table View -->
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th><i class="fas fa-hashtag me-2"></i>ID</th>
                                        <th><i class="fas fa-file-text me-2"></i>Request Title</th>
                                        <th><i class="fas fa-id-card me-2"></i>Student Number</th>
                                        <th><i class="fas fa-info-circle me-2"></i>Status</th>
                                        <th><i class="fas fa-calendar-check me-2"></i>Claim Date</th>
                                        <th><i class="fas fa-calendar me-2"></i>Date Created</th>
                                    </tr>
                                </thead>
                                <tbody id="tableBody">
                                    <?php if ($requests_result && $requests_result->num_rows > 0): ?>
                                        <?php while ($request = $requests_result->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <span class="badge bg-primary">#<?php echo $request['id']; ?></span>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($request['title']); ?></strong>
                                            </td>
                                            <td>
                                                <code class="bg-light px-2 py-1 rounded"><?php echo htmlspecialchars($request['student_number']); ?></code>
                                            </td>
                                            <td>
                                                <span class="badge status-badge bg-<?php 
                                                    echo match($request['status']) {
                                                        'pending' => 'warning',
                                                        'processing' => 'info',
                                                        'for_signature' => 'primary',
                                                        'for_release' => 'secondary',
                                                        'released' => 'success',
                                                        default => 'secondary'
                                                    };
                                                ?>">
                                                    <i class="fas <?php 
                                                        echo match($request['status']) {
                                                            'pending' => 'fa-clock',
                                                            'processing' => 'fa-cogs',
                                                            'for_signature' => 'fa-pen-fancy',
                                                            'for_release' => 'fa-hand-holding',
                                                            'released' => 'fa-check-circle',
                                                            default => 'fa-question'
                                                        };
                                                    ?> me-1"></i>
                                                    <?php echo ucwords(str_replace('_', ' ', $request['status'])); ?>
                                                </span>
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
                                                    $badge_class = '';
                                                    $icon = '';
                                                    $message = '';
                                                    
                                                    if ($claiming_date_start < $today) {
                                                        $class = 'claiming-date-overdue';
                                                        $badge_class = 'bg-danger';
                                                        $icon = 'fa-exclamation-triangle';
                                                        $message = 'Overdue';
                                                    } elseif ($claiming_date_start == $today) {
                                                        $class = 'claiming-date-today';
                                                        $badge_class = 'bg-warning';
                                                        $icon = 'fa-calendar-day';
                                                        $message = 'Today';
                                                    } else {
                                                        $class = 'claiming-date-upcoming';
                                                        $badge_class = 'bg-success';
                                                        $icon = 'fa-calendar-alt';
                                                        $message = 'Scheduled';
                                                    }
                                                    ?>
                                                    <div class="<?php echo $class; ?>">
                                                        <i class="fas fa-calendar-alt me-1"></i>
                                                        <strong><?php echo $claiming_date->format('M d, Y'); ?></strong>
                                                        <br>
                                                        <span class="badge claiming-date-badge <?php echo $badge_class; ?>">
                                                            <i class="fas <?php echo $icon; ?> me-1"></i>
                                                            <?php echo $message; ?>
                                                        </span>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted">
                                                        <i class="fas fa-calendar-times me-1"></i>
                                                        Not scheduled
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <i class="fas fa-calendar-alt me-2 text-muted"></i>
                                                <?php echo date('M j, Y', strtotime($request['created_at'])); ?>
                                                <br>
                                                <small class="text-muted">
                                                    <i class="fas fa-clock me-1"></i>
                                                    <?php echo date('g:i A', strtotime($request['created_at'])); ?>
                                                </small>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-5">
                                                <div class="empty-state">
                                                    <i class="fas fa-inbox"></i>
                                                    <h5>No requests found</h5>
                                                    <p>
                                                        <?php if ($search || $status_filter): ?>
                                                            Try adjusting your search criteria or clear filters to see all requests.
                                                        <?php else: ?>
                                                            No document requests are currently available.
                                                        <?php endif; ?>
                                                    </p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Mobile Card View -->
                        <div class="card-view">
                            <?php if ($requests_result && $requests_result->num_rows > 0): ?>
                                <?php 
                                // Reset result pointer for card view
                                $requests_result->data_seek(0);
                                while ($request = $requests_result->fetch_assoc()): 
                                ?>
                                <div class="request-card">
                                    <div class="request-card-header">
                                        <div class="request-id">#<?php echo $request['id']; ?></div>
                                        <div class="status-badge-container">
                                            <span class="badge status-badge bg-<?php 
                                                echo match($request['status']) {
                                                    'pending' => 'warning',
                                                    'processing' => 'info',
                                                    'for_signature' => 'primary',
                                                    'for_release' => 'secondary',
                                                    'released' => 'success',
                                                    default => 'secondary'
                                                };
                                                ?>">
                                                <i class="fas <?php 
                                                    echo match($request['status']) {
                                                        'pending' => 'fa-clock',
                                                        'processing' => 'fa-cogs',
                                                        'for_signature' => 'fa-pen-fancy',
                                                        'for_release' => 'fa-hand-holding',
                                                        'released' => 'fa-check-circle',
                                                        default => 'fa-question'
                                                    };
                                                ?> me-1"></i>
                                                <?php echo ucwords(str_replace('_', ' ', $request['status'])); ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <div class="request-title"><?php echo htmlspecialchars($request['title']); ?></div>
                                    
                                    <div class="request-info">
                                        <div class="info-item">
                                            <div class="info-label">
                                                <i class="fas fa-id-card me-1"></i>Student Number
                                            </div>
                                            <div class="info-value">
                                                <code class="bg-light px-2 py-1 rounded"><?php echo htmlspecialchars($request['student_number']); ?></code>
                                            </div>
                                        </div>
                                        
                                        <div class="info-item">
                                            <div class="info-label">
                                                <i class="fas fa-calendar me-1"></i>Created
                                            </div>
                                            <div class="info-value">
                                                <?php echo date('M j, Y', strtotime($request['created_at'])); ?>
                                                <br>
                                                <small class="text-muted"><?php echo date('g:i A', strtotime($request['created_at'])); ?></small>
                                            </div>
                                        </div>
                                        
                                        <div class="info-item" style="grid-column: 1 / -1;">
                                            <div class="info-label">
                                                <i class="fas fa-calendar-check me-1"></i>Claim Date
                                            </div>
                                            <div class="info-value">
                                                <?php if (!empty($request['claiming_date'])): ?>
                                                    <?php
                                                    $claiming_date = new DateTime($request['claiming_date']);
                                                    $today = new DateTime();
                                                    $today->setTime(0, 0, 0);
                                                    $claiming_date_start = clone $claiming_date;
                                                    $claiming_date_start->setTime(0, 0, 0);
                                                    
                                                    $class = '';
                                                    $badge_class = '';
                                                    $icon = '';
                                                    $message = '';
                                                    
                                                    if ($claiming_date_start < $today) {
                                                        $class = 'claiming-date-overdue';
                                                        $badge_class = 'bg-danger';
                                                        $icon = 'fa-exclamation-triangle';
                                                        $message = 'Overdue';
                                                    } elseif ($claiming_date_start == $today) {
                                                        $class = 'claiming-date-today';
                                                        $badge_class = 'bg-warning';
                                                        $icon = 'fa-calendar-day';
                                                        $message = 'Today';
                                                    } else {
                                                        $class = 'claiming-date-upcoming';
                                                        $badge_class = 'bg-success';
                                                        $icon = 'fa-calendar-alt';
                                                        $message = 'Scheduled';
                                                    }
                                                    ?>
                                                    <div class="<?php echo $class; ?>">
                                                        <strong><?php echo $claiming_date->format('M d, Y'); ?></strong>
                                                        <span class="badge claiming-date-badge <?php echo $badge_class; ?> ms-2">
                                                            <i class="fas <?php echo $icon; ?> me-1"></i>
                                                            <?php echo $message; ?>
                                                        </span>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted">
                                                        <i class="fas fa-calendar-times me-1"></i>
                                                        Not scheduled
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-inbox"></i>
                                    <h5>No requests found</h5>
                                    <p>
                                        <?php if ($search || $status_filter): ?>
                                            Try adjusting your search criteria or clear filters to see all requests.
                                        <?php else: ?>
                                            No document requests are currently available.
                                        <?php endif; ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <nav class="mt-4" aria-label="Request pagination">
                        <ul class="pagination">
                            <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo $search ? "&search=" . urlencode($search) : ''; ?><?php echo $status_filter ? "&status=" . urlencode($status_filter) : ''; ?>" aria-label="Previous page">
                                    <i class="fas fa-chevron-left"></i>
                                    <span class="d-none d-sm-inline ms-1">Previous</span>
                                </a>
                            </li>
                            <?php endif; ?>
                            
                            <?php 
                            // Smart pagination - show relevant page numbers
                            $start = max(1, $page - 2);
                            $end = min($total_pages, $page + 2);
                            
                            if ($start > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=1<?php echo $search ? "&search=" . urlencode($search) : ''; ?><?php echo $status_filter ? "&status=" . urlencode($status_filter) : ''; ?>">1</a>
                                </li>
                                <?php if ($start > 2): ?>
                                    <li class="page-item disabled">
                                        <span class="page-link">...</span>
                                    </li>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php for ($i = $start; $i <= $end; $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?><?php echo $search ? "&search=" . urlencode($search) : ''; ?><?php echo $status_filter ? "&status=" . urlencode($status_filter) : ''; ?>" <?php echo $i === $page ? 'aria-current="page"' : ''; ?>>
                                    <?php echo $i; ?>
                                </a>
                            </li>
                            <?php endfor; ?>
                            
                            <?php if ($end < $total_pages): ?>
                                <?php if ($end < $total_pages - 1): ?>
                                    <li class="page-item disabled">
                                        <span class="page-link">...</span>
                                    </li>
                                <?php endif; ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $total_pages; ?><?php echo $search ? "&search=" . urlencode($search) : ''; ?><?php echo $status_filter ? "&status=" . urlencode($status_filter) : ''; ?>"><?php echo $total_pages; ?></a>
                                </li>
                            <?php endif; ?>
                            
                            <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo $search ? "&search=" . urlencode($search) : ''; ?><?php echo $status_filter ? "&status=" . urlencode($status_filter) : ''; ?>" aria-label="Next page">
                                    <span class="d-none d-sm-inline me-1">Next</span>
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                            <?php endif; ?>
                        </ul>
                        
                        
                    </nav>
                    <?php endif; ?>

                    <!-- Quick Stats -->
                    <?php if (!$search && !$status_filter): ?>
                    <div class="row mt-4">
                        <div class="col-12">
                            <div class="alert alert-info">
                                <div class="row text-center">
                                    <div class="col-6 col-md-3 mb-2 mb-md-0">
                                        <img src="assets/images/logo.png" 
                                        alt="CVSu Naic Registrar's Office" 
                                        class="registrar-logo mb-2" 
                                        style="width: 60px; height: 60px; border-radius: 50%; border: 3px solid var(--primary-green); object-fit: cover; box-shadow: 0 4px 10px rgba(0,0,0,0.2);">
                                        <h6 class="mb-0">Official</h6>
                                        <strong>Registrar</strong>
                                    </div>
                                    <div class="col-6 col-md-3 mb-2 mb-md-0">
                                        <i class="fas fa-clock fa-2x mb-2 text-warning"></i>
                                        <h6 class="mb-0">Auto Refresh</h6>
                                        <strong>30 seconds</strong>
                                    </div>
                                    <div class="col-6 col-md-3 mb-2 mb-md-0">
                                        <i class="fas fa-shield-alt fa-2x mb-2 text-success"></i>
                                        <h6 class="mb-0">Privacy</h6>
                                        <strong>Protected</strong>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <i class="fas fa-sync-alt fa-2x mb-2 text-info"></i>
                                        <h6 class="mb-0">Real-time</h6>
                                        <strong>Updates</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="row">
                <div class="col-lg-8 col-md-6 mb-3 mb-md-0">
                    <h6 class="mb-2">
                        <i class="fas fa-university me-2"></i>
                        Cavite State University - Naic Campus
                    </h6>
                    <p class="mb-1">
                        <i class="fas fa-map-marker-alt me-2"></i>
                        Bucana Malaki, Naic, Cavite, Philippines
                    </p>
                    <p class="mb-0">
                        <i class="fas fa-envelope me-2"></i>
                        <a href="mailto:registrar@cvsu-naic.edu.ph">registrar@cvsu-naic.edu.ph</a>
                    </p>
                </div>
                <div class="col-lg-4 col-md-6 text-md-end">
                    <h6 class="mb-2">Office Hours</h6>
                    <p class="mb-1">
                        <i class="fas fa-clock me-2"></i>
                        Monday - Thursday: 7:00 AM - 6:00 PM
                    </p>
                    <p class="mb-0">
                        <i class="fas fa-phone me-2"></i>
                        For urgent inquiries: 0976 592 7310
                    </p>
                </div>
            </div>
            <hr class="my-3" style="border-color: rgba(255,255,255,0.3);">
            <div class="text-center">
                <small>
                    <i class="fas fa-shield-alt me-1"></i>
                    © <?php echo date('Y'); ?> Cavite State University - Naic Campus. All rights reserved.
                </small>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const loadingSpinner = document.getElementById('loadingSpinner');
            const tableBody = document.getElementById('tableBody');
            const filterForm = document.getElementById('filterForm');
            const statusSelect = document.querySelector('select[name="status"]');
            const searchInput = document.querySelector('input[name="search"]');

            // Show loading spinner
            function showLoading() {
                loadingSpinner.style.display = 'block';
                if (tableBody) {
                    tableBody.classList.add('loading');
                }
                document.body.style.cursor = 'wait';
            }

            // Hide loading spinner
            function hideLoading() {
                loadingSpinner.style.display = 'none';
                if (tableBody) {
                    tableBody.classList.remove('loading');
                }
                document.body.style.cursor = 'default';
            }

            // Add loading state when form is submitted
            if (filterForm) {
                filterForm.addEventListener('submit', function(e) {
                    showLoading();
                    // Add slight delay to show loading state
                    setTimeout(() => {
                        // Form will submit naturally
                    }, 100);
                });
            }

            // Auto-submit when status filter changes
            if (statusSelect) {
                statusSelect.addEventListener('change', function() {
                    showLoading();
                    setTimeout(() => {
                        filterForm.submit();
                    }, 100);
                });
            }

            // Enhanced search functionality
            if (searchInput) {
                let searchTimeout;
                
                // Clear button for search input
                const inputGroup = searchInput.closest('.input-group');
                if (searchInput.value) {
                    addClearButton();
                }

                searchInput.addEventListener('input', function() {
                    if (this.value) {
                        addClearButton();
                    } else {
                        removeClearButton();
                    }
                });

                function addClearButton() {
                    const existing = inputGroup.querySelector('.clear-search-btn');
                    if (!existing && searchInput.value) {
                        const clearBtn = document.createElement('button');
                        clearBtn.type = 'button';
                        clearBtn.className = 'btn btn-outline-secondary clear-search-btn';
                        clearBtn.innerHTML = '<i class="fas fa-times"></i>';
                        clearBtn.title = 'Clear search';
                        clearBtn.addEventListener('click', function() {
                            searchInput.value = '';
                            removeClearButton();
                            showLoading();
                            setTimeout(() => {
                                filterForm.submit();
                            }, 100);
                        });
                        inputGroup.appendChild(clearBtn);
                    }
                }

                function removeClearButton() {
                    const clearBtn = inputGroup.querySelector('.clear-search-btn');
                    if (clearBtn) {
                        clearBtn.remove();
                    }
                }
            }

            // Auto-refresh functionality (only when no filters are active)
            const currentSearch = searchInput ? searchInput.value : '';
            const currentStatus = statusSelect ? statusSelect.value : '';
            
            if (!currentSearch && !currentStatus) {
                let refreshCountdown = 30;
                const refreshTimer = setInterval(() => {
                    refreshCountdown--;
                    if (refreshCountdown <= 0) {
                        showLoading();
                        window.location.reload();
                    }
                }, 1000);

                // Show refresh countdown in footer
                const footerText = document.querySelector('footer small');
                if (footerText) {
                    const originalText = footerText.innerHTML;
                    const countdownTimer = setInterval(() => {
                        if (refreshCountdown > 0) {
                            footerText.innerHTML = originalText + 
                                ` | <i class="fas fa-sync-alt fa-spin me-1"></i>Auto-refresh in ${refreshCountdown}s`;
                        } else {
                            clearInterval(countdownTimer);
                        }
                    }, 1000);
                }

                // Clear refresh timer if user interacts with the page
                document.addEventListener('click', () => clearInterval(refreshTimer));
                document.addEventListener('keydown', () => clearInterval(refreshTimer));
            }

            // Smooth scrolling for pagination
            document.querySelectorAll('.pagination a').forEach(link => {
                link.addEventListener('click', function(e) {
                    showLoading();
                    window.scrollTo({
                        top: 0,
                        behavior: 'smooth'
                    });
                });
            });

            // Add keyboard navigation
            document.addEventListener('keydown', function(e) {
                // Ctrl/Cmd + F to focus search
                if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                    e.preventDefault();
                    if (searchInput) {
                        searchInput.focus();
                        searchInput.select();
                    }
                }
            });

            // Add tooltips to status badges
            document.querySelectorAll('.status-badge').forEach(badge => {
                const status = badge.textContent.trim().toLowerCase();
                let tooltip = '';
                switch(status) {
                    case 'pending':
                        tooltip = 'Request has been submitted and is waiting to be processed';
                        break;
                    case 'processing':
                        tooltip = 'Request is currently being processed by staff';
                        break;
                    case 'for signature':
                        tooltip = 'Document is ready and waiting for official signature';
                        break;
                    case 'for release':
                        tooltip = 'Document is complete and ready for pickup';
                        break;
                    case 'released':
                        tooltip = 'Document has been successfully released to student';
                        break;
                }
                if (tooltip) {
                    badge.title = tooltip;
                }
            });

            // Initialize Bootstrap tooltips if available
            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                const tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
                tooltipTriggerList.map(function (tooltipTriggerEl) {
                    return new bootstrap.Tooltip(tooltipTriggerEl);
                });
            }

            // Add visual feedback for form interactions
            document.querySelectorAll('.form-control, .form-select').forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement.style.transform = 'translateY(-1px)';
                });
                
                input.addEventListener('blur', function() {
                    this.parentElement.style.transform = 'translateY(0)';
                });
            });

            // Hide loading spinner on page load
            setTimeout(hideLoading, 100);
        });

        // Service Worker for offline functionality (optional)
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                // Register service worker if available
                // navigator.serviceWorker.register('/sw.js');
            });
        }

        // Handle print functionality
        function printRequests() {
            window.print();
        }

        // Add print button if needed
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                printRequests();
            }
        });
    </script>
</body>
</html>