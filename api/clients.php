<?php
require_once 'config.php';

validateApiKey();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get all clients or a specific client
    try {
        if (isset($_GET['id'])) {
            // Get specific client
            $client_id = intval($_GET['id']);
            $stmt = $conn->prepare("
                SELECT id, code, category_id, firstname, middlename, lastname, 
                       contact, address, meter_code, first_reading, status, 
                       date_created, date_updated
                FROM client_list 
                WHERE id = ?
            ");
            $stmt->bind_param("i", $client_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                sendResponse(false, 'Client not found', null, 404);
            }
            
            $client = $result->fetch_assoc();
            sendResponse(true, 'Client data retrieved', $client);
            
        } else {
            // Get all clients
            $stmt = $conn->prepare("
                SELECT id, code, category_id, firstname, middlename, lastname, 
                       contact, address, meter_code, first_reading, status, 
                       date_created, date_updated
                FROM client_list 
                ORDER BY lastname, firstname
            ");
            $stmt->execute();
            $result = $stmt->get_result();
            
            $clients = [];
            while ($row = $result->fetch_assoc()) {
                $clients[] = $row;
            }
            
            sendResponse(true, 'Clients retrieved successfully', $clients);
        }
        
    } catch (Exception $e) {
        sendResponse(false, 'Error retrieving clients: ' . $e->getMessage(), null, 500);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Create new client (for admin mobile app)
    $input = getInputData();
    validateRequiredFields($input, ['code', 'firstname', 'lastname', 'category_id']);
    
    try {
        // Check if code already exists
        $stmt = $conn->prepare("SELECT id FROM client_list WHERE code = ?");
        $stmt->bind_param("s", $input['code']);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            sendResponse(false, 'Client code already exists', null, 400);
        }
        
        // Insert new client
        $stmt = $conn->prepare("
            INSERT INTO client_list (code, category_id, firstname, middlename, lastname, contact, address, meter_code, first_reading, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        
        $stmt->bind_param("sissssssd", 
            $input['code'],
            $input['category_id'],
            $input['firstname'],
            $input['middlename'] ?? null,
            $input['lastname'],
            $input['contact'] ?? null,
            $input['address'] ?? null,
            $input['meter_code'] ?? null,
            $input['first_reading'] ?? 0.00
        );
        
        if ($stmt->execute()) {
            $client_id = $stmt->insert_id;
            sendResponse(true, 'Client created successfully', ['client_id' => $client_id]);
        } else {
            sendResponse(false, 'Failed to create client', null, 500);
        }
        
    } catch (Exception $e) {
        sendResponse(false, 'Error creating client: ' . $e->getMessage(), null, 500);
    }
    
} else {
    sendResponse(false, 'Method not allowed', null, 405);
}
?> 