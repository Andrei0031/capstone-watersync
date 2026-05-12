<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit();
}

include 'db.php';

$sql = "SELECT id, rate, excess_rate FROM category_rates";
$result = $conn->query($sql);
$rates = [];

while ($row = $result->fetch_assoc()) {
    $rates[$row['id']] = [
        'rate' => floatval($row['rate']),
        'excess_rate' => floatval($row['excess_rate'])
    ];
}

header('Content-Type: application/json');
echo json_encode($rates);
?>
