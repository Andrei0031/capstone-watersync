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
    if (!isset($_GET['id'])) {
        error_log("Bill ID not provided in request");
        throw new Exception('Bill ID not provided');
    }

    $bill_id = intval($_GET['id']);
    error_log("Processing request for bill ID: " . $bill_id);

    // Get bill details including client information
    $sql = "SELECT b.*, c.firstname, c.lastname, c.meter_code, c.category_id,
            cr.rate, cr.excess_rate
            FROM billing_list b
            JOIN client_list c ON b.client_id = c.id
            LEFT JOIN category_rates cr ON c.category_id = cr.category_id
            WHERE b.id = ?";

    error_log("Executing SQL query: " . $sql);
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Failed to prepare statement: " . $conn->error);
        throw new Exception('Database error while preparing query');
    }

    $stmt->bind_param("i", $bill_id);
    if (!$stmt->execute()) {
        error_log("Failed to execute statement: " . $stmt->error);
        throw new Exception('Database error while executing query');
    }

    $result = $stmt->get_result();
    error_log("Query executed. Found " . $result->num_rows . " rows");

    if ($result->num_rows === 0) {
        error_log("No bill found with ID: " . $bill_id);
        throw new Exception('Bill not found');
    }

    $bill = $result->fetch_assoc();
    error_log("Raw bill data: " . print_r($bill, true));

    // Format dates
    $bill['reading_date_formatted'] = $bill['reading_date'] ? date('Y-m-d', strtotime($bill['reading_date'])) : '';
    $bill['due_date_formatted'] = $bill['due_date'] ? date('Y-m-d', strtotime($bill['due_date'])) : '';

    // Calculate consumption
    $bill['consumption'] = $bill['reading'] - ($bill['previous'] ?? 0);

    // Calculate charges
    if ($bill['consumption'] <= 6) {
        $bill['base_charge'] = $bill['rate'] ?? 0;
        $bill['excess_charge'] = 0;
    } else {
        $bill['base_charge'] = $bill['rate'] ?? 0;
        $bill['excess_charge'] = ($bill['consumption'] - 6) * ($bill['excess_rate'] ?? 0);
    }

    $bill['total'] = $bill['base_charge'] + $bill['excess_charge'];
    error_log("Processed bill data: " . print_r($bill, true));

    $response = [
        'success' => true,
        'data' => $bill
    ];
    
    error_log("Sending response: " . json_encode($response));
    echo json_encode($response);

} catch (Exception $e) {
    error_log("Error in get_bill_details.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// Make sure nothing else is output
exit();
?> 