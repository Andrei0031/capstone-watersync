<?php
// Start session FIRST before any output
session_start();

// Enable error display for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'session_validation.php';
validateSession();

include 'db.php';
include 'comprehensive_fee_manager.php';
include 'timezone_helper.php';
watersync_force_timezone($conn);

// Get customer information
$stmt = $conn->prepare("SELECT cl.*, ca.email 
                       FROM client_list cl 
                       JOIN customer_accounts ca ON cl.id = ca.client_id 
                       WHERE ca.id = ?");
$stmt->bind_param("i", $_SESSION['customer_id']);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();

// Calculate total amount due and balances
$total_amount_due = 0;
$overdue_balance = 0;
$current_balance = 0;

// Get all bills with payment information
$balance_sql = "WITH PaymentTotals AS (
    SELECT 
        billing_id,
        COALESCE(SUM(amount), 0) as total_paid
    FROM payment_list
    WHERE client_id = ?
    GROUP BY billing_id
)
SELECT 
    b.*,
    COALESCE(pt.total_paid, 0) as amount_paid,
    COALESCE(b.total - COALESCE(pt.total_paid, 0), b.total) as remaining_balance,
    CASE 
        WHEN b.status = 1 THEN 'Paid'
        WHEN b.status = 0 AND b.due_date < CURRENT_DATE THEN 'Overdue'
        ELSE 'Pending'
    END as status_text
FROM billing_list b
LEFT JOIN PaymentTotals pt ON b.id = pt.billing_id
WHERE b.client_id = ? 
ORDER BY b.reading_date DESC
LIMIT 5";

$stmt = $conn->prepare($balance_sql);
$stmt->bind_param("ii", $_SESSION['client_id'], $_SESSION['client_id']);
$stmt->execute();
$bills = $stmt->get_result();

// Calculate balances from unpaid bills
while ($bill = $bills->fetch_assoc()) {
    $remaining = $bill['remaining_balance'];
    if ($bill['status'] == 0 && $remaining > 0) {
        $total_amount_due += $remaining;
        if (strtotime($bill['due_date']) < time()) {
            $overdue_balance += $remaining;
        } else {
            $current_balance += $remaining;
        }
    }
}

// Reset the result for later use
$stmt->execute();
$bills = $stmt->get_result();

// Get total billing records count
$total_bills_query = "SELECT COUNT(*) as total_bills FROM billing_list WHERE client_id = ?";
$stmt_total = $conn->prepare($total_bills_query);
$stmt_total->bind_param("i", $_SESSION['client_id']);
$stmt_total->execute();
$total_bills_result = $stmt_total->get_result()->fetch_assoc();
$total_bills_count = $total_bills_result['total_bills'];

// Get payment history
$stmt = $conn->prepare("SELECT p.*, 
                              CASE WHEN p.status = 1 THEN 'Paid' ELSE 'Pending' END as status_text
                       FROM payment_list p
                       WHERE p.client_id = ?
                       ORDER BY p.payment_date DESC");
$stmt->bind_param("i", $_SESSION['client_id']);
$stmt->execute();
$payments = $stmt->get_result();

// Get notification count for badge
$notification_count = 0;
// Count active notices
$notices_count_query = "
    SELECT COUNT(*) as count 
    FROM notices 
    WHERE (status = 'ongoing' OR 
          (status = 'scheduled' AND start_date <= DATE_ADD(NOW(), INTERVAL 24 HOUR)) OR
          (status = 'completed' AND end_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)))
    AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
";
$notices_count_result = $conn->query($notices_count_query);
if ($notices_count_result) {
    $notification_count += $notices_count_result->fetch_assoc()['count'];
}

// Count unacknowledged disconnection notices
$disconnection_count_query = "
    SELECT COUNT(*) as count 
    FROM disconnection_notices 
    WHERE client_id = ? 
    AND status IN ('pending', 'sent')
";
$stmt_count = $conn->prepare($disconnection_count_query);
$stmt_count->bind_param("i", $_SESSION['client_id']);
$stmt_count->execute();
$disconnection_count_result = $stmt_count->get_result();
if ($disconnection_count_result) {
    $notification_count += $disconnection_count_result->fetch_assoc()['count'];
}

// Get recent disconnection notices for the customer (exclude resolved notices)
$notices_sql = "SELECT * FROM disconnection_notices 
                WHERE client_id = ? 
                AND status != 'resolved'
                ORDER BY created_at DESC 
                LIMIT 3";
$stmt = $conn->prepare($notices_sql);
$stmt->bind_param("i", $_SESSION['client_id']);
$stmt->execute();
$disconnection_notices = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Dashboard - Water Billing System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2196F3;
            --secondary-color: #0D47A1;
            --accent-color: #E3F2FD;
            --success-color: #4CAF50;
            --warning-color: #FFC107;
            --danger-color: #f44336;
        }

        body {
            background: #f8f9fa;
            min-height: 100vh;
        }

        .navbar {
            background: linear-gradient(45deg, var(--secondary-color), var(--primary-color)) !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        /* Ensure navbar has proper styling */
        nav.navbar.navbar-expand-lg.navbar-dark {
            background: linear-gradient(45deg, #0D47A1, #2196F3) !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1) !important;
        }

        .navbar-dark .navbar-nav .nav-link {
            color: rgba(255,255,255,.8) !important;
        }

        .navbar-dark .navbar-nav .nav-link:hover {
            color: rgba(255,255,255,1) !important;
        }

        .navbar-dark .navbar-brand {
            color: rgba(255,255,255,1) !important;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .navbar-brand {
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .navbar-brand img {
            height: 40px;
            filter: brightness(0) invert(1);
        }

        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            transition: transform 0.2s;
        }

        .card:hover {
            transform: translateY(-5px);
        }

        .card-header {
            background: white;
            border-bottom: 1px solid rgba(0,0,0,0.1);
            padding: 20px;
            border-radius: 15px 15px 0 0;
        }

        .card-title {
            color: var(--secondary-color);
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-body {
            padding: 20px;
        }

        .info-card {
            background: linear-gradient(45deg, var(--secondary-color), var(--primary-color));
            color: white;
        }

        .info-card .card-title {
            color: white;
        }

        .info-table {
            color: white;
        }

        .info-table th {
            font-weight: 500;
            opacity: 0.9;
            padding: 12px 0;
        }

        .info-table td {
            font-weight: 600;
            padding: 12px 0;
        }

        .table {
            margin-bottom: 0;
        }

        .table thead th {
            border-bottom: 2px solid rgba(0,0,0,0.1);
            font-weight: 600;
            color: var(--secondary-color);
        }

        .table td {
            vertical-align: middle;
        }

        .badge {
            padding: 8px 12px;
            border-radius: 8px;
            font-weight: 500;
        }

        .badge.bg-success {
            background-color: var(--success-color) !important;
        }

        .badge.bg-warning {
            background-color: var(--warning-color) !important;
            color: #000;
        }

        .badge.bg-danger {
            background-color: var(--danger-color) !important;
        }

        .amount {
            font-weight: 600;
            color: var(--secondary-color);
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 8px;
            color: white !important;
            font-weight: 500;
            padding: 8px 16px;
            border-radius: 8px;
            transition: all 0.3s;
        }

        .nav-link:hover {
            background: rgba(255,255,255,0.1);
        }

        .welcome-section {
            background: white;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .welcome-text {
            color: var(--secondary-color);
            margin: 0;
        }

        .notice-icon {
            font-size: 2.5rem;
            opacity: 0.2;
            position: absolute;
            right: 1rem;
            top: 1rem;
        }

        .notice-card {
            transition: transform 0.2s;
        }

        .notice-card:hover {
            transform: translateY(-5px);
        }

        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            .card {
                margin-bottom: 15px;
            }
            
            /* Ensure proper spacing on mobile */
            .row {
                margin-left: -5px;
                margin-right: -5px;
            }
            
            .row > [class*="col-"] {
                padding-left: 5px;
                padding-right: 5px;
            }
            
            /* Make list group items stack better on mobile */
            .list-group-item {
                flex-wrap: wrap;
            }
            
            .list-group-item span,
            .list-group-item strong {
                word-break: break-word;
            }
            
            /* Adjust card margins on mobile */
            [style*="margin: 20px"] {
                margin: 10px !important;
            }
            
            /* Better text wrapping */
            h3, h4, h5, h6 {
                word-wrap: break-word;
            }
            
            /* Make flex items stack on very small screens */
            .d-flex.justify-content-between {
                flex-wrap: wrap;
            }
            
            /* Balance cards - adjust layout on mobile */
            .d-flex.justify-content-between.align-items-center {
                flex-wrap: wrap;
            }
            
            /* Smaller icons on mobile for balance cards */
            .d-flex.justify-content-between.align-items-center .fa-2x {
                font-size: 1.5em !important;
            }
        }

        /* Basic tab styling that works */
        .tab-content {
            min-height: 500px;
            background-color: #fff;
        }

        /* Basic tab functionality */
        .tab-pane {
            display: none;
        }
        
        .tab-pane.show.active {
            display: block;
        }

        /* Force no spacing anywhere in tabs */
        .tab-content {
            padding: 0 !important;
            margin: 0 !important;
            background: transparent !important;
        }
        
        .tab-pane {
            padding: 0 !important;
            margin: 0 !important;
        }

        /* Clean Water Reports - matches other tabs */
        #nav-reports {
            margin: 0;
            padding: 0;
        }

        /* Smooth transitions for responsive design */
        .nav-tabs {
            transition: all 0.3s ease;
        }
        
        .nav-tabs .tab-button {
            transition: all 0.3s ease;
            border-radius: 8px 8px 0 0 !important;
        }

        /* Center alignment for tab container */
        .nav-tabs {
            justify-content: center;
        }

        /* Enhanced form styling */
        #water-reports-form .form-select {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 10px 15px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background-color: #fafafa;
        }

        #water-reports-form .form-select:focus {
            border-color: #ff9800;
            box-shadow: 0 0 0 0.2rem rgba(255, 152, 0, 0.15);
            background-color: white;
        }

        #water-reports-form .form-label {
            font-weight: 600;
            color: #424242;
            margin-bottom: 8px;
        }

        #water-reports-form .text-muted {
            color: #757575 !important;
            font-size: 0.85rem;
        }

        /* Responsive Tab Navigation */
        @media (max-width: 992px) {
            .nav-tabs .tab-button {
                min-width: 120px !important;
                max-width: 180px !important;
                padding: 10px 15px !important;
                font-size: 0.9rem !important;
            }
        }

        @media (max-width: 768px) {
            /* Mobile Tab Navigation */
            .nav-tabs {
                flex-direction: column !important;
                gap: 3px !important;
                align-items: center !important;
            }
            
            .nav-tabs .tab-button {
                width: 90% !important;
                max-width: 300px !important;
                min-width: 200px !important;
                flex: none !important;
                margin: 0 !important;
                text-align: center !important;
                padding: 12px 20px !important;
                font-size: 0.95rem !important;
            }
            
            /* Water Reports Form Mobile */
            #water-reports-form {
                margin: 10px !important;
                border-radius: 8px !important;
            }
            
            #water-reports-form .col-md-6 {
                margin-bottom: 20px;
            }
            
            #water-reports-form button {
                width: 100%;
                margin-top: 10px;
            }
            
            /* Make all cards responsive on mobile */
            .tab-pane [style*="margin: 20px"] {
                margin: 10px !important;
            }
            
            .tab-pane [style*="padding: 25px"] {
                padding: 20px !important;
            }
        }

        @media (max-width: 576px) {
            /* Small Mobile Screens */
            .nav-tabs .tab-button {
                width: 95% !important;
                padding: 10px 15px !important;
                font-size: 0.85rem !important;
            }
            
            .nav-tabs .tab-button i {
                display: none !important;
            }
            
            /* Ensure all cards stack properly on mobile */
            .row > [class*="col-"] {
                margin-bottom: 15px;
            }
            
            /* Balance summary cards - full width on mobile */
            .balance-card {
                margin-bottom: 15px !important;
            }
            
            /* Reduce padding on mobile */
            [style*="padding: 25px"] {
                padding: 15px !important;
            }
            
            /* Make icons smaller on mobile */
            .fa-2x {
                font-size: 1.5em !important;
            }
            
            /* Adjust text sizes for mobile */
            h3 {
                font-size: 1.5rem !important;
            }
            
            h5 {
                font-size: 1.1rem !important;
            }
            
            /* Ensure proper spacing */
            .mb-4 {
                margin-bottom: 1rem !important;
            }
            
            .m-3 {
                margin: 0.75rem !important;
            }
        }

        /* Enhance table styling within modern cards */
        .table-responsive .table th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: #495057;
            border-top: none;
        }

        .table-responsive .table td {
            vertical-align: middle;
        }

        /* Modern badge styling */
        .badge {
            font-size: 0.75rem;
            font-weight: 500;
            padding: 0.5em 0.8em;
        }

        .tab-pane {
            animation: fadeIn 0.3s ease-in-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .avatar-circle {
            width: 80px;
            height: 80px;
            background-color: #2196F3;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: white;
            margin: 0 auto;
        }

        .list-group-item {
            border: none;
            padding: 0.75rem 0;
        }

        .list-group-item:not(:last-child) {
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }

        /* Profile Modal Styles */
        .profile-modal .modal-content {
            border: none;
            border-radius: 15px;
        }

        .profile-modal .modal-header {
            background: linear-gradient(45deg, var(--secondary-color), var(--primary-color));
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 20px;
        }

        .profile-modal .modal-title {
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .profile-modal .avatar-circle {
            width: 100px;
            height: 100px;
            background: linear-gradient(45deg, var(--primary-color), var(--secondary-color));
            font-size: 2.5rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .profile-modal .list-group-item {
            padding: 15px 0;
            border: none;
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }

        .profile-modal .list-group-item:last-child {
            border-bottom: none;
        }

        .profile-modal .info-label {
            color: var(--secondary-color);
            font-weight: 500;
        }

        .profile-modal .info-value {
            font-weight: 500;
        }

        /* Edit Profile Modal Styles */
        .edit-profile-modal .form-label {
            color: var(--secondary-color);
            font-weight: 500;
            margin-bottom: 8px;
        }

        .edit-profile-modal .form-control {
            border-radius: 8px;
            padding: 12px;
            border: 1px solid rgba(0,0,0,0.1);
            transition: all 0.3s;
        }

        .edit-profile-modal .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(33, 150, 243, 0.25);
        }

        /* Password Modal Styles */
        .password-modal .password-requirements {
            font-size: 0.9rem;
            color: #666;
            margin-top: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .password-modal .requirement-item {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
        }

        .password-modal .requirement-item i {
            color: var(--primary-color);
        }

        /* Modal Button Styles */
        .modal-btn {
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s;
        }

        .modal-btn-primary {
            background: linear-gradient(45deg, var(--secondary-color), var(--primary-color));
            border: none;
            color: white;
        }

        .modal-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }

        .modal-btn-secondary {
            background: #f8f9fa;
            border: 1px solid rgba(0,0,0,0.1);
            color: #666;
        }

        .modal-btn-secondary:hover {
            background: #e9ecef;
        }

        @media (max-width: 768px) {
            .modal {
                padding-right: 0 !important;
            }
            
            .modal-dialog {
                margin: 0.5rem;
                max-width: calc(100% - 1rem);
            }

            .modal-content {
                border-radius: 15px;
            }

            .list-group-item {
                flex-direction: column;
                align-items: flex-start !important;
            }

            .list-group-item .info-value {
                margin-top: 0.25rem;
                word-break: break-word;
            }

            .d-flex.justify-content-center.gap-3 {
                flex-direction: column;
                gap: 0.5rem !important;
            }

            .modal-btn {
                width: 100%;
            }

            .profile-modal .modal-header {
                padding: 1rem;
            }

            .profile-modal .modal-body {
                padding: 1rem !important;
            }

            .profile-modal .avatar-circle {
                width: 80px;
                height: 80px;
                font-size: 2rem;
            }
        }

        /* Fix modal stacking issues */
        .modal-backdrop {
            z-index: 1040;
        }

        .modal {
            z-index: 1045;
        }

        .modal-dialog {
            z-index: 1050;
        }
        @keyframes pulse {
            0%, 100% {
                opacity: 1;
                transform: scale(1);
            }
            50% {
                opacity: 0.8;
                transform: scale(1.1);
            }
        }
        .notification-badge {
            animation: pulse 2s infinite;
            box-shadow: 0 2px 4px rgba(220, 53, 69, 0.4);
        }
        .notification-bubble {
            animation: pulse 2s infinite;
            box-shadow: 0 2px 4px rgba(220, 53, 69, 0.4);
            font-weight: 600;
            line-height: 1;
        }

        /* Minimal pill-style dashboard tabs (like mobile bottom nav) */
        .dashboard-tabs-container {
            background: #ffffff;
            border-radius: 999px;
            padding: 10px 18px;
            margin-bottom: 16px;
            box-shadow: 0 18px 45px rgba(15, 23, 42, 0.12);
        }

        .dashboard-tabs-container .nav-tabs {
            border-bottom: none;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .nav-tabs .dashboard-tab-button {
            position: relative;
            border: none;
            background: transparent !important;
            color: #0D47A1 !important; /* deep blue for maximum readability */
            font-weight: 600 !important;
            padding: 8px 16px !important;
            min-width: 90px;
            text-align: center !important;
        }

        .nav-tabs .dashboard-tab-button .tab-icon {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 4px;
            font-size: 1.25rem;
        }

        .nav-tabs .dashboard-tab-button .tab-label {
            display: block;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .nav-tabs .dashboard-tab-button .tab-icon i {
            color: #0D47A1 !important;
        }

        .nav-tabs .dashboard-tab-button.active {
            color: #e91e63 !important; /* pink accent for active tab */
        }

        .nav-tabs .dashboard-tab-button.active .tab-icon i {
            color: #e91e63 !important;
        }

        @media (max-width: 576px) {
            .dashboard-tabs-container {
                padding: 8px 14px;
            }
            .nav-tabs .dashboard-tab-button {
                padding: 6px 8px !important;
                min-width: 70px;
            }
            .nav-tabs .dashboard-tab-button .tab-icon {
                font-size: 1.1rem;
            }
            .nav-tabs .dashboard-tab-button .tab-label {
                font-size: 0.75rem;
            }
        }

        /* Enhanced top navbar for customer dashboard */
        .customer-dashboard-nav {
            background: linear-gradient(90deg, #0D47A1, #2196F3);
            box-shadow: 0 6px 18px rgba(13, 71, 161, 0.45);
        }

        .customer-dashboard-nav .navbar-brand img {
            height: 38px;
            margin-right: 10px;
        }

        .customer-dashboard-nav .navbar-brand {
            font-weight: 700;
            letter-spacing: 0.03em;
        }

        .customer-dashboard-nav .nav-link {
            position: relative;
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 0.35rem 0.9rem;
            border-radius: 999px;
            font-size: 0.9rem;
            font-weight: 500;
            color: rgba(255, 255, 255, 0.86);
            transition: background-color 0.2s ease-out, color 0.2s ease-out, transform 0.15s ease-out;
        }

        .customer-dashboard-nav .nav-link i {
            font-size: 0.95rem;
        }

        .customer-dashboard-nav .nav-link:hover {
            background-color: rgba(255, 255, 255, 0.14);
            color: #ffffff;
            transform: translateY(-1px);
        }

        .customer-dashboard-nav .nav-link.active {
            background-color: #ffffff;
            color: #0D47A1 !important;
            box-shadow: 0 4px 12px rgba(13, 71, 161, 0.35);
        }

        .customer-dashboard-nav .nav-link.active i {
            color: #0D47A1 !important;
        }

        /* Compact notices tab layout */
        #nav-notifications .card {
            border-radius: 12px;
            margin-bottom: 12px;
        }
        #nav-notifications .card-header {
            padding: 12px 16px;
        }
        #nav-notifications .card-body {
            padding: 14px 16px;
        }
        #nav-notifications .table th,
        #nav-notifications .table td {
            padding: 0.55rem 0.65rem;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark mb-4 customer-dashboard-nav">
        <div class="container">
            <a class="navbar-brand" href="customer_dashboard.php">
                <img src="icons/Logo.png" alt="WaterSync Logo">
                WaterSync
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="customer_dashboard.php">
                            <i class="fas fa-home"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="client_notices.php" style="position: relative;">
                            <i class="fas fa-bell"></i> Notices
                            <span id="navbar-notification-badge" class="badge bg-danger" style="display: none; position: absolute; top: -4px; right: -8px; font-size: 0.65rem; border-radius: 10px; padding: 2px 6px;"></span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#" data-bs-toggle="modal" data-bs-target="#profileModal">
                            <i class="fas fa-user-circle"></i> Profile
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="customer_logout.php">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Profile Modal -->
    <div class="modal fade profile-modal" id="profileModal" tabindex="-1" aria-labelledby="profileModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="profileModalLabel">
                        <i class="fas fa-user-circle"></i>
                        Profile Information
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="text-center mb-4">
                        <div class="avatar-circle mb-3 mx-auto">
                            <?php 
                                $initials = strtoupper(substr($customer['firstname'], 0, 1) . substr($customer['lastname'], 0, 1));
                                echo $initials;
                            ?>
                        </div>
                        <h4 class="mb-1"><?php echo htmlspecialchars($customer['firstname'] . ' ' . $customer['lastname']); ?></h4>
                        <p class="text-muted mb-0">
                            <i class="fas fa-envelope me-2"></i>
                            <?php echo htmlspecialchars($customer['email']); ?>
                        </p>
                    </div>
                    
                    <div class="list-group list-group-flush mb-4">
                        <div class="list-group-item d-flex justify-content-between align-items-center py-3">
                            <span class="info-label"><i class="fas fa-phone me-2"></i>Contact Number</span>
                            <span class="info-value"><?php echo htmlspecialchars($customer['contact']); ?></span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center py-3">
                            <span class="info-label"><i class="fas fa-map-marker-alt me-2"></i>Address</span>
                            <span class="info-value"><?php echo htmlspecialchars($customer['address']); ?></span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center py-3">
                            <span class="info-label"><i class="fas fa-tachometer-alt me-2"></i>Meter Code</span>
                            <span class="info-value"><?php echo htmlspecialchars($customer['meter_code']); ?></span>
                        </div>
                    </div>

                    <div class="d-flex justify-content-center gap-3">
                        <button type="button" class="modal-btn modal-btn-primary" data-bs-toggle="modal" data-bs-target="#editProfileModal">
                            <i class="fas fa-edit me-2"></i>Edit Profile
                        </button>
                        <button type="button" class="modal-btn modal-btn-secondary" data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                            <i class="fas fa-key me-2"></i>Change Password
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container" style="padding: 0 !important; margin-top: 0 !important;">
        <div class="welcome-section">
            <h4 class="welcome-text">
                <i class="fas fa-user-circle me-2"></i>
                Welcome back, <?php echo htmlspecialchars($customer['firstname']); ?>!
            </h4>
        </div>

        <!-- Tab Navigation -->
        <div class="dashboard-tabs-container">
            <nav>
                <div class="nav nav-tabs w-100" id="nav-tab" role="tablist">
                        <button class="nav-link tab-button dashboard-tab-button active" id="nav-overview-tab" data-bs-toggle="tab" data-bs-target="#nav-overview" type="button" role="tab" aria-controls="nav-overview" aria-selected="true">
                            <span class="tab-icon">
                                <i class="fas fa-tachometer-alt"></i>
                            </span>
                            <span class="tab-label">Dashboard Overview</span>
                        </button>
                        <button class="nav-link tab-button dashboard-tab-button" id="nav-notifications-tab" data-bs-toggle="tab" data-bs-target="#nav-notifications" type="button" role="tab" aria-controls="nav-notifications" aria-selected="false" style="position: relative;">
                            <span class="badge bg-danger notification-badge" style="position: absolute; top: -5px; right: 12px; font-size: 0.65rem; padding: 2px 6px; border-radius: 10px; display: none;"></span>
                            <span class="tab-icon">
                                <i class="fas fa-bell"></i>
                            </span>
                            <span class="tab-label">Notices</span>
                        </button>
                        <button class="nav-link tab-button dashboard-tab-button" id="nav-billing-tab" data-bs-toggle="tab" data-bs-target="#nav-billing" type="button" role="tab" aria-controls="nav-billing" aria-selected="false">
                            <span class="tab-icon">
                                <i class="fas fa-file-invoice-dollar"></i>
                            </span>
                            <span class="tab-label">Billing Information</span>
                        </button>
                        <button class="nav-link tab-button dashboard-tab-button" id="nav-reports-tab" data-bs-toggle="tab" data-bs-target="#nav-reports" type="button" role="tab" aria-controls="nav-reports" aria-selected="false">
                            <span class="tab-icon">
                                <i class="fas fa-exclamation-triangle"></i>
                            </span>
                            <span class="tab-label">Water Reports</span>
                        </button>
                </div>
            </nav>
        </div>
        
        <!-- Water Reports Form - Positioned at Top (Only visible when Water Reports tab is active) -->
        <div id="water-reports-form" style="display: none; margin: 20px; padding: 0; background: white; border: 1px solid #e0e0e0; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); overflow: hidden;">
            <!-- Form Header -->
            <div style="background: linear-gradient(135deg, #ff9800, #f57c00); padding: 20px; border-bottom: 1px solid #e0e0e0;">
                <h5 style="margin: 0; color: white; font-weight: 600;">
                    <i class="fas fa-exclamation-triangle me-2" style="color: white;"></i>Report Water Outage
                </h5>
                <p style="margin: 5px 0 0 0; color: rgba(255,255,255,0.9); font-size: 0.9rem;">Submit your water service issues here for immediate assistance</p>
            </div>
            <!-- Form Body -->
            <div style="padding: 25px;">
                <form id="outageReportForm">
                <div class="row">
                    <div class="col-12 col-md-6">
                        <div class="mb-3">
                            <label for="outageLocation" class="form-label">Location <span class="text-danger">*</span></label>
                            <select class="form-select" id="outageLocation" name="location" required>
                                <option value="">Select your area</option>
                                <option value="Purok 1-A">Purok 1-A</option>
                                <option value="Purok 1-B">Purok 1-B</option>
                                <option value="Purok 1-C">Purok 1-C</option>
                                <option value="Purok 2">Purok 2</option>
                                <option value="Purok 3">Purok 3</option>
                                <option value="Purok 4">Purok 4</option>
                                <option value="Purok 5">Purok 5</option>
                            </select>
                            <small class="text-muted">Select the area where water outage is occurring</small>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="mb-3">
                            <label for="outageDescription" class="form-label">Type of Water Issue <span class="text-danger">*</span></label>
                            <select class="form-select" id="outageDescription" name="description" required>
                                <option value="">Select the type of water issue</option>
                                <optgroup label="Water Supply Issues">
                                    <option value="No water supply for more than 2 hours">No water supply for more than 2 hours (Wala'y tubig sulod sa 2 ka oras)</option>
                                    <option value="Complete water outage - no water at all">Complete water outage - no water at all (Tanan wala'y tubig)</option>
                                    <option value="Intermittent water supply - on and off">Intermittent water supply - on and off (Intermittent nga tubig - naa ug wala)</option>
                                </optgroup>
                                <optgroup label="Water Pressure Issues">
                                    <option value="Very low water pressure">Very low water pressure (Ubos kaayo ang pressure sa tubig)</option>
                                    <option value="No water pressure at all">No water pressure at all (Wala'y pressure sa tubig)</option>
                                    <option value="Water pressure only during certain times">Water pressure only during certain times (Pressure sa tubig sa pipila lang ka oras)</option>
                                </optgroup>
                                <optgroup label="Water Quality Issues">
                                    <option value="Water quality issues - unusual color">Water quality issues - unusual color (Problema sa kalidad sa tubig - katingad-an nga kolor)</option>
                                    <option value="Water quality issues - bad taste">Water quality issues - bad taste (Problema sa kalidad sa tubig - dili maayo ang lami)</option>
                                    <option value="Water quality issues - unusual odor">Water quality issues - unusual odor (Problema sa kalidad sa tubig - katingad-an nga baho)</option>
                                    <option value="Cloudy or murky water">Cloudy or murky water (Hapon o mabaga ang tubig)</option>
                                </optgroup>
                                <optgroup label="Infrastructure Issues">
                                    <option value="Visible pipe leaks in the area">Visible pipe leaks in the area (Makita nga nag-leak ang tubo sa lugar)</option>
                                    <option value="Burst pipes causing water loss">Burst pipes causing water loss (Nabuak ang tubo nga naka-cause sa pagkawala sa tubig)</option>
                                    <option value="Water meter issues or damage">Water meter issues or damage (Problema o kadaot sa water meter)</option>
                                    <option value="Suspected main line break">Suspected main line break (Gisuspetsahan nga nabuak ang main line)</option>
                                </optgroup>
                            </select>
                            <small class="text-muted">Choose the option that best describes your water issue</small>
                        </div>
                        <button type="submit" style="background: linear-gradient(135deg, #ff9800, #f57c00); border: none; color: white; padding: 12px 25px; border-radius: 8px; font-weight: 600; box-shadow: 0 3px 10px rgba(255,152,0,0.3); transition: all 0.3s ease;" 
                                onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 5px 15px rgba(255,152,0,0.4)'"
                                onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 3px 10px rgba(255,152,0,0.3)'">
                            <i class="fas fa-paper-plane me-2"></i>Submit Report
                        </button>
                    </div>
                    <div class="col-12 col-md-6">
                        <div style="background: linear-gradient(135deg, #e3f2fd, #bbdefb); border: 1px solid #2196f3; border-radius: 8px; padding: 15px; margin-bottom: 15px;">
                            <h6 style="color: #1976d2; margin-bottom: 10px; font-weight: 600;">
                                <i class="fas fa-lightbulb me-2" style="color: #ffc107;"></i>When to Report
                            </h6>
                            <ul style="margin: 0; color: #1565c0; font-size: 0.9rem;">
                                <li>No water supply for more than 2 hours</li>
                                <li>Very low water pressure</li>
                                <li>Water quality issues (color, taste, odor)</li>
                                <li>Visible pipe leaks or burst pipes</li>
                            </ul>
                        </div>
                        <div style="background: linear-gradient(135deg, #fff3e0, #ffe0b2); border: 1px solid #ff9800; border-radius: 8px; padding: 15px;">
                            <h6 style="color: #f57c00; margin-bottom: 10px; font-weight: 600;">
                                <i class="fas fa-clock me-2" style="color: #ff9800;"></i>Response Time
                            </h6>
                            <p style="margin: 0; color: #e65100; font-size: 0.9rem;">Our team typically responds within <strong>2-4 hours</strong> during business hours and within <strong>24 hours</strong> on weekends.</p>
                        </div>
                                         </div>
                 </div>
                 </form>
             </div>
         </div>
         
         <!-- Submitted Outage Reports List - Below the form -->
         <div id="outage-reports-list" style="display: none; margin: 20px; padding: 0; background: white; border: 1px solid #e0e0e0; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); overflow: hidden;">
            <!-- List Header -->
            <div style="background: linear-gradient(135deg, #2196f3, #1976d2); padding: 20px; border-bottom: 1px solid #e0e0e0;">
                <h5 style="margin: 0; color: white; font-weight: 600;">
                    <i class="fas fa-list me-2" style="color: white;"></i>Your Submitted Reports
                </h5>
                <p style="margin: 5px 0 0 0; color: rgba(255,255,255,0.9); font-size: 0.9rem;">View all your water outage reports and their status</p>
            </div>
            <!-- Reports List Body -->
            <div style="padding: 25px;">
                <?php
                // Fetch all reports for the logged-in client
                if (isset($_SESSION['client_id'])) {
                    $client_id = $_SESSION['client_id'];
                    
                    // Check if table exists, if not create it
                    $check_table = $conn->query("SHOW TABLES LIKE 'outage_reports'");
                    if ($check_table->num_rows == 0) {
                        $create_table_sql = "CREATE TABLE IF NOT EXISTS outage_reports (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            client_id INT NOT NULL,
                            location VARCHAR(255) NOT NULL,
                            description TEXT NOT NULL,
                            status TINYINT(1) DEFAULT 0,
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            resolved_at TIMESTAMP NULL,
                            resolution_notes TEXT,
                            FOREIGN KEY (client_id) REFERENCES client_list(id)
                        )";
                        $conn->query($create_table_sql);
                    }
                    
                    // Fetch reports for this client, newest first
                    $reports_query = "SELECT id, location, description, status, created_at, resolved_at, resolution_notes 
                                     FROM outage_reports 
                                     WHERE client_id = ? 
                                     ORDER BY created_at DESC";
                    $stmt = $conn->prepare($reports_query);
                    $stmt->bind_param("i", $client_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $reports = $result->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
                    
                    if (empty($reports)) {
                        echo '<div style="text-align: center; padding: 40px; color: #666;">
                                <i class="fas fa-inbox" style="font-size: 48px; color: #ccc; margin-bottom: 15px;"></i>
                                <p style="margin: 0; font-size: 1.1rem;">No reports submitted yet</p>
                                <p style="margin: 10px 0 0 0; color: #999; font-size: 0.9rem;">Submit a report above to get started</p>
                              </div>';
                    } else {
                        echo '<div style="max-height: 600px; overflow-y: auto;">';
                        foreach ($reports as $report) {
                            $is_resolved = $report['status'] == 1;
                            $status_color = $is_resolved ? '#4caf50' : '#ff9800';
                            $status_text = $is_resolved ? 'Resolved' : 'Pending';
                            $status_icon = $is_resolved ? 'fa-check-circle' : 'fa-clock';
                            
                            $created_date = date('M d, Y g:i A', strtotime($report['created_at']));
                            $resolved_date = $report['resolved_at'] ? date('M d, Y g:i A', strtotime($report['resolved_at'])) : null;
                            
                            echo '<div style="border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; margin-bottom: 15px; background: ' . ($is_resolved ? '#f1f8f4' : '#fff8e1') . '; transition: all 0.3s ease;">';
                            echo '    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px;">';
                            echo '        <div style="flex: 1;">';
                            echo '            <div style="display: flex; align-items: center; margin-bottom: 10px;">';
                            echo '                <span style="background: ' . $status_color . '; color: white; padding: 5px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 600; margin-right: 10px;">';
                            echo '                    <i class="fas ' . $status_icon . ' me-1"></i>' . $status_text;
                            echo '                </span>';
                            echo '                <span style="color: #666; font-size: 0.9rem;">';
                            echo '                    <i class="fas fa-calendar me-1"></i>' . $created_date;
                            echo '                </span>';
                            echo '            </div>';
                            echo '            <h6 style="margin: 0 0 8px 0; color: #333; font-weight: 600;">';
                            echo '                <i class="fas fa-map-marker-alt me-2" style="color: #2196f3;"></i>' . htmlspecialchars($report['location']);
                            echo '            </h6>';
                            echo '            <p style="margin: 0; color: #555; line-height: 1.6;">' . htmlspecialchars($report['description']) . '</p>';
                            echo '        </div>';
                            echo '    </div>';
                            
                            if ($is_resolved && $resolved_date) {
                                echo '    <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #e0e0e0;">';
                                echo '        <div style="display: flex; align-items: center; color: #4caf50; font-size: 0.9rem; margin-bottom: 8px;">';
                                echo '            <i class="fas fa-check-circle me-2"></i>';
                                echo '            <strong>Resolved on:</strong> ' . $resolved_date;
                                echo '        </div>';
                                if (!empty($report['resolution_notes'])) {
                                    echo '        <div style="background: white; padding: 12px; border-radius: 6px; margin-top: 10px; border-left: 3px solid #4caf50;">';
                                    echo '            <strong style="color: #333; font-size: 0.9rem;">Resolution Notes:</strong>';
                                    echo '            <p style="margin: 5px 0 0 0; color: #666; font-size: 0.9rem;">' . htmlspecialchars($report['resolution_notes']) . '</p>';
                                    echo '        </div>';
                                }
                                echo '    </div>';
                            } else {
                                echo '    <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #e0e0e0;">';
                                echo '        <div style="display: flex; align-items: center; color: #ff9800; font-size: 0.9rem;">';
                                echo '            <i class="fas fa-hourglass-half me-2"></i>';
                                echo '            <strong>Status:</strong> Under review - We will update you once resolved';
                                echo '        </div>';
                                echo '    </div>';
                            }
                            
                            echo '</div>';
                        }
                        echo '</div>';
                    }
                } else {
                    echo '<div style="text-align: center; padding: 40px; color: #666;">
                            <p style="margin: 0;">Please log in to view your reports</p>
                          </div>';
                }
                ?>
            </div>
         </div>
        
        <div class="tab-content" id="nav-tabContent" style="padding: 0 !important; margin: 0 !important; border: none !important;">
                <!-- Dashboard Overview Tab -->
                <div class="tab-pane fade show active" id="nav-overview" role="tabpanel" aria-labelledby="nav-overview-tab">
                    <!-- Balance Summary Cards -->
                        <div class="row m-3 mb-4">
                            <div class="col-12 col-sm-6 col-md-4 mb-3 mb-md-0">
                                <div style="background: linear-gradient(135deg, #4caf50, #388e3c); border-radius: 12px; box-shadow: 0 4px 15px rgba(76,175,80,0.2); overflow: hidden; margin-bottom: 20px;">
                                    <div style="padding: 25px;">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 style="color: rgba(255,255,255,0.8); margin-bottom: 8px; font-weight: 500;">Current Balance</h6>
                                                <h3 style="color: white; margin-bottom: 5px; font-weight: 600;">₱<?php echo number_format($current_balance, 2); ?></h3>
                                                <small style="color: rgba(255,255,255,0.7); font-size: 0.85rem;">Current billing period</small>
                                            </div>
                                            <i class="fas fa-calendar-alt fa-2x" style="color: rgba(255,255,255,0.6);"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12 col-sm-6 col-md-4 mb-3 mb-md-0">
                                <div style="background: linear-gradient(135deg, #f44336, #d32f2f); border-radius: 12px; box-shadow: 0 4px 15px rgba(244,67,54,0.2); overflow: hidden; margin-bottom: 20px;">
                                    <div style="padding: 25px;">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 style="color: rgba(255,255,255,0.8); margin-bottom: 8px; font-weight: 500;">Previous Balance</h6>
                                                <h3 style="color: white; margin-bottom: 5px; font-weight: 600;">₱<?php echo number_format($overdue_balance, 2); ?></h3>
                                                <small style="color: rgba(255,255,255,0.7); font-size: 0.85rem;">Past due bills</small>
                                            </div>
                                            <i class="fas fa-exclamation-circle fa-2x" style="color: rgba(255,255,255,0.6);"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12 col-sm-6 col-md-4 mb-3 mb-md-0">
                                <div style="background: linear-gradient(135deg, #2196f3, #1976d2); border-radius: 12px; box-shadow: 0 4px 15px rgba(33,150,243,0.2); overflow: hidden; margin-bottom: 20px;">
                                    <div style="padding: 25px;">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 style="color: rgba(255,255,255,0.8); margin-bottom: 8px; font-weight: 500;">Total Amount</h6>
                                                <h3 style="color: white; margin-bottom: 5px; font-weight: 600;">₱<?php echo number_format($total_amount_due, 2); ?></h3>
                                                <small style="color: rgba(255,255,255,0.7); font-size: 0.85rem;">Total unpaid bills</small>
                                            </div>
                                            <i class="fas fa-file-invoice-dollar fa-2x" style="color: rgba(255,255,255,0.6);"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Disconnection Notices -->
                        <div class="row mb-4">
                            <div class="col-12">
                                <div style="background: white; border: 1px solid #e0e0e0; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); overflow: hidden; margin: 20px;">
                                    <div style="background: linear-gradient(135deg, #ff5722, #d84315); padding: 20px; border-bottom: 1px solid #e0e0e0;">
                                        <h5 style="margin: 0; color: white; font-weight: 600;">
                                            <i class="fas fa-exclamation-triangle me-2" style="color: white;"></i>Disconnection Notices
                                        </h5>
                                    </div>
                                    <div style="padding: 25px;">
                                        <?php if ($disconnection_notices->num_rows > 0): ?>
                                            <div class="list-group list-group-flush">
                                                <?php while ($notice = $disconnection_notices->fetch_assoc()): 
                                                    $notice_class = '';
                                                    switch($notice['notice_type']) {
                                                        case 'first_warning':
                                                            $notice_class = 'warning';
                                                            break;
                                                        case 'final_notice':
                                                            $notice_class = 'danger';
                                                            break;
                                                        case 'disconnection_order':
                                                            $notice_class = 'dark';
                                                            break;
                                                        default:
                                                            $notice_class = 'info';
                                                    }
                                                ?>
                                                <div class="list-group-item">
                                                    <div class="d-flex justify-content-between align-items-start">
                                                        <div>
                                                            <h6 class="mb-1"><?php echo htmlspecialchars($notice['title']); ?></h6>
                                                            <p class="mb-1 text-muted small">
                                                                <?php echo date('M j, Y', strtotime($notice['created_at'])); ?>
                                                                <?php if ($notice['due_date']): ?>
                                                                    - Due: <?php echo date('M j, Y', strtotime($notice['due_date'])); ?>
                                                                <?php endif; ?>
                                                            </p>
                                                        </div>
                                                        <div class="text-end">
                                                            <span class="badge bg-<?php echo $notice_class; ?> small">
                                                                <?php echo ucfirst(str_replace('_', ' ', $notice['notice_type'])); ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <?php endwhile; ?>
                                            </div>
                                            <div class="text-center mt-3">
                                                <a href="customer_disconnection_notices.php" class="btn btn-outline-warning btn-sm">
                                                    <i class="fas fa-bell me-1"></i>View All Notices
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <div class="text-center py-3">
                                                <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                                                <p class="text-muted">No disconnection notices</p>
                                                <small class="text-success">Your account is in good standing!</small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Water Advisory Notices on Dashboard -->
                        <?php
                        // Get active water advisory notices for dashboard
                        $dashboard_notices_query = "
                            SELECT n.*, a.username as admin_name
                            FROM notices n
                            JOIN admin a ON n.created_by = a.id
                            WHERE n.status = 'ongoing' 
                               OR (n.status = 'scheduled' AND n.start_date <= DATE_ADD(NOW(), INTERVAL 24 HOUR))
                            ORDER BY 
                                CASE n.status
                                    WHEN 'ongoing' THEN 1
                                    WHEN 'scheduled' THEN 2
                                END,
                                n.start_date DESC
                            LIMIT 2";
                        $dashboard_notices = $conn->query($dashboard_notices_query);
                        
                        if ($dashboard_notices && $dashboard_notices->num_rows > 0):
                        ?>
                        <div style="background: white; border: 1px solid #e0e0e0; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); overflow: hidden; margin: 20px;">
                            <div style="background: linear-gradient(135deg, #2196f3, #1976d2); padding: 20px; border-bottom: 1px solid #e0e0e0;">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 style="margin: 0; color: white; font-weight: 600;">
                                        <i class="fas fa-bell me-2" style="color: white;"></i>Water Service Notices
                                    </h5>
                                    <a href="#" onclick="document.querySelector('#nav-notifications-tab').click();" style="background: rgba(255,255,255,0.2); color: white; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 0.85rem; font-weight: 500; transition: all 0.3s ease;"
                                       onmouseover="this.style.background='rgba(255,255,255,0.3)'"
                                       onmouseout="this.style.background='rgba(255,255,255,0.2)'">
                                        View All
                                    </a>
                                </div>
                            </div>
                            <div style="padding: 25px;">
                                <div class="row">
                                    <?php while ($notice = $dashboard_notices->fetch_assoc()): ?>
                                        <div class="col-12 col-md-6 mb-3">
                                            <div class="card border h-100">
                                                <div class="card-body p-3">
                                                    <?php
                                                    $icon_class = '';
                                                    switch($notice['type']) {
                                                        case 'interruption':
                                                            $icon_class = 'fa-tint-slash text-danger';
                                                            break;
                                                        case 'maintenance':
                                                            $icon_class = 'fa-wrench text-warning';
                                                            break;
                                                        case 'announcement':
                                                            $icon_class = 'fa-info-circle text-info';
                                                            break;
                                                    }
                                                    ?>
                                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                                        <h6 class="mb-0">
                                                            <i class="fas <?php echo $icon_class; ?> me-2"></i>
                                                            <?php echo htmlspecialchars($notice['title']); ?>
                                                        </h6>
                                                        <span class="badge <?php 
                                                            $status_class = 'bg-secondary';
                                                            if ($notice['status'] === 'ongoing') {
                                                                $status_class = 'bg-warning';
                                                            } elseif ($notice['status'] === 'scheduled') {
                                                                $status_class = 'bg-info';
                                                            }
                                                            echo $status_class;
                                                        ?> small">
                                                            <?php echo ucfirst($notice['status']); ?>
                                                        </span>
                                                    </div>
                                                    
                                                    <p class="text-muted small mb-2">
                                                        <?php echo htmlspecialchars(substr($notice['description'], 0, 100)) . (strlen($notice['description']) > 100 ? '...' : ''); ?>
                                                    </p>
                                                    
                                                    <div class="small text-muted">
                                                        <i class="fas fa-map-marker-alt me-1"></i>
                                                        <?php echo htmlspecialchars($notice['affected_areas']); ?>
                                                        <br>
                                                        <i class="fas fa-clock me-1"></i>
                                                        <?php 
                                                        echo date('M d, Y h:i A', strtotime($notice['start_date']));
                                                        if ($notice['end_date']) {
                                                            echo ' - ' . date('h:i A', strtotime($notice['end_date']));
                                                        }
                                                        ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Account Summary -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-user me-2"></i>Account Summary
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-12 col-md-6 mb-3 mb-md-0">
                                        <div class="list-group list-group-flush">
                                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                                <span><i class="fas fa-user me-2"></i>Account Holder</span>
                                                <strong><?php echo htmlspecialchars($customer['firstname'] . ' ' . $customer['lastname']); ?></strong>
                                            </div>
                                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                                <span><i class="fas fa-tachometer-alt me-2"></i>Meter Code</span>
                                                <strong><?php echo htmlspecialchars($customer['meter_code']); ?></strong>
                                            </div>
                                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                                <span><i class="fas fa-phone me-2"></i>Contact</span>
                                                <strong><?php echo htmlspecialchars($customer['contact']); ?></strong>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <div class="list-group list-group-flush">
                                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                                <span><i class="fas fa-envelope me-2"></i>Email</span>
                                                <strong><?php echo htmlspecialchars($customer['email']); ?></strong>
                                            </div>
                                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                                <span><i class="fas fa-map-marker-alt me-2"></i>Address</span>
                                                <strong><?php echo htmlspecialchars($customer['address']); ?></strong>
                                            </div>
                                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                                <span><i class="fas fa-chart-line me-2"></i>Total Bills</span>
                                                <strong><?php echo $total_bills_count; ?></strong>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                <!-- Notifications Tab -->
                <div class="tab-pane fade" id="nav-notifications" role="tabpanel" aria-labelledby="nav-notifications-tab">
                    <!-- Disconnection Notices Section -->
                        <?php if ($disconnection_notices->num_rows > 0): ?>
                        <div style="background: white; border: 1px solid #e0e0e0; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); overflow: hidden; margin: 20px;">
                            <div style="background: linear-gradient(135deg, #f44336, #d32f2f); padding: 20px; border-bottom: 1px solid #e0e0e0;">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 style="margin: 0; color: white; font-weight: 600;">
                                        <i class="fas fa-exclamation-triangle me-2" style="color: white;"></i>Payment Disconnection Notices
                                    </h5>
                                    <a href="customer_disconnection_notices.php" style="background: rgba(255,255,255,0.2); color: white; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 0.85rem; font-weight: 500; transition: all 0.3s ease;"
                                       onmouseover="this.style.background='rgba(255,255,255,0.3)'"
                                       onmouseout="this.style.background='rgba(255,255,255,0.2)'">
                                        <i class="fas fa-eye me-1"></i>View All
                                    </a>
                                </div>
                            </div>
                            <div style="padding: 25px;">
                                <div class="row">
                                    <?php 
                                    // Reset disconnection notices for display
                                    $stmt = $conn->prepare($notices_sql);
                                    $stmt->bind_param("i", $_SESSION['client_id']);
                                    $stmt->execute();
                                    $disconnection_notices_display = $stmt->get_result();
                                    
                                    while ($notice = $disconnection_notices_display->fetch_assoc()): 
                                        $notice_border_class = '';
                                        $notice_icon = '';
                                        $notice_text_class = '';
                                        switch($notice['notice_type']) {
                                            case 'first_warning':
                                                $notice_border_class = 'border-warning';
                                                $notice_icon = 'fa-exclamation-triangle text-warning';
                                                $notice_text_class = 'text-warning';
                                                break;
                                            case 'final_notice':
                                                $notice_border_class = 'border-danger';
                                                $notice_icon = 'fa-times-circle text-danger';
                                                $notice_text_class = 'text-danger';
                                                break;
                                            case 'disconnection_order':
                                                $notice_border_class = 'border-dark';
                                                $notice_icon = 'fa-ban text-dark';
                                                $notice_text_class = 'text-dark';
                                                break;
                                            default:
                                                $notice_border_class = 'border-info';
                                                $notice_icon = 'fa-info-circle text-info';
                                                $notice_text_class = 'text-info';
                                        }
                                    ?>
                                    <div class="col-12 col-md-6 mb-3">
                                        <div class="card <?php echo $notice_border_class; ?> h-100">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between align-items-start mb-3">
                                                    <div class="d-flex align-items-center">
                                                        <i class="fas <?php echo $notice_icon; ?> fa-lg me-2"></i>
                                                        <h6 class="mb-0 <?php echo $notice_text_class; ?>"><?php echo ucfirst(str_replace('_', ' ', $notice['notice_type'])); ?></h6>
                                                    </div>
                                                    <span class="badge <?php 
                                                        $status_class = 'bg-secondary';
                                                        $status_text = 'Unknown';
                                                        if ($notice['status'] === 'pending') {
                                                            $status_class = 'bg-warning text-dark';
                                                            $status_text = 'Pending Review';
                                                        } elseif ($notice['status'] === 'sent') {
                                                            $status_class = 'bg-info text-white';
                                                            $status_text = 'Notice Sent';
                                                        } elseif ($notice['status'] === 'resolved') {
                                                            $status_class = 'bg-success text-white';
                                                            $status_text = 'Resolved';
                                                        }
                                                        echo $status_class;
                                                    ?> small">
                                                        <?php echo $status_text; ?>
                                                    </span>
                                                </div>

                                                <h6 class="card-title"><?php echo htmlspecialchars($notice['title']); ?></h6>
                                                <p class="card-text small text-muted mb-3"><?php echo htmlspecialchars(substr($notice['description'], 0, 100)) . (strlen($notice['description']) > 100 ? '...' : ''); ?></p>
                                                
                                                <div class="row text-center">
                                                    <div class="col-6">
                                                        <div class="border-end pe-3">
                                                            <strong class="text-danger d-block">₱<?php echo number_format($notice['amount_due'], 2); ?></strong>
                                                            <small class="text-muted">Amount Due</small>
                                                        </div>
                                                    </div>
                                                    <div class="col-6">
                                                        <div class="ps-3">
                                                            <strong class="text-dark d-block"><?php echo $notice['overdue_days']; ?> days</strong>
                                                            <small class="text-muted">Overdue</small>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="mt-3 pt-3 border-top">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <small class="text-muted">
                                                            <i class="fas fa-calendar me-1"></i>Due: <?php echo date('M j, Y', strtotime($notice['due_date'])); ?>
                                                        </small>
                                                        <?php if ($notice['disconnection_date']): ?>
                                                        <small class="text-danger fw-bold">
                                                            <i class="fas fa-plug me-1"></i>Disconnection: <?php echo date('M j, Y', strtotime($notice['disconnection_date'])); ?>
                                                        </small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endwhile; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Service Notices & Announcements Section -->
                        <?php
                        // Get active notices (only ongoing and scheduled)
                        $notices_query = "
                            SELECT n.*, a.username as admin_name
                            FROM notices n
                            JOIN admin a ON n.created_by = a.id
                            WHERE n.status = 'ongoing' 
                               OR (n.status = 'scheduled' AND n.start_date <= DATE_ADD(NOW(), INTERVAL 24 HOUR))
                            ORDER BY 
                                CASE n.status
                                    WHEN 'ongoing' THEN 1
                                    WHEN 'scheduled' THEN 2
                                END,
                                n.start_date DESC";
                        $notices = $conn->query($notices_query);
                        
                        if ($notices && $notices->num_rows > 0):
                        ?>
                        <div class="card border-primary">
                            <div class="card-header bg-primary text-white">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">
                                        <i class="fas fa-bell me-2"></i>Service Notices & Announcements
                                    </h5>
                                    <a href="client_notices.php" class="btn btn-light btn-sm">
                                        <i class="fas fa-external-link-alt me-1"></i>View All Notices
                                    </a>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <?php while ($notice = $notices->fetch_assoc()): ?>
                                        <div class="col-12 col-md-6 mb-3">
                                            <div class="card h-100 border-0 shadow-sm">
                                                <?php
                                                $card_class = '';
                                                $icon_class = '';
                                                switch($notice['type']) {
                                                    case 'interruption':
                                                        $card_class = 'border-danger';
                                                        $icon_class = 'fa-tint-slash text-danger';
                                                        break;
                                                    case 'maintenance':
                                                        $card_class = 'border-warning';
                                                        $icon_class = 'fa-wrench text-warning';
                                                        break;
                                                    case 'announcement':
                                                        $card_class = 'border-info';
                                                        $icon_class = 'fa-info-circle text-info';
                                                        break;
                                                }
                                                ?>
                                                <div class="card-body position-relative">
                                                    <i class="fas <?php echo $icon_class; ?> notice-icon"></i>
                                                    
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
                                                    ?> position-absolute top-0 end-0 mt-3 me-3">
                                                        <?php echo ucfirst($notice['status']); ?>
                                                    </span>

                                                    <h5 class="card-title mt-2"><?php echo htmlspecialchars($notice['title']); ?></h5>
                                                    <p class="card-text"><?php echo nl2br(htmlspecialchars($notice['description'])); ?></p>
                                                    
                                                    <div class="mt-3">
                                                        <p class="mb-2">
                                                            <strong><i class="fas fa-map-marker-alt me-2"></i>Affected Areas:</strong><br>
                                                            <?php echo htmlspecialchars($notice['affected_areas']); ?>
                                                        </p>
                                                        
                                                        <p class="mb-2">
                                                            <strong><i class="fas fa-clock me-2"></i>Duration:</strong><br>
                                                            <?php 
                                                            echo date('M d, Y h:i A', strtotime($notice['start_date']));
                                                            if ($notice['end_date']) {
                                                                echo ' to ' . date('M d, Y h:i A', strtotime($notice['end_date']));
                                                            }
                                                            ?>
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="card border-primary">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0">
                                    <i class="fas fa-bell me-2"></i>Service Notices & Announcements
                                </h5>
                            </div>
                            <div class="card-body text-center py-5">
                                <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                                <h4>No Active Notices</h4>
                                <p class="text-muted">There are currently no service notices or announcements.</p>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Recent Notices Table -->
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-history me-2"></i>Recent Notice History
                                </h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table mb-0">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Title</th>
                                                <th class="d-none d-md-table-cell">Type</th>
                                                <th>Status</th>
                                                <th class="text-end">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            // Get recent notices including completed ones
                                            $all_notices_query = "
                                                SELECT n.*, a.username as admin_name
                                                FROM notices n
                                                JOIN admin a ON n.created_by = a.id
                                                WHERE (n.status = 'ongoing' OR 
                                                      (n.status = 'scheduled' AND n.start_date <= DATE_ADD(NOW(), INTERVAL 24 HOUR)) OR
                                                      (n.status = 'completed' AND n.end_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)))
                                                ORDER BY 
                                                    CASE n.status
                                                        WHEN 'ongoing' THEN 1
                                                        WHEN 'scheduled' THEN 2
                                                        WHEN 'completed' THEN 3
                                                    END,
                                                    n.start_date DESC
                                                LIMIT 5";
                                            $all_notices = $conn->query($all_notices_query);

                                            if ($all_notices && $all_notices->num_rows > 0):
                                                while ($notice = $all_notices->fetch_assoc()):
                                                    $status_class = '';
                                                    $type_class = '';
                                                    switch($notice['status']) {
                                                        case 'ongoing':
                                                            $status_class = 'bg-warning';
                                                            break;
                                                        case 'scheduled':
                                                            $status_class = 'bg-info';
                                                            break;
                                                        case 'completed':
                                                            $status_class = 'bg-success';
                                                            break;
                                                    }
                                                    switch($notice['type']) {
                                                        case 'interruption':
                                                            $type_class = 'text-danger';
                                                            break;
                                                        case 'maintenance':
                                                            $type_class = 'text-warning';
                                                            break;
                                                        case 'announcement':
                                                            $type_class = 'text-info';
                                                            break;
                                                    }
                                            ?>
                                            <tr>
                                                <td>
                                                    <small class="d-block"><?php echo date('M d, Y', strtotime($notice['start_date'])); ?></small>
                                                </td>
                                                <td>
                                                    <div><?php echo htmlspecialchars($notice['title']); ?></div>
                                                    <small class="d-md-none <?php echo $type_class; ?>">
                                                        <?php echo ucfirst($notice['type']); ?>
                                                    </small>
                                                </td>
                                                <td class="d-none d-md-table-cell">
                                                    <span class="<?php echo $type_class; ?>">
                                                        <?php echo ucfirst($notice['type']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge <?php echo $status_class; ?>">
                                                        <?php echo ucfirst($notice['status']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <button class="btn btn-sm btn-outline-primary" onclick="viewNotice(<?php echo $notice['id']; ?>)">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endwhile; ?>
                                            <?php else: ?>
                                            <tr>
                                                <td colspan="5" class="text-center text-muted py-4">
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

                <!-- Billing Information Tab -->
                <div class="tab-pane fade" id="nav-billing" role="tabpanel" aria-labelledby="nav-billing-tab">
                    <!-- Recent Billing History -->
                        <div style="background: white; border: 1px solid #e0e0e0; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); overflow: hidden; margin: 20px;">
                            <div style="background: linear-gradient(135deg, #2196f3, #1976d2); padding: 20px; border-bottom: 1px solid #e0e0e0;">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 style="margin: 0; color: white; font-weight: 600;">
                                        <i class="fas fa-file-invoice me-2" style="color: white;"></i>Recent Billing History
                                    </h5>
                                    <a href="customer_billing_history.php" style="background: rgba(255,255,255,0.2); color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: 600; transition: all 0.3s ease;"
                                       onmouseover="this.style.background='rgba(255,255,255,0.3)'"
                                       onmouseout="this.style.background='rgba(255,255,255,0.2)'">
                                        <i class="fas fa-eye me-1"></i>View Complete History
                                    </a>
                                </div>
                            </div>
                            <div style="padding: 25px;">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Reading Date</th>
                                                <th>Readings</th>
                                                <th>Usage</th>
                                                <th>Amount</th>
                                                <th class="d-none d-md-table-cell">Due Date</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            // Create fresh query for billing information tab
                                            $billing_history_sql = "WITH PaymentTotals AS (
                                                SELECT 
                                                    billing_id,
                                                    COALESCE(SUM(amount), 0) as total_paid
                                                FROM payment_list
                                                WHERE client_id = ? AND status = 1
                                                GROUP BY billing_id
                                            )
                                            SELECT 
                                                b.*,
                                                COALESCE(pt.total_paid, 0) as amount_paid,
                                                COALESCE(b.total - COALESCE(pt.total_paid, 0), b.total) as remaining_balance,
                                                CASE 
                                                    WHEN b.status = 1 THEN 'Paid'
                                                    WHEN b.status = 0 AND b.due_date < CURRENT_DATE THEN 'Overdue'
                                                    ELSE 'Pending'
                                                END as status_text
                                            FROM billing_list b
                                            LEFT JOIN PaymentTotals pt ON b.id = pt.billing_id
                                            WHERE b.client_id = ? 
                                            ORDER BY b.reading_date DESC
                                            LIMIT 5";
                                            
                                            $billing_stmt = $conn->prepare($billing_history_sql);
                                            $billing_stmt->bind_param("ii", $_SESSION['client_id'], $_SESSION['client_id']);
                                            $billing_stmt->execute();
                                            $billing_history = $billing_stmt->get_result();
                                            
                                            if ($billing_history->num_rows > 0):
                                                while ($bill = $billing_history->fetch_assoc()): 
                                                    $remaining = $bill['remaining_balance'];
                                                    $is_overdue = strtotime($bill['due_date']) < time() && $bill['status'] == 0;
                                                    $is_paid = $bill['status'] == 1 || $remaining <= 0;
                                                    
                                                    // Handle missing fields with defaults
                                                    $current_reading = isset($bill['reading']) ? $bill['reading'] : 0;
                                                    $previous_reading = isset($bill['previous']) ? $bill['previous'] : 0;
                                                    $usage = $current_reading - $previous_reading;
                                                    
                                                    // Get fee breakdown for this bill
                                                    $fee_breakdown = getBillFeeBreakdown($bill['id'], $conn);
                                                    $has_fees = $fee_breakdown['success'] && !empty($fee_breakdown['applied_fees']);
                                            ?>
                                            <tr>
                                                <td>
                                                    <small class="d-block"><?php echo date('M d, Y', strtotime($bill['reading_date'])); ?></small>
                                                    <small class="d-md-none text-muted">Due: <?php echo date('M d, Y', strtotime($bill['due_date'])); ?></small>
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-column">
                                                        <small class="text-muted">Previous: <?php echo number_format($previous_reading, 1); ?></small>
                                                        <small>Current: <?php echo number_format($current_reading, 1); ?></small>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php echo number_format($usage, 1); ?>m³
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div>
                                                            <div>₱<?php echo number_format($bill['total'], 2); ?></div>
                                                            <?php if (!$is_paid): ?>
                                                                <small class="<?php echo $is_overdue ? 'text-danger' : 'text-warning'; ?>">
                                                                    Due: ₱<?php echo number_format($remaining, 2); ?>
                                                                </small>
                                                            <?php else: ?>
                                                                <small class="text-success">Paid: ₱<?php echo number_format($bill['amount_paid'], 2); ?></small>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if ($has_fees): ?>
                                                            <div class="ms-2">
                                                                <button class="btn btn-sm btn-outline-info" type="button" 
                                                                        data-bs-toggle="collapse" data-bs-target="#feeBreakdown<?php echo $bill['id']; ?>" 
                                                                        aria-expanded="false" aria-controls="feeBreakdown<?php echo $bill['id']; ?>"
                                                                        title="View fee breakdown">
                                                                    <i class="fas fa-info-circle"></i>
                                                                </button>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($has_fees): ?>
                                                        <div class="collapse mt-2" id="feeBreakdown<?php echo $bill['id']; ?>">
                                                            <div class="card card-body small" style="background-color: #f8f9fa;">
                                                                <strong>Bill Breakdown:</strong>
                                                                <div class="row">
                                                                    <div class="col-6">Water Usage:</div>
                                                                    <div class="col-6 text-end">₱<?php echo number_format($fee_breakdown['base_amount'], 2); ?></div>
                                                                </div>
                                                                <?php foreach ($fee_breakdown['applied_fees'] as $fee): ?>
                                                                    <div class="row">
                                                                        <div class="col-6"><?php echo htmlspecialchars($fee['fee_name']); ?>:</div>
                                                                        <div class="col-6 text-end">₱<?php echo number_format($fee['fee_amount'], 2); ?></div>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                                <hr class="my-1">
                                                                <div class="row fw-bold">
                                                                    <div class="col-6">Total:</div>
                                                                    <div class="col-6 text-end">₱<?php echo number_format($fee_breakdown['final_total'], 2); ?></div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="d-none d-md-table-cell">
                                                    <?php echo date('M d, Y', strtotime($bill['due_date'])); ?>
                                                </td>
                                                <td>
                                                    <span class="badge <?php 
                                                        if ($is_paid) {
                                                            echo 'bg-success';
                                                        } else {
                                                            echo $is_overdue ? 'bg-danger text-white' : 'bg-warning text-dark';
                                                        }
                                                    ?>">
                                                        <?php 
                                                        if ($is_paid) {
                                                            echo 'Paid';
                                                        } else {
                                                            echo $is_overdue ? 'Overdue' : 'Pending';
                                                        }
                                                        ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php 
                                                endwhile;
                                            else:
                                            ?>
                                            <tr>
                                                <td colspan="6" class="text-center py-4">
                                                    <i class="fas fa-file-invoice fa-2x text-muted mb-2"></i>
                                                    <p class="text-muted mb-0">No billing history found</p>
                                                </td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                                <!-- Payment History Card -->
                                <div style="background: white; border: 1px solid #e0e0e0; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); overflow: hidden; margin: 20px;">
                                    <div style="background: linear-gradient(135deg, #4caf50, #388e3c); padding: 20px; border-bottom: 1px solid #e0e0e0;">
                                        <h5 style="margin: 0; color: white; font-weight: 600;">
                                            <i class="fas fa-money-bill-wave me-2" style="color: white;"></i>
                                            Payment History
                                        </h5>
                                    </div>
                                    <div style="padding: 0;">
                                        <div class="table-responsive">
                                            <table class="table mb-0">
                                                <thead>
                                                    <tr>
                                                        <th>Date</th>
                                                        <th class="d-none d-md-table-cell">Reference</th>
                                                        <th>Amount</th>
                                                        <th>Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php 
                                                    $count = 0;
                                                    while (($payment = $payments->fetch_assoc()) && $count < 5): 
                                                        $count++;
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <small class="d-block"><?php echo date('M d, Y h:i A', strtotime($payment['payment_date'])); ?></small>
                                                            <small class="d-md-none text-muted"><?php echo htmlspecialchars($payment['reference_number']); ?></small>
                                                        </td>
                                                        <td class="d-none d-md-table-cell">
                                                            <?php echo htmlspecialchars($payment['reference_number']); ?>
                                                        </td>
                                                        <td>₱<?php echo number_format($payment['amount'], 2); ?></td>
                                                        <td>
                                                            <span class="badge <?php echo $payment['status'] ? 'bg-success' : 'bg-warning'; ?>">
                                                                <?php echo $payment['status_text']; ?>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                    <?php endwhile; ?>
                                                    <?php if ($count === 0): ?>
                                                    <tr>
                                                        <td colspan="4" class="text-center text-muted">
                                                            <i class="fas fa-info-circle me-2"></i>No payment records found
                                                        </td>
                                                    </tr>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                                                <!-- Water Reports Tab -->
                <div class="tab-pane fade" id="nav-reports" role="tabpanel" aria-labelledby="nav-reports-tab">
                    <!-- Empty tab - form will show above -->
                </div>
            </div>
        </div>

    <!-- Edit Profile Modal -->
    <div class="modal fade edit-profile-modal" id="editProfileModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>
                        Edit Profile
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="editProfileForm" action="update_profile.php" method="POST">
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label">First Name</label>
                            <input type="text" class="form-control" name="firstname" value="<?php echo htmlspecialchars($customer['firstname']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Last Name</label>
                            <input type="text" class="form-control" name="lastname" value="<?php echo htmlspecialchars($customer['lastname']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Contact Number</label>
                            <input type="tel" class="form-control" name="contact" value="<?php echo htmlspecialchars($customer['contact']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($customer['email']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Address</label>
                            <textarea class="form-control" name="address" rows="3" required><?php echo htmlspecialchars($customer['address']); ?></textarea>
                        </div>
                    </div>
                    <div class="modal-footer border-0 pt-0">
                        <button type="button" class="modal-btn modal-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="modal-btn modal-btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div class="modal fade password-modal" id="changePasswordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-key me-2"></i>
                        Change Password
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="changePasswordForm" action="update_password.php" method="POST">
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label">Current Password</label>
                            <input type="password" class="form-control" name="current_password" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <input type="password" class="form-control" name="new_password" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" class="form-control" name="confirm_password" required>
                        </div>

                        <div class="password-requirements">
                            <h6 class="mb-3">Password Requirements:</h6>
                            <div class="requirement-item">
                                <i class="fas fa-check-circle"></i>
                                At least 8 characters long
                            </div>
                            <div class="requirement-item">
                                <i class="fas fa-check-circle"></i>
                                Contains both uppercase and lowercase letters
                            </div>
                            <div class="requirement-item">
                                <i class="fas fa-check-circle"></i>
                                Includes numbers or special characters
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 pt-0">
                        <button type="button" class="modal-btn modal-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="modal-btn modal-btn-primary">Change Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Notice Modal -->
    <div class="modal fade" id="viewNoticeModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-bell me-2"></i>Notice Details
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="noticeDetails">
                        <div class="mb-4">
                            <span id="noticeType" class="badge mb-2"></span>
                            <h4 id="noticeTitle" class="mb-3"></h4>
                            <p id="noticeDescription" class="text-muted"></p>
                        </div>

                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="text-muted mb-1">Affected Areas</label>
                                <p id="noticeAreas" class="mb-3"></p>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="text-muted mb-1">Status</label>
                                <div id="noticeStatus"></div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="text-muted mb-1">Start Date</label>
                                <p id="noticeStartDate" class="mb-3"></p>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="text-muted mb-1">End Date</label>
                                <p id="noticeEndDate" class="mb-3"></p>
                            </div>
                            <div class="col-12">
                                <label class="text-muted mb-1">Posted By</label>
                                <p id="noticeAdmin" class="mb-0"></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Notification System -->
    <script src="assets/js/notifications.js"></script>
    <script>
    // Initialize tab functionality
    // Update notification badge (unread-style: vanishes when Notifications tab is opened)
    const clientId = <?php echo intval($_SESSION['client_id'] ?? 0); ?>;
    const clientNotifSeenKey = `ws_client_notif_seen_event_key_${clientId}`;
    let latestNotifTotalCount = 0;
    let latestNotifEventKey = '';

    function getSeenNotifEventKey() {
        return localStorage.getItem(clientNotifSeenKey) || '';
    }

    function markNotificationsAsSeen() {
        if (latestNotifEventKey) {
            localStorage.setItem(clientNotifSeenKey, latestNotifEventKey);
        }
        const tabBadge = document.querySelector('#nav-notifications-tab .notification-badge');
        if (tabBadge) {
            tabBadge.style.display = 'none';
        }
    }

    function updateNotificationBadge() {
        fetch('get_notification_count.php')
            .then(response => response.json())
            .then(data => {
                if (!data.success) return;
                latestNotifTotalCount = parseInt(data.count || 0, 10);
                latestNotifEventKey = (data.event_key || `count-${latestNotifTotalCount}`);
                const seenEventKey = getSeenNotifEventKey();
                const hasUnread = latestNotifTotalCount > 0 && latestNotifEventKey !== seenEventKey;
                const unreadCount = hasUnread ? latestNotifTotalCount : 0;

                // Update tab badge
                const tabBadge = document.querySelector('#nav-notifications-tab .notification-badge');
                if (tabBadge) {
                    if (unreadCount > 0) {
                        tabBadge.textContent = unreadCount > 99 ? '99+' : unreadCount;
                        tabBadge.style.display = 'block';
                    } else {
                        tabBadge.style.display = 'none';
                    }
                } else if (unreadCount > 0) {
                    // Create tab badge if it doesn't exist
                    const tab = document.getElementById('nav-notifications-tab');
                    if (tab && !tabBadge) {
                        const newBadge = document.createElement('span');
                        newBadge.className = 'badge bg-danger notification-badge';
                        newBadge.style.cssText = 'position: absolute; top: -5px; right: -5px; font-size: 0.7rem; padding: 2px 6px; border-radius: 10px; animation: pulse 2s infinite; box-shadow: 0 2px 4px rgba(220, 53, 69, 0.4);';
                        newBadge.textContent = unreadCount > 99 ? '99+' : unreadCount;
                        tab.appendChild(newBadge);
                    }
                }
                
                // Update navbar bubble badge
                const navbarBadge = document.getElementById('navbar-notification-badge');
                if (navbarBadge) {
                    if (unreadCount > 0) {
                        navbarBadge.textContent = unreadCount > 99 ? '99+' : unreadCount;
                        navbarBadge.style.display = 'flex';
                    } else {
                        navbarBadge.style.display = 'none';
                    }
                }
            })
            .catch(error => console.error('Error updating notification badge:', error));
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        // If user lands directly on Notifications tab, consider current notifications as seen.
        const notifTabBtn = document.getElementById('nav-notifications-tab');
        if (notifTabBtn) {
            notifTabBtn.addEventListener('shown.bs.tab', function () {
                markNotificationsAsSeen();
            });
            notifTabBtn.addEventListener('click', function () {
                markNotificationsAsSeen();
            });
        }

        // Update notification badge on load and every 30 seconds
        updateNotificationBadge();
        setInterval(updateNotificationBadge, 30000);
        
        // Force show first tab content
        const firstTab = document.getElementById('nav-overview');
        if (firstTab) {
            firstTab.classList.add('show', 'active');
        }
        
        // No custom click styling needed; Bootstrap will handle active classes.
    });

    // Tab navigation from URL hash - DISABLED (no hash in URL)
    // Removed to prevent hash from appearing in URL when switching tabs

    // Handle tab changes (without updating URL hash)
    document.addEventListener('shown.bs.tab', function (e) {
        const target = e.target.getAttribute('data-bs-target');
        
        // Show/hide water reports form and reports list based on active tab
        const waterReportsForm = document.getElementById('water-reports-form');
        const outageReportsList = document.getElementById('outage-reports-list');
        if (waterReportsForm) {
            if (target === '#nav-reports') {
                waterReportsForm.style.display = 'block';
                if (outageReportsList) {
                    outageReportsList.style.display = 'block';
                }
            } else {
                waterReportsForm.style.display = 'none';
                if (outageReportsList) {
                    outageReportsList.style.display = 'none';
                }
            }
        }
    });

    // View report details function
    window.viewReportDetails = function(reportId) {
        showInfo(`Viewing details for report ID: ${reportId}`);
    }

    // View notice function for notifications tab
    function viewNotice(noticeId) {
        window.location.href = `client_notices.php#notice-${noticeId}`;
    }

    // Add outage report submission handling
    document.addEventListener('DOMContentLoaded', function() {
        const outageForm = document.getElementById('outageReportForm');
        if (outageForm) {
            outageForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                
                fetch('submit_outage_report.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showSuccess('Report submitted successfully');
                        this.reset();
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showError(data.message || 'Error submitting report');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showError('Error submitting report');
                });
            });
        }
    });
    </script>
</body>
</html> 