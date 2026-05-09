<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit();
}

include 'db.php';

$sql = "SELECT id, rate FROM category_rates";
$result = $conn->query($sql);
$rates = [];

while ($row = $result->fetch_assoc()) {
    $rates[$row['id']] = floatval($row['rate']);
}

header('Content-Type: application/json');
echo json_encode($rates);
?>
