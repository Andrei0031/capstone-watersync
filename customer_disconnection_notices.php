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

// Handle acknowledgment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'acknowledge') {
    $notice_id = intval($_POST['notice_id']);
    $stmt = $conn->prepare("UPDATE disconnection_notices SET status = 'acknowledged', acknowledged_at = NOW() WHERE id = ? AND client_id = ?");
    $stmt->bind_param("ii", $notice_id, $_SESSION['client_id']);
    $stmt->execute();
}

// Get disconnection notices for this customer
$sql = "SELECT dn.*, 
               a.username as created_by_username
        FROM disconnection_notices dn
        JOIN admin a ON dn.created_by = a.id
        WHERE dn.client_id = ?
        ORDER BY dn.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $_SESSION['client_id']);
$stmt->execute();
$notices = $stmt->get_result();

// Count notices by status
$stats_sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status IN ('pending', 'sent') THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN notice_type = 'disconnection_order' AND status IN ('pending', 'sent') THEN 1 ELSE 0 END) as critical
              FROM disconnection_notices 
              WHERE client_id = ?";
$stmt = $conn->prepare($stats_sql);
$stmt->bind_param("i", $_SESSION['client_id']);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disconnection Notices - Water Billing System</title>
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

        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }

        .notice-card {
            border-left: 5px solid;
            margin-bottom: 20px;
        }

        .notice-card.first_warning {
            border-left-color: var(--warning-color);
        }

        .notice-card.final_notice {
            border-left-color: #ff9800;
        }

        .notice-card.disconnection_order {
            border-left-color: var(--danger-color);
        }

        .notice-content {
            white-space: pre-line;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
        }

        .status-badge {
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .alert-critical {
            background: linear-gradient(45deg, #f44336, #ff6b6b);
            color: white;
            border: none;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.02); }
            100% { transform: scale(1); }
        }

        .days-overdue {
            background: var(--danger-color);
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: bold;
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
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark mb-4">
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
                        <a class="nav-link" href="customer_dashboard.php">
                            <i class="fas fa-home"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="customer_billing_history.php">
                            <i class="fas fa-file-invoice"></i> Billing History
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="client_notices.php">
                            <i class="fas fa-bell"></i> Notices
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="customer_disconnection_notices.php">
                            <i class="fas fa-exclamation-triangle"></i> Disconnection Notices
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

    <div class="container my-4">
        <!-- Page Header -->
        <div class="row mb-4">
            <div class="col">
                <h2 class="mb-0">
                    <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                    Disconnection Notices
                </h2>
                <p class="text-muted mt-2">Important notices regarding your water service</p>
            </div>
        </div>

        <!-- Critical Alert -->
        <?php if ($stats['critical'] > 0): ?>
        <div class="alert alert-critical mb-4">
            <div class="d-flex align-items-center">
                <i class="fas fa-exclamation-triangle fa-2x me-3"></i>
                <div>
                    <h5 class="mb-1">⚠️ URGENT ACTION REQUIRED</h5>
                    <p class="mb-0">
                        You have <?php echo $stats['critical']; ?> disconnection order(s). 
                        Please contact our office immediately to avoid service termination.
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body text-center">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Total Notices</h6>
                                <h3 class="mb-0"><?php echo $stats['total']; ?></h3>
                            </div>
                            <i class="fas fa-file-alt fa-2x text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body text-center">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Active Notices</h6>
                                <h3 class="mb-0"><?php echo $stats['active']; ?></h3>
                            </div>
                            <i class="fas fa-exclamation-circle fa-2x text-warning"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body text-center">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Critical Notices</h6>
                                <h3 class="mb-0 text-danger"><?php echo $stats['critical']; ?></h3>
                            </div>
                            <i class="fas fa-exclamation-triangle fa-2x text-danger"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Notices List -->
        <div class="row">
            <div class="col">
                <?php if ($notices && $notices->num_rows > 0): ?>
                    <?php while ($notice = $notices->fetch_assoc()): ?>
                    <div class="card notice-card <?php echo $notice['notice_type']; ?>">
                        <div class="card-header">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h5 class="card-title mb-1">
                                        <?php if ($notice['notice_type'] === 'disconnection_order'): ?>
                                            <i class="fas fa-exclamation-triangle text-danger me-2"></i>
                                        <?php elseif ($notice['notice_type'] === 'final_notice'): ?>
                                            <i class="fas fa-exclamation-circle text-warning me-2"></i>
                                        <?php else: ?>
                                            <i class="fas fa-info-circle text-info me-2"></i>
                                        <?php endif; ?>
                                        <?php echo htmlspecialchars($notice['title']); ?>
                                    </h5>
                                    <div class="d-flex align-items-center gap-3">
                                        <span class="status-badge bg-<?php 
                                            echo $notice['status'] === 'pending' ? 'warning' : 
                                                ($notice['status'] === 'sent' ? 'primary' : 
                                                ($notice['status'] === 'acknowledged' ? 'info' : 
                                                ($notice['status'] === 'resolved' ? 'success' : 'secondary')));
                                        ?>">
                                            <?php echo ucfirst($notice['status']); ?>
                                        </span>
                                        <span class="badge bg-<?php 
                                            echo $notice['notice_type'] === 'first_warning' ? 'warning' : 
                                                ($notice['notice_type'] === 'final_notice' ? 'danger' : 'dark');
                                        ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $notice['notice_type'])); ?>
                                        </span>
                                        <span class="days-overdue">
                                            <?php echo $notice['overdue_days']; ?> days overdue
                                        </span>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <div class="h5 mb-1 text-danger">₱<?php echo number_format($notice['amount_due'], 2); ?></div>
                                    <small class="text-muted">
                                        <?php echo date('M j, Y', strtotime($notice['created_at'])); ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="notice-content">
                                <?php echo nl2br(htmlspecialchars($notice['description'])); ?>
                            </div>
                            
                            <?php if ($notice['disconnection_date'] && in_array($notice['status'], ['pending', 'sent'])): ?>
                            <div class="alert alert-danger mb-3">
                                <i class="fas fa-calendar-times me-2"></i>
                                <strong>Scheduled Disconnection Date:</strong> 
                                <?php echo date('F j, Y', strtotime($notice['disconnection_date'])); ?>
                                <br>
                                <small>You have until this date to settle your account and avoid service disconnection.</small>
                            </div>
                            <?php endif; ?>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <h6>Notice Details</h6>
                                    <ul class="list-unstyled">
                                        <li><strong>Original Due Date:</strong> <?php echo date('M j, Y', strtotime($notice['due_date'])); ?></li>
                                        <li><strong>Days Overdue:</strong> <?php echo $notice['overdue_days']; ?> days</li>
                                        <li><strong>Grace Period:</strong> <?php echo $notice['grace_period_days']; ?> days</li>
                                        <?php if ($notice['sent_at']): ?>
                                        <li><strong>Sent:</strong> <?php echo date('M j, Y g:i A', strtotime($notice['sent_at'])); ?></li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <h6>What to Do Next</h6>
                                    <ul class="list-unstyled">
                                        <li><i class="fas fa-credit-card text-primary me-2"></i>Pay your outstanding balance immediately</li>
                                        <li><i class="fas fa-phone text-success me-2"></i>Contact our office for payment arrangements</li>
                                        <li><i class="fas fa-clock text-warning me-2"></i>Act before the disconnection date</li>
                                        <?php if ($notice['status'] === 'sent' && $notice['notice_type'] !== 'disconnection_order'): ?>
                                        <li><i class="fas fa-check text-info me-2"></i>Acknowledge this notice</li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </div>
                            
                            <div class="d-flex justify-content-between align-items-center mt-3">
                                <small class="text-muted">
                                    Notice created by: <?php echo htmlspecialchars($notice['created_by_username']); ?>
                                </small>
                                <?php if ($notice['status'] === 'sent' && !in_array($notice['status'], ['acknowledged', 'resolved'])): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="acknowledge">
                                    <input type="hidden" name="notice_id" value="<?php echo $notice['id']; ?>">
                                    <button type="submit" class="btn btn-outline-primary btn-sm">
                                        <i class="fas fa-check me-1"></i>Acknowledge Notice
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                        <h4>No Disconnection Notices</h4>
                        <p class="text-muted">You currently have no disconnection notices. Keep your account current to avoid future notices.</p>
                        <a href="customer_dashboard.php" class="btn btn-primary">
                            <i class="fas fa-home me-1"></i>Go to Dashboard
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Contact Information -->
        <div class="card mt-4">
            <div class="card-body">
                <h5 class="card-title">
                    <i class="fas fa-phone me-2"></i>Need Help?
                </h5>
                <p class="card-text">
                    If you have received a disconnection notice, please contact our office immediately to discuss 
                    payment options or resolve any billing issues.
                </p>
                <div class="row">
                    <div class="col-md-6">
                        <h6>Contact Information</h6>
                        <ul class="list-unstyled">
                            <li><i class="fas fa-phone me-2"></i>Phone: (123) 456-7890</li>
                            <li><i class="fas fa-envelope me-2"></i>Email: billing@watersync.com</li>
                            <li><i class="fas fa-map-marker-alt me-2"></i>Office Hours: Mon-Fri 8AM-5PM</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6>Payment Options</h6>
                        <ul class="list-unstyled">
                            <li><i class="fas fa-building me-2"></i>Office Payment</li>
                            <li><i class="fas fa-mobile-alt me-2"></i>Online Payment</li>
                            <li><i class="fas fa-university me-2"></i>Bank Transfer</li>
                            <li><i class="fas fa-handshake me-2"></i>Payment Plan Available</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Profile Modal (same as other customer pages) -->
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
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#editProfileModal">
                            <i class="fas fa-edit me-2"></i>Edit Profile
                        </button>
                        <button type="button" class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                            <i class="fas fa-key me-2"></i>Change Password
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 