<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

include 'db.php';

header('Content-Type: application/json');

try {
    // Get last meter code (numbers only, ordered by numeric value)
    $sql = "SELECT meter_code FROM client_list 
            WHERE meter_code IS NOT NULL 
            AND meter_code != '' 
            AND delete_flag = 0
            ORDER BY CAST(meter_code AS UNSIGNED) DESC, meter_code DESC 
            LIMIT 1";
    
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $last_meter_code = $row['meter_code'];
        
        // Generate next meter code
        $next_meter_code = strval(intval($last_meter_code) + 1);
        
        echo json_encode([
            'success' => true,
            'last_meter_code' => $last_meter_code,
            'next_meter_code' => $next_meter_code
        ]);
    } else {
        // No meter codes found, start with 1
        echo json_encode([
            'success' => true,
            'last_meter_code' => null,
            'next_meter_code' => '1'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>

