<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once 'vendor/autoload.php';
use thiagoalessio\TesseractOCR\TesseractOCR;

include 'db.php';

/**
 * Check if Tesseract OCR is available on the system
 */
function isTesseractAvailable() {
    $possiblePaths = [
        'tesseract',
        'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
        'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
        '/usr/bin/tesseract',
        '/usr/local/bin/tesseract',
    ];
    
    foreach ($possiblePaths as $path) {
        $testCommand = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') 
            ? "\"$path\" --version 2>nul" 
            : "$path --version 2>/dev/null";
        
        $output = [];
        $returnVar = 0;
        @exec($testCommand, $output, $returnVar);
        
        if ($returnVar === 0) {
            return true;
        }
    }
    
    // Try direct execution as fallback
    $testOutput = [];
    @exec('tesseract --version 2>&1', $testOutput, $testReturn);
    return $testReturn === 0;
}

header('Content-Type: application/json');

try {
    if (!isset($_POST['id'])) {
        throw new Exception('Reading ID not provided');
    }

    $reading_id = intval($_POST['id']);

    // Get reading details
    $stmt = $conn->prepare("SELECT * FROM pending_meter_readings WHERE id = ? AND status = 'failed'");
    $stmt->bind_param("i", $reading_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $reading = $result->fetch_assoc();

    if (!$reading) {
        throw new Exception('Reading not found or not in failed status');
    }

    // Check if Tesseract is available
    if (!isTesseractAvailable()) {
        throw new Exception('Tesseract OCR is not installed. Please install Tesseract OCR or enter readings manually.');
    }

    // Process image with OCR
    $ocr = new TesseractOCR($reading['image_path']);
    $ocr->digits(); // Only look for digits
    $ocr->whitelist(range(0, 9)); // Whitelist numbers only
    
    // Get OCR result
    $result = $ocr->run();
    $numbers = preg_replace('/[^0-9.]/', '', $result);
    
    if (empty($numbers)) {
        throw new Exception('No numbers found in the image');
    }
    
    $reading_value = floatval($numbers);
    
    // Update reading status
    $update = $conn->prepare("UPDATE pending_meter_readings SET 
        status = 'processed',
        reading_value = ?,
        error_message = NULL,
        processed_date = CURRENT_TIMESTAMP
        WHERE id = ?");
    $update->bind_param("di", $reading_value, $reading_id);
    
    if (!$update->execute()) {
        throw new Exception('Failed to update reading status');
    }

    echo json_encode([
        'success' => true,
        'message' => 'Reading processed successfully',
        'reading_value' => $reading_value
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} 