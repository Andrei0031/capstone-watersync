<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

include 'db.php';

header('Content-Type: application/json');

try {
    $client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : null;
    
    if ($client_id) {
        // Fetch specific client with QR code data from client_list table
        $sql = "SELECT id, 
                       CONCAT(firstname, ' ', lastname) as name, 
                       meter_code,
                       qr_code_data,
                       CASE 
                           WHEN qr_image_path IS NULL OR qr_image_path = '' THEN NULL
                           WHEN qr_image_path LIKE 'qr_codes/%' THEN qr_image_path
                           ELSE CONCAT('qr_codes/', qr_image_path)
                       END AS qr_image_path,
                       qr_generated_at as qr_created_at,
                       qr_print_count as print_count,
                       qr_last_printed as last_printed
                FROM client_list 
                WHERE id = ? AND status = 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $client_id);
    } else {
        // Fetch all active clients with QR code data from client_list table
        $sql = "SELECT id, 
                       CONCAT(firstname, ' ', lastname) as name, 
                       meter_code,
                       qr_code_data,
                       CASE 
                           WHEN qr_image_path IS NULL OR qr_image_path = '' THEN NULL
                           WHEN qr_image_path LIKE 'qr_codes/%' THEN qr_image_path
                           ELSE CONCAT('qr_codes/', qr_image_path)
                       END AS qr_image_path,
                       qr_generated_at as qr_created_at,
                       qr_print_count as print_count,
                       qr_last_printed as last_printed
                FROM client_list 
                WHERE status = 1 
                ORDER BY firstname, lastname";
        $stmt = $conn->prepare($sql);
    }
    
    if (!$stmt) {
        throw new Exception("Failed to prepare statement: " . $conn->error);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $clients = [];
    while ($row = $result->fetch_assoc()) {
        $clients[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'clients' => $clients,
        'count' => count($clients)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 