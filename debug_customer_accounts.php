<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    echo "Unauthorized";
    exit();
}

include 'db.php';

// Check for auto-created accounts that might not be showing
$sql = "SELECT ca.id, ca.client_id, ca.email, ca.status, ca.created_at, cl.firstname, cl.lastname, cl.meter_code
        FROM customer_accounts ca
        LEFT JOIN client_list cl ON ca.client_id = cl.id
        ORDER BY ca.created_at DESC
        LIMIT 20";

$result = $conn->query($sql);

echo "<h2>All Customer Accounts in Database:</h2>";
echo "<pre>";
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo json_encode($row, JSON_PRETTY_PRINT) . "\n";
    }
} else {
    echo "No customer accounts found.";
}
echo "</pre>";

// Check for orphaned client records (clients without customer accounts)
$sql2 = "SELECT id, firstname, lastname, meter_code FROM client_list WHERE id NOT IN (SELECT DISTINCT client_id FROM customer_accounts) LIMIT 10";
$result2 = $conn->query($sql2);

echo "<h2>Clients without Customer Accounts:</h2>";
echo "<pre>";
if ($result2 && $result2->num_rows > 0) {
    while ($row = $result2->fetch_assoc()) {
        echo json_encode($row, JSON_PRETTY_PRINT) . "\n";
    }
} else {
    echo "All clients have customer accounts.";
}
echo "</pre>";
?>
