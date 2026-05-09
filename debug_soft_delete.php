<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    echo "Unauthorized";
    exit();
}

include 'db.php';

// Check records with delete_flag = 1 (soft deleted)
$sql = "SELECT id, firstname, lastname, meter_code, delete_flag, status, date_created
        FROM client_list 
        WHERE delete_flag = 1
        ORDER BY id DESC
        LIMIT 20";

$result = $conn->query($sql);

echo "<h2>Soft Deleted Records (delete_flag = 1):</h2>";
echo "<table border='1' cellpadding='10'>";
echo "<tr><th>ID</th><th>Name</th><th>Meter Code</th><th>Delete Flag</th><th>Status</th><th>Created</th></tr>";

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . htmlspecialchars($row['firstname'] . " " . $row['lastname']) . "</td>";
        echo "<td>" . $row['meter_code'] . "</td>";
        echo "<td>" . $row['delete_flag'] . "</td>";
        echo "<td>" . $row['status'] . "</td>";
        echo "<td>" . $row['date_created'] . "</td>";
        echo "</tr>";
    }
} else {
    echo "<tr><td colspan='6'>No soft deleted records found.</td></tr>";
}
echo "</table>";

// Check if there are duplicate records (same meter code with different delete flags)
echo "<h2>Duplicate Meter Codes (Active + Deleted):</h2>";
$sql2 = "SELECT meter_code, COUNT(*) as count 
         FROM client_list 
         WHERE meter_code IS NOT NULL 
         GROUP BY meter_code 
         HAVING count > 1";

$result2 = $conn->query($sql2);

if ($result2 && $result2->num_rows > 0) {
    echo "<table border='1' cellpadding='10'>";
    echo "<tr><th>Meter Code</th><th>Records</th><th>Details</th></tr>";
    
    while ($row = $result2->fetch_assoc()) {
        $meter = $row['meter_code'];
        $sql3 = "SELECT id, firstname, lastname, delete_flag, status FROM client_list WHERE meter_code = ?";
        $stmt = $conn->prepare($sql3);
        $stmt->bind_param("s", $meter);
        $stmt->execute();
        $res3 = $stmt->get_result();
        
        $details = "<ul>";
        while ($r = $res3->fetch_assoc()) {
            $details .= "<li>ID: " . $r['id'] . ", Delete: " . $r['delete_flag'] . ", Status: " . $r['status'] . "</li>";
        }
        $details .= "</ul>";
        
        echo "<tr>";
        echo "<td>" . $meter . "</td>";
        echo "<td>" . $row['count'] . "</td>";
        echo "<td>" . $details . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No duplicate meter codes found.</p>";
}

?>
