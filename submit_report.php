<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Debug session data
var_dump($_SESSION);

// Check if user is logged in
if (!isset($_SESSION['client_id'])) {
    header("Location: customer_login.php");
    exit();
}

include 'db.php';

// Debug database connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Debug POST data
    var_dump($_POST);
    
    $client_id = $_SESSION['client_id'];
    $report_type = $_POST['report_type'];
    $description = $_POST['description'];
    $priority = $_POST['priority'];
    
    try {
        // First check if client exists in client_list
        $check_client = $conn->prepare("SELECT id FROM client_list WHERE id = ?");
        $check_client->bind_param("i", $client_id);
        $check_client->execute();
        $result = $check_client->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception("Invalid client ID");
        }
        $check_client->close();
        
        // Insert into client_reports with all required fields
        $stmt = $conn->prepare("
            INSERT INTO client_reports (
                client_id, 
                report_type, 
                description, 
                priority, 
                status,
                created_at,
                resolved_at,
                resolution_notes,
                assigned_to
            ) VALUES (
                ?, ?, ?, ?, 
                0, 
                CURRENT_TIMESTAMP,
                NULL,
                NULL,
                NULL
            )
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("isss", $client_id, $report_type, $description, $priority);
        
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $success_message = "Your report has been submitted successfully.";
        $stmt->close();
        
    } catch (Exception $e) {
        $error_message = "Error submitting report: " . $e->getMessage();
        // Debug the error
        echo "<pre>Error details: " . $e->getMessage() . "</pre>";
    }
}

// Get client's previous reports with full details
$reports_query = "
    SELECT cr.*, 
           CONCAT(cl.firstname, ' ', cl.lastname) as client_name,
           a.username as admin_name
    FROM client_reports cr
    LEFT JOIN client_list cl ON cr.client_id = cl.id
    LEFT JOIN admin a ON cr.assigned_to = a.id
    WHERE cr.client_id = ?
    ORDER BY cr.created_at DESC";

try {
    $stmt = $conn->prepare($reports_query);
    $stmt->bind_param("i", $_SESSION['client_id']);
    $stmt->execute();
    $reports = $stmt->get_result();
    $stmt->close();
} catch (Exception $e) {
    $error_message = "Error retrieving reports: " . $e->getMessage();
    error_log("Report retrieval error: " . $e->getMessage());
    $reports = false;
}

// Get the client's name
try {
    $stmt = $conn->prepare("SELECT firstname, lastname FROM client_list WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['client_id']);
    $stmt->execute();
    $client = $stmt->get_result()->fetch_assoc();
    $client_name = $client['firstname'] . ' ' . $client['lastname'];
    $stmt->close();
} catch (Exception $e) {
    $client_name = "Client";
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Report - Water Billing System</title>
    
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
        <a href="client_dashboard.php">
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
        <a href="submit_report.php" class="active">
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
        <h2 class="mb-4">Submit a Report</h2>

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

        <!-- Report Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="POST" action="">
                    <div class="mb-3">
                        <label for="report_type" class="form-label">Report Type</label>
                        <select class="form-select" id="report_type" name="report_type" required>
                            <option value="">Select a report type</option>
                            <option value="Billing Issue">Billing Issue</option>
                            <option value="Water Quality">Water Quality</option>
                            <option value="Water Pressure">Water Pressure</option>
                            <option value="Leakage">Leakage</option>
                            <option value="Meter Problem">Meter Problem</option>
                            <option value="Service Interruption">Service Interruption</option>
                            <option value="Others">Others</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="5" required
                                placeholder="Please provide detailed information about your concern..."></textarea>
                    </div>

                    <div class="mb-3">
                        <label for="priority" class="form-label">Priority Level</label>
                        <select class="form-select" id="priority" name="priority" required>
                            <option value="">Select priority level</option>
                            <option value="Low">Low - Minor issue, can wait</option>
                            <option value="Medium">Medium - Needs attention soon</option>
                            <option value="High">High - Urgent issue</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane me-2"></i>Submit Report
                    </button>
                </form>
            </div>
        </div>

        <!-- Previous Reports -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Your Previous Reports</h5>
            </div>
            <div class="card-body">
                <?php if ($reports && $reports->num_rows > 0): ?>
                    <?php while ($report = $reports->fetch_assoc()): ?>
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
                    <?php endwhile; ?>
                <?php else: ?>
                    <p class="text-center text-muted my-4">You haven't submitted any reports yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
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
    </script>
</body>
</html> 