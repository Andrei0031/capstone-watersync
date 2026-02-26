<?php
include 'db.php';

echo "<h2>Billing Cycle Filter Diagnostic Report</h2>";
echo "<hr>";

// 1. Check if billing_cycle_id column exists
echo "<h3>1. Checking Column Structure</h3>";
$check_column = "SHOW COLUMNS FROM billing_list LIKE 'billing_cycle_id'";
$result = $conn->query($check_column);

if ($result->num_rows == 0) {
    echo "❌ <strong>billing_cycle_id column DOES NOT EXIST</strong><br>";
    echo "Adding column now...<br>";
    $add_column = "ALTER TABLE billing_list 
                   ADD COLUMN billing_cycle_id INT NULL AFTER status,
                   ADD FOREIGN KEY (billing_cycle_id) REFERENCES billing_cycles(id)";
    
    if ($conn->query($add_column)) {
        echo "✅ Successfully added billing_cycle_id column<br>";
    } else {
        echo "❌ Error adding column: " . $conn->error . "<br>";
    }
} else {
    echo "✅ billing_cycle_id column EXISTS<br>";
}

echo "<hr>";

// 2. Check billing cycles
echo "<h3>2. Checking Billing Cycles</h3>";
$cycles_query = "SELECT * FROM billing_cycles ORDER BY start_date DESC LIMIT 10";
$cycles_result = $conn->query($cycles_query);

if ($cycles_result->num_rows == 0) {
    echo "❌ <strong>NO BILLING CYCLES FOUND</strong><br>";
} else {
    echo "✅ Found " . $cycles_result->num_rows . " billing cycles:<br>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Cycle Name</th><th>Start Date</th><th>End Date</th><th>Due Date</th><th>Status</th></tr>";
    while ($cycle = $cycles_result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $cycle['id'] . "</td>";
        echo "<td>" . $cycle['cycle_name'] . "</td>";
        echo "<td>" . $cycle['start_date'] . "</td>";
        echo "<td>" . $cycle['end_date'] . "</td>";
        echo "<td>" . $cycle['due_date'] . "</td>";
        echo "<td>" . $cycle['status'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "<hr>";

// 3. Check bills and their billing_cycle_id
echo "<h3>3. Checking Bills</h3>";
$bills_query = "SELECT b.id, b.client_id, CONCAT(cl.firstname, ' ', cl.lastname) as client_name, 
                       b.reading_date, b.billing_cycle_id, b.total
                FROM billing_list b
                LEFT JOIN client_list cl ON b.client_id = cl.id
                ORDER BY b.reading_date DESC
                LIMIT 20";
$bills_result = $conn->query($bills_query);

if ($bills_result->num_rows == 0) {
    echo "❌ <strong>NO BILLS FOUND</strong><br>";
} else {
    echo "✅ Found " . $bills_result->num_rows . " bills (showing latest 20):<br>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Bill ID</th><th>Client</th><th>Reading Date</th><th>Billing Cycle ID</th><th>Total</th></tr>";
    $null_count = 0;
    while ($bill = $bills_result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $bill['id'] . "</td>";
        echo "<td>" . $bill['client_name'] . "</td>";
        echo "<td>" . $bill['reading_date'] . "</td>";
        echo "<td>" . ($bill['billing_cycle_id'] ?: "<span style='color:red;'><strong>NULL</strong></span>") . "</td>";
        echo "<td>₱" . number_format($bill['total'], 2) . "</td>";
        echo "</tr>";
        if (is_null($bill['billing_cycle_id'])) {
            $null_count++;
        }
    }
    echo "</table>";
    
    if ($null_count > 0) {
        echo "<br><span style='color:red;'><strong>⚠️ WARNING: " . $null_count . " bills have NULL billing_cycle_id</strong></span>";
    }
}

echo "<hr>";

// 4. Auto-populate billing_cycle_id for bills without it
echo "<h3>4. Auto-Populating Missing Billing Cycles</h3>";
$update_sql = "UPDATE billing_list b
               LEFT JOIN billing_cycles bc ON b.reading_date BETWEEN bc.start_date AND bc.end_date
               SET b.billing_cycle_id = bc.id
               WHERE b.billing_cycle_id IS NULL AND bc.id IS NOT NULL";

if ($conn->query($update_sql)) {
    $affected_rows = $conn->affected_rows;
    echo "✅ Successfully assigned billing cycles to <strong>" . $affected_rows . "</strong> bills<br>";
} else {
    echo "❌ Error updating bills: " . $conn->error . "<br>";
}

echo "<hr>";

// 5. Test the filter query
echo "<h3>5. Testing Filter Query</h3>";
$test_sql = "SELECT bc.id, bc.cycle_name, COUNT(b.id) as bill_count
             FROM billing_cycles bc
             LEFT JOIN billing_list b ON bc.id = b.billing_cycle_id
             GROUP BY bc.id, bc.cycle_name
             ORDER BY bc.start_date DESC";
$test_result = $conn->query($test_sql);

echo "Bills per cycle:<br>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Cycle ID</th><th>Cycle Name</th><th>Bill Count</th></tr>";
while ($row = $test_result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . $row['id'] . "</td>";
    echo "<td>" . $row['cycle_name'] . "</td>";
    echo "<td>" . $row['bill_count'] . "</td>";
    echo "</tr>";
}
echo "</table>";

echo "<hr>";

// 6. Specific check for customers
echo "<h3>6. Checking Specific Customers (Arnand Ardhale)</h3>";
$customer_check = "SELECT b.id, cl.firstname, cl.lastname, b.reading_date, b.billing_cycle_id, 
                          bc.cycle_name, b.total
                   FROM billing_list b
                   LEFT JOIN client_list cl ON b.client_id = cl.id
                   LEFT JOIN billing_cycles bc ON b.billing_cycle_id = bc.id
                   WHERE cl.firstname LIKE '%arn%' OR cl.firstname LIKE '%ardh%'
                   ORDER BY b.reading_date DESC";
$customer_result = $conn->query($customer_check);

if ($customer_result->num_rows == 0) {
    echo "❌ No customers found matching 'arn' or 'ardh'<br>";
} else {
    echo "✅ Found " . $customer_result->num_rows . " bills for matching customers:<br>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Bill ID</th><th>Customer</th><th>Reading Date</th><th>Billing Cycle ID</th><th>Cycle Name</th><th>Total</th></tr>";
    while ($row = $customer_result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . $row['firstname'] . " " . $row['lastname'] . "</td>";
        echo "<td>" . $row['reading_date'] . "</td>";
        echo "<td>" . ($row['billing_cycle_id'] ?: "<span style='color:red;'>NULL</span>") . "</td>";
        echo "<td>" . ($row['cycle_name'] ?: "-") . "</td>";
        echo "<td>₱" . number_format($row['total'], 2) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "<hr>";
echo "<h3>✅ Diagnostic Complete</h3>";
echo "<p>If issues persist, check if:</p>";
echo "<ul>";
echo "<li>February billing cycle exists in billing_cycles table</li>";
echo "<li>Bills have correct reading_date values that fall within the cycle dates</li>";
echo "<li>The filter dropdown is selecting the correct cycle ID</li>";
echo "</ul>";

$conn->close();
?>
