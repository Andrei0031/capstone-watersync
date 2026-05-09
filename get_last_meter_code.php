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
    // Prefer the most recently created numeric meter code to keep sequence predictable
    $latestSql = "SELECT meter_code 
                  FROM client_list 
                  WHERE meter_code REGEXP '^[0-9]+$'
                    AND delete_flag = 0
                  ORDER BY id DESC 
                  LIMIT 1";
    $result = $conn->query($latestSql);
    if ($result && $row = $result->fetch_assoc()) {
        $last_meter_code = $row['meter_code'];
        $next_meter_code = strval(intval($last_meter_code) + 1);
        echo json_encode([
            'success' => true,
            'last_meter_code' => $last_meter_code,
            'next_meter_code' => $next_meter_code
        ]);
        exit;
    }

    // Fallback to max numeric value if no recent numeric codes exist
    $maxSql = "SELECT MAX(CAST(meter_code AS UNSIGNED)) AS max_code
               FROM client_list 
               WHERE meter_code REGEXP '^[0-9]+$'
                 AND delete_flag = 0";
    $result = $conn->query($maxSql);
    if ($result) {
        $row = $result->fetch_assoc();
        if ($row && $row['max_code'] !== null) {
            $last_meter_code = $row['max_code'];
            $next_meter_code = strval(intval($last_meter_code) + 1);
            echo json_encode([
                'success' => true,
                'last_meter_code' => strval($last_meter_code),
                'next_meter_code' => $next_meter_code
            ]);
            exit;
        }
    }

    // No numeric meter codes found - start from 1
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

