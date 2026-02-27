<?php
/**
 * Notification Status Checker
 * Use this to diagnose why emails aren't being sent
 */

include 'db.php';
include 'simple_notifications.php';

// Get the ENABLE_REAL_EMAIL value by reading the file
$file_content = file_get_contents('simple_notifications.php');
preg_match('/\$ENABLE_REAL_EMAIL\s*=\s*(true|false)/i', $file_content, $matches);
$email_enabled = isset($matches[1]) && strtolower($matches[1]) === 'true';

echo "<h2>Email Notification Status Check</h2>";
echo "<hr>";

// Check 1: Email sending enabled?
echo "<h3>1. Email Sending Status</h3>";
if ($email_enabled) {
    echo "<p style='color: green;'>✅ Real email sending is ENABLED</p>";
} else {
    echo "<p style='color: red;'>❌ Real email sending is DISABLED (Dummy Mode)</p>";
    echo "<p><strong>Fix:</strong> Edit <code>simple_notifications.php</code> line 136 and change:</p>";
    echo "<pre>\$ENABLE_REAL_EMAIL = false;  // Change to: true</pre>";
}
echo "<hr>";

// Check 2: Get a sample client to check
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : null;

if ($client_id) {
    echo "<h3>2. Checking Client ID: $client_id</h3>";
    
    // Check if client exists and is registered
    $stmt = $conn->prepare("
        SELECT 
            cl.id, cl.firstname, cl.lastname, cl.contact as phone, cl.email,
            ca.email as registered_email, ca.id as account_id, ca.status as account_status
        FROM client_list cl
        LEFT JOIN customer_accounts ca ON cl.id = ca.client_id
        WHERE cl.id = ?
    ");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $client = $stmt->get_result()->fetch_assoc();
    
    if ($client) {
        echo "<p><strong>Client:</strong> {$client['firstname']} {$client['lastname']}</p>";
        echo "<p><strong>Client Email:</strong> " . ($client['email'] ?: 'Not set') . "</p>";
        echo "<p><strong>Registered Email:</strong> " . ($client['registered_email'] ?: 'Not registered') . "</p>";
        echo "<p><strong>Account ID:</strong> " . ($client['account_id'] ?: 'None') . "</p>";
        echo "<p><strong>Account Status:</strong> " . ($client['account_status'] ? 'Active' : 'Inactive') . "</p>";
        
        if ($client['account_id']) {
            echo "<p style='color: green;'>✅ Client is registered in customer_accounts</p>";
        } else {
            echo "<p style='color: red;'>❌ Client is NOT registered in customer_accounts</p>";
            echo "<p><strong>Fix:</strong> Register this client in the customer accounts system or manually insert into customer_accounts table.</p>";
        }
        
        if (empty($client['registered_email']) && empty($client['email'])) {
            echo "<p style='color: red;'>❌ No email address found for this client</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ Client ID $client_id not found</p>";
    }
    echo "<hr>";
}

// Check 3: Show recent notification logs
echo "<h3>3. Recent Notification Logs</h3>";
$logs_sql = "SELECT * FROM notification_logs ORDER BY created_at DESC LIMIT 10";
$logs_result = $conn->query($logs_sql);

if ($logs_result && $logs_result->num_rows > 0) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Client ID</th><th>Bill ID</th><th>Type</th><th>Recipient</th><th>Status</th><th>Date</th></tr>";
    while ($log = $logs_result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$log['id']}</td>";
        echo "<td>{$log['client_id']}</td>";
        echo "<td>" . ($log['bill_id'] ?: '-') . "</td>";
        echo "<td>{$log['notification_type']}</td>";
        echo "<td>{$log['recipient']}</td>";
        echo "<td>{$log['status']}</td>";
        echo "<td>{$log['created_at']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No notification logs found. This means no notifications have been sent yet.</p>";
}
echo "<hr>";

// Check 4: List registered customers
echo "<h3>4. Registered Customers (Will Receive Notifications)</h3>";
$registered_sql = "SELECT ca.*, cl.firstname, cl.lastname, cl.meter_code 
                   FROM customer_accounts ca 
                   JOIN client_list cl ON ca.client_id = cl.id 
                   ORDER BY ca.created_at DESC LIMIT 20";
$registered_result = $conn->query($registered_sql);

if ($registered_result && $registered_result->num_rows > 0) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Client ID</th><th>Name</th><th>Meter Code</th><th>Email</th><th>Status</th></tr>";
    while ($row = $registered_result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$row['client_id']}</td>";
        echo "<td>{$row['firstname']} {$row['lastname']}</td>";
        echo "<td>{$row['meter_code']}</td>";
        echo "<td>{$row['email']}</td>";
        echo "<td>" . ($row['status'] ? 'Active' : 'Inactive') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "<p><strong>Tip:</strong> Use ?client_id=X in the URL to check a specific client (e.g., ?client_id=1)</p>";
} else {
    echo "<p style='color: red;'>❌ No registered customers found!</p>";
    echo "<p>Customers must be registered in the customer_accounts table to receive notifications.</p>";
}

echo "<hr>";
echo "<h3>Summary</h3>";
echo "<ul>";
echo "<li>For emails to be sent, you need:</li>";
echo "<ol>";
echo "<li>Set <code>\$ENABLE_REAL_EMAIL = true;</code> in simple_notifications.php</li>";
echo "<li>Customer must be registered in <code>customer_accounts</code> table</li>";
echo "<li>Customer must have an email address</li>";
echo "</ol>";
echo "</ul>";
?>

