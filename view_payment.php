<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: adminlogin.php");
    exit();
}

include 'db.php';

if (!isset($_GET['id'])) {
    header("Location: payments.php");
    exit();
}

$payment_id = $_GET['id'];

// Get payment details with client and bill information
$payment_sql = "SELECT pl.*, cl.firstname, cl.lastname, cl.meter_code, 
                bl.reading_date, bl.reading, bl.previous, bl.total as bill_total
                FROM payment_list pl 
                JOIN client_list cl ON pl.client_id = cl.id 
                JOIN billing_list bl ON pl.billing_id = bl.id 
                WHERE pl.id = ?";
$stmt = $conn->prepare($payment_sql);
$stmt->bind_param("i", $payment_id);
$stmt->execute();
$result = $stmt->get_result();
$payment = $result->fetch_assoc();

if (!$payment) {
    header("Location: payments.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Payment - Water Billing System</title>
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
            --table-hover-bg: #f8f9fa;
            --modal-bg: #fff;
            --input-bg: #fff;
            --input-border: #dee2e6;
            --input-text: #333;
            --input-placeholder: #6c757d;
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

        /* Sidebar styles */
        .sidebar {
            height: 100vh;
            width: 250px;
            position: fixed;
            top: 0;
            left: 0;
            background-color: var(--sidebar-bg);
            border-right: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            transition: all 0.3s ease;
            z-index: 1000;
        }

        .sidebar-header {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid var(--border-color);
        }

        .sidebar-header img {
            max-height: 90px;
            margin-bottom: 10px;
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
            padding: 1rem;
            flex-grow: 1;
        }

        .nav-content a {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            color: var(--text-color);
            text-decoration: none;
            border-radius: 8px;
            margin-bottom: 5px;
            transition: all 0.3s ease;
        }

        .nav-content a i {
            width: 20px;
            margin-right: 10px;
            text-align: center;
        }

        .nav-content a:hover {
            background-color: var(--hover-bg);
            color: var(--hover-text);
        }

        .nav-content a.active {
            background-color: var(--hover-bg);
            color: var(--hover-text);
            font-weight: 600;
        }

        /* Theme switch */
        .theme-switch-wrapper {
            padding: 1rem;
            border-top: 1px solid var(--border-color);
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
            padding: 2rem;
            min-height: 100vh;
            background-color: var(--bg-color);
            transition: all 0.3s ease;
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

        .card-header {
            background-color: var(--card-bg);
            border-bottom: 1px solid var(--border-color);
            padding: 1.25rem;
            border-radius: 15px 15px 0 0;
        }

        .detail-label {
            color: var(--muted-text);
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
        }

        .detail-value {
            font-size: 1rem;
            font-weight: 500;
            color: var(--text-color);
        }

        /* Status badges */
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.875rem;
        }

        .status-verified { 
            background-color: #19875420; 
            color: #198754; 
        }

        .status-pending { 
            background-color: #ffc10720; 
            color: #ffc107; 
        }

        [data-theme="dark"] .status-verified { 
            background-color: #19875415; 
            color: #4caf50; 
        }

        [data-theme="dark"] .status-pending { 
            background-color: #ffc10715; 
            color: #ffd54f; 
        }

        .avatar-lg {
            width: 80px;
            height: 80px;
            background: linear-gradient(45deg, #4e73df 0%, #224abe 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        /* Button styles */
        .btn-outline-primary {
            color: var(--hover-text);
            border-color: var(--hover-text);
        }

        .btn-outline-primary:hover {
            background-color: var(--hover-text);
            color: white;
        }

        .btn-success {
            background-color: #198754;
            border-color: #198754;
        }

        .btn-danger {
            background-color: #dc3545;
            border-color: #dc3545;
        }

        /* Text colors */
        .text-muted {
            color: var(--muted-text) !important;
        }
    </style>
</head>
<body>

<!-- Sidebar --><div class="sidebar">    <div class="sidebar-header">        <img src="icons/Logo.png" alt="Water Billing Logo" class="img-fluid">        <h5 class="mt-2 mb-0" style="color: var(--text-color);">Water Billing System</h5>    </div>        <div class="nav-content">        <a href="adminlandingpage.php">            <i class="fas fa-chart-line"></i>            <span>Dashboard</span>        </a>        <a href="view_clients.php">            <i class="fas fa-users"></i>            <span>Customers</span>        </a>        <a href="billing_list.php">            <i class="fas fa-file-invoice-dollar"></i>            <span>Bills</span>        </a>        <a href="pending_readings.php">            <i class="fas fa-camera"></i>            <span>Meter Readings</span>        </a>        <a href="payments.php" class="active">            <i class="fas fa-money-bill-wave"></i>            <span>Payments</span>        </a>        <a href="customer_accounts.php">            <i class="fas fa-user-circle"></i>            <span>Customer Accounts</span>        </a>        <a href="reports.php">            <i class="fas fa-file-chart-line"></i>            <span>Reports</span>        </a>        <a href="client_reports.php">            <i class="fas fa-chart-bar"></i>            <span>Water Outage Reports</span>        </a>        <a href="disconnection_notices.php">            <i class="fas fa-exclamation-triangle"></i>            <span>Disconnection Notices</span>        </a>        <a href="settings_rate.php">            <i class="fas fa-cog"></i>            <span>Settings</span>        </a>    </div>    <div class="theme-switch-wrapper">        <div class="d-flex align-items-center justify-content-center">            <i class="fas fa-sun me-2"></i>            <label class="theme-switch mb-0">                <input type="checkbox" id="theme-toggle">                <span class="slider"></span>            </label>            <i class="fas fa-moon ms-2"></i>        </div>        <form method="POST" action="logout.php" class="mt-3">            <button type="submit" class="btn btn-outline-primary w-100">                <i class="fas fa-sign-out-alt me-2"></i>Logout            </button>        </form>    </div></div>

<!-- Main Content -->
<div class="main-content">
    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">Payment Details</h2>
            <a href="payments.php" class="btn btn-outline-primary">
                <i class="fas fa-arrow-left me-2"></i>Back to Payments
            </a>
        </div>

        <div class="row">
            <div class="col-md-8">
                <div class="card card-soft">
                    <div class="card-body">
                        <div class="text-center mb-4">
                            <div class="avatar-lg mx-auto">
                                <?php 
                                    $initials = strtoupper(substr($payment['firstname'], 0, 1) . substr($payment['lastname'], 0, 1));
                                    echo $initials;
                                ?>
                            </div>
                            <h4><?php echo htmlspecialchars($payment['firstname'] . ' ' . $payment['lastname']); ?></h4>
                            <p class="text-muted mb-0">Meter Code: <?php echo htmlspecialchars($payment['meter_code']); ?></p>
                        </div>

                        <div class="row g-4">
                            <div class="col-md-6">
                                <div class="detail-label">Reference Number</div>
                                <div class="detail-value"><?php echo htmlspecialchars($payment['reference_number']); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="detail-label">Amount Paid</div>
                                <div class="detail-value">₱<?php echo number_format($payment['amount'], 2); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="detail-label">Payment Date</div>
                                <div class="detail-value"><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="detail-label">Payment Method</div>
                                <div class="detail-value"><?php echo ucfirst($payment['payment_method']); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="detail-label">Status</div>
                                <div class="detail-value">
                                    <?php if ($payment['status'] == 1): ?>
                                        <span class="status-badge status-verified">Verified</span>
                                    <?php else: ?>
                                        <span class="status-badge status-pending">Pending</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($payment['status'] == 1): ?>
                            <div class="col-md-6">
                                <div class="detail-label">Verified Date</div>
                                <div class="detail-value"><?php echo date('M d, Y H:i', strtotime($payment['verified_date'])); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($payment['notes'])): ?>
                        <div class="mt-4">
                            <div class="detail-label">Notes</div>
                            <div class="detail-value"><?php echo nl2br(htmlspecialchars($payment['notes'])); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-soft">
                    <div class="card-header">
                        <h5 class="mb-0">Bill Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <div class="detail-label">Reading Date</div>
                            <div class="detail-value"><?php echo date('M d, Y', strtotime($payment['reading_date'])); ?></div>
                        </div>
                        <div class="mb-3">
                            <div class="detail-label">Current Reading</div>
                            <div class="detail-value"><?php echo number_format($payment['reading'], 2); ?></div>
                        </div>
                        <div class="mb-3">
                            <div class="detail-label">Previous Reading</div>
                            <div class="detail-value"><?php echo number_format($payment['previous'], 2); ?></div>
                        </div>
                        <div class="mb-3">
                            <div class="detail-label">Consumption</div>
                            <div class="detail-value"><?php echo number_format($payment['reading'] - $payment['previous'], 2); ?></div>
                        </div>
                        <div>
                            <div class="detail-label">Total Amount</div>
                            <div class="detail-value">₱<?php echo number_format($payment['bill_total'], 2); ?></div>
                        </div>
                    </div>
                </div>

                <?php if ($payment['status'] == 0): ?>
                <div class="d-grid gap-2 mt-3">
                    <form method="POST" action="payments.php">
                        <input type="hidden" name="payment_id" value="<?php echo $payment['id']; ?>">
                        <button type="submit" name="verify_payment" class="btn btn-success w-100">
                            <i class="fas fa-check me-2"></i>Verify Payment
                        </button>
                    </form>
                    <button class="btn btn-danger" onclick="deletePayment(<?php echo $payment['id']; ?>)">
                        <i class="fas fa-trash me-2"></i>Delete Payment
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Notification System -->
<script src="assets/js/notifications.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Theme Toggle
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

function deletePayment(id) {
    if (confirm('Are you sure you want to delete this payment record?')) {
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
                setTimeout(() => window.location.href = 'payments.php', 1000);
            } else {
                showError('Error deleting payment: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showError('Error deleting payment');
        });
    }
}
</script>
</body>
</html> 