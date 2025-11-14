<?php
require_once 'config.php';

validateApiKey();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // Count active clients from client_list table
        // status = 1 means active, delete_flag = 0 means not deleted
        $stmt = $conn->prepare("
            SELECT COUNT(*) as active_clients 
            FROM client_list 
            WHERE status = 1 AND delete_flag = 0
        ");
        
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        $active_clients = $row['active_clients'] ?? 0;
        
        sendResponse(true, 'Active clients count retrieved', [
            'active_clients' => (int)$active_clients
        ]);
        
    } catch (Exception $e) {
        sendResponse(false, 'Error retrieving client count: ' . $e->getMessage(), null, 500);
    }
} else {
    sendResponse(false, 'Method not allowed', null, 405);
}
?> 