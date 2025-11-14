<?php
include 'db.php';

$sql = "CREATE TABLE IF NOT EXISTS outage_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    location VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    status TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL DEFAULT NULL,
    resolution_notes TEXT,
    FOREIGN KEY (client_id) REFERENCES client_list(id)
)";

if ($conn->query($sql) === TRUE) {
    echo "Outage reports table created successfully";
} else {
    echo "Error creating table: " . $conn->error;
}

$conn->close();
?> 