<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: adminlogin.php");
    exit();
}

include 'db.php';
include 'comprehensive_fee_manager.php';

// Handle report generation
$report_data = [];
$report_type = $_GET['type'] ?? 'dashboard';
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$date_to = $_GET['date_to'] ?? date('Y-m-d'); // Today

// Dashboard Summary
if ($report_type === 'dashboard') {
    // Total collections this month
    $collections_sql = "SELECT 
        COUNT(*) as total_payments,
        SUM(amount) as total_collected,
        SUM(CASE WHEN status = 1 THEN amount ELSE 0 END) as verified_collected
        FROM payment_list 
        WHERE DATE(payment_date) BETWEEN ? AND ?";
    $stmt = $conn->prepare($collections_sql);
    $stmt->bind_param("ss", $date_from, $date_to);
    $stmt->execute();
    $collections_data = $stmt->get_result()->fetch_assoc();
    
    // Client statistics
    $clients_sql = "SELECT 
        COUNT(*) as total_clients,
        SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as active_clients,
        SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) as inactive_clients
        FROM client_list WHERE delete_flag = 0";
    $clients_data = $conn->query($clients_sql)->fetch_assoc();
    
    // Overdue accounts
    $overdue_sql = "SELECT 
        COUNT(DISTINCT bl.client_id) as overdue_clients,
        COUNT(*) as overdue_bills,
        SUM(bl.total) as overdue_amount
        FROM billing_list bl
        WHERE bl.status = 0 AND bl.due_date < CURRENT_DATE()";
    $overdue_data = $conn->query($overdue_sql)->fetch_assoc();
    
    // Recent billing activity
    $recent_bills_sql = "SELECT 
        COUNT(*) as bills_generated,
        SUM(total) as total_billed
        FROM billing_list 
        WHERE DATE(date_created) BETWEEN ? AND ?";
    $stmt = $conn->prepare($recent_bills_sql);
    $stmt->bind_param("ss", $date_from, $date_to);
    $stmt->execute();
    $billing_data = $stmt->get_result()->fetch_assoc();
    
    $report_data = [
        'collections' => $collections_data,
        'clients' => $clients_data,
        'overdue' => $overdue_data,
        'billing' => $billing_data
    ];
}

// Collections Report
elseif ($report_type === 'collections') {
    // Daily collections breakdown
    $daily_collections_sql = "SELECT 
        DATE(payment_date) as payment_date,
        COUNT(*) as payment_count,
        SUM(amount) as daily_total,
        SUM(CASE WHEN status = 1 THEN amount ELSE 0 END) as verified_total,
        payment_method
        FROM payment_list 
        WHERE DATE(payment_date) BETWEEN ? AND ?
        GROUP BY DATE(payment_date), payment_method
        ORDER BY payment_date DESC";
    $stmt = $conn->prepare($daily_collections_sql);
    $stmt->bind_param("ss", $date_from, $date_to);
    $stmt->execute();
    $daily_collections = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Payment method breakdown
    $method_breakdown_sql = "SELECT 
        payment_method,
        COUNT(*) as count,
        SUM(amount) as total
        FROM payment_list 
        WHERE DATE(payment_date) BETWEEN ? AND ? AND status = 1
        GROUP BY payment_method";
    $stmt = $conn->prepare($method_breakdown_sql);
    $stmt->bind_param("ss", $date_from, $date_to);
    $stmt->execute();
    $method_breakdown = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Top paying clients
    $top_clients_sql = "SELECT 
        cl.firstname, cl.lastname, cl.meter_code,
        COUNT(pl.id) as payment_count,
        SUM(pl.amount) as total_paid
        FROM payment_list pl
        JOIN billing_list bl ON pl.billing_id = bl.id
        JOIN client_list cl ON bl.client_id = cl.id
        WHERE DATE(pl.payment_date) BETWEEN ? AND ? AND pl.status = 1
        GROUP BY cl.id
        ORDER BY total_paid DESC
        LIMIT 10";
    $stmt = $conn->prepare($top_clients_sql);
    $stmt->bind_param("ss", $date_from, $date_to);
    $stmt->execute();
    $top_clients = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $report_data = [
        'daily_collections' => $daily_collections,
        'method_breakdown' => $method_breakdown,
        'top_clients' => $top_clients
    ];
}

// Clients Report
elseif ($report_type === 'clients') {
    // Client categories breakdown
    $categories_sql = "SELECT 
        cr.category_id,
        COUNT(cl.id) as client_count,
        cr.rate,
        cr.excess_rate
        FROM client_list cl
        LEFT JOIN category_rates cr ON cl.category_id = cr.category_id
        WHERE cl.delete_flag = 0
        GROUP BY cl.category_id";
    $categories_data = $conn->query($categories_sql)->fetch_all(MYSQLI_ASSOC);
    
    // Recent registrations
    $recent_clients_sql = "SELECT 
        firstname, lastname, contact, address, meter_code, date_created
        FROM client_list 
        WHERE delete_flag = 0 AND DATE(date_created) BETWEEN ? AND ?
        ORDER BY date_created DESC";
    $stmt = $conn->prepare($recent_clients_sql);
    $stmt->bind_param("ss", $date_from, $date_to);
    $stmt->execute();
    $recent_clients = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Client activity summary
    $activity_sql = "SELECT 
        cl.id, cl.firstname, cl.lastname, cl.meter_code,
        COUNT(bl.id) as total_bills,
        SUM(bl.total) as total_billed,
        COUNT(pl.id) as payments_made,
        SUM(pl.amount) as total_paid
        FROM client_list cl
        LEFT JOIN billing_list bl ON cl.id = bl.client_id
        LEFT JOIN payment_list pl ON bl.id = pl.billing_id AND pl.status = 1
        WHERE cl.delete_flag = 0
        GROUP BY cl.id
        ORDER BY total_billed DESC
        LIMIT 20";
    $client_activity = $conn->query($activity_sql)->fetch_all(MYSQLI_ASSOC);
    
    $report_data = [
        'categories' => $categories_data,
        'recent_clients' => $recent_clients,
        'client_activity' => $client_activity
    ];
}

// Overdue Accounts Report
elseif ($report_type === 'overdue') {
    // Overdue bills with client details
    $overdue_bills_sql = "SELECT 
        cl.firstname, cl.lastname, cl.contact, cl.meter_code,
        bl.id as bill_id, bl.reading_date, bl.due_date, bl.total,
        DATEDIFF(CURRENT_DATE(), bl.due_date) as days_overdue,
        COALESCE(SUM(pl.amount), 0) as amount_paid,
        (bl.total - COALESCE(SUM(pl.amount), 0)) as balance_due
        FROM billing_list bl
        JOIN client_list cl ON bl.client_id = cl.id
        LEFT JOIN payment_list pl ON bl.id = pl.billing_id AND pl.status = 1
        WHERE bl.status = 0 AND bl.due_date < CURRENT_DATE()
        GROUP BY bl.id
        HAVING balance_due > 0
        ORDER BY days_overdue DESC, balance_due DESC";
    $overdue_bills = $conn->query($overdue_bills_sql)->fetch_all(MYSQLI_ASSOC);
    
    // Overdue summary by age
    $overdue_aging_sql = "SELECT 
        age_group,
        COUNT(*) as bill_count,
        SUM(balance_due) as total_overdue
        FROM (
            SELECT 
                bl.id,
                CASE 
                    WHEN DATEDIFF(CURRENT_DATE(), bl.due_date) <= 30 THEN '1-30 days'
                    WHEN DATEDIFF(CURRENT_DATE(), bl.due_date) <= 60 THEN '31-60 days'
                    WHEN DATEDIFF(CURRENT_DATE(), bl.due_date) <= 90 THEN '61-90 days'
                    ELSE '90+ days'
                END as age_group,
                (bl.total - COALESCE(SUM(pl.amount), 0)) as balance_due
                FROM billing_list bl
                LEFT JOIN payment_list pl ON bl.id = pl.billing_id AND pl.status = 1
                WHERE bl.status = 0 AND bl.due_date < CURRENT_DATE()
                GROUP BY bl.id, bl.total, bl.due_date
                HAVING balance_due > 0
        ) as overdue_bills_with_age
        GROUP BY age_group
        ORDER BY 
            CASE age_group
                WHEN '1-30 days' THEN 1
                WHEN '31-60 days' THEN 2
                WHEN '61-90 days' THEN 3
                WHEN '90+ days' THEN 4
            END";
    $aging_temp = $conn->query($overdue_aging_sql)->fetch_all(MYSQLI_ASSOC);
    
    // Process aging data (already grouped by the query)
    $overdue_aging = [];
    foreach ($aging_temp as $row) {
        $overdue_aging[$row['age_group']] = [
            'bill_count' => $row['bill_count'],
            'total_overdue' => $row['total_overdue']
        ];
    }
    
    $report_data = [
        'overdue_bills' => $overdue_bills,
        'overdue_aging' => $overdue_aging
    ];
}

// Billing Summary Report
elseif ($report_type === 'billing') {
    // Monthly billing summary
    $monthly_billing_sql = "SELECT 
        YEAR(reading_date) as year,
        MONTH(reading_date) as month,
        MONTHNAME(reading_date) as month_name,
        COUNT(*) as bills_generated,
        SUM(total) as total_amount,
        AVG(total) as average_bill,
        COUNT(CASE WHEN status = 1 THEN 1 END) as paid_bills,
        COUNT(CASE WHEN status = 0 AND due_date < CURRENT_DATE() THEN 1 END) as overdue_bills
        FROM billing_list 
        WHERE DATE(reading_date) BETWEEN ? AND ?
        GROUP BY YEAR(reading_date), MONTH(reading_date)
        ORDER BY year DESC, month DESC";
    $stmt = $conn->prepare($monthly_billing_sql);
    $stmt->bind_param("ss", $date_from, $date_to);
    $stmt->execute();
    $monthly_billing = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Consumption patterns
    $consumption_sql = "SELECT 
        CASE 
            WHEN (reading - previous) <= 6 THEN '0-6 cubic meters'
            WHEN (reading - previous) <= 15 THEN '7-15 cubic meters'
            WHEN (reading - previous) <= 30 THEN '16-30 cubic meters'
            ELSE '30+ cubic meters'
        END as consumption_range,
        COUNT(*) as bill_count,
        AVG(total) as average_bill
        FROM billing_list 
        WHERE DATE(reading_date) BETWEEN ? AND ?
        GROUP BY consumption_range";
    $stmt = $conn->prepare($consumption_sql);
    $stmt->bind_param("ss", $date_from, $date_to);
    $stmt->execute();
    $consumption_patterns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $report_data = [
        'monthly_billing' => $monthly_billing,
        'consumption_patterns' => $consumption_patterns
    ];
}

// Additional Fees Report
elseif ($report_type === 'fees') {
    // Fee breakdown
    $fees_breakdown_sql = "SELECT 
        af.fee_name, af.fee_type, af.fee_amount,
        COUNT(baf.id) as times_applied,
        SUM(baf.fee_amount) as total_collected
        FROM additional_fees af
        LEFT JOIN bill_additional_fees baf ON af.id = baf.fee_id
        LEFT JOIN billing_list bl ON baf.bill_id = bl.id
        WHERE af.is_active = 1 AND (bl.reading_date IS NULL OR DATE(bl.reading_date) BETWEEN ? AND ?)
        GROUP BY af.id
        ORDER BY total_collected DESC";
    $stmt = $conn->prepare($fees_breakdown_sql);
    $stmt->bind_param("ss", $date_from, $date_to);
    $stmt->execute();
    $fees_breakdown = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Recent fee applications
    $recent_fees_sql = "SELECT 
        af.fee_name, baf.fee_amount, baf.applied_at,
        cl.firstname, cl.lastname, bl.reading_date
        FROM bill_additional_fees baf
        JOIN additional_fees af ON baf.fee_id = af.id
        JOIN billing_list bl ON baf.bill_id = bl.id
        JOIN client_list cl ON bl.client_id = cl.id
        WHERE DATE(baf.applied_at) BETWEEN ? AND ?
        ORDER BY baf.applied_at DESC
        LIMIT 50";
    $stmt = $conn->prepare($recent_fees_sql);
    $stmt->bind_param("ss", $date_from, $date_to);
    $stmt->execute();
    $recent_fees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $report_data = [
        'fees_breakdown' => $fees_breakdown,
        'recent_fees' => $recent_fees
    ];
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Water Billing System</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />

    <!-- Google Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" />
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

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

    .report-card {
        transition: transform 0.2s;
        border: none;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        background-color: var(--card-bg);
        color: var(--card-text);
        border-radius: 15px;
    }

    .report-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
    }

    .metric-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 15px;
    }

    .chart-container {
        position: relative;
        height: 400px;
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

    .table-responsive {
        border-radius: 10px;
        overflow: hidden;
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

    .form-control, .form-select {
        background-color: var(--sidebar-bg);
        border-color: var(--border-color);
        color: var(--text-color);
    }

    .form-control:focus, .form-select:focus {
        background-color: var(--sidebar-bg);
        border-color: var(--hover-text);
        color: var(--text-color);
        box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
    }

    .reports-sidebar {
        background-color: var(--card-bg);
        border-radius: 15px;
        padding: 20px;
        margin-bottom: 20px;
    }

    .reports-sidebar .nav-link {
        color: var(--text-color);
        padding: 0.75rem 1rem;
        border-radius: 8px;
        margin: 0.2rem 0;
        transition: all 0.3s ease;
    }

    .reports-sidebar .nav-link:hover, 
    .reports-sidebar .nav-link.active {
        background-color: var(--hover-bg);
        color: var(--hover-text);
    }

    /* Responsive */
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
    </style>
</head>
<body>

<button id="sidebarToggle" aria-label="Toggle sidebar">
    <i class="fas fa-bars"></i>
</button>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <img src="icons/Logo.png" alt="Water Billing Logo" class="img-fluid" style="max-height: 90px;" />
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
        <a href="payments.php">
            <i class="fas fa-money-bill-wave"></i>
            <span>Payments</span>
        </a>
        <a href="customer_accounts.php">
            <i class="fas fa-user-circle"></i>
            <span>Customer Accounts</span>
        </a>
        <a href="reports.php" class="active">
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
    <div class="row">
        <!-- Reports Navigation -->
        <div class="col-md-3">
            <div class="reports-sidebar">
                <h5 class="mb-3"><i class="fas fa-chart-bar me-2"></i>Report Types</h5>
                <nav class="nav flex-column">
                    <a class="nav-link <?php echo $report_type === 'dashboard' ? 'active' : ''; ?>" href="?type=dashboard">
                        <i class="fas fa-tachometer-alt me-2"></i>Dashboard Overview
                    </a>
                    <a class="nav-link <?php echo $report_type === 'collections' ? 'active' : ''; ?>" href="?type=collections">
                        <i class="fas fa-money-bill-wave me-2"></i>Collections Report
                    </a>
                    <a class="nav-link <?php echo $report_type === 'clients' ? 'active' : ''; ?>" href="?type=clients">
                        <i class="fas fa-users me-2"></i>Clients Report
                    </a>
                    <a class="nav-link <?php echo $report_type === 'overdue' ? 'active' : ''; ?>" href="?type=overdue">
                        <i class="fas fa-exclamation-triangle me-2"></i>Overdue Accounts
                    </a>
                    <a class="nav-link <?php echo $report_type === 'billing' ? 'active' : ''; ?>" href="?type=billing">
                        <i class="fas fa-file-invoice me-2"></i>Billing Summary
                    </a>
                    <a class="nav-link <?php echo $report_type === 'fees' ? 'active' : ''; ?>" href="?type=fees">
                        <i class="fas fa-tags me-2"></i>Additional Fees
                    </a>
                </nav>
            </div>
        </div>

        <!-- Report Content -->
        <div class="col-md-9">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3>
                        <?php
                        $titles = [
                            'dashboard' => 'Dashboard Overview',
                            'collections' => 'Collections Report',
                            'clients' => 'Clients Report',
                            'overdue' => 'Overdue Accounts Report',
                            'billing' => 'Billing Summary Report',
                            'fees' => 'Additional Fees Report'
                        ];
                        echo $titles[$report_type] ?? 'Reports';
                        ?>
                    </h3>
                    <p class="text-muted">Period: <?php echo date('M d, Y', strtotime($date_from)); ?> to <?php echo date('M d, Y', strtotime($date_to)); ?></p>
                </div>
                <div>
                    <!-- Date Filter -->
                    <form method="GET" class="d-flex gap-2">
                        <input type="hidden" name="type" value="<?php echo $report_type; ?>">
                        <input type="date" name="date_from" value="<?php echo $date_from; ?>" class="form-control">
                        <input type="date" name="date_to" value="<?php echo $date_to; ?>" class="form-control">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                        <div class="btn-group" role="group">
                            <button type="button" onclick="window.print()" class="btn btn-outline-secondary">
                                <i class="fas fa-print"></i> Print
                            </button>
                            <button type="button" onclick="exportReport('csv')" class="btn btn-success">
                                <i class="fas fa-download"></i> Export CSV
                            </button>
                            <button type="button" onclick="exportReport('pdf')" class="btn btn-outline-danger" disabled title="PDF export coming soon">
                                <i class="fas fa-file-pdf"></i> Export PDF
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Report Content -->
            <?php include 'report_content.php'; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Notification System -->
<script src="assets/js/notifications.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const themeToggle = document.getElementById('theme-toggle');
    const html = document.documentElement;
    
    const savedTheme = localStorage.getItem('theme') || 'light';
    html.setAttribute('data-theme', savedTheme);
    themeToggle.checked = savedTheme === 'dark';

    themeToggle.addEventListener('change', function() {
        const theme = this.checked ? 'dark' : 'light';
        html.setAttribute('data-theme', theme);
        localStorage.setItem('theme', theme);
    });
});

// Sidebar toggle for mobile
document.addEventListener('DOMContentLoaded', function() {
    var sidebar = document.getElementById('sidebar');
    var sidebarToggle = document.getElementById('sidebarToggle');
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('open');
        });
    }
    document.addEventListener('click', function(e) {
        if (window.innerWidth <= 991 && sidebar.classList.contains('open')) {
            if (!sidebar.contains(e.target) && e.target !== sidebarToggle) {
                sidebar.classList.remove('open');
            }
        }
    });
});

function exportReport(format) {
    const urlParams = new URLSearchParams(window.location.search);
    const reportType = urlParams.get('type') || 'dashboard';
    const dateFrom = urlParams.get('date_from') || '<?php echo $date_from; ?>';
    const dateTo = urlParams.get('date_to') || '<?php echo $date_to; ?>';
    
    if (format === 'pdf') {
        showInfo('PDF export feature is coming soon! Please use CSV export for now.');
        return;
    }
    
    // Create export URL
    const exportUrl = `export_reports.php?type=${reportType}&format=${format}&date_from=${dateFrom}&date_to=${dateTo}`;
    
    // Trigger download
    window.location.href = exportUrl;
}

// Add loading state to export button
function showExportLoading(button) {
    const originalHtml = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';
    button.disabled = true;
    
    // Reset button after 3 seconds
    setTimeout(() => {
        button.innerHTML = originalHtml;
        button.disabled = false;
    }, 3000);
}
</script>
</body>
</html> 