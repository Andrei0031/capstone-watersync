<?php
include 'db.php';

$sql = "ALTER TABLE outage_reports
        DROP COLUMN IF EXISTS report_date,
        DROP COLUMN IF EXISTS resolved_date,
        DROP COLUMN IF EXISTS notes";

try {
    if ($conn->multi_query($sql)) {
        echo "Table structure fixed successfully";
    } else {
        echo "Error fixing table: " . $conn->error;
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
} 