<?php
require_once 'config.php';

validateApiKey();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get all customers or a specific customer
    try {
        if (isset($_GET['id'])) {
            // Get specific customer
            $customer_id = intval($_GET['id']);
            $stmt = $conn->prepare("
                SELECT c.id, c.firstname, c.lastname, c.email, c.phone, c.address, c.status,
                       cl.meter_number, cl.connection_date
                FROM customer_accounts c
                LEFT JOIN client_list cl ON c.id = cl.customer_id
                WHERE c.id = ?
            ");
            $stmt->bind_param("i", $customer_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                sendResponse(false, 'Customer not found', null, 404);
            }
            
            $customer = $result->fetch_assoc();
            sendResponse(true, 'Customer data retrieved', $customer);
            
        } else {
            // Get all customers
            $stmt = $conn->prepare("
                SELECT c.id, c.firstname, c.lastname, c.email, c.phone, c.address, c.status,
                       cl.meter_number, cl.connection_date
                FROM customer_accounts c
                LEFT JOIN client_list cl ON c.id = cl.customer_id
                ORDER BY c.lastname, c.firstname
            ");
            $stmt->execute();
            $result = $stmt->get_result();
            
            $customers = [];
            while ($row = $result->fetch_assoc()) {
                $customers[] = $row;
            }
            
            sendResponse(true, 'Customers retrieved successfully', $customers);
        }
        
    } catch (Exception $e) {
        sendResponse(false, 'Error retrieving customers: ' . $e->getMessage(), null, 500);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Create new customer (for admin mobile app)
    $input = getInputData();
    validateRequiredFields($input, ['firstname', 'lastname', 'email', 'phone', 'address']);
    
    try {
        // Check if email already exists
        $stmt = $conn->prepare("SELECT id FROM customer_accounts WHERE email = ?");
        $stmt->bind_param("s", $input['email']);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            sendResponse(false, 'Email already exists', null, 400);
        }
        
        // Insert new customer
        $stmt = $conn->prepare("
            INSERT INTO customer_accounts (firstname, lastname, email, phone, address, status, password) 
            VALUES (?, ?, ?, ?, ?, 'active', ?)
        ");
        
        // Generate default password
        $default_password = password_hash('password123', PASSWORD_DEFAULT);
        
        $stmt->bind_param("ssssss", 
            $input['firstname'],
            $input['lastname'],
            $input['email'],
            $input['phone'],
            $input['address'],
            $default_password
        );
        
        if ($stmt->execute()) {
            $customer_id = $stmt->insert_id;
            
            // If meter_number is provided, create client_list entry
            if (isset($input['meter_number'])) {
                $stmt2 = $conn->prepare("
                    INSERT INTO client_list (customer_id, meter_number, connection_date) 
                    VALUES (?, ?, NOW())
                ");
                $stmt2->bind_param("is", $customer_id, $input['meter_number']);
                $stmt2->execute();
            }
            
            sendResponse(true, 'Customer created successfully', ['customer_id' => $customer_id]);
        } else {
            sendResponse(false, 'Failed to create customer', null, 500);
        }
        
    } catch (Exception $e) {
        sendResponse(false, 'Error creating customer: ' . $e->getMessage(), null, 500);
    }
    
} else {
    sendResponse(false, 'Method not allowed', null, 405);
}
?> 