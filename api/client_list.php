<?php
require_once 'config.php';

validateApiKey();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Only GET method allowed', null, 405);
}

try {
    // Get pagination parameters (optional)
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 1000; // Default to 1000 to get all clients
    $offset = ($page - 1) * $limit;
    
    // Get search parameter (optional)
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    
    // Build WHERE clause
    $whereClause = 'WHERE 1=1';
    $params = [];
    $types = '';
    
    // Add search functionality if provided
    if (!empty($search)) {
        $whereClause .= " AND (firstname LIKE ? OR lastname LIKE ? OR meter_code LIKE ? OR contact LIKE ? OR address LIKE ?)";
        $searchParam = "%{$search}%";
        $params = [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam];
        $types = 'sssss';
    }
    
    // Get total count
    $countQuery = "SELECT COUNT(*) as total FROM client_list {$whereClause}";
    
    if (!empty($search)) {
        $stmt = $conn->prepare($countQuery);
        $stmt->bind_param($types, ...$params);
    } else {
        $stmt = $conn->prepare($countQuery);
    }
    
    $stmt->execute();
    $totalResult = $stmt->get_result()->fetch_assoc();
    $totalClients = (int)$totalResult['total'];
    
    // Get clients with pagination
    $clientQuery = "
        SELECT 
            id,
            firstname,
            lastname,
            middlename,
            contact,
            address,
            meter_code,
            status,
            date_created
        FROM client_list
        {$whereClause}
        ORDER BY lastname, firstname
        LIMIT ? OFFSET ?
    ";
    
    // Add pagination parameters
    $allParams = $params;
    $allParams[] = $limit;
    $allParams[] = $offset;
    $allTypes = $types . 'ii';
    
    $stmt = $conn->prepare($clientQuery);
    if (!empty($allParams)) {
        $stmt->bind_param($allTypes, ...$allParams);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $clients = [];
    while ($row = $result->fetch_assoc()) {
        $clients[] = [
            'id' => (string)$row['id'],
            'firstname' => $row['firstname'] ?? '',
            'lastname' => $row['lastname'] ?? '',
            'middlename' => $row['middlename'] ?? null,
            'contact' => $row['contact'] ?? null,
            'address' => $row['address'] ?? null,
            'meter_code' => $row['meter_code'] ?? null,
            'status' => (string)($row['status'] ?? '0'),
            'date_created' => $row['date_created'] ?? null
        ];
    }
    
    // Return response in format expected by mobile app
    // Include total count for dashboard statistics
    $responseData = [
        'clients' => $clients,
        'total_count' => $totalClients, // Total registered customers in the system
        'returned_count' => count($clients) // Number of clients in this response
    ];
    
    sendResponse(true, 'Client list retrieved successfully', $responseData);
    
} catch (Exception $e) {
    sendResponse(false, 'Error retrieving client list: ' . $e->getMessage(), null, 500);
}
?>
