<?php
include 'db.php';

echo "Checking if billing_cycle_id column exists in billing_list table...\n";

// Check if column exists
$check_column = "SHOW COLUMNS FROM billing_list LIKE 'billing_cycle_id'";
$result = $conn->query($check_column);

if ($result->num_rows == 0) {
    echo "Column does not exist. Adding billing_cycle_id column...\n";
    
    $add_column = "ALTER TABLE billing_list 
                   ADD COLUMN billing_cycle_id INT NULL AFTER status,
                   ADD FOREIGN KEY (billing_cycle_id) REFERENCES billing_cycles(id)";
    
    if ($conn->query($add_column)) {
        echo "✓ Successfully added billing_cycle_id column to billing_list\n";
    } else {
        echo "✗ Error adding column: " . $conn->error . "\n";
    }
} else {
    echo "✓ Column already exists\n";
}

// Check existing bills and assign billing cycles
echo "\nAssigning billing cycles to existing bills...\n";
$update_sql = "UPDATE billing_list b
               LEFT JOIN billing_cycles bc ON b.reading_date BETWEEN bc.start_date AND bc.end_date
               SET b.billing_cycle_id = bc.id
               WHERE b.billing_cycle_id IS NULL AND bc.id IS NOT NULL";

if ($conn->query($update_sql)) {
    $affected_rows = $conn->affected_rows;
    echo "✓ Successfully assigned billing cycles to $affected_rows bills\n";
} else {
    echo "✗ Error updating bills: " . $conn->error . "\n";
}

echo "\nDone!\n";
$conn->close();
?>
