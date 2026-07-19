<?php
session_start();
require_once 'includes/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Fetch user details
if ($_SESSION['role'] !== 'admin') {
    header('Location: requests.php');
    exit();
}

// Get current year or selected year from request
$selected_year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$selected_month = isset($_GET['month']) ? intval($_GET['month']) : date('n');

// Get statistics
$sql = "SELECT 
            COUNT(*) as total_requests,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
            SUM(CASE WHEN status = 'for_signature' THEN 1 ELSE 0 END) as for_signature,
            SUM(CASE WHEN status = 'for_release' THEN 1 ELSE 0 END) as for_release,
            SUM(CASE WHEN status = 'released' THEN 1 ELSE 0 END) as released
        FROM requests";
$result = $conn->query($sql);
$stats = $result->fetch_assoc();

// Get available years for dropdown (for main dashboard charts based on creation date)
$sql_years = "SELECT DISTINCT YEAR(created_at) as year FROM requests ORDER BY year DESC";
$years_result = $conn->query($sql_years);
$available_years = [];
while ($year_row = $years_result->fetch_assoc()) {
    $available_years[] = $year_row['year'];
}

// *** NEW *** Get available years based on RELEASED date for modal filter
$sql_released_years = "SELECT DISTINCT YEAR(released_at) as year FROM requests WHERE status = 'released' AND released_at IS NOT NULL ORDER BY year DESC";
$released_years_result = $conn->query($sql_released_years);
$available_released_years = [];
while ($year_row = $released_years_result->fetch_assoc()) {
    $available_released_years[] = $year_row['year'];
}


// Get monthly statistics for selected year
$sql = "SELECT 
            MONTH(created_at) as month,
            COUNT(*) as count
        FROM requests
        WHERE YEAR(created_at) = ?
        GROUP BY MONTH(created_at)
        ORDER BY month";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $selected_year);
$stmt->execute();
$monthly_stats = $stmt->get_result();

// Create complete monthly data array (fill missing months with 0)
$monthly_data = array_fill(1, 12, 0);
while ($row = $monthly_stats->fetch_assoc()) {
    $monthly_data[$row['month']] = $row['count'];
}

// Get daily statistics for selected year and month
$sql = "SELECT 
            DAY(created_at) as day,
            COUNT(*) as count
        FROM requests
        WHERE YEAR(created_at) = ? AND MONTH(created_at) = ?
        GROUP BY DAY(created_at)
        ORDER BY day";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $selected_year, $selected_month);
$stmt->execute();
$daily_stats = $stmt->get_result();

// Get number of days in selected month
$days_in_month = cal_days_in_month(CAL_GREGORIAN, $selected_month, $selected_year);
$daily_data = array_fill(1, $days_in_month, 0);
while ($row = $daily_stats->fetch_assoc()) {
    $daily_data[$row['day']] = $row['count'];
}

// *** MODIFIED *** Get monthly released requests data based on released_at
$sql_released = "SELECT 
                    YEAR(released_at) as year,
                    MONTH(released_at) as month,
                    COUNT(*) as count
                FROM requests
                WHERE status = 'released' AND released_at IS NOT NULL
                GROUP BY YEAR(released_at), MONTH(released_at)
                ORDER BY year DESC, month";
$released_result = $conn->query($sql_released);

// Organize released data by year
$released_by_year = [];
while ($row = $released_result->fetch_assoc()) {
    if (!isset($released_by_year[$row['year']])) {
        $released_by_year[$row['year']] = array_fill(1, 12, 0);
    }
    $released_by_year[$row['year']][$row['month']] = $row['count'];
}

// *** MODIFIED *** Get daily released requests data based on released_at
$sql_daily_released = "SELECT 
                        YEAR(released_at) as year,
                        MONTH(released_at) as month,
                        DAY(released_at) as day,
                        COUNT(*) as count
                    FROM requests
                    WHERE status = 'released' AND released_at IS NOT NULL
                    GROUP BY YEAR(released_at), MONTH(released_at), DAY(released_at)
                    ORDER BY year DESC, month, day";
$daily_released_result = $conn->query($sql_daily_released);


// Organize daily released data by year-month
$daily_released_by_year_month = [];
while ($row = $daily_released_result->fetch_assoc()) {
    $year = $row['year'];
    $month = $row['month'];
    $day = $row['day'];
    
    if (!isset($daily_released_by_year_month[$year])) {
        $daily_released_by_year_month[$year] = [];
    }
    if (!isset($daily_released_by_year_month[$year][$month])) {
        $days_in_month_temp = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $daily_released_by_year_month[$year][$month] = array_fill(1, $days_in_month_temp, 0);
    }
    $daily_released_by_year_month[$year][$month][$day] = $row['count'];
}

// Get current year's released data for the modal
$current_year_released = isset($released_by_year[$selected_year]) ? 
    array_values($released_by_year[$selected_year]) : 
    array_fill(0, 12, 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - RMS</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            border: none;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
            min-height: 120px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        }
        
        .stat-card.clickable {
            cursor: pointer;
        }
        
        .stat-card.clickable:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 25px rgba(0,0,0,0.15);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--green-main), var(--green-dark));
        }
        
        .stat-card.primary::before {
            background: linear-gradient(90deg, #007bff, #0056b3);
        }
        
        .stat-card.warning::before {
            background: linear-gradient(90deg, #ffc107, #e0a800);
        }
        
        .stat-card.success::before {
            background: linear-gradient(90deg, var(--green-main), var(--green-dark));
        }
        
        .stat-card.processing::before {
            background: linear-gradient(90deg, #17a2b8, #138496);
        }
        
        .stat-card.signature::before {
            background: linear-gradient(90deg, #6f42c1, #5a32a3);
        }
        
        .stat-card.release::before {
            background: linear-gradient(90deg, #fd7e14, #e8681c);
        }
        
        .stat-card h5 {
            font-weight: 500;
            color: #666;
            margin-bottom: 15px;
            font-size: 0.95rem;
            z-index: 2;
            position: relative;
        }
        
        .stat-card h3 {
            font-weight: 700;
            color: var(--text-color);
            font-size: 2.2rem;
            margin: 0;
            line-height: 1;
            z-index: 2;
            position: relative;
        }
        
        .stat-card .stat-icon {
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 2.5rem;
            opacity: 0.1;
            z-index: 1;
        }
        
        .stat-card.primary .stat-icon {
            color: #007bff;
        }
        
        .stat-card.warning .stat-icon {
            color: #ffc107;
        }
        
        .stat-card.success .stat-icon {
            color: var(--green-main);
        }
        
        .stat-card.processing .stat-icon {
            color: #17a2b8;
        }
        
        .stat-card.signature .stat-icon {
            color: #6f42c1;
        }
        
        .stat-card.release .stat-icon {
            color: #fd7e14;
        }
        
        .chart-container {
            position: relative;
            height: 400px;
            margin-top: 20px;
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .chart-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--green-dark);
            margin: 0;
        }
        
        .chart-subtitle {
            font-size: 0.85rem;
            color: #666;
            margin: 0;
        }
        
        .year-selector {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .year-selector label {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--text-color);
            margin: 0;
        }
        
        .year-selector select {
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: white;
            color: var(--text-color);
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .year-selector select:focus {
            outline: none;
            border-color: var(--green-main);
            box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.1);
        }
        
        .overview-card {
            flex: 1;
            min-width: 200px;
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            border: none;
        }
        
        .overview-header {
            background-color: var(--green-main);
            color: white;
            border-radius: 12px 12px 0 0;
            margin: -20px -20px 20px -20px;
            padding: 15px 20px;
        }
        
        .overview-header h6 {
            margin: 0;
            font-weight: 600;
        }
        
        .status-breakdown {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 15px;
        }
        
        .status-item {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 12px 15px;
            text-align: center;
            min-width: 120px;
            border-left: 4px solid var(--green-main);
        }
        
        .status-item.processing {
            border-left-color: #17a2b8;
        }
        
        .status-item.for_signature {
            border-left-color: #6f42c1;
        }
        
        .status-item.for_release {
            border-left-color: #fd7e14;
        }
        
        .status-item .status-count {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-color);
        }
        
        .status-item .status-label {
            font-size: 0.8rem;
            color: #666;
            text-transform: capitalize;
        }

        .charts-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .daily-chart-container {
            position: relative;
            height: 350px;
            margin-top: 20px;
        }

        /* Admin badge for visibility */
        .admin-badge {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 2px 4px rgba(220, 53, 69, 0.3);
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding: 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }

        .page-title {
            margin: 0;
            color: var(--green-dark);
            font-weight: 600;
        }
        
        /* Modal Styles */
        .modal-content {
            border: none;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--green-main), var(--green-dark));
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 20px 25px;
            border: none;
        }
        
        .modal-title {
            font-weight: 600;
            margin: 0;
            color: white;
        }
        
        .btn-close {
            filter: brightness(0) invert(1);
            opacity: 0.8;
        }
        
        .btn-close:hover {
            opacity: 1;
        }
        
        .modal-body {
            padding: 25px;
        }
        
        .modal-chart-container {
            position: relative;
            height: 350px;
            margin-top: 15px;
        }
        
        .modal-year-selector {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .modal-year-selector label {
            font-weight: 500;
            color: var(--text-color);
            margin: 0;
        }
        
        .modal-year-selector select {
            padding: 8px 15px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: white;
            color: var(--text-color);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .modal-year-selector select:focus {
            outline: none;
            border-color: var(--green-main);
            box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.1);
        }

        /* Tab Navigation for Modal */
        .modal-tabs {
            display: flex;
            border-bottom: 2px solid #f8f9fa;
            margin-bottom: 20px;
        }

        .modal-tab {
            flex: 1;
            padding: 12px 20px;
            background: none;
            border: none;
            color: #666;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border-bottom: 2px solid transparent;
        }

        .modal-tab.active {
            color: var(--green-main);
            border-bottom-color: var(--green-main);
        }

        .modal-tab:hover {
            color: var(--green-main);
            background: rgba(76, 175, 80, 0.05);
        }

        .modal-tab-content {
            display: none;
        }

        .modal-tab-content.active {
            display: block;
        }

        .daily-selector {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }

        .daily-selector select {
            padding: 8px 15px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: white;
            color: var(--text-color);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        /* Responsive Breakpoints */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 15px;
            }
            
            .charts-row {
                grid-template-columns: 1fr;
                gap: 15px;
            }
        }
        
        @media (max-width: 992px) {
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }
            
            .stat-card {
                min-height: 110px;
                padding: 20px;
            }
            
            .stat-card h3 {
                font-size: 2rem;
            }
            
            .charts-row {
                grid-template-columns: 1fr;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }
            
            .stat-card {
                padding: 18px;
                min-height: 100px;
            }
            
            .stat-card h3 {
                font-size: 1.8rem;
            }
            
            .stat-card h5 {
                font-size: 0.9rem;
                margin-bottom: 10px;
            }
            
            .chart-container, .daily-chart-container {
                height: 300px;
            }
            
            .modal-chart-container {
                height: 300px;
            }
            
            h2 {
                font-size: 1.4rem;
            }
            
            .stat-card .stat-icon {
                font-size: 2rem;
            }
            
            .chart-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .year-selector {
                align-self: flex-end;
            }

            .modal-tabs {
                flex-direction: column;
            }

            .modal-tab {
                text-align: center;
                border-bottom: 1px solid #dee2e6;
            }

            .modal-tab.active {
                border-bottom: 2px solid var(--green-main);
            }
        }
        
        @media (max-width: 576px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }
            
            .stat-card {
                min-height: 90px;
                padding: 15px;
            }
            
            .stat-card h3 {
                font-size: 1.6rem;
            }
            
            .stat-card h5 {
                font-size: 0.8rem;
            }
            
            .stat-card .stat-icon {
                font-size: 1.8rem;
                right: 15px;
            }
            
            .modal-body {
                padding: 20px;
            }
            
            .modal-chart-container {
                height: 250px;
            }
        }
        
        @media (max-width: 400px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .stat-card {
                min-height: 80px;
                padding: 12px;
            }
            
            .stat-card h3 {
                font-size: 1.5rem;
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
                    <a href="users.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : ''; ?>">
                        <i class="fas fa-users"></i>
                        <span>Users</span>
                    </a>
                    <a href="audit.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'audit.php' ? 'active' : ''; ?>">
                        <i class="fas fa-history"></i>
                        <span>Audit Trail</span>
                    </a>
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
                <div class="page-header">
                    <h2 class="page-title">Cavite State University - Naic Registrar</h2>
                    <div class="admin-badge">
                        <i class="fas fa-shield-alt me-1"></i>Admin Dashboard
                    </div>
                </div>
                
                <div class="stats-grid">
                    <div class="stat-card primary">
                        <div class="stat-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <h5><i class="fas fa-file-alt me-2"></i>Total Requests</h5>
                        <h3><?php echo number_format($stats['total_requests']); ?></h3>
                    </div>
                    <div class="stat-card warning">
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <h5><i class="fas fa-clock me-2"></i>Pending Requests</h5>
                        <h3><?php echo number_format($stats['pending']); ?></h3>
                    </div>
                    <div class="stat-card processing">
                        <div class="stat-icon">
                            <i class="fas fa-cog"></i>
                        </div>
                        <h5><i class="fas fa-cog me-2"></i>Processing</h5>
                        <h3><?php echo number_format($stats['processing']); ?></h3>
                    </div>
                    <div class="stat-card signature">
                        <div class="stat-icon">
                            <i class="fas fa-signature"></i>
                        </div>
                        <h5><i class="fas fa-signature me-2"></i>For Signature</h5>
                        <h3><?php echo number_format($stats['for_signature']); ?></h3>
                    </div>
                    <div class="stat-card for release">
                        <div class="stat-icon">
                            <i class="fas fa-paper-plane"></i>
                        </div>
                        <h5><i class="fas fa-paper-plane me-2"></i>For Release</i></h5>
                        <h3><?php echo number_format($stats['for_release']); ?></h3>
                    </div>
                    <div class="stat-card success clickable" onclick="showReleasedModal()">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <h5><i class="fas fa-check-circle me-2"></i>Released Requests <i class="fas fa-chart-bar ms-2" style="font-size: 0.8rem; opacity: 0.7;"></i></h5>
                        <h3><?php echo number_format($stats['released']); ?></h3>
                    </div>
                </div>

                <div class="charts-row">
                    <div class="card">
                        <div class="chart-header">
                            <div>
                                <h5 class="chart-title">Monthly Request Trends</h5>
                                <p class="chart-subtitle">Request volume for <?php echo $selected_year; ?></p>
                            </div>
                            <div class="year-selector">
                                <label for="yearSelect">Year:</label>
                                <select id="yearSelect" onchange="changeYear()">
                                    <?php foreach ($available_years as $year): ?>
                                    <option value="<?php echo $year; ?>" <?php echo ($year == $selected_year) ? 'selected' : ''; ?>>
                                        <?php echo $year; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="monthlyChart"></canvas>
                        </div>
                    </div>

                    <div class="card">
                        <div class="chart-header">
                            <div>
                                <h5 class="chart-title">Daily Request Monitoring</h5>
                                <p class="chart-subtitle"><?php echo date('F Y', mktime(0, 0, 0, $selected_month, 1, $selected_year)); ?></p>
                            </div>
                            <div class="year-selector">
                                <label for="monthSelect">Month:</label>
                                <select id="monthSelect" onchange="changeMonth()">
                                    <?php for ($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo ($i == $selected_month) ? 'selected' : ''; ?>>
                                        <?php echo date('F', mktime(0, 0, 0, $i, 1)); ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        <div class="daily-chart-container">
                            <canvas id="dailyChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="releasedModal" tabindex="-1" aria-labelledby="releasedModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="releasedModalLabel">
                        <i class="fas fa-check-circle me-2"></i>
                        Released Requests Analysis
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="modal-tabs">
                        <button class="modal-tab active" onclick="switchTab('monthly')">
                            <i class="fas fa-calendar-alt me-2"></i>Monthly Analysis
                        </button>
                        <button class="modal-tab" onclick="switchTab('daily')">
                            <i class="fas fa-calendar-day me-2"></i>Daily Analysis
                        </button>
                    </div>

                    <div id="monthlyTab" class="modal-tab-content active">
                        <div class="modal-year-selector">
                            <label for="modalYearSelect"><i class="fas fa-calendar me-2"></i>Select Year:</label>
                            <select id="modalYearSelect" onchange="updateReleasedChart()">
                                <?php foreach ($available_released_years as $year): ?>
                                <option value="<?php echo $year; ?>" <?php echo ($year == $selected_year) ? 'selected' : ''; ?>>
                                    <?php echo $year; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            
                        </div>
                        <div class="modal-chart-container">
                            <canvas id="releasedChart"></canvas>
                        </div>
                    </div>

                    <div id="dailyTab" class="modal-tab-content">
                        <div class="modal-year-selector">
                            <div class="daily-selector">
                                <label for="dailyYearSelect"><i class="fas fa-calendar me-2"></i>Year:</label>
                                <select id="dailyYearSelect" onchange="updateDailyReleasedChart()">
                                    <?php foreach ($available_released_years as $year): ?>
                                    <option value="<?php echo $year; ?>" <?php echo ($year == $selected_year) ? 'selected' : ''; ?>>
                                        <?php echo $year; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                
                                <label for="dailyMonthSelect"><i class="fas fa-calendar-alt me-2"></i>Month:</label>
                                <select id="dailyMonthSelect" onchange="updateDailyReleasedChart()">
                                    <?php for ($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo ($i == $selected_month) ? 'selected' : ''; ?>>
                                        <?php echo date('F', mktime(0, 0, 0, $i, 1)); ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            
                        </div>
                        <div class="modal-chart-container">
                            <canvas id="dailyReleasedChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/js/dashboard.js"></script>
    <script>
        // Initialize dashboard data when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            initializeDashboardData({
                monthlyData: <?php echo json_encode(array_values($monthly_data)); ?>,
                dailyData: <?php echo json_encode(array_values($daily_data)); ?>,
                daysInMonth: <?php echo $days_in_month; ?>,
                releasedDataByYear: <?php echo json_encode($released_by_year); ?>,
                dailyReleasedDataByYearMonth: <?php echo json_encode($daily_released_by_year_month); ?>,
                // *** MODIFIED *** Pass the correct set of available years
                availableYears: <?php echo json_encode($available_years); ?>,
                availableReleasedYears: <?php echo json_encode($available_released_years); ?>,
                selectedYear: <?php echo $selected_year; ?>,
                selectedMonth: <?php echo $selected_month; ?>
            });
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>