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
    validateApiKey();
}

// Include OCR functions for auto-processing
require_once __DIR__ . '/ocr_functions.php';

$input = getInputData();

// Validate batch data structure
if (!isset($input['readings']) || !is_array($input['readings'])) {
    sendBatchResponse(false, 'Invalid batch data. Expected "readings" array.', null, 400);
}

if (empty($input['readings'])) {
    sendBatchResponse(false, 'No readings provided in batch', null, 400);
}

// Limit batch size to prevent timeout
$max_batch_size = 50;
if (count($input['readings']) > $max_batch_size) {
    sendBatchResponse(false, "Batch size exceeds maximum of $max_batch_size readings", null, 400);
}

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
            $insert_stmt = $conn->prepare("
                INSERT INTO pending_meter_readings 
                (client_id, billing_cycle_id, image_path, reading_value, reading_date, 
                 mobile_upload_id, status, upload_date, device_info, app_version, gps_location,
                 ocr_reading, extracted_text, processed_at) 
                VALUES (?, ?, ?, ?, CURDATE(), ?, ?, NOW(), ?, ?, ?, ?, ?, NOW())
            ");
            
            $mobile_upload_id = 'batch_' . uniqid() . '_' . time() . '_' . $index;
            $reading_value = $ocrReading ?? (isset($reading_data['meter_reading']) ? floatval($reading_data['meter_reading']) : null);
            
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
    error_log("Batch upload API error: " . $e->getMessage());
    sendBatchResponse(false, 'Server error occurred: ' . $e->getMessage(), null, 500);
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

