<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

include 'db.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid notice ID']);
    exit();
}

$notice_id = intval($_GET['id']);

try {
    $sql = "SELECT dn.*, 
                   CONCAT(cl.firstname, ' ', cl.lastname) as client_name,
                   cl.contact, cl.address, cl.code as client_code,
                   a.username as created_by_username
            FROM disconnection_notices dn
            JOIN client_list cl ON dn.client_id = cl.id
            JOIN admin a ON dn.created_by = a.id
            WHERE dn.id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $notice_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Notice not found']);
        exit();
    }
    
    $notice = $result->fetch_assoc();
    
    // Format dates
    if ($notice['created_at']) {
        $notice['created_at_formatted'] = date('F j, Y g:i A', strtotime($notice['created_at']));
    }
    if ($notice['sent_at']) {
        $notice['sent_at_formatted'] = date('F j, Y g:i A', strtotime($notice['sent_at']));
    }
    if ($notice['disconnection_date']) {
        $notice['disconnection_date_formatted'] = date('F j, Y', strtotime($notice['disconnection_date']));
    }
    if ($notice['due_date']) {
        $notice['due_date_formatted'] = date('F j, Y', strtotime($notice['due_date']));
    }
    
    // Format notice type
    $notice['notice_type_formatted'] = ucfirst(str_replace('_', ' ', $notice['notice_type']));
    
    // Format status
    $notice['status_formatted'] = ucfirst($notice['status']);
    
    echo json_encode(['success' => true, 'notice' => $notice]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?> 