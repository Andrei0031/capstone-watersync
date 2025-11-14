<?php
header('Content-Type: application/json');
session_start();

// Check if user is logged in as admin
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

include 'db.php';

$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;

if ($client_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid client ID']);
    exit();
}

try {
    // Get historical readings from billing_list table (which contains the processed readings)
    $sql = "
        SELECT 
            id,
            reading,
            previous,
            total as amount,
            reading_date,
            status
        FROM billing_list
        WHERE client_id = ?
        ORDER BY reading_date DESC
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $readings = [];
    while ($row = $result->fetch_assoc()) {
        $consumption = max(0, floatval($row['reading']) - floatval($row['previous']));
        $readings[] = [
            'id' => $row['id'],
            'reading' => floatval($row['reading']),
            'previous_reading' => floatval($row['previous']),
            'consumption' => $consumption,
            'amount' => floatval($row['amount']),
            'reading_date' => $row['reading_date'],
            'status' => $row['status'] == 1 ? 'paid' : 'unpaid',
            'cycle_name' => 'N/A', // Will be added later when billing cycles are properly linked
            'created_at' => $row['reading_date'] // Use reading_date as fallback
        ];
    }
    
    echo json_encode([
        'success' => true,
        'readings' => $readings,
        'count' => count($readings)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
