<?php
session_start();
include 'db.php';

header('Content-Type: application/json');

// Check if user is logged in as admin
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Get password from request
$input = json_decode(file_get_contents('php://input'), true);
$provided_password = $input['password'] ?? '';

if (empty($provided_password)) {
    echo json_encode(['success' => false, 'message' => 'Password is required']);
    exit();
}

// Get stored password hash
$stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'delete_password'");
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

if (!$row || empty($row['setting_value'])) {
    echo json_encode(['success' => false, 'message' => 'Delete password not configured. Please set it in Settings > Additional Fees']);
    exit();
}

$stored_hash = $row['setting_value'];

// Verify password
if (password_verify($provided_password, $stored_hash)) {
    // Store verification in session for this request
    $_SESSION['delete_verified'] = true;
    $_SESSION['delete_verified_time'] = time();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Password verified successfully',
        'verified' => true
    ]);
} else {
    echo json_encode([
        'success' => false, 
        'message' => 'Incorrect password. Please try again.'
    ]);
}
?>

