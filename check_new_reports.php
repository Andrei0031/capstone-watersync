<?php
session_start();
include 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);
    exit;
}

$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
$limit = max(1, min($limit, 20));

$reports = [];
$pendingTotal = 0;

function wsUtcToEpoch($dt): int {
    if (empty($dt)) return 0;
    try {
        $utcTz = new DateTimeZone('UTC');
        $clean = substr((string)$dt, 0, 19);
        $d = DateTime::createFromFormat('Y-m-d H:i:s', $clean, $utcTz);
        if (!$d) $d = new DateTime($clean, $utcTz);
        return (int)$d->getTimestamp();
    } catch (Exception $e) {
        return 0;
    }
}

function wsUtcToPhText($dt): string {
    if (empty($dt)) return '';
    try {
        $utcTz = new DateTimeZone('UTC');
        $phTz  = new DateTimeZone('Asia/Manila');
        $clean = substr((string)$dt, 0, 19);
        $d = DateTime::createFromFormat('Y-m-d H:i:s', $clean, $utcTz);
        if (!$d) $d = new DateTime($clean, $utcTz);
        $d->setTimezone($phTz);
        return $d->format('M d, Y g:i A');
    } catch (Exception $e) {
        return '';
    }
}

function wsTableExists($conn, $tableName) {
    $safe = $conn->real_escape_string($tableName);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

$hasOutageReports = wsTableExists($conn, 'outage_reports');
$hasClientReports = wsTableExists($conn, 'client_reports');

// Source A: outage_reports (water outage form)
if ($hasOutageReports) {
    $outageSql = "SELECT o.id, o.client_id, o.location, o.description, o.status, o.created_at,
                         cl.firstname, cl.lastname, cl.meter_code
                  FROM outage_reports o
                  JOIN client_list cl ON o.client_id = cl.id
                  WHERE o.status = 0
                  ORDER BY o.created_at DESC
                  LIMIT {$limit}";
    $outageResult = $conn->query($outageSql);
    if ($outageResult) {
        while ($row = $outageResult->fetch_assoc()) {
            $reports[] = [
                'uid' => 'outage-' . intval($row['id']),
                'id' => intval($row['id']),
                'source' => 'outage_reports',
                'client_id' => intval($row['client_id']),
                'customer_name' => trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')),
                'meter_code' => $row['meter_code'] ?? '',
                'location' => $row['location'] ?? '',
                'report_type' => 'Water Outage',
                'description' => $row['description'] ?? '',
                'created_at' => $row['created_at'] ?? null,
                'created_at_ts' => wsUtcToEpoch($row['created_at'] ?? null),
                'report_date' => wsUtcToPhText($row['created_at'] ?? null),
                'status' => intval($row['status'] ?? 0)
            ];
        }
    }

    $outageCountResult = $conn->query("SELECT COUNT(*) AS c FROM outage_reports WHERE status = 0");
    if ($outageCountResult && $outageCountResult->num_rows > 0) {
        $pendingTotal += intval($outageCountResult->fetch_assoc()['c'] ?? 0);
    }
}

// Source B: client_reports (legacy/general client report form)
if ($hasClientReports) {
    $clientReportsSql = "SELECT cr.id, cr.client_id, cr.report_type, cr.description, cr.status, cr.created_at,
                                cl.firstname, cl.lastname, cl.meter_code
                         FROM client_reports cr
                         JOIN client_list cl ON cr.client_id = cl.id
                         WHERE cr.status = 0
                         ORDER BY cr.created_at DESC
                         LIMIT {$limit}";
    $clientReportsResult = $conn->query($clientReportsSql);
    if ($clientReportsResult) {
        while ($row = $clientReportsResult->fetch_assoc()) {
            $reports[] = [
                'uid' => 'client-' . intval($row['id']),
                'id' => intval($row['id']),
                'source' => 'client_reports',
                'client_id' => intval($row['client_id']),
                'customer_name' => trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')),
                'meter_code' => $row['meter_code'] ?? '',
                'location' => $row['report_type'] ?? 'Client Report',
                'report_type' => $row['report_type'] ?? 'Client Report',
                'description' => $row['description'] ?? '',
                'created_at' => $row['created_at'] ?? null,
                'created_at_ts' => wsUtcToEpoch($row['created_at'] ?? null),
                'report_date' => wsUtcToPhText($row['created_at'] ?? null),
                'status' => intval($row['status'] ?? 0)
            ];
        }
    }

    $clientCountResult = $conn->query("SELECT COUNT(*) AS c FROM client_reports WHERE status = 0");
    if ($clientCountResult && $clientCountResult->num_rows > 0) {
        $pendingTotal += intval($clientCountResult->fetch_assoc()['c'] ?? 0);
    }
}

// Sort newest first and trim to requested limit.
usort($reports, function ($a, $b) {
    return strtotime($b['created_at'] ?? '1970-01-01 00:00:00') <=> strtotime($a['created_at'] ?? '1970-01-01 00:00:00');
});
if (count($reports) > $limit) {
    $reports = array_slice($reports, 0, $limit);
}

echo json_encode([
    'success' => true,
    'count' => count($reports),
    'pending_total' => $pendingTotal,
    'reports' => $reports,
    'server_time' => date('Y-m-d H:i:s'),
    'server_time_ts' => time(),
    'sources' => [
        'outage_reports' => $hasOutageReports,
        'client_reports' => $hasClientReports
    ]
]);

$conn->close();
?>