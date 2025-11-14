<?php
require_once 'config.php';

validateApiKey();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get bills for a customer or all bills
    try {
        if (isset($_GET['customer_id'])) {
            // Get bills for specific customer
            $customer_id = intval($_GET['customer_id']);
            $stmt = $conn->prepare("
                SELECT b.*, c.firstname, c.lastname, cl.meter_number
                FROM billing_list b
                JOIN client_list cl ON b.client_id = cl.id
                JOIN customer_accounts c ON cl.customer_id = c.id
                WHERE c.id = ?
                ORDER BY b.billing_date DESC
            ");
            $stmt->bind_param("i", $customer_id);
            
        } elseif (isset($_GET['bill_id'])) {
            // Get specific bill
            $bill_id = intval($_GET['bill_id']);
            $stmt = $conn->prepare("
                SELECT b.*, c.firstname, c.lastname, cl.meter_number
                FROM billing_list b
                JOIN client_list cl ON b.client_id = cl.id
                JOIN customer_accounts c ON cl.customer_id = c.id
                WHERE b.id = ?
            ");
            $stmt->bind_param("i", $bill_id);
            
        } else {
            // Get all bills (for admin)
            $stmt = $conn->prepare("
                SELECT b.*, c.firstname, c.lastname, cl.meter_number
                FROM billing_list b
                JOIN client_list cl ON b.client_id = cl.id
                JOIN customer_accounts c ON cl.customer_id = c.id
                ORDER BY b.billing_date DESC
                LIMIT 100
            ");
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $bills = [];
        while ($row = $result->fetch_assoc()) {
            $bills[] = $row;
        }
        
        sendResponse(true, 'Bills retrieved successfully', $bills);
        
    } catch (Exception $e) {
        sendResponse(false, 'Error retrieving bills: ' . $e->getMessage(), null, 500);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Create new bill
    $input = getInputData();
    validateRequiredFields($input, ['client_id', 'current_reading', 'billing_period']);
    
    try {
        // Get client info and previous reading
        $stmt = $conn->prepare("
            SELECT cl.*, 
                   COALESCE(
                       (SELECT current_reading FROM billing_list 
                        WHERE client_id = cl.id 
                        ORDER BY billing_date DESC LIMIT 1), 
                       0
                   ) as previous_reading
            FROM client_list cl
            WHERE cl.id = ?
        ");
        $stmt->bind_param("i", $input['client_id']);
        $stmt->execute();
        $client = $stmt->get_result()->fetch_assoc();
        
        if (!$client) {
            sendResponse(false, 'Client not found', null, 404);
        }
        
        // Calculate consumption
        $current_reading = floatval($input['current_reading']);
        $previous_reading = floatval($client['previous_reading']);
        $consumption = max(0, $current_reading - $previous_reading);
        
        // Get rate structure (you'll need to adjust this based on your rate table)
        $stmt = $conn->prepare("SELECT * FROM rate_structure ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $rate = $stmt->get_result()->fetch_assoc();
        
        if (!$rate) {
            sendResponse(false, 'Rate structure not found', null, 500);
        }
        
        // Calculate bill amount (simplified calculation)
        $basic_rate = floatval($rate['basic_rate'] ?? 50);
        $per_cubic_rate = floatval($rate['per_cubic_rate'] ?? 15);
        $total_amount = $basic_rate + ($consumption * $per_cubic_rate);
        
        // Insert bill
        $stmt = $conn->prepare("
            INSERT INTO billing_list 
            (client_id, billing_period, previous_reading, current_reading, consumption, 
             total_amount, billing_date, due_date, status) 
            VALUES (?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), 'unpaid')
        ");
        
        $stmt->bind_param("isdddd",
            $input['client_id'],
            $input['billing_period'],
            $previous_reading,
            $current_reading,
            $consumption,
            $total_amount
        );
        
        if ($stmt->execute()) {
            $bill_id = $stmt->insert_id;
            sendResponse(true, 'Bill created successfully', [
                'bill_id' => $bill_id,
                'consumption' => $consumption,
                'total_amount' => $total_amount
            ]);
        } else {
            sendResponse(false, 'Failed to create bill', null, 500);
        }
        
    } catch (Exception $e) {
        sendResponse(false, 'Error creating bill: ' . $e->getMessage(), null, 500);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    // Update bill (mark as paid, etc.)
    $input = getInputData();
    validateRequiredFields($input, ['bill_id', 'action']);
    
    try {
        if ($input['action'] === 'mark_paid') {
            validateRequiredFields($input, ['payment_amount', 'payment_method']);
            
            $stmt = $conn->prepare("
                UPDATE billing_list 
                SET status = 'paid', payment_date = NOW(), payment_amount = ?, payment_method = ?
                WHERE id = ?
            ");
            $stmt->bind_param("dsi", 
                $input['payment_amount'],
                $input['payment_method'],
                $input['bill_id']
            );
            
            if ($stmt->execute()) {
                sendResponse(true, 'Bill marked as paid successfully');
            } else {
                sendResponse(false, 'Failed to update bill', null, 500);
            }
        } else {
            sendResponse(false, 'Invalid action', null, 400);
        }
        
    } catch (Exception $e) {
        sendResponse(false, 'Error updating bill: ' . $e->getMessage(), null, 500);
    }
    
} else {
    sendResponse(false, 'Method not allowed', null, 405);
}
?> 