<?php
include 'db.php';

// Add updated_at column if it doesn't exist
$check_column = "SHOW COLUMNS FROM billing_list LIKE 'updated_at'";
$result = $conn->query($check_column);

if ($result->num_rows == 0) {
    $add_column = "ALTER TABLE billing_list 
                   ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";
    if ($conn->query($add_column)) {
        echo "Successfully added updated_at column";
    } else {
        echo "Error adding column: " . $conn->error;
    }
} else {
    echo "Column already exists";
}

$conn->close();
?> 