<?php
require_once 'config.php';

validateApiKey();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Only POST method allowed', null, 405);
}

$input = getInputData();
$clientId = isset($input['client_id']) ? trim((string)$input['client_id']) : '';
$meterCode = isset($input['meter_code']) ? trim((string)$input['meter_code']) : '';

if (empty($clientId) && empty($meterCode)) {
    sendResponse(false, 'Client ID or meter code is required', null, 400);
}

try {
    // Find active billing cycle
    $cycleStmt = $conn->query("
        SELECT id, cycle_name, start_date, end_date, status, due_date, created_at
        FROM billing_cycles
        WHERE status = 'active'
        ORDER BY start_date DESC
        LIMIT 1
    ");
    $activeCycle = $cycleStmt && $cycleStmt->num_rows > 0 ? $cycleStmt->fetch_assoc() : null;

    if (!$activeCycle) {
        sendResponse(false, 'No active billing cycle found', null, 404);
    }

    $cycleId = $activeCycle['id'];

    // Build query to locate client
    $query = "
        SELECT
            cl.id,
            cl.code,
            cl.meter_code,
            cl.status AS client_status,
            cl.firstname,
            cl.lastname,
            cl.middlename,
            cl.contact,
            cl.address,
            COALESCE(pmr.upload_date, bl.reading_date) AS last_scanned_date,
            COALESCE(pmr.reading_value, bl.reading) AS meter_reading,
            CASE
                WHEN bl.id IS NOT NULL THEN 'scanned'
                WHEN pmr.id IS NOT NULL THEN 'submitted'
                ELSE 'not_scanned'
            END AS scan_status,
            CASE
                WHEN bl.id IS NOT NULL THEN 1
                WHEN pmr.id IS NOT NULL THEN 1
                ELSE 0
            END AS is_scanned
        FROM client_list cl
        LEFT JOIN billing_list bl
            ON bl.client_id = cl.id
            AND bl.billing_cycle_id = ?
        LEFT JOIN pending_meter_readings pmr
            ON pmr.client_id = cl.id
            AND pmr.billing_cycle_id = ?
            AND pmr.status != 'failed'
        WHERE cl.status = 1
            AND cl.delete_flag = 0
            AND (
                cl.id = ?
                OR cl.meter_code = ?
                OR cl.code = ?
            )
        LIMIT 1
    ";

    $clientIdInt = is_numeric($clientId) ? (int)$clientId : 0;
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception('Failed to prepare client query: ' . $conn->error);
    }
    $stmt->bind_param("iiiss", $cycleId, $cycleId, $clientIdInt, $meterCode, $meterCode);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        sendResponse(false, 'Client not found in active billing cycle', null, 404);
    }

    $client = $result->fetch_assoc();

    if ($client['client_status'] != 1) {
        sendResponse(false, 'Client is inactive and cannot be scanned', null, 403);
    }

    $isScanned = (bool)$client['is_scanned'];

    $clientData = [
        'id' => (string)$client['id'],
        'firstname' => $client['firstname'],
        'lastname' => $client['lastname'],
        'middlename' => $client['middlename'],
        'contact' => $client['contact'],
        'address' => $client['address'],
        'meter_code' => $client['meter_code'],
        'status' => (string)$client['client_status'],
        'billing_cycle_id' => (string)$cycleId,
        'is_scanned' => $isScanned,
        'scan_status' => $client['scan_status'] ?? 'not_scanned',
        'last_scanned_date' => $client['last_scanned_date'],
        'meter_reading' => $client['meter_reading'],
    ];

    $cycleData = [
        'id' => (string)$activeCycle['id'],
        'cycle_name' => $activeCycle['cycle_name'],
        'start_date' => $activeCycle['start_date'],
        'end_date' => $activeCycle['end_date'],
        'status' => $activeCycle['status'],
        'due_date' => $activeCycle['due_date'],
        'created_at' => $activeCycle['created_at'],
    ];

    sendResponse(true, $isScanned ? 'Client already scanned but can be updated' : 'Client verified and ready for scanning', [
        'client' => $clientData,
        'billing_cycle' => $cycleData,
        'is_already_scanned' => $isScanned,
        'can_scan' => true,
    ]);
} catch (Exception $e) {
    sendResponse(false, 'Verification error: ' . $e->getMessage(), null, 500);
}
?>

