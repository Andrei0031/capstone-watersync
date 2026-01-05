<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

include 'db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if password verification is required and was done
    if (!isset($_SESSION['delete_verified']) || $_SESSION['delete_verified'] !== true) {
        echo json_encode([
            'success' => false,
            'message' => 'Password verification required'
        ]);
        exit();
    }
    
    // Clear verification after use (one-time use)
    unset($_SESSION['delete_verified']);
    // Debug log
    error_log("Delete bill request received: " . print_r($_POST, true));
    
    if (!isset($_POST['bill_id'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Bill ID not provided',
            'debug' => ['post_data' => $_POST]
        ]);
        exit();
    }

    $bill_id = $_POST['bill_id'];
    
    // Validate bill_id is numeric
    if (!is_numeric($bill_id)) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid bill ID format',
            'debug' => ['bill_id' => $bill_id]
        ]);
        exit();
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // First check if the bill exists and get its details
        $check_bill = "SELECT id, status, total, client_id, due_date FROM billing_list WHERE id = ?";
        $stmt = $conn->prepare($check_bill);
        $stmt->bind_param("i", $bill_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception("Bill not found");
        }
        
        $bill_data = $result->fetch_assoc();
        $bill_status = $bill_data['status'];
        $bill_total = $bill_data['total'];
        $due_date = $bill_data['due_date'] ?? null;
        
        // Determine status text for logging
        $status_text = ($bill_status == 1) ? 'Paid' : 
                      ($due_date && strtotime($due_date) < time() ? 'Overdue' : 'Pending');

        // Delete associated records in the correct order to avoid foreign key constraints
        
        // 1. Delete notification logs (if they reference bill_id)
        $delete_notifications = "DELETE FROM notification_logs WHERE bill_id = ?";
        $stmt = $conn->prepare($delete_notifications);
        $stmt->bind_param("i", $bill_id);
        $stmt->execute();
        $notifications_deleted = $stmt->affected_rows;
        
        // 2. Delete disconnection notices (if they reference billing_id)
        $delete_notices = "DELETE FROM disconnection_notices WHERE billing_id = ?";
        $stmt = $conn->prepare($delete_notices);
        $stmt->bind_param("i", $bill_id);
        $stmt->execute();
        $notices_deleted = $stmt->affected_rows;
        
        // 3. Delete bill additional fees
        $delete_fees = "DELETE FROM bill_additional_fees WHERE bill_id = ?";
        $stmt = $conn->prepare($delete_fees);
        $stmt->bind_param("i", $bill_id);
        $stmt->execute();
        $fees_deleted = $stmt->affected_rows;
        
        // 4. Delete associated payments
        $delete_payments = "DELETE FROM payment_list WHERE billing_id = ?";
        $stmt = $conn->prepare($delete_payments);
        $stmt->bind_param("i", $bill_id);
        $stmt->execute();
        $payments_deleted = $stmt->affected_rows;
        
        // 5. Now delete the bill (works for all statuses: pending, paid, overdue)
        $delete_bill = "DELETE FROM billing_list WHERE id = ?";
        $stmt = $conn->prepare($delete_bill);
        $stmt->bind_param("i", $bill_id);
        $stmt->execute();
        $bill_deleted = $stmt->affected_rows;

        if ($bill_deleted === 0) {
            throw new Exception("Failed to delete bill");
        }

        // If we got here, everything succeeded
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "Bill #{$bill_id} ({$status_text}) deleted successfully. Removed {$notifications_deleted} notification(s), {$notices_deleted} notice(s), {$fees_deleted} fee record(s), and {$payments_deleted} payment record(s).",
            'debug' => [
                'bill_id' => $bill_id,
                'bill_status' => $status_text,
                'bill_total' => $bill_total,
                'notifications_deleted' => $notifications_deleted,
                'notices_deleted' => $notices_deleted,
                'fees_deleted' => $fees_deleted,
                'payments_deleted' => $payments_deleted,
                'bill_deleted' => $bill_deleted
            ]
        ]);
        
    } catch (Exception $e) {
        // If anything failed, roll back the transaction
        $conn->rollback();
        
        error_log("Error in delete_bill.php: " . $e->getMessage());
        
        echo json_encode([
            'success' => false,
            'message' => 'Error deleting bill: ' . $e->getMessage(),
            'debug' => [
                'bill_id' => $bill_id,
                'error' => $e->getMessage(),
                'sql_error' => $conn->error
            ]
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
}
?> 