<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: adminlogin.php");
    exit();
}

include 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id = $_POST['client_id'];
    $billing_id = $_POST['billing_id'];
    $reference_number = $_POST['reference_number'];
    $amount = $_POST['amount'];
    $payment_date = $_POST['payment_date'];
    $payment_method = $_POST['payment_method'];
    $notes = $_POST['notes'] ?? '';
    
    // Insert payment record
    $insert_sql = "INSERT INTO payment_list (client_id, billing_id, reference_number, amount, payment_date, payment_method, notes, status) 
                   VALUES (?, ?, ?, ?, ?, ?, ?, 0)";
    $stmt = $conn->prepare($insert_sql);
    $stmt->bind_param("iisdsss", $client_id, $billing_id, $reference_number, $amount, $payment_date, $payment_method, $notes);
    
    if ($stmt->execute()) {
        $payment_id = $conn->insert_id;
        // Update bill status
        $update_bill = "UPDATE billing_list SET status = 1 WHERE id = ?";
        $bill_stmt = $conn->prepare($update_bill);
        $bill_stmt->bind_param("i", $billing_id);
        $bill_stmt->execute();
        
        header("Location: payments.php?success=Payment recorded successfully&payment_id=" . $payment_id);
    } else {
        header("Location: payments.php?error=Failed to record payment");
    }
    exit();
}
?> 