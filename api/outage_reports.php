<?php
require_once 'config.php';

validateApiKey();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get outage reports
    try {
        if (isset($_GET['customer_id'])) {
            // Get reports for specific customer
            $customer_id = intval($_GET['customer_id']);
            $stmt = $conn->prepare("
                SELECT or.*, c.firstname, c.lastname
                FROM outage_reports or
                JOIN customer_accounts c ON or.customer_id = c.id
                WHERE or.customer_id = ?
                ORDER BY or.created_at DESC
            ");
            $stmt->bind_param("i", $customer_id);
            
        } elseif (isset($_GET['id'])) {
            // Get specific report
            $report_id = intval($_GET['id']);
            $stmt = $conn->prepare("
                SELECT or.*, c.firstname, c.lastname
                FROM outage_reports or
                JOIN customer_accounts c ON or.customer_id = c.id
                WHERE or.id = ?
            ");
            $stmt->bind_param("i", $report_id);
            
        } else {
            // Get all reports (for admin)
            $status_filter = isset($_GET['status']) ? $_GET['status'] : '';
            
            if ($status_filter) {
                $stmt = $conn->prepare("
                    SELECT or.*, c.firstname, c.lastname
                    FROM outage_reports or
                    JOIN customer_accounts c ON or.customer_id = c.id
                    WHERE or.status = ?
                    ORDER BY or.created_at DESC
                ");
                $stmt->bind_param("s", $status_filter);
            } else {
                $stmt = $conn->prepare("
                    SELECT or.*, c.firstname, c.lastname
                    FROM outage_reports or
                    JOIN customer_accounts c ON or.customer_id = c.id
                    ORDER BY or.created_at DESC
                    LIMIT 50
                ");
            }
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $reports = [];
        while ($row = $result->fetch_assoc()) {
            $reports[] = $row;
        }
        
        sendResponse(true, 'Reports retrieved successfully', $reports);
        
    } catch (Exception $e) {
        sendResponse(false, 'Error retrieving reports: ' . $e->getMessage(), null, 500);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Submit new outage report
    $input = getInputData();
    validateRequiredFields($input, ['customer_id', 'description', 'location']);
    
    try {
        // Verify customer exists
        $stmt = $conn->prepare("SELECT id FROM customer_accounts WHERE id = ?");
        $stmt->bind_param("i", $input['customer_id']);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            sendResponse(false, 'Customer not found', null, 404);
        }
        
        $stmt = $conn->prepare("
            INSERT INTO outage_reports 
            (customer_id, description, location, urgency_level, status, created_at) 
            VALUES (?, ?, ?, ?, 'pending', NOW())
        ");
        
        $urgency_level = isset($input['urgency_level']) ? $input['urgency_level'] : 'medium';
        
        $stmt->bind_param("isss", 
            $input['customer_id'],
            $input['description'],
            $input['location'],
            $urgency_level
        );
        
        if ($stmt->execute()) {
            $report_id = $stmt->insert_id;
            sendResponse(true, 'Report submitted successfully', ['report_id' => $report_id]);
        } else {
            sendResponse(false, 'Failed to submit report', null, 500);
        }
        
    } catch (Exception $e) {
        sendResponse(false, 'Error submitting report: ' . $e->getMessage(), null, 500);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    // Update report status (admin only)
    $input = getInputData();
    validateRequiredFields($input, ['report_id', 'status']);
    
    try {
        $valid_statuses = ['pending', 'in_progress', 'resolved', 'cancelled'];
        if (!in_array($input['status'], $valid_statuses)) {
            sendResponse(false, 'Invalid status', null, 400);
        }
        
        $stmt = $conn->prepare("
            UPDATE outage_reports 
            SET status = ?, resolution_notes = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        
        $resolution_notes = isset($input['resolution_notes']) ? $input['resolution_notes'] : null;
        
        $stmt->bind_param("ssi", 
            $input['status'],
            $resolution_notes,
            $input['report_id']
        );
        
        if ($stmt->execute()) {
            sendResponse(true, 'Report updated successfully');
        } else {
            sendResponse(false, 'Failed to update report', null, 500);
        }
        
    } catch (Exception $e) {
        sendResponse(false, 'Error updating report: ' . $e->getMessage(), null, 500);
    }
    
} else {
    sendResponse(false, 'Method not allowed', null, 405);
}
?> 