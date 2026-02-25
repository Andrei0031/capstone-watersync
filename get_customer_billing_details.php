<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

include 'db.php';

header('Content-Type: application/json');

if (!isset($_GET['client_id'])) {
    echo json_encode(['success' => false, 'message' => 'Client ID not provided']);
    exit();
}

$client_id = intval($_GET['client_id']);

try {
    // Get customer information
    $client_sql = "SELECT cl.*, c.name as category_name, cr.rate, cr.excess_rate
                   FROM client_list cl
                   LEFT JOIN categories c ON cl.category_id = c.id
                   LEFT JOIN category_rates cr ON cl.category_id = cr.category_id
                   WHERE cl.id = ?";
    $stmt = $conn->prepare($client_sql);
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $client = $stmt->get_result()->fetch_assoc();
    
    if (!$client) {
        throw new Exception('Client not found');
    }
    
    // Get all billing history with payment information
    $billing_sql = "WITH PaymentTotals AS (
        SELECT 
            billing_id,
            COALESCE(SUM(amount), 0) as total_paid,
            MAX(payment_date) as last_payment_date
        FROM payment_list
        WHERE billing_id IN (SELECT id FROM billing_list WHERE client_id = ?)
          AND status = 1
        GROUP BY billing_id
    )
    SELECT 
        b.*,
        LEAST(COALESCE(pt.total_paid, 0), COALESCE(b.total, 0)) as amount_paid,
        GREATEST(COALESCE(b.total - LEAST(COALESCE(pt.total_paid, 0), COALESCE(b.total, 0)), b.total), 0) as remaining_balance,
        pt.last_payment_date,
        CASE 
            WHEN b.status = 1 THEN 'Paid'
            WHEN b.status = 0 AND b.due_date < CURRENT_DATE THEN 'Overdue'
            ELSE 'Unpaid'
        END as status_text,
        CASE 
            WHEN b.status = 0 AND b.due_date < CURRENT_DATE THEN DATEDIFF(CURRENT_DATE(), b.due_date)
            ELSE 0
        END as days_overdue
    FROM billing_list b
    LEFT JOIN PaymentTotals pt ON b.id = pt.billing_id
    WHERE b.client_id = ?
    ORDER BY b.reading_date DESC";
    
    $stmt = $conn->prepare($billing_sql);
    $stmt->bind_param("ii", $client_id, $client_id);
    $stmt->execute();
    $bills_result = $stmt->get_result();
    
    $bills = [];
    while ($bill = $bills_result->fetch_assoc()) {
        $bill['consumption'] = $bill['reading'] - $bill['previous'];
        $bill['reading_date_formatted'] = $bill['reading_date'] ? date('M d, Y', strtotime($bill['reading_date'])) : '';
        $bill['due_date_formatted'] = $bill['due_date'] ? date('M d, Y', strtotime($bill['due_date'])) : '';
        $bill['billing_month'] = $bill['reading_date'] ? date('F Y', strtotime($bill['reading_date'])) : '';
        $bills[] = $bill;
    }
    
    // Calculate statistics
    $stats_sql = "SELECT 
        COUNT(*) as total_bills,
        SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as paid_bills,
        SUM(CASE WHEN status = 0 AND due_date < CURRENT_DATE THEN 1 ELSE 0 END) as overdue_bills,
        SUM(CASE WHEN status = 0 AND due_date >= CURRENT_DATE THEN 1 ELSE 0 END) as unpaid_bills,
        COALESCE(SUM(total), 0) as total_billed,
        COALESCE(SUM(CASE WHEN status = 1 THEN total ELSE 0 END), 0) as total_paid,
        COALESCE(SUM(CASE WHEN status = 0 THEN total ELSE 0 END), 0) as total_outstanding
    FROM billing_list
    WHERE client_id = ?";
    
    $stmt = $conn->prepare($stats_sql);
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();
    
    echo json_encode([
        'success' => true,
        'client' => $client,
        'bills' => $bills,
        'statistics' => $stats
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>

