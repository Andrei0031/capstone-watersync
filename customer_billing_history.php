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

// Pagination settings
$records_per_page = 20;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $records_per_page;

// Filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$year_filter = isset($_GET['year']) ? $_GET['year'] : 'all';

// Build WHERE clause for filters
$where_conditions = ["b.client_id = ?"];
$params = [$_SESSION['client_id']];
$param_types = "i";

if ($status_filter !== 'all') {
    if ($status_filter === 'paid') {
        $where_conditions[] = "b.status = 1";
    } elseif ($status_filter === 'pending') {
        $where_conditions[] = "b.status = 0 AND b.due_date >= CURRENT_DATE";
    } elseif ($status_filter === 'overdue') {
        $where_conditions[] = "b.status = 0 AND b.due_date < CURRENT_DATE";
    }
}

if ($year_filter !== 'all') {
    $where_conditions[] = "YEAR(b.reading_date) = ?";
    $params[] = $year_filter;
    $param_types .= "i";
}

$where_clause = implode(" AND ", $where_conditions);

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total 
              FROM billing_list b 
              WHERE " . $where_clause;
$stmt = $conn->prepare($count_sql);
$stmt->bind_param($param_types, ...$params);
$stmt->execute();
$total_records = $stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get all bills with payment information
$sql = "WITH PaymentTotals AS (
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
WHERE " . $where_clause . "
ORDER BY b.reading_date DESC
LIMIT ? OFFSET ?";

$all_params = array_merge([$_SESSION['client_id']], $params, [$records_per_page, $offset]);
$all_param_types = "i" . $param_types . "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($all_param_types, ...$all_params);
$stmt->execute();
$bills = $stmt->get_result();

// Get available years for filter
$years_sql = "SELECT DISTINCT YEAR(reading_date) as year 
              FROM billing_list 
              WHERE client_id = ? 
              ORDER BY year DESC";
$stmt = $conn->prepare($years_sql);
$stmt->bind_param("i", $_SESSION['client_id']);
$stmt->execute();
$years = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billing History - Water Billing System</title>
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

        .table {
            margin-bottom: 0;
        }

        .table th {
            background: var(--accent-color);
            border: none;
            font-weight: 600;
            color: var(--secondary-color);
            padding: 15px 12px;
        }

        .table td {
            border: none;
            padding: 12px;
            vertical-align: middle;
        }

        .table tbody tr {
            border-bottom: 1px solid #e9ecef;
        }

        .table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .badge {
            padding: 8px 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .btn {
            border-radius: 8px;
            padding: 8px 16px;
            font-weight: 500;
        }

        .form-select {
            border-radius: 8px;
        }

        .pagination .page-link {
            border-radius: 8px;
            margin: 0 2px;
            border: none;
            color: var(--primary-color);
        }

        .pagination .page-item.active .page-link {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        @media (max-width: 768px) {
            .table-responsive {
                border-radius: 8px;
            }
            
            .d-md-none {
                display: block !important;
            }
            
            .d-none.d-md-table-cell {
                display: none !important;
            }
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
                    <i class="fas fa-file-invoice me-2 text-primary"></i>
                    Complete Billing History
                </h2>
                <p class="text-muted mt-2">View all your billing records and payment history</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label for="status" class="form-label">Filter by Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="paid" <?php echo $status_filter === 'paid' ? 'selected' : ''; ?>>Paid</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="overdue" <?php echo $status_filter === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="year" class="form-label">Filter by Year</label>
                        <select class="form-select" id="year" name="year">
                            <option value="all" <?php echo $year_filter === 'all' ? 'selected' : ''; ?>>All Years</option>
                            <?php while ($year_row = $years->fetch_assoc()): ?>
                                <option value="<?php echo $year_row['year']; ?>" <?php echo $year_filter == $year_row['year'] ? 'selected' : ''; ?>>
                                    <?php echo $year_row['year']; ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="fas fa-filter me-1"></i>Apply Filters
                        </button>
                        <a href="customer_billing_history.php" class="btn btn-outline-secondary">
                            <i class="fas fa-times me-1"></i>Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Billing History Table -->
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-file-invoice me-2"></i>
                        Billing Records
                    </h5>
                    <span class="badge bg-primary"><?php echo $total_records; ?> Total Records</span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>Reading Date</th>
                                <th>Readings</th>
                                <th>Usage (m³)</th>
                                <th>Amount</th>
                                <th class="d-none d-md-table-cell">Due Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($bills->num_rows > 0): ?>
                                <?php while ($bill = $bills->fetch_assoc()): 
                                    $remaining = $bill['remaining_balance'];
                                    $is_overdue = strtotime($bill['due_date']) < time() && $bill['status'] == 0;
                                    $is_paid = $bill['status'] == 1 || $remaining <= 0;
                                ?>
                                <tr>
                                    <td>
                                        <div>
                                            <strong><?php echo date('M d, Y', strtotime($bill['reading_date'])); ?></strong>
                                            <small class="d-block d-md-none text-muted">
                                                Due: <?php echo date('M d, Y', strtotime($bill['due_date'])); ?>
                                            </small>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-column">
                                            <small class="text-muted">Previous: <?php echo number_format($bill['previous']); ?></small>
                                            <small>Current: <?php echo number_format($bill['reading']); ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <strong><?php echo number_format($bill['reading'] - $bill['previous']); ?></strong>
                                    </td>
                                    <td>
                                        <div>
                                            <strong>₱<?php echo number_format($bill['total'], 2); ?></strong>
                                            <?php if (!$is_paid): ?>
                                                <small class="d-block <?php echo $is_overdue ? 'text-danger' : 'text-warning'; ?>">
                                                    Due: ₱<?php echo number_format($remaining, 2); ?>
                                                </small>
                                            <?php else: ?>
                                                <small class="d-block text-success">
                                                    Paid: ₱<?php echo number_format($bill['amount_paid'], 2); ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="d-none d-md-table-cell">
                                        <?php echo date('M d, Y', strtotime($bill['due_date'])); ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php 
                                            if ($is_paid) {
                                                echo 'bg-success';
                                            } else {
                                                echo $is_overdue ? 'bg-danger' : 'bg-warning';
                                            }
                                        ?>">
                                            <?php echo $bill['status_text']; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">
                                    <i class="fas fa-info-circle me-2"></i>No billing records found
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="d-flex justify-content-center mt-4">
            <nav aria-label="Billing history pagination">
                <ul class="pagination">
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?>&status=<?php echo $status_filter; ?>&year=<?php echo $year_filter; ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>
                    <?php endif; ?>
                    
                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    
                    for ($i = $start_page; $i <= $end_page; $i++):
                    ?>
                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo $status_filter; ?>&year=<?php echo $year_filter; ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?>&status=<?php echo $status_filter; ?>&year=<?php echo $year_filter; ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>

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

    <!-- Edit Profile Modal -->
    <div class="modal fade" id="editProfileModal" tabindex="-1">
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
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div class="modal fade" id="changePasswordModal" tabindex="-1">
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
                    </div>
                    <div class="modal-footer border-0 pt-0">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Change Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 