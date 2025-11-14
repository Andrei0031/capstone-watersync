<?php
require_once 'config.php';

validateApiKey();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Only GET method allowed', null, 405);
}

try {
    // Query for active billing cycle
    // Try common table names: billing_cycles, billing_cycle, cycles
    $activeCycle = null;
    
    // Try billing_cycles table first (most common)
    $tablesToTry = ['billing_cycles', 'billing_cycle', 'cycles'];
    
    foreach ($tablesToTry as $tableName) {
        // Check if table exists
        $checkTable = $conn->query("SHOW TABLES LIKE '$tableName'");
        if ($checkTable && $checkTable->num_rows > 0) {
            // Try to get active cycle
            // Status might be 'active', 'Active', 'ACTIVE', or status = 1
            $query = "SELECT * FROM $tableName WHERE (status = 'active' OR status = 'Active' OR status = 'ACTIVE' OR status = 1) ORDER BY id DESC LIMIT 1";
            $result = $conn->query($query);
            
            if ($result && $result->num_rows > 0) {
                $activeCycle = $result->fetch_assoc();
                break;
            }
        }
    }
    
    if (!$activeCycle) {
        sendResponse(false, 'No active billing cycle found', null, 404);
    }
    
    // Format the response to match expected structure
    // Handle different column name variations
    $formattedCycle = [
        'id' => (string)($activeCycle['id'] ?? $activeCycle['cycle_id'] ?? ''),
        'cycle_name' => $activeCycle['cycle_name'] ?? $activeCycle['name'] ?? $activeCycle['title'] ?? '',
        'start_date' => $activeCycle['start_date'] ?? $activeCycle['period_start'] ?? $activeCycle['start'] ?? '',
        'end_date' => $activeCycle['end_date'] ?? $activeCycle['period_end'] ?? $activeCycle['end'] ?? '',
        'status' => strtolower($activeCycle['status'] ?? 'active'),
        'due_date' => $activeCycle['due_date'] ?? $activeCycle['due'] ?? null,
        'created_at' => $activeCycle['created_at'] ?? $activeCycle['date_created'] ?? null,
    ];
    
    // Count total clients in client_list table
    $totalClientsQuery = "SELECT COUNT(*) as total FROM client_list WHERE status = 1";
    $totalResult = $conn->query($totalClientsQuery);
    $totalClients = 0;
    if ($totalResult && $totalResult->num_rows > 0) {
        $totalRow = $totalResult->fetch_assoc();
        $totalClients = (int)$totalRow['total'];
    }
    
    // Count scanned clients for this cycle (if there's a readings or scans table)
    $scannedClients = 0;
    $readingsTables = ['meter_readings', 'readings', 'billing_readings', 'scans'];
    foreach ($readingsTables as $readingsTable) {
        $checkTable = $conn->query("SHOW TABLES LIKE '$readingsTable'");
        if ($checkTable && $checkTable->num_rows > 0) {
            // Try to count scanned clients for this cycle
            $cycleId = $formattedCycle['id'];
            $scannedQuery = "SELECT COUNT(DISTINCT client_id) as scanned FROM $readingsTable WHERE billing_cycle_id = ?";
            $stmt = $conn->prepare($scannedQuery);
            if ($stmt) {
                $stmt->bind_param("s", $cycleId);
                $stmt->execute();
                $scannedResult = $stmt->get_result();
                if ($scannedResult && $scannedResult->num_rows > 0) {
                    $scannedRow = $scannedResult->fetch_assoc();
                    $scannedClients = (int)$scannedRow['scanned'];
                }
                break;
            }
        }
    }
    
    $formattedCycle['total_clients'] = $totalClients;
    $formattedCycle['scanned_clients'] = $scannedClients;
    
    // Convert dates to ISO format if they're not already
    if (!empty($formattedCycle['start_date']) && !str_contains($formattedCycle['start_date'], 'T')) {
        $formattedCycle['start_date'] = date('c', strtotime($formattedCycle['start_date']));
    }
    if (!empty($formattedCycle['end_date']) && !str_contains($formattedCycle['end_date'], 'T')) {
        $formattedCycle['end_date'] = date('c', strtotime($formattedCycle['end_date']));
    }
    if (!empty($formattedCycle['created_at']) && !str_contains($formattedCycle['created_at'], 'T')) {
        $formattedCycle['created_at'] = date('c', strtotime($formattedCycle['created_at']));
    }
    
    sendResponse(true, 'Active billing cycle retrieved successfully', $formattedCycle);
    
} catch (Exception $e) {
    sendResponse(false, 'Error retrieving active billing cycle: ' . $e->getMessage(), null, 500);
}
?>

