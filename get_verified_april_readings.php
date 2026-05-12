<?php
header('Content-Type: application/json');

include 'db.php';
include 'timezone_helper.php';

watersync_force_timezone($conn);

$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;

if ($client_id <= 0) {
    echo json_encode(['success' => false, 'verified_reading' => null]);
    exit();
}

// Get the latest verified reading from April billing cycle for this customer
// Try to get the reading value from verified_reading, then ocr_reading, then reading_value
$sql = "SELECT 
            pmr.id,
            pmr.verified_reading,
            pmr.ocr_reading,
            pmr.reading_value,
            pmr.processed_date,
            pmr.processed_at,
            bc.name as cycle_name,
            pmr.status
        FROM pending_meter_readings pmr
        JOIN billing_cycles bc ON pmr.billing_cycle_id = bc.id
        WHERE pmr.client_id = ? 
        AND bc.name LIKE '%April%'
        AND pmr.status = 'verified'
        ORDER BY pmr.processed_date DESC, pmr.processed_at DESC
        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $client_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();

if ($row && $row['status'] === 'verified') {
    // Use verified_reading if available, otherwise use ocr_reading, otherwise reading_value
    $readingValue = $row['verified_reading'] ?? $row['ocr_reading'] ?? $row['reading_value'] ?? null;
    
    if ($readingValue !== null) {
        $processDate = $row['processed_date'] ?? $row['processed_at'];
        
        echo json_encode([
            'success' => true,
            'verified_reading' => floatval($readingValue),
            'cycle_name' => $row['cycle_name'],
            'processed_date' => $processDate,
            'reading_id' => $row['id']
        ]);
    } else {
        echo json_encode(['success' => false, 'verified_reading' => null]);
    }
} else {
    echo json_encode(['success' => false, 'verified_reading' => null]);
}
?>

