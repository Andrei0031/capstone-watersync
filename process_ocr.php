<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once 'vendor/autoload.php';
use thiagoalessio\TesseractOCR\TesseractOCR;

header('Content-Type: application/json');

try {
    if (!isset($_FILES['meter_image'])) {
        throw new Exception('No image file uploaded');
    }

    $file = $_FILES['meter_image'];
    
    // Validate file
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Error uploading file');
    }

    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
    if (!in_array($file['type'], $allowedTypes)) {
        throw new Exception('Invalid file type. Only JPG and PNG are allowed.');
    }

    // Create uploads directory if it doesn't exist
    $uploadDir = 'uploads/meter_readings/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // Generate unique filename
    $filename = uniqid('meter_') . '_' . basename($file['name']);
    $uploadPath = $uploadDir . $filename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
        throw new Exception('Failed to save uploaded file');
    }

    // Process image with OCR
    $ocr = new TesseractOCR($uploadPath);
    $ocr->digits(); // Only look for digits
    $ocr->whitelist(range(0, 9)); // Whitelist numbers only
    
    // Get OCR result
    $result = $ocr->run();
    
    // Clean up the result - extract only numbers
    $numbers = preg_replace('/[^0-9.]/', '', $result);
    
    // Validate the result
    if (empty($numbers)) {
        throw new Exception('No numbers found in the image');
    }

    // Return success response
    echo json_encode([
        'success' => true,
        'reading' => floatval($numbers),
        'image_path' => $uploadPath
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} 