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
    $sql = "SELECT MAX(CAST(meter_code AS UNSIGNED)) AS max_code
            FROM client_list 
            WHERE meter_code REGEXP '^[0-9]+$'
              AND delete_flag = 0";
    
    $result = $conn->query($sql);
    if ($result && $row = $result->fetch_assoc()) {
        $last_meter_code = $row['max_code'];
        if ($last_meter_code !== null) {
            $next_meter_code = strval(intval($last_meter_code) + 1);
            echo json_encode([
                'success' => true,
                'last_meter_code' => strval($last_meter_code),
                'next_meter_code' => $next_meter_code
            ]);
            exit;
        }
    }

    // No numeric meter codes found
    echo json_encode([
        'success' => true,
        'last_meter_code' => null,
        'next_meter_code' => '1'
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>

