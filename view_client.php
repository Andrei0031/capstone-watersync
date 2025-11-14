<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: adminlogin.php");
    exit();
}

include 'db.php';

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: view_clients.php");
    exit();
}

$client_id = intval($_GET['id']);

$sql = "SELECT * FROM client_list WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $client_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    $stmt->close();
    $conn->close();
    header("Location: view_clients.php");
    exit();
}

$client = $result->fetch_assoc();
$stmt->close();

// Get category name
$category_name = '';
$category_sql = "SELECT name FROM categories WHERE id = ?";
$category_stmt = $conn->prepare($category_sql);
$category_stmt->bind_param("i", $client['category_id']);
$category_stmt->execute();
$category_result = $category_stmt->get_result();
if ($category_result && $category_result->num_rows > 0) {
    $category_data = $category_result->fetch_assoc();
    $category_name = $category_data['name'];
}
$category_stmt->close();

?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="icon" href="logo.png" />
    <title>Customer Details - Water Billing System</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <!-- Google Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" />
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
        }

        body {
            font-family: 'Open Sans', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            transition: background-color 0.3s, color 0.3s;
            margin: 0;
            padding: 0;
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

        .back-link {
            display: inline-flex;
            align-items: center;
            padding: 8px 15px;
            color: var(--text-color);
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.3s ease;
            margin-bottom: 20px;
        }

        .back-link:hover {
            background-color: var(--hover-bg);
            color: var(--hover-text);
        }

        .back-link i {
            margin-right: 8px;
        }

        .client-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .client-avatar {
            width: 100px;
            height: 100px;
            background: linear-gradient(45deg, #4e73df 0%, #224abe 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2.5rem;
            font-weight: 600;
            margin: 0 auto 15px;
        }

        .info-card {
            height: 100%;
        }

        .info-card .card-body {
            padding: 1.5rem;
        }

        .info-label {
            font-size: 0.875rem;
            color: var(--muted-text);
            margin-bottom: 0.25rem;
        }

        .info-value {
            font-size: 1rem;
            color: var(--text-color);
            font-weight: 500;
            margin-bottom: 1rem;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .status-active {
            background-color: #1cc88a20;
            color: #1cc88a;
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

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <img src="icons/Logo.png" alt="Water Billing Logo" class="img-fluid" style="max-height: 90px;" />
    </div>
    <div class="nav-content">
        <a href="adminlandingpage.php">
            <i class="fas fa-chart-line"></i>
            <span>Dashboard</span>
        </a>
        <a href="view_clients.php" class="active">
            <i class="fas fa-users"></i>
            <span>Customers</span>
        </a>
        <a href="billing_list.php">
            <i class="fas fa-file-invoice-dollar"></i>
            <span>Bills</span>
        </a>
        <a href="#">
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
        <form method="POST" action="logout.php" class="mt-3 px-3">
            <button type="submit" class="btn btn-outline-primary w-100">
                <i class="fas fa-sign-out-alt me-2"></i>Logout
            </button>
        </form>
    </div>
</div>

<div class="main-content">
    <a href="view_clients.php" class="back-link">
        <i class="fas fa-arrow-left"></i> Back to Customers
    </a>

    <div class="client-header">
        <div class="client-avatar">
            <?php 
                $initials = strtoupper(substr($client['firstname'], 0, 1) . substr($client['lastname'], 0, 1));
                echo $initials;
            ?>
        </div>
        <h2 class="mb-1"><?php echo htmlspecialchars($client['firstname'] . ' ' . $client['lastname']); ?></h2>
        <p class="text-muted mb-0">Customer ID: <?php echo htmlspecialchars($client['code']); ?></p>
        <span class="status-badge status-active mt-2">Active Customer</span>
    </div>

    <div class="row g-4">
        <!-- Personal Information -->
        <div class="col-md-6">
            <div class="card card-soft info-card">
                <div class="card-body">
                    <h5 class="card-title mb-4">Personal Information</h5>
                    
                    <div class="info-label">Full Name</div>
                    <div class="info-value">
                        <?php echo htmlspecialchars($client['firstname'] . ' ' . 
                        ($client['middlename'] ? $client['middlename'] . ' ' : '') . 
                        $client['lastname']); ?>
                    </div>

                    <div class="info-label">Contact Number</div>
                    <div class="info-value"><?php echo htmlspecialchars($client['contact']); ?></div>

                    <div class="info-label">Address</div>
                    <div class="info-value"><?php echo htmlspecialchars($client['address']); ?></div>
                </div>
            </div>
        </div>

        <!-- Billing Information -->
        <div class="col-md-6">
            <div class="card card-soft info-card">
                <div class="card-body">
                    <h5 class="card-title mb-4">Billing Information</h5>

                    <div class="info-label">Category</div>
                    <div class="info-value"><?php echo htmlspecialchars($category_name); ?></div>

                    <div class="info-label">Meter Code</div>
                    <div class="info-value"><?php echo htmlspecialchars($client['meter_code']); ?></div>

                    <div class="info-label">Customer Code</div>
                    <div class="info-value"><?php echo htmlspecialchars($client['code']); ?></div>
                </div>
            </div>
        </div>

        <!-- Recent Bills -->
        <div class="col-12">
            <div class="card card-soft">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="card-title mb-0">Recent Bills</h5>
                        <button class="btn btn-primary btn-sm">
                            <i class="fas fa-plus me-2"></i>Add New Bill
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Bill Date</th>
                                    <th>Due Date</th>
                                    <th>Reading</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="6" class="text-center text-muted">No billing records found</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

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

    // Sidebar toggle for mobile
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
</body>
</html>

<?php
$conn->close();
?>
