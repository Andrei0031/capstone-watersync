<?php
include 'db.php';

header('Content-Type: application/json');

if (isset($_GET['meter_code'])) {
    $meter_code = trim($_GET['meter_code']);
    
    $stmt = $conn->prepare("SELECT id FROM client_list WHERE meter_code = ? AND delete_flag = 0");
    $stmt->bind_param("s", $meter_code);
    $stmt->execute();
    $result = $stmt->get_result();
    
    echo json_encode(['exists' => $result->num_rows > 0]);
} else {
    echo json_encode(['exists' => false]);
}
?>

