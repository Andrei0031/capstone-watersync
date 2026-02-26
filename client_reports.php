<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: adminlogin.php");
    exit();
}

include 'db.php';

$success_message = '';
$error_message = '';

// Format datetime assuming DB stores UTC, convert to Philippine time (Asia/Manila)
if (!function_exists('adminFormatDT')) {
    function adminFormatDT($dt): string
    {
        if (empty($dt)) return '';
        try {
            $utcTz = new DateTimeZone('UTC');
            $phTz  = new DateTimeZone('Asia/Manila');
            $clean = substr((string)$dt, 0, 19);

            $d = DateTime::createFromFormat('Y-m-d H:i:s', $clean, $utcTz);
            if (!$d) {
                $d = new DateTime($clean, $utcTz);
            }
            $d->setTimezone($phTz);
            return $d->format('M d, Y g:i A');
        } catch (Exception $e) {
            return is_string($dt) ? $dt : '';
        }
    }
}

// Handle notice operations (create, update, delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $admin_id = $_SESSION['admin_id'];
    
    if ($_POST['action'] === 'create') {
        // Validate and sanitize inputs
        $title = trim($_POST['title']);
        $description = trim($_POST['description'] ?? '');
        $type = $_POST['type'];
        $status = $_POST['status'];
        $start_date = $_POST['start_date'];
        $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        
        // Process affected areas
        $affected_areas = isset($_POST['affected_areas']) && is_array($_POST['affected_areas']) ? $_POST['affected_areas'] : [];
        $affected_areas_str = implode(', ', $affected_areas);

        // Additional validation
        if (empty($title) || empty($affected_areas)) {
            $error_message = "Please fill in the title and select at least one affected area.";
        } else {
            try {
                $stmt = $conn->prepare("INSERT INTO notices (title, description, type, status, start_date, end_date, affected_areas, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->bind_param("sssssssi", $title, $description, $type, $status, $start_date, $end_date, $affected_areas_str, $admin_id);
                
                if ($stmt->execute()) {
                    $success_message = "Notice created successfully!";
                } else {
                    $error_message = "Error creating notice: " . $stmt->error;
                }
                $stmt->close();
            } catch (Exception $e) {
                $error_message = "Error: " . $e->getMessage();
            }
        }
    } elseif ($_POST['action'] === 'update') {
        $notice_id = $_POST['notice_id'];
        $title = $_POST['title'];
        $description = $_POST['description'];
        $type = $_POST['type'];
        $status = $_POST['status'];
        $start_date = $_POST['start_date'];
        $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        
        // Process affected areas for update
        $affected_areas = isset($_POST['affected_areas']) && is_array($_POST['affected_areas']) ? $_POST['affected_areas'] : [];
        $affected_areas_str = implode(', ', $affected_areas);
        
        $stmt = $conn->prepare("UPDATE notices SET title = ?, description = ?, type = ?, status = ?, start_date = ?, end_date = ?, affected_areas = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND created_by = ?");
        $stmt->bind_param("sssssssii", $title, $description, $type, $status, $start_date, $end_date, $affected_areas_str, $notice_id, $admin_id);
        
        if ($stmt->execute()) {
            $success_message = "Notice updated successfully.";
        } else {
            $error_message = "Error updating notice. Please try again.";
        }
        $stmt->close();
    } elseif ($_POST['action'] === 'delete') {
        $notice_id = $_POST['notice_id'];
        
        $stmt = $conn->prepare("DELETE FROM notices WHERE id = ? AND created_by = ?");
        $stmt->bind_param("ii", $notice_id, $admin_id);
        
        if ($stmt->execute()) {
            $success_message = "Notice deleted successfully.";
        } else {
            $error_message = "Error deleting notice. Please try again.";
        }
        $stmt->close();
    }
}

// Handle report resolution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_report'])) {
    $report_id = (int)($_POST['report_id'] ?? 0);
    $resolution_notes = trim((string)($_POST['resolution_notes'] ?? ''));
    if ($report_id > 0) {
        $stmt = $conn->prepare("UPDATE outage_reports SET status = 1, resolved_at = CURRENT_TIMESTAMP, resolution_notes = ? WHERE id = ?");
        $stmt->bind_param("si", $resolution_notes, $report_id);
        if ($stmt->execute()) {
            $success_message = "Report has been resolved successfully.";
        } else {
            $error_message = "Error resolving report. Please try again.";
        }
        $stmt->close();
    } else {
        $error_message = "Invalid report ID.";
    }
}

// Get filter parameters
$status_filter = isset($_GET['status_filter']) ? $_GET['status_filter'] : 'all';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$notice_status_filter = isset($_GET['notice_status']) ? $_GET['notice_status'] : 'active';

// Build reports query with filters (status + date range)
$reports_query = "
    SELECT o.*, cl.firstname, cl.lastname
    FROM outage_reports o
    JOIN client_list cl ON o.client_id = cl.id
    WHERE DATE(o.created_at) BETWEEN '{$date_from}' AND '{$date_to}'";
    
if ($status_filter === 'pending') {
    $reports_query .= " AND o.status = 0";
} elseif ($status_filter === 'resolved') {
    $reports_query .= " AND o.status = 1";
}

$reports_query .= " ORDER BY o.created_at DESC";
$reports = $conn->query($reports_query);

// Get statistics (overall, not filtered by date)
$stats_query = "
    SELECT 
        COUNT(*) as total_reports,
        SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as resolved_reports,
        SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) as pending_reports,
        AVG(CASE WHEN status = 1 THEN TIMESTAMPDIFF(HOUR, created_at, resolved_at) END) as avg_resolution_time
    FROM outage_reports";
$stats = $conn->query($stats_query)->fetch_assoc();

// Notices query with status + date filters
$notices_query = "
    SELECT n.*, a.username as admin_name
    FROM notices n
    JOIN admin a ON n.created_by = a.id
    WHERE DATE(n.start_date) BETWEEN '{$date_from}' AND '{$date_to}'";

if ($notice_status_filter === 'ongoing') {
    $notices_query .= " AND n.status = 'ongoing'";
} elseif ($notice_status_filter === 'scheduled') {
    $notices_query .= " AND n.status = 'scheduled'";
} elseif ($notice_status_filter === 'completed') {
    $notices_query .= " AND n.status = 'completed'";
} else {
    // 'active' (default) – show ongoing + upcoming + recent completed (same logic as before)
    $notices_query .= " AND (n.status = 'ongoing' 
          OR (n.status = 'scheduled' AND n.start_date <= DATE_ADD(NOW(), INTERVAL 24 HOUR)) 
          OR (n.status = 'completed' AND n.end_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)))";
}

$notices_query .= " ORDER BY 
        CASE n.status
            WHEN 'ongoing' THEN 1
            WHEN 'scheduled' THEN 2
            WHEN 'completed' THEN 3
        END,
        n.start_date DESC";
$notices = $conn->query($notices_query);
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Reports - Water Billing System</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root {
            --bg-color: #f8f9fa;
            --sidebar-bg: #fff;
            --text-color: #333;
            --card-bg: #fff;
            --border-color: #dee2e6;
            --hover-bg: #f0f2f5;
            --hover-text: #007bff;
            --muted-text: #6c757d;
            --card-text: #333;
            --table-header-bg: #f8f9fa;
            --table-header-text: #333;
            --table-cell-text: #333;
            --table-bg: #fff;
            --modal-bg: #fff;
            --input-bg: #fff;
            --input-text: #333;
        }

        :root[data-theme="dark"] {
            --bg-color: #1a1d21;
            --sidebar-bg: #242529;
            --text-color: #e4e6eb;
            --card-bg: #2d2f34;
            --border-color: #393b40;
            --hover-bg: #393b40;
            --hover-text: #4e9eff;
            --muted-text: #a0a0a0;
            --card-text: #e4e6eb;
            --table-header-bg: #242529;
            --table-header-text: #e4e6eb;
            --table-cell-text: #e4e6eb;
            --table-bg: #2d2f34;
            --modal-bg: #2d2f34;
            --input-bg: #242529;
            --input-text: #e4e6eb;
        }

        body {
            font-family: 'Open Sans', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            transition: background-color 0.3s, color 0.3s;
        }

        .sidebar {
            height: 100vh;
            background-color: var(--sidebar-bg);
            border-right: 1px solid var(--border-color);
            padding-top: 20px;
            position: fixed;
            width: 250px;
            display: flex;
            flex-direction: column;
        }

        .sidebar-header {
            padding: 20px;
            margin-bottom: 20px;
            text-align: center;
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            margin: 0 20px 20px;
            border-radius: 12px;
            transition: background-color 0.3s, border-color 0.3s;
            overflow: hidden;
        }
        .sidebar-header img { 
            max-width: 100%; 
            height: auto;
            object-fit: contain;
            filter: none !important;
        }

        /* Prevent logo from being affected by dark mode filters */
        html[data-theme="dark"] .sidebar-header img,
        [data-theme="dark"] .sidebar-header img {
            filter: none !important;
            opacity: 1 !important;
            mix-blend-mode: normal !important;
        }

        /* Keep sidebar-header background light in dark mode for logo visibility */
        html[data-theme="dark"] .sidebar-header,
        [data-theme="dark"] .sidebar-header {
            background-color: #fff !important;
        }

        .nav-content {
            flex: 1;
            overflow-y: auto;
        }

        .sidebar-footer {
            padding: 20px;
            border-top: 1px solid var(--border-color);
            margin-top: auto;
        }

        .sidebar a {
            padding: 12px 20px;
            display: flex;
            align-items: center;
            color: var(--text-color);
            font-weight: 600;
            text-decoration: none;
            border-radius: 12px;
            margin: 0 8px 8px;
            transition: all 0.3s ease;
        }

        .sidebar a i {
            min-width: 24px;
            margin-right: 10px;
            text-align: center;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background-color: var(--hover-bg);
            color: var(--hover-text);
        }

        .main-content {
            margin-left: 250px;
            padding: 30px;
        }

        .card {
            background-color: var(--card-bg);
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            color: var(--card-text);
        }

        .card-header {
            background-color: transparent;
            border-bottom: 1px solid var(--border-color);
            padding: 20px;
        }

        .table {
            color: var(--table-cell-text);
            background-color: var(--table-bg);
        }

        .table thead th {
            background-color: var(--table-header-bg);
            color: var(--table-header-text);
            border-bottom: 2px solid var(--border-color);
        }

        .table td, .table th {
            background-color: var(--table-bg);
            border-color: var(--border-color);
            color: var(--table-cell-text);
        }

        .status-badge {
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.875rem;
        }

        .priority-high { color: #dc3545; }
        .priority-medium { color: #ffc107; }
        .priority-low { color: #28a745; }

        .theme-switch-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px;
            border-radius: 10px;
            margin: 10px 20px;
            background-color: var(--hover-bg);
        }

        .theme-switch-wrapper i {
            margin: 0 5px;
            color: var(--text-color);
        }

        .theme-switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
            margin: 0 10px;
        }

        .theme-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: #2196F3;
        }

        input:checked + .slider:before {
            transform: translateX(26px);
        }

        .btn-outline-primary {
            color: var(--hover-text);
            border-color: var(--hover-text);
        }

        .btn-outline-primary:hover {
            background-color: var(--hover-text);
            border-color: var(--hover-text);
            color: #fff;
        }

        .stat-card {
            padding: 20px;
            border-radius: 15px;
            color: white;
            height: 100%;
        }

        .modal-content {
            background-color: var(--modal-bg);
            color: var(--text-color);
        }

        .form-control, .form-select {
            background-color: var(--input-bg);
            color: var(--input-text);
            border-color: var(--border-color);
        }

        .form-control:focus, .form-select:focus {
            background-color: var(--input-bg);
            color: var(--input-text);
        }

        .modal-header, .modal-footer {
            border-color: var(--border-color);
        }

        @media (max-width: 991.98px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: 0;
                height: 100vh;
                width: 250px;
                background-color: var(--sidebar-bg, #fff);
                border-right: 1px solid var(--border-color, #dee2e6);
                transform: translateX(-250px);
                transition: transform 0.3s ease;
                z-index: 1050;
                display: block;
            }
            .sidebar.open {
                transform: translateX(0);
            }
            .sidebar-footer {
                position: absolute;
                bottom: 0;
                width: 100%;
            }
            .main-content {
                margin-left: 0 !important;
                padding: 20px 10px;
                transition: margin-left 0.3s ease;
            }
            #sidebarToggle {
                display: block;
                position: fixed;
                top: 15px;
                left: 15px;
                z-index: 1100;
                background-color: var(--sidebar-bg, #fff);
                border: none;
                padding: 8px 12px;
                border-radius: 5px;
                box-shadow: 0 0 5px rgba(0,0,0,0.2);
                cursor: pointer;
            }
        }
        @media (min-width: 992px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: 0;
                height: 100vh;
                width: 250px;
                background-color: var(--sidebar-bg, #fff);
                border-right: 1px solid var(--border-color, #dee2e6);
                display: flex;
                flex-direction: column;
                transform: none !important;
            }
            .main-content {
                margin-left: 250px;
                padding: 30px;
            }
            #sidebarToggle {
                display: none;
            }
        }

        /* Action buttons improvements */
        .table td .btn-sm {
            padding: 8px 12px !important;
            margin: 0 4px 0 0 !important;
            border-radius: 6px !important;
            min-width: 40px;
            height: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            transition: all 0.2s ease;
            border-width: 2px;
        }

        .table td .btn-sm:last-child {
            margin-right: 0 !important;
        }

        .table td .btn-sm i {
            font-size: 1rem;
        }

        .table td .btn-sm:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        .table td .btn-outline-primary {
            border-color: #0d6efd;
            color: #0d6efd;
        }

        .table td .btn-outline-primary:hover {
            background-color: #0d6efd;
            color: #fff;
        }

        .table td .btn-outline-success {
            border-color: #198754;
            color: #198754;
        }

        .table td .btn-outline-success:hover {
            background-color: #198754;
            color: #fff;
        }

        .table td .btn-outline-danger {
            border-color: #dc3545 !important;
            color: #dc3545 !important;
        }

        .table td .btn-outline-danger:hover {
            background-color: #dc3545 !important;
            color: #fff !important;
        }

        /* Dark mode improvements for action buttons */
        html[data-theme="dark"] .table td .btn-outline-primary,
        [data-theme="dark"] .table td .btn-outline-primary {
            border-color: #4e9eff;
            color: #4e9eff;
        }

        html[data-theme="dark"] .table td .btn-outline-primary:hover,
        [data-theme="dark"] .table td .btn-outline-primary:hover {
            background-color: #4e9eff;
            color: #fff;
        }

        html[data-theme="dark"] .table td .btn-outline-success,
        [data-theme="dark"] .table td .btn-outline-success {
            border-color: #4caf50;
            color: #4caf50;
        }

        html[data-theme="dark"] .table td .btn-outline-success:hover,
        [data-theme="dark"] .table td .btn-outline-success:hover {
            background-color: #4caf50;
            color: #fff;
        }
    </style>
</head>
<body>
    <!-- Hamburger Sidebar Toggle Button for Mobile -->
    <button id="sidebarToggle" aria-label="Toggle sidebar">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <img src="icons/Logo.png" alt="Water Billing Logo" class="img-fluid" style="max-height: 120px;" />
        </div>
        
        <div class="nav-content">
            <a href="adminlandingpage.php">
                <i class="fas fa-chart-line"></i>
                <span>Dashboard</span>
            </a>
            <a href="view_clients.php">
                <i class="fas fa-users"></i>
                <span>Customers</span>
            </a>
            <a href="billing_list.php">
                <i class="fas fa-file-invoice-dollar"></i>
                <span>Bills</span>
            </a>
            <a href="pending_readings.php">
                <i class="fas fa-camera"></i>
                <span>Meter Readings</span>
            </a>
            <a href="payments.php">
                <i class="fas fa-money-bill-wave"></i>
                <span>Payments</span>
            </a>
            <a href="customer_accounts.php">
                <i class="fas fa-user-circle"></i>
                <span>Customer Accounts</span>
            </a>
            <a href="reports.php">
                <i class="fas fa-chart-line"></i>
                <span>Reports</span>
            </a>
            <a href="client_reports.php" class="active">
                <i class="fas fa-chart-bar"></i>
                <span>Water Outage Reports</span>
            </a>
            <a href="disconnection_notices.php">
                <i class="fas fa-exclamation-triangle"></i>
                <span>Disconnection Notices</span>
            </a>
            <a href="settings_rate.php">
                <i class="fas fa-cog"></i>
                <span>Settings</span>
            </a>
        </div>

        <div class="sidebar-footer">
            <!-- Theme Switch -->
            <div class="theme-switch-wrapper">
                <i class="fas fa-sun"></i>
                <label class="theme-switch">
                    <input type="checkbox" id="theme-toggle">
                    <span class="slider"></span>
                </label>
                <i class="fas fa-moon"></i>
            </div>
            
            <form method="POST" action="logout.php" class="mt-3">
                <button type="submit" class="btn btn-outline-primary w-100">
                    <i class="fas fa-sign-out-alt me-2"></i>
                    Logout
                </button>
            </form>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <h2 class="mb-4">Client Reports & Notices Management</h2>

        <!-- Global Filters for Reports & Notices -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">From Date</label>
                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">To Date</label>
                        <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Notice Status</label>
                        <select name="notice_status" class="form-select">
                            <option value="active" <?php echo $notice_status_filter === 'active' ? 'selected' : ''; ?>>Active (ongoing / upcoming / recent)</option>
                            <option value="ongoing" <?php echo $notice_status_filter === 'ongoing' ? 'selected' : ''; ?>>Ongoing</option>
                            <option value="scheduled" <?php echo $notice_status_filter === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                            <option value="completed" <?php echo $notice_status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="all" <?php echo $notice_status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label d-block">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-filter me-2"></i>Apply Filters
                        </button>
                    </div>
                    <!-- Preserve current report status filter -->
                    <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter); ?>">
                </form>
            </div>
        </div>

        <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card">
                    <div class="stat-card" style="background: linear-gradient(45deg, #4e73df 0%, #224abe 100%);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-white">Total Reports</h6>
                                <h3 class="mb-0 text-white"><?php echo $stats['total_reports']; ?></h3>
                                <small class="text-white">All Time</small>
                            </div>
                            <i class="fas fa-file-alt fa-2x text-white-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="stat-card" style="background: linear-gradient(45deg, #1cc88a 0%, #13855c 100%);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-white">Resolved</h6>
                                <h3 class="mb-0 text-white"><?php echo $stats['resolved_reports']; ?></h3>
                                <small class="text-white">Reports</small>
                            </div>
                            <i class="fas fa-check-circle fa-2x text-white-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="stat-card" style="background: linear-gradient(45deg, #f6c23e 0%, #dda20a 100%);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-white">Pending</h6>
                                <h3 class="mb-0 text-white"><?php echo $stats['pending_reports']; ?></h3>
                                <small class="text-white">Need Action</small>
                            </div>
                            <i class="fas fa-clock fa-2x text-white-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Reports Section (Left) -->
            <div class="col-md-7">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-chart-bar me-2"></i>Client Reports
                        </h5>
                        <div class="btn-group" role="group">
                            <?php
                            // Helper to build URLs that keep date and notice filters
                            $baseQuery = http_build_query([
                                'date_from' => $date_from,
                                'date_to' => $date_to,
                                'notice_status' => $notice_status_filter,
                            ]);
                            ?>
                            <a href="?<?php echo $baseQuery; ?>&status_filter=all" class="btn btn-sm <?php echo $status_filter === 'all' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                                All
                            </a>
                            <a href="?<?php echo $baseQuery; ?>&status_filter=pending" class="btn btn-sm <?php echo $status_filter === 'pending' ? 'btn-warning' : 'btn-outline-warning'; ?>">
                                Pending
                            </a>
                            <a href="?<?php echo $baseQuery; ?>&status_filter=resolved" class="btn btn-sm <?php echo $status_filter === 'resolved' ? 'btn-success' : 'btn-outline-success'; ?>">
                                Resolved
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Client</th>
                                        <th>Type</th>
                                        <th>Description</th>
                                        <th>Status</th>
                                        <th>Submitted</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($report = $reports->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($report['firstname'] . ' ' . $report['lastname']); ?></td>
                                        <td>Water Outage</td>
                                        <td><?php echo htmlspecialchars($report['description']); ?></td>
                                        <td>
                                            <span class="badge <?php echo $report['status'] ? 'bg-success' : 'bg-warning'; ?>">
                                                <?php echo $report['status'] ? 'Resolved' : 'Pending'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo adminFormatDT($report['created_at']); ?></td>
                                        <td>
                                            <?php if (!$report['status']): ?>
                                            <button type="button" class="btn btn-sm btn-outline-success" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#resolveModal<?php echo $report['id']; ?>">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary"
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#viewModal<?php echo $report['id']; ?>">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </td>
                                    </tr>

                                    <!-- View Modal -->
                                    <div class="modal fade" id="viewModal<?php echo $report['id']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Report Details</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <h6>Client</h6>
                                                    <p><?php echo htmlspecialchars($report['firstname'] . ' ' . $report['lastname']); ?></p>
                                                    
                                                    <h6>Type</h6>
                                                    <p>Water Outage</p>
                                                    
                                                    <h6>Location</h6>
                                                    <p><?php echo htmlspecialchars($report['location']); ?></p>
                                                    
                                                    <h6>Description</h6>
                                                    <p><?php echo htmlspecialchars($report['description']); ?></p>
                                                    
                                                    <h6>Status</h6>
                                                    <p>
                                                        <span class="badge <?php echo $report['status'] ? 'bg-success' : 'bg-warning'; ?>">
                                                            <?php echo $report['status'] ? 'Resolved' : 'Pending'; ?>
                                                        </span>
                                                    </p>
                                                    
                                                    <h6>Submitted</h6>
                                                    <p><?php echo adminFormatDT($report['created_at']); ?></p>
                                                    
                                                    <?php if ($report['status']): ?>
                                                    <h6>Resolution Notes</h6>
                                                    <p><?php echo htmlspecialchars($report['resolution_notes']); ?></p>
                                                    
                                                    <h6>Resolved At</h6>
                                                    <p><?php echo adminFormatDT($report['resolved_at']); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Resolve Modal -->
                                    <?php if (!$report['status']): ?>
                                    <div class="modal fade" id="resolveModal<?php echo $report['id']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Resolve Report</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST" action="" id="resolveReportForm<?php echo $report['id']; ?>" class="resolve-report-form">
                                                    <div class="modal-body">
                                                        <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                                        <input type="hidden" name="resolve_report" value="1">
                                                        <div class="mb-3">
                                                            <label for="resolution_notes" class="form-label">Resolution Notes</label>
                                                            <textarea class="form-control" id="resolution_notes" name="resolution_notes" 
                                                                      rows="4" required></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-success">Resolve Report</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Notices Section (Right) -->
            <div class="col-md-5">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-bell me-2"></i>Notices Management
                        </h5>
                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createNoticeModal">
                            <i class="fas fa-plus me-2"></i>Create Notice
                        </button>
                    </div>
                    <div class="card-body">
                        <!-- Success/Error Messages -->
                        <?php if ($success_message): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?php echo $success_message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php endif; ?>

                        <?php if ($error_message): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?php echo $error_message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php endif; ?>

                        <!-- Notice Filter -->
                        <?php
                        $notice_status_filter = isset($_GET['notice_status_filter']) ? $_GET['notice_status_filter'] : 'all';
                        $notices_query_filtered = "
                            SELECT n.*, a.username as admin_name
                            FROM notices n
                            JOIN admin a ON n.created_by = a.id";
                        
                        if ($notice_status_filter === 'ongoing') {
                            $notices_query_filtered .= " WHERE n.status = 'ongoing'";
                        } elseif ($notice_status_filter === 'scheduled') {
                            $notices_query_filtered .= " WHERE n.status = 'scheduled'";
                        } elseif ($notice_status_filter === 'completed') {
                            $notices_query_filtered .= " WHERE n.status = 'completed'";
                        } else {
                            $notices_query_filtered .= " WHERE (n.status = 'ongoing' OR 
                                  (n.status = 'scheduled' AND n.start_date <= DATE_ADD(NOW(), INTERVAL 24 HOUR)) OR
                                  (n.status = 'completed' AND n.end_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)))";
                        }
                        
                        $notices_query_filtered .= " ORDER BY 
                            CASE n.status
                                WHEN 'ongoing' THEN 1
                                WHEN 'scheduled' THEN 2
                                WHEN 'completed' THEN 3
                            END,
                            n.start_date DESC";
                        $notices_filtered = $conn->query($notices_query_filtered);
                        ?>

                        <div class="mb-3">
                            <div class="btn-group btn-group-sm" role="group">
                                <a href="?notice_status_filter=all<?php echo $status_filter !== 'all' ? '&status_filter=' . $status_filter : ''; ?>" 
                                   class="btn <?php echo $notice_status_filter === 'all' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                                    All
                                </a>
                                <a href="?notice_status_filter=ongoing<?php echo $status_filter !== 'all' ? '&status_filter=' . $status_filter : ''; ?>" 
                                   class="btn <?php echo $notice_status_filter === 'ongoing' ? 'btn-warning' : 'btn-outline-warning'; ?>">
                                    Ongoing
                                </a>
                                <a href="?notice_status_filter=scheduled<?php echo $status_filter !== 'all' ? '&status_filter=' . $status_filter : ''; ?>" 
                                   class="btn <?php echo $notice_status_filter === 'scheduled' ? 'btn-info' : 'btn-outline-info'; ?>">
                                    Scheduled
                                </a>
                                <a href="?notice_status_filter=completed<?php echo $status_filter !== 'all' ? '&status_filter=' . $status_filter : ''; ?>" 
                                   class="btn <?php echo $notice_status_filter === 'completed' ? 'btn-success' : 'btn-outline-success'; ?>">
                                    Completed
                                </a>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Type</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($notice = $notices_filtered->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($notice['title']); ?></td>
                                        <td>
                                            <span class="badge <?php 
                                                $type_class = 'bg-secondary';
                                                if ($notice['type'] === 'interruption') {
                                                    $type_class = 'bg-danger';
                                                } elseif ($notice['type'] === 'maintenance') {
                                                    $type_class = 'bg-warning';
                                                } elseif ($notice['type'] === 'announcement') {
                                                    $type_class = 'bg-info';
                                                }
                                                echo $type_class;
                                            ?>">
                                                <?php echo ucfirst($notice['type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge <?php 
                                                $status_class = 'bg-secondary';
                                                if ($notice['status'] === 'ongoing') {
                                                    $status_class = 'bg-warning';
                                                } elseif ($notice['status'] === 'scheduled') {
                                                    $status_class = 'bg-info';
                                                } elseif ($notice['status'] === 'completed') {
                                                    $status_class = 'bg-success';
                                                }
                                                echo $status_class;
                                            ?>">
                                                <?php echo ucfirst($notice['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                    onclick="editNotice(<?php echo htmlspecialchars(json_encode($notice)); ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                                    onclick="deleteNotice(<?php echo $notice['id']; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Notice Modal -->
    <div class="modal fade" id="createNoticeModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create Notice</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create">
                        <div class="mb-3">
                            <label class="form-label">Title</label>
                            <select class="form-select" name="title" required>
                                <option value="">Select Notice Title</option>
                                <optgroup label="Water Interruption">
                                    <option value="Scheduled Water Interruption">Scheduled Water Interruption</option>
                                    <option value="Emergency Water Interruption">Emergency Water Interruption</option>
                                    <option value="Low Water Pressure Advisory">Low Water Pressure Advisory</option>
                                </optgroup>
                                <optgroup label="Maintenance">
                                    <option value="Pump Maintenance Schedule">Pump Maintenance Schedule</option>
                                    <option value="Pipeline Repair Works">Pipeline Repair Works</option>
                                    <option value="System Maintenance">System Maintenance</option>
                                </optgroup>
                                <optgroup label="General">
                                    <option value="Water Quality Advisory">Water Quality Advisory</option>
                                    <option value="Service Update">Service Update</option>
                                    <option value="Payment System Maintenance">Payment System Maintenance</option>
                                </optgroup>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="4" placeholder="Enter additional details (optional)"></textarea>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Type</label>
                                <select class="form-select" name="type" required>
                                    <option value="interruption">Water Interruption</option>
                                    <option value="maintenance">Maintenance</option>
                                    <option value="announcement">Announcement</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status" required>
                                    <option value="scheduled">Scheduled</option>
                                    <option value="ongoing">Ongoing</option>
                                    <option value="completed">Completed</option>
                                </select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Start Date & Time</label>
                                <input type="datetime-local" class="form-control" name="start_date" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">End Date & Time</label>
                                <input type="datetime-local" class="form-control" name="end_date">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Affected Areas</label>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="affected_areas[]" value="Purok 1-A" id="purok1a">
                                        <label class="form-check-label" for="purok1a">Purok 1-A</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="affected_areas[]" value="Purok 1-B" id="purok1b">
                                        <label class="form-check-label" for="purok1b">Purok 1-B</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="affected_areas[]" value="Purok 1-C" id="purok1c">
                                        <label class="form-check-label" for="purok1c">Purok 1-C</label>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="affected_areas[]" value="Purok 2" id="purok2">
                                        <label class="form-check-label" for="purok2">Purok 2</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="affected_areas[]" value="Purok 3" id="purok3">
                                        <label class="form-check-label" for="purok3">Purok 3</label>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="affected_areas[]" value="Purok 4" id="purok4">
                                        <label class="form-check-label" for="purok4">Purok 4</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="affected_areas[]" value="Purok 5" id="purok5">
                                        <label class="form-check-label" for="purok5">Purok 5</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Notice</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Notice Modal -->
    <div class="modal fade" id="editNoticeModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Notice</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="notice_id" id="edit_notice_id">
                        <div class="mb-3">
                            <label class="form-label">Title</label>
                            <select class="form-select" name="title" id="edit_title" required>
                                <option value="">Select Notice Title</option>
                                <optgroup label="Water Interruption">
                                    <option value="Scheduled Water Interruption">Scheduled Water Interruption</option>
                                    <option value="Emergency Water Interruption">Emergency Water Interruption</option>
                                    <option value="Low Water Pressure Advisory">Low Water Pressure Advisory</option>
                                </optgroup>
                                <optgroup label="Maintenance">
                                    <option value="Pump Maintenance Schedule">Pump Maintenance Schedule</option>
                                    <option value="Pipeline Repair Works">Pipeline Repair Works</option>
                                    <option value="System Maintenance">System Maintenance</option>
                                </optgroup>
                                <optgroup label="General">
                                    <option value="Water Quality Advisory">Water Quality Advisory</option>
                                    <option value="Service Update">Service Update</option>
                                    <option value="Payment System Maintenance">Payment System Maintenance</option>
                                </optgroup>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" id="edit_description" rows="4" placeholder="Enter additional details (optional)"></textarea>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Type</label>
                                <select class="form-select" name="type" id="edit_type" required>
                                    <option value="interruption">Water Interruption</option>
                                    <option value="maintenance">Maintenance</option>
                                    <option value="announcement">Announcement</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status" id="edit_status" required>
                                    <option value="scheduled">Scheduled</option>
                                    <option value="ongoing">Ongoing</option>
                                    <option value="completed">Completed</option>
                                </select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Start Date & Time</label>
                                <input type="datetime-local" class="form-control" name="start_date" id="edit_start_date" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">End Date & Time (Optional)</label>
                                <input type="datetime-local" class="form-control" name="end_date" id="edit_end_date">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Affected Areas</label>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="affected_areas[]" value="Purok 1-A" id="purok1a">
                                        <label class="form-check-label" for="purok1a">Purok 1-A</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="affected_areas[]" value="Purok 1-B" id="purok1b">
                                        <label class="form-check-label" for="purok1b">Purok 1-B</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="affected_areas[]" value="Purok 1-C" id="purok1c">
                                        <label class="form-check-label" for="purok1c">Purok 1-C</label>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="affected_areas[]" value="Purok 2" id="purok2">
                                        <label class="form-check-label" for="purok2">Purok 2</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="affected_areas[]" value="Purok 3" id="purok3">
                                        <label class="form-check-label" for="purok3">Purok 3</label>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="affected_areas[]" value="Purok 4" id="purok4">
                                        <label class="form-check-label" for="purok4">Purok 4</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="affected_areas[]" value="Purok 5" id="purok5">
                                        <label class="form-check-label" for="purok5">Purok 5</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Notice</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Notice Form -->
    <form id="deleteNoticeForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="notice_id" id="delete_notice_id">
    </form>

    <!-- Add Bootstrap and other required scripts before the closing body tag -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Notification System -->
    <script src="assets/js/notifications.js"></script>
    
    <script>
    function editNotice(notice) {
        document.getElementById('edit_notice_id').value = notice.id;
        document.getElementById('edit_title').value = notice.title;
        document.getElementById('edit_description').value = notice.description;
        document.getElementById('edit_type').value = notice.type;
        document.getElementById('edit_status').value = notice.status;
        document.getElementById('edit_start_date').value = notice.start_date.slice(0, 16);
        if (notice.end_date) {
            document.getElementById('edit_end_date').value = notice.end_date.slice(0, 16);
        }
        
        // Handle affected areas checkboxes
        const affectedAreas = notice.affected_areas.split(', ');
        document.querySelectorAll('input[name="affected_areas[]"]').forEach(checkbox => {
            checkbox.checked = affectedAreas.includes(checkbox.value);
        });
        
        new bootstrap.Modal(document.getElementById('editNoticeModal')).show();
    }

    function deleteNotice(id) {
        if (confirm('Are you sure you want to delete this notice?')) {
            document.getElementById('delete_notice_id').value = id;
            document.getElementById('deleteNoticeForm').submit();
        }
    }

    // Handle resolve report form submissions
    document.querySelectorAll('.resolve-report-form').forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            // Submit the form normally
            this.submit();
        });
    });

    // Add form validation for affected areas (only for notice forms)
    document.querySelectorAll('#createNoticeModal form, #editNoticeModal form').forEach(form => {
        form.addEventListener('submit', function(e) {
            const checkboxes = this.querySelectorAll('input[name="affected_areas[]"]');
            const checked = Array.from(checkboxes).some(cb => cb.checked);
            
            if (!checked) {
                e.preventDefault();
                showWarning('Please select at least one affected area.');
            }
        });
    });

    // Initialize Bootstrap components and handle form submission
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize all modals
        var modals = document.querySelectorAll('.modal');
        modals.forEach(function(modal) {
            new bootstrap.Modal(modal);
        });

        // Initialize datetime inputs
        const now = new Date();
        now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
        const nowStr = now.toISOString().slice(0, 16);
        document.querySelector('input[name="start_date"]').value = nowStr;

        // Theme switching functionality
        const themeToggle = document.getElementById('theme-toggle');
        const html = document.documentElement;
        
        // Load theme from localStorage using 'theme' key instead of 'darkMode'
        const savedTheme = localStorage.getItem('theme') || 'light';
        html.setAttribute('data-theme', savedTheme);
        themeToggle.checked = savedTheme === 'dark';

        // Theme toggle event listener
        themeToggle.addEventListener('change', function() {
            const theme = this.checked ? 'dark' : 'light';
            html.setAttribute('data-theme', theme);
            localStorage.setItem('theme', theme);
        });

        // Show active tab based on URL hash
        const hash = window.location.hash;
        if (hash) {
            const tab = new bootstrap.Tab(document.querySelector(`[data-bs-target="${hash}"]`));
            tab.show();
        }

        // Update URL hash when tab changes
        const tabEls = document.querySelectorAll('button[data-bs-toggle="tab"]');
        tabEls.forEach(tabEl => {
            tabEl.addEventListener('shown.bs.tab', function (event) {
                const targetId = event.target.getAttribute('data-bs-target');
                window.location.hash = targetId;
            });
        });

        // Handle notice form submission
        const createNoticeForm = document.querySelector('#createNoticeModal form');
        if (createNoticeForm) {
            createNoticeForm.addEventListener('submit', function(e) {
                const requiredFields = createNoticeForm.querySelectorAll('[required]');
                let isValid = true;

                requiredFields.forEach(field => {
                    if (!field.value.trim()) {
                        isValid = false;
                        field.classList.add('is-invalid');
                    } else {
                        field.classList.remove('is-invalid');
                    }
                });

                if (!isValid) {
                    e.preventDefault();
                    showWarning('Please fill in all required fields.');
                }
            });
        }

        // Sidebar toggle for mobile
        var sidebar = document.querySelector('.sidebar');
        var sidebarToggle = document.getElementById('sidebarToggle');
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function() {
                sidebar.classList.toggle('open');
            });
        }
        // Optional: close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 991 && sidebar.classList.contains('open')) {
                if (!sidebar.contains(e.target) && e.target !== sidebarToggle) {
                    sidebar.classList.remove('open');
                }
            }
        });
    });
    </script>
</body>
</html> 