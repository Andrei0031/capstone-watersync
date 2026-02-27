<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

include 'db.php';
include 'image_cleanup_utility.php';

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
    $originalImagePath = $imagePath;

    // Check if this is a cropped image - if so, try original too
    if (strpos($imagePath, '_cropped.jpg') !== false) {
        $originalImagePath = str_replace('_cropped.jpg', '.jpg', $imagePath);
        // Also try without .jpg extension
        if (!file_exists($originalImagePath)) {
            $originalImagePath = str_replace('_cropped.jpg', '', $imagePath);
        }
    }

    // Try Roboflow digit detection first (preferred method)
    if (function_exists('processImageWithRoboflowDigits')) {
        // Try current image (might be cropped) first
        if (file_exists($imagePath)) {
            error_log("Attempting OCR on current image: $imagePath");
            $ocrResult = processImageWithRoboflowDigits($imagePath);
            if ($ocrResult['success'] && !empty($ocrResult['meter_reading'])) {
                $ocrReading = $ocrResult['meter_reading'];
                $extractedText = $ocrResult['extracted_text'] ?? '';
                $ocrProcessed = true;
                error_log("✓ Retry OCR SUCCESS (Roboflow): Reading ID $reading_id processed with value: $ocrReading");
            } else {
                $ocrError = $ocrResult['error'] ?? 'Roboflow OCR failed';
                error_log("⚠ Retry OCR failed on current image: $ocrError");
            }
        }
        
        // If failed and we have an original image, try that
        if (!$ocrProcessed && $originalImagePath !== $imagePath && file_exists($originalImagePath)) {
            error_log("Attempting OCR on ORIGINAL (uncropped) image: $originalImagePath");
            $ocrResult = processImageWithRoboflowDigits($originalImagePath);
            if ($ocrResult['success'] && !empty($ocrResult['meter_reading'])) {
                $ocrReading = $ocrResult['meter_reading'];
                $extractedText = $ocrResult['extracted_text'] ?? '';
                $ocrProcessed = true;
                error_log("✓ Retry OCR SUCCESS (Roboflow on ORIGINAL): Reading ID $reading_id processed with value: $ocrReading");
            } else {
                $ocrError = $ocrResult['error'] ?? 'Roboflow OCR failed on original image';
                error_log("⚠ Retry OCR failed on original image: $ocrError");
            }
        }
    }
    
    // Roboflow YOLOv8 only - no Tesseract fallback
    if (!$ocrProcessed) {
        $errorMsg = $ocrError ?? 'Roboflow YOLOv8 OCR processing failed. Please check if Roboflow model version 7 is deployed and accessible.';
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

    // Keep image - don't delete after retry processing
    // deleteImageAfterProcessing($reading_id, $conn);

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