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

// Use the EXACT same query logic as pending_readings.php for verified readings
// This ensures we get the data exactly the same way it's displayed in the verified tab
// The key is using COALESCE to get the best reading value from all three fields
$sql = "SELECT pmr.*, cl.firstname, cl.lastname, cl.meter_code, cl.status as client_status,
                bc.cycle_name, bc.due_date as cycle_due_date,
                COALESCE(pmr.verified_reading, pmr.ocr_reading, pmr.reading_value, 0) as reading_value
        FROM pending_meter_readings pmr 
        LEFT JOIN client_list cl ON pmr.client_id = cl.id 
        LEFT JOIN billing_cycles bc ON pmr.billing_cycle_id = bc.id
        WHERE pmr.client_id = ? 
        AND pmr.status = 'verified'
        ORDER BY pmr.processed_date DESC, pmr.processed_at DESC
        LIMIT 1";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
    exit();
}

$stmt->bind_param("i", $client_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();

if ($row && $row['reading_value'] > 0) {
    $processDate = $row['processed_date'] ?? $row['processed_at'];
    $cycleName = $row['cycle_name'] ?? 'Verified Reading';
    
    echo json_encode([
        'success' => true,
        'verified_reading' => floatval($row['reading_value']),
        'cycle_name' => $cycleName,
        'processed_date' => $processDate,
        'reading_id' => $row['id'],
        'firstname' => $row['firstname'],
        'lastname' => $row['lastname']
    ]);
} else {
    echo json_encode(['success' => false, 'verified_reading' => null, 'debug' => 'No verified reading found']);
}
?>
