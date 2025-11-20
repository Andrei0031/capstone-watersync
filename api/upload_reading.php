<?php
// Check if this file is being included from another file (like pending_readings.php)
// If sendResponse already exists, we're being included - just return early
if (function_exists('sendResponse')) {
    // Already included from web interface - don't execute API endpoint logic
    return;
}

// This file is being called directly as an API endpoint
// Include OCR functions and config
require_once __DIR__ . '/ocr_functions.php';
require_once __DIR__ . '/config.php';
validateApiKey();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Only POST method allowed', null, 405);
}

$input = getInputData();
validateRequiredFields($input, ['client_id', 'image_data']);

try {
    // Validate client exists
    $stmt = $conn->prepare("
        SELECT id, meter_code, firstname, lastname, middlename
        FROM client_list
        WHERE id = ?
    ");
    $stmt->bind_param("i", $input['client_id']);
    $stmt->execute();
    $client = $stmt->get_result()->fetch_assoc();
    
    if (!$client) {
        sendResponse(false, 'Invalid client ID', null, 404);
    }
    
    // Decode base64 image
    $image_data = base64_decode($input['image_data']);
    if (!$image_data) {
        sendResponse(false, 'Invalid image data', null, 400);
    }
    
    // Create directory if it doesn't exist
    $upload_dir = '../uploads/meter_readings/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Generate unique filename
    $filename = uniqid('mobile_') . '_' . date('Y-m-d_H-i-s') . '.jpg';
    $filepath = $upload_dir . $filename;
    
    // Save image
    if (!file_put_contents($filepath, $image_data)) {
        sendResponse(false, 'Failed to save image', null, 500);
    }
    
    // AUTO-PROCESS OCR IMMEDIATELY (always process, no pending status)
    $ocrReading = null;
    $extractedText = '';
    $ocrProcessed = false;
    $ocrError = null;
    
    try {
        // Try Roboflow digit detection first (preferred method)
        if (function_exists('processImageWithRoboflowDigits')) {
            $ocrResult = processImageWithRoboflowDigits($filepath);
            if ($ocrResult['success'] && !empty($ocrResult['meter_reading'])) {
                $ocrReading = $ocrResult['meter_reading'];
                $extractedText = $ocrResult['extracted_text'] ?? '';
                $ocrProcessed = true;
                error_log("✓ Auto-OCR SUCCESS (Roboflow): Reading will be created with value: $ocrReading");
            } else {
                $ocrError = $ocrResult['error'] ?? 'Roboflow OCR failed';
            }
        }
        
        // Roboflow YOLOv8 only - no Tesseract fallback
        
        if (!$ocrProcessed) {
            $ocrError = $ocrError ?? 'OCR processing failed';
            error_log("✗ Auto-OCR FAILED: $ocrError");
        }
        
    } catch (Exception $e) {
        $ocrError = 'OCR processing exception: ' . $e->getMessage();
        error_log("✗ Auto-OCR EXCEPTION: $ocrError");
    }
    
    // Get current active billing cycle
    $cycle_stmt = $conn->prepare("
        SELECT id, cycle_name FROM billing_cycles 
        WHERE status = 'active' 
        ORDER BY start_date DESC 
        LIMIT 1
    ");
    $cycle_stmt->execute();
    $current_cycle = $cycle_stmt->get_result()->fetch_assoc();
    
    // Check if pending_meter_readings table exists, create if not
    $stmt = $conn->prepare("SHOW TABLES LIKE 'pending_meter_readings'");
    $stmt->execute();
    
    if ($stmt->get_result()->num_rows === 0) {
        // Create table if it doesn't exist
        $create_table = "
            CREATE TABLE pending_meter_readings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id INT NOT NULL,
                billing_cycle_id INT NULL,
                image_path VARCHAR(255) NOT NULL,
                mobile_upload_id VARCHAR(100),
                reading_date DATE NULL,
                upload_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                ocr_reading DECIMAL(10,2) NULL,
                extracted_text TEXT NULL,
                verified_reading DECIMAL(10,2) NULL,
                status ENUM('pending', 'processed', 'failed') DEFAULT 'pending',
                admin_notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                processed_at TIMESTAMP NULL,
                FOREIGN KEY (client_id) REFERENCES client_list(id),
                FOREIGN KEY (billing_cycle_id) REFERENCES billing_cycles(id)
            )
        ";
        $conn->query($create_table);
    } else {
        // Table exists - check and add missing columns
        $columns_to_add = [
            'billing_cycle_id' => "ALTER TABLE pending_meter_readings ADD COLUMN billing_cycle_id INT NULL AFTER client_id",
            'reading_date' => "ALTER TABLE pending_meter_readings ADD COLUMN reading_date DATE NULL AFTER mobile_upload_id",
            'upload_date' => "ALTER TABLE pending_meter_readings ADD COLUMN upload_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER reading_date",
            'ocr_reading' => "ALTER TABLE pending_meter_readings ADD COLUMN ocr_reading DECIMAL(10,2) NULL AFTER upload_date",
            'extracted_text' => "ALTER TABLE pending_meter_readings ADD COLUMN extracted_text TEXT NULL AFTER ocr_reading",
            'admin_notes' => "ALTER TABLE pending_meter_readings ADD COLUMN admin_notes TEXT NULL AFTER verified_reading",
        ];
        
        foreach ($columns_to_add as $column => $alter_sql) {
            $check_col = $conn->query("SHOW COLUMNS FROM pending_meter_readings LIKE '$column'");
            if ($check_col->num_rows === 0) {
                @$conn->query($alter_sql);
            }
        }
        
        // Update status enum if needed
        $conn->query("ALTER TABLE pending_meter_readings MODIFY COLUMN status ENUM('pending', 'processed', 'failed') DEFAULT 'pending'");
    }
    
    // Determine status based on OCR processing (no pending - either processed or failed)
    $status = $ocrProcessed ? 'processed' : 'failed';
    
    // Insert into pending_meter_readings with billing cycle
    $stmt = $conn->prepare("
        INSERT INTO pending_meter_readings 
        (client_id, billing_cycle_id, image_path, mobile_upload_id, reading_date, status, upload_date, ocr_reading, extracted_text, processed_at) 
        VALUES (?, ?, ?, ?, CURDATE(), ?, NOW(), ?, ?, NOW())
    ");
    
    $relative_path = 'uploads/meter_readings/' . $filename;
    $mobile_upload_id = isset($input['mobile_upload_id']) ? $input['mobile_upload_id'] : uniqid('mobile_');
    $billing_cycle_id = $current_cycle ? $current_cycle['id'] : null;
    $ocrReadingValue = ($ocrReading !== null && is_numeric($ocrReading)) ? $ocrReading : null;
    
    $stmt->bind_param("iisssdss", 
        $input['client_id'],
        $billing_cycle_id,
        $relative_path,
        $mobile_upload_id,
        $status,
        $ocrReadingValue,
        $extractedText
    );
    
    if (!$stmt->execute()) {
        // Clean up image if database insert fails
        unlink($filepath);
        sendResponse(false, 'Failed to save reading record: ' . $conn->error, null, 500);
    }
    
    $reading_id = $stmt->insert_id;
    
    sendResponse(true, $ocrProcessed ? 'Reading uploaded and OCR processed successfully' : 'Reading uploaded but OCR processing failed', [
        'reading_id' => $reading_id,
        'status' => $status,
        'ocr_processed' => $ocrProcessed,
        'ocr_error' => $ocrError,
        'billing_cycle' => $current_cycle ? [
            'cycle_name' => $current_cycle['cycle_name'],
            'cycle_id' => $current_cycle['id']
        ] : null,
        'client_info' => [
            'meter_code' => $client['meter_code'] ?? null,
            'customer_name' => trim(($client['firstname'] ?? '') . ' ' . ($client['lastname'] ?? ''))
        ],
        'filename' => $filename,
        'ocr_reading' => $ocrReading,
        'extracted_text' => $extractedText
    ]);

} catch (Exception $e) {
    sendResponse(false, 'Upload failed: ' . $e->getMessage(), null, 500);
}
