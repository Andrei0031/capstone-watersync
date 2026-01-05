<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

include 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    // Check if delete actions are enabled in settings
    $settings_result = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'delete_enabled'");
    $delete_enabled = false;
    if ($settings_result && $row = $settings_result->fetch_assoc()) {
        $delete_enabled = ($row['setting_value'] === '1');
    }
    if (!$delete_enabled) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Delete actions are currently disabled in settings.']);
        exit();
    }

    // Check if password verification is required and was done
    if (!isset($_SESSION['delete_verified']) || $_SESSION['delete_verified'] !== true) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Password verification required']);
        exit();
    }

    // Clear verification after use (one-time use)
    unset($_SESSION['delete_verified']);

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