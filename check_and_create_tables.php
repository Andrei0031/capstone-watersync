<?php
include 'db.php';

// Make sure we're using the correct database
$conn->query("USE watersync");

// Function to check if a table exists
function tableExists($conn, $tableName) {
    $result = $conn->query("SHOW TABLES LIKE '$tableName'");
    return $result->num_rows > 0;
}

// Function to get table structure
function getTableStructure($conn, $tableName) {
    $result = $conn->query("DESCRIBE $tableName");
    $structure = [];
    while ($row = $result->fetch_assoc()) {
        $structure[$row['Field']] = $row;
    }
    return $structure;
}

// Check if required tables exist
if (!tableExists($conn, 'client_list')) {
    die("Error: client_list table does not exist!");
}

if (!tableExists($conn, 'admin')) {
    die("Error: admin table does not exist!");
}

// Get structures
$clientStructure = getTableStructure($conn, 'client_list');
$adminStructure = getTableStructure($conn, 'admin');

// Verify primary keys
if (!isset($clientStructure['id']) || $clientStructure['id']['Key'] != 'PRI') {
    die("Error: client_list table must have 'id' as primary key!");
}

if (!isset($adminStructure['id']) || $adminStructure['id']['Key'] != 'PRI') {
    die("Error: admin table must have 'id' as primary key!");
}

// Drop the table if it exists with wrong structure
$conn->query("DROP TABLE IF EXISTS `client_reports`");

// Create client_reports table
$createTableSQL = "CREATE TABLE `client_reports` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `client_id` int(11) NOT NULL,
    `report_type` varchar(50) NOT NULL,
    `description` text NOT NULL,
    `status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0=pending, 1=resolved',
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `resolved_at` datetime DEFAULT NULL,
    `resolution_notes` text DEFAULT NULL,
    `priority` varchar(20) NOT NULL DEFAULT 'medium' COMMENT 'low, medium, high',
    `assigned_to` int(11) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `client_id` (`client_id`),
    KEY `assigned_to` (`assigned_to`),
    CONSTRAINT `client_reports_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `client_list` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `client_reports_ibfk_2` FOREIGN KEY (`assigned_to`) REFERENCES `admin` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

if ($conn->query($createTableSQL) === TRUE) {
    echo "client_reports table created successfully!";
} else {
    echo "Error creating table: " . $conn->error . "\n";
    
    // Print table structures for debugging
    echo "\n\nClient List Structure:\n";
    print_r($clientStructure);
    
    echo "\n\nAdmin Structure:\n";
    print_r($adminStructure);
}

$conn->close();
?> 