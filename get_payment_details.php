<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

include 'db.php';
date_default_timezone_set('Asia/Manila');

if (isset($_GET['id'])) {
    $payment_id = $_GET['id'];
    
    // Get payment details with client and bill information
    $payment_sql = "SELECT 
        pl.*, 
        cl.firstname, 
        cl.lastname, 
        cl.meter_code,
        DATE_FORMAT(COALESCE(pl.verified_date, pl.payment_date), '%M %d, %Y %r') as formatted_payment_date,
        DATE_FORMAT(pl.verified_date, '%M %d, %Y %r') as formatted_verified_date,
        bl.reading_date, 
        bl.reading, 
        bl.previous, 
        bl.total as bill_total,
        CASE WHEN pl.status = 1 THEN 'Verified' ELSE 'Pending' END as status_text
    FROM payment_list pl 
    JOIN client_list cl ON pl.client_id = cl.id 
    JOIN billing_list bl ON pl.billing_id = bl.id 
    WHERE pl.id = ?";
    
    $stmt = $conn->prepare($payment_sql);
    $stmt->bind_param("i", $payment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $payment = $result->fetch_assoc();
    
    if ($payment) {
        // Calculate total amount paid for all payments with the same reference number and payment date
        // This handles cases where multiple bills were paid in one transaction
        $total_paid_sql = "SELECT SUM(amount) as total_amount_paid 
                          FROM payment_list 
                          WHERE reference_number = ? 
                          AND DATE(payment_date) = DATE(?) 
                          AND client_id = ?";
        $total_stmt = $conn->prepare($total_paid_sql);
        $total_stmt->bind_param("ssi", $payment['reference_number'], $payment['payment_date'], $payment['client_id']);
        $total_stmt->execute();
        $total_result = $total_stmt->get_result()->fetch_assoc();
        $total_amount_paid = $total_result['total_amount_paid'] ?? $payment['amount'];
        
        // Format numbers
        $payment['amount'] = number_format($payment['amount'], 2);
        $payment['total_amount_paid'] = number_format($total_amount_paid, 2);
        $payment['bill_total'] = number_format($payment['bill_total'], 2);
        
        header('Content-Type: application/json');
        echo json_encode($payment);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Payment not found']);
    }
} else {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No payment ID provided']);
}
?> 