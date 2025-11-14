<?php
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_GET['client_id'])) {
    echo json_encode(['error' => 'Client ID not provided']);
    exit;
}

$client_id = intval($_GET['client_id']);

try {
    // Get the latest reading for the client
    $stmt = $conn->prepare("SELECT reading FROM billing_list WHERE client_id = ? ORDER BY reading_date DESC LIMIT 1");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        echo json_encode(['success' => true, 'previous_reading' => $row['reading']]);
    } else {
        echo json_encode(['success' => true, 'previous_reading' => 0]);
    }
    
    $stmt->close();
} catch (Exception $e) {
    echo json_encode(['error' => 'Error fetching previous reading: ' . $e->getMessage()]);
}

$conn->close();
?> 