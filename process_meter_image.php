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
    // Check if file was uploaded
    if (!isset($_FILES['meter_image'])) {
        throw new Exception('No image file uploaded');
    }

    $file = $_FILES['meter_image'];
    
    // Validate file
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload failed with error code: ' . $file['error']);
    }

    // Validate file type
    $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
    if (!in_array($file['type'], $allowed_types)) {
        throw new Exception('Invalid file type. Only JPG and PNG are allowed.');
    }

    // Create uploads directory if it doesn't exist
    $upload_dir = 'uploads/meter_images/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    // Generate unique filename
    $filename = uniqid('meter_') . '_' . date('Ymd_His') . '_' . $file['name'];
    $filepath = $upload_dir . $filename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception('Failed to save uploaded file');
    }

    // Process image with OCR
    $ocr = new TesseractOCR($filepath);
    $ocr->digits();  // Only look for digits
    $ocr->whitelist(range(0, 9)); // Only allow numbers
    
    // Get OCR result
    $result = $ocr->run();
    
    // Clean up the result - extract only numbers
    $reading = preg_replace('/[^0-9.]/', '', $result);
    
    // Validate reading
    if (empty($reading)) {
        throw new Exception('Could not detect meter reading from image');
    }

    // Return success response
    echo json_encode([
        'success' => true,
        'reading' => floatval($reading),
        'image_path' => $filepath,
        'raw_result' => $result
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 