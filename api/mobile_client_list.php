<?php
require_once 'config.php';

// Headers for mobile app compatibility
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Only GET method allowed', null, 405);
}

// Validate API key or allow local network access
$is_local_network = isLocalNetworkRequest();
if (!$is_local_network) {
    validateApiKey();
}

try {
    // Get current active billing cycle
    $cycle_stmt = $conn->prepare("
        SELECT id, cycle_name, start_date, end_date, due_date, description
        FROM billing_cycles 
        WHERE status = 'active' 
        ORDER BY start_date DESC 
        LIMIT 1
    ");
    $cycle_stmt->execute();
    $current_cycle = $cycle_stmt->get_result()->fetch_assoc();
    
    if (!$current_cycle) {
        sendResponse(false, 'No active billing cycle found', null, 400);
    }
    
    // Get all active clients with their current meter reading status
    $clients_stmt = $conn->prepare("
        SELECT 
            cl.id,
            cl.meter_number,
            cl.meter_code,
            cl.customer_id,
            ca.firstname,
            ca.lastname,
            ca.email,
            ca.address,
            ca.contact,
            CASE 
                WHEN pmr.id IS NOT NULL THEN 'submitted'
                ELSE 'pending'
            END as reading_status,
            pmr.reading_value as submitted_reading,
            pmr.upload_date as submission_date,
            (SELECT reading FROM billing_list 
             WHERE client_id = cl.id 
             ORDER BY reading_date DESC 
             LIMIT 1) as last_reading
        FROM client_list cl
        JOIN customer_accounts ca ON cl.customer_id = ca.id
        LEFT JOIN pending_meter_readings pmr ON (
            cl.id = pmr.client_id 
            AND pmr.billing_cycle_id = ? 
            AND pmr.status != 'failed'
        )
        WHERE cl.status = 1
        ORDER BY ca.lastname, ca.firstname
    ");
    
    $clients_stmt->bind_param("i", $current_cycle['id']);
    $clients_stmt->execute();
    $clients_result = $clients_stmt->get_result();
    
    $clients = [];
    $total_clients = 0;
    $readings_submitted = 0;
    $readings_pending = 0;
    
    while ($client = $clients_result->fetch_assoc()) {
        $total_clients++;
        
        if ($client['reading_status'] === 'submitted') {
            $readings_submitted++;
        } else {
            $readings_pending++;
        }
        
        $clients[] = [
            'id' => (int)$client['id'],
            'meter_number' => $client['meter_number'],
            'meter_code' => $client['meter_code'],
            'customer_info' => [
                'name' => $client['firstname'] . ' ' . $client['lastname'],
                'email' => $client['email'],
                'address' => $client['address'],
                'contact' => $client['contact']
            ],
            'reading_info' => [
                'status' => $client['reading_status'],
                'last_reading' => $client['last_reading'] ? (float)$client['last_reading'] : null,
                'submitted_reading' => $client['submitted_reading'] ? (float)$client['submitted_reading'] : null,
                'submission_date' => $client['submission_date']
            ]
        ];
    }
    
    // Calculate progress percentage
    $progress_percentage = $total_clients > 0 ? round(($readings_submitted / $total_clients) * 100, 1) : 0;
    
    sendResponse(true, 'Client list and billing cycle information retrieved successfully', [
        'billing_cycle' => [
            'id' => (int)$current_cycle['id'],
            'name' => $current_cycle['cycle_name'],
            'start_date' => $current_cycle['start_date'],
            'end_date' => $current_cycle['end_date'],
            'due_date' => $current_cycle['due_date'],
            'description' => $current_cycle['description'],
            'days_remaining' => max(0, (strtotime($current_cycle['end_date']) - time()) / (24 * 3600))
        ],
        'progress' => [
            'total_clients' => $total_clients,
            'readings_submitted' => $readings_submitted,
            'readings_pending' => $readings_pending,
            'progress_percentage' => $progress_percentage
        ],
        'clients' => $clients
    ]);
    
} catch (Exception $e) {
    error_log("Mobile client list API error: " . $e->getMessage());
    sendResponse(false, 'Server error occurred', null, 500);
}

/**
 * Check if request is coming from local network
 */
function isLocalNetworkRequest() {
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    
    // Define local network ranges
    $local_ranges = [
        '127.0.0.1',      // Localhost
        '192.168.',       // Private Class C
        '10.',            // Private Class A
        '172.16.',        // Private Class B (start)
        '172.17.',        // Private Class B
        '172.18.',        // Private Class B
        '172.19.',        // Private Class B
        '172.20.',        // Private Class B
        '172.21.',        // Private Class B
        '172.22.',        // Private Class B
        '172.23.',        // Private Class B
        '172.24.',        // Private Class B
        '172.25.',        // Private Class B
        '172.26.',        // Private Class B
        '172.27.',        // Private Class B
        '172.28.',        // Private Class B
        '172.29.',        // Private Class B
        '172.30.',        // Private Class B
        '172.31.',        // Private Class B (end)
        '::1',            // IPv6 localhost
    ];
    
    foreach ($local_ranges as $range) {
        if (strpos($client_ip, $range) === 0) {
            return true;
        }
    }
    
    return false;
}

/**
 * Enhanced sendResponse function with mobile app considerations
 */
function sendResponse($success, $message, $data = null, $status_code = 200) {
    http_response_code($status_code);
    
    $response = [
        'success' => $success,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s'),
        'api_version' => '2.0'
    ];
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit();
}
?> 