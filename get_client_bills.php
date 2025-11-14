<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

include 'db.php';

if (isset($_GET['client_id'])) {
    $client_id = $_GET['client_id'];
    
    // Get all unpaid bills for the client with remaining balances
    $bills_sql = "WITH PaymentTotals AS (
        SELECT 
            billing_id,
            COALESCE(SUM(amount), 0) as total_paid
        FROM payment_list
        WHERE client_id = ? AND status = 1
        GROUP BY billing_id
    )
    SELECT 
        b.id,
        b.reading_date,
        b.reading,
        b.previous,
        b.total,
        COALESCE(b.total - COALESCE(pt.total_paid, 0), b.total) as balance
    FROM billing_list b
    LEFT JOIN PaymentTotals pt ON b.id = pt.billing_id
    WHERE b.client_id = ? 
    AND b.status = 0 
    AND (b.total - COALESCE(pt.total_paid, 0)) > 0
    ORDER BY b.reading_date ASC";
    
    $stmt = $conn->prepare($bills_sql);
    $stmt->bind_param("ii", $client_id, $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $bills = [];
    $running_total = 0;
    while ($row = $result->fetch_assoc()) {
        $running_total += $row['balance'];
        $bills[] = [
            'id' => $row['id'],
            'reading_date' => date('M d, Y', strtotime($row['reading_date'])),
            'reading' => $row['reading'],
            'previous' => $row['previous'],
            'total' => $row['total'],
            'balance' => $row['balance'],
            'cumulative_total' => $running_total
        ];
    }
    
    header('Content-Type: application/json');
    echo json_encode($bills);
} else {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Client ID not provided']);
}
?> 