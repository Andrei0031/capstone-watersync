<?php
// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Include database connection
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['success' => false, 'message' => 'Only GET method allowed']);
    exit();
}

$reading_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($reading_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid reading ID']);
    exit();
}

try {
    // Check database connection
    if (!isset($conn) || !$conn) {
        throw new Exception('Database connection not available');
    }
    
    // Get reading data with client info
    $stmt = $conn->prepare("SELECT pmr.*, cl.firstname, cl.lastname, cl.meter_code, cl.address
                           FROM pending_meter_readings pmr
                           JOIN client_list cl ON pmr.client_id = cl.id
                           WHERE pmr.id = ?");
    
    if (!$stmt) {
        throw new Exception('Failed to prepare statement: ' . $conn->error);
    }
    
    $stmt->bind_param("i", $reading_id);
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to execute query: ' . $stmt->error);
    }
    
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Reading not found with ID: ' . $reading_id]);
        exit();
    }
    
    $reading = $result->fetch_assoc();
    
    if (!$reading) {
        throw new Exception('Failed to fetch reading data');
    }
    
    // Use original image_path from database (don't resolve to absolute path for web display)
    $imagePath = $reading['image_path'] ?? '';
    
    // Get current reading value (prioritize verified_reading, then ocr_reading)
    $current_reading = null;
    if (isset($reading['verified_reading']) && $reading['verified_reading'] !== null) {
        $current_reading = floatval($reading['verified_reading']);
    } elseif (isset($reading['ocr_reading']) && $reading['ocr_reading'] !== null) {
        $current_reading = floatval($reading['ocr_reading']);
    } elseif (isset($reading['reading_value']) && $reading['reading_value'] !== null) {
        $current_reading = floatval($reading['reading_value']);
    } else {
        $current_reading = 0;
    }
    
    // Build response data
    $responseData = [
        'id' => intval($reading['id']),
        'client_name' => trim(($reading['firstname'] ?? '') . ' ' . ($reading['lastname'] ?? '')),
        'meter_code' => $reading['meter_code'] ?? '',
        'address' => $reading['address'] ?? '',
        'image_path' => $imagePath,
        'ocr_reading' => isset($reading['ocr_reading']) && $reading['ocr_reading'] !== null ? floatval($reading['ocr_reading']) : null,
        'verified_reading' => isset($reading['verified_reading']) && $reading['verified_reading'] !== null ? floatval($reading['verified_reading']) : null,
        'current_reading' => $current_reading,
        'extracted_text' => $reading['extracted_text'] ?? '',
        'admin_notes' => $reading['admin_notes'] ?? '',
        'status' => $reading['status'] ?? 'pending',
        'upload_date' => $reading['upload_date'] ?? null,
        'processed_at' => $reading['processed_at'] ?? null
    ];
    
    echo json_encode([
        'success' => true,
        'data' => $responseData
    ], JSON_UNESCAPED_SLASHES);
    
    $stmt->close();
    
} catch (Exception $e) {
    error_log("Error in get_meter_reading.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching reading: ' . $e->getMessage(),
        'debug' => [
            'reading_id' => $reading_id,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ]);
}

