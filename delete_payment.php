<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

include 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $payment_id = $_POST['id'];
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Get the billing_id before deleting the payment
        $stmt = $conn->prepare("SELECT billing_id FROM payment_list WHERE id = ?");
        $stmt->bind_param("i", $payment_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $payment = $result->fetch_assoc();
        
        if ($payment) {
            $billing_id = $payment['billing_id'];
            
            // Delete the payment
            $stmt = $conn->prepare("DELETE FROM payment_list WHERE id = ?");
            $stmt->bind_param("i", $payment_id);
            $stmt->execute();
            
            // Update the billing status back to unpaid (status = 0)
            $stmt = $conn->prepare("UPDATE billing_list SET status = 0 WHERE id = ?");
            $stmt->bind_param("i", $billing_id);
            $stmt->execute();
            
            // Commit transaction
            $conn->commit();
            
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
        } else {
            throw new Exception("Payment not found");
        }
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
?> 