<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

include 'db.php';

$month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

$sql = "SELECT b.*, c.firstname, c.lastname, c.meter_code 
        FROM billing_list b 
        LEFT JOIN client_list c ON b.client_id = c.id 
        WHERE b.status = 1 
        AND DATE_FORMAT(b.reading_date, '%Y-%m') = ?
        ORDER BY b.reading_date DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $month);
$stmt->execute();
$result = $stmt->get_result();

$bills = [];
while ($row = $result->fetch_assoc()) {
    $bills[] = [
        'id' => $row['id'],
        'firstname' => $row['firstname'],
        'lastname' => $row['lastname'],
        'meter_code' => $row['meter_code'],
        'reading_date' => $row['reading_date'],
        'reading' => $row['reading'],
        'previous' => $row['previous'],
        'total' => $row['total'],
        'updated_at' => $row['updated_at']
    ];
}

header('Content-Type: application/json');
echo json_encode($bills); 