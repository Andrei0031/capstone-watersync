<?php
require_once 'db.php';

echo "<h2>Mobile Upload Diagnostic</h2>";

// Check recent readings
echo "<h3>Recent Readings (Last 20):</h3>";
$recent = $conn->query("
    SELECT pmr.*, cl.firstname, cl.lastname, cl.meter_code, cl.status as client_status,
           bc.cycle_name
    FROM pending_meter_readings pmr
    LEFT JOIN client_list cl ON pmr.client_id = cl.id
    LEFT JOIN billing_cycles bc ON pmr.billing_cycle_id = bc.id
    ORDER BY pmr.upload_date DESC
    LIMIT 20
");

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Client ID</th><th>Client Name</th><th>Status</th><th>Reading Value</th><th>OCR Reading</th><th>Upload Date</th><th>Billing Cycle</th><th>Client Status</th></tr>";

while ($row = $recent->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . $row['id'] . "</td>";
    echo "<td>" . $row['client_id'] . "</td>";
    echo "<td>" . ($row['firstname'] ?? 'NULL') . " " . ($row['lastname'] ?? 'NULL') . "</td>";
    echo "<td>" . $row['status'] . "</td>";
    echo "<td>" . ($row['reading_value'] ?? 'NULL') . "</td>";
    echo "<td>" . ($row['ocr_reading'] ?? 'NULL') . "</td>";
    echo "<td>" . $row['upload_date'] . "</td>";
    echo "<td>" . ($row['cycle_name'] ?? 'NULL') . "</td>";
    echo "<td>" . ($row['client_status'] ?? 'NULL') . "</td>";
    echo "</tr>";
}
echo "</table>";

// Check status counts
echo "<h3>Status Counts:</h3>";
$status_counts = $conn->query("
    SELECT status, COUNT(*) as count
    FROM pending_meter_readings
    GROUP BY status
");
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Status</th><th>Count</th></tr>";
while ($row = $status_counts->fetch_assoc()) {
    echo "<tr><td>" . $row['status'] . "</td><td>" . $row['count'] . "</td></tr>";
}
echo "</table>";

// Check readings that should appear in processed tab
echo "<h3>Readings that should appear in 'Processed' tab:</h3>";
$processed = $conn->query("
    SELECT pmr.*, cl.firstname, cl.lastname, cl.meter_code,
           bc.cycle_name, bc.due_date as cycle_due_date
    FROM pending_meter_readings pmr 
    JOIN client_list cl ON pmr.client_id = cl.id 
    LEFT JOIN billing_cycles bc ON pmr.billing_cycle_id = bc.id
    WHERE pmr.status = 'processed'
    ORDER BY pmr.processed_at DESC, pmr.processed_date DESC
    LIMIT 10
");

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Client</th><th>Meter Code</th><th>Reading</th><th>Upload Date</th><th>Cycle</th></tr>";
$count = 0;
while ($row = $processed->fetch_assoc()) {
    $count++;
    echo "<tr>";
    echo "<td>" . $row['id'] . "</td>";
    echo "<td>" . $row['firstname'] . " " . $row['lastname'] . "</td>";
    echo "<td>" . $row['meter_code'] . "</td>";
    echo "<td>" . ($row['reading_value'] ?? $row['ocr_reading'] ?? 'NULL') . "</td>";
    echo "<td>" . $row['upload_date'] . "</td>";
    echo "<td>" . ($row['cycle_name'] ?? 'NULL') . "</td>";
    echo "</tr>";
}
echo "</table>";
echo "<p>Found $count processed readings</p>";

// Check for readings with missing clients
echo "<h3>Readings with Missing or Inactive Clients:</h3>";
$orphaned = $conn->query("
    SELECT pmr.*
    FROM pending_meter_readings pmr
    LEFT JOIN client_list cl ON pmr.client_id = cl.id
    WHERE cl.id IS NULL OR cl.status != 1
    ORDER BY pmr.upload_date DESC
    LIMIT 10
");

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Client ID</th><th>Status</th><th>Upload Date</th></tr>";
$orphan_count = 0;
while ($row = $orphaned->fetch_assoc()) {
    $orphan_count++;
    echo "<tr>";
    echo "<td>" . $row['id'] . "</td>";
    echo "<td>" . $row['client_id'] . "</td>";
    echo "<td>" . $row['status'] . "</td>";
    echo "<td>" . $row['upload_date'] . "</td>";
    echo "</tr>";
}
echo "</table>";
echo "<p>Found $orphan_count orphaned readings</p>";

// Check active billing cycle
echo "<h3>Active Billing Cycle:</h3>";
$cycle = $conn->query("SELECT * FROM billing_cycles WHERE status = 'active' LIMIT 1");
if ($cycle && $row = $cycle->fetch_assoc()) {
    echo "<pre>" . print_r($row, true) . "</pre>";
} else {
    echo "<p style='color:red;'><strong>NO ACTIVE BILLING CYCLE FOUND!</strong></p>";
}

?>

