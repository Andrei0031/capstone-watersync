<?php
include 'db.php';

echo "Checking billing_list table structure...\n";
$result = $conn->query('DESCRIBE billing_list');
$has_billing_cycle_id = false;

while($row = $result->fetch_assoc()) {
    echo $row['Field'] . ' - ' . $row['Type'] . "\n";
    if ($row['Field'] === 'billing_cycle_id') {
        $has_billing_cycle_id = true;
    }
}

echo "\n";
if ($has_billing_cycle_id) {
    echo "✓ billing_cycle_id column EXISTS\n";
} else {
    echo "✗ billing_cycle_id column MISSING - needs to be added\n";
    echo "\nAdding billing_cycle_id column...\n";
    $alter_result = $conn->query("ALTER TABLE billing_list ADD COLUMN billing_cycle_id INT NULL AFTER status");
    if ($alter_result) {
        echo "✓ Successfully added billing_cycle_id column\n";
    } else {
        echo "✗ Failed to add column: " . $conn->error . "\n";
    }
}
?>
