<?php
session_start();
require_once 'session_validation.php';
include 'db.php';

// Check if report ID is provided
if (!isset($_GET['id'])) {
    header('Location: adminlandingpage.php');
    exit();
}

$report_id = $_GET['id'];

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $new_status = $_POST['status'];
    $resolution_notes = $_POST['resolution_notes'];
    
    $update_sql = "UPDATE outage_reports SET 
                   status = ?, 
                   resolution_notes = ?,
                   resolved_at = " . ($new_status == 1 ? "CURRENT_TIMESTAMP" : "NULL") . "
                   WHERE id = ?";
    
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param("isi", $new_status, $resolution_notes, $report_id);
    $stmt->execute();
    
    // Redirect to refresh the page
    header("Location: view_report.php?id=" . $report_id . "&updated=1");
    exit();
}

// Get report details
$stmt = $conn->prepare("
    SELECT o.*, cl.firstname, cl.lastname, cl.meter_code
    FROM outage_reports o
    JOIN client_list cl ON o.client_id = cl.id
    WHERE o.id = ?
");
$stmt->bind_param("i", $report_id);
$stmt->execute();
$report = $stmt->get_result()->fetch_assoc();

if (!$report) {
    header('Location: adminlandingpage.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Report - Water Billing System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-4">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-file-alt me-2"></i>Report Details
                </h5>
                <a href="adminlandingpage.php" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                </a>
            </div>
            <div class="card-body">
                <?php if (isset($_GET['updated'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i>Report status updated successfully
                </div>
                <?php endif; ?>

                <div class="row mb-4">
                    <div class="col-md-6">
                        <h6 class="text-muted">Client Information</h6>
                        <p><strong>Name:</strong> <?php echo htmlspecialchars($report['firstname'] . ' ' . $report['lastname']); ?></p>
                        <p><strong>Meter Code:</strong> <?php echo htmlspecialchars($report['meter_code']); ?></p>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-muted">Report Information</h6>
                        <p><strong>Submitted:</strong> <?php echo date('M d, Y h:i A', strtotime($report['created_at'])); ?></p>
                        <p><strong>Status:</strong> 
                            <span class="badge <?php echo $report['status'] ? 'bg-success' : 'bg-warning'; ?>">
                                <?php echo $report['status'] ? 'Resolved' : 'Pending'; ?>
                            </span>
                        </p>
                    </div>
                </div>

                <div class="mb-4">
                    <h6 class="text-muted">Location</h6>
                    <p><?php echo htmlspecialchars($report['location']); ?></p>
                </div>

                <div class="mb-4">
                    <h6 class="text-muted">Description</h6>
                    <p><?php echo nl2br(htmlspecialchars($report['description'])); ?></p>
                </div>

                <?php if ($report['status'] == 1 && !empty($report['resolution_notes'])): ?>
                <div class="mb-4">
                    <h6 class="text-muted">Resolution Notes</h6>
                    <p><?php echo nl2br(htmlspecialchars($report['resolution_notes'])); ?></p>
                </div>
                <?php endif; ?>

                <form method="POST" class="mt-4">
                    <div class="mb-3">
                        <label class="form-label">Update Status</label>
                        <select name="status" class="form-select">
                            <option value="0" <?php echo !$report['status'] ? 'selected' : ''; ?>>Pending</option>
                            <option value="1" <?php echo $report['status'] ? 'selected' : ''; ?>>Resolved</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Resolution Notes</label>
                        <textarea name="resolution_notes" class="form-control" rows="3"><?php echo htmlspecialchars($report['resolution_notes'] ?? ''); ?></textarea>
                    </div>
                    <button type="submit" name="update_status" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Update Report
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 