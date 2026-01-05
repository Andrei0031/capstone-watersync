<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: adminlogin.php");
    exit();
}

include 'db.php';
include 'late_payment_processor.php';

// Ensure system_settings table exists for delete password configuration
if ($conn instanceof mysqli) {
    $create_settings_table = "CREATE TABLE IF NOT EXISTS system_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    $conn->query($create_settings_table);
}

// Check if delete actions are enabled (password set + toggle enabled)
$delete_password_configured = false;
$has_password = false;
$delete_enabled = false;
$settings_result = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('delete_password', 'delete_enabled')");
if ($settings_result) {
    while ($row = $settings_result->fetch_assoc()) {
        if ($row['setting_key'] === 'delete_password' && !empty($row['setting_value'])) {
            $has_password = true;
        }
        if ($row['setting_key'] === 'delete_enabled' && $row['setting_value'] === '1') {
            $delete_enabled = true;
        }
    }
}
$delete_password_configured = $has_password && $delete_enabled;

// Calculate statistics
$total_sql = "SELECT 
    COUNT(*) as total,
    COALESCE(SUM(CASE WHEN status = 1 THEN amount ELSE 0 END), 0) as total_amount,
    COALESCE(SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END), 0) as verified_count,
    COALESCE(SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END), 0) as pending_count
    FROM payment_list";
$total_result = $conn->query($total_sql);
$stats = $total_result->fetch_assoc();
if (!$stats) {
    $stats = ['total' => 0, 'total_amount' => 0, 'verified_count' => 0, 'pending_count' => 0];
}

// Get today's payments
$today_sql = "SELECT 
    COUNT(*) as today_count, 
    COALESCE(SUM(CASE WHEN status = 1 THEN amount ELSE 0 END), 0) as today_amount 
    FROM payment_list 
    WHERE DATE(payment_date) = CURRENT_DATE()";
$today_result = $conn->query($today_sql);
$today_stats = $today_result->fetch_assoc();
if (!$today_stats) {
    $today_stats = ['today_count' => 0, 'today_amount' => 0];
}

// Process payment verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_payment'])) {
    $payment_id = $_POST['payment_id'];
    $update_sql = "UPDATE payment_list SET status = 1, verified_date = CURRENT_TIMESTAMP WHERE id = ?";
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param("i", $payment_id);
    
    if ($stmt->execute()) {
        header("Location: payments.php?success=Payment verified successfully");
    } else {
        header("Location: payments.php?error=Failed to verify payment");
    }
    exit();
}

// Fetch payments with client information
$payments_sql = "SELECT 
    pl.*, 
    cl.firstname, 
    cl.lastname, 
    cl.meter_code, 
    bl.reading_date,
    bl.total as bill_total,
    COALESCE((SELECT SUM(amount) FROM payment_list WHERE billing_id = bl.id AND status = 1), 0) as total_paid,
    CASE 
        WHEN pl.status = 1 AND pl.amount >= (bl.total - COALESCE((SELECT SUM(amount) FROM payment_list WHERE billing_id = bl.id AND status = 1 AND id != pl.id), 0)) THEN 'Fully Paid'
        WHEN pl.status = 1 THEN 'Partial Payment'
        ELSE 'Partial Payment'
    END as status_text,
    pl.amount as display_amount
FROM payment_list pl 
JOIN client_list cl ON pl.client_id = cl.id 
JOIN billing_list bl ON pl.billing_id = bl.id 
ORDER BY pl.payment_date DESC";
$payments_result = $conn->query($payments_sql);

?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments - Water Billing System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Theme variables */
        :root[data-theme="light"] {
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
            --table-hover-bg: #32353a;
            --modal-bg: #2d2f34;
            --input-bg: #242529;
            --input-border: #393b40;
            --input-text: #e4e6eb;
            --input-placeholder: #6c757d;
        }

        body {
            font-family: 'Open Sans', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            transition: background-color 0.3s, color 0.3s;
            min-height: 100vh;
            margin: 0;
        }

        /* Add this new style for text-muted */
        .text-muted {
            color: var(--muted-text) !important;
        }

        /* Dark mode improvements for text-muted */
        html[data-theme="dark"] .text-muted,
        [data-theme="dark"] .text-muted {
            color: #b0b0b0 !important;
        }

        html[data-theme="dark"] .table .text-muted,
        [data-theme="dark"] .table .text-muted {
            color: #b0b0b0 !important;
        }

        /* Sidebar styles */
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

        /* Theme switch */
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

        /* Main content */
        .main-content {
            margin-left: 250px;
            padding: 30px;
        }

        /* Card styles */
        .card-soft {
            background-color: var(--card-bg);
            border-color: var(--border-color);
            color: var(--text-color);
            border-radius: 15px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            border: none;
            margin-bottom: 20px;
        }

        /* Table styles */
        .table {
            color: var(--text-color);
            background-color: var(--table-bg);
        }

        .table thead th {
            background-color: var(--table-header-bg);
            color: var(--table-header-text);
            border-color: var(--border-color);
        }

        .table tbody td {
            background-color: var(--table-bg);
            color: var(--table-cell-text);
            border-color: var(--border-color);
        }

        .table tbody tr:hover {
            background-color: var(--table-hover-bg);
        }

        /* Dark mode table improvements */
        html[data-theme="dark"] .table tbody td,
        [data-theme="dark"] .table tbody td {
            color: var(--text-color) !important;
        }

        html[data-theme="dark"] .table tbody td .fw-bold,
        [data-theme="dark"] .table tbody td .fw-bold {
            color: var(--text-color) !important;
        }

        /* Status badges */
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.875rem;
        }

        .status-verified, .status-fully-paid { 
            background-color: #19875420; 
            color: #198754; 
        }

        .status-pending, .status-partial-payment { 
            background-color: #ffc10720; 
            color: #ffc107; 
        }

        html[data-theme="dark"] .status-verified,
        [data-theme="dark"] .status-verified,
        html[data-theme="dark"] .status-fully-paid,
        [data-theme="dark"] .status-fully-paid { 
            background-color: #19875430 !important; 
            color: #4caf50 !important; 
            border: 1px solid #19875460;
        }

        html[data-theme="dark"] .status-pending,
        [data-theme="dark"] .status-pending,
        html[data-theme="dark"] .status-partial-payment,
        [data-theme="dark"] .status-partial-payment { 
            background-color: #ffc10730 !important; 
            color: #ffc107 !important; 
            border: 1px solid #ffc10760;
        }

        /* Avatar */
        .avatar-sm {
            width: 40px;
            height: 40px;
            background: linear-gradient(45deg, #4e73df 0%, #224abe 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            margin-right: 12px;
        }

        /* Form controls */
        .form-control {
            background-color: var(--input-bg);
            border-color: var(--input-border);
            color: var(--input-text);
        }

        .form-control:focus {
            background-color: var(--input-bg);
            border-color: var(--hover-text);
            color: var(--input-text);
        }

        .form-control::placeholder {
            color: var(--input-placeholder);
        }

        /* Modal styles */
        .modal-content {
            background-color: var(--modal-bg);
            border-color: var(--border-color);
        }

        .modal-header {
            border-bottom-color: var(--border-color);
        }

        .modal-footer {
            border-top-color: var(--border-color);
        }

        [data-theme="dark"] .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
        }

        /* Responsive Sidebar and Hamburger Toggle */
        @media (max-width: 991.98px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: 0;
                height: 100vh;
                width: 250px;
                background-color: var(--sidebar-bg);
                border-right: 1px solid var(--border-color);
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
                background-color: var(--sidebar-bg);
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
                background-color: var(--sidebar-bg);
                border-right: 1px solid var(--border-color);
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
        @media (max-width: 767.98px) {
            .table {
                font-size: 0.92rem;
            }
            .table th, .table td {
                padding: 6px 4px !important;
                word-break: break-word;
                vertical-align: middle;
            }
            .table th {
                white-space: nowrap;
            }
            .table td {
                max-width: 90px;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .table td .status-badge {
                max-width: none;
            }
            .btn-group .btn {
                padding: 3px 6px;
                margin-right: 2px;
                font-size: 0.95em;
            }
            .avatar-sm {
                width: 32px;
                height: 32px;
                font-size: 1em;
                margin-right: 7px;
            }
            .status-badge {
                padding: 2px 6px !important;
                font-size: 0.82em !important;
                border-radius: 12px !important;
                white-space: nowrap !important;
            }
        }

        /* Modal Visibility Improvements */
        .modal {
            z-index: 1055 !important;
        }
        
        .modal-backdrop {
            z-index: 1050 !important;
            background-color: rgba(0, 0, 0, 0.7) !important;
        }
        
        .modal-dialog {
            margin: 30px auto !important;
        }
        
        .modal-content {
            background-color: #ffffff !important;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3) !important;
            border: none !important;
            border-radius: 10px !important;
        }
        
        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
            color: white !important;
            border-radius: 10px 10px 0 0 !important;
            border-bottom: none !important;
        }
        
        .modal-header .btn-close {
            background: transparent !important;
            color: white !important;
            opacity: 0.8 !important;
        }
        
        .modal-header .btn-close:hover {
            opacity: 1 !important;
        }
        
        .modal-title {
            font-weight: 600 !important;
        }
        
        /* Ensure modal appears above everything */
        .modal.show {
            display: block !important;
        }
        
        .modal.fade.show {
            opacity: 1 !important;
        }
        
        /* Ensure modal body has white background */
        .modal-body {
            background-color: #ffffff !important;
            color: #333333 !important;
        }
        
        .modal-footer {
            background-color: #ffffff !important;
            border-top: 1px solid #dee2e6 !important;
            border-radius: 0 0 10px 10px !important;
        }
        
        /* Override any theme-based backgrounds */
        .modal-content * {
            color: #333333 !important;
        }
        
        .modal-content .form-control {
            background-color: #ffffff !important;
            border: 1px solid #ced4da !important;
            color: #333333 !important;
        }
        
        .modal-content .form-select {
            background-color: #ffffff !important;
            border: 1px solid #ced4da !important;
            color: #333333 !important;
        }
        
        .modal-content .card {
            background-color: #f8f9fa !important;
            border: 1px solid #dee2e6 !important;
        }
        
        .modal-content .alert {
            background-color: #d1ecf1 !important;
            border: 1px solid #bee5eb !important;
            color: #0c5460 !important;
        }

        /* Action buttons improvements */
        .table td .btn-group {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .table td .btn-group .btn {
            padding: 8px 12px !important;
            margin: 0 !important;
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

        .table td .btn-group .btn i {
            font-size: 1rem;
        }

        .table td .btn-group .btn:hover {
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
        /* Client Search Dropdown Styles */
        .client-option {
            transition: background-color 0.2s;
        }
        .client-option:hover {
            background-color: var(--hover-bg, #f0f0f0) !important;
        }
        html[data-theme="dark"] .client-option:hover {
            background-color: var(--hover-bg, #3a3c42) !important;
        }
        #clientDropdown {
            margin-top: 2px;
        }
        .cursor-pointer {
            cursor: pointer;
        }
    </style>
</head>
<body>
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
            <span>Billing Dashboard</span>
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
        <a href="payments.php" class="active">
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
        <a href="client_reports.php">
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
    <!-- Header Section -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">Payment Management</h2>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPaymentModal">
            <i class="fas fa-plus me-2"></i>Record Payment
        </button>
    </div>

    <!-- Stats Row -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card card-soft stat-card" style="background: linear-gradient(45deg, #4e73df 0%, #224abe 100%);">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-white-50">Total Payments</h6>
                            <h3 class="mb-0 text-white"><?php echo number_format($stats['total']); ?></h3>
                            <small class="text-white-50"><?php echo number_format($today_stats['today_count']); ?> today</small>
                        </div>
                        <i class="fas fa-money-bill-wave fa-2x text-white-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-soft stat-card" style="background: linear-gradient(45deg, #1cc88a 0%, #13855c 100%);">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-white-50">Total Amount</h6>
                            <h3 class="mb-0 text-white">₱<?php echo number_format($stats['total_amount'] ?? 0, 2); ?></h3>
                            <small class="text-white-50">₱<?php echo number_format($today_stats['today_amount'] ?? 0, 2); ?> today</small>
                        </div>
                        <i class="fas fa-peso-sign fa-2x text-white-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-soft stat-card" style="background: linear-gradient(45deg, #36b9cc 0%, #258391 100%);">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-white-50">Fully Paid Payments</h6>
                            <h3 class="mb-0 text-white"><?php echo number_format($stats['verified_count']); ?></h3>
                            <small class="text-white-50">Completed payments</small>
                        </div>
                        <i class="fas fa-check-circle fa-2x text-white-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-soft stat-card" style="background: linear-gradient(45deg, #f6c23e 0%, #dda20a 100%);">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-white-50">Partial Payments</h6>
                            <h3 class="mb-0 text-white"><?php echo number_format($stats['pending_count']); ?></h3>
                            <small class="text-white-50">Incomplete payments</small>
                        </div>
                        <i class="fas fa-clock fa-2x text-white-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Payments Table -->
    <div class="card card-soft">
        <div class="card-header py-3">
            <h5 class="mb-0">Payment Records</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Reference No.</th>
                            <th>Amount</th>
                            <th>Payment Date</th>
                            <th>Reading Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($payments_result->num_rows > 0): ?>
                            <?php while($row = $payments_result->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div>
                                            <div class="fw-bold"><?php echo htmlspecialchars($row['firstname'] . ' ' . $row['lastname']); ?></div>
                                            <div class="text-muted"><?php echo htmlspecialchars($row['meter_code']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($row['reference_number']); ?></td>
                                <td>₱<?php echo number_format($row['display_amount'], 2); ?></td>
                                <td><?php echo date('M d, Y', strtotime($row['payment_date'])); ?></td>
                                <td><?php echo date('M d, Y', strtotime($row['reading_date'])); ?></td>
                                <td>
                                    <span class="status-badge status-verified"><?php echo htmlspecialchars($row['status_text']); ?></span>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <a href="view_payment.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="View Payment Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if ($row['status'] == 0): ?>
                                            <form method="POST" class="d-inline" style="margin: 0;">
                                                <input type="hidden" name="payment_id" value="<?php echo $row['id']; ?>">
                                                <button type="submit" name="verify_payment" class="btn btn-sm btn-outline-success" title="Verify Payment">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($delete_password_configured): ?>
                                        <button class="btn btn-sm btn-outline-danger" onclick="deletePaymentWithPassword(<?php echo $row['id']; ?>)" title="Delete Payment">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    <i class="fas fa-money-bill-wave fa-3x mb-3"></i>
                                    <p class="mb-0">No payment records found</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Payment Modal -->
<div class="modal fade" id="addPaymentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Record New Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="paymentForm" method="POST">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Client</label>
                            <div class="position-relative">
                                <input type="text" class="form-control" id="clientSearch" placeholder="Search client by name or meter code..." autocomplete="off">
                                <select class="form-select" name="client_id" id="clientSelect" required style="display: none;">
                                    <option value="">Select Client</option>
                                    <?php
                                    $client_sql = "SELECT id, CONCAT(firstname, ' ', lastname, ' (', meter_code, ')') as client_name, firstname, lastname, meter_code FROM client_list ORDER BY firstname";
                                    $client_result = $conn->query($client_sql);
                                    while ($client = $client_result->fetch_assoc()) {
                                        echo "<option value='" . $client['id'] . "' data-name='" . htmlspecialchars(strtolower($client['firstname'] . ' ' . $client['lastname'])) . "' data-meter='" . htmlspecialchars(strtolower($client['meter_code'])) . "'>" . htmlspecialchars($client['client_name']) . "</option>";
                                    }
                                    ?>
                                </select>
                                <div id="clientDropdown" class="position-absolute w-100 bg-white border rounded shadow-lg" style="max-height: 200px; overflow-y: auto; z-index: 1000; display: none; top: 100%;"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Reference Number</label>
                            <input type="text" class="form-control" name="reference_number" id="referenceNumber" readonly>
                            <small class="text-muted">Auto-generated</small>
                        </div>
                    </div>

                    <!-- Payment Summary Section -->
                    <div class="card mb-3">
                        <div class="card-body bg-light">
                            <h6 class="card-title mb-3">Payment Summary</h6>
                            <div id="selectedBillsSummary" class="mb-3" style="display: none;">
                                <small class="text-muted">Selected Bills:</small>
                                <div id="selectedBillsList" class="mt-1"></div>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Total Amount Due</label>
                                    <div class="input-group">
                                        <span class="input-group-text">₱</span>
                                        <input type="number" class="form-control" name="total_due" id="totalDue" step="0.01" required readonly>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Amount to Pay</label>
                                    <div class="input-group">
                                        <span class="input-group-text">₱</span>
                                        <input type="number" class="form-control" name="amount" id="amountToPay" step="0.01" min="0.01" required>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Remaining Balance</label>
                                    <div class="input-group">
                                        <span class="input-group-text">₱</span>
                                        <input type="number" class="form-control" id="remainingBalance" step="0.01" readonly>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-12">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label mb-0">Select Bills to Pay</label>
                                <button type="button" class="btn btn-sm btn-outline-primary" id="selectAllBills" style="display: none;">
                                    <i class="fas fa-check-square me-1"></i>Select All
                                </button>
                            </div>
                            <div class="bill-selection-container border rounded p-3" style="max-height: 300px; overflow-y: auto;">
                                <div id="billsList" class="d-flex flex-column gap-2">
                                    <!-- Bills will be populated here -->
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label">Payment Date</label>
                            <input type="date" class="form-control" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Payment Method</label>
                            <select class="form-select" name="payment_method" required>
                                <option value="cash">Cash</option>
                                <option value="gcash">GCash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add the View Payment Modal -->
<div class="modal fade" id="viewPaymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Payment Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div class="d-flex align-items-center mb-3">
                    <div class="avatar-sm me-3" id="customerInitials"></div>
                    <div>
                        <h6 class="mb-1" id="customerName"></h6>
                        <small class="text-muted" id="meterCode"></small>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-6">
                        <small class="text-muted d-block">Reference Number</small>
                        <strong id="referenceNumberView"></strong>
                    </div>
                    <div class="col-6">
                        <small class="text-muted d-block">Amount</small>
                        <strong id="amountView"></strong>
                    </div>
                    <div class="col-6">
                        <small class="text-muted d-block">Payment Date</small>
                        <strong id="paymentDateView"></strong>
                    </div>
                    <div class="col-6">
                        <small class="text-muted d-block">Payment Method</small>
                        <strong id="paymentMethodView"></strong>
                    </div>
                    <div class="col-6">
                        <small class="text-muted d-block">Status</small>
                        <div id="statusBadgeView" class="status-badge"></div>
                    </div>
                    <div class="col-6" id="verifiedDateContainer" style="display: none;">
                        <small class="text-muted d-block">Verified Date</small>
                        <strong id="verifiedDateView"></strong>
                    </div>
                </div>

                <hr class="my-3">
                <h6 class="mb-3">Bill Information</h6>
                <div class="row g-3">
                    <div class="col-6">
                        <small class="text-muted d-block">Reading Date</small>
                        <strong id="readingDateView"></strong>
                    </div>
                    <div class="col-6">
                        <small class="text-muted d-block">Current Reading</small>
                        <strong id="readingView"></strong>
                    </div>
                    <div class="col-6">
                        <small class="text-muted d-block">Previous Reading</small>
                        <strong id="previousView"></strong>
                    </div>
                    <div class="col-12">
                        <small class="text-muted d-block">Total Bill Amount</small>
                        <strong class="text-primary" id="billTotalView"></strong>
                    </div>
                </div>
            </div>
            <div class="modal-footer pending-actions" style="display: none;">
                <form method="POST" class="d-inline-block w-100">
                    <input type="hidden" name="payment_id" id="paymentIdView">
                    <div class="d-flex gap-2">
                        <button type="submit" name="verify_payment" class="btn btn-success flex-grow-1">
                            <i class="fas fa-check me-2"></i>Verify Payment
                        </button>
                        <button type="button" class="btn btn-danger" onclick="deletePaymentFromModal()">
                            <i class="fas fa-trash me-2"></i>Delete
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Add Delete Confirmation Modal -->
<div class="modal fade" id="deletePaymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                Are you sure you want to delete this payment record?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeletePaymentBtn">Delete</button>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap JS and other scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Notification System -->
<script src="assets/js/notifications.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Check for payment_id in URL parameters
    const urlParams = new URLSearchParams(window.location.search);
    const newPaymentId = urlParams.get('payment_id');
    if (newPaymentId) {
        // Show payment details modal for the new payment
        viewPayment(newPaymentId);
        
        // Clean URL without reloading the page
        window.history.replaceState({}, document.title, 'payments.php');
    }

    // Theme Toggle with error handling
    const themeToggle = document.getElementById('theme-toggle');
    const root = document.documentElement;
    
    function getThemePreference() {
        try {
            return localStorage.getItem('theme') || 'light';
        } catch (e) {
            console.warn('Local storage access denied:', e);
            return 'light';
        }
    }

    function setThemePreference(theme) {
        try {
            localStorage.setItem('theme', theme);
        } catch (e) {
            console.warn('Local storage access denied:', e);
        }
    }
    
    // Check for saved theme preference or default to light
    const savedTheme = getThemePreference();
    root.setAttribute('data-theme', savedTheme);
    themeToggle.checked = savedTheme === 'dark';

    themeToggle.addEventListener('change', function() {
        const theme = this.checked ? 'dark' : 'light';
        root.setAttribute('data-theme', theme);
        setThemePreference(theme);
    });

    const clientSearch = document.getElementById('clientSearch');
    const clientSelect = document.getElementById('clientSelect');
    const clientDropdown = document.getElementById('clientDropdown');
    const billsList = document.getElementById('billsList');
    const totalAmountInput = document.getElementById('totalDue');
    const amountToPayInput = document.getElementById('amountToPay');
    const remainingBalanceInput = document.getElementById('remainingBalance');
    const selectAllBillsBtn = document.getElementById('selectAllBills');
    const selectedBillsSummary = document.getElementById('selectedBillsSummary');
    const selectedBillsList = document.getElementById('selectedBillsList');
    let selectedBills = new Set();
    let allBills = [];
    
    // Client search functionality
    clientSearch.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase().trim();
        const options = clientSelect.querySelectorAll('option');
        const filteredOptions = [];
        
        if (searchTerm === '') {
            clientDropdown.style.display = 'none';
            return;
        }
        
        options.forEach(option => {
            if (option.value === '') return;
            const name = option.dataset.name || '';
            const meter = option.dataset.meter || '';
            const text = option.textContent.toLowerCase();
            
            if (name.includes(searchTerm) || meter.includes(searchTerm) || text.includes(searchTerm)) {
                filteredOptions.push(option);
            }
        });
        
        if (filteredOptions.length > 0) {
            clientDropdown.innerHTML = filteredOptions.map(option => 
                `<div class="p-2 border-bottom cursor-pointer client-option" data-value="${option.value}" style="cursor: pointer;">
                    ${option.textContent}
                </div>`
            ).join('');
            clientDropdown.style.display = 'block';
        } else {
            clientDropdown.innerHTML = '<div class="p-2 text-muted">No clients found</div>';
            clientDropdown.style.display = 'block';
        }
    });
    
    // Handle client selection from dropdown
    clientDropdown.addEventListener('click', function(e) {
        if (e.target.classList.contains('client-option')) {
            const value = e.target.dataset.value;
            const text = e.target.textContent.trim();
            clientSelect.value = value;
            clientSearch.value = text;
            clientDropdown.style.display = 'none';
            
            if (value) {
                generateReference();
                loadClientBills(value);
            } else {
                resetPaymentForm();
            }
        }
    });
    
    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (!clientSearch.contains(e.target) && !clientDropdown.contains(e.target)) {
            clientDropdown.style.display = 'none';
        }
    });
    
    // Update client select change handler
    const originalClientSelect = document.querySelector('select[name="client_id"]');
    if (originalClientSelect) {
        originalClientSelect.addEventListener('change', function() {
            if (this.value) {
                clientSelect.value = this.value;
                clientSearch.value = this.options[this.selectedIndex].textContent;
                generateReference();
                loadClientBills(this.value);
            } else {
                resetPaymentForm();
            }
        });
    }

    // Auto-generate reference number when client is selected (using hidden select)
    clientSelect.addEventListener('change', function() {
        if (this.value) {
            generateReference();
            loadClientBills(this.value);
        } else {
            resetPaymentForm();
        }
    });
    
    // Select All Bills functionality
    selectAllBillsBtn.addEventListener('click', function() {
        const checkboxes = document.querySelectorAll('.bill-checkbox');
        const allSelected = Array.from(checkboxes).every(cb => cb.checked);
        
        checkboxes.forEach(cb => {
            cb.checked = !allSelected;
            if (!allSelected) {
                selectedBills.add(cb.value);
            } else {
                selectedBills.delete(cb.value);
            }
        });
        
        updateSelectedBillsSummary();
        updateAmountToPay();
        this.innerHTML = allSelected ? 
            '<i class="fas fa-check-square me-1"></i>Select All' : 
            '<i class="fas fa-square me-1"></i>Deselect All';
    });
    
    function updateSelectedBillsSummary() {
        if (selectedBills.size === 0) {
            selectedBillsSummary.style.display = 'none';
            return;
        }
        
        const selectedBillsData = allBills.filter(bill => selectedBills.has(bill.id.toString()));
        const totalSelected = selectedBillsData.reduce((sum, bill) => {
            let billAmount = parseFloat(bill.balance);
            if (bill.late_fee_info && bill.late_fee_info.has_late_fee && !bill.late_fee_info.already_applied) {
                billAmount += parseFloat(bill.late_fee_info.fee_amount);
            }
            return sum + billAmount;
        }, 0);
        
        selectedBillsList.innerHTML = selectedBillsData.map(bill => {
            let billAmount = parseFloat(bill.balance);
            if (bill.late_fee_info && bill.late_fee_info.has_late_fee && !bill.late_fee_info.already_applied) {
                billAmount += parseFloat(bill.late_fee_info.fee_amount);
            }
            return `<div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                <span class="small">${bill.reading_date}</span>
                <strong class="text-primary">₱${billAmount.toFixed(2)}</strong>
            </div>`;
        }).join('') + 
        `<div class="d-flex justify-content-between align-items-center pt-2 mt-2 border-top">
            <strong>Total Selected (Amount to Pay):</strong>
            <strong class="text-success">₱${totalSelected.toFixed(2)}</strong>
        </div>`;
        
        selectedBillsSummary.style.display = 'block';
    }
    
    function updateAmountToPay() {
        const selectedBillsData = allBills.filter(bill => selectedBills.has(bill.id.toString()));
        const totalSelected = selectedBillsData.reduce((sum, bill) => {
            let billAmount = parseFloat(bill.balance);
            if (bill.late_fee_info && bill.late_fee_info.has_late_fee && !bill.late_fee_info.already_applied) {
                billAmount += parseFloat(bill.late_fee_info.fee_amount);
            }
            return sum + billAmount;
        }, 0);
        
        amountToPayInput.value = totalSelected.toFixed(2);
        const totalDue = parseFloat(totalAmountInput.value) || 0;
        const remainingBalance = Math.max(0, totalDue - totalSelected);
        remainingBalanceInput.value = remainingBalance.toFixed(2);
    }

    // Prevent negative and invalid characters in amount to pay
    amountToPayInput.addEventListener('keydown', function(e) {
        if (e.key === '-' || e.key === 'e' || e.key === 'E') {
            e.preventDefault();
        }
    });

    // Handle amount to pay changes
    amountToPayInput.addEventListener('input', function() {
        const totalDue = parseFloat(totalAmountInput.value) || 0;
        const amountToPay = parseFloat(this.value) || 0;
        const remainingBalance = Math.max(0, totalDue - amountToPay);
        remainingBalanceInput.value = remainingBalance.toFixed(2);
        
        // Validate amount to pay
        if (amountToPay > totalDue) {
            this.setCustomValidity('Amount to pay cannot exceed total due amount');
        } else if (amountToPay <= 0) {
            this.setCustomValidity('Amount to pay must be greater than 0');
        } else {
            this.setCustomValidity('');
        }

        // Auto-select bills based on amount to pay
        const checkboxes = document.querySelectorAll('.bill-checkbox');
        let remainingAmount = amountToPay;
        
        // First uncheck all bills
        checkboxes.forEach(cb => {
            cb.checked = false;
            selectedBills.delete(cb.value);
        });

        // Then select bills until we reach the amount to pay
        checkboxes.forEach(cb => {
            const billAmount = parseFloat(cb.dataset.amount);
            if (remainingAmount >= billAmount) {
                cb.checked = true;
                selectedBills.add(cb.value);
                remainingAmount -= billAmount;
            }
        });
    });

    function updateRemainingBalance() {
        const totalDue = parseFloat(totalAmountInput.value) || 0;
        const amountToPay = parseFloat(amountToPayInput.value) || 0;
        const remainingBalance = Math.max(0, totalDue - amountToPay);
        remainingBalanceInput.value = remainingBalance.toFixed(2);
        
        // Validate amount to pay
        if (amountToPay > totalDue) {
            amountToPayInput.setCustomValidity('Amount to pay cannot exceed total due amount');
        } else if (amountToPay <= 0) {
            amountToPayInput.setCustomValidity('Amount to pay must be greater than 0');
        } else {
            amountToPayInput.setCustomValidity('');
        }
    }

    async function loadClientBills(clientId) {
        try {
            console.log('🔄 Loading bills for client:', clientId);
            
            const response = await fetch(`get_client_bills_enhanced.php?client_id=${clientId}`);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const bills = await response.json();
            console.log('📋 Received bills:', bills);
            
            // Check if response contains error
            if (bills.error) {
                throw new Error(bills.error);
            }
            
            // Ensure bills is an array
            if (!Array.isArray(bills)) {
                throw new Error('Invalid response format: expected array of bills');
            }
            
            if (bills.length === 0) {
                billsList.innerHTML = `
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle me-2"></i>
                        No unpaid bills found for this client.
                    </div>
                `;
                totalAmountInput.value = '0.00';
                amountToPayInput.value = '0.00';
                remainingBalanceInput.value = '0.00';
                return;
            }
            
            // Calculate total unpaid balance including pending late fees
            const totalUnpaidBalance = bills.reduce((sum, bill) => {
                let billTotal = parseFloat(bill.balance);
                
                // Add pending late fees that haven't been applied yet
                if (bill.late_fee_info && bill.late_fee_info.has_late_fee && !bill.late_fee_info.already_applied) {
                    billTotal += parseFloat(bill.late_fee_info.fee_amount);
                }
                
                return sum + billTotal;
            }, 0);
            
            // Set the total due amount immediately when bills are loaded
            totalAmountInput.value = totalUnpaidBalance.toFixed(2);
            amountToPayInput.value = totalUnpaidBalance.toFixed(2);
            amountToPayInput.max = totalUnpaidBalance;
            remainingBalanceInput.value = '0.00';
            
            billsList.innerHTML = bills.map(bill => {
                const isOverdue = bill.is_overdue;
                const hasLateFee = bill.late_fee_info && bill.late_fee_info.has_late_fee;
                const hasAppliedFees = bill.has_applied_fees;
                
                // Calculate total amount due for this bill (including pending late fees)
                let billAmountDue = parseFloat(bill.balance);
                if (hasLateFee && !bill.late_fee_info.already_applied) {
                    billAmountDue += parseFloat(bill.late_fee_info.fee_amount);
                }
                
                let overdueWarning = '';
                let lateFeeWarning = '';
                let appliedFeesInfo = '';
                
                if (isOverdue) {
                    overdueWarning = `<div class="badge bg-danger mb-1">Overdue: ${bill.days_overdue} days</div>`;
                }
                
                if (hasLateFee && !bill.late_fee_info.already_applied) {
                    lateFeeWarning = `
                        <div class="alert alert-success py-1 px-2 mb-1" style="font-size: 0.75rem;">
                            <i class="fas fa-plus-circle me-1"></i>
                            <strong>Late fee: ₱${parseFloat(bill.late_fee_info.fee_amount).toFixed(2)} included in total</strong>
                        </div>
                    `;
                }
                
                if (hasAppliedFees) {
                    const feesList = bill.applied_fees.map(fee => 
                        `${fee.fee_name}: ₱${parseFloat(fee.fee_amount).toFixed(2)}`
                    ).join(', ');
                    appliedFeesInfo = `
                        <div class="text-info mb-1" style="font-size: 0.75rem;">
                            <i class="fas fa-info-circle me-1"></i>
                            Applied fees: ${feesList}
                        </div>
                    `;
                }
                
                return `
                    <div class="bill-item border rounded p-2 ${isOverdue ? 'border-warning' : ''}">
                        <div class="form-check">
                            <input class="form-check-input bill-checkbox" type="checkbox" 
                                   value="${bill.id}" 
                                   data-amount="${billAmountDue}"
                                   data-total="${bill.total}">
                            <label class="form-check-label w-100">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1">
                                        <div class="d-flex align-items-center gap-2 mb-1">
                                            <strong>Reading Date:</strong> ${bill.reading_date}
                                            ${overdueWarning}
                                        </div>
                                        <small class="text-muted d-block">
                                            Due: ${bill.due_date} | 
                                            Reading: ${bill.reading} (Previous: ${bill.previous}) |
                                            Consumption: ${bill.consumption}
                                        </small>
                                        ${appliedFeesInfo}
                                        ${lateFeeWarning}
                                    </div>
                                    <div class="text-end">
                                        <strong class="text-primary">₱${parseFloat(bill.total).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong>
                                        <br>
                                        <small class="text-muted">Amount Due: ₱${billAmountDue.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</small>
                                        ${hasLateFee && !bill.late_fee_info.already_applied ? 
                                            `<br><small class="text-success"><i class="fas fa-plus"></i> +₱${parseFloat(bill.late_fee_info.fee_amount).toFixed(2)} late fee</small>` 
                                            : ''}
                                    </div>
                                </div>
                            </label>
                        </div>
                    </div>
                `;
            }).join('');

            // Store all bills for later use
            allBills = bills;
            
            // Show Select All button
            selectAllBillsBtn.style.display = 'block';
            
            // Add event listeners to checkboxes
            document.querySelectorAll('.bill-checkbox').forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    if (this.checked) {
                        selectedBills.add(this.value);
                    } else {
                        selectedBills.delete(this.value);
                    }
                    updateSelectedBillsSummary();
                    updateAmountToPay();
                    
                    // Update Select All button text
                    const allCheckboxes = document.querySelectorAll('.bill-checkbox');
                    const allSelected = Array.from(allCheckboxes).every(cb => cb.checked);
                    selectAllBillsBtn.innerHTML = allSelected ? 
                        '<i class="fas fa-square me-1"></i>Deselect All' : 
                        '<i class="fas fa-check-square me-1"></i>Select All';
                });
            });
            
            // Auto-select all bills by default
            document.querySelectorAll('.bill-checkbox').forEach(cb => {
                cb.checked = true;
                selectedBills.add(cb.value);
            });
            updateSelectedBillsSummary();
            updateAmountToPay();
            selectAllBillsBtn.innerHTML = '<i class="fas fa-square me-1"></i>Deselect All';

            // Show total unpaid balance
            const totalBalanceDiv = document.createElement('div');
            totalBalanceDiv.className = 'alert alert-info mt-3';
            totalBalanceDiv.innerHTML = `
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <strong>Total Unpaid Balance:</strong> ₱${totalUnpaidBalance.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}
                        <br>
                        <small class="text-muted">${bills.length} unpaid bill(s)</small>
                    </div>
                    <div class="text-end">
                        ${bills.some(bill => bill.is_overdue) ? 
                            '<span class="badge bg-warning">Some bills are overdue</span>' : 
                            '<span class="badge bg-success">All bills current</span>'
                        }
                    </div>
                </div>
            `;
            billsList.insertAdjacentElement('beforebegin', totalBalanceDiv);
            
            console.log('✅ Bills loaded successfully');
            
        } catch (error) {
            console.error('❌ Error loading bills:', error);
            billsList.innerHTML = `
                <div class="alert alert-danger text-center">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Error loading bills:</strong> ${error.message}
                    <br>
                    <small>Please try selecting the client again or contact support.</small>
                </div>
            `;
            
            // Reset form values on error
            totalAmountInput.value = '0.00';
            amountToPayInput.value = '0.00';
            remainingBalanceInput.value = '0.00';
        }
    }

    function resetPaymentForm() {
        billsList.innerHTML = '';
        totalAmountInput.value = '0.00';
        amountToPayInput.value = '0.00';
        remainingBalanceInput.value = '0.00';
        document.getElementById('referenceNumber').value = '';
        selectedBills.clear();
        allBills = [];
        selectAllBillsBtn.style.display = 'none';
        selectedBillsSummary.style.display = 'none';
        clientSearch.value = '';
    }

    // Handle form submission
    const paymentForm = document.getElementById('paymentForm');
    paymentForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        // Remove any existing error messages
        const existingError = this.querySelector('.alert-danger');
        if (existingError) {
            existingError.remove();
        }

        // Validate amount to pay
        const amountToPay = parseFloat(amountToPayInput.value);
        const totalDue = parseFloat(totalAmountInput.value);
        if (amountToPay <= 0 || amountToPay > totalDue) {
            showError('Please enter a valid amount to pay');
            return;
        }

        // Validate selected bills
        const selectedBillIds = Array.from(selectedBills);
        if (selectedBillIds.length === 0) {
            showError('Please select at least one bill to pay');
            return;
        }

        // Show loading state
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';

        try {
            // Create form data
            const formData = new FormData(this);
            formData.append('bill_ids', JSON.stringify(selectedBillIds));

            // Make the request
            const response = await fetch('process_payment.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json'
                },
                credentials: 'same-origin'
            });

            // Check if response is JSON
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                throw new Error('Server returned non-JSON response. Please try again.');
            }

            // Get the response data
            const result = await response.json();
            
            // Handle the response
            if (result.success) {
                // Show success message before redirect
                const successDiv = document.createElement('div');
                
                if (result.late_fees_applied) {
                    // Show detailed success message with late fee information
                    successDiv.className = 'alert alert-warning mt-3';
                    successDiv.innerHTML = `
                        <div class="d-flex align-items-start">
                            <i class="fas fa-check-circle me-2 mt-1"></i>
                            <div>
                                <strong>Payment Processed Successfully!</strong>
                                <br>
                                <small class="text-muted">
                                    Late payment fees totaling ₱${parseFloat(result.total_late_fees).toFixed(2)} were automatically applied.
                                    ${result.late_fees.map(fee => 
                                        `<br>• Bill ${fee.bill_id}: ₱${parseFloat(fee.fee_amount).toFixed(2)} (${fee.days_late} days late)`
                                    ).join('')}
                                </small>
                                <br>
                                <span class="text-muted">Redirecting...</span>
                            </div>
                        </div>
                    `;
                } else {
                    // Show standard success message
                    successDiv.className = 'alert alert-success mt-3';
                    successDiv.innerHTML = `
                        <i class="fas fa-check-circle me-2"></i>
                        Payment processed successfully! Redirecting...
                    `;
                }
                
                paymentForm.insertBefore(successDiv, paymentForm.firstChild);
                
                // Redirect after a longer delay if late fees were applied (to give time to read)
                const redirectDelay = result.late_fees_applied ? 3000 : 1500;
                setTimeout(() => {
                    window.location.href = `payments.php?payment_id=${result.payment_id}`;
                }, redirectDelay);
            } else {
                throw new Error(result.message || 'Failed to process payment');
            }
        } catch (error) {
            console.error('Payment Error:', error);
            
            // Handle session errors
            if (error.message.includes('log in') || error.message.includes('session')) {
                showError('Your session has expired. Please refresh the page and try again.');
                setTimeout(() => window.location.reload(), 2000);
                return;
            }
            
            // Show the error message
            showError(error.message || 'An unexpected error occurred. Please try again.');
        } finally {
            // Reset button state if not redirecting
            if (!submitBtn.disabled) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
        }
    });

    // Helper function to show errors with improved styling
    function showError(message) {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'alert alert-danger mt-3';
        errorDiv.innerHTML = `
            <div class="d-flex align-items-center">
                <i class="fas fa-exclamation-circle me-2"></i>
                <div>
                    <strong>Error:</strong> ${message}
                    ${message.includes('session') ? '<br><small class="text-muted">The page will refresh automatically...</small>' : ''}
                </div>
            </div>
        `;
        
        // Add the error message at the top of the form
        paymentForm.insertBefore(errorDiv, paymentForm.firstChild);
        
        // Scroll error into view
        errorDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function generateReference() {
        fetch('generate_reference.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'generate=1'
        })
        .then(response => response.json())
        .then(data => {
            document.getElementById('referenceNumber').value = data.reference;
        })
        .catch(error => {
            console.error('Error:', error);
            showError('Error generating reference number');
        });
    }

    function viewPayment(id) {
        fetch(`get_payment_details.php?id=${id}`)
            .then(response => response.json())
            .then(payment => {
                // Set customer information
                document.getElementById('customerInitials').textContent = payment.firstname.charAt(0) + payment.lastname.charAt(0);
                document.getElementById('customerName').textContent = payment.firstname + ' ' + payment.lastname;
                document.getElementById('meterCode').textContent = 'Meter Code: ' + payment.meter_code;
                
                // Set payment details
                document.getElementById('referenceNumberView').textContent = payment.reference_number;
                document.getElementById('amountView').textContent = '₱' + payment.amount;
                document.getElementById('paymentDateView').textContent = payment.formatted_payment_date;
                document.getElementById('paymentMethodView').textContent = payment.payment_method;
                
                // Set bill details
                document.getElementById('readingDateView').textContent = payment.reading_date;
                document.getElementById('readingView').textContent = payment.reading;
                document.getElementById('previousView').textContent = payment.previous;
                document.getElementById('billTotalView').textContent = '₱' + payment.bill_total;
                
                // Set status
                const statusBadge = document.getElementById('statusBadgeView');
                statusBadge.textContent = payment.status_text || (payment.status == 1 ? 'Fully Paid' : 'Partial Payment');
                const statusClass = (payment.status_text === 'Fully Paid' || payment.status == 1) ? 'status-fully-paid' : 'status-partial-payment';
                statusBadge.className = 'status-badge ' + statusClass;
                
                // Show verified date if payment is verified
                const verifiedDateContainer = document.getElementById('verifiedDateContainer');
                if (payment.status == 1 && payment.formatted_verified_date) {
                    document.getElementById('verifiedDateView').textContent = payment.formatted_verified_date;
                    verifiedDateContainer.style.display = 'block';
                } else {
                    verifiedDateContainer.style.display = 'none';
                }
                
                // Show/hide pending actions based on status
                const pendingActions = document.querySelector('.pending-actions');
                if (payment.status == 0) {
                    pendingActions.style.display = 'block';
                    document.getElementById('paymentIdView').value = payment.id;
                } else {
                    pendingActions.style.display = 'none';
                }
                
                // Show the modal
                const viewModal = new bootstrap.Modal(document.getElementById('viewPaymentModal'));
                viewModal.show();
            })
            .catch(error => {
                console.error('Error:', error);
                showError('Error loading payment details');
            });
    }

    function deletePaymentWithPassword(id) {
        // Show password verification modal
        const passwordModalId = 'deletePasswordModal';
        let passwordModalElement = document.getElementById(passwordModalId);
        
        if (passwordModalElement) {
            passwordModalElement.remove();
        }
        
        const passwordModalHtml = `
            <div class="modal fade" id="${passwordModalId}" tabindex="-1" aria-labelledby="${passwordModalId}Label" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header bg-danger text-white">
                            <h5 class="modal-title" id="${passwordModalId}Label">
                                <i class="fas fa-lock me-2"></i>Verify Delete Password
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Warning:</strong> This action cannot be undone. Please enter the delete password to confirm.
                            </div>
                            <div class="mb-3">
                                <label for="deletePasswordInput" class="form-label">Delete Password</label>
                                <input type="password" class="form-control" id="deletePasswordInput" placeholder="Enter delete password" autofocus>
                                <div id="passwordError" class="text-danger mt-2" style="display: none;"></div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-danger" id="confirmDeleteWithPassword" data-payment-id="${id}">
                                <i class="fas fa-trash me-2"></i>Delete Payment
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', passwordModalHtml);
        passwordModalElement = document.getElementById(passwordModalId);
        const passwordModal = new bootstrap.Modal(passwordModalElement);
        
        // Handle password input Enter key
        document.getElementById('deletePasswordInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                document.getElementById('confirmDeleteWithPassword').click();
            }
        });
        
        // Handle confirm delete button
        document.getElementById('confirmDeleteWithPassword').addEventListener('click', function() {
            const password = document.getElementById('deletePasswordInput').value;
            const paymentId = this.getAttribute('data-payment-id');
            const errorDiv = document.getElementById('passwordError');
            
            if (!password) {
                errorDiv.textContent = 'Password is required';
                errorDiv.style.display = 'block';
                return;
            }
            
            // Verify password
            fetch('verify_delete_password.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ password: password })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    passwordModal.hide();
                    // Now proceed with deletion
                    if (typeof showConfirm !== 'undefined') {
                        showConfirm('Are you sure you want to delete this payment? This action cannot be undone.', function() {
                            deletePayment(paymentId);
                        });
                    } else {
                        if (confirm('Are you sure you want to delete this payment? This action cannot be undone.')) {
                            deletePayment(paymentId);
                        }
                    }
                } else {
                    errorDiv.textContent = data.message || 'Incorrect password';
                    errorDiv.style.display = 'block';
                    document.getElementById('deletePasswordInput').value = '';
                    document.getElementById('deletePasswordInput').focus();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                errorDiv.textContent = 'Error verifying password';
                errorDiv.style.display = 'block';
            });
        });
        
        passwordModalElement.addEventListener('hidden.bs.modal', function() {
            passwordModalElement.remove();
        }, { once: true });
        
        passwordModal.show();
        document.getElementById('deletePasswordInput').focus();
    }

    function deletePaymentFromModal() {
        const paymentId = document.getElementById('paymentIdView').value;
        deletePaymentWithPassword(paymentId);
    }

    function deletePayment(id) {
        fetch('delete_payment.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `id=${id}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showSuccess('Payment deleted successfully!');
                setTimeout(() => location.reload(), 1000);
            } else {
                showError('Error deleting payment: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showError('Error deleting payment');
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
</html> 