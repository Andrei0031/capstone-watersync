<?php
require_once 'config.php';

// Headers for mobile app compatibility
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Only POST method allowed', null, 405);
}

// Validate API key or allow local network access
$is_local_network = isLocalNetworkRequest();
if (!$is_local_network) {
    validateApiKey();
}

$input = getInputData();
validateRequiredFields($input, ['client_id', 'image_data']);

// Include OCR functions for auto-processing
require_once __DIR__ . '/ocr_functions.php';

try {
    // Get current active billing cycle
    $cycle_stmt = $conn->prepare("
        SELECT id, cycle_name, start_date, end_date, due_date 
        FROM billing_cycles 
        WHERE status = 'active' 
        ORDER BY start_date DESC 
        LIMIT 1
    ");
    $cycle_stmt->execute();
    $current_cycle = $cycle_stmt->get_result()->fetch_assoc();
    
    if (!$current_cycle) {
        sendResponse(false, 'No active billing cycle found. Please contact administrator.', null, 400);
    }
    
    // Validate client exists and get client info
    $client_stmt = $conn->prepare("
        SELECT cl.id, cl.meter_number, cl.meter_code, cl.customer_id,
               ca.firstname, ca.lastname, ca.email
        FROM client_list cl
        LEFT JOIN customer_accounts ca ON cl.customer_id = ca.id
        WHERE cl.id = ? AND cl.status = 1
    ");
    $client_stmt->bind_param("i", $input['client_id']);
    $client_stmt->execute();
    $client = $client_stmt->get_result()->fetch_assoc();
    
    if (!$client) {
        sendResponse(false, 'Invalid or inactive client ID', null, 404);
    }
    
    // Check for duplicate reading in current cycle
    $duplicate_stmt = $conn->prepare("
        SELECT id FROM pending_meter_readings 
        WHERE client_id = ? AND billing_cycle_id = ? AND status != 'failed'
    ");
    $duplicate_stmt->bind_param("ii", $input['client_id'], $current_cycle['id']);
    $duplicate_stmt->execute();
    $duplicate = $duplicate_stmt->get_result()->fetch_assoc();
    
    if ($duplicate) {
        sendResponse(false, 'Reading already submitted for this billing cycle', [
            'cycle_name' => $current_cycle['cycle_name'],
            'existing_reading_id' => $duplicate['id']
        ], 409);
    }
    
    // Process and save image
    $image_data = base64_decode($input['image_data']);
    if (!$image_data) {
        sendResponse(false, 'Invalid image data', null, 400);
    }
    
    // Create directory structure
    $upload_dir = '../uploads/meter_readings/' . date('Y/m') . '/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Generate unique filename
    $filename = 'mobile_' . $client['meter_code'] . '_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.jpg';
    $filepath = $upload_dir . $filename;
    $relative_path = 'uploads/meter_readings/' . date('Y/m') . '/' . $filename;
    
    // Save image
    if (!file_put_contents($filepath, $image_data)) {
        sendResponse(false, 'Failed to save meter image', null, 500);
    }
    
    // AUTO-PROCESS OCR IMMEDIATELY
    $ocrReading = null;
    $extractedText = '';
    $ocrProcessed = false;
    $ocrError = null;
    $status = 'failed'; // Default to failed, will change if OCR succeeds
    
    try {
        // Try Roboflow digit detection first (preferred method)
        if (function_exists('processImageWithRoboflowDigits')) {
            $ocrResult = processImageWithRoboflowDigits($filepath);
            if ($ocrResult['success'] && !empty($ocrResult['meter_reading'])) {
                $ocrReading = $ocrResult['meter_reading'];
                $extractedText = $ocrResult['extracted_text'] ?? '';
                $ocrProcessed = true;
                $status = 'processed';
                error_log("✓ Auto-OCR SUCCESS (Roboflow): Reading ID will be created with value: $ocrReading");
            } else {
                $ocrError = $ocrResult['error'] ?? 'Roboflow OCR failed';
            }
        }
        
        // If Roboflow failed, try Tesseract as fallback (if available)
        if (!$ocrProcessed && function_exists('processImageWithTesseract')) {
            $tesseractResult = processImageWithTesseract($filepath);
            if ($tesseractResult['success'] && !empty($tesseractResult['meter_reading'])) {
                $ocrReading = $tesseractResult['meter_reading'];
                $extractedText = $tesseractResult['extracted_text'] ?? '';
                $ocrProcessed = true;
                $status = 'processed';
                error_log("✓ Auto-OCR SUCCESS (Tesseract): Reading ID will be created with value: $ocrReading");
            } else {
                $ocrError = $tesseractResult['error'] ?? 'Tesseract OCR failed';
            }
        }
        
        // If both OCR methods failed, use manual reading if provided
        if (!$ocrProcessed && isset($input['meter_reading'])) {
            $meter_reading = floatval($input['meter_reading']);
            if ($meter_reading > 0) {
                $ocrReading = $meter_reading;
                $extractedText = 'Manual reading from mobile app';
                $ocrProcessed = true;
                $status = 'processed';
                error_log("✓ Using manual reading from mobile app: $meter_reading");
            }
        }
        
        if (!$ocrProcessed) {
            $ocrError = $ocrError ?? 'OCR processing failed and no manual reading provided';
            error_log("✗ Auto-OCR FAILED: $ocrError");
        }
        
    } catch (Exception $e) {
        $ocrError = 'OCR processing exception: ' . $e->getMessage();
        error_log("✗ Auto-OCR EXCEPTION: $ocrError");
    }
    
    // Get mobile device info if provided
    $device_info = $input['device_info'] ?? null;
    $mobile_app_version = $input['app_version'] ?? null;
    $gps_location = $input['gps_location'] ?? null;
    
    // Insert meter reading record with OCR results
    $insert_stmt = $conn->prepare("
        INSERT INTO pending_meter_readings 
        (client_id, billing_cycle_id, image_path, reading_value, reading_date, 
         mobile_upload_id, status, upload_date, device_info, app_version, gps_location,
         ocr_reading, extracted_text, processed_at) 
        VALUES (?, ?, ?, ?, CURDATE(), ?, ?, NOW(), ?, ?, ?, ?, ?, NOW())
    ");
    
    $mobile_upload_id = 'mobile_' . uniqid() . '_' . time();
    $reading_value = $ocrReading ?? (isset($input['meter_reading']) ? floatval($input['meter_reading']) : null);
    
    // Count: client_id(i), billing_cycle_id(i), image_path(s), reading_value(d), mobile_upload_id(s), status(s), device_info(s), app_version(s), gps_location(s), ocr_reading(d), extracted_text(s) = 11 params
    $insert_stmt->bind_param("iisdsssssdss", 
        $input['client_id'],
        $current_cycle['id'],
        $relative_path,
        $reading_value,
        $mobile_upload_id,
        $status,
        $device_info,
        $mobile_app_version,
        $gps_location,
        $ocrReading,
        $extractedText
    );
    
    if ($insert_stmt->execute()) {
        $reading_id = $insert_stmt->insert_id;
        
        // Log the submission
        $client_name = ($client['firstname'] ?? '') . ' ' . ($client['lastname'] ?? '');
        error_log("Mobile meter reading submitted - Client: $client_name, Reading: " . ($ocrReading ?? 'N/A') . ", Status: $status, Cycle: {$current_cycle['cycle_name']}");
        
        sendResponse(true, $ocrProcessed ? 'Meter reading uploaded and OCR processed successfully' : 'Meter reading uploaded but OCR processing failed', [
            'reading_id' => $reading_id,
            'status' => $status,
            'ocr_processed' => $ocrProcessed,
            'ocr_reading' => $ocrReading,
            'ocr_error' => $ocrError,
            'cycle_info' => [
                'cycle_name' => $current_cycle['cycle_name'],
                'due_date' => $current_cycle['due_date']
            ],
            'client_info' => [
                'name' => $client_name,
                'meter_code' => $client['meter_code'] ?? null
            ],
            'submission_details' => [
                'reading_value' => $reading_value,
                'upload_id' => $mobile_upload_id,
                'timestamp' => date('Y-m-d H:i:s')
            ]
        ]);
    } else {
        // Delete uploaded image if database insert failed
        if (file_exists($filepath)) {
            unlink($filepath);
        }
        sendResponse(false, 'Failed to save meter reading: ' . $conn->error, null, 500);
    }
    
} catch (Exception $e) {
    error_log("Mobile meter reading API error: " . $e->getMessage());
    sendResponse(false, 'Server error occurred', null, 500);
}

/**
 * Check if request is coming from local network
 */
function isLocalNetworkRequest() {
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    
    // Define local network ranges
    $local_ranges = [
        '127.0.0.1',      // Localhost
        '192.168.',       // Private Class C
        '10.',            // Private Class A
        '172.16.',        // Private Class B (start)
        '172.17.',        // Private Class B
        '172.18.',        // Private Class B
        '172.19.',        // Private Class B
        '172.20.',        // Private Class B
        '172.21.',        // Private Class B
        '172.22.',        // Private Class B
        '172.23.',        // Private Class B
        '172.24.',        // Private Class B
        '172.25.',        // Private Class B
        '172.26.',        // Private Class B
        '172.27.',        // Private Class B
        '172.28.',        // Private Class B
        '172.29.',        // Private Class B
        '172.30.',        // Private Class B
        '172.31.',        // Private Class B (end)
        '::1',            // IPv6 localhost
    ];
    
    foreach ($local_ranges as $range) {
        if (strpos($client_ip, $range) === 0) {
            return true;
        }
    }
    
    return false;
}

/**
 * Enhanced sendResponse function with mobile app considerations
 */
function sendResponse($success, $message, $data = null, $status_code = 200) {
    http_response_code($status_code);
    
    $response = [
        'success' => $success,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s'),
        'api_version' => '2.0'
    ];
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit();
}
?> 