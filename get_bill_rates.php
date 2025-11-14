<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

include 'db.php';

if (!isset($_GET['bill_id'])) {
    echo json_encode(['success' => false, 'message' => 'Bill ID not provided']);
    exit();
}

try {
    $bill_id = intval($_GET['bill_id']);
    
    // Get client's category and rates
    $sql = "SELECT cr.rate, cr.excess_rate 
            FROM billing_list bl
            JOIN client_list cl ON bl.client_id = cl.id
            JOIN category_rates cr ON cl.category_id = cr.category_id
            WHERE bl.id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $bill_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $rate_data = $result->fetch_assoc();
    
    if (!$rate_data) {
        throw new Exception('Could not find rate information for this bill');
    }
    
    echo json_encode([
        'success' => true,
        'rate' => $rate_data['rate'],
        'excess_rate' => $rate_data['excess_rate']
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

$conn->close(); 