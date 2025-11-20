<?php
/**
 * Diagnostic script to check uploaded meter readings
 * Usage: Visit this file in browser or run: php check_uploaded_readings.php
 */

session_start();
if (!isset($_SESSION['admin_id'])) {
    die('Unauthorized. Please log in first.');
}

include 'db.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Check Uploaded Readings</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .status-pending { color: orange; }
        .status-processed { color: green; }
        .status-failed { color: red; }
    </style>
</head>
<body>
    <h1>Uploaded Meter Readings Diagnostic</h1>
    
    <?php
    // Check all readings
    $all_sql = "SELECT pmr.*, cl.firstname, cl.lastname, cl.meter_code, cl.status as client_status
                FROM pending_meter_readings pmr 
                LEFT JOIN client_list cl ON pmr.client_id = cl.id 
                ORDER BY pmr.upload_date DESC 
                LIMIT 50";
    $all_result = $conn->query($all_sql);
    
    if (!$all_result) {
        echo "<p style='color: red;'>Error: " . $conn->error . "</p>";
    } else {
        echo "<h2>All Recent Readings (Last 50)</h2>";
        echo "<table>";
        echo "<tr><th>ID</th><th>Client ID</th><th>Client Name</th><th>Meter Code</th><th>Status</th><th>Upload Date</th><th>Image Path</th><th>Mobile Upload ID</th></tr>";
        
        while ($row = $all_result->fetch_assoc()) {
            $client_name = !empty($row['firstname']) ? $row['firstname'] . ' ' . $row['lastname'] : 'Client ID: ' . $row['client_id'];
            $client_status = isset($row['client_status']) && $row['client_status'] != 1 ? ' (Inactive)' : '';
            
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['id']) . "</td>";
            echo "<td>" . htmlspecialchars($row['client_id']) . "</td>";
            echo "<td>" . htmlspecialchars($client_name) . $client_status . "</td>";
            echo "<td>" . htmlspecialchars($row['meter_code'] ?? 'N/A') . "</td>";
            echo "<td class='status-" . htmlspecialchars($row['status']) . "'>" . htmlspecialchars($row['status']) . "</td>";
            echo "<td>" . htmlspecialchars($row['upload_date'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars(substr($row['image_path'], 0, 50)) . "...</td>";
            echo "<td>" . htmlspecialchars($row['mobile_upload_id'] ?? 'N/A') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Count by status
        $count_sql = "SELECT status, COUNT(*) as count FROM pending_meter_readings GROUP BY status";
        $count_result = $conn->query($count_sql);
        
        echo "<h2>Count by Status</h2>";
        echo "<table>";
        echo "<tr><th>Status</th><th>Count</th></tr>";
        while ($row = $count_result->fetch_assoc()) {
            echo "<tr><td>" . htmlspecialchars($row['status']) . "</td><td>" . htmlspecialchars($row['count']) . "</td></tr>";
        }
        echo "</table>";
    }
    ?>
    
    <p><a href="pending_readings.php">← Back to Meter Readings</a></p>
</body>
</html>

