<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    echo "Unauthorized";
    exit();
}

include 'db.php';

echo "<html><body>";
echo "<h1>Database Cleanup - Remove Soft Deleted Records</h1>";

// Count records to delete
$count_sql = "SELECT COUNT(*) as cnt FROM client_list WHERE delete_flag = 1";
$result = $conn->query($count_sql);
$row = $result->fetch_assoc();
$total_to_delete = $row['cnt'];

echo "<p><strong>Records marked for deletion (delete_flag = 1): $total_to_delete</strong></p>";

if ($_POST['confirm_delete'] ?? false) {
    // First delete related payment records
    $pay_delete = "DELETE FROM payment_list WHERE billing_id IN (SELECT id FROM billing_list WHERE client_id IN (SELECT id FROM client_list WHERE delete_flag = 1))";
    $conn->query($pay_delete);
    $pay_deleted = $conn->affected_rows;
    echo "<p>✓ Deleted $pay_deleted payment records</p>";
    
    // Delete billing records
    $bill_delete = "DELETE FROM billing_list WHERE client_id IN (SELECT id FROM client_list WHERE delete_flag = 1)";
    $conn->query($bill_delete);
    $bill_deleted = $conn->affected_rows;
    echo "<p>✓ Deleted $bill_deleted billing records</p>";
    
    // Delete customer accounts
    $ca_delete = "DELETE FROM customer_accounts WHERE client_id IN (SELECT id FROM client_list WHERE delete_flag = 1)";
    $conn->query($ca_delete);
    $ca_deleted = $conn->affected_rows;
    echo "<p>✓ Deleted $ca_deleted customer accounts</p>";
    
    // Finally delete clients
    $cli_delete = "DELETE FROM client_list WHERE delete_flag = 1";
    $conn->query($cli_delete);
    $cli_deleted = $conn->affected_rows;
    echo "<p>✓ Deleted $cli_deleted client records</p>";
    
    echo "<h2 style='color: green;'>✓ Cleanup Complete!</h2>";
    echo "<p>Total records removed: " . ($pay_deleted + $bill_deleted + $ca_deleted + $cli_deleted) . "</p>";
    echo "<a href='view_clients.php'>Back to Clients</a>";
} else {
    echo "<form method='POST'>";
    echo "<p><strong>This will PERMANENTLY delete:</strong></p>";
    echo "<ul>";
    echo "<li>All soft-deleted client records</li>";
    echo "<li>Related billing records</li>";
    echo "<li>Related payments</li>";
    echo "<li>Related customer accounts</li>";
    echo "</ul>";
    echo "<p style='color: red;'><strong>WARNING: This cannot be undone!</strong></p>";
    echo "<button type='submit' name='confirm_delete' value='1' style='background: red; color: white; padding: 10px 20px; font-size: 16px; cursor: pointer;'>Permanently Delete All Soft-Deleted Records</button>";
    echo "</form>";
}

echo "</body></html>";
?>
