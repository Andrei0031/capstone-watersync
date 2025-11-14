<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: adminlogin.php");
    exit();
}

include 'db.php';

// Handle cycle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_cycle') {
        $cycle_name = $_POST['cycle_name'];
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $due_date = $_POST['due_date'];
        $description = $_POST['description'] ?? '';
        
        $stmt = $conn->prepare("INSERT INTO billing_cycles (cycle_name, start_date, end_date, due_date, description, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssi", $cycle_name, $start_date, $end_date, $due_date, $description, $_SESSION['admin_id']);
        
        if ($stmt->execute()) {
            $success_message = "Billing cycle created successfully!";
        } else {
            $error_message = "Error creating billing cycle: " . $conn->error;
        }
    }
    
    if ($action === 'activate_cycle') {
        $cycle_id = $_POST['cycle_id'];
        
        // Deactivate all other cycles first
        $conn->query("UPDATE billing_cycles SET status = 'completed' WHERE status = 'active'");
        
        // Activate the selected cycle
        $stmt = $conn->prepare("UPDATE billing_cycles SET status = 'active', activated_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $cycle_id);
        
        if ($stmt->execute()) {
            $success_message = "Billing cycle activated successfully!";
        } else {
            $error_message = "Error activating billing cycle: " . $conn->error;
        }
    }
    
    if ($action === 'complete_cycle') {
        $cycle_id = $_POST['cycle_id'];
        
        $stmt = $conn->prepare("UPDATE billing_cycles SET status = 'completed', completed_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $cycle_id);
        
        if ($stmt->execute()) {
            $success_message = "Billing cycle completed successfully!";
        } else {
            $error_message = "Error completing billing cycle: " . $conn->error;
        }
    }
}

// Get all billing cycles
$cycles_sql = "SELECT bc.*, a.username as created_by_name,
               COUNT(pmr.id) as total_readings,
               SUM(CASE WHEN pmr.status = 'pending' THEN 1 ELSE 0 END) as pending_readings,
               SUM(CASE WHEN pmr.status = 'processed' THEN 1 ELSE 0 END) as processed_readings
               FROM billing_cycles bc
               LEFT JOIN admin a ON bc.created_by = a.id
               LEFT JOIN pending_meter_readings pmr ON bc.id = pmr.billing_cycle_id
               GROUP BY bc.id
               ORDER BY bc.created_at DESC";
$cycles_result = $conn->query($cycles_sql);

// Get current active cycle
$active_cycle_sql = "SELECT * FROM billing_cycles WHERE status = 'active' LIMIT 1";
$active_cycle_result = $conn->query($active_cycle_sql);
$active_cycle = $active_cycle_result->fetch_assoc();

// Get total clients
$total_clients_sql = "SELECT COUNT(*) as total FROM client_list WHERE status = 1";
$total_clients_result = $conn->query($total_clients_sql);
$total_clients = $total_clients_result->fetch_assoc()['total'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billing Cycles Management - WaterSync</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2563eb;
            --primary-hover: #1d4ed8;
            --sidebar-width: 280px;
            --header-height: 70px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8fafc;
            line-height: 1.6;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(180deg, #1e40af 0%, #1e3a8a 100%);
            color: white;
            transform: translateX(0);
            transition: transform 0.3s ease;
            z-index: 1000;
            box-shadow: 4px 0 10px rgba(0, 0, 0, 0.1);
        }

        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
        }

        .nav-content {
            padding: 1rem 0;
        }

        .nav-content a {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }

        .nav-content a:hover, .nav-content a.active {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
            border-left-color: #fbbf24;
        }

        .nav-content a i {
            width: 20px;
            margin-right: 12px;
        }

        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            background-color: #f8fafc;
        }

        .header {
            background: white;
            padding: 1rem 2rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-bottom: 1px solid #e5e7eb;
        }

        .content-area {
            padding: 2rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            border-left: 4px solid;
        }

        .stat-card.primary { border-left-color: #3b82f6; }
        .stat-card.success { border-left-color: #10b981; }
        .stat-card.warning { border-left-color: #f59e0b; }
        .stat-card.info { border-left-color: #06b6d4; }

        .cycle-status {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .status-planned { background: #ddd6fe; color: #7c3aed; }
        .status-active { background: #dcfce7; color: #16a34a; }
        .status-completed { background: #e5e7eb; color: #6b7280; }
        .status-cancelled { background: #fee2e2; color: #dc2626; }

        .btn-custom {
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-primary-custom {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary-custom:hover {
            background: var(--primary-hover);
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .main-content {
                margin-left: 0;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
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
            <a href="billing_list.php">
                <i class="fas fa-file-invoice-dollar"></i>
                <span>Bills</span>
            </a>
            <a href="pending_readings.php">
                <i class="fas fa-camera"></i>
                <span>Meter Readings</span>
            </a>
            <a href="billing_cycles.php" class="active">
                <i class="fas fa-calendar-alt"></i>
                <span>Billing Cycles</span>
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
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="mb-0">Billing Cycles Management</h4>
                    <small class="text-muted">Manage monthly billing cycles and meter reading periods</small>
                </div>
                <button class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#createCycleModal">
                    <i class="fas fa-plus me-2"></i>Create New Cycle
                </button>
            </div>
        </div>

        <div class="content-area">
            <!-- Success/Error Messages -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Current Active Cycle Info -->
            <?php if ($active_cycle): ?>
                <div class="card mb-4 border-success">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-calendar-check me-2"></i>Current Active Billing Cycle
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8">
                                <h6 class="text-success"><?php echo htmlspecialchars($active_cycle['cycle_name']); ?></h6>
                                <p class="mb-2">
                                    <strong>Period:</strong> 
                                    <?php echo date('M d, Y', strtotime($active_cycle['start_date'])); ?> - 
                                    <?php echo date('M d, Y', strtotime($active_cycle['end_date'])); ?>
                                </p>
                                <p class="mb-2">
                                    <strong>Due Date:</strong> 
                                    <?php echo date('M d, Y', strtotime($active_cycle['due_date'])); ?>
                                </p>
                                <?php if ($active_cycle['description']): ?>
                                    <p class="mb-0 text-muted"><?php echo htmlspecialchars($active_cycle['description']); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-4 text-end">
                                <div class="mb-2">
                                    <span class="badge bg-success fs-6">Active</span>
                                </div>
                                <div>
                                    <?php
                                    $days_left = max(0, ceil((strtotime($active_cycle['end_date']) - time()) / (24 * 3600)));
                                    ?>
                                    <strong class="text-primary"><?php echo $days_left; ?> days remaining</strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>No Active Billing Cycle</strong> - Please create and activate a billing cycle to enable meter reading collection.
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="text-primary"><?php echo $total_clients; ?></h3>
                            <p class="mb-0 text-muted">Total Active Clients</p>
                        </div>
                        <i class="fas fa-users fa-2x text-primary opacity-50"></i>
                    </div>
                </div>

                <?php if ($active_cycle): ?>
                    <?php
                    // Get reading stats for active cycle
                    $stats_sql = "SELECT 
                        COUNT(pmr.id) as total_readings,
                        SUM(CASE WHEN pmr.status = 'pending' THEN 1 ELSE 0 END) as pending_readings,
                        SUM(CASE WHEN pmr.status = 'processed' THEN 1 ELSE 0 END) as processed_readings
                        FROM pending_meter_readings pmr 
                        WHERE pmr.billing_cycle_id = ?";
                    $stats_stmt = $conn->prepare($stats_sql);
                    $stats_stmt->bind_param("i", $active_cycle['id']);
                    $stats_stmt->execute();
                    $stats = $stats_stmt->get_result()->fetch_assoc();
                    ?>

                    <div class="stat-card success">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h3 class="text-success"><?php echo $stats['processed_readings'] ?? 0; ?></h3>
                                <p class="mb-0 text-muted">Processed Readings</p>
                            </div>
                            <i class="fas fa-check-circle fa-2x text-success opacity-50"></i>
                        </div>
                    </div>

                    <div class="stat-card warning">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h3 class="text-warning"><?php echo $stats['pending_readings'] ?? 0; ?></h3>
                                <p class="mb-0 text-muted">Pending Readings</p>
                            </div>
                            <i class="fas fa-clock fa-2x text-warning opacity-50"></i>
                        </div>
                    </div>

                    <div class="stat-card info">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <?php 
                                $progress = $total_clients > 0 ? round((($stats['total_readings'] ?? 0) / $total_clients) * 100, 1) : 0;
                                ?>
                                <h3 class="text-info"><?php echo $progress; ?>%</h3>
                                <p class="mb-0 text-muted">Collection Progress</p>
                            </div>
                            <i class="fas fa-chart-pie fa-2x text-info opacity-50"></i>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Billing Cycles Table -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-calendar-alt me-2"></i>All Billing Cycles
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Cycle Name</th>
                                    <th>Period</th>
                                    <th>Due Date</th>
                                    <th>Status</th>
                                    <th>Readings</th>
                                    <th>Created By</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($cycle = $cycles_result->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($cycle['cycle_name']); ?></strong>
                                            <?php if ($cycle['description']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($cycle['description']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo date('M d', strtotime($cycle['start_date'])); ?> - 
                                            <?php echo date('M d, Y', strtotime($cycle['end_date'])); ?>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($cycle['due_date'])); ?></td>
                                        <td>
                                            <span class="cycle-status status-<?php echo $cycle['status']; ?>">
                                                <?php echo ucfirst($cycle['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary"><?php echo $cycle['total_readings']; ?></span>
                                            <small class="text-muted">
                                                (<?php echo $cycle['pending_readings']; ?> pending, 
                                                <?php echo $cycle['processed_readings']; ?> processed)
                                            </small>
                                        </td>
                                        <td><?php echo htmlspecialchars($cycle['created_by_name']); ?></td>
                                        <td>
                                            <?php if ($cycle['status'] === 'planned'): ?>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="activate_cycle">
                                                    <input type="hidden" name="cycle_id" value="<?php echo $cycle['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-success" 
                                                            onclick="return confirm('Activate this billing cycle? This will deactivate the current active cycle.')">
                                                        <i class="fas fa-play"></i> Activate
                                                    </button>
                                                </form>
                                            <?php elseif ($cycle['status'] === 'active'): ?>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="complete_cycle">
                                                    <input type="hidden" name="cycle_id" value="<?php echo $cycle['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-warning"
                                                            onclick="return confirm('Complete this billing cycle? This action cannot be undone.')">
                                                        <i class="fas fa-check"></i> Complete
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            
                                            <a href="pending_readings.php?cycle_id=<?php echo $cycle['id']; ?>" 
                                               class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-eye"></i> View Readings
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Cycle Modal -->
    <div class="modal fade" id="createCycleModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Billing Cycle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create_cycle">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="cycle_name" class="form-label">Cycle Name *</label>
                                    <input type="text" class="form-control" id="cycle_name" name="cycle_name" 
                                           placeholder="e.g., January 2024 Billing" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="due_date" class="form-label">Bill Due Date *</label>
                                    <input type="date" class="form-control" id="due_date" name="due_date" required>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="start_date" class="form-label">Reading Period Start *</label>
                                    <input type="date" class="form-control" id="start_date" name="start_date" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="end_date" class="form-label">Reading Period End *</label>
                                    <input type="date" class="form-control" id="end_date" name="end_date" required>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description (Optional)</label>
                            <textarea class="form-control" id="description" name="description" rows="2" 
                                      placeholder="Additional notes about this billing cycle..."></textarea>
                        </div>

                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Mobile App Integration:</strong> Once activated, meter readers using the mobile app 
                            will automatically submit readings to this billing cycle when connected to the same network.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary-custom">Create Billing Cycle</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-fill dates when start date is selected
        document.getElementById('start_date').addEventListener('change', function() {
            const startDate = new Date(this.value);
            const endDate = new Date(startDate);
            endDate.setMonth(endDate.getMonth() + 1);
            endDate.setDate(0); // Last day of the month
            
            const dueDate = new Date(endDate);
            dueDate.setDate(dueDate.getDate() + 15); // 15 days after end date
            
            document.getElementById('end_date').value = endDate.toISOString().split('T')[0];
            document.getElementById('due_date').value = dueDate.toISOString().split('T')[0];
        });
    </script>
</body>
</html> 