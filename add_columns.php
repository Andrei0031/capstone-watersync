<?php
include 'db.php';

$alter_sql = "ALTER TABLE outage_reports 
              ADD COLUMN IF NOT EXISTS resolution_notes TEXT NULL AFTER description,
              ADD COLUMN IF NOT EXISTS resolved_at TIMESTAMP NULL AFTER resolution_notes";

try {
    if ($conn->query($alter_sql)) {
        echo "Columns added successfully\n";
    } else {
        echo "Error adding columns: " . $conn->error . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "SQL State: " . $conn->sqlstate . "\n";
    echo "Error Code: " . $conn->errno . "\n";
} 