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

$since = isset($_GET['since']) ? trim((string)$_GET['since']) : '';
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
$limit = max(1, min($limit, 20));

$whereSql = "o.status = 0";
$types = '';
$params = [];

if ($since !== '') {
    $sinceTs = strtotime($since);
    if ($sinceTs !== false) {
        $sinceNormalized = date('Y-m-d H:i:s', $sinceTs);
        $whereSql .= " AND o.created_at > ?";
        $types .= 's';
        $params[] = $sinceNormalized;
    }
}

$sql = "SELECT o.id, o.client_id, o.location, o.description, o.status, o.created_at,
               cl.firstname, cl.lastname, cl.meter_code
        FROM outage_reports o
        JOIN client_list cl ON o.client_id = cl.id
        WHERE {$whereSql}
        ORDER BY o.created_at DESC
        LIMIT {$limit}";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare query'
    ]);
    exit;
}
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$reports = [];
while ($row = $result->fetch_assoc()) {
    $reports[] = [
        'id' => intval($row['id']),
        'client_id' => intval($row['client_id']),
        'customer_name' => trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')),
        'meter_code' => $row['meter_code'] ?? '',
        'location' => $row['location'] ?? '',
        'description' => $row['description'] ?? '',
        'created_at' => $row['created_at'] ?? null,
        'report_date' => !empty($row['created_at']) ? date('M d, Y h:i A', strtotime($row['created_at'])) : '',
        'status' => intval($row['status'] ?? 0)
    ];
}

$totalsResult = $conn->query("SELECT COUNT(*) AS pending_total FROM outage_reports WHERE status = 0");
$pendingTotal = 0;
if ($totalsResult && $totalsResult->num_rows > 0) {
    $pendingTotal = intval($totalsResult->fetch_assoc()['pending_total'] ?? 0);
}

echo json_encode([
    'success' => true,
    'count' => count($reports),
    'pending_total' => $pendingTotal,
    'reports' => $reports,
    'server_time' => date('Y-m-d H:i:s')
]);

$stmt->close();
$conn->close();
?>