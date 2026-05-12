<?php
header('Content-Type: application/json');

include 'db.php';
include 'timezone_helper.php';

watersync_force_timezone($conn);

$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;

if (!$client_id) {
    echo json_encode(['success' => false, 'has_billing' => false]);
    exit;
}

// Check if customer has existing billing records
$sql = "SELECT COUNT(*) as count, MAX(reading_date) as last_billing_date 
        FROM billing_list 
        WHERE client_id = ? 
        ORDER BY reading_date DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $client_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

$hasBilling = $row['count'] > 0;
$lastBillingDate = $row['last_billing_date'];

echo json_encode([
    'success' => true,
    'has_billing' => $hasBilling,
    'billing_count' => intval($row['count']),
    'last_billing_date' => $lastBillingDate ? date('F j, Y', strtotime($lastBillingDate)) : null
]);

$stmt->close();
?>
