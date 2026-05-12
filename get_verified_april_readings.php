<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

include 'db.php';

$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;

if ($client_id <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'verified_reading' => null]);
    exit();
}

// Get the latest verified reading from April billing cycle for this customer
$sql = "SELECT pmr.verified_reading, pmr.processed_date, bc.name as cycle_name
        FROM pending_meter_readings pmr
        JOIN billing_cycles bc ON pmr.billing_cycle_id = bc.id
        WHERE pmr.client_id = ? 
        AND bc.name LIKE '%April%'
        AND pmr.status = 'verified'
        ORDER BY pmr.processed_date DESC
        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $client_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();

if ($row) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'verified_reading' => floatval($row['verified_reading']),
        'cycle_name' => $row['cycle_name'],
        'processed_date' => $row['processed_date']
    ]);
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'verified_reading' => null]);
}
?>
