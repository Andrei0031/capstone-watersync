<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    echo "Unauthorized";
    exit();
}

include 'db.php';

echo "<html><body><h1>Debug: Soft Delete Analysis</h1>";

// Check records with delete_flag = 1 (soft deleted)
$sql = "SELECT id, firstname, lastname, meter_code, delete_flag, status FROM client_list WHERE delete_flag = 1 LIMIT 20";
$result = $conn->query($sql);

echo "<h2>Soft Deleted Records (delete_flag = 1):</h2>";
if ($result->num_rows > 0) {
    echo "<ul>";
    while ($row = $result->fetch_assoc()) {
        echo "<li>ID: " . $row['id'] . " | Name: " . $row['firstname'] . " " . $row['lastname'] . " | Meter: " . $row['meter_code'] . " | Delete Flag: " . $row['delete_flag'] . "</li>";
    }
    echo "</ul>";
} else {
    echo "<p><strong>No soft deleted records found.</strong></p>";
}

// Check for duplicate meter codes
echo "<h2>Duplicate Meter Codes:</h2>";
$sql2 = "SELECT meter_code, COUNT(*) as cnt FROM client_list WHERE meter_code IS NOT NULL GROUP BY meter_code HAVING cnt > 1";
$result2 = $conn->query($sql2);

if ($result2->num_rows > 0) {
    echo "<ul>";
    while ($row = $result2->fetch_assoc()) {
        echo "<li>Meter Code <strong>" . $row['meter_code'] . "</strong> appears " . $row['cnt'] . " times</li>";
        
        // Show details
        $sql3 = "SELECT id, firstname, lastname, delete_flag, status FROM client_list WHERE meter_code = ?";
        $stmt = $conn->prepare($sql3);
        $stmt->bind_param("s", $row['meter_code']);
        $stmt->execute();
        $res3 = $stmt->get_result();
        
        echo "<ul>";
        while ($r = $res3->fetch_assoc()) {
            $flag = $r['delete_flag'] == 1 ? "DELETED" : "ACTIVE";
            echo "<li>ID " . $r['id'] . " - Delete Flag: " . $flag . " - Status: " . $r['status'] . "</li>";
        }
        echo "</ul>";
    }
    echo "</ul>";
} else {
    echo "<p><strong>No duplicate meter codes found.</strong></p>";
}

echo "</body></html>";
?>
