<?php
session_start();
include 'db.php';

// Check if user is logged in as admin
if (!isset($_SESSION['admin_id'])) {
    header('Location: adminlogin.php');
    exit();
}

$message = '';
$messageClass = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_rates'])) {
        $residential_rate = $_POST['residential_rate'];
        $residential_excess_rate = $_POST['residential_excess_rate'];
        $commercial_rate = $_POST['commercial_rate'];
        $commercial_excess_rate = $_POST['commercial_excess_rate'];

        // Start transaction
        $conn->begin_transaction();

        try {
            // Update residential rates
            $stmt = $conn->prepare("UPDATE category_rates SET rate = ?, excess_rate = ? WHERE category_id = 1");
            $stmt->bind_param("dd", $residential_rate, $residential_excess_rate);
            $residential_success = $stmt->execute();
            $stmt->close();

            // Update commercial rates
            $stmt = $conn->prepare("UPDATE category_rates SET rate = ?, excess_rate = ? WHERE category_id = 2");
            $stmt->bind_param("dd", $commercial_rate, $commercial_excess_rate);
            $commercial_success = $stmt->execute();
            $stmt->close();

            if ($residential_success && $commercial_success) {
                // Update all unpaid bills with new rates
                $update_bills_sql = "
                    UPDATE billing_list bl
                    JOIN client_list cl ON bl.client_id = cl.id
                    JOIN category_rates cr ON cl.category_id = cr.category_id
                    SET bl.total = 
                        CASE 
                            WHEN (bl.reading - bl.previous) <= 6 
                            THEN cr.rate
                            ELSE cr.rate + ((bl.reading - bl.previous - 6) * cr.excess_rate)
                        END
                    WHERE bl.status = 0";
                
                if ($conn->query($update_bills_sql)) {
                    $conn->commit();
                    $message = "Rates and all unpaid bills updated successfully!";
                    $messageClass = "alert-success";
                } else {
                    throw new Exception("Error updating bills");
                }
            } else {
                throw new Exception("Error updating rates");
            }
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error updating rates and bills: " . $e->getMessage();
            $messageClass = "alert-danger";
        }
    }
}

// Fetch current rates
$rates_query = "SELECT * FROM category_rates ORDER BY category_id";
$rates_result = $conn->query($rates_query);
$rates = [];
while ($rate = $rates_result->fetch_assoc()) {
    $rates[$rate['category_id']] = $rate;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Water Rates Management - WaterSync</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="container-fluid">
        <div class="px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="fas fa-tint me-2"></i>Water Rates Management
                </h1>
            </div>

            <!-- Alert Messages -->
            <?php if ($message): ?>
                <div class="alert <?php echo $messageClass; ?> alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-body">
                    <h5 class="card-title mb-4">Update Water Rates</h5>
                    
                    <form method="POST" action="">
                        <div class="row">
                            <div class="col-md-6">
                                <fieldset class="mb-4 p-3 border rounded">
                                    <legend class="w-auto px-2 h6">Residential Rates</legend>
                                    <div class="mb-3">
                                        <label for="residential_rate" class="form-label">Rate per 6 m³ (₱)</label>
                                        <input type="number" step="0.01" class="form-control" id="residential_rate" name="residential_rate" value="<?php echo htmlspecialchars($rates[1]['rate'] ?? '0.00'); ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="residential_excess_rate" class="form-label">Excess Rate per m³ (₱)</label>
                                        <input type="number" step="0.01" class="form-control" id="residential_excess_rate" name="residential_excess_rate" value="<?php echo htmlspecialchars($rates[1]['excess_rate'] ?? '0.00'); ?>" required>
                                    </div>
                                </fieldset>
                            </div>
                            <div class="col-md-6">
                                <fieldset class="mb-4 p-3 border rounded">
                                    <legend class="w-auto px-2 h6">Commercial Rates</legend>
                                    <div class="mb-3">
                                        <label for="commercial_rate" class="form-label">Rate per 6 m³ (₱)</label>
                                        <input type="number" step="0.01" class="form-control" id="commercial_rate" name="commercial_rate" value="<?php echo htmlspecialchars($rates[2]['rate'] ?? '0.00'); ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="commercial_excess_rate" class="form-label">Excess Rate per m³ (₱)</label>
                                        <input type="number" step="0.01" class="form-control" id="commercial_excess_rate" name="commercial_excess_rate" value="<?php echo htmlspecialchars($rates[2]['excess_rate'] ?? '0.00'); ?>" required>
                                    </div>
                                </fieldset>
                            </div>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Important:</strong> Updating rates will automatically recalculate all unpaid bills with the new rates. This action cannot be undone.
                        </div>
                        
                        <button type="submit" name="update_rates" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Update Rates & Unpaid Bills
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
