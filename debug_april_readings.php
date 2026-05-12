<?php
include 'db.php';
include 'timezone_helper.php';

watersync_force_timezone($conn);

echo "=== Billing Cycles (April) ===\n";
$result = $conn->query("SELECT id, name FROM billing_cycles WHERE name LIKE '%April%' LIMIT 5");
while($row = $result->fetch_assoc()) {
    echo "ID: " . $row['id'] . " | Name: " . $row['name'] . "\n";
}

echo "\n=== Verified Readings (April) ===\n";
$result = $conn->query("SELECT pmr.id, pmr.client_id, pmr.verified_reading, pmr.ocr_reading, pmr.reading_value, pmr.status, pmr.processed_date, bc.name FROM pending_meter_readings pmr JOIN billing_cycles bc ON pmr.billing_cycle_id = bc.id WHERE bc.name LIKE '%April%' AND pmr.status = 'verified' LIMIT 10");

if($result->num_rows == 0) {
    echo "No verified April readings found\n";
} else {
    while($row = $result->fetch_assoc()) {
        $reading = $row['verified_reading'] ?? $row['ocr_reading'] ?? $row['reading_value'];
        echo "Client: " . $row['client_id'] . " | Reading: " . $reading . " | Cycle: " . $row['name'] . "\n";
    }
}

echo "\n=== Test API for Client 1 ===\n";
$_GET['client_id'] = 1;
include 'get_verified_april_readings.php';
?>
