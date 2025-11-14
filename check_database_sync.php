<?php
include 'db.php';

echo "<h2>Database Sync Check</h2>";

echo "<h3>1. Client List Table Structure</h3>";
try {
    $stmt = $conn->prepare("DESCRIBE client_list");
    $stmt->execute();
    $result = $stmt->get_result();
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background: #f0f0f0;'><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['Field'] . "</td>";
        echo "<td>" . $row['Type'] . "</td>";
        echo "<td>" . $row['Null'] . "</td>";
        echo "<td>" . $row['Key'] . "</td>";
        echo "<td>" . $row['Default'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

echo "<h3>2. Client List Data (Same as Web Dashboard)</h3>";
try {
    // This is the same query your web dashboard should use
    $stmt = $conn->prepare("
        SELECT 
            cl.id as client_id,
            cl.meter_number,
            cl.connection_date,
            cl.delete_flag,
            c.id as customer_id,
            c.firstname,
            c.lastname,
            c.email,
            c.phone,
            c.address,
            c.status
        FROM client_list cl
        JOIN customer_accounts c ON cl.customer_id = c.id
        WHERE cl.delete_flag = 0
        ORDER BY c.lastname, c.firstname
        LIMIT 10
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 10px 0;'>";
    echo "<tr style='background: #f0f0f0;'>";
    echo "<th>Client ID</th><th>Customer Name</th><th>Meter Number</th><th>Email</th><th>Status</th><th>Connection Date</th>";
    echo "</tr>";
    
    $count = 0;
    while ($row = $result->fetch_assoc()) {
        $count++;
        echo "<tr>";
        echo "<td>" . $row['client_id'] . "</td>";
        echo "<td>" . $row['firstname'] . " " . $row['lastname'] . "</td>";
        echo "<td>" . $row['meter_number'] . "</td>";
        echo "<td>" . $row['email'] . "</td>";
        echo "<td>" . $row['status'] . "</td>";
        echo "<td>" . $row['connection_date'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<strong>Total Active Clients Found: $count</strong><br>";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

echo "<h3>3. Quick Stats Comparison</h3>";
try {
    // Total clients (excluding deleted)
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM client_list WHERE delete_flag = 0");
    $stmt->execute();
    $total_clients = $stmt->get_result()->fetch_assoc()['total'];
    
    // Active customers
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM customer_accounts WHERE status = 'active'");
    $stmt->execute();
    $active_customers = $stmt->get_result()->fetch_assoc()['total'];
    
    // Bills
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM billing_list WHERE status = 0");
    $stmt->execute();
    $unpaid_bills = $stmt->get_result()->fetch_assoc()['total'];
    
    echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px;'>";
    echo "<strong>Database Stats (What Mobile App Should See):</strong><br>";
    echo "Total Clients: $total_clients<br>";
    echo "Active Customers: $active_customers<br>";
    echo "Unpaid Bills: $unpaid_bills<br>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

echo "<hr>";
echo "<h3>4. API Endpoint Test</h3>";
echo "<p>Test your mobile API by visiting:</p>";
echo "<ul>";
echo "<li><a href='http://localhost/CAPSTONE/quick_api_test.php' target='_blank'>Quick API Test</a></li>";
echo "<li><a href='http://localhost/CAPSTONE/api/dashboard_stats.php' target='_blank'>Dashboard Stats API</a> (should show JSON)</li>";
echo "<li><a href='http://localhost/CAPSTONE/api/client_list.php' target='_blank'>Client List API</a> (should show JSON)</li>";
echo "</ul>";

$conn->close();
?> 