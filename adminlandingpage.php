<?php
// Enable error display for debugging (but suppress vendor deprecation warnings)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL & ~E_DEPRECATED); // Suppress deprecation warnings from vendor libraries

// Start session FIRST before any output
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: adminlogin.php");
    exit();
}

include 'db.php';
include 'dashboard_data.php';

$dashboard = new DashboardData($conn);
$total_clients = $dashboard->getTotalClients();
$current_month_revenue = $dashboard->getCurrentMonthRevenue();
$pending_payments = $dashboard->getPendingPayments();
$average_bill = $dashboard->getAverageBill();
$payment_status = $dashboard->getPaymentStatusData();
$recent_transactions = $dashboard->getRecentTransactions();
$payment_predictions = $dashboard->getPaymentPredictions();
$clients_result = $conn->query("SELECT id, firstname, lastname FROM client_list WHERE delete_flag = 0 AND status = 1 ORDER BY lastname, firstname");
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Billing Dashboard - Water Billing System</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />

    <!-- Google Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" />
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <style>
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
    }

    /* Prevent logo from being affected by dark mode filters */
    .sidebar-header img {
        filter: none !important;
        opacity: 1 !important;
    }

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

    .card-soft {
        border-radius: 15px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        border: none;
        background-color: var(--card-bg);
        color: var(--card-text);
    }

    .stat-card {
        background: linear-gradient(45deg, #4e73df 0%, #224abe 100%);
        color: white;
    }

    .stat-icon {
        font-size: 2rem;
        opacity: 0.8;
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

    .avatar-sm {
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
    }

    .chart-container {
        position: relative;
        height: 400px;  /* Increased height */
        margin: 20px 0;
    }

    .analytics-card {
        background: var(--card-bg);
        border-radius: 15px;
        padding: 20px;
        margin-bottom: 20px;
    }

    .prediction-item {
        padding: 15px;
        border-radius: 10px;
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: var(--hover-bg);
        color: var(--text-color);
    }

    .prediction-item .text-muted {
        color: var(--muted-text) !important;
        opacity: 0.8;
    }

    .prediction-icon {
        font-size: 1.5rem;
        margin-right: 15px;
    }

    .prediction-trend {
        display: flex;
        align-items: center;
    }

    .trend-up {
        color: #1cc88a;
    }

    .trend-down {
        color: #e74a3b;
    }

    .prediction-value {
        font-size: 1.25rem;
        font-weight: 600;
        color: var(--text-color);
    }

    .report-stats {
        background-color: var(--card-bg);
        border-radius: 15px;
        padding: 20px;
        margin-bottom: 20px;
        border: 1px solid var(--border-color);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    }

    .stat-box {
        background: var(--sidebar-bg);
        border-radius: 10px;
        padding: 15px;
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 10px;
        border: 1px solid transparent;
        transition: all 0.3s ease;
    }
    
    .stat-box:hover {
        border-color: var(--border-color);
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }

    .stat-icon {
        width: 45px;
        height: 45px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        color: white;
    }

    .stat-info h3 {
        margin: 0;
        font-size: 1.5rem;
        color: var(--text-color);
    }

    .stat-info p {
        margin: 0;
        color: var(--muted-text);
        font-size: 0.9rem;
    }

    .total-reports .stat-icon { background: #36b9cc; }
    .resolved-reports .stat-icon { background: #1cc88a; }
    .pending-reports .stat-icon { background: #f6c23e; }
    .avg-time .stat-icon { background: #4e73df; }
    
    /* Make borders more subtle */
    .report-stats {
        border-color: rgba(0, 0, 0, 0.08) !important;
    }
    
    [data-theme="dark"] .report-stats {
        border-color: rgba(255, 255, 255, 0.1) !important;
    }

    /* Add these modal styles to your existing CSS */
    .modal-content {
        background-color: var(--card-bg);
        color: var(--text-color);
        border: 1px solid var(--border-color);
    }

    .modal-header {
        background-color: var(--sidebar-bg);
        border-bottom: 1px solid var(--border-color);
        color: var(--text-color);
    }

    .modal-footer {
        background-color: var(--sidebar-bg);
        border-top: 1px solid var(--border-color);
    }

    .modal .text-muted {
        color: var(--muted-text) !important;
    }

    .modal .form-control, .modal .form-select {
        background-color: var(--sidebar-bg);
        border-color: var(--border-color);
        color: var(--text-color);
    }

    .modal .form-control:focus, .modal .form-select:focus {
        background-color: var(--sidebar-bg);
        border-color: var(--hover-text);
        color: var(--text-color);
        box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
    }

    .modal .btn-close {
        filter: invert(1);
    }

    [data-theme="light"] .modal .btn-close {
        filter: none;
    }

    .modal label {
        color: var(--text-color);
    }

    .modal-title {
        color: var(--text-color);
    }

    .modal strong {
        color: var(--text-color);
    }

    .modal p {
        color: var(--text-color);
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

    /* Add this to ensure text is visible in both light and dark mode for notice history */
    .text-body {
        color: var(--text-color) !important;
    }
    .fw-semibold {
        font-weight: 600;
    }

    @media (max-width: 767.98px) {
        .card-soft,
        .report-stats,
        .main-content .row > [class^='col-'],
        .main-content .table-responsive {
            margin-bottom: 0.75rem !important;
        }
        .main-content {
            padding: 8px !important;
        }
        .card-body,
        .card-header {
            padding: 0.75rem !important;
        }
        .mb-4 {
            margin-bottom: 0.75rem !important;
        }
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
        <a href="adminlandingpage.php" class="active">
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
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">Billing Dashboard</h2>
        <div>
            <button class="btn btn-primary me-2" onclick="window.location.href='billing_list.php?action=new'"><i class="fas fa-plus me-2"></i>New Bill</button>
            <button class="btn btn-outline-primary" onclick="exportReport()"><i class="fas fa-download me-2"></i>Export Report</button>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card card-soft stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-white-50">Total Revenue</h6>
                            <h3 class="mb-0">₱<?php echo number_format($current_month_revenue, 2); ?></h3>
                            <small class="text-white-50">This Month</small>
                        </div>
                        <i class="fas fa-dollar-sign stat-icon"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-soft stat-card" style="background: linear-gradient(45deg, #1cc88a 0%, #13855c 100%);">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-white-50">Active Customers</h6>
                            <h3 class="mb-0"><?php echo $total_clients; ?></h3>
                            <small class="text-white-50">Total Accounts</small>
                        </div>
                        <i class="fas fa-users stat-icon"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-soft stat-card" style="background: linear-gradient(45deg, #f6c23e 0%, #dda20a 100%);">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-white-50">Pending Payments</h6>
                            <h3 class="mb-0"><?php echo $pending_payments; ?></h3>
                            <small class="text-white-50">Needs Action</small>
                        </div>
                        <i class="fas fa-clock stat-icon"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row mb-4">
        <div class="col-md-8">
            <div class="card card-soft">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="card-title">Revenue Forecasting (Actual vs Forecasted)</h5>
                        <div class="d-flex align-items-center gap-2">
                            <div class="btn-group me-2">
                            <button class="btn btn-sm btn-outline-primary active" data-period="monthly">Monthly</button>
                            <button class="btn btn-sm btn-outline-primary" data-period="quarterly">Quarterly</button>
                            <button class="btn btn-sm btn-outline-primary" data-period="yearly">Yearly</button>
                            </div>
                                    <span class="badge bg-primary px-3 py-2">Linear Regression</span>
                                    <select id="forecastHorizon" class="form-select form-select-sm" style="width: auto;">
                                        <option value="6" selected>6 months</option>
                                        <option value="12">12 months</option>
                                    </select>
                                </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="revenueChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-soft">
                <div class="card-body">
                    <h5 class="card-title">Payment Status</h5>
                    <?php 
                    $total_bills = $payment_status['total_bills'];
                    if ($total_bills > 0):
                        $paid_percentage = $payment_status['paid_percentage'];
                        $pending_percentage = $payment_status['pending_percentage'];
                        $overdue_percentage = $payment_status['overdue_percentage'];
                    ?>
                    <div style="margin-bottom: 20px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                            <div>
                                <span style="font-weight: 600; color: var(--text-color);">Payment Status Overview</span>
                            </div>
                            <div style="font-size: 0.9rem; color: var(--muted-text);">
                                Total: <?php echo $total_bills; ?> bills
                            </div>
                        </div>
                        
                        <!-- Bar Graph -->
                        <div style="background: #f5f5f5; border-radius: 8px; overflow: hidden; height: 50px; position: relative; margin-bottom: 15px;">
                            <?php if ($payment_status['paid'] > 0): ?>
                            <div style="background: linear-gradient(90deg, #1cc88a, #13855c); height: 100%; width: <?php echo $paid_percentage; ?>%; display: inline-block; position: absolute; left: 0; top: 0; transition: width 0.3s ease;">
                                <div style="display: flex; align-items: center; justify-content: center; height: 100%; color: white; font-weight: 600; font-size: 0.85rem;">
                                    <?php if ($paid_percentage >= 10): ?>
                                        Paid (<?php echo $payment_status['paid']; ?>)
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($payment_status['pending'] > 0): ?>
                            <div style="background: linear-gradient(90deg, #f6c23e, #dda20a); height: 100%; width: <?php echo $pending_percentage; ?>%; display: inline-block; position: absolute; left: <?php echo $paid_percentage; ?>%; top: 0; transition: width 0.3s ease;">
                                <div style="display: flex; align-items: center; justify-content: center; height: 100%; color: white; font-weight: 600; font-size: 0.85rem;">
                                    <?php if ($pending_percentage >= 10): ?>
                                        Pending (<?php echo $payment_status['pending']; ?>)
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($payment_status['overdue'] > 0): ?>
                            <div style="background: linear-gradient(90deg, #e74a3b, #c0392b); height: 100%; width: <?php echo $overdue_percentage; ?>%; display: inline-block; position: absolute; left: <?php echo ($paid_percentage + $pending_percentage); ?>%; top: 0; transition: width 0.3s ease;">
                                <div style="display: flex; align-items: center; justify-content: center; height: 100%; color: white; font-weight: 600; font-size: 0.85rem;">
                                    <?php if ($overdue_percentage >= 10): ?>
                                        Overdue (<?php echo $payment_status['overdue']; ?>)
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Legend -->
                        <div style="display: flex; flex-direction: column; gap: 10px; font-size: 0.9rem;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div style="width: 16px; height: 16px; background: linear-gradient(90deg, #1cc88a, #13855c); border-radius: 3px;"></div>
                                <span style="color: var(--text-color);">Paid: <?php echo $payment_status['paid']; ?> (<?php echo $paid_percentage; ?>%)</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div style="width: 16px; height: 16px; background: linear-gradient(90deg, #f6c23e, #dda20a); border-radius: 3px;"></div>
                                <span style="color: var(--text-color);">Pending: <?php echo $payment_status['pending']; ?> (<?php echo $pending_percentage; ?>%)</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div style="width: 16px; height: 16px; background: linear-gradient(90deg, #e74a3b, #c0392b); border-radius: 3px;"></div>
                                <span style="color: var(--text-color);">Overdue: <?php echo $payment_status['overdue']; ?> (<?php echo $overdue_percentage; ?>%)</span>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: var(--muted-text);">
                        <i class="fas fa-info-circle" style="font-size: 48px; color: #ccc; margin-bottom: 15px;"></i>
                        <p style="margin: 0;">No payment data available</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Transactions -->
    <div class="card card-soft mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h5 class="card-title mb-0">Recent Transactions</h5>
                <button class="btn btn-sm btn-outline-primary" onclick="window.location.href='billing_list.php'">View All</button>
            </div>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th scope="col">Invoice ID</th>
                            <th scope="col">Customer</th>
                            <th scope="col">Amount</th>
                            <th scope="col">Status</th>
                            <th scope="col">Due Date</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_transactions as $transaction): ?>
                        <tr>
                            <td>#<?php echo $transaction['id']; ?></td>
                            <td><?php echo $transaction['firstname'] . ' ' . $transaction['lastname']; ?></td>
                            <td>₱<?php echo number_format($transaction['total'], 2); ?></td>
                            <td>
                                <?php if ($transaction['status'] == 1): ?>
                                    <span class="badge bg-success">Paid</span>
                                <?php elseif (strtotime($transaction['due_date']) < time()): ?>
                                    <span class="badge bg-danger">Overdue</span>
                                <?php else: ?>
                                    <span class="badge bg-warning">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($transaction['due_date'])); ?></td>
                            <td>
                                <a href="javascript:void(0)" class="btn btn-sm btn-outline-primary me-1" onclick="viewBill(<?php echo $transaction['id']; ?>)">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Reports Status -->
    <div class="report-stats">
        <div class="d-flex justify-content-center align-items-center mb-4">
            <h5 class="card-title mb-0">Client Reports Status</h5>
        </div>
        <div class="row justify-content-center">
            <?php 
            $reports_status = $dashboard->getReportsStatus();
            ?>
            <div class="col-md-3">
                <div class="stat-box total-reports">
                    <div class="stat-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $reports_status['total_reports']; ?></h3>
                        <p>Total Reports</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-box resolved-reports">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $reports_status['resolved_reports']; ?></h3>
                        <p>Resolved</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-box pending-reports">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $reports_status['pending_reports']; ?></h3>
                        <p>Pending</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Reports -->
    <div class="card card-soft mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Recent Reports</h5>
            <a href="client_reports.php" class="btn btn-sm btn-outline-primary">View All</a>
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
                        <?php
                        $recent_reports = $conn->query("
                            SELECT o.*, cl.firstname, cl.lastname 
                            FROM outage_reports o
                            JOIN client_list cl ON o.client_id = cl.id
                            ORDER BY o.created_at DESC
                            LIMIT 5
                        ");
                        
                        if ($recent_reports->num_rows > 0) {
                            while ($report = $recent_reports->fetch_assoc()) {
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($report['firstname'] . ' ' . $report['lastname']); ?></td>
                                    <td><?php echo "Water Outage"; ?></td>
                                    <td><?php echo htmlspecialchars(substr($report['description'], 0, 50)) . '...'; ?></td>
                                    <td>
                                        <span class="badge <?php echo $report['status'] ? 'bg-success' : 'bg-warning'; ?>">
                                            <?php echo $report['status'] ? 'Resolved' : 'Pending'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y H:i', strtotime($report['created_at'])); ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-primary" 
                                                onclick="viewReportModal(<?php echo $report['id']; ?>, 
                                                                       '<?php echo htmlspecialchars($report['firstname'] . ' ' . $report['lastname'], ENT_QUOTES); ?>', 
                                                                       '<?php echo htmlspecialchars($report['description'], ENT_QUOTES); ?>', 
                                                                       '<?php echo htmlspecialchars($report['location'], ENT_QUOTES); ?>', 
                                                                       '<?php echo $report['status']; ?>', 
                                                                       '<?php echo htmlspecialchars($report['created_at'], ENT_QUOTES); ?>', 
                                                                       '<?php echo htmlspecialchars($report['resolution_notes'] ?? '', ENT_QUOTES); ?>')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php
                            }
                        } else {
                            echo '<tr><td colspan="6" class="text-center">No reports found.</td></tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Predictive Analytics Section -->
    <div class="row">
        <div class="col-md-6">
            <div class="card card-soft">
                <div class="card-body">
                    <h5 class="card-title">Payment Predictions</h5>
                    <div class="prediction-item">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-arrow-up prediction-icon trend-up"></i>
                            <div>
                                <div class="text-muted">Expected Payments (Next Month)</div>
                                <div class="prediction-value">₱<?php echo number_format($payment_predictions['expected_payment'], 2); ?></div>
                            </div>
                        </div>
                        <div class="prediction-trend">
                            <i class="fas fa-arrow-<?php echo $payment_predictions['payment_trend'] >= 0 ? 'up' : 'down'; ?> me-2 trend-<?php echo $payment_predictions['payment_trend'] >= 0 ? 'up' : 'down'; ?>"></i>
                            <span><?php echo abs($payment_predictions['payment_trend']); ?>%</span>
                        </div>
                    </div>
                    <div class="prediction-item">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-exclamation-triangle prediction-icon text-warning"></i>
                            <div>
                                <div class="text-muted">Predicted Late Payments</div>
                                <div class="prediction-value"><?php echo $payment_predictions['predicted_late']; ?> Bills</div>
                            </div>
                        </div>
                        <div class="prediction-trend text-warning">
                            <span><?php echo $payment_predictions['predicted_late'] > 5 ? 'High Risk' : ($payment_predictions['predicted_late'] > 2 ? 'Medium Risk' : 'Low Risk'); ?></span>
                        </div>
                    </div>
                    <div class="prediction-item">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-clock prediction-icon text-info"></i>
                            <div>
                                <div class="text-muted">Average Payment Delay</div>
                                <div class="prediction-value"><?php echo $payment_predictions['avg_delay']; ?> Days</div>
                            </div>
                        </div>
                        <div class="prediction-trend">
                            <i class="fas fa-arrow-<?php echo $payment_predictions['avg_delay'] <= 5 ? 'down' : 'up'; ?> me-2 trend-<?php echo $payment_predictions['avg_delay'] <= 5 ? 'down' : 'up'; ?>"></i>
                            <span><?php echo $payment_predictions['avg_delay'] <= 5 ? 'Good' : 'Needs Improvement'; ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
    </div>

    <!-- Notices History Card -->
    <div class="card card-soft mb-4" style="margin-top: 2rem;">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-bell text-primary me-2"></i>Notices History
            </h5>
            <a href="manage_notices.php" class="btn btn-sm btn-outline-primary">
                <i class="fas fa-list me-1"></i>View All
            </a>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Title</th>
                            <th>Type</th>
                            <th>Affected Areas</th>
                            <th>Duration</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Get recent notices
                        $notices_query = "
                            SELECT n.*, a.username as admin_name
                            FROM notices n
                            JOIN admin a ON n.created_by = a.id
                            ORDER BY n.start_date DESC
                            LIMIT 5";
                        $notices = $conn->query($notices_query);

                        if ($notices && $notices->num_rows > 0):
                            while ($notice = $notices->fetch_assoc()):
                                // Determine status class
                                $status_class = '';
                                $status_icon = '';
                                switch($notice['status']) {
                                    case 'ongoing':
                                        $status_class = 'bg-warning';
                                        $status_icon = 'fa-clock';
                                        break;
                                    case 'scheduled':
                                        $status_class = 'bg-info';
                                        $status_icon = 'fa-calendar';
                                        break;
                                    case 'completed':
                                        $status_class = 'bg-success';
                                        $status_icon = 'fa-check-circle';
                                        break;
                                }

                                // Determine type class and icon
                                $type_class = '';
                                $type_icon = '';
                                switch($notice['type']) {
                                    case 'interruption':
                                        $type_class = 'text-danger';
                                        $type_icon = 'fa-tint-slash';
                                        break;
                                    case 'maintenance':
                                        $type_class = 'text-warning';
                                        $type_icon = 'fa-wrench';
                                        break;
                                    case 'announcement':
                                        $type_class = 'text-info';
                                        $type_icon = 'fa-info-circle';
                                        break;
                                }
                        ?>
                        <tr>
                            <td>
                                <i class="far fa-calendar-alt me-2"></i>
                                <span class="text-body"><?php echo date('M d, Y', strtotime($notice['start_date'])); ?></span>
                            </td>
                            <td>
                                <span class="fw-semibold text-body">
                                    <?php echo htmlspecialchars($notice['title']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="<?php echo $type_class; ?>">
                                    <i class="fas <?php echo $type_icon; ?> me-1"></i>
                                    <?php echo ucfirst($notice['type']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="text-body">
                                    <?php 
                                    $areas = htmlspecialchars($notice['affected_areas']);
                                    echo (strlen($areas) > 30) ? substr($areas, 0, 30) . '...' : $areas;
                                    ?>
                                </span>
                            </td>
                            <td>
                                <small class="text-body">
                                    <?php 
                                    echo date('M d, h:i A', strtotime($notice['start_date']));
                                    if ($notice['end_date']) {
                                        echo '<br>to<br>' . date('M d, h:i A', strtotime($notice['end_date']));
                                    }
                                    ?>
                                </small>
                            </td>
                            <td>
                                <span class="badge <?php echo $status_class; ?>">
                                    <i class="fas <?php echo $status_icon; ?> me-1"></i>
                                    <?php echo ucfirst($notice['status']); ?>
                                </span>
                            </td>
                        </tr>
                        <?php 
                            endwhile;
                        else:
                        ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted">
                                <i class="fas fa-info-circle me-2"></i>No notices found
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Report View Modal -->
<div class="modal fade" id="reportModal" tabindex="-1" aria-labelledby="reportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="reportModalLabel">Report Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h6 class="text-muted">Client Information</h6>
                        <p><strong>Name:</strong> <span id="modalClientName"></span></p>
                        <p><strong>Location:</strong> <span id="modalLocation"></span></p>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-muted">Report Information</h6>
                        <p><strong>Submitted:</strong> <span id="modalSubmitDate"></span></p>
                        <p><strong>Status:</strong> <span id="modalStatus"></span></p>
                    </div>
                </div>

                <div class="mb-4">
                    <h6 class="text-muted">Description</h6>
                    <p id="modalDescription"></p>
                </div>

                <div id="resolutionNotesSection" class="mb-4" style="display: none;">
                    <h6 class="text-muted">Resolution Notes</h6>
                    <p id="modalResolutionNotes"></p>
                </div>

                <form id="updateReportForm">
                    <input type="hidden" id="modalReportId" name="report_id">
                    <div class="mb-3">
                        <label class="form-label">Update Status</label>
                        <select name="status" id="modalStatusSelect" class="form-select">
                            <option value="0">Pending</option>
                            <option value="1">Resolved</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Resolution Notes</label>
                        <textarea name="resolution_notes" id="modalResolutionInput" class="form-control" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="updateReport()">Update Report</button>
            </div>
        </div>
    </div>
</div>

<!-- View Notice Modal -->
<div class="modal fade" id="viewNoticeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Notice Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="noticeDetails">
                    <!-- Notice details will be loaded here -->
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let revenueChart;
let paymentStatusChart;

// Initialize charts
$(document).ready(function() {
    // Wait for Chart.js to be fully loaded
    if (typeof Chart === 'undefined') {
        console.error('Chart.js not loaded! Waiting for it...');
        // Wait a bit and try again
        setTimeout(function() {
            if (typeof Chart === 'undefined') {
                console.error('Chart.js still not loaded after timeout!');
                showError('Chart.js library failed to load. Please refresh the page.');
                return;
            }
            updateRevenueChart('monthly');
        }, 1000);
    } else {
        // Initialize Revenue Chart
        updateRevenueChart('monthly');
    }
    
    // Payment Status Chart removed - replaced with bar graph in HTML


    
    // Handle revenue period buttons
    $('.btn-group button').click(function() {
        $('.btn-group button').removeClass('active');
        $(this).addClass('active');
        updateRevenueChart($(this).data('period'));
    });

                // Handle forecast horizon change
                $('#forecastHorizon').on('change', function(){
                    const period = $('.btn-group button.active').data('period') || 'monthly';
                    updateRevenueChart(period);
                });

    // Auto-refresh dashboard data every 5 minutes
    setInterval(refreshDashboardData, 300000);
});

function updateRevenueChart(period) {
    if (revenueChart) {
        revenueChart.destroy();
    }

    const horizon = parseInt(document.getElementById('forecastHorizon')?.value || '6');
    const forecastMethod = 'linear'; // Always use Linear Regression

    // Fetch paid revenue forecast using Linear Regression
    console.log('Fetching revenue forecast - Period:', period, 'Horizon:', horizon, 'Method: Linear Regression');
    $.ajax({
        url: 'dashboard_data.php',
        method: 'GET',
        data: { action: 'revenue_forecast', period: period, forecast_method: forecastMethod, forecast_months: horizon },
        dataType: 'json'
    })
    .done(function(paidData) {
        console.log('✅ Revenue forecast data received:', paidData);
        console.log('📊 Response structure:', {
            hasActual: !!paidData.actual,
            hasForecast: !!paidData.forecast,
            actualLength: paidData.actual ? paidData.actual.length : 0,
            forecastLength: paidData.forecast ? paidData.forecast.length : 0
        });
        
        const actual = paidData.actual || [];
        const forecast = paidData.forecast || [];
        
        console.log('🔍 After assignment - Actual:', actual.length, 'Forecast:', forecast.length);
        
        // If we have actual data, always try to show the chart (even without forecast)
        if (actual.length === 0) {
            console.error('❌ No actual revenue data found!');
            loadActualDataOnly(period);
            return;
        }
        
        // If no forecast but have actual, show chart with actual only  
        if (forecast.length === 0) {
            console.warn('⚠️ No forecast data, but we have actual data. Showing actual only.');
            // Don't call loadActualDataOnly, just use the actual data we already have
            renderChartWithActualOnly(actual, period);
            return;
        }
        
        console.log('✅ Both actual and forecast data available. Rendering full chart.');

        const actualLabels = actual.map(i => i.period);
        const actualValues = actual.map(i => parseFloat(i.revenue) || 0);
        const forecastLabels = forecast.map(i => i.period);
        const forecastValues = forecast.map(i => parseFloat(i.revenue) || 0);

        // Combine all labels and create a map for data alignment
        const allLabelsSet = new Set([...actualLabels, ...forecastLabels]);
        const allLabels = Array.from(allLabelsSet).sort();
        
        // Create maps for quick lookup
        const paidActualMap = {};
        actualLabels.forEach((label, idx) => { paidActualMap[label] = actualValues[idx]; });
        const paidForecastMap = {};
        forecastLabels.forEach((label, idx) => { paidForecastMap[label] = forecastValues[idx]; });
        
        // Build aligned series
        const actualSeries = allLabels.map(label => paidActualMap[label] || null);
        const forecastSeries = allLabels.map(label => paidForecastMap[label] || null);
        
        // Find last actual value for paid to connect with forecast
        const lastActual = actualValues[actualValues.length - 1] || 0;
        const lastActualIndex = actualLabels.length > 0 ? allLabels.indexOf(actualLabels[actualLabels.length - 1]) : -1;
        if (lastActualIndex >= 0 && forecastValues.length > 0) {
            forecastSeries[lastActualIndex] = lastActual;
        }

        const datasets = [
            { label: 'Actual Revenue (Paid)', data: actualSeries, borderColor: '#4e73df', backgroundColor: 'rgba(78, 115, 223, 0.1)', tension: 0.3, fill: false, pointBackgroundColor: '#4e73df', pointBorderColor: '#4e73df', pointRadius: 4, spanGaps: false },
            { label: 'Forecasted Revenue (Linear Regression)', data: forecastSeries, borderColor: '#dc3545', backgroundColor: 'rgba(220, 53, 69, 0.1)', tension: 0.3, fill: false, pointBackgroundColor: '#dc3545', pointBorderColor: '#dc3545', pointRadius: 4, borderDash: [5,5], spanGaps: false }
        ];
        
        console.log('LINEAR REGRESSION forecast - Actual data points:', actual.length, 'Forecast data points:', forecast.length);
        console.log('All labels:', allLabels);
        console.log('Actual series (first 5):', actualSeries.slice(0, 5));
        console.log('Forecast series (first 5):', forecastSeries.slice(0, 5));

        // Check if Chart.js is loaded
        if (typeof Chart === 'undefined') {
            console.error('Chart.js is not loaded!');
            showError('Chart.js library is missing. Please refresh the page.');
            return;
        }

        // Check if canvas element exists
        const canvasElement = document.getElementById('revenueChart');
        if (!canvasElement) {
            console.error('Canvas element #revenueChart not found!');
            return;
        }

        // Destroy existing chart if it exists
        if (revenueChart) {
            revenueChart.destroy();
        }

        const revenueCtx = canvasElement.getContext('2d');
        if (!revenueCtx) {
            console.error('Could not get 2d context from canvas!');
            return;
        }

        try {
            revenueChart = new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: allLabels,
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: function(value){ return '₱' + value.toLocaleString(); } }, grid: { color: 'rgba(0,0,0,0.1)' } },
                    x: { grid: { color: 'rgba(0,0,0,0.1)' } }
                },
                plugins: {
                    legend: { display: true, position: 'top' },
                    tooltip: { 
                        filter: function(ti){ return ti.raw !== null; }, 
                        callbacks: { 
                            label: function(ctx){ 
                                const v = ctx.raw; 
                                if (v === null) return null;
                                return ctx.dataset.label + ': ₱' + Number(v).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2}); 
                            } 
                        } 
                    }
                },
                elements: {
                    line: {
                        spanGaps: true  // Allow lines to span gaps for better visualization
                    }
                }
            }
        });
        console.log('Chart created successfully');
        } catch (chartError) {
            console.error('Error creating chart:', chartError);
            console.error('Chart error details:', {
                message: chartError.message,
                stack: chartError.stack,
                labels: allLabels.length,
                actualSeriesLength: actualSeries.length,
                forecastSeriesLength: forecastSeries.length
            });
            // Show error message to user
            const chartContainer = document.querySelector('.chart-container');
            if (chartContainer) {
                chartContainer.innerHTML = '<div class="alert alert-danger">Error rendering chart: ' + chartError.message + '</div>';
            }
        }
    })
    .fail(function(xhr, status, error){
        console.error('Linear Regression forecast failed:', error);
        console.error('Response:', xhr.responseText);
        console.log('Attempting to load actual data only as fallback');
        $.ajax({
            url: 'dashboard_data.php',
            method: 'GET',
            data: { action: 'revenue_forecast', period: period, forecast_method: 'linear', forecast_months: horizon },
            dataType: 'json'
        })
        .done(function(paidData) {
            console.log('Ensemble forecast data received:', paidData);
            const actual = paidData.actual || [];
            const forecast = paidData.forecast || [];
            
            console.log('Ensemble - Actual:', actual.length, 'Forecast:', forecast.length);
            
            // If no forecast data but have actual, still show chart with actual only
            if (forecast.length === 0 && actual.length > 0) {
                console.warn('No ensemble forecast data, showing actual data only');
                loadActualDataOnly(period);
                return;
            }
            
            if (actual.length === 0 && forecast.length === 0) {
                console.warn('No data at all in ensemble forecast');
                loadActualDataOnly(period);
                return;
            }
            
            // Use same chart rendering logic as ML method
            const actualLabels = actual.map(i => i.period);
            const actualValues = actual.map(i => parseFloat(i.revenue) || 0);
            const forecastLabels = forecast.map(i => i.period);
            const forecastValues = forecast.map(i => parseFloat(i.revenue) || 0);
            
            const allLabelsSet = new Set([...actualLabels, ...forecastLabels]);
            const allLabels = Array.from(allLabelsSet).sort();
            
            const paidActualMap = {};
            actualLabels.forEach((label, idx) => { paidActualMap[label] = actualValues[idx]; });
            const paidForecastMap = {};
            forecastLabels.forEach((label, idx) => { paidForecastMap[label] = forecastValues[idx]; });
            
            const actualSeries = allLabels.map(label => paidActualMap[label] || null);
            const forecastSeries = allLabels.map(label => paidForecastMap[label] || null);
            
            const lastActual = actualValues[actualValues.length - 1] || 0;
            const lastActualIndex = actualLabels.length > 0 ? allLabels.indexOf(actualLabels[actualLabels.length - 1]) : -1;
            if (lastActualIndex >= 0 && forecastValues.length > 0) {
                forecastSeries[lastActualIndex] = lastActual;
            }
            
            const datasets = [
                { label: 'Actual Revenue (Paid)', data: actualSeries, borderColor: '#4e73df', backgroundColor: 'rgba(78, 115, 223, 0.1)', tension: 0.3, fill: false, pointBackgroundColor: '#4e73df', pointBorderColor: '#4e73df', pointRadius: 4, spanGaps: false },
                { label: 'Forecasted Revenue (Linear Regression - Fallback)', data: forecastSeries, borderColor: '#dc3545', backgroundColor: 'rgba(220, 53, 69, 0.1)', tension: 0.3, fill: false, pointBackgroundColor: '#dc3545', pointBorderColor: '#dc3545', pointRadius: 4, borderDash: [5,5], spanGaps: false }
            ];
            
            console.log('Linear forecast (fallback) - Actual data points:', actual.length, 'Forecast data points:', forecast.length);
            console.log('Actual series:', actualSeries);
            console.log('Forecast series:', forecastSeries);
            
            const revenueCtx = document.getElementById('revenueChart').getContext('2d');
            revenueChart = new Chart(revenueCtx, {
                type: 'line',
                data: { labels: allLabels, datasets: datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    scales: {
                        y: { beginAtZero: true, ticks: { callback: function(value){ return '₱' + value.toLocaleString(); } }, grid: { color: 'rgba(0,0,0,0.1)' } },
                        x: { grid: { color: 'rgba(0,0,0,0.1)' } }
                    },
                    plugins: {
                        legend: { display: true, position: 'top' },
                        tooltip: { 
                            filter: function(ti){ return ti.raw !== null; }, 
                            callbacks: { 
                                label: function(ctx){ 
                                    const v = ctx.raw; 
                                    if (v === null) return null;
                                    return ctx.dataset.label + ': ₱' + Number(v).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2}); 
                                } 
                            } 
                        }
                    },
                    elements: { line: { spanGaps: true } }
                }
            });
        })
        .fail(function(){ 
            loadActualDataOnly(period); 
        });
    });
}

function renderChartWithActualOnly(actualData, period) {
    console.log('📊 Rendering chart with actual data only:', actualData.length, 'points');
    
    if (revenueChart) {
        revenueChart.destroy();
    }
    
    const labels = actualData.map(item => item.period);
    const values = actualData.map(item => parseFloat(item.revenue) || 0);
    
    console.log('Labels:', labels.slice(0, 5), '...');
    console.log('Values:', values.slice(0, 5), '...');
    
    const canvasElement = document.getElementById('revenueChart');
    if (!canvasElement) {
        console.error('❌ Canvas element not found!');
        return;
    }
    
    const revenueCtx = canvasElement.getContext('2d');
    revenueChart = new Chart(revenueCtx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Actual Revenue (Paid)',
                data: values,
                borderColor: '#4e73df',
                backgroundColor: 'rgba(78, 115, 223, 0.1)',
                tension: 0.3,
                fill: true,
                pointBackgroundColor: '#4e73df',
                pointBorderColor: '#4e73df',
                pointRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '₱' + value.toLocaleString();
                        }
                    }
                }
            },
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
                }
            }
        }
    });
    
    console.log('✅ Chart rendered successfully with actual data');
}

function loadActualDataOnly(period) {
    console.log('🔄 Loading actual data only for period:', period);
    
    $.ajax({
        url: 'dashboard_data.php',
        method: 'GET',
        data: { action: 'revenue_data', period: period },
        dataType: 'json'
    })
    .done(function(data) {
        console.log('📥 Received data from revenue_data endpoint:', data);
        
        // Ensure data is an array
        if (!Array.isArray(data)) {
            console.error('❌ Expected array but got:', typeof data, data);
            data = [];
        }
        
        const labels = data.map(item => item.period);
        const values = data.map(item => parseFloat(item.revenue));
        
        if (revenueChart) {
            revenueChart.destroy();
        }
        
        const revenueCtx = document.getElementById('revenueChart').getContext('2d');
        revenueChart = new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Actual Revenue',
                    data: values,
                    borderColor: '#4e73df',
                    tension: 0.3,
                    fill: true,
                    backgroundColor: 'rgba(78, 115, 223, 0.05)'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₱' + value.toLocaleString();
                            }
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: true
                    }
                }
            }
        });
        
        console.log('Fallback chart created with actual data only');
        
        // If still no data, show empty chart with message
        if (labels.length === 0 || values.every(v => v === 0)) {
            console.warn('No revenue data found in database');
            // Create empty chart with message
            if (revenueChart) {
                revenueChart.destroy();
            }
            const revenueCtx = document.getElementById('revenueChart').getContext('2d');
            revenueChart = new Chart(revenueCtx, {
                type: 'line',
                data: {
                    labels: ['No Data'],
                    datasets: [{
                        label: 'No Revenue Data Available',
                        data: [0],
                        borderColor: '#ccc',
                        backgroundColor: 'rgba(200, 200, 200, 0.1)',
                        pointRadius: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return '₱' + value.toLocaleString();
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: true
                        },
                        tooltip: {
                            enabled: false
                        }
                    }
                }
            });
        }
    })
    .fail(function(xhr, status, error) {
        console.error('Failed to load actual data:', error, xhr.responseText);
        // Show error message in chart area
        const chartContainer = document.querySelector('.chart-container');
        if (chartContainer) {
            chartContainer.innerHTML = '<div class="alert alert-warning text-center">Unable to load revenue data. Please check your database connection.</div>';
        }
    });
}



function refreshDashboardData() {
    $.get('dashboard_data.php', { action: 'all' }, function(data) {
        // Update stats
        $('.total-revenue h3').text('₱' + parseFloat(data.current_month_revenue).toLocaleString(2));
        $('.active-customers h3').text(data.total_clients);
        $('.pending-payments h3').text(data.pending_payments);
        $('.average-bill h3').text('₱' + parseFloat(data.average_bill).toLocaleString(2));
        
        // Payment status bar graph is static HTML - no update needed
        // Status data is already displayed in the bar graph
    });
}



function viewBill(billId) {
    window.location.href = `billing_list.php?view=${billId}`;
}

function exportReport() {
    // Get the current date for the filename
    const date = new Date();
    const dateStr = date.getFullYear() + '-' + 
                   String(date.getMonth() + 1).padStart(2, '0') + '-' + 
                   String(date.getDate()).padStart(2, '0');

    // Create CSV content
    let csvContent = 'data:text/csv;charset=utf-8,';
    csvContent += 'Invoice ID,Customer,Amount,Status,Due Date\n';

    // Add transaction data
    <?php foreach ($recent_transactions as $transaction): ?>
    csvContent += `<?php echo $transaction['id']; ?>,`;
    csvContent += `"<?php echo $transaction['firstname'] . ' ' . $transaction['lastname']; ?>",`;
    csvContent += `"₱<?php echo number_format($transaction['total'], 2); ?>",`;
    csvContent += `"<?php echo $transaction['status'] == 1 ? 'Paid' : (strtotime($transaction['due_date']) < time() ? 'Overdue' : 'Pending'); ?>",`;
    csvContent += `"<?php echo date('M d, Y', strtotime($transaction['due_date'])); ?>"\n`;
    <?php endforeach; ?>

    // Create download link and trigger download
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', `billing_report_${dateStr}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

document.addEventListener('DOMContentLoaded', function() {
    const themeToggle = document.getElementById('theme-toggle');
    const html = document.documentElement;
    
    const savedTheme = localStorage.getItem('theme') || 'light';
    html.setAttribute('data-theme', savedTheme);
    themeToggle.checked = savedTheme === 'dark';

    function updateChartsTheme(isDark) {
        const textColor = isDark ? '#e4e6eb' : '#333';
        const gridColor = isDark ? '#393b40' : '#dee2e6';
        const backgroundColor = isDark ? '#2d2f34' : '#fff';
        
        // Update Revenue Chart (with proper null checks)
        if (revenueChart && revenueChart.options && revenueChart.options.scales) {
            try {
                if (revenueChart.options.scales.y) {
                    revenueChart.options.scales.y.grid.color = gridColor;
                    revenueChart.options.scales.y.ticks.color = textColor;
                }
                if (revenueChart.options.scales.x) {
                    revenueChart.options.scales.x.grid.color = gridColor;
                    revenueChart.options.scales.x.ticks.color = textColor;
                }
                if (revenueChart.options.plugins && revenueChart.options.plugins.title) {
                    revenueChart.options.plugins.title.color = textColor;
                }
                if (revenueChart.data && revenueChart.data.datasets && revenueChart.data.datasets[0]) {
                    revenueChart.data.datasets[0].borderColor = isDark ? '#4e9eff' : '#4e73df';
                    revenueChart.data.datasets[0].backgroundColor = isDark ? 'rgba(78, 158, 255, 0.1)' : 'rgba(78, 115, 223, 0.05)';
                }
                revenueChart.update();
            } catch (e) {
                console.warn('Could not update revenue chart theme:', e);
            }
        }
        
        // Update Payment Status Chart (with proper null checks)
        if (paymentStatusChart && paymentStatusChart.options && paymentStatusChart.options.plugins) {
            try {
                if (paymentStatusChart.options.plugins.legend && paymentStatusChart.options.plugins.legend.labels) {
                    paymentStatusChart.options.plugins.legend.labels.color = textColor;
                    paymentStatusChart.options.plugins.legend.labels.boxWidth = 15;
                }
                paymentStatusChart.update();
            } catch (e) {
                console.warn('Could not update payment status chart theme:', e);
            }
        }

        // Update other elements that might need color adjustment
        document.querySelectorAll('.card-title').forEach(title => {
            title.style.color = textColor;
        });
    }

    themeToggle.addEventListener('change', function() {
        const theme = this.checked ? 'dark' : 'light';
        html.setAttribute('data-theme', theme);
        localStorage.setItem('theme', theme);
        updateChartsTheme(this.checked);
    });

    // Initialize with current theme
    updateChartsTheme(savedTheme === 'dark');
});

function viewReportModal(id, name, description, location, status, created_at, resolution_notes) {
    document.getElementById('modalReportId').value = id;
    document.getElementById('modalClientName').textContent = name;
    document.getElementById('modalDescription').textContent = description;
    document.getElementById('modalLocation').textContent = location;
    document.getElementById('modalSubmitDate').textContent = new Date(created_at).toLocaleString();
    
    const statusBadge = status == 1 ? 
        '<span class="badge bg-success">Resolved</span>' : 
        '<span class="badge bg-warning">Pending</span>';
    document.getElementById('modalStatus').innerHTML = statusBadge;
    
    document.getElementById('modalStatusSelect').value = status;
    document.getElementById('modalResolutionInput').value = resolution_notes || '';
    
    if (resolution_notes && status == 1) {
        document.getElementById('resolutionNotesSection').style.display = 'block';
        document.getElementById('modalResolutionNotes').textContent = resolution_notes;
    } else {
        document.getElementById('resolutionNotesSection').style.display = 'none';
    }
    
    new bootstrap.Modal(document.getElementById('reportModal')).show();
}

function updateReport() {
    const formData = new FormData();
    formData.append('report_id', document.getElementById('modalReportId').value);
    formData.append('status', document.getElementById('modalStatusSelect').value);
    formData.append('resolution_notes', document.getElementById('modalResolutionInput').value);
    formData.append('update_status', '1');

    fetch('update_report.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Close modal
            bootstrap.Modal.getInstance(document.getElementById('reportModal')).hide();
            // Refresh the page to show updated data
            showSuccess('Report updated successfully!');
            setTimeout(() => location.reload(), 1000);
        } else {
            showError('Error updating report: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showError('Error updating report. Please try again.');
    });
}

// Handle notice view button click
document.querySelectorAll('.view-notice').forEach(button => {
    button.addEventListener('click', function() {
        const noticeId = this.getAttribute('data-id');
        fetch(`get_notice_details.php?id=${noticeId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const notice = data.notice;
                    let statusClass = '';
                    let statusIcon = '';
                    switch(notice.status) {
                        case 'ongoing':
                            statusClass = 'bg-warning';
                            statusIcon = 'fa-clock';
                            break;
                        case 'scheduled':
                            statusClass = 'bg-info';
                            statusIcon = 'fa-calendar';
                            break;
                        case 'completed':
                            statusClass = 'bg-success';
                            statusIcon = 'fa-check-circle';
                            break;
                    }

                    let typeClass = '';
                    let typeIcon = '';
                    switch(notice.type) {
                        case 'interruption':
                            typeClass = 'text-danger';
                            typeIcon = 'fa-tint-slash';
                            break;
                        case 'maintenance':
                            typeClass = 'text-warning';
                            typeIcon = 'fa-wrench';
                            break;
                        case 'announcement':
                            typeClass = 'text-info';
                            typeIcon = 'fa-info-circle';
                            break;
                    }

                    document.getElementById('noticeDetails').innerHTML = `
                        <div class="mb-4">
                            <h4 class="mb-3">${notice.title}</h4>
                            <div class="d-flex gap-3 mb-3">
                                <span class="${typeClass}">
                                    <i class="fas ${typeIcon} me-1"></i>
                                    ${notice.type.charAt(0).toUpperCase() + notice.type.slice(1)}
                                </span>
                                <span class="badge ${statusClass}">
                                    <i class="fas ${statusIcon} me-1"></i>
                                    ${notice.status.charAt(0).toUpperCase() + notice.status.slice(1)}
                                </span>
                            </div>
                            <p class="text-muted mb-4">${notice.description}</p>
                            <div class="row">
                                <div class="col-md-6">
                                    <h6 class="mb-2">Affected Areas</h6>
                                    <p>${notice.affected_areas}</p>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="mb-2">Duration</h6>
                                    <p>
                                        Start: ${new Date(notice.start_date).toLocaleString()}<br>
                                        ${notice.end_date ? 'End: ' + new Date(notice.end_date).toLocaleString() : ''}
                                    </p>
                                </div>
                            </div>
                            <div class="mt-3">
                                <small class="text-muted">
                                    Created by ${notice.admin_name} on ${new Date(notice.created_at).toLocaleString()}
                                </small>
                            </div>
                        </div>
                    `;
                } else {
                    document.getElementById('noticeDetails').innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            Error loading notice details
                        </div>
                    `;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('noticeDetails').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        Error loading notice details
                    </div>
                `;
            });
    });
});

// Sidebar toggle for mobile
document.addEventListener('DOMContentLoaded', function() {
    var sidebar = document.getElementById('sidebar');
    var sidebarToggle = document.getElementById('sidebarToggle');
    var mainContent = document.querySelector('.main-content');
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

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Notification System -->
<script src="assets/js/notifications.js"></script>
</body>
</html>
