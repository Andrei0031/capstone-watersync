<?php
require_once 'session_validation.php';
validateSession();

include 'db.php';

// Get customer information
$stmt = $conn->prepare("SELECT cl.*, ca.email 
                       FROM client_list cl 
                       JOIN customer_accounts ca ON cl.id = ca.client_id 
                       WHERE ca.id = ?");
$stmt->bind_param("i", $_SESSION['customer_id']);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();

// Get billing history
$stmt = $conn->prepare("SELECT b.*, 
                              CASE 
                                WHEN b.status = 1 THEN 'Paid'
                                WHEN b.status = 0 AND b.due_date < CURRENT_DATE THEN 'Overdue'
                                ELSE 'Pending'
                              END as status_text
                       FROM billing_list b
                       WHERE b.client_id = ?
                       ORDER BY b.reading_date DESC");
$stmt->bind_param("i", $_SESSION['client_id']);
$stmt->execute();
$bills = $stmt->get_result();

// Get payment history
$stmt = $conn->prepare("SELECT p.*, 
                              CASE WHEN p.status = 1 THEN 'Verified' ELSE 'Pending' END as status_text
                       FROM payment_list p
                       WHERE p.client_id = ?
                       ORDER BY p.payment_date DESC");
$stmt->bind_param("i", $_SESSION['client_id']);
$stmt->execute();
$payments = $stmt->get_result();

// Get reports history
$stmt = $conn->prepare("SELECT * FROM client_reports WHERE client_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $_SESSION['client_id']);
$stmt->execute();
$reports = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Dashboard - Water Billing System</title>
    
    <!-- Bootstrap CSS -->
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
        }

        body {
            font-family: 'Open Sans', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
        }

        .sidebar {
            height: 100vh;
            background-color: var(--sidebar-bg);
            border-right: 1px solid var(--border-color);
            padding-top: 20px;
            position: fixed;
            width: 250px;
        }

        .sidebar-header {
            padding: 20px;
            text-align: center;
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
            text-decoration: none;
            transition: all 0.3s;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background-color: var(--hover-bg);
            color: var(--hover-text);
        }

        .sidebar a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }

        .main-content {
            margin-left: 250px;
            padding: 30px;
        }

        .card {
            background-color: var(--card-bg);
            border: none;
            border-radius: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .card-header {
            background-color: transparent;
            border-bottom: 1px solid var(--border-color);
            padding: 20px;
        }

        .status-badge {
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.875rem;
        }

        .status-pending {
            background-color: #ffc107;
            color: #000;
        }

        .status-resolved {
            background-color: #28a745;
            color: #fff;
        }

        .priority-high { color: #dc3545; }
        .priority-medium { color: #ffc107; }
        .priority-low { color: #28a745; }

        .theme-switch-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px;
            margin-top: auto;
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
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <img src="icons/Logo.png" alt="Water Billing Logo" class="img-fluid" style="max-height: 90px;" />
        </div>
        <a href="client_dashboard.php" class="active">
            <i class="fas fa-home"></i>
            <span>Dashboard</span>
        </a>
        <a href="view_bills.php">
            <i class="fas fa-file-invoice-dollar"></i>
            <span>Bills</span>
        </a>
        <a href="payment_history.php">
            <i class="fas fa-money-bill-wave"></i>
            <span>Payments</span>
        </a>
        <a href="submit_report.php">
            <i class="fas fa-flag"></i>
            <span>Submit Report</span>
        </a>
        <a href="profile.php">
            <i class="fas fa-user"></i>
            <span>Profile</span>
        </a>
        
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
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Welcome, <?php echo htmlspecialchars($customer['firstname']); ?>!</h2>
            <a href="submit_report.php" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i>Submit New Report
            </a>
        </div>

        <!-- Quick Stats -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <h6 class="text-muted">Latest Bill</h6>
                        <?php
                        $bills->data_seek(0);
                        $latest_bill = $bills->fetch_assoc();
                        ?>
                        <h3 class="mb-0">₱<?php echo $latest_bill ? number_format($latest_bill['total'], 2) : '0.00'; ?></h3>
                        <small class="text-muted">Due: <?php echo $latest_bill ? date('M d, Y', strtotime($latest_bill['due_date'])) : 'N/A'; ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <h6 class="text-muted">Pending Bills</h6>
                        <?php
                        $pending_count = 0;
                        $bills->data_seek(0);
                        while ($bill = $bills->fetch_assoc()) {
                            if ($bill['status'] == 0) $pending_count++;
                        }
                        ?>
                        <h3 class="mb-0"><?php echo $pending_count; ?></h3>
                        <small class="text-muted">Need Attention</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <h6 class="text-muted">Active Reports</h6>
                        <?php
                        $active_reports = 0;
                        $reports->data_seek(0);
                        while ($report = $reports->fetch_assoc()) {
                            if ($report['status'] == 0) $active_reports++;
                        }
                        ?>
                        <h3 class="mb-0"><?php echo $active_reports; ?></h3>
                        <small class="text-muted">Pending Resolution</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <h6 class="text-muted">Last Payment</h6>
                        <?php
                        $payments->data_seek(0);
                        $last_payment = $payments->fetch_assoc();
                        ?>
                        <h3 class="mb-0">₱<?php echo $last_payment ? number_format($last_payment['amount'], 2) : '0.00'; ?></h3>
                        <small class="text-muted"><?php echo $last_payment ? date('M d, Y', strtotime($last_payment['payment_date'])) : 'No payments yet'; ?></small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Reports -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Recent Reports</h5>
                <a href="submit_report.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body">
                <?php
                $reports->data_seek(0);
                $count = 0;
                while ($report = $reports->fetch_assoc()) {
                    if ($count >= 5) break; // Show only 5 most recent reports
                    ?>
                    <div class="d-flex justify-content-between align-items-center mb-3 p-3 bg-light rounded">
                        <div>
                            <h6 class="mb-1"><?php echo htmlspecialchars($report['report_type']); ?></h6>
                            <p class="mb-1 text-muted"><?php echo htmlspecialchars(substr($report['description'], 0, 100)) . '...'; ?></p>
                            <small class="text-muted">Submitted: <?php echo date('M d, Y H:i', strtotime($report['created_at'])); ?></small>
                        </div>
                        <div class="d-flex align-items-center">
                            <span class="priority-<?php echo strtolower($report['priority']); ?> me-3">
                                <i class="fas fa-flag"></i> <?php echo ucfirst($report['priority']); ?>
                            </span>
                            <span class="status-badge <?php echo $report['status'] ? 'status-resolved' : 'status-pending'; ?>">
                                <?php echo $report['status'] ? 'Resolved' : 'Pending'; ?>
                            </span>
                        </div>
                    </div>
                    <?php
                    $count++;
                }
                if ($count == 0) {
                    echo '<p class="text-center text-muted my-4">No reports submitted yet.</p>';
                }
                ?>
            </div>
        </div>

        <!-- Recent Bills -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Recent Bills</h5>
                <a href="view_bills.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Bill #</th>
                                <th>Reading Date</th>
                                <th>Due Date</th>
                                <th>Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $bills->data_seek(0);
                            $count = 0;
                            while ($bill = $bills->fetch_assoc()) {
                                if ($count >= 5) break; // Show only 5 most recent bills
                                ?>
                                <tr>
                                    <td>#<?php echo $bill['id']; ?></td>
                                    <td><?php echo date('M d, Y', strtotime($bill['reading_date'])); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($bill['due_date'])); ?></td>
                                    <td>₱<?php echo number_format($bill['total'], 2); ?></td>
                                    <td>
                                        <span class="badge <?php
                                            echo $bill['status_text'] == 'Paid' ? 'bg-success' :
                                                ($bill['status_text'] == 'Overdue' ? 'bg-danger' : 'bg-warning');
                                            ?>">
                                            <?php echo $bill['status_text']; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php
                                $count++;
                            }
                            if ($count == 0) {
                                echo '<tr><td colspan="5" class="text-center">No bills found.</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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

        // Handle outage report submission
        const outageForm = document.querySelector('form');
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
                        showSuccess(data.message);
                        // Clear form
                        outageForm.reset();
                        // Optionally refresh the page or update the UI
                        setTimeout(() => window.location.reload(), 1000);
                    } else {
                        showError(data.message || 'Error submitting report');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showError('Error submitting report. Please try again.');
                });
            });
        }
    });
    </script>
</body>
</html>