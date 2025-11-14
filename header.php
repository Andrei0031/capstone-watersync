<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Water Billing System</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
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
        margin: 0 20px 20px;
        border-radius: 12px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
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

    .card {
        background-color: var(--card-bg);
        border-color: var(--border-color);
        color: var(--card-text);
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

    .logo-container {
        text-align: center;
        padding: 15px;
        margin-bottom: 20px;
    }

    .logo-text {
        font-size: 24px;
        font-weight: bold;
        color: var(--text-color);
        margin: 0;
    }

    .logo-icon {
        font-size: 32px;
        color: #2196F3;
        margin-bottom: 10px;
    }

    .logo-subtitle {
        font-size: 14px;
        color: var(--muted-text);
        margin: 5px 0 0;
    }
    </style>
</head>
<body>
    <div class="sidebar">
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
                <i class="fas fa-tachometer-alt"></i>
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
                  <i class="fas fa-file-chart-line"></i>
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

    <div class="main-content"> 