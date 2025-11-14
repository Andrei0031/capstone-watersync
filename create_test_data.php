<?php
include 'db.php';

// Create test customer
$sql_customer = "INSERT INTO client_list (firstname, lastname, meter_code, category_id, status) 
                 VALUES ('Test', 'Customer', 'TEST001', 1, 1)";
$conn->query($sql_customer);
$customer_id = $conn->insert_id;

// Create an overdue bill (with a past due date)
$past_date = date('Y-m-d', strtotime('-10 days')); // Reading date 10 days ago
$due_date = date('Y-m-d', strtotime('-2 days'));   // Due date 2 days ago

$sql_bill = "INSERT INTO billing_list (client_id, reading_date, due_date, reading, previous, rate, total, status) 
             VALUES (?, ?, ?, 15, 0, 10, 150, 0)";
$stmt = $conn->prepare($sql_bill);
$stmt->bind_param("iss", $customer_id, $past_date, $due_date);
$stmt->execute();

if ($conn->error) {
    echo "Error: " . $conn->error;
} else {
    echo "Test customer and overdue bill created successfully!";
} 