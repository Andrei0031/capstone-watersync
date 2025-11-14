<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

include 'db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Get form data
        $id = $_POST['bill_id'];
        $reading_date = $_POST['reading_date'];
        $due_date = $_POST['due_date'];
        $reading = floatval($_POST['reading']);
        $previous = floatval($_POST['previous']);
        $status = intval($_POST['status']);

        // Get client's category and rates
        $sql = "SELECT cl.category_id, cr.rate, cr.excess_rate 
                FROM billing_list bl
                JOIN client_list cl ON bl.client_id = cl.id
                JOIN category_rates cr ON cl.category_id = cr.category_id
                WHERE bl.id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $rate_data = $result->fetch_assoc();
        
        if (!$rate_data) {
            throw new Exception('Could not find rate information');
        }

        // Calculate consumption and total
        $consumption = $reading - $previous;
        
        // Calculate total based on consumption and rates
        if ($consumption <= 6) {
            $total = $rate_data['rate']; // Base rate for first 6 cubic meters
        } else {
            $excess_consumption = $consumption - 6;
            $excess_charge = $excess_consumption * $rate_data['excess_rate'];
            $total = $rate_data['rate'] + $excess_charge;
        }

        // Update the billing record
        $update_stmt = $conn->prepare("UPDATE billing_list 
                                     SET reading_date = ?, 
                                         due_date = ?, 
                                         reading = ?, 
                                         previous = ?, 
                                         total = ?, 
                                         status = ? 
                                     WHERE id = ?");
        
        $update_stmt->bind_param("ssddiii", 
            $reading_date, 
            $due_date, 
            $reading, 
            $previous, 
            $total, 
            $status, 
            $id
        );

        if ($update_stmt->execute()) {
            // Return the updated data including the new total
            echo json_encode([
                'success' => true, 
                'message' => 'Billing record updated successfully',
                'data' => [
                    'total' => $total,
                    'consumption' => $consumption,
                    'base_rate' => $rate_data['rate'],
                    'excess_rate' => $rate_data['excess_rate']
                ]
            ]);
        } else {
            throw new Exception('Error updating billing record: ' . $update_stmt->error);
        }

        $update_stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}

$conn->close();
?> 