<?php
require_once 'config.php';

validateApiKey();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Only GET method allowed', null, 405);
}

try {
    $cycleId = $_GET['cycle_id'] ?? $_GET['billing_cycle_id'] ?? null;

    if (empty($cycleId)) {
        // Fallback to active billing cycle
        $cycleQuery = $conn->query("
            SELECT id, cycle_name, start_date, end_date, status, due_date, created_at
            FROM billing_cycles
            WHERE status = 'active'
            ORDER BY start_date DESC
            LIMIT 1
        ");
        $activeCycle = $cycleQuery && $cycleQuery->num_rows > 0 ? $cycleQuery->fetch_assoc() : null;

        if (!$activeCycle) {
            sendResponse(false, 'No active billing cycle found', null, 404);
        }

        $cycleId = $activeCycle['id'];
    }

    // Prepare client query with scan status
    $stmt = $conn->prepare("
        SELECT
            cl.id,
            cl.code,
            cl.firstname,
            cl.lastname,
            cl.middlename,
            cl.contact,
            cl.address,
            cl.meter_code,
            cl.status AS client_status,
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
        WHERE cl.status = 1 AND cl.delete_flag = 0
        ORDER BY cl.lastname, cl.firstname
    ");

    if (!$stmt) {
        throw new Exception('Failed to prepare client query: ' . $conn->error);
    }

    $stmt->bind_param("ii", $cycleId, $cycleId);
    $stmt->execute();
    $result = $stmt->get_result();

    $clients = [];
    while ($row = $result->fetch_assoc()) {
        $clients[] = [
            'id' => (string)$row['id'],
            'firstname' => $row['firstname'],
            'lastname' => $row['lastname'],
            'middlename' => $row['middlename'],
            'contact' => $row['contact'],
            'address' => $row['address'],
            'meter_code' => $row['meter_code'],
            'status' => (string)$row['client_status'],
            'billing_cycle_id' => (string)$cycleId,
            'is_scanned' => (bool)$row['is_scanned'],
            'scan_status' => $row['scan_status'] ?? 'not_scanned',
            'last_scanned_date' => $row['last_scanned_date'],
            'meter_reading' => $row['meter_reading'],
        ];
    }

    sendResponse(true, 'Clients for billing cycle retrieved successfully', $clients);
} catch (Exception $e) {
    sendResponse(false, 'Error retrieving clients: ' . $e->getMessage(), null, 500);
}
?>

