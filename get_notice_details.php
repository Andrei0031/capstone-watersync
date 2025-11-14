<?php
// Prevent any output buffering issues
ob_start();

// Disable error display
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Start session and validate
session_start();
require_once 'session_validation.php';

// Set JSON header early
header('Content-Type: application/json');

try {
    validateSession();
    
    // Include database connection
    require_once 'db.php';

    if (!isset($_GET['id'])) {
        throw new Exception('Notice ID not provided');
    }

    $notice_id = intval($_GET['id']);
    if ($notice_id <= 0) {
        throw new Exception('Invalid notice ID');
    }

    $sql = "SELECT n.*, a.username as admin_name
            FROM notices n
            JOIN admin a ON n.created_by = a.id
            WHERE n.id = ?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Failed to prepare statement: ' . $conn->error);
    }

    $stmt->bind_param("i", $notice_id);
    if (!$stmt->execute()) {
        throw new Exception('Failed to execute query: ' . $stmt->error);
    }

    $result = $stmt->get_result();
    if (!$result) {
        throw new Exception('Failed to get result: ' . $stmt->error);
    }

    if ($notice = $result->fetch_assoc()) {
        // Format dates for better display
        $notice['start_date'] = date('Y-m-d H:i:s', strtotime($notice['start_date']));
        if ($notice['end_date']) {
            $notice['end_date'] = date('Y-m-d H:i:s', strtotime($notice['end_date']));
        }
        
        $response = [
            'success' => true,
            'data' => $notice
        ];
    } else {
        throw new Exception('Notice not found');
    }

} catch (Exception $e) {
    // Log the error
    error_log('Notice Details Error: ' . $e->getMessage());
    
    // Prepare error response
    $response = [
        'success' => false,
        'error' => $e->getMessage()
    ];
} finally {
    // Clean any output buffers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Close database connection if it exists
    if (isset($conn)) {
        $conn->close();
    }
    
    // Ensure clean output
    if (ob_get_length()) ob_clean();

    // Send JSON response
    echo json_encode($response);
    exit;
} 