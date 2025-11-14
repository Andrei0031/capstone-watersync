<?php
// Suppress output to ensure clean JSON response
ob_start();

// Log errors to a file (but don't display them)
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/qr_error.log');
error_reporting(E_ALL);

// Start session first (may output headers)
session_start();

// Include QR Code library
include 'phpqrcode/qrlib.php';

// Clear any output buffer before sending JSON
ob_clean();
header('Content-Type: application/json');
if (!isset($_SESSION['admin_id'])) {
    ob_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    include 'db.php';
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection error',
        'debug' => $e->getMessage()
    ]);
    exit();
}

try {
    // Debug POST data
    error_log("POST data received: " . print_r($_POST, true));
    
    // Validate input parameters
    if (!isset($_POST['client_id'])) {
        throw new Exception('Missing client_id parameter');
    }

    $client_id = $_POST['client_id'] === 'all' ? null : intval($_POST['client_id']);
    $regenerate = isset($_POST['regenerate']) && $_POST['regenerate'] === 'true';
    
    // Create qr_codes directory if it doesn't exist
    $qr_dir = __DIR__ . '/qr_codes';
    if (!file_exists($qr_dir)) {
        if (!mkdir($qr_dir, 0755, true)) {
            $error = error_get_last();
            throw new Exception("Failed to create QR codes directory: " . ($error ? $error['message'] : 'Unknown error'));
        }
    }
    
    // Check directory permissions
    if (!is_writable($qr_dir)) {
        throw new Exception(sprintf(
            'QR codes directory is not writable. Path: %s, Current permissions: %o',
            $qr_dir,
            fileperms($qr_dir) & 0777
        ));
    }
    
    $generated_codes = [];
    
    if ($client_id !== null) {
        // Generate QR code for specific client
        $clients = getClientData($conn, $client_id);
        if (empty($clients)) {
            throw new Exception("Client not found or inactive (ID: $client_id)");
        }
    } else {
        // Generate QR codes for all active clients
        $clients = getAllActiveClients($conn);
        if (empty($clients)) {
            throw new Exception('No active clients found in the database');
        }
    }
    
    foreach ($clients as $client) {
        try {
            error_log("Processing client: " . print_r($client, true));
            $qr_result = generateClientQR($conn, $client, $qr_dir, $regenerate);
            if ($qr_result) {
                $generated_codes[] = $qr_result;
            }
        } catch (Exception $e) {
            error_log("Error generating QR for client {$client['id']}: " . $e->getMessage());
            // Continue with next client
            continue;
        }
    }
    
    if (empty($generated_codes)) {
        throw new Exception('No QR codes were generated successfully');
    }
    
    // Ensure clean JSON output
    ob_clean();
    echo json_encode([
        'success' => true,
        'generated_codes' => $generated_codes,
        'count' => count($generated_codes)
    ]);
    exit(); // Exit to prevent any additional output
    
} catch (Exception $e) {
    error_log("QR Generation Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    
    // Ensure clean JSON output even on error
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'debug_info' => [
            'post_data' => $_POST,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'current_dir' => getcwd(),
            'script_path' => __FILE__,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            'php_sapi' => php_sapi_name()
        ]
    ]);
    exit(); // Exit to prevent any additional output
}

// --- CLEANUP OLD qr_image_path VALUES (run once, then remove) ---
if (isset($_GET['cleanup_qr_paths']) && $_GET['cleanup_qr_paths'] === '1') {
    $sql = "SELECT id, qr_image_path FROM client_list WHERE qr_image_path IS NOT NULL AND qr_image_path != ''";
    $result = $conn->query($sql);
    $updated = 0;
    while ($row = $result->fetch_assoc()) {
        $id = $row['id'];
        $path = $row['qr_image_path'];
        $filename = basename($path);
        if ($filename !== $path) {
            $update = $conn->prepare("UPDATE client_list SET qr_image_path = ? WHERE id = ?");
            $update->bind_param("si", $filename, $id);
            if ($update->execute()) {
                $updated++;
            }
            $update->close();
        }
    }
    echo json_encode(['success' => true, 'updated' => $updated]);
    exit();
}

function getClientData($conn, $client_id) {
    $sql = "SELECT id, CONCAT(firstname, ' ', lastname) as name, meter_code,
                   qr_code_data, qr_image_path, qr_generated_at, qr_print_count, qr_last_printed
            FROM client_list 
            WHERE id = ? AND status = 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error);
    }
    $stmt->bind_param("i", $client_id);
    if (!$stmt->execute()) {
        throw new Exception("Failed to execute query: " . $stmt->error);
    }
    $result = $stmt->get_result();
    $clients = [];
    while ($row = $result->fetch_assoc()) {
        $clients[] = $row;
    }
    return $clients;
}

function getAllActiveClients($conn) {
    $sql = "SELECT id, CONCAT(firstname, ' ', lastname) as name, meter_code,
                   qr_code_data, qr_image_path, qr_generated_at, qr_print_count, qr_last_printed
            FROM client_list 
            WHERE status = 1 
            ORDER BY firstname, lastname";
    $result = $conn->query($sql);
    if (!$result) {
        throw new Exception("Database error: " . $conn->error);
    }
    $clients = [];
    while ($row = $result->fetch_assoc()) {
        $clients[] = $row;
    }
    return $clients;
}

function generateClientQR($conn, $client, $qr_dir, $regenerate = false) {
    try {
        $client_id = $client['id'];
        $meter_code = $client['meter_code'];
        $client_name = $client['name'];
        
        // Generate filename and ensure no spaces in directory or filename
        $safe_meter_code = preg_replace('/[^A-Za-z0-9\-_]/', '_', $meter_code);
        $safe_client_name = preg_replace('/[^A-Za-z0-9\-_]/', '_', $client_name);
        $timestamp = date('Ymd_His');
        $filename = "qr_meter_{$safe_meter_code}_client_{$client_id}_{$timestamp}.png";
        // File system path for saving inside qr_codes directory
        $file_path = $qr_dir . '/' . $filename;
        // Web accessible path (relative to project root)
        $web_path = 'qr_codes/' . $filename;
        
        // Check if QR code already exists and regenerate is false
        if (!empty($client['qr_image_path']) && !$regenerate) {
            // Normalize the path - handle both full paths and relative paths
            $existing_path = $client['qr_image_path'];
            // If it's a full Windows path, extract just the filename
            if (strpos($existing_path, '\\') !== false || strpos($existing_path, '/') !== false) {
                $existing_filename = basename($existing_path);
            } else {
                $existing_filename = $existing_path;
            }
            
            // Check if file exists in qr_codes directory
            $existing_file_path = $qr_dir . '/' . $existing_filename;
            
            if (file_exists($existing_file_path)) {
                return [
                    'id' => $client_id,
                    'name' => $client_name,
                    'meter_code' => $meter_code,
                    'qr_image_path' => 'qr_codes/' . $existing_filename,
                    'created_at' => $client['qr_generated_at'],
                    'print_count' => $client['qr_print_count'] ?? 0,
                    'status' => 'existing'
                ];
            }
        }
        
        // Generate QR code data
        $qr_data = json_encode([
            'meter_code' => $meter_code,
            'client_id' => $client_id,
            'client_name' => $client_name,
            'generated_date' => date('Y-m-d'),
            'system' => 'WaterSync',
            'timestamp' => time()
        ]);
        
        // Generate QR code using PHP QR Code
        // Higher error correction and size for better scanning from screens
        ob_start();
        QRcode::png($qr_data, $file_path, QR_ECLEVEL_H, 10, 2); // QR_ECLEVEL_H = High error correction, size 10 for larger QR codes
        ob_end_clean();
        
        if (!file_exists($file_path)) {
            throw new Exception("QR code file was not created at {$file_path}");
        }
        
        // Update database with the filename
        $update_sql = "UPDATE client_list SET 
                      qr_code_data = ?,
                      qr_image_path = ?, 
                      qr_generated_at = CURRENT_TIMESTAMP
                      WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        if (!$update_stmt) {
            throw new Exception("Failed to prepare update statement: " . $conn->error);
        }
        $update_stmt->bind_param("ssi", $qr_data, $web_path, $client_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update client record: " . $update_stmt->error);
        }
        
        return [
            'id' => $client_id,
            'name' => $client_name,
            'meter_code' => $meter_code,
            'qr_image_path' => $web_path,
            'created_at' => date('Y-m-d H:i:s'),
            'print_count' => 0,
            'status' => $regenerate ? 'updated' : 'created'
        ];
        
    } catch (Exception $e) {
        error_log("Error generating QR for client {$client_id}: " . $e->getMessage());
        // Clean up file if it was created but database update failed
        if (isset($file_path) && file_exists($file_path)) {
            unlink($file_path);
        }
        throw $e;
    }
}
?> 