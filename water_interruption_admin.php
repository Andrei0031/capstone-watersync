<?php
session_start();
include 'db.php';
include 'notification_manager.php';
include 'water_interruption_manager.php';

// Check if user is logged in as admin
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

$notification_manager = new NotificationManager($conn);
$interruption_manager = new WaterInterruptionManager($conn, $notification_manager);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['create_interruption'])) {
        $title = $_POST['title'];
        $description = $_POST['description'];
        $affected_areas = $_POST['affected_areas'];
        $estimated_restoration = $_POST['estimated_restoration'];
        
        $result = $interruption_manager->createInterruptionFromAdmin(
            $_SESSION['admin_id'], 
            $title, 
            $description, 
            explode(',', $affected_areas), 
            $estimated_restoration
        );
        
        if ($result['success']) {
            $success_message = "Water interruption reported and notifications sent successfully!";
        } else {
            $error_message = "Error: " . $result['error'];
        }
    }
    
    if (isset($_POST['update_status'])) {
        $interruption_id = $_POST['interruption_id'];
        $status = $_POST['status'];
        $notes = $_POST['notes'];
        
        $result = $interruption_manager->updateInterruptionStatus($interruption_id, $status, $notes);
        
        if ($result['success']) {
            $success_message = "Interruption status updated successfully!";
        } else {
            $error_message = "Error: " . $result['error'];
        }
    }
}

// Get active interruptions
$active_interruptions = $interruption_manager->getActiveInterruptions();
$interruption_history = $interruption_manager->getInterruptionHistory(20);

// Get all areas for dropdown
$areas_stmt = $conn->query("SELECT DISTINCT area FROM client_list WHERE area IS NOT NULL ORDER BY area");
$areas = $areas_stmt->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Water Interruption Management - WaterSync</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 d-md-block bg-light sidebar">
                <div class="position-sticky pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="billing_list.php">
                                <i class="fas fa-file-invoice me-2"></i>Billing Management
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="water_interruption_admin.php">
                                <i class="fas fa-exclamation-triangle me-2"></i>Water Interruptions
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="view_clients.php">
                                <i class="fas fa-users me-2"></i>Customer Management
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Main content -->
            <div class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-exclamation-triangle me-2"></i>Water Interruption Management
                    </h1>
                </div>

                <!-- Alert Messages -->
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

                <!-- Create New Interruption -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-plus-circle me-2"></i>Report New Water Interruption
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="title" class="form-label">Title</label>
                                        <input type="text" class="form-control" id="title" name="title" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="estimated_restoration" class="form-label">Estimated Restoration</label>
                                        <input type="text" class="form-control" id="estimated_restoration" name="estimated_restoration" placeholder="e.g., 2 hours, Tomorrow 8 AM">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="3" required placeholder="Describe the water interruption, cause, and any additional details..."></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label for="affected_areas" class="form-label">Affected Areas</label>
                                <select class="form-select" id="affected_areas" name="affected_areas" multiple required>
                                    <?php foreach ($areas as $area): ?>
                                        <option value="<?php echo $area['area']; ?>"><?php echo $area['area']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted">Hold Ctrl/Cmd to select multiple areas</small>
                            </div>
                            
                            <button type="submit" name="create_interruption" class="btn btn-warning">
                                <i class="fas fa-broadcast-tower me-2"></i>Report Interruption & Send Notifications
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Active Interruptions -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-exclamation-circle me-2"></i>Active Interruptions
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($active_interruptions)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>No active water interruptions.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Title</th>
                                            <th>Affected Areas</th>
                                            <th>Estimated Restoration</th>
                                            <th>Reported</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($active_interruptions as $interruption): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($interruption['title']); ?></strong>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($interruption['description']); ?></small>
                                                </td>
                                                <td>
                                                    <?php 
                                                    $areas = json_decode($interruption['affected_areas'], true);
                                                    echo implode(', ', $areas);
                                                    ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($interruption['estimated_restoration'] ?: 'Not specified'); ?></td>
                                                <td>
                                                    <?php echo date('M d, Y H:i', strtotime($interruption['created_at'])); ?>
                                                    <br><small class="text-muted">by <?php echo htmlspecialchars($interruption['reported_by']); ?></small>
                                                </td>
                                                <td>
                                                    <button class="btn btn-sm btn-success" onclick="updateInterruptionStatus(<?php echo $interruption['id']; ?>, 'resolved')">
                                                        <i class="fas fa-check me-1"></i>Mark Resolved
                                                    </button>
                                                    <button class="btn btn-sm btn-secondary" onclick="updateInterruptionStatus(<?php echo $interruption['id']; ?>, 'cancelled')">
                                                        <i class="fas fa-times me-1"></i>Cancel
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Interruption History -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-history me-2"></i>Interruption History
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Status</th>
                                        <th>Affected Areas</th>
                                        <th>Created</th>
                                        <th>Updated</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($interruption_history as $interruption): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($interruption['title']); ?></strong>
                                                <?php if ($interruption['notes']): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($interruption['notes']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $interruption['status'] === 'resolved' ? 'success' : ($interruption['status'] === 'active' ? 'warning' : 'secondary'); ?>">
                                                    <?php echo ucfirst($interruption['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php 
                                                $areas = json_decode($interruption['affected_areas'], true);
                                                echo implode(', ', $areas);
                                                ?>
                                            </td>
                                            <td><?php echo date('M d, Y H:i', strtotime($interruption['created_at'])); ?></td>
                                            <td><?php echo date('M d, Y H:i', strtotime($interruption['updated_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Update Status Modal -->
    <div class="modal fade" id="updateStatusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Interruption Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="updateStatusForm">
                    <div class="modal-body">
                        <input type="hidden" id="interruption_id" name="interruption_id">
                        <input type="hidden" id="status" name="status">
                        
                        <div class="mb-3">
                            <label for="notes" class="form-label">Notes (Optional)</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="Add any additional notes about the status update..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_status" class="btn btn-primary">Update Status</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function updateInterruptionStatus(interruptionId, status) {
            document.getElementById('interruption_id').value = interruptionId;
            document.getElementById('status').value = status;
            
            const modal = new bootstrap.Modal(document.getElementById('updateStatusModal'));
            modal.show();
        }
    </script>
</body>
</html>
