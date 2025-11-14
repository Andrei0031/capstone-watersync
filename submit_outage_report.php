<?php
session_start();
include 'db.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['client_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please log in to submit a report']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

try {
    // Create table if it doesn't exist
    $create_table_sql = "CREATE TABLE IF NOT EXISTS outage_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NOT NULL,
        location VARCHAR(255) NOT NULL,
        description TEXT NOT NULL,
        status TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        resolved_at TIMESTAMP NULL,
        resolution_notes TEXT,
        FOREIGN KEY (client_id) REFERENCES client_list(id)
    )";
    
    if (!$conn->query($create_table_sql)) {
        throw new Exception("Error creating table: " . $conn->error);
    }

    $client_id = $_SESSION['client_id'];
    $location = trim($_POST['location']);
    $description = trim($_POST['description']);

    if (empty($location) || empty($description)) {
        echo json_encode(['success' => false, 'message' => 'Please fill in all required fields']);
        exit;
    }

    // Verify client exists
    $check_client = $conn->prepare("SELECT id FROM client_list WHERE id = ?");
    $check_client->bind_param("i", $client_id);
    $check_client->execute();
    if ($check_client->get_result()->num_rows === 0) {
        throw new Exception("Invalid client ID");
    }
    $check_client->close();

    // Insert the report
    $stmt = $conn->prepare("INSERT INTO outage_reports (client_id, location, description, status) VALUES (?, ?, ?, 0)");
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    $stmt->bind_param("iss", $client_id, $location, $description);

    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }

    echo json_encode(['success' => true, 'message' => 'Outage report submitted successfully']);
    $stmt->close();

} catch (Exception $e) {
    error_log("Outage report error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error submitting report: ' . $e->getMessage()]);
}

$conn->close();
?> 