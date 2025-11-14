<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

include 'db.php';

header('Content-Type: application/json');

try {
    $client_id = isset($_POST['client_id']) ? intval($_POST['client_id']) : null;
    $print_type = isset($_POST['print_type']) ? $_POST['print_type'] : 'single'; // 'single' or 'batch'
    
    if (!$client_id) {
        throw new Exception('Client ID is required');
    }
    
    // Update print statistics in client_list table
    $update_sql = "UPDATE client_list 
                   SET qr_print_count = qr_print_count + 1,
                       qr_last_printed = CURRENT_TIMESTAMP
                   WHERE id = ?";
    
    $stmt = $conn->prepare($update_sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare statement: " . $conn->error);
    }
    
    $stmt->bind_param("i", $client_id);
    
    if ($stmt->execute()) {
        // Get updated statistics
        $stats_sql = "SELECT qr_print_count as print_count, qr_last_printed as last_printed 
                     FROM client_list WHERE id = ?";
        $stats_stmt = $conn->prepare($stats_sql);
        
        if (!$stats_stmt) {
            throw new Exception("Failed to prepare stats statement: " . $conn->error);
        }
        
        $stats_stmt->bind_param("i", $client_id);
        $stats_stmt->execute();
        $stats = $stats_stmt->get_result()->fetch_assoc();
        
        echo json_encode([
            'success' => true,
            'message' => 'Print tracked successfully',
            'print_type' => $print_type,
            'print_count' => $stats['print_count'] ?? 0, // Ensure default value
            'last_printed' => $stats['last_printed']
        ]);
    } else {
        throw new Exception('Failed to update print statistics: ' . $conn->error);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 