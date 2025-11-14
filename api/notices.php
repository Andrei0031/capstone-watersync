<?php
require_once 'config.php';

validateApiKey();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get notices
    try {
        if (isset($_GET['id'])) {
            // Get specific notice
            $notice_id = intval($_GET['id']);
            $stmt = $conn->prepare("SELECT * FROM notices WHERE id = ?");
            $stmt->bind_param("i", $notice_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                sendResponse(false, 'Notice not found', null, 404);
            }
            
            $notice = $result->fetch_assoc();
            sendResponse(true, 'Notice retrieved successfully', $notice);
            
        } else {
            // Get all active notices
            $stmt = $conn->prepare("
                SELECT * FROM notices 
                WHERE status = 'active' 
                ORDER BY created_at DESC
            ");
            $stmt->execute();
            $result = $stmt->get_result();
            
            $notices = [];
            while ($row = $result->fetch_assoc()) {
                $notices[] = $row;
            }
            
            sendResponse(true, 'Notices retrieved successfully', $notices);
        }
        
    } catch (Exception $e) {
        sendResponse(false, 'Error retrieving notices: ' . $e->getMessage(), null, 500);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Create new notice (admin only)
    $input = getInputData();
    validateRequiredFields($input, ['title', 'content', 'notice_type']);
    
    try {
        $stmt = $conn->prepare("
            INSERT INTO notices (title, content, notice_type, status, created_at) 
            VALUES (?, ?, ?, 'active', NOW())
        ");
        
        $stmt->bind_param("sss", 
            $input['title'],
            $input['content'],
            $input['notice_type']
        );
        
        if ($stmt->execute()) {
            $notice_id = $stmt->insert_id;
            sendResponse(true, 'Notice created successfully', ['notice_id' => $notice_id]);
        } else {
            sendResponse(false, 'Failed to create notice', null, 500);
        }
        
    } catch (Exception $e) {
        sendResponse(false, 'Error creating notice: ' . $e->getMessage(), null, 500);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    // Update notice status
    $input = getInputData();
    validateRequiredFields($input, ['notice_id', 'action']);
    
    try {
        if ($input['action'] === 'deactivate') {
            $stmt = $conn->prepare("UPDATE notices SET status = 'inactive' WHERE id = ?");
            $stmt->bind_param("i", $input['notice_id']);
            
            if ($stmt->execute()) {
                sendResponse(true, 'Notice deactivated successfully');
            } else {
                sendResponse(false, 'Failed to update notice', null, 500);
            }
        } else {
            sendResponse(false, 'Invalid action', null, 400);
        }
        
    } catch (Exception $e) {
        sendResponse(false, 'Error updating notice: ' . $e->getMessage(), null, 500);
    }
    
} else {
    sendResponse(false, 'Method not allowed', null, 405);
}
?> 