<?php
// Start session
session_start();

// Validate session
require_once 'session_validation.php';
validateSession();

include 'db.php';
include 'comprehensive_fee_manager.php';

// Get bill_id from query parameter
$bill_id = isset($_GET['bill_id']) ? intval($_GET['bill_id']) : 0;

if (!$bill_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid bill ID']);
    exit;
}

try {
    // Verify the bill belongs to the current customer
    $verify_stmt = $conn->prepare("
        SELECT bl.client_id FROM billing_list bl
        JOIN customer_accounts ca ON bl.client_id = ca.client_id
        WHERE bl.id = ? AND ca.id = ?
    ");
    $verify_stmt->bind_param("ii", $bill_id, $_SESSION['customer_id']);
    $verify_stmt->execute();
    $verify_result = $verify_stmt->get_result();

    if ($verify_result->num_rows === 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    // Get bill details
    $bill_stmt = $conn->prepare("
        SELECT bl.*, 
               bc.rate_per_cubic,
               (bl.reading - bl.previous) as usage,
               COALESCE(SUM(p.amount), 0) as amount_paid
        FROM billing_list bl
        LEFT JOIN billing_cycles bc ON bl.billing_cycle_id = bc.id
        LEFT JOIN payment_list p ON bl.id = p.billing_id AND p.status = 1
        WHERE bl.id = ?
        GROUP BY bl.id
    ");
    $bill_stmt->bind_param("i", $bill_id);
    $bill_stmt->execute();
    $bill = $bill_stmt->get_result()->fetch_assoc();

    if (!$bill) {
        echo json_encode(['success' => false, 'message' => 'Bill not found']);
        exit;
    }

    // Get system settings for tax
    $settings_stmt = $conn->prepare("
        SELECT setting_value FROM system_settings 
        WHERE setting_key IN ('tax_rate', 'tax_enabled')
    ");
    $settings_stmt->execute();
    $settings_result = $settings_stmt->get_result();
    $settings = [];
    while ($row = $settings_result->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    $tax_rate = isset($settings['tax_rate']) ? floatval($settings['tax_rate']) : 0;
    $tax_enabled = isset($settings['tax_enabled']) ? $settings['tax_enabled'] : 0;

    // Calculate base charge
    $usage = $bill['usage'] > 0 ? $bill['usage'] : 0;
    $rate = $bill['rate_per_cubic'] ? floatval($bill['rate_per_cubic']) : 0;
    $base_charge = $usage * $rate;

    // Get applied fees
    $fees_data = [];
    $fees_stmt = $conn->prepare("
        SELECT af.id, af.fee_name, af.amount, af.fee_type
        FROM applied_fees af
        WHERE af.bill_id = ?
        ORDER BY af.id ASC
    ");
    $fees_stmt->bind_param("i", $bill_id);
    $fees_stmt->execute();
    $fees_result = $fees_stmt->get_result();

    $total_fees = 0;
    while ($fee = $fees_result->fetch_assoc()) {
        $fees_data[] = [
            'name' => $fee['fee_name'],
            'amount' => floatval($fee['amount']),
            'type' => $fee['fee_type']
        ];
        $total_fees += floatval($fee['amount']);
    }

    // Calculate subtotal before tax
    $subtotal_before_tax = $base_charge + $total_fees;

    // Calculate tax
    $tax_amount = 0;
    if ($tax_enabled && $tax_rate > 0) {
        $tax_amount = $subtotal_before_tax * ($tax_rate / 100);
    }

    // Final total
    $final_total = $subtotal_before_tax + $tax_amount;

    // Response
    $response = [
        'success' => true,
        'base_charge' => $base_charge,
        'usage' => $usage,
        'rate_per_cubic' => $rate,
        'fees' => $fees_data,
        'total_fees' => $total_fees,
        'tax_rate' => $tax_rate,
        'tax_enabled' => (bool)$tax_enabled,
        'tax_amount' => $tax_amount,
        'subtotal' => $subtotal_before_tax,
        'final_total' => $final_total,
        'amount_paid' => floatval($bill['amount_paid']),
        'remaining' => $final_total - floatval($bill['amount_paid'])
    ];

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching breakdown: ' . $e->getMessage()
    ]);
}

$conn->close();
?>
