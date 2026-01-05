<?php
session_start();
include 'db.php';

// Check if user is logged in as admin
if (!isset($_SESSION['admin_id'])) {
    header('Location: adminlogin.php');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'add_fee') {
            $fee_name = $_POST['fee_name'];
            $fee_type = $_POST['fee_type'];
            $fee_amount = $_POST['fee_amount'];
            $applies_to = $_POST['applies_to'];
            $description = $_POST['description'] ?? '';
            
            $stmt = $conn->prepare("INSERT INTO additional_fees (fee_name, fee_type, fee_amount, applies_to, description, is_active, created_by) VALUES (?, ?, ?, ?, ?, 1, ?)");
            $stmt->bind_param("ssdssi", $fee_name, $fee_type, $fee_amount, $applies_to, $description, $_SESSION['admin_id']);
            
            if ($stmt->execute()) {
                $success_message = "Fee added successfully!";
                $messageClass = "alert-success";
            } else {
                $error_message = "Error adding fee: " . $conn->error;
                $messageClass = "alert-danger";
            }
        }
        
        if ($action === 'edit_fee') {
            $fee_id = $_POST['fee_id'];
            $fee_name = $_POST['fee_name'];
            $fee_type = $_POST['fee_type'];
            $fee_amount = $_POST['fee_amount'];
            $applies_to = $_POST['applies_to'];
            $description = $_POST['description'] ?? '';
            
            $stmt = $conn->prepare("UPDATE additional_fees SET fee_name = ?, fee_type = ?, fee_amount = ?, applies_to = ?, description = ? WHERE id = ?");
            $stmt->bind_param("ssdssi", $fee_name, $fee_type, $fee_amount, $applies_to, $description, $fee_id);
            
            if ($stmt->execute()) {
                $success_message = "Fee updated successfully!";
                $messageClass = "alert-success";
            } else {
                $error_message = "Error updating fee: " . $conn->error;
                $messageClass = "alert-danger";
            }
        }
        
        if ($action === 'delete_fee') {
            $fee_id = $_POST['fee_id'];
            
            $stmt = $conn->prepare("DELETE FROM additional_fees WHERE id = ?");
            $stmt->bind_param("i", $fee_id);
            
            if ($stmt->execute()) {
                $success_message = "Fee deleted successfully!";
                $messageClass = "alert-success";
            } else {
                $error_message = "Error deleting fee: " . $conn->error;
                $messageClass = "alert-danger";
            }
        }
        
        if ($action === 'toggle_fee_status') {
            $fee_id = $_POST['fee_id'];
            $new_status = $_POST['new_status'];
            
            $stmt = $conn->prepare("UPDATE additional_fees SET is_active = ? WHERE id = ?");
            $stmt->bind_param("ii", $new_status, $fee_id);
            
            if ($stmt->execute()) {
                $success_message = "Fee status updated successfully!";
                $messageClass = "alert-success";
            } else {
                $error_message = "Error updating fee status: " . $conn->error;
                $messageClass = "alert-danger";
            }
        }
    }
}

// Create system_settings table if it doesn't exist
$create_settings_table = "CREATE TABLE IF NOT EXISTS system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";
$conn->query($create_settings_table);

// Handle delete password update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_delete_password') {
    $delete_password = $_POST['delete_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($delete_password)) {
        $error_message = "Password cannot be empty!";
    } elseif ($delete_password !== $confirm_password) {
        $error_message = "Passwords do not match!";
    } else {
        // Hash the password
        $hashed_password = password_hash($delete_password, PASSWORD_DEFAULT);
        
        // Insert or update the delete password
        $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('delete_password', ?) 
                                ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = CURRENT_TIMESTAMP");
        $stmt->bind_param("ss", $hashed_password, $hashed_password);
        
        if ($stmt->execute()) {
            $success_message = "Delete password updated successfully!";
        } else {
            $error_message = "Error updating password: " . $conn->error;
        }
    }
}

// Handle delete actions enable/disable toggle
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_delete_toggle') {
    // Checkbox sends value when checked; when unchecked, no value is present
    $enabled_flag = isset($_POST['delete_enabled']) && $_POST['delete_enabled'] === '1' ? '1' : '0';

    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('delete_enabled', ?) 
                            ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = CURRENT_TIMESTAMP");
    $stmt->bind_param("ss", $enabled_flag, $enabled_flag);
    
    if ($stmt->execute()) {
        $success_message = $enabled_flag === '1'
            ? "Delete actions have been enabled for Readings and Billing."
            : "Delete actions have been disabled for Readings and Billing.";
    } else {
        $error_message = "Error updating delete settings: " . $conn->error;
    }
}

// Get current delete password & toggle status
$password_set = false;
$delete_enabled = false;
$settings_result = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('delete_password', 'delete_enabled')");
if ($settings_result) {
    while ($row = $settings_result->fetch_assoc()) {
        if ($row['setting_key'] === 'delete_password' && !empty($row['setting_value'])) {
            $password_set = true;
        }
        if ($row['setting_key'] === 'delete_enabled' && $row['setting_value'] === '1') {
            $delete_enabled = true;
        }
    }
}

// Fetch all fees
$fees_query = "SELECT * FROM additional_fees ORDER BY created_at DESC";
$fees_result = $conn->query($fees_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Additional Fees Management - WaterSync</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="container-fluid">
        <div class="px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="fas fa-plus-circle me-2"></i>Additional Fees Management
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

            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="card-title mb-0">Fee Management</h5>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addFeeModal">
                            <i class="fas fa-plus me-2"></i>Add New Fee
                        </button>
                    </div>

                    <p class="text-muted mb-4">
                        Manage additional fees that are automatically applied to bills during the automated billing process.
                        These fees will be added to the base water consumption charges.
                    </p>

                    <!-- Additional Fees Table -->
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Fee Name</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                    <th>Applies To</th>
                                    <th>Status</th>
                                    <th>Description</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($fees_result && $fees_result->num_rows > 0): ?>
                                    <?php while ($fee = $fees_result->fetch_assoc()): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($fee['fee_name']); ?></strong></td>
                                            <td>
                                                <?php if ($fee['fee_type'] === 'fixed'): ?>
                                                    <span class="badge bg-info">Fixed Amount</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning">Percentage</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($fee['fee_type'] === 'fixed'): ?>
                                                    ₱<?php echo number_format($fee['fee_amount'], 2); ?>
                                                <?php else: ?>
                                                    <?php echo number_format($fee['fee_amount'], 2); ?>%
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary"><?php echo ucfirst($fee['applies_to']); ?></span>
                                            </td>
                                            <td>
                                                <?php if (isset($fee['is_active']) && $fee['is_active'] == 1): ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($fee['description']); ?></td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <?php if (isset($fee['is_active']) && $fee['is_active'] == 1): ?>
                                                        <button class="btn btn-warning btn-sm" onclick="toggleFeeStatus(<?php echo $fee['id']; ?>, 0)" title="Deactivate">
                                                            <i class="fas fa-pause"></i>
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="btn btn-success btn-sm" onclick="toggleFeeStatus(<?php echo $fee['id']; ?>, 1)" title="Activate">
                                                            <i class="fas fa-play"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <button class="btn btn-info btn-sm" onclick="editFee(<?php echo htmlspecialchars(json_encode($fee)); ?>)" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-danger btn-sm" onclick="deleteFee(<?php echo $fee['id']; ?>)" title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted">No fees found. Add your first fee to get started.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- How Additional Fees Work -->
                    <div class="alert alert-info mt-4">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>How Additional Fees Work:</strong>
                        <ul class="mb-0 mt-2">
                            <li>Fixed fees are added as a flat amount to each bill</li>
                            <li>Percentage fees are calculated as a percentage of the base water consumption charge</li>
                            <li>Only active fees are applied during automated bill creation</li>
                            <li>Fees can be applied to All customers, only Residential, or only Commercial</li>
                        </ul>
                    </div>

                    <!-- Delete Password Security Settings -->
                    <div class="card border-warning mt-4">
                        <div class="card-header bg-warning text-dark">
                            <h5 class="mb-0">
                                <i class="fas fa-lock me-2"></i>Delete Password Protection
                            </h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted">
                                Set a password to protect delete operations in Readings and Billing modules. 
                                This password will be required before any deletion can be performed when protection is enabled.
                            </p>
                            
                            <?php if ($password_set): ?>
                                <div class="alert alert-success">
                                    <i class="fas fa-check-circle me-2"></i>
                                    <strong>Password is set.</strong> Delete operations are protected.
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    <strong>No password set.</strong> Delete buttons will be disabled until a password is configured.
                                </div>
                            <?php endif; ?>

                            <form method="POST" class="mb-3">
                                <input type="hidden" name="action" value="update_delete_toggle">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="delete_enabled" name="delete_enabled" value="1"
                                           <?php echo $delete_enabled ? 'checked' : ''; ?>
                                           onchange="this.form.submit()">
                                    <label class="form-check-label" for="delete_enabled">
                                        Enable delete actions in Readings &amp; Billing
                                    </label>
                                </div>
                                <small class="text-muted d-block mt-1">
                                    When disabled, delete buttons will be hidden and delete requests will be blocked even if a password is set.
                                </small>
                            </form>

                            <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#deletePasswordModal">
                                <i class="fas fa-key me-2"></i>
                                <?php echo $password_set ? 'Change Delete Password' : 'Set Delete Password'; ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Fee Modal -->
    <div class="modal fade" id="addFeeModal" tabindex="-1" aria-labelledby="addFeeModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addFeeModalLabel">
                        <i class="fas fa-plus me-2"></i>Add New Fee
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_fee">
                        
                        <div class="mb-3">
                            <label for="fee_name" class="form-label">Fee Name</label>
                            <input type="text" class="form-control" id="fee_name" name="fee_name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="fee_type" class="form-label">Fee Type</label>
                            <select class="form-select" id="fee_type" name="fee_type" onchange="updateFeePrefix(this.value)" required>
                                <option value="">Select fee type</option>
                                <option value="fixed">Fixed Amount</option>
                                <option value="percentage">Percentage</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="fee_amount" class="form-label">Amount</label>
                            <div class="input-group">
                                <span class="input-group-text" id="fee_prefix">₱</span>
                                <input type="number" step="0.01" class="form-control" id="fee_amount" name="fee_amount" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="applies_to" class="form-label">Applies To</label>
                            <select class="form-select" id="applies_to" name="applies_to" required>
                                <option value="">Select customer type</option>
                                <option value="all">All Customers</option>
                                <option value="residential">Residential Only</option>
                                <option value="commercial">Commercial Only</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Fee</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Fee Modal -->
    <div class="modal fade" id="editFeeModal" tabindex="-1" aria-labelledby="editFeeModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editFeeModalLabel">
                        <i class="fas fa-edit me-2"></i>Edit Fee
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_fee">
                        <input type="hidden" name="fee_id" id="edit_fee_id">
                        
                        <div class="mb-3">
                            <label for="edit_fee_name" class="form-label">Fee Name</label>
                            <input type="text" class="form-control" id="edit_fee_name" name="fee_name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_fee_type" class="form-label">Fee Type</label>
                            <select class="form-select" id="edit_fee_type" name="fee_type" onchange="updateEditFeePrefix(this.value)" required>
                                <option value="fixed">Fixed Amount</option>
                                <option value="percentage">Percentage</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_fee_amount" class="form-label">Amount</label>
                            <div class="input-group">
                                <span class="input-group-text" id="edit_fee_prefix">₱</span>
                                <input type="number" step="0.01" class="form-control" id="edit_fee_amount" name="fee_amount" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_applies_to" class="form-label">Applies To</label>
                            <select class="form-select" id="edit_applies_to" name="applies_to" required>
                                <option value="all">All Customers</option>
                                <option value="residential">Residential Only</option>
                                <option value="commercial">Commercial Only</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_description" class="form-label">Description</label>
                            <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Fee</button>
                    </div>
                </form>
            </div>
            </div>
        </div>
    </div>

    <!-- Delete Password Modal -->
    <div class="modal fade" id="deletePasswordModal" tabindex="-1" aria-labelledby="deletePasswordModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title" id="deletePasswordModalLabel">
                        <i class="fas fa-key me-2"></i><?php echo $password_set ? 'Change' : 'Set'; ?> Delete Password
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_delete_password">
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            This password will be required to delete readings or bills. Keep it secure!
                        </div>
                        
                        <div class="mb-3">
                            <label for="delete_password" class="form-label">Delete Password</label>
                            <input type="password" class="form-control" id="delete_password" name="delete_password" required 
                                   placeholder="Enter password" minlength="4">
                            <small class="text-muted">Minimum 4 characters</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Confirm Password</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required 
                                   placeholder="Confirm password" minlength="4">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-save me-2"></i><?php echo $password_set ? 'Update' : 'Set'; ?> Password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function updateFeePrefix(type) {
            const prefix = document.getElementById('fee_prefix');
            if (type === 'percentage') {
                prefix.textContent = '%';
            } else {
                prefix.textContent = '₱';
            }
        }
        
        function updateEditFeePrefix(type) {
            const prefix = document.getElementById('edit_fee_prefix');
            if (type === 'percentage') {
                prefix.textContent = '%';
            } else {
                prefix.textContent = '₱';
            }
        }
        
        function editFee(fee) {
            document.getElementById('edit_fee_id').value = fee.id;
            document.getElementById('edit_fee_name').value = fee.fee_name;
            document.getElementById('edit_fee_type').value = fee.fee_type;
            document.getElementById('edit_fee_amount').value = fee.fee_amount;
            document.getElementById('edit_applies_to').value = fee.applies_to;
            document.getElementById('edit_description').value = fee.description || '';
            
            // Update prefix based on fee type
            updateEditFeePrefix(fee.fee_type);
            
            const editModal = new bootstrap.Modal(document.getElementById('editFeeModal'));
            editModal.show();
        }
        
        function deleteFee(feeId) {
            if (confirm('Are you sure you want to delete this fee? This action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_fee">
                    <input type="hidden" name="fee_id" value="${feeId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function toggleFeeStatus(feeId, newStatus) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="toggle_fee_status">
                <input type="hidden" name="fee_id" value="${feeId}">
                <input type="hidden" name="new_status" value="${newStatus}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
        
        // Initialize fields on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateFeePrefix('fixed');
        });
    </script>
</body>
</html>
