<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_id'])) {
    die('Unauthorized');
}

include 'db.php';

// Get all bills with their status
$sql = "SELECT 
        id,
        status,
        due_date,
        total,
        CASE 
            WHEN status = 1 THEN 'paid'
            WHEN status = 0 AND due_date < CURRENT_DATE() THEN 'overdue'
            WHEN status = 0 THEN 'pending'
        END as payment_status
        FROM billing_list
        ORDER BY id";

$result = $conn->query($sql);

echo "<h2>Bill Status Debug</h2>";
echo "<pre>";

$counts = ['paid' => 0, 'pending' => 0, 'overdue' => 0];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "Bill #{$row['id']}: {$row['payment_status']} (Status: {$row['status']}, Due: {$row['due_date']})\n";
        $counts[$row['payment_status']]++;
    }
}

echo "\nSummary:\n";
echo "Paid: {$counts['paid']}\n";
echo "Pending: {$counts['pending']}\n";
echo "Overdue: {$counts['overdue']}\n";
echo "Total: " . array_sum($counts) . "\n";

echo "</pre>"; 