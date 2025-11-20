<?php
header('Content-Type: application/json');

include 'db.php';
include 'late_payment_processor.php';

if (!isset($_GET['client_id'])) {
    echo json_encode(['error' => 'Client ID is required']);
    exit;
}

$client_id = intval($_GET['client_id']);

try {
    // Get unpaid bills for the client
    $stmt = $conn->prepare("
        SELECT 
            b.id,
            b.reading_date,
            b.due_date,
            b.reading,
            b.previous,
            b.rate,
            b.total,
            b.status,
            COALESCE((
                SELECT SUM(amount) 
                FROM payment_list 
                WHERE billing_id = b.id AND status = 1
            ), 0) as paid_amount
        FROM billing_list b
        WHERE b.client_id = ? AND b.status = 0
        ORDER BY b.reading_date DESC
    ");
    
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $bills = [];
    $current_date = date('Y-m-d');
    
    while ($row = $result->fetch_assoc()) {
        $balance = $row['total'] - $row['paid_amount'];
        
        // Check for potential late fees
        $late_fee_info = calculateLateFees($row['id'], $current_date, $conn);
        
        // Get already applied fees
        $applied_fees = getAppliedFees($row['id'], $conn);
        
        $bill_data = [
            'id' => $row['id'],
            'reading_date' => date('M d, Y', strtotime($row['reading_date'])),
            'due_date' => date('M d, Y', strtotime($row['due_date'])),
            'due_date_raw' => $row['due_date'],
            'reading' => $row['reading'],
            'previous' => $row['previous'],
            'consumption' => $row['reading'] - $row['previous'],
            'total' => $row['total'],
            'paid_amount' => $row['paid_amount'],
            'balance' => $balance, // Keep as number for JavaScript
            'is_overdue' => strtotime($row['due_date']) < strtotime($current_date),
            'days_overdue' => max(0, ceil((strtotime($current_date) - strtotime($row['due_date'])) / (60 * 60 * 24))),
            'late_fee_info' => $late_fee_info,
            'applied_fees' => $applied_fees,
            'has_applied_fees' => !empty($applied_fees)
        ];
        
        $bills[] = $bill_data;
    }
    
    // Return just the bills array to match expected format
    echo json_encode($bills);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?> 