<?php
// Check current client_list table structure for QR columns
include 'db.php';

echo "<h2>Checking client_list Table Structure for QR Columns</h2>";

// Get table structure
echo "<h3>Current client_list table structure:</h3>";
$describe_sql = "DESCRIBE client_list";
$result = $conn->query($describe_sql);

echo "<table border='1' style='border-collapse: collapse; width: 100%; margin-bottom: 20px;'>";
echo "<tr style='background: #f0f0f0;'><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";

$qr_columns = [];
while ($row = $result->fetch_assoc()) {
    $is_qr_column = strpos(strtolower($row['Field']), 'qr') !== false;
    $row_style = $is_qr_column ? "style='background: #e8f5e8; font-weight: bold;'" : "";
    
    if ($is_qr_column) {
        $qr_columns[] = $row['Field'];
    }
    
    echo "<tr $row_style>";
    echo "<td>" . $row['Field'] . "</td>";
    echo "<td>" . $row['Type'] . "</td>";
    echo "<td>" . $row['Null'] . "</td>";
    echo "<td>" . $row['Key'] . "</td>";
    echo "<td>" . ($row['Default'] ?? 'NULL') . "</td>";
    echo "<td>" . ($row['Extra'] ?? '') . "</td>";
    echo "</tr>";
}
echo "</table>";

echo "<h3>QR-related columns found:</h3>";
if (!empty($qr_columns)) {
    echo "<ul>";
    foreach ($qr_columns as $column) {
        echo "<li><strong>$column</strong></li>";
    }
    echo "</ul>";
    
    // Check for data in QR columns
    echo "<h3>QR data status:</h3>";
    $data_check_sql = "SELECT COUNT(*) as total_clients";
    foreach ($qr_columns as $column) {
        $data_check_sql .= ", COUNT($column) as {$column}_count";
    }
    $data_check_sql .= " FROM client_list WHERE status = 1";
    
    $data_result = $conn->query($data_check_sql);
    $data_stats = $data_result->fetch_assoc();
    
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr style='background: #f0f0f0;'><th>Metric</th><th>Count</th></tr>";
    echo "<tr><td>Total Active Clients</td><td>{$data_stats['total_clients']}</td></tr>";
    foreach ($qr_columns as $column) {
        $count_key = $column . '_count';
        echo "<tr><td>Clients with $column</td><td>{$data_stats[$count_key]}</td></tr>";
    }
    echo "</table>";
    
} else {
    echo "<p style='color: orange;'>⚠ No QR-related columns found in client_list table.</p>";
    echo "<p>Expected QR columns would be named like: qr_code, qr_data, qr_image, qr_path, etc.</p>";
}

// Sample client data
echo "<h3>Sample client data (first 3 active clients):</h3>";
$sample_sql = "SELECT * FROM client_list WHERE status = 1 LIMIT 3";
$sample_result = $conn->query($sample_sql);

if ($sample_result->num_rows > 0) {
    echo "<div style='overflow-x: auto;'>";
    echo "<table border='1' style='border-collapse: collapse; font-size: 12px;'>";
    
    // Get column names
    $columns = [];
    $first_row = $sample_result->fetch_assoc();
    foreach ($first_row as $key => $value) {
        $columns[] = $key;
    }
    
    // Reset result pointer
    $sample_result = $conn->query($sample_sql);
    
    // Header
    echo "<tr style='background: #f0f0f0;'>";
    foreach ($columns as $column) {
        $is_qr = strpos(strtolower($column), 'qr') !== false;
        $style = $is_qr ? "style='background: #e8f5e8; font-weight: bold;'" : "";
        echo "<th $style>$column</th>";
    }
    echo "</tr>";
    
    // Data rows
    while ($row = $sample_result->fetch_assoc()) {
        echo "<tr>";
        foreach ($columns as $column) {
            $is_qr = strpos(strtolower($column), 'qr') !== false;
            $style = $is_qr ? "style='background: #e8f5e8;'" : "";
            $value = $row[$column] ?? 'NULL';
            if (strlen($value) > 50) {
                $value = substr($value, 0, 47) . '...';
            }
            echo "<td $style>" . htmlspecialchars($value) . "</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
    echo "</div>";
}

$conn->close();
?> 