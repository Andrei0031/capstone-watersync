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
    // Verify the bill belongs to the current customer and get client_id
    $verify_sql = "SELECT bl.id, bl.client_id, bl.reading, bl.previous, bl.total, 
                          ca.id as customer_id
                   FROM billing_list bl
                   JOIN customer_accounts ca ON bl.client_id = ca.client_id
                   WHERE bl.id = ? AND ca.id = ?";
    
    $verify_stmt = $conn->prepare($verify_sql);
    if (!$verify_stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    
    $verify_stmt->bind_param("ii", $bill_id, $_SESSION['customer_id']);
    if (!$verify_stmt->execute()) {
        throw new Exception('Execute failed: ' . $verify_stmt->error);
    }
    
    $verify_result = $verify_stmt->get_result();
    $bill_data = $verify_result->fetch_assoc();

    if (!$bill_data) {
        echo json_encode(['success' => false, 'message' => 'Bill not found or unauthorized']);
        exit;
    }

    $client_id = $bill_data['client_id'];
    $reading = floatval($bill_data['reading']);
    $previous = floatval($bill_data['previous']);
    $total_bill = floatval($bill_data['total']);
    $usage = $reading - $previous;
    $usage = max(0, $usage); // Ensure non-negative

    // Get client category to determine rate
    $client_sql = "SELECT category_id FROM client_list WHERE id = ?";
    $client_stmt = $conn->prepare($client_sql);
    if (!$client_stmt) {
        throw new Exception('Prepare client failed: ' . $conn->error);
    }
    $client_stmt->bind_param("i", $client_id);
    if (!$client_stmt->execute()) {
        throw new Exception('Execute client failed: ' . $client_stmt->error);
    }
    $client_result = $client_stmt->get_result();
    $client = $client_result->fetch_assoc();
    
    // Set rate based on category
    $category_id = $client['category_id'] ?? 1;
    $rate_map = [1 => 10.00, 2 => 12.00, 3 => 15.00];
    $rate = $rate_map[$category_id] ?? 10.00;

    // Calculate base charge
    $base_charge = $usage * $rate;

    // Get applied fees for this bill
    $fees_sql = "SELECT fee_name, amount FROM applied_fees WHERE bill_id = ? ORDER BY id ASC";
    $fees_stmt = $conn->prepare($fees_sql);
    if (!$fees_stmt) {
        throw new Exception('Prepare fees failed: ' . $conn->error);
    }
    $fees_stmt->bind_param("i", $bill_id);
    if (!$fees_stmt->execute()) {
        throw new Exception('Execute fees failed: ' . $fees_stmt->error);
    }
    $fees_result = $fees_stmt->get_result();
    
    $fees_data = [];
    $total_fees = 0;
    while ($fee = $fees_result->fetch_assoc()) {
        $fee_amount = floatval($fee['amount']);
        $fees_data[] = [
            'name' => $fee['fee_name'],
            'amount' => $fee_amount
        ];
        $total_fees += $fee_amount;
    }

    // Get tax settings
    $tax_rate = 0;
    $tax_enabled = false;
    $tax_sql = "SELECT setting_key, setting_value FROM system_settings 
                WHERE setting_key IN ('tax_rate', 'tax_enabled')";
    $tax_stmt = $conn->prepare($tax_sql);
    if ($tax_stmt) {
        $tax_stmt->execute();
        $tax_result = $tax_stmt->get_result();
        while ($row = $tax_result->fetch_assoc()) {
            if ($row['setting_key'] === 'tax_rate') {
                $tax_rate = floatval($row['setting_value']);
            } elseif ($row['setting_key'] === 'tax_enabled') {
                $tax_enabled = $row['setting_value'] == 1;
            }
        }
    }

    // Calculate subtotal and tax
    $subtotal = $base_charge + $total_fees;
    $tax_amount = 0;
    if ($tax_enabled && $tax_rate > 0) {
        $tax_amount = $subtotal * ($tax_rate / 100);
    }

    // Final total from calculation (should match bill total)
    $calculated_total = $subtotal + $tax_amount;

    // Get payment info
    $payment_sql = "SELECT COALESCE(SUM(amount), 0) as total_paid FROM payment_list 
                    WHERE billing_id = ? AND status = 1";
    $payment_stmt = $conn->prepare($payment_sql);
    if (!$payment_stmt) {
        throw new Exception('Prepare payment failed: ' . $conn->error);
    }
    $payment_stmt->bind_param("i", $bill_id);
    if (!$payment_stmt->execute()) {
        throw new Exception('Execute payment failed: ' . $payment_stmt->error);
    }
    $payment_result = $payment_stmt->get_result();
    $payment_info = $payment_result->fetch_assoc();
    $amount_paid = floatval($payment_info['total_paid'] ?? 0);
    $remaining = $total_bill - $amount_paid;

    // Build response
    $response = [
        'success' => true,
        'base_charge' => round($base_charge, 2),
        'usage' => $usage,
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
    ];

    echo json_encode($response);

} catch (Exception $e) {
    error_log('Breakdown Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}

$conn->close();
?>
