<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

include 'db.php';

// Include OCR functions (Roboflow + Tesseract)
require_once __DIR__ . '/api/ocr_functions.php';

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

    // Resolve image path (handle both relative and absolute paths)
    $storedPath = $reading['image_path'];
    $imagePath = null;
    
    // Try multiple path formats
    $possiblePaths = [
        $storedPath, // Original path as stored
        __DIR__ . '/' . ltrim($storedPath, '/'), // Relative from root
        realpath($storedPath), // Absolute path
        realpath(__DIR__ . '/' . ltrim($storedPath, '/')) // Absolute from root
    ];
    
    // Also try without _cropped suffix if it exists
    if (strpos($storedPath, '_cropped.') !== false) {
        $originalPath = str_replace('_cropped.', '.', $storedPath);
        $possiblePaths[] = $originalPath;
        $possiblePaths[] = __DIR__ . '/' . ltrim($originalPath, '/');
        $possiblePaths[] = realpath($originalPath);
    }
    
    foreach ($possiblePaths as $path) {
        if ($path && file_exists($path)) {
            $imagePath = $path;
            break;
        }
    }
    
    if (!$imagePath) {
        throw new Exception('Image file not found. Tried: ' . implode(', ', array_filter($possiblePaths)) . '. Stored path: ' . $storedPath);
    }
    
    error_log("Retry reading ID $reading_id: Using image path: $imagePath");

    $ocrProcessed = false;
    $ocrReading = null;
    $extractedText = '';
    $ocrError = null;

    // Try Roboflow digit detection first (preferred method)
    if (function_exists('processImageWithRoboflowDigits')) {
        $ocrResult = processImageWithRoboflowDigits($imagePath);
        if ($ocrResult['success'] && !empty($ocrResult['meter_reading'])) {
            $ocrReading = $ocrResult['meter_reading'];
            $extractedText = $ocrResult['extracted_text'] ?? '';
            $ocrProcessed = true;
            error_log("✓ Retry OCR SUCCESS (Roboflow): Reading ID $reading_id processed with value: $ocrReading");
        } else {
            $ocrError = $ocrResult['error'] ?? 'Roboflow OCR failed';
        }
    }
    
    // If Roboflow failed, try Tesseract as fallback (if available)
    if (!$ocrProcessed && function_exists('processImageWithTesseract')) {
        $tesseractResult = processImageWithTesseract($imagePath);
        if ($tesseractResult['success'] && !empty($tesseractResult['meter_reading'])) {
            $ocrReading = $tesseractResult['meter_reading'];
            $extractedText = $tesseractResult['extracted_text'] ?? '';
            $ocrProcessed = true;
            error_log("✓ Retry OCR SUCCESS (Tesseract): Reading ID $reading_id processed with value: $ocrReading");
        } else {
            $ocrError = $tesseractResult['error'] ?? 'Tesseract OCR failed';
        }
    }
    
    if (!$ocrProcessed) {
        // If Roboflow failed, Tesseract should still work as fallback
        // But if both failed, provide helpful error message
        $errorMsg = $ocrError ?? 'OCR processing failed. Both Roboflow and Tesseract failed to process the image.';
        error_log("✗ Retry OCR FAILED for reading ID $reading_id: $errorMsg");
        
        // Don't throw exception - instead update status to failed with error message
        // This allows user to manually enter the reading
        $update = $conn->prepare("UPDATE pending_meter_readings SET 
            error_message = ?,
            processed_at = NOW()
            WHERE id = ?");
        $update->bind_param("si", $errorMsg, $reading_id);
        $update->execute();
        
        // Return error but don't throw (allows UI to show error)
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $errorMsg . ' You can manually enter the reading value.',
            'can_retry' => true
        ]);
        exit();
    }
    
    // Convert reading to float (handle 5-digit reading like "00792")
    $reading_value = floatval($ocrReading);
    
    // Update reading status with OCR results
    $update = $conn->prepare("UPDATE pending_meter_readings SET 
        status = 'processed',
        reading_value = ?,
        ocr_reading = ?,
        extracted_text = ?,
        error_message = NULL,
        processed_at = NOW()
        WHERE id = ?");
    $update->bind_param("ddsi", $reading_value, $reading_value, $extractedText, $reading_id);
    
    if (!$update->execute()) {
        throw new Exception('Failed to update reading status: ' . $update->error);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Reading processed successfully',
        'reading_value' => $reading_value,
        'extracted_text' => $extractedText
    ]);

} catch (Exception $e) {
    http_response_code(400);
    error_log("Retry reading error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} 