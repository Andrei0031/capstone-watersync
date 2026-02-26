<?php
// Start session
session_start();

// Validate session
require_once 'session_validation.php';
validateSession();

include 'db.php';

header('Content-Type: application/json');

// Get bill_id from query parameter
$bill_id = isset($_GET['bill_id']) ? intval($_GET['bill_id']) : 0;

if (!$bill_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid bill ID']);
    exit;
}

try {
    // Step 1: Get bill data with customer verification
    $bill_query = "SELECT bl.id, bl.client_id, bl.reading, bl.previous, bl.total
                   FROM billing_list bl
                   WHERE bl.id = ?";
    
    $bill_stmt = $conn->prepare($bill_query);
    if (!$bill_stmt) {
        throw new Exception('Bill query prepare error: ' . $conn->error);
    }
    
    $bill_stmt->bind_param("i", $bill_id);
    if (!$bill_stmt->execute()) {
        throw new Exception('Bill query execute error: ' . $bill_stmt->error);
    }
    
    $bill_result = $bill_stmt->get_result();
    if ($bill_result->num_rows === 0) {
        throw new Exception('Bill not found');
    }
    
    $bill_data = $bill_result->fetch_assoc();
    $client_id = $bill_data['client_id'];
    
    // Step 2: Verify customer owns this bill
    $verify_query = "SELECT ca.id FROM customer_accounts ca WHERE ca.client_id = ? AND ca.id = ?";
    $verify_stmt = $conn->prepare($verify_query);
    if (!$verify_stmt) {
        throw new Exception('Verify query prepare error: ' . $conn->error);
    }
    
    $verify_stmt->bind_param("ii", $client_id, $_SESSION['customer_id']);
    if (!$verify_stmt->execute()) {
        throw new Exception('Verify query execute error: ' . $verify_stmt->error);
    }
    
    $verify_result = $verify_stmt->get_result();
    if ($verify_result->num_rows === 0) {
        throw new Exception('Unauthorized - bill does not belong to customer');
    }
    
    // Step 3: Get client rate
    $rate = 10.00; // Default rate
    $client_query = "SELECT category_id FROM client_list WHERE id = ?";
    $client_stmt = $conn->prepare($client_query);
    if ($client_stmt) {
        $client_stmt->bind_param("i", $client_id);
        if ($client_stmt->execute()) {
            $client_result = $client_stmt->get_result();
            if ($client_result->num_rows > 0) {
                $client_data = $client_result->fetch_assoc();
                $category_id = $client_data['category_id'];
                if ($category_id == 1) $rate = 10.00;
                elseif ($category_id == 2) $rate = 12.00;
                elseif ($category_id == 3) $rate = 15.00;
            }
        }
    }
    
    // Step 4: Calculate usage and base charge
    $reading = floatval($bill_data['reading']);
    $previous = floatval($bill_data['previous']);
    $total_bill = floatval($bill_data['total']);
    $usage = max(0, $reading - $previous);
    $base_charge = $usage * $rate;
    
    // Step 5: Get applied fees
    $fees_data = [];
    $total_fees = 0;
    $fees_query = "SELECT fee_name, amount FROM applied_fees WHERE bill_id = ?";
    $fees_stmt = $conn->prepare($fees_query);
    if ($fees_stmt) {
        $fees_stmt->bind_param("i", $bill_id);
        if ($fees_stmt->execute()) {
            $fees_result = $fees_stmt->get_result();
            while ($fee = $fees_result->fetch_assoc()) {
                $fee_amount = floatval($fee['amount']);
                $fees_data[] = [
                    'name' => $fee['fee_name'],
                    'amount' => $fee_amount
                ];
                $total_fees += $fee_amount;
            }
        }
    }
    
    // Step 6: Calculate tax
    $tax_rate = 0;
    $tax_enabled = false;
    $tax_query = "SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('tax_rate', 'tax_enabled')";
    $tax_stmt = $conn->prepare($tax_query);
    if ($tax_stmt) {
        if ($tax_stmt->execute()) {
            $tax_result = $tax_stmt->get_result();
            while ($row = $tax_result->fetch_assoc()) {
                if ($row['setting_key'] === 'tax_rate') {
                    $tax_rate = floatval($row['setting_value']);
                } elseif ($row['setting_key'] === 'tax_enabled') {
                    $tax_enabled = $row['setting_value'] == 1;
                }
            }
        }
    }
    
    // Step 7: Calculate totals
    $subtotal = $base_charge + $total_fees;
    $tax_amount = 0;
    if ($tax_enabled && $tax_rate > 0) {
        $tax_amount = $subtotal * ($tax_rate / 100);
    }
    
    // Step 8: Get payment info
    $amount_paid = 0;
    $payment_query = "SELECT COALESCE(SUM(amount), 0) as total_paid FROM payment_list WHERE billing_id = ? AND status = 1";
    $payment_stmt = $conn->prepare($payment_query);
    if ($payment_stmt) {
        $payment_stmt->bind_param("i", $bill_id);
        if ($payment_stmt->execute()) {
            $payment_result = $payment_stmt->get_result();
            if ($payment_result->num_rows > 0) {
                $payment_data = $payment_result->fetch_assoc();
                $amount_paid = floatval($payment_data['total_paid']);
            }
        }
    }
    
    $remaining = $total_bill - $amount_paid;
    
    // Step 9: Return response
    echo json_encode([
        'success' => true,
        'base_charge' => round($base_charge, 2),
        'usage' => round($usage, 2),
        'rate_per_cubic' => $rate,
        'fees' => $fees_data,
        'total_fees' => round($total_fees, 2),
        'tax_rate' => $tax_rate,
        'tax_enabled' => $tax_enabled,
        'tax_amount' => round($tax_amount, 2),
        'subtotal' => round($subtotal, 2),
        'final_total' => round($total_bill, 2),
        'amount_paid' => round($amount_paid, 2),
        'remaining' => round($remaining, 2)
    ]);

} catch (Exception $e) {
    error_log('Breakdown Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?>
