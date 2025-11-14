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
    if (!isset($_POST['reading_id']) || !isset($_POST['reading_value']) || !isset($_POST['reading_date'])) {
        throw new Exception('Missing required fields');
    }

    $reading_id = intval($_POST['reading_id']);
    $reading_value = floatval($_POST['reading_value']);
    $reading_date = $_POST['reading_date'];

    // Validate reading date
    if (!strtotime($reading_date)) {
        throw new Exception('Invalid reading date');
    }

    // Get reading details to recalculate charges
    $sql = "SELECT pmr.*, c.category_id, cr.rate, cr.excess_rate
            FROM pending_meter_readings pmr
            JOIN client_list c ON pmr.client_id = c.id
            JOIN category_rates cr ON c.category_id = cr.category_id
            WHERE pmr.id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $reading_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        throw new Exception('Reading not found');
    }

    $reading = $result->fetch_assoc();

    // Calculate consumption and charges
    $consumption = $reading_value - ($reading['previous'] ?? 0);
    
    if ($consumption <= 6) {
        $total = $reading['rate'];
    } else {
        $excess = $consumption - 6;
        $total = $reading['rate'] + ($excess * $reading['excess_rate']);
    }

    // Update reading
    $update = $conn->prepare("UPDATE pending_meter_readings SET 
        reading_value = ?,
        reading_date = ?,
        total = ?,
        processed_date = CURRENT_TIMESTAMP
        WHERE id = ?");
    
    $update->bind_param("dsdi", 
        $reading_value,
        $reading_date,
        $total,
        $reading_id
    );

    if (!$update->execute()) {
        throw new Exception('Failed to update reading');
    }

    echo json_encode([
        'success' => true,
        'message' => 'Reading updated successfully',
        'data' => [
            'reading_value' => $reading_value,
            'reading_date' => $reading_date,
            'total' => $total,
            'consumption' => $consumption
        ]
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} 