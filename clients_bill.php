<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: adminlogin.php");
    exit();
}

include 'db.php';

$showNotificationModal = false; // Initialize to avoid undefined variable warning
$message = ''; // Initialize message variable

// Calculate total revenue for current month
$total_revenue = 0;
$revenue_query = "SELECT SUM(total) as total FROM billing_list 
                 WHERE MONTH(reading_date) = MONTH(CURRENT_DATE()) 
                 AND YEAR(reading_date) = YEAR(CURRENT_DATE())";
$revenue_result = $conn->query($revenue_query);
if ($revenue_result && $row = $revenue_result->fetch_assoc()) {
    $total_revenue = $row['total'] ?? 0;
}

// Calculate number of paid bills this month
$paid_bills = 0;
$paid_query = "SELECT COUNT(*) as count FROM billing_list 
               WHERE status = 1 
               AND MONTH(reading_date) = MONTH(CURRENT_DATE()) 
               AND YEAR(reading_date) = YEAR(CURRENT_DATE())";
$paid_result = $conn->query($paid_query);
if ($paid_result && $row = $paid_result->fetch_assoc()) {
    $paid_bills = $row['count'];
}

// Calculate number of pending bills
$pending_bills = 0;
$pending_query = "SELECT COUNT(*) as count FROM billing_list 
                 WHERE status = 0 
                 AND due_date >= CURRENT_DATE()";
$pending_result = $conn->query($pending_query);
if ($pending_result && $row = $pending_result->fetch_assoc()) {
    $pending_bills = $row['count'];
}

// Calculate number of overdue bills
$overdue_bills = 0;
$overdue_query = "SELECT COUNT(*) as count FROM billing_list 
                 WHERE status = 0 
                 AND due_date < CURRENT_DATE()";
$overdue_result = $conn->query($overdue_query);
if ($overdue_result && $row = $overdue_result->fetch_assoc()) {
    $overdue_bills = $row['count'];
}

// Fetch list of active clients for selection in add billing modal
$sql_clients = "SELECT id, firstname, lastname, category_id FROM client_list WHERE status = 1";
$result_clients = $conn->query($sql_clients);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['delete_client'])) {
        // Handle client deletion
        $client_id = $_POST['delete_client_id'];
        $stmt = $conn->prepare("DELETE FROM client_list WHERE id = ?");
        $stmt->bind_param("i", $client_id);
        if ($stmt->execute()) {
            $notification = 'Client deleted successfully.';
            $notificationClass = 'alert-success';
            $stmt->close();
            // Redirect to avoid form resubmission and show updated list
            header("Location: clients_bill.php?delete_status=success");
            exit();
        } else {
            $notification = 'Error deleting client: ' . $stmt->error;
            $notificationClass = 'alert-danger';
            $stmt->close();
            header("Location: clients_bill.php?delete_status=error&message=" . urlencode($stmt->error));
            exit();
        }
        $showNotificationModal = true;
    } elseif (isset($_POST['update_client'])) {
        // Retrieve values from POST data
            $client_id = $_POST['client_id'];
            $firstname = $_POST['firstname'];
            $lastname = $_POST['lastname'];

            // Update client record in the database
            $stmt = $conn->prepare("UPDATE client_list SET firstname = ?, lastname = ? WHERE id = ?");
            
            // Bind parameters
            $stmt->bind_param("ssi", $firstname, $lastname, $client_id);

            // Execute the statement
            if ($stmt->execute()) {
                $notification = 'Client updated successfully.';
                $notificationClass = 'alert-success';
            } else {
                $notification = 'Error updating client: ' . $stmt->error;
                $notificationClass = 'alert-danger';
            }

        $showNotificationModal = true;

        // Close the statement
        $stmt->close();
    } elseif (isset($_POST['update_rates'])) {
        // This block can be removed or left as is since settings_rate.php will handle rates
        // For safety, keep it but it won't be triggered from this page anymore
        $residential_rate = $_POST['residential_rate'];
        $residential_excess_rate = $_POST['residential_excess_rate'];
        $commercial_rate = $_POST['commercial_rate'];
        $commercial_excess_rate = $_POST['commercial_excess_rate'];

        // Update residential rates
        $stmt_residential = $conn->prepare("UPDATE category_rates SET rate = ?, excess_rate = ? WHERE category_id = 1");
        $stmt_residential->bind_param("dd", $residential_rate, $residential_excess_rate);
        $residential_update_success = $stmt_residential->execute();
        $stmt_residential->close();

        // Update commercial rates
        $stmt_commercial = $conn->prepare("UPDATE category_rates SET rate = ?, excess_rate = ? WHERE category_id = 2");
        $stmt_commercial->bind_param("dd", $commercial_rate, $commercial_excess_rate);
        $commercial_update_success = $stmt_commercial->execute();
        $stmt_commercial->close();

        if ($residential_update_success && $commercial_update_success) {
            $notification = 'Rates updated successfully.';
            $notificationClass = 'alert-success';
        } else {
            $notification = 'Error updating rates.';
            $notificationClass = 'alert-danger';
        }

        $showNotificationModal = true;
    } elseif (isset($_POST['add_billing'])) {
        // Handle adding new billing record from modal form
        $client_id = $_POST['client_id'];
        $reading_date = $_POST['reading_date'];
        $due_date = $_POST['due_date'];
        $reading = $_POST['reading'];
        $status = $_POST['status']; // Assuming status comes from the form

        // Fetch the category_id of the selected client
        $sql_category = "SELECT category_id FROM client_list WHERE id = ?";
        $stmt_category = $conn->prepare($sql_category);
        $stmt_category->bind_param("i", $client_id);
        $stmt_category->execute();
        $stmt_category->bind_result($category_id);
        $stmt_category->fetch();
        $stmt_category->close();

        // Remove fetching rate from database as we're using fixed rates
        $rate = null; // Not used for calculation

        // Fetch the latest reading for the client to use as previous
        $sql_latest_reading = "SELECT reading FROM billing_list WHERE client_id = ? ORDER BY reading_date DESC LIMIT 1";
        $stmt_latest_reading = $conn->prepare($sql_latest_reading);
        $stmt_latest_reading->bind_param("i", $client_id);
        $stmt_latest_reading->execute();
        $stmt_latest_reading->store_result();
        $num_rows = $stmt_latest_reading->num_rows;
        if ($num_rows > 0) {
            $stmt_latest_reading->bind_result($previous);
            $stmt_latest_reading->fetch();
        } else {
            $previous = 0;
        }
        $stmt_latest_reading->close();

        // Fixed rates
        $base_rate = 100; // 100 pesos for first 6 cubic meters
        $excess_rate = 20; // 20 pesos per cubic meter for excess

        // Calculate total based on consumption and fixed rates
        $usage = $reading - $previous;
        if ($usage <= 6) {
            $total = $base_rate; // Just charge base rate if within 6 cubic meters
        } else {
            $excess_consumption = $usage - 6;
            $excess_charge = $excess_consumption * $excess_rate;
            $total = $base_rate + $excess_charge;
        }

        $stmt = $conn->prepare("INSERT INTO billing_list (client_id, reading_date, due_date, reading, previous, rate, total, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("issdddis", $client_id, $reading_date, $due_date, $reading, $previous, $rate, $total, $status);

        if ($stmt->execute()) {
            $message = "New billing record added successfully";
            $messageClass = "success-message";
            // Removed update of latest_reading as column does not exist in client_list
            // $update_stmt = $conn->prepare("UPDATE client_list SET latest_reading = ? WHERE id = ?");
            // $update_stmt->bind_param("di", $reading, $client_id);
            // $update_stmt->execute();
            // $update_stmt->close();
        } else {
            $message = "Error: " . $stmt->error;
            $messageClass = "error-message";
        }

        $stmt->close();
    } elseif (isset($_POST['update_billing'])) {
        // Handle updating billing record from edit modal form
        $id = $_POST['id'];
        $client_id = $_POST['client_id'];
        $reading_date = $_POST['reading_date'];
        $due_date = $_POST['due_date'];
        $reading = $_POST['reading'];
        $previous = $_POST['previous'];
        $status = $_POST['status'];

        // Fetch the category_id of the client
        $sql_category = "SELECT category_id FROM client_list WHERE id = ?";
        $stmt_category = $conn->prepare($sql_category);
        $stmt_category->bind_param("i", $client_id);
        $stmt_category->execute();
        $stmt_category->bind_result($category_id);
        $stmt_category->fetch();
        $stmt_category->close();

        // Fetch the rate and excess_rate for the client's category
        $sql_rate = "SELECT rate, excess_rate FROM category_rates WHERE category_id = ?";
        $stmt_rate = $conn->prepare($sql_rate);
        $stmt_rate->bind_param("i", $category_id);
        $stmt_rate->execute();
        $stmt_rate->bind_result($rate, $excess_rate);
        $stmt_rate->fetch();
        $stmt_rate->close();

        // Recalculate total based on updated reading and previous using fetched rate and excess rate
        $usage = $reading - $previous;
        if ($usage <= 6) {
            $total = $usage * $rate;
        } else {
            $total = 6 * $rate + ($usage - 6) * $excess_rate;
        }

        $stmt = $conn->prepare("UPDATE billing_list SET client_id = ?, reading_date = ?, due_date = ?, reading = ?, previous = ?, total = ?, status = ? WHERE id = ?");
        $stmt->bind_param("issdddii", $client_id, $reading_date, $due_date, $reading, $previous, $total, $status, $id);

        if ($stmt->execute()) {
            $message = "Billing record updated successfully";
            $messageClass = "success-message";
        } else {
            $message = "Error updating billing record: " . $stmt->error;
            $messageClass = "error-message";
        }

        $stmt->close();
    }
}

$search = '';
if (isset($_GET['search'])) {
    $search = $conn->real_escape_string($_GET['search']);
    $sql = "SELECT cl.*, b.id AS bill_id, b.reading_date, b.due_date, b.reading, b.previous, b.rate, b.total, b.status,
            (b.reading - b.previous) as consumption 
            FROM client_list cl
            LEFT JOIN billing_list b ON cl.id = b.client_id
            WHERE cl.firstname LIKE '%$search%' OR cl.lastname LIKE '%$search%' OR cl.code LIKE '%$search%'
            ORDER BY cl.firstname, cl.lastname, b.reading_date DESC";
} else {
    $sql = "SELECT cl.*, b.id AS bill_id, b.reading_date, b.due_date, b.reading, b.previous, b.rate, b.total, b.status,
            (b.reading - b.previous) as consumption 
            FROM client_list cl
            LEFT JOIN billing_list b ON cl.id = b.client_id
            ORDER BY cl.firstname, cl.lastname, b.reading_date DESC";
}
$result = $conn->query($sql);

?>
<!DOCTYPE html><html lang="en"><head>    <meta charset="UTF-8" />    <meta name="viewport" content="width=device-width, initial-scale=1" />    <link rel="icon" href="logo.png" />    <!-- Theme initialization -->    <script src="theme.js"></script>
    <title>Billing Management - Water Billing System</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <!-- Google Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" />
        <style>        /* Theme variables */        :root {            --bg-color: #f8f9fa;            --sidebar-bg: #fff;            --text-color: #333;            --card-bg: #fff;            --border-color: #dee2e6;            --hover-bg: #f0f2f5;            --hover-text: #007bff;            --muted-text: #6c757d;            --card-text: #333;            --table-header-bg: #f8f9fa;            --table-header-text: #333;            --table-cell-text: #333;            --table-bg: #fff;
            --modal-bg: #fff;
            --input-bg: #fff;
            --input-text: #333;
            --input-border: #dee2e6;
        }

                html[data-theme="dark"] {            --bg-color: #1a1d21;            --sidebar-bg: #242529;            --text-color: #e4e6eb;            --card-bg: #2d2f34;            --border-color: #393b40;            --hover-bg: #393b40;            --hover-text: #4e9eff;            --muted-text: #a0a0a0;            --card-text: #e4e6eb;            --table-header-bg: #242529;            --table-header-text: #e4e6eb;            --table-cell-text: #e4e6eb;            --table-bg: #2d2f34;            --modal-bg: #2d2f34;            --input-bg: #242529;            --input-text: #e4e6eb;            --input-border: #393b40;        }        /* Ensure dark mode styles are applied */        html[data-theme="dark"] body {            background-color: var(--bg-color);            color: var(--text-color);        }        html[data-theme="dark"] .sidebar {            background-color: var(--sidebar-bg);            border-color: var(--border-color);        }        html[data-theme="dark"] .card-soft {            background-color: var(--card-bg);            color: var(--card-text);        }        html[data-theme="dark"] .table {            color: var(--table-cell-text);            background-color: var(--table-bg);        }        html[data-theme="dark"] .table thead th {            background-color: var(--table-header-bg);            color: var(--table-header-text);            border-color: var(--border-color);        }        html[data-theme="dark"] .table td {            border-color: var(--border-color);            color: var(--text-color) !important;        }        html[data-theme="dark"] .modal-content {            background-color: var(--modal-bg);            color: var(--text-color);            border-color: var(--border-color);        }        html[data-theme="dark"] .form-control,        html[data-theme="dark"] .form-select {            background-color: var(--input-bg);            border-color: var(--input-border);            color: var(--input-text);        }        html[data-theme="dark"] .btn-close {            filter: invert(1) grayscale(100%) brightness(200%);        }        html[data-theme="dark"] .search-input {            background-color: var(--input-bg);            border-color: var(--input-border);            color: var(--input-text);        }        html[data-theme="dark"] .avatar-sm {            background: linear-gradient(45deg, #4e73df 0%, #224abe 100%);            color: white !important;        }        html[data-theme="dark"] .customer-name {            color: var(--text-color) !important;        }        html[data-theme="dark"] .customer-code {            color: var(--muted-text) !important;        }        html[data-theme="dark"] .modal-card {            background-color: var(--card-bg);            border-color: var(--border-color);        }        html[data-theme="dark"] .bill-detail-label {            color: var(--muted-text);        }        html[data-theme="dark"] .bill-detail-value {            color: var(--text-color);        }        html[data-theme="dark"] .payment-summary-label {            color: var(--muted-text);        }        html[data-theme="dark"] .payment-summary-value {            color: var(--text-color);        }

        body {
            font-family: 'Open Sans', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            transition: background-color 0.3s, color 0.3s;
        }

        /* Dark mode specific styles */
        [data-theme="dark"] .modal-content {
            background-color: var(--modal-bg);
            color: var(--text-color);
        }

        [data-theme="dark"] .form-control,
        [data-theme="dark"] .form-select {
            background-color: var(--input-bg);
            border-color: var(--input-border);
            color: var(--input-text);
        }

        [data-theme="dark"] .modal-header,
        [data-theme="dark"] .modal-footer {
            border-color: var(--border-color);
        }

        [data-theme="dark"] .customer-name {
            color: var(--text-color) !important;
        }

        [data-theme="dark"] .customer-code {
            color: var(--muted-text) !important;
        }

        [data-theme="dark"] .table td {
            color: var(--text-color) !important;
        }

        [data-theme="dark"] .modal-card {
            background-color: var(--card-bg);
            border-color: var(--border-color);
        }

        [data-theme="dark"] .bill-detail-label {
            color: var(--muted-text);
        }

        [data-theme="dark"] .bill-detail-value {
            color: var(--text-color);
        }

        [data-theme="dark"] .payment-summary-label {
            color: var(--muted-text);
        }

        [data-theme="dark"] .payment-summary-value {
            color: var(--text-color);
        }

        [data-theme="dark"] .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
        }

        [data-theme="dark"] .search-input {
            background-color: var(--input-bg);
            border-color: var(--input-border);
            color: var(--input-text);
        }

        [data-theme="dark"] .card-subtitle {
            color: var(--text-color);
        }

        /* Existing styles continue below */
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
            background-color: #fff;
            margin: 0 20px 20px;
            border-radius: 12px;
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

        /* Main content styles */
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
            margin-bottom: 20px;
        }

        /* Stats cards */
        .stat-card {
            padding: 20px;
            border-radius: 15px;
            color: white;
            height: 100%;
        }

        .stat-icon {
            font-size: 2rem;
            opacity: 0.8;
        }

        /* Table styles */
        .table {
            color: var(--table-cell-text);
            background-color: var(--table-bg);
            margin-bottom: 0;
        }

        .table thead th {
            background-color: var(--table-header-bg);
            color: var(--table-header-text);
            border-bottom: 2px solid var(--border-color);
            padding: 15px;
            font-weight: 600;
        }

        .table tbody tr {
            border-bottom: 1px solid var(--border-color);
        }

        .table td {
            padding: 15px;
            vertical-align: middle;
            border-color: var(--border-color);
            color: var(--text-color) !important;
            background-color: transparent;
        }

        .table tbody tr:hover {
            background-color: var(--hover-bg);
        }

        .avatar-sm {
            width: 40px;
            height: 40px;
            background: linear-gradient(45deg, #4e73df 0%, #224abe 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white !important;
            font-weight: 600;
            margin-right: 12px;
        }

        .customer-name {
            color: var(--text-color);
            font-weight: 500;
        }

        .customer-code {
            color: var(--muted-text);
            font-size: 0.875rem;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .action-buttons .btn {
            padding: 6px;
            line-height: 1;
            border-radius: 6px;
        }

        .action-buttons .btn i {
            font-size: 1rem;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .status-paid {
            background-color: #1cc88a20;
            color: #1cc88a;
        }

        .status-pending {
            background-color: #f6c23e20;
            color: #f6c23e;
        }

        .status-overdue {
            background-color: #e74a3b20;
            color: #e74a3b;
        }

        /* Search and filter styles */
        .search-form {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .search-input {
            max-width: 300px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background-color: var(--card-bg);
            color: var(--text-color);
        }

        .filter-section {
            background-color: var(--card-bg);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
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

        /* Modal styles */
        .modal-content {
            background-color: var(--card-bg);
            color: var(--text-color);
        }

        .modal-header, .modal-footer {
            border-color: var(--border-color);
        }

        .modal-title {
            color: var(--text-color);
        }

        .modal-body {
            padding: 1.25rem;
        }

        .customer-header {
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .customer-header .avatar-lg {
            width: 60px !important;
            height: 60px !important;
            font-size: 1.5rem !important;
            margin-bottom: 0.75rem;
        }

        .customer-name {
            color: var(--text-color);
            font-weight: 600;
            font-size: 1.25rem;
            margin: 0.25rem 0;
        }

        .customer-code {
            color: var(--muted-text);
            font-size: 0.813rem;
            margin-bottom: 0;
        }

        .modal-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem !important;
        }

        .card-subtitle {
            color: var(--text-color);
            font-weight: 600;
            font-size: 0.875rem;
            margin-bottom: 1rem;
        }

        .info-section {
            margin-bottom: 0.75rem;
        }

        .info-section:last-child {
            margin-bottom: 0;
        }

        .bill-detail-label {
            color: var(--muted-text);
            font-size: 0.75rem;
            margin-bottom: 0.125rem;
        }

        .bill-detail-value {
            color: var(--text-color);
            font-weight: 500;
            font-size: 0.875rem;
            margin-bottom: 0;
        }

        .payment-summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.375rem;
            font-size: 0.875rem;
        }

        .payment-summary-label {
            color: var(--muted-text);
        }

        .payment-summary-value {
            color: var(--text-color);
            font-weight: 500;
        }

        .payment-total {
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--border-color);
        }

        .total-amount {
            color: var(--hover-text);
            font-size: 1.125rem;
            font-weight: 600;
        }

        .row.g-4 {
            --bs-gutter-x: 1rem;
            --bs-gutter-y: 1rem;
        }

        .modal-footer {
            padding: 0.75rem 1.25rem;
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }

        .status-badge {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
        }

        @media print {
            body * {
                visibility: hidden;
            }
            #viewBillModal, #viewBillModal * {
                visibility: visible;
            }
            #viewBillModal {
                position: absolute;
                left: 0;
                top: 0;
            }
            .modal-footer {
                display: none;
            }
        }
    </style>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar">
    <div class="sidebar-header">
        <img src="icons/Logo.png" alt="Water Billing Logo" class="img-fluid" style="max-height: 90px;" />
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
        <a href="billing_list.php" class="active">
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

    <!-- Theme Switch -->
    <div class="theme-switch-wrapper mt-auto">
        <i class="fas fa-sun"></i>
        <label class="theme-switch">
            <input type="checkbox" id="theme-toggle">
            <span class="slider"></span>
        </label>
        <i class="fas fa-moon"></i>
    </div>

    <form method="POST" action="logout.php" class="mt-3 px-3">
        <button type="submit" class="btn btn-outline-primary w-100">
            <i class="fas fa-sign-out-alt me-2"></i>Logout
        </button>
    </form>
</div>

<!-- Main Content -->
<div class="main-content">
    <!-- Header Section -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">Billing Management</h2>
        <div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBillingModal">
                <i class="fas fa-plus me-2"></i>Create New Bill
            </button>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card card-soft stat-card" style="background: linear-gradient(45deg, #4e73df 0%, #224abe 100%);">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-white-50">Total Revenue</h6>
                        <h3 class="mb-0">₱<?php echo number_format($total_revenue, 2); ?></h3>
                        <small class="text-white-50">This Month</small>
                    </div>
                    <i class="fas fa-dollar-sign stat-icon"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-soft stat-card" style="background: linear-gradient(45deg, #1cc88a 0%, #13855c 100%);">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-white-50">Paid Bills</h6>
                        <h3 class="mb-0"><?php echo $paid_bills; ?></h3>
                        <small class="text-white-50">This Month</small>
                    </div>
                    <i class="fas fa-check-circle stat-icon"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-soft stat-card" style="background: linear-gradient(45deg, #f6c23e 0%, #dda20a 100%);">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-white-50">Pending Bills</h6>
                        <h3 class="mb-0"><?php echo $pending_bills; ?></h3>
                        <small class="text-white-50">Needs Action</small>
                    </div>
                    <i class="fas fa-clock stat-icon"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-soft stat-card" style="background: linear-gradient(45deg, #e74a3b 0%, #be2617 100%);">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-white-50">Overdue Bills</h6>
                        <h3 class="mb-0"><?php echo $overdue_bills; ?></h3>
                        <small class="text-white-50">Past Due Date</small>
                    </div>
                    <i class="fas fa-exclamation-circle stat-icon"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="card card-soft mb-4">
        <div class="card-body">
            <form class="row g-3">
                <div class="col-md-4">
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" class="form-control" placeholder="Search bills..." name="search" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <select class="form-select" name="status">
                        <option value="">All Status</option>
                        <option value="paid">Paid</option>
                        <option value="pending">Pending</option>
                        <option value="overdue">Overdue</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="month" class="form-control" name="billing_month">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Apply Filters</button>
                </div>
                <div class="col-md-2">
                    <button type="reset" class="btn btn-outline-secondary w-100">Reset</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Billing Table -->
    <div class="card card-soft">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Bill #</th>
                            <th>Customer</th>
                            <th>Meter Reading</th>
                            <th>Consumption</th>
                            <th>Amount</th>
                            <th>Due Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result->num_rows > 0): 
                            while($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['bill_id']); ?></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="avatar-sm">
                                            <?php 
                                                $initials = strtoupper(substr($row['firstname'], 0, 1) . substr($row['lastname'], 0, 1));
                                                echo $initials;
                                            ?>
                                        </div>
                                        <div>
                                            <div class="customer-name">
                                                <?php echo htmlspecialchars($row['firstname'] . ' ' . $row['lastname']); ?>
                                            </div>
                                            <div class="customer-code">
                                                <?php echo htmlspecialchars($row['meter_code']); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($row['reading']); ?></td>
                                <td><?php echo htmlspecialchars($row['consumption'] ?? '0'); ?></td>
                                <td>₱<?php echo number_format($row['total'] ?? 0, 2); ?></td>
                                <td><?php echo $row['due_date'] ? date('M d, Y', strtotime($row['due_date'])) : '-'; ?></td>
                                <td>
                                    <?php
                                        $status_class = '';
                                        $status = $row['status'] ?? 'pending';
                                        switch($status) {
                                            case '1':
                                                $status_class = 'status-paid';
                                                $status_text = 'Paid';
                                                break;
                                            case '0':
                                                if (strtotime($row['due_date']) < time()) {
                                                    $status_class = 'status-overdue';
                                                    $status_text = 'Overdue';
                                                } else {
                                                    $status_class = 'status-pending';
                                                    $status_text = 'Pending';
                                                }
                                                break;
                                            default:
                                                $status_class = 'status-pending';
                                                $status_text = 'Pending';
                                        }
                                    ?>
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <?php echo $status_text; ?>
                                    </span>
                                </td>
                                <td class="action-links">
                                    <a href="javascript:void(0)" class="btn btn-sm btn-outline-primary me-1 view-btn" data-id="<?php echo $row['bill_id']; ?>" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="javascript:void(0)" class="btn btn-sm btn-outline-success me-1 edit-btn" data-id="<?php echo $row['bill_id']; ?>" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="javascript:void(0)" class="btn btn-sm btn-outline-danger delete-btn" data-id="<?php echo $row['bill_id']; ?>" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; 
                        else: ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted">No billing records found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add this after the existing billing table card -->
    <div class="card card-soft mt-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h5 class="card-title mb-0">Paid Bills History</h5>
                <div class="d-flex gap-2">
                    <select class="form-select form-select-sm" id="monthFilter" style="width: 150px;">
                        <?php
                        for ($i = 0; $i < 12; $i++) {
                            $date = date('Y-m', strtotime("-$i months"));
                            $formatted = date('F Y', strtotime($date));
                            echo "<option value='$date'" . ($i === 0 ? ' selected' : '') . ">$formatted</option>";
                        }
                        ?>
                    </select>
                    <button class="btn btn-sm btn-outline-primary" id="exportPaidBills">
                        <i class="fas fa-download me-1"></i>Export
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover" id="paidBillsTable">
                    <thead>
                        <tr>
                            <th>Bill #</th>
                            <th>Customer</th>
                            <th>Reading Date</th>
                            <th>Consumption</th>
                            <th>Amount</th>
                            <th>Payment Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Get current month and year
                        $current_month = date('Y-m');
                        
                        // Fetch paid bills for current month
                        $paid_bills_sql = "SELECT b.*, c.firstname, c.lastname, c.meter_code 
                                         FROM billing_list b 
                                         LEFT JOIN client_list c ON b.client_id = c.id 
                                         WHERE b.status = 1 
                                         AND DATE_FORMAT(b.reading_date, '%Y-%m') = ?
                                         ORDER BY b.reading_date DESC";
                        
                        $stmt = $conn->prepare($paid_bills_sql);
                        $stmt->bind_param("s", $current_month);
                        $stmt->execute();
                        $paid_result = $stmt->get_result();

                        if ($paid_result->num_rows > 0):
                            while($row = $paid_result->fetch_assoc()):
                                $consumption = $row['reading'] - $row['previous'];
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['id']); ?></td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="avatar-sm bg-success rounded-circle text-white me-2 d-flex align-items-center justify-content-center">
                                        <?php 
                                            $initials = strtoupper(substr($row['firstname'], 0, 1) . substr($row['lastname'], 0, 1));
                                            echo $initials;
                                        ?>
                                    </div>
                                    <div>
                                        <div class="customer-name">
                                            <?php echo htmlspecialchars($row['firstname'] . ' ' . $row['lastname']); ?>
                                        </div>
                                        <div class="customer-code">
                                            <?php echo htmlspecialchars($row['meter_code']); ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($row['reading_date'])); ?></td>
                            <td><?php echo number_format($consumption, 2); ?></td>
                            <td>₱<?php echo number_format($row['total'], 2); ?></td>
                            <td><?php echo date('M d, Y', strtotime($row['updated_at'] ?? $row['reading_date'])); ?></td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary view-receipt" data-id="<?php echo $row['id']; ?>" title="View Receipt">
                                    <i class="fas fa-receipt"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-secondary print-receipt" data-id="<?php echo $row['id']; ?>" title="Print Receipt">
                                    <i class="fas fa-print"></i>
                                </button>
                            </td>
                        </tr>
                        <?php 
                            endwhile;
                        else:
                        ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">No paid bills found for this month</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Billing Modal -->
<div class="modal fade" id="addBillingModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create New Bill</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="addBillingForm">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Customer</label>
                            <select class="form-select" required>
                                <option value="">Select Customer</option>
                                <?php while($client = $result_clients->fetch_assoc()): ?>
                                    <option value="<?php echo $client['id']; ?>">
                                        <?php echo htmlspecialchars($client['firstname'] . ' ' . $client['lastname']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Billing Period</label>
                            <input type="month" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Previous Reading</label>
                            <input type="number" class="form-control" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Current Reading</label>
                            <input type="number" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Due Date</label>
                            <input type="date" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Rate (per m3)</label>
                            <input type="number" class="form-control" step="0.01" required>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="addBillingForm" class="btn btn-primary">Create Bill</button>
            </div>
        </div>
    </div>
</div>

<!-- View Bill Modal -->
<div class="modal fade" id="viewBillModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title">Billing Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="customer-header">
                    <div class="avatar-lg bg-primary rounded-circle text-white d-inline-flex align-items-center justify-content-center">
                        <span id="viewCustomerInitials"></span>
                    </div>
                    <h4 class="customer-name" id="viewCustomerName"></h4>
                    <p class="customer-code" id="viewMeterCode"></p>
                </div>

                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="modal-card">
                            <h6 class="card-subtitle">Billing Information</h6>
                            <div class="info-section">
                                <div class="bill-detail-label">Bill Number</div>
                                <div class="bill-detail-value" id="viewBillNumber"></div>
                            </div>
                            <div class="info-section">
                                <div class="bill-detail-label">Reading Date</div>
                                <div class="bill-detail-value" id="viewReadingDate"></div>
                            </div>
                            <div class="info-section">
                                <div class="bill-detail-label">Due Date</div>
                                <div class="bill-detail-value" id="viewDueDate"></div>
                            </div>
                            <div class="info-section">
                                <div class="bill-detail-label">Status</div>
                                <div class="bill-detail-value">
                                    <span id="viewStatus" class="status-badge"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="modal-card">
                            <h6 class="card-subtitle">Consumption Details</h6>
                            <div class="info-section">
                                <div class="bill-detail-label">Previous Reading</div>
                                <div class="bill-detail-value" id="viewPreviousReading"></div>
                            </div>
                            <div class="info-section">
                                <div class="bill-detail-label">Current Reading</div>
                                <div class="bill-detail-value" id="viewCurrentReading"></div>
                            </div>
                            <div class="info-section">
                                <div class="bill-detail-label">Consumption</div>
                                <div class="bill-detail-value" id="viewConsumption"></div>
                            </div>
                            <div class="info-section">
                                <div class="bill-detail-label">Rate</div>
                                <div class="bill-detail-value" id="viewRate"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-card mt-3">
                    <h6 class="card-subtitle">Payment Summary</h6>
                    <div class="payment-summary-row">
                        <span class="payment-summary-label">Base Charge (First 6 m3)</span>
                        <span class="payment-summary-value" id="viewBaseCharge"></span>
                    </div>
                    <div class="payment-summary-row">
                        <span class="payment-summary-label">Excess Charge</span>
                        <span class="payment-summary-value" id="viewExcessCharge"></span>
                    </div>
                    <div class="payment-summary-row payment-total">
                        <span class="payment-summary-label">Total Amount</span>
                        <span class="total-amount" id="viewTotalAmount"></span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-sm btn-success" id="markAsPaidBtn">Mark as Paid</button>
                <button type="button" class="btn btn-sm btn-primary" id="printBillBtn">
                    <i class="fas fa-print me-1"></i>Print
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Add this modal for editing billing -->
<div class="modal fade" id="editBillModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title">Edit Billing Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editBillForm" method="POST">
                <div class="modal-body">
                    <input type="hidden" id="editBillId" name="bill_id">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <div class="modal-card">
                                <h6 class="card-subtitle">Billing Information</h6>
                                <div class="mb-3">
                                    <label class="bill-detail-label">Reading Date</label>
                                    <input type="date" class="form-control form-control-sm" id="editReadingDate" name="reading_date" required>
                                </div>
                                <div class="mb-3">
                                    <label class="bill-detail-label">Due Date</label>
                                    <input type="date" class="form-control form-control-sm" id="editDueDate" name="due_date" required>
                                </div>
                                <div class="mb-3">
                                    <label class="bill-detail-label">Status</label>
                                    <select class="form-select form-select-sm" id="editStatus" name="status">
                                        <option value="0">Pending</option>
                                        <option value="1">Paid</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="modal-card">
                                <h6 class="card-subtitle">Consumption Details</h6>
                                <div class="mb-3">
                                    <label class="bill-detail-label">Previous Reading</label>
                                    <input type="number" class="form-control form-control-sm" id="editPreviousReading" name="previous" step="0.01" required>
                                </div>
                                <div class="mb-3">
                                    <label class="bill-detail-label">Current Reading</label>
                                    <input type="number" class="form-control form-control-sm" id="editCurrentReading" name="reading" step="0.01" required>
                                </div>
                                <div class="mb-3">
                                    <label class="bill-detail-label">Rate (per m3)</label>
                                    <input type="number" class="form-control form-control-sm" id="editRate" name="rate" step="0.01" required>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-card mt-3">
                        <h6 class="card-subtitle">Calculated Totals</h6>
                        <div class="payment-summary-row">
                            <span class="payment-summary-label">Consumption</span>
                            <span class="payment-summary-value" id="editConsumption">0</span>
                        </div>
                        <div class="payment-summary-row">
                            <span class="payment-summary-label">Base Charge (First 6 m3)</span>
                            <span class="payment-summary-value" id="editBaseCharge">₱0.00</span>
                        </div>
                        <div class="payment-summary-row">
                            <span class="payment-summary-label">Excess Charge</span>
                            <span class="payment-summary-value" id="editExcessCharge">₱0.00</span>
                        </div>
                        <div class="payment-summary-row payment-total">
                            <span class="payment-summary-label">Total Amount</span>
                            <span class="total-amount" id="editTotalAmount">₱0.00</span>
                            <input type="hidden" id="editTotalInput" name="total">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary" name="update_bill">
                        <i class="fas fa-save me-1"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Notification System -->
<script src="assets/js/notifications.js"></script>
<!-- Custom JS -->
<script src="billing.js"></script>

</body>
</html>
