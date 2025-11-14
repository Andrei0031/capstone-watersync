<?php
session_start();
include 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['client_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

try {
    // Get form data
    $firstname = trim($_POST['firstname']);
    $lastname = trim($_POST['lastname']);
    $contact = trim($_POST['contact']);
    $email = trim($_POST['email']);
    $address = trim($_POST['address']);
    
    // Validate required fields
    if (empty($firstname) || empty($lastname) || empty($contact) || empty($email) || empty($address)) {
        echo json_encode(['success' => false, 'message' => 'All fields are required']);
        exit();
    }
    
    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email format']);
        exit();
    }
    
    // Check if email already exists (excluding current user)
    $stmt = $conn->prepare("SELECT id FROM customer_accounts WHERE email = ? AND client_id != ?");
    $stmt->bind_param("si", $email, $_SESSION['client_id']);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Email already in use']);
        exit();
    }
    
    // Update client_list table
    $stmt = $conn->prepare("UPDATE client_list SET firstname = ?, lastname = ?, contact = ?, address = ? WHERE id = ?");
    $stmt->bind_param("ssssi", $firstname, $lastname, $contact, $address, $_SESSION['client_id']);
    $stmt->execute();
    
    // Update customer_accounts table
    $stmt = $conn->prepare("UPDATE customer_accounts SET email = ? WHERE client_id = ?");
    $stmt->bind_param("si", $email, $_SESSION['client_id']);
    $stmt->execute();
    
    echo json_encode(['success' => true, 'message' => 'Profile updated successfully']);
    
} catch (Exception $e) {
    error_log("Profile update error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error updating profile']);
}

$conn->close(); 