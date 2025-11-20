<?php
// Enable error reporting for debugging (but don't display to client)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors to client
ini_set('log_errors', 1);

// Start output buffering to catch any unexpected output
ob_start();

// Set error handler to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_end_clean(); // Clear any output
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Server error: ' . $error['message'],
            'timestamp' => date('Y-m-d H:i:s'),
            'api_version' => '2.0',
            'error_details' => [
                'file' => $error['file'],
                'line' => $error['line'],
                'type' => $error['type']
            ]
        ], JSON_PRETTY_PRINT);
        exit();
    }
});

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
    // Clear any output buffer
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    
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
    
    $json_output = json_encode($response, JSON_PRETTY_PRINT);
    
    if ($json_output === false) {
        // JSON encoding failed
        error_log("Batch Upload API: JSON encoding failed: " . json_last_error_msg());
        $json_output = json_encode([
            'success' => false,
            'message' => 'Response encoding error',
            'timestamp' => date('Y-m-d H:i:s'),
            'api_version' => '2.0'
        ]);
    }
    
    echo $json_output;
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
    // Verify database connection
    if (!isset($conn) || !$conn) {
        throw new Exception('Database connection not available');
    }
    
    // Test database connection
    if (!$conn->ping()) {
        throw new Exception('Database connection lost');
    }
    
    // Ensure required columns exist in pending_meter_readings table
    // Add columns in order: gps_location first, then device_info, then app_version
    try {
        $check_gps = $conn->query("SHOW COLUMNS FROM pending_meter_readings LIKE 'gps_location'");
        if (!$check_gps || $check_gps->num_rows === 0) {
            // Add gps_location after upload_date if it exists
            $check_upload_date = $conn->query("SHOW COLUMNS FROM pending_meter_readings LIKE 'upload_date'");
            if ($check_upload_date && $check_upload_date->num_rows > 0) {
                $conn->query("ALTER TABLE pending_meter_readings ADD COLUMN gps_location VARCHAR(100) NULL AFTER upload_date");
            } else {
                $conn->query("ALTER TABLE pending_meter_readings ADD COLUMN gps_location VARCHAR(100) NULL");
            }
            error_log("Added missing column 'gps_location' to pending_meter_readings table");
        }
    } catch (Exception $e) {
        error_log("Error checking/adding gps_location column: " . $e->getMessage());
    }
    
    try {
        $check_device = $conn->query("SHOW COLUMNS FROM pending_meter_readings LIKE 'device_info'");
        if (!$check_device || $check_device->num_rows === 0) {
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
    } catch (Exception $e) {
        error_log("Error checking/adding device_info column: " . $e->getMessage());
    }
    
    try {
        $check_app = $conn->query("SHOW COLUMNS FROM pending_meter_readings LIKE 'app_version'");
        if (!$check_app || $check_app->num_rows === 0) {
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
    } catch (Exception $e) {
        error_log("Error checking/adding app_version column: " . $e->getMessage());
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
            
            // BATCH UPLOAD: Save as 'pending' status - OCR will be processed manually from web interface
            // This allows admin to review and process OCR from the pending tab
            $ocrReading = null;
            $extractedText = '';
            $ocrProcessed = false;
            $ocrError = null;
            $status = 'pending'; // Always 'pending' for batch uploads - manual OCR processing from web
            
            // If manual reading is provided from mobile app, use it but still keep status as 'pending'
            // Admin can verify and process from web interface
            if (isset($reading_data['meter_reading'])) {
                $meter_reading = floatval($reading_data['meter_reading']);
                if ($meter_reading > 0) {
                    $ocrReading = $meter_reading;
                    $extractedText = 'Manual reading from mobile app - pending verification';
                    error_log("Batch Upload: Manual reading provided for client $client_id: $meter_reading (status: pending for verification)");
                }
            }
            
            // NOTE: OCR processing is skipped for batch uploads
            // All readings go to 'pending' status and will be processed manually from web interface
            // This ensures quality control and allows admin to verify readings before processing
            
            // Get mobile device info if provided
            $device_info = $reading_data['device_info'] ?? null;
            $mobile_app_version = $reading_data['app_version'] ?? null;
            $gps_location = $reading_data['gps_location'] ?? null;
            
            // Insert meter reading record
            // Use conditional INSERT - only include columns that exist
            $mobile_upload_id = 'batch_' . uniqid() . '_' . time() . '_' . $index;
            $reading_value = $ocrReading ?? (isset($reading_data['meter_reading']) ? floatval($reading_data['meter_reading']) : null);
            
            // Check if optional columns exist (with error handling)
            $has_device_info = false;
            $has_app_version = false;
            $has_gps_location = false;
            
            try {
                $check_device_info = $conn->query("SHOW COLUMNS FROM pending_meter_readings LIKE 'device_info'");
                $has_device_info = $check_device_info && $check_device_info->num_rows > 0;
            } catch (Exception $e) {
                error_log("Error checking device_info column: " . $e->getMessage());
            }
            
            try {
                $check_app_version = $conn->query("SHOW COLUMNS FROM pending_meter_readings LIKE 'app_version'");
                $has_app_version = $check_app_version && $check_app_version->num_rows > 0;
            } catch (Exception $e) {
                error_log("Error checking app_version column: " . $e->getMessage());
            }
            
            try {
                $check_gps_location = $conn->query("SHOW COLUMNS FROM pending_meter_readings LIKE 'gps_location'");
                $has_gps_location = $check_gps_location && $check_gps_location->num_rows > 0;
            } catch (Exception $e) {
                error_log("Error checking gps_location column: " . $e->getMessage());
            }
            
            // Build INSERT statement - use minimal required columns first
            // Handle optional columns by checking if they exist before including them
            $base_sql = "INSERT INTO pending_meter_readings 
                (client_id, billing_cycle_id, image_path, reading_value, reading_date, 
                 mobile_upload_id, status, upload_date, ocr_reading, extracted_text, processed_at) 
                VALUES (?, ?, ?, ?, CURDATE(), ?, ?, NOW(), ?, ?, NOW())";
            
            // Prepare base statement
            $insert_stmt = $conn->prepare($base_sql);
            if (!$insert_stmt) {
                $error_msg = $conn->error ?: 'Unknown database error';
                error_log("Batch Upload: Prepare failed: $error_msg | SQL: $base_sql");
                throw new Exception('Failed to prepare INSERT statement: ' . $error_msg);
            }
            
            // Bind base parameters
            // Types: i, i, s, d, s, s, d, s = 8 parameters
            $ocrReading_val = $ocrReading !== null ? $ocrReading : null;
            $extractedText_val = $extractedText !== null ? $extractedText : '';
            
            if (!$insert_stmt->bind_param("iisdssds", 
                $client_id,
                $current_cycle['id'],
                $relative_path,
                $reading_value,
                $mobile_upload_id,
                $status,
                $ocrReading_val,
                $extractedText_val
            )) {
                throw new Exception('Failed to bind base parameters: ' . $insert_stmt->error);
            }
            
            // Execute base insert first
            if (!$insert_stmt->execute()) {
                $error_msg = $insert_stmt->error ?: $conn->error;
                error_log("Batch Upload: Execute failed for client $client_id: $error_msg");
                throw new Exception('Failed to execute INSERT: ' . $error_msg);
            }
            
            $reading_id = $insert_stmt->insert_id;
            $insert_stmt->close();
            
            // Update optional columns if they exist and were provided
            if ($reading_id && ($has_device_info || $has_app_version || $has_gps_location)) {
                $update_parts = [];
                $update_types = "";
                $update_values = [];
                
                if ($has_device_info && $device_info !== null) {
                    $update_parts[] = "device_info = ?";
                    $update_types .= "s";
                    $update_values[] = $device_info;
                }
                if ($has_app_version && $mobile_app_version !== null) {
                    $update_parts[] = "app_version = ?";
                    $update_types .= "s";
                    $update_values[] = $mobile_app_version;
                }
                if ($has_gps_location && $gps_location !== null) {
                    $update_parts[] = "gps_location = ?";
                    $update_types .= "s";
                    $update_values[] = $gps_location;
                }
                
                if (!empty($update_parts)) {
                    $update_sql = "UPDATE pending_meter_readings SET " . implode(", ", $update_parts) . " WHERE id = ?";
                    $update_types .= "i";
                    $update_values[] = $reading_id;
                    
                    $update_stmt = $conn->prepare($update_sql);
                    if ($update_stmt) {
                        $update_params = [$update_types];
                        foreach ($update_values as &$val) {
                            $update_params[] = &$val;
                        }
                        call_user_func_array([$update_stmt, 'bind_param'], $update_params);
                        $update_stmt->execute();
                        $update_stmt->close();
                    }
                }
            }
            
            // If we got here, the insert was successful
            $reading_result['success'] = true;
            $reading_result['reading_id'] = $reading_id;
            $reading_result['status'] = $status;
            $reading_result['ocr_processed'] = $ocrProcessed;
            $reading_result['ocr_reading'] = $ocrReading;
            $reading_result['client_name'] = trim(($client['firstname'] ?? '') . ' ' . ($client['lastname'] ?? ''));
            $reading_result['meter_code'] = $client['meter_code'] ?? null;
            
            $success_count++;
            error_log("Batch Upload: Successfully inserted reading ID $reading_id for client $client_id");
            
        } catch (Exception $e) {
            $reading_result['error'] = $e->getMessage();
            $failed_count++;
            error_log("Batch upload error for reading index $index: " . $e->getMessage());
        }
        
        $results[] = $reading_result;
    }
    
    // Clear any output buffer before sending response
    ob_end_clean();
    
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
    // Clear output buffer
    ob_end_clean();
    
    $errorMsg = $e->getMessage();
    $errorTrace = $e->getTraceAsString();
    error_log("Batch Upload API ERROR: $errorMsg");
    error_log("Batch Upload API STACK TRACE: $errorTrace");
    sendBatchResponse(false, 'Server error occurred: ' . $errorMsg, [
        'error_type' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], 500);
} catch (Error $e) {
    // Catch PHP 7+ errors (TypeError, ParseError, etc.)
    ob_end_clean();
    
    $errorMsg = $e->getMessage();
    $errorTrace = $e->getTraceAsString();
    error_log("Batch Upload API FATAL ERROR: $errorMsg");
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

