<?php
header('Content-Type: application/json');
session_start();

// Check if user is logged in as admin
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

include 'db.php';

$reading_id = isset($_POST['reading_id']) ? intval($_POST['reading_id']) : 0;
$admin_password = isset($_POST['admin_password']) ? $_POST['admin_password'] : '';

if ($reading_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid reading ID']);
    exit();
}

// Verify admin password
if (empty($admin_password)) {
    echo json_encode(['success' => false, 'message' => 'Admin password is required']);
    exit();
}

// Get admin password from database
$admin_id = $_SESSION['admin_id'];
$admin_query = "SELECT password FROM admin WHERE id = ?";
$admin_stmt = $conn->prepare($admin_query);
$admin_stmt->bind_param("i", $admin_id);
$admin_stmt->execute();
$admin_result = $admin_stmt->get_result();

if ($admin_result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Admin not found']);
    exit();
}

$admin_data = $admin_result->fetch_assoc();
$stored_password = $admin_data['password'];

// Try different password verification methods
$password_valid = false;

// Method 1: Try password_verify (for hashed passwords)
if (password_verify($admin_password, $stored_password)) {
    $password_valid = true;
}
// Method 2: Try direct comparison (for plain text passwords)
elseif ($admin_password === $stored_password) {
    $password_valid = true;
}
// Method 3: Try MD5 comparison (for MD5 hashed passwords)
elseif (md5($admin_password) === $stored_password) {
    $password_valid = true;
}
// Method 4: Try SHA1 comparison (for SHA1 hashed passwords)
elseif (sha1($admin_password) === $stored_password) {
    $password_valid = true;
}

if (!$password_valid) {
    echo json_encode(['success' => false, 'message' => 'Invalid admin password. Please enter your correct admin password.']);
    exit();
}

try {
    // Start transaction
    $conn->begin_transaction();
    
    // First, delete related records in payment_list
    $stmt_payments = $conn->prepare("DELETE FROM payment_list WHERE billing_id = ?");
    $stmt_payments->bind_param("i", $reading_id);
    $stmt_payments->execute();
    
    // Also delete from any other related tables that might reference this billing record
    // Delete from pending_meter_readings if it exists
    $stmt_pending = $conn->prepare("DELETE FROM pending_meter_readings WHERE id = ?");
    $stmt_pending->bind_param("i", $reading_id);
    $stmt_pending->execute(); // Don't check result as it might not exist
    
    // Now delete from billing_list table
    $stmt = $conn->prepare("DELETE FROM billing_list WHERE id = ?");
    $stmt->bind_param("i", $reading_id);
    
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Historical reading and related records deleted successfully']);
        } else {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Reading not found']);
        }
    } else {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Failed to delete reading: ' . $stmt->error]);
    }
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
