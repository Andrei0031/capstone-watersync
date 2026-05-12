<?php
header('Content-Type: application/json');

include 'db.php';
include 'timezone_helper.php';

watersync_force_timezone($conn);

// Check if we should return all customers with readings
if (isset($_GET['all']) && $_GET['all'] == '1') {
    // Return all customers with verified readings
    $sql = "SELECT DISTINCT 
                cl.id as client_id,
                cl.firstname,
                cl.lastname,
                cl.meter_code,
                pmr.verified_reading,
                pmr.ocr_reading,
                pmr.reading_value,
                pmr.processed_date,
                pmr.processed_at,
                bc.cycle_name,
                COALESCE(pmr.verified_reading, pmr.ocr_reading, pmr.reading_value, 0) as reading_value
            FROM pending_meter_readings pmr
            JOIN client_list cl ON pmr.client_id = cl.id
            LEFT JOIN billing_cycles bc ON pmr.billing_cycle_id = bc.id
            WHERE pmr.status = 'verified'
            AND cl.delete_flag = 0
            AND cl.status = 1
            ORDER BY pmr.processed_date DESC, pmr.processed_at DESC
            LIMIT 100";
    
    $result = $conn->query($sql);
    $customers = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $customers[] = [
                'client_id' => $row['client_id'],
                'firstname' => $row['firstname'],
                'lastname' => $row['lastname'],
                'meter_code' => $row['meter_code'],
                'verified_reading' => floatval($row['reading_value']),
                'cycle_name' => $row['cycle_name'] ?? 'Verified Reading',
                'processed_date' => $row['processed_date'] ?? $row['processed_at']
            ];
        }
    }
    
    echo json_encode($customers);
    exit;
}

// Otherwise, return billing status for a single customer
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;

if (!$client_id) {
    echo json_encode(['success' => false, 'has_billing' => false]);
    exit;
}

// Check if customer has existing billing records
$sql = "SELECT COUNT(*) as count, MAX(reading_date) as last_billing_date 
        FROM billing_list 
        WHERE client_id = ? 
        ORDER BY reading_date DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $client_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

$hasBilling = $row['count'] > 0;
$lastBillingDate = $row['last_billing_date'];

echo json_encode([
    'success' => true,
    'has_billing' => $hasBilling,
    'billing_count' => intval($row['count']),
    'last_billing_date' => $lastBillingDate ? date('F j, Y', strtotime($lastBillingDate)) : null
]);

$stmt->close();
?>
