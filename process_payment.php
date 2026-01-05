<?php
// Basic initialization
session_start();
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Function to send JSON response
function send_json_response($success, $message, $data = null) {
    $response = [
        'success' => $success,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    if ($data) {
        $response = array_merge($response, $data);
    }
    echo json_encode($response);
    exit;
}

// Global error handler
function handleError($errno, $errstr, $errfile, $errline) {
    send_json_response(false, 'Server error occurred', [
        'error_details' => 'An unexpected error occurred while processing your request'
    ]);
    return true;
}

// Set error handler
set_error_handler('handleError');

// Check authentication
if (!isset($_SESSION['admin_id'])) {
    send_json_response(false, 'Please log in to continue.');
}

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_response(false, 'Invalid request method.');
}

// Include database connection
require_once 'db.php';
require_once 'late_payment_processor.php';

try {
    // Validate required fields
    if (empty($_POST['client_id']) || empty($_POST['payment_date']) || 
        empty($_POST['payment_method']) || empty($_POST['reference_number']) || 
        empty($_POST['amount']) || empty($_POST['bill_ids'])) {
        send_json_response(false, 'All fields are required.');
    }

    // Parse input data
    $client_id = intval($_POST['client_id']);
    $payment_date = $_POST['payment_date'];
    $payment_method = $_POST['payment_method'];
    $reference_number = $_POST['reference_number'];
    $amount_to_pay = floatval($_POST['amount']);
    
    // Validate amount is positive
    if ($amount_to_pay <= 0) {
        send_json_response(false, 'Amount to pay must be greater than 0.');
    }
    
    if ($amount_to_pay < 0) {
        send_json_response(false, 'Amount to pay cannot be negative.');
    }
    
    // Validate bill IDs
    $bill_ids = json_decode($_POST['bill_ids'], true);
    if (!is_array($bill_ids) || empty($bill_ids)) {
        send_json_response(false, 'Please select at least one bill.');
    }

    // Start transaction
    $conn->begin_transaction();

    $payment_id = null;
    $remaining_payment = $amount_to_pay;
    $applied_late_fees = [];

    // Process each bill
    foreach ($bill_ids as $bill_id) {
        // Check and apply late fees first
        $late_fee_data = calculateLateFees($bill_id, $payment_date, $conn);
        
        if ($late_fee_data['has_late_fee'] && !$late_fee_data['already_applied']) {
            $late_fee_result = applyLateFee($bill_id, $late_fee_data, $conn);
            if ($late_fee_result['success']) {
                $applied_late_fees[] = [
                    'bill_id' => $bill_id,
                    'fee_amount' => $late_fee_result['fee_amount'],
                    'days_late' => $late_fee_result['days_late']
                ];
            }
        }
        
        // Get bill details (after potential late fee application)
        $bill_sql = "SELECT total, COALESCE((SELECT SUM(amount) FROM payment_list WHERE billing_id = ? AND status = 1), 0) as paid_amount 
                     FROM billing_list WHERE id = ?";
        $stmt = $conn->prepare($bill_sql);
        $stmt->bind_param('ii', $bill_id, $bill_id);
        $stmt->execute();
        $bill_result = $stmt->get_result()->fetch_assoc();

        if (!$bill_result) {
            throw new Exception("Invalid bill ID: $bill_id");
        }

        $remaining_bill = $bill_result['total'] - $bill_result['paid_amount'];
        if ($remaining_bill <= 0) {
            continue; // Skip fully paid bills
        }

        // Calculate payment amount for this bill
        $payment_amount = min($remaining_payment, $remaining_bill);

        // Determine payment status: auto-verify if full payment
        $is_full_payment = ($payment_amount >= $remaining_bill);
        $payment_status = $is_full_payment ? 1 : 0; // Auto-verify if full payment
        $verified_date = $is_full_payment ? date('Y-m-d H:i:s') : null;
        
        // Insert payment record
        if ($is_full_payment) {
            // Full payment - auto-verify with verified_date
            $payment_sql = "INSERT INTO payment_list (client_id, billing_id, payment_date, amount, payment_method, reference_number, status, verified_date) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($payment_sql);
            $stmt->bind_param('iisdssss', $client_id, $bill_id, $payment_date, $payment_amount, $payment_method, $reference_number, $payment_status, $verified_date);
        } else {
            // Partial payment - pending verification
            $payment_sql = "INSERT INTO payment_list (client_id, billing_id, payment_date, amount, payment_method, reference_number, status) 
                           VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($payment_sql);
            $stmt->bind_param('iisdssi', $client_id, $bill_id, $payment_date, $payment_amount, $payment_method, $reference_number, $payment_status);
        }
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to save payment record.");
        }

        if ($payment_id === null) {
            $payment_id = $conn->insert_id;
        }

        // Update bill status
        $status = ($payment_amount >= $remaining_bill) ? 1 : 0;
        $update_sql = "UPDATE billing_list SET status = ? WHERE id = ?";
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param('ii', $status, $bill_id);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to update bill status.");
        }

        $remaining_payment -= $payment_amount;
        if ($remaining_payment <= 0) {
            break;
        }
    }

    if ($payment_id === null) {
        throw new Exception("No payments were processed.");
    }

    // Commit transaction
    $conn->commit();

    // Prepare success response with late fee information
    $response_data = ['payment_id' => $payment_id];
    
    if (!empty($applied_late_fees)) {
        $total_late_fees = array_sum(array_column($applied_late_fees, 'fee_amount'));
        $response_data['late_fees_applied'] = true;
        $response_data['late_fees'] = $applied_late_fees;
        $response_data['total_late_fees'] = $total_late_fees;
        $message = 'Payment processed successfully. Late payment fees of ₱' . number_format($total_late_fees, 2) . ' were automatically applied.';
    } else {
        $message = 'Payment processed successfully.';
    }
    
    // Send success response
    send_json_response(true, $message, $response_data);

} catch (Exception $e) {
    // Rollback transaction if it was started
    if (isset($conn) && !$conn->connect_error) {
        $conn->rollback();
    }
    
    // Log the error
    error_log("Payment Processing Error: " . $e->getMessage());
    
    // Send error response
    send_json_response(false, $e->getMessage(), [
        'error_type' => get_class($e)
    ]);
}
?> 