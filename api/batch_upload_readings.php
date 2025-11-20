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

/**
 * Enhanced sendResponse for batch API (includes timestamp and api_version)
 */
function sendBatchResponse($success, $message, $data = null, $status_code = 200) {
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

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendBatchResponse(false, 'Only POST method allowed', null, 405);
}

// Validate API key or allow local network access
$is_local_network = isLocalNetworkRequest();
if (!$is_local_network) {
    try {
        validateApiKey();
    } catch (Exception $e) {
        error_log("Batch Upload API: API key validation failed: " . $e->getMessage());
        sendBatchResponse(false, 'Authentication failed: ' . $e->getMessage(), null, 401);
    }
}

// Include OCR functions for auto-processing
require_once __DIR__ . '/ocr_functions.php';

// Log incoming request for debugging
error_log("Batch Upload API: Request received from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
error_log("Batch Upload API: Request method: " . $_SERVER['REQUEST_METHOD']);
error_log("Batch Upload API: Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'not set'));

/**
 * Get input data for batch upload (custom version to avoid sendResponse conflict)
 */
function getBatchInputData() {
    $raw_input = file_get_contents('php://input');
    
    if (empty($raw_input)) {
        error_log("Batch Upload API: No input data received");
        return null;
    }
    
    $input = json_decode($raw_input, true);
    $json_error = json_last_error();
    
    if ($json_error !== JSON_ERROR_NONE) {
        $error_msg = json_last_error_msg();
        error_log("Batch Upload API: JSON decode failed: $error_msg (Error code: $json_error)");
        return ['error' => $error_msg, 'error_code' => $json_error];
    }
    
    return $input;
}

// Get input data with better error handling
$raw_input = file_get_contents('php://input');
error_log("Batch Upload API: Raw input length: " . strlen($raw_input) . " bytes");

if (empty($raw_input)) {
    error_log("Batch Upload API: No input data received");
    sendBatchResponse(false, 'No data received. Please send JSON data in request body.', null, 400);
}

$input = getBatchInputData();

if (is_array($input) && isset($input['error'])) {
    sendBatchResponse(false, 'Invalid JSON data: ' . $input['error'], [
        'json_error_code' => $input['error_code'] ?? 0,
        'json_error_message' => $input['error'],
        'input_preview' => substr($raw_input, 0, 200)
    ], 400);
}

if (!$input || !is_array($input)) {
    error_log("Batch Upload API: Input data is null or not an array after JSON decode");
    sendBatchResponse(false, 'Invalid or empty JSON data', null, 400);
}

// Log input data structure (without full image data)
$logInput = $input;
if (isset($logInput['readings']) && is_array($logInput['readings'])) {
    foreach ($logInput['readings'] as &$reading) {
        if (isset($reading['image_data'])) {
            $reading['image_data'] = '[BASE64_DATA_LENGTH: ' . strlen($reading['image_data']) . ']';
        }
    }
}
error_log("Batch Upload API: Input data structure: " . json_encode($logInput));

// Validate batch data structure
if (!isset($input['readings']) || !is_array($input['readings'])) {
    error_log("Batch Upload API: Validation failed - readings array missing or invalid");
    sendBatchResponse(false, 'Invalid batch data. Expected "readings" array.', [
        'received_keys' => array_keys($input ?? []),
        'readings_type' => isset($input['readings']) ? gettype($input['readings']) : 'not set'
    ], 400);
}

if (empty($input['readings'])) {
    error_log("Batch Upload API: Validation failed - readings array is empty");
    sendBatchResponse(false, 'No readings provided in batch', null, 400);
}

// Limit batch size to prevent timeout
$max_batch_size = 50;
if (count($input['readings']) > $max_batch_size) {
    sendBatchResponse(false, "Batch size exceeds maximum of $max_batch_size readings", null, 400);
}

try {
    // Ensure required columns exist in pending_meter_readings table
    // Add columns in order: gps_location first, then device_info, then app_version
    $check_gps = $conn->query("SHOW COLUMNS FROM pending_meter_readings LIKE 'gps_location'");
    if ($check_gps->num_rows === 0) {
        // Add gps_location after upload_date if it exists
        $check_upload_date = $conn->query("SHOW COLUMNS FROM pending_meter_readings LIKE 'upload_date'");
        if ($check_upload_date && $check_upload_date->num_rows > 0) {
            $conn->query("ALTER TABLE pending_meter_readings ADD COLUMN gps_location VARCHAR(100) NULL AFTER upload_date");
        } else {
            $conn->query("ALTER TABLE pending_meter_readings ADD COLUMN gps_location VARCHAR(100) NULL");
        }
        error_log("Added missing column 'gps_location' to pending_meter_readings table");
    }
    
    $check_device = $conn->query("SHOW COLUMNS FROM pending_meter_readings LIKE 'device_info'");
    if ($check_device->num_rows === 0) {
        // Add device_info after gps_location if it exists, otherwise after upload_date
        $check_gps_again = $conn->query("SHOW COLUMNS FROM pending_meter_readings LIKE 'gps_location'");
        if ($check_gps_again && $check_gps_again->num_rows > 0) {
            $conn->query("ALTER TABLE pending_meter_readings ADD COLUMN device_info VARCHAR(255) NULL AFTER gps_location");
        } else {
            $check_upload_date = $conn->query("SHOW COLUMNS FROM pending_meter_readings LIKE 'upload_date'");
            if ($check_upload_date && $check_upload_date->num_rows > 0) {
                $conn->query("ALTER TABLE pending_meter_readings ADD COLUMN device_info VARCHAR(255) NULL AFTER upload_date");
            } else {
                $conn->query("ALTER TABLE pending_meter_readings ADD COLUMN device_info VARCHAR(255) NULL");
            }
        }
        error_log("Added missing column 'device_info' to pending_meter_readings table");
    }
    
    $check_app = $conn->query("SHOW COLUMNS FROM pending_meter_readings LIKE 'app_version'");
    if ($check_app->num_rows === 0) {
        // Add app_version after device_info if it exists
        $check_device_again = $conn->query("SHOW COLUMNS FROM pending_meter_readings LIKE 'device_info'");
        if ($check_device_again && $check_device_again->num_rows > 0) {
            $conn->query("ALTER TABLE pending_meter_readings ADD COLUMN app_version VARCHAR(50) NULL AFTER device_info");
        } else {
            $check_gps_again = $conn->query("SHOW COLUMNS FROM pending_meter_readings LIKE 'gps_location'");
            if ($check_gps_again && $check_gps_again->num_rows > 0) {
                $conn->query("ALTER TABLE pending_meter_readings ADD COLUMN app_version VARCHAR(50) NULL AFTER gps_location");
            } else {
                $check_upload_date = $conn->query("SHOW COLUMNS FROM pending_meter_readings LIKE 'upload_date'");
                if ($check_upload_date && $check_upload_date->num_rows > 0) {
                    $conn->query("ALTER TABLE pending_meter_readings ADD COLUMN app_version VARCHAR(50) NULL AFTER upload_date");
                } else {
                    $conn->query("ALTER TABLE pending_meter_readings ADD COLUMN app_version VARCHAR(50) NULL");
                }
            }
        }
        error_log("Added missing column 'app_version' to pending_meter_readings table");
    }
    
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
        sendBatchResponse(false, 'No active billing cycle found. Please contact administrator.', null, 400);
    }
    
    $results = [];
    $success_count = 0;
    $failed_count = 0;
    
    // Process each reading in the batch
    foreach ($input['readings'] as $index => $reading_data) {
        $reading_result = [
            'index' => $index,
            'success' => false,
            'client_id' => $reading_data['client_id'] ?? null,
            'error' => null,
            'reading_id' => null
        ];
        
        try {
            // Validate required fields for each reading
            if (!isset($reading_data['client_id']) || !isset($reading_data['image_data'])) {
                throw new Exception('Missing required fields: client_id and image_data are required');
            }
            
            $client_id = intval($reading_data['client_id']);
            
            // Validate client exists
            $client_stmt = $conn->prepare("
                SELECT cl.id, cl.meter_code, cl.firstname, cl.lastname
                FROM client_list cl
                WHERE cl.id = ? AND cl.status = 1
            ");
            $client_stmt->bind_param("i", $client_id);
            $client_stmt->execute();
            $client = $client_stmt->get_result()->fetch_assoc();
            
            if (!$client) {
                throw new Exception('Invalid or inactive client ID: ' . $client_id);
            }
            
            // Check for duplicate reading in current cycle
            $duplicate_stmt = $conn->prepare("
                SELECT id FROM pending_meter_readings 
                WHERE client_id = ? AND billing_cycle_id = ? AND status != 'failed'
            ");
            $duplicate_stmt->bind_param("ii", $client_id, $current_cycle['id']);
            $duplicate_stmt->execute();
            $duplicate = $duplicate_stmt->get_result()->fetch_assoc();
            
            if ($duplicate) {
                throw new Exception('Reading already submitted for this billing cycle');
            }
            
            // Process and save image
            $image_data = base64_decode($reading_data['image_data']);
            if (!$image_data) {
                throw new Exception('Invalid image data');
            }
            
            // Create directory structure
            $upload_dir = '../uploads/meter_readings/' . date('Y/m') . '/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Generate unique filename
            $filename = 'batch_' . $client['meter_code'] . '_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.jpg';
            $filepath = $upload_dir . $filename;
            $relative_path = 'uploads/meter_readings/' . date('Y/m') . '/' . $filename;
            
            // Save image
            if (!file_put_contents($filepath, $image_data)) {
                throw new Exception('Failed to save meter image');
            }
            
            // AUTO-PROCESS OCR IMMEDIATELY
            $ocrReading = null;
            $extractedText = '';
            $ocrProcessed = false;
            $ocrError = null;
            $status = 'failed';
            
            try {
                // Try Roboflow digit detection first
                if (function_exists('processImageWithRoboflowDigits')) {
                    $ocrResult = processImageWithRoboflowDigits($filepath);
                    if ($ocrResult['success'] && !empty($ocrResult['meter_reading'])) {
                        $ocrReading = $ocrResult['meter_reading'];
                        $extractedText = $ocrResult['extracted_text'] ?? '';
                        $ocrProcessed = true;
                        $status = 'processed';
                    } else {
                        $ocrError = $ocrResult['error'] ?? 'Roboflow OCR failed';
                    }
                }
                
                // If Roboflow failed, try Tesseract as fallback
                if (!$ocrProcessed && function_exists('processImageWithTesseract')) {
                    $tesseractResult = processImageWithTesseract($filepath);
                    if ($tesseractResult['success'] && !empty($tesseractResult['meter_reading'])) {
                        $ocrReading = $tesseractResult['meter_reading'];
                        $extractedText = $tesseractResult['extracted_text'] ?? '';
                        $ocrProcessed = true;
                        $status = 'processed';
                    } else {
                        $ocrError = $tesseractResult['error'] ?? 'Tesseract OCR failed';
                    }
                }
                
                // If both OCR methods failed, use manual reading if provided
                if (!$ocrProcessed && isset($reading_data['meter_reading'])) {
                    $meter_reading = floatval($reading_data['meter_reading']);
                    if ($meter_reading > 0) {
                        $ocrReading = $meter_reading;
                        $extractedText = 'Manual reading from mobile app';
                        $ocrProcessed = true;
                        $status = 'processed';
                    }
                }
                
                if (!$ocrProcessed) {
                    $ocrError = $ocrError ?? 'OCR processing failed and no manual reading provided';
                }
                
            } catch (Exception $e) {
                $ocrError = 'OCR processing exception: ' . $e->getMessage();
            }
            
            // Get mobile device info if provided
            $device_info = $reading_data['device_info'] ?? null;
            $mobile_app_version = $reading_data['app_version'] ?? null;
            $gps_location = $reading_data['gps_location'] ?? null;
            
            // Insert meter reading record
            // Use conditional INSERT - only include columns that exist
            $mobile_upload_id = 'batch_' . uniqid() . '_' . time() . '_' . $index;
            $reading_value = $ocrReading ?? (isset($reading_data['meter_reading']) ? floatval($reading_data['meter_reading']) : null);
            
            // Check if optional columns exist
            $check_device_info = $conn->query("SHOW COLUMNS FROM pending_meter_readings LIKE 'device_info'");
            $check_app_version = $conn->query("SHOW COLUMNS FROM pending_meter_readings LIKE 'app_version'");
            $check_gps_location = $conn->query("SHOW COLUMNS FROM pending_meter_readings LIKE 'gps_location'");
            
            $has_device_info = $check_device_info && $check_device_info->num_rows > 0;
            $has_app_version = $check_app_version && $check_app_version->num_rows > 0;
            $has_gps_location = $check_gps_location && $check_gps_location->num_rows > 0;
            
            // Build INSERT statement based on available columns
            if ($has_device_info && $has_app_version && $has_gps_location) {
                // All columns exist - use full INSERT
                $insert_stmt = $conn->prepare("
                    INSERT INTO pending_meter_readings 
                    (client_id, billing_cycle_id, image_path, reading_value, reading_date, 
                     mobile_upload_id, status, upload_date, device_info, app_version, gps_location,
                     ocr_reading, extracted_text, processed_at) 
                    VALUES (?, ?, ?, ?, CURDATE(), ?, ?, NOW(), ?, ?, ?, ?, ?, NOW())
                ");
                $insert_stmt->bind_param("iisdsssssdss", 
                    $client_id,
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
            } else {
                // Some columns missing - use minimal INSERT (without optional columns)
                $insert_stmt = $conn->prepare("
                    INSERT INTO pending_meter_readings 
                    (client_id, billing_cycle_id, image_path, reading_value, reading_date, 
                     mobile_upload_id, status, upload_date,
                     ocr_reading, extracted_text, processed_at) 
                    VALUES (?, ?, ?, ?, CURDATE(), ?, ?, NOW(), ?, ?, NOW())
                ");
                $insert_stmt->bind_param("iisdsssdss", 
                    $client_id,
                    $current_cycle['id'],
                    $relative_path,
                    $reading_value,
                    $mobile_upload_id,
                    $status,
                    $ocrReading,
                    $extractedText
                );
            }
            
            if ($insert_stmt->execute()) {
                $reading_id = $insert_stmt->insert_id;
                
                $reading_result['success'] = true;
                $reading_result['reading_id'] = $reading_id;
                $reading_result['status'] = $status;
                $reading_result['ocr_processed'] = $ocrProcessed;
                $reading_result['ocr_reading'] = $ocrReading;
                $reading_result['client_name'] = trim(($client['firstname'] ?? '') . ' ' . ($client['lastname'] ?? ''));
                $reading_result['meter_code'] = $client['meter_code'] ?? null;
                
                $success_count++;
            } else {
                // Delete uploaded image if database insert failed
                if (file_exists($filepath)) {
                    unlink($filepath);
                }
                throw new Exception('Failed to save meter reading: ' . $conn->error);
            }
            
        } catch (Exception $e) {
            $reading_result['error'] = $e->getMessage();
            $failed_count++;
            error_log("Batch upload error for reading index $index: " . $e->getMessage());
        }
        
        $results[] = $reading_result;
    }
    
    // Log final results
    error_log("Batch Upload API: Completed - Success: $success_count, Failed: $failed_count, Total: " . count($input['readings']));
    
    // Return batch results
    sendBatchResponse(true, "Batch upload completed: $success_count succeeded, $failed_count failed", [
        'total' => count($input['readings']),
        'success_count' => $success_count,
        'failed_count' => $failed_count,
        'cycle_info' => [
            'cycle_name' => $current_cycle['cycle_name'],
            'due_date' => $current_cycle['due_date']
        ],
        'results' => $results
    ]);
    
} catch (Exception $e) {
    $errorMsg = $e->getMessage();
    $errorTrace = $e->getTraceAsString();
    error_log("Batch Upload API ERROR: $errorMsg");
    error_log("Batch Upload API STACK TRACE: $errorTrace");
    sendBatchResponse(false, 'Server error occurred: ' . $errorMsg, [
        'error_type' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], 500);
}

/**
 * Check if request is coming from local network
 */
function isLocalNetworkRequest() {
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    
    $local_ranges = [
        '127.0.0.1', '192.168.', '10.', '172.16.', '172.17.', '172.18.', '172.19.',
        '172.20.', '172.21.', '172.22.', '172.23.', '172.24.', '172.25.', '172.26.',
        '172.27.', '172.28.', '172.29.', '172.30.', '172.31.', '::1',
    ];
    
    foreach ($local_ranges as $range) {
        if (strpos($client_ip, $range) === 0) {
            return true;
        }
    }
    
    return false;
}

