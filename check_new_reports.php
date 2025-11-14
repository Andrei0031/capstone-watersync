<?php
include 'db.php';

header('Content-Type: application/json');

$sql = "SELECT o.*, cl.firstname, cl.lastname, cl.meter_code 
        FROM outage_reports o
        JOIN client_list cl ON o.client_id = cl.id
        WHERE o.status = 0
        ORDER BY o.created_at DESC";

$result = $conn->query($sql);
$reports = [];

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $reports[] = [
            'id' => $row['id'],
            'customer_name' => $row['firstname'] . ' ' . $row['lastname'],
            'meter_code' => $row['meter_code'],
            'location' => $row['location'],
            'description' => $row['description'],
            'report_date' => date('M d, Y H:i', strtotime($row['created_at'])),
            'status' => $row['status']
        ];
    }
}

echo json_encode([
    'success' => true,
    'count' => count($reports),
    'reports' => $reports
]);

$conn->close();
?> 