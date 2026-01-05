<?php
require_once __DIR__ . '/timezone_helper.php';
watersync_force_timezone();

session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: adminlogin.php");
    exit();
}

// Enable error display for debugging (suppress deprecation warnings from vendor libraries)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL & ~E_DEPRECATED); // Suppress deprecation warnings (PHP 8.2+)

// Load vendor autoload if it exists (for Tesseract OCR)
$tesseract_available = false;
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    try {
        // Temporarily suppress deprecation warnings from vendor code
        $oldErrorReporting = error_reporting();
        error_reporting($oldErrorReporting & ~E_DEPRECATED);
        
        require_once __DIR__ . '/vendor/autoload.php';
        
        if (class_exists('thiagoalessio\TesseractOCR\TesseractOCR')) {
            $tesseract_available = true;
        }
        
        // Restore error reporting
        error_reporting($oldErrorReporting);
    } catch (Exception $e) {
        error_log("Failed to load Tesseract: " . $e->getMessage());
    }
}
define('TESSERACT_AVAILABLE', $tesseract_available);

include 'db.php';
if (isset($conn)) {
    watersync_force_timezone($conn);
}
include 'simple_notifications.php';
include 'automated_bill_creation.php';

/**
 * Check if Tesseract OCR is available on the system
 */
function isTesseractAvailable() {
    // First check if the PHP class is available
    if (!defined('TESSERACT_AVAILABLE') || !TESSERACT_AVAILABLE) {
        return false;
    }
    
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

// Calculate statistics
$total_readings = 0;
$pending_count = 0;
$processed_count = 0;
$failed_count = 0;

// Get counts for each status
$stats_sql = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'processed' THEN 1 ELSE 0 END) as processed,
    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
    FROM pending_meter_readings";
$stats_result = $conn->query($stats_sql);
if ($stats_result && $row = $stats_result->fetch_assoc()) {
    $total_readings = $row['total'];
    $pending_count = $row['pending'];
    $processed_count = $row['processed'];
    $failed_count = $row['failed'];
}

// Calculate success rate
$success_rate = $total_readings > 0 ? round(($processed_count / $total_readings) * 100) : 0;

// Get readings uploaded today
$today_sql = "SELECT COUNT(*) as today_count FROM pending_meter_readings 
              WHERE DATE(upload_date) = CURRENT_DATE()";
$today_result = $conn->query($today_sql);
$today_count = 0;
if ($today_result && $row = $today_result->fetch_assoc()) {
    $today_count = $row['today_count'];
}

// Process OCR for selected readings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_selected'])) {
    // Enable error reporting for debugging (remove in production)
    error_reporting(E_ALL);
    ini_set('display_errors', 0); // Don't display, but log
    ini_set('log_errors', 1);
    
    // Ensure all required columns exist
    $columns_to_check = [
        'billing_cycle_id' => "ALTER TABLE pending_meter_readings ADD COLUMN billing_cycle_id INT NULL AFTER client_id",
        'reading_date' => "ALTER TABLE pending_meter_readings ADD COLUMN reading_date DATE NULL AFTER mobile_upload_id",
        'upload_date' => "ALTER TABLE pending_meter_readings ADD COLUMN upload_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER reading_date",
        'ocr_reading' => "ALTER TABLE pending_meter_readings ADD COLUMN ocr_reading DECIMAL(10,2) NULL AFTER upload_date",
        'extracted_text' => "ALTER TABLE pending_meter_readings ADD COLUMN extracted_text TEXT NULL AFTER ocr_reading",
        'verified_reading' => "ALTER TABLE pending_meter_readings ADD COLUMN verified_reading DECIMAL(10,2) NULL AFTER extracted_text",
        'admin_notes' => "ALTER TABLE pending_meter_readings ADD COLUMN admin_notes TEXT NULL",
        'processed_at' => "ALTER TABLE pending_meter_readings ADD COLUMN processed_at TIMESTAMP NULL AFTER admin_notes"
    ];
    
    foreach ($columns_to_check as $column => $alter_sql) {
        $check_col = $conn->query("SHOW COLUMNS FROM pending_meter_readings LIKE '$column'");
        if ($check_col->num_rows === 0) {
            // For admin_notes, add after verified_reading if it exists, otherwise just add at end
            if ($column === 'admin_notes') {
                $check_verified = $conn->query("SHOW COLUMNS FROM pending_meter_readings LIKE 'verified_reading'");
                if ($check_verified->num_rows > 0) {
                    $conn->query("ALTER TABLE pending_meter_readings ADD COLUMN admin_notes TEXT NULL AFTER verified_reading");
                } else {
                    $conn->query("ALTER TABLE pending_meter_readings ADD COLUMN admin_notes TEXT NULL");
                }
            } elseif ($column === 'processed_at') {
                // For processed_at, add after admin_notes if it exists, otherwise just add at end
                $check_admin_notes = $conn->query("SHOW COLUMNS FROM pending_meter_readings LIKE 'admin_notes'");
                if ($check_admin_notes->num_rows > 0) {
                    $conn->query("ALTER TABLE pending_meter_readings ADD COLUMN processed_at TIMESTAMP NULL AFTER admin_notes");
                } else {
                    $conn->query("ALTER TABLE pending_meter_readings ADD COLUMN processed_at TIMESTAMP NULL");
                }
            } else {
                $conn->query($alter_sql);
            }
        }
    }
    
    // Update status enum if needed
    $conn->query("ALTER TABLE pending_meter_readings MODIFY COLUMN status ENUM('pending', 'processed', 'failed') DEFAULT 'pending'");
    
    // Include Roboflow service and OCR functions
    require_once __DIR__ . '/api/roboflow_service.php';
    require_once __DIR__ . '/api/ocr_functions.php';
    
    $selected_ids = $_POST['selected_readings'] ?? [];
    $processed_count = 0;
    $failed_count = 0;
    
    // Check if any readings were selected
    if (empty($selected_ids)) {
        header("Location: pending_readings.php?processed=true&status=warning&message=" . urlencode("No readings selected. Please select at least one reading to process."));
        exit();
    }
    
    foreach ($selected_ids as $reading_id) {
        // Get reading details
        $stmt = $conn->prepare("SELECT * FROM pending_meter_readings WHERE id = ? AND status = 'pending'");
        $stmt->bind_param("i", $reading_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $reading = $result->fetch_assoc();
        
        if ($reading) {
            try {
                // Get full image path - handle both relative and absolute paths
                $storedPath = $reading['image_path'];
                
                // If path doesn't start with / or C:, it's relative to the project root
                // upload_reading.php stores path as 'uploads/meter_readings/filename.jpg'
                // pending_readings.php is in project root, so path should be relative to __DIR__
                if (strpos($storedPath, '/') !== 0 && strpos($storedPath, '\\') !== 0 && strpos($storedPath, 'C:') !== 0) {
                    // Relative path - try multiple possible locations
                    $possiblePaths = [
                        __DIR__ . '/' . $storedPath,  // From project root: C:\xampp\htdocs\CAPSTONE\uploads\meter_readings\...
                        realpath(__DIR__ . '/' . $storedPath),  // Resolved absolute path
                        $storedPath,  // Direct path (if already correct)
                    ];
                    
                    // Also try if stored path already includes ../ (legacy)
                    if (strpos($storedPath, '../') === false) {
                        $possiblePaths[] = __DIR__ . '/../' . $storedPath;
                    }
                } else {
                    // Absolute path
                    $possiblePaths = [$storedPath, realpath($storedPath)];
                }
                
                $imagePath = null;
                foreach ($possiblePaths as $path) {
                    if (file_exists($path)) {
                        $imagePath = $path;
                        break;
                    }
                }
                
                if (!$imagePath || !file_exists($imagePath)) {
                    throw new Exception('Image file not found. Tried: ' . implode(', ', $possiblePaths) . '. Stored path: ' . $storedPath);
                }
                
                // Step 1: Use Roboflow to detect and crop meter region (OPTIONAL - can work without it)
                $croppedImagePath = $imagePath;
                $roboflowUsed = false;
                $roboflowError = null;
                
                try {
                    if (function_exists('detectAndCropMeterWithRoboflow')) {
                        error_log("Attempting Roboflow detection for reading ID $reading_id, image: $imagePath");
                        $croppedImagePath = detectAndCropMeterWithRoboflow($imagePath);
                        
                        if ($croppedImagePath !== $imagePath && $croppedImagePath !== null && file_exists($croppedImagePath)) {
                            $roboflowUsed = true;
                            error_log("✓ Roboflow SUCCESS: Cropped image for reading ID $reading_id: $croppedImagePath");
                        } else {
                            // Roboflow didn't crop or returned original path - this is OK, we'll use full image
                            $roboflowError = "Roboflow returned original image path (no crop detected) - will process full image";
                            error_log("⚠ Roboflow: $roboflowError for reading ID $reading_id");
                            $croppedImagePath = $imagePath;
                        }
                    } else {
                        $roboflowError = "detectAndCropMeterWithRoboflow function not found - will process full image";
                        error_log("⚠ Roboflow: $roboflowError - continuing without Roboflow");
                        $croppedImagePath = $imagePath;
                    }
                } catch (Exception $e) {
                    // Roboflow failed, continue with original image - this is OK
                    $roboflowError = "Roboflow exception: " . $e->getMessage() . " - will process full image";
                    error_log("⚠ Roboflow FAILED for reading ID $reading_id: " . $roboflowError);
                    $croppedImagePath = $imagePath;
                }
                
                // Step 2: Process OCR with Roboflow YOLOv8 digit detection on cropped image
                if (!function_exists('processImageWithRoboflowDigits')) {
                    throw new Exception('Roboflow OCR function not available. Make sure ocr_functions.php and roboflow_service.php are included.');
                }
                
                // Log which image we're processing (original or cropped)
                if ($roboflowUsed) {
                    error_log("Processing CROPPED image with Roboflow digit detection (Roboflow detected meter region): $croppedImagePath");
                } else {
                    error_log("Processing FULL image with Roboflow digit detection (Roboflow not used or failed): $croppedImagePath");
                    if ($roboflowError) {
                        error_log("Roboflow meter detection status: $roboflowError");
                    }
                }
                
                // Step 2a: Try Roboflow digit detection first (preferred method)
                $ocrProcessed = false;
                $ocrReading = null;
                $extractedText = '';
                $ocrError = null;
                $imageUsed = $croppedImagePath;
                
                if (function_exists('processImageWithRoboflowDigits')) {
                    // Try cropped image first (if different from original)
                    if ($croppedImagePath !== $imagePath && file_exists($croppedImagePath)) {
                        error_log("Attempting OCR on CROPPED image: $croppedImagePath");
                        $ocrResult = processImageWithRoboflowDigits($croppedImagePath);
                        if ($ocrResult['success'] && !empty($ocrResult['meter_reading'])) {
                            $ocrReading = $ocrResult['meter_reading'];
                            $extractedText = $ocrResult['extracted_text'] ?? '';
                            $ocrProcessed = true;
                            $imageUsed = $croppedImagePath;
                            error_log("✓ OCR SUCCESS (Roboflow on CROPPED): Reading ID $reading_id processed with value: $ocrReading");
                        } else {
                            $ocrError = $ocrResult['error'] ?? 'Roboflow OCR failed on cropped image';
                            error_log("⚠ Roboflow OCR failed on CROPPED image for reading ID $reading_id: $ocrError");
                            error_log("   Will try ORIGINAL image as fallback...");
                        }
                    }
                    
                    // If cropped image failed or doesn't exist, try original image
                    if (!$ocrProcessed && file_exists($imagePath)) {
                        error_log("Attempting OCR on ORIGINAL (uncropped) image: $imagePath");
                        $ocrResult = processImageWithRoboflowDigits($imagePath);
                        if ($ocrResult['success'] && !empty($ocrResult['meter_reading'])) {
                            $ocrReading = $ocrResult['meter_reading'];
                            $extractedText = $ocrResult['extracted_text'] ?? '';
                            $ocrProcessed = true;
                            $imageUsed = $imagePath;
                            error_log("✓ OCR SUCCESS (Roboflow on ORIGINAL): Reading ID $reading_id processed with value: $ocrReading");
                        } else {
                            $ocrError = $ocrResult['error'] ?? 'Roboflow OCR failed on original image';
                            error_log("⚠ Roboflow OCR failed on ORIGINAL image for reading ID $reading_id: $ocrError");
                        }
                    }
                }
                
                // Step 2b: If Roboflow failed, throw exception (no fallback - Roboflow YOLOv8 only)
                if (!$ocrProcessed) {
                    $errorMsg = 'Roboflow YOLOv8 OCR processing failed. ';
                    if ($ocrError) {
                        $errorMsg .= $ocrError;
                    } else {
                        $errorMsg .= 'Roboflow digit detection failed to process the image.';
                    }
                    $errorMsg .= ' Image path: ' . $croppedImagePath;
                    $errorMsg .= ' Please check if Roboflow model version 7 is deployed and accessible.';
                    throw new Exception($errorMsg);
                }
                
                // Clean up cropped image if it was created by Roboflow
                if ($roboflowUsed && $croppedImagePath !== $imagePath && file_exists($croppedImagePath)) {
                    // Optionally keep cropped image for debugging, or delete it
                    // unlink($croppedImagePath); // Uncomment to delete cropped images
                }
                
                // Update reading status (force Asia/Manila timestamp via PHP)
                $processedAt = date('Y-m-d H:i:s');
                $update = $conn->prepare("UPDATE pending_meter_readings SET 
                    status = 'processed',
                    ocr_reading = ?,
                    extracted_text = ?,
                    processed_at = ?
                    WHERE id = ?");
                $update->bind_param("dssi", $ocrReading, $extractedText, $processedAt, $reading_id);
                
                if (!$update->execute()) {
                    throw new Exception('Failed to update database: ' . $conn->error);
                }
                
                $processed_count++;
                
            } catch (Exception $e) {
                // Update with error
                $error_msg = $e->getMessage();
                $failedProcessedAt = date('Y-m-d H:i:s');
                $update = $conn->prepare("UPDATE pending_meter_readings SET 
                    status = 'failed',
                    admin_notes = ?,
                    processed_at = ?
                    WHERE id = ?");
                $update->bind_param("ssi", $error_msg, $failedProcessedAt, $reading_id);
                $update->execute();
                
                $failed_count++;
                error_log("OCR processing failed for reading ID $reading_id: " . $error_msg);
            }
        }
    }
    
    // Set success message
    if ($processed_count > 0 && $failed_count == 0) {
        $message = "Successfully processed $processed_count reading(s)";
        $status = 'success';
    } elseif ($processed_count > 0 && $failed_count > 0) {
        $message = "Processed: $processed_count, Failed: $failed_count";
        $status = 'partial';
    } elseif ($failed_count > 0) {
        $message = "Failed to process $failed_count reading(s). Check error logs for details.";
        $status = 'error';
    } else {
        $message = "No readings were processed. Please select at least one pending reading.";
        $status = 'warning';
    }
    
    // Redirect with message
    header("Location: pending_readings.php?processed=true&status=" . urlencode($status) . "&message=" . urlencode($message));
    exit();
}

// Create new bills for processed readings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_bills'])) {
    $selected_ids = $_POST['selected_processed'] ?? [];
    
    foreach ($selected_ids as $reading_id) {
        $stmt = $conn->prepare("SELECT pmr.*, cl.category_id, bc.due_date AS cycle_due_date 
            FROM pending_meter_readings pmr 
            JOIN client_list cl ON pmr.client_id = cl.id 
            LEFT JOIN billing_cycles bc ON pmr.billing_cycle_id = bc.id
            WHERE pmr.id = ? AND pmr.status = 'processed'");
        $stmt->bind_param("i", $reading_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $reading = $result->fetch_assoc();
        
        if ($reading) {
            // Get the actual reading value (prioritize verified_reading, then ocr_reading, then reading_value)
            $current_reading = null;
            if (isset($reading['verified_reading']) && $reading['verified_reading'] !== null) {
                $current_reading = floatval($reading['verified_reading']);
            } elseif (isset($reading['ocr_reading']) && $reading['ocr_reading'] !== null) {
                $current_reading = floatval($reading['ocr_reading']);
            } elseif (isset($reading['reading_value']) && $reading['reading_value'] !== null) {
                $current_reading = floatval($reading['reading_value']);
            }
            
            // Validate reading value
            if ($current_reading === null || $current_reading < 0) {
                error_log("Skipping reading ID {$reading_id}: Invalid reading value");
                continue; // Skip this reading if no valid reading value
            }
            
            // Get previous reading
            $prev_stmt = $conn->prepare("SELECT reading FROM billing_list 
                WHERE client_id = ? ORDER BY reading_date DESC LIMIT 1");
            $prev_stmt->bind_param("i", $reading['client_id']);
            $prev_stmt->execute();
            $prev_result = $prev_stmt->get_result();
            $previous = $prev_result->fetch_assoc()['reading'] ?? 0;
            
            // Determine due date from billing cycle, fallback to +30 days if missing
            if (!empty($reading['cycle_due_date'])) {
                $due_date = $reading['cycle_due_date'];
            } elseif (!empty($active_cycle['due_date'])) {
                $due_date = $active_cycle['due_date'];
            } else {
                $due_date = date('Y-m-d', strtotime('+30 days'));
            }
            
            // Get rates for the client's category
            $rate_stmt = $conn->prepare("SELECT rate, excess_rate FROM category_rates WHERE category_id = ?");
            $rate_stmt->bind_param("i", $reading['category_id']);
            $rate_stmt->execute();
            $rate_result = $rate_stmt->get_result();
            $rate_data = $rate_result->fetch_assoc();
            
            if (!$rate_data) {
                error_log("Skipping reading ID {$reading_id}: No rate data found for category_id {$reading['category_id']}");
                continue; // Skip if no rate data
            }
            
            // Calculate total
            $consumption = $current_reading - $previous;
            if ($consumption <= 6) {
                $total = $rate_data['rate'];
            } else {
                $excess = $consumption - 6;
                $total = $rate_data['rate'] + ($excess * $rate_data['excess_rate']);
            }
            
            // Create new bill
            $bill_stmt = $conn->prepare("INSERT INTO billing_list 
                (client_id, reading_date, due_date, reading, previous, total, status) 
                VALUES (?, CURRENT_DATE(), ?, ?, ?, ?, 0)");
            $bill_stmt->bind_param("isddd", 
                $reading['client_id'],
                $due_date,
                $current_reading,
                $previous,
                $total
            );
            
            if ($bill_stmt->execute()) {
                $bill_id = $conn->insert_id; // Get the newly created bill ID
                
                // Send notification to registered customer
                try {
                    $notification_result = sendBillingNotification($reading['client_id'], $bill_id, 'bill_approved');
                    if ($notification_result['success']) {
                        error_log("Notification sent for bill $bill_id (from meter reading): " . json_encode($notification_result['results']));
                    } else {
                        error_log("Notification failed for bill $bill_id: " . ($notification_result['error'] ?? 'Unknown error'));
                    }
                } catch (Exception $e) {
                    error_log("Notification system error for bill $bill_id: " . $e->getMessage());
                    // Don't fail bill creation if notifications fail
                }
                
                // Mark reading as processed
                $update = $conn->prepare("UPDATE pending_meter_readings SET 
                    status = 'processed',
                    processed_date = CURRENT_TIMESTAMP
                    WHERE id = ?");
                $update->bind_param("i", $reading_id);
                $update->execute();
            }
        }
    }
    
    header("Location: pending_readings.php?bills_created=true");
    exit();
}

// Set longer timeout for OCR processing
set_time_limit(600); // 10 minutes for batch processing (Roboflow API can be slow)
ini_set('max_execution_time', 600);

// Automated bill creation for all pending readings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auto_create_bills'])) {
    $result = processOCRReadingsAutomatically($conn);
    
    if ($result['success']) {
        $message = "✅ Automated bill creation completed! Processed {$result['processed_count']} readings for cycle: {$result['cycle_name']}";
        if (!empty($result['errors'])) {
            $message .= "\n❌ Errors: " . implode(", ", $result['errors']);
        }
        header("Location: pending_readings.php?auto_bills_created=true&count=" . $result['processed_count']);
    } else {
        header("Location: pending_readings.php?auto_bills_error=" . urlencode($result['error']));
    }
    exit();
}

// Get current active billing cycle
$active_cycle_sql = "SELECT * FROM billing_cycles WHERE status = 'active' LIMIT 1";
$active_cycle_result = $conn->query($active_cycle_sql);
$active_cycle = $active_cycle_result ? $active_cycle_result->fetch_assoc() : null;

// Diagnostic: Check all readings in database (for debugging)
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    $debug_sql = "SELECT id, client_id, status, upload_date, image_path, mobile_upload_id 
                  FROM pending_meter_readings 
                  ORDER BY upload_date DESC 
                  LIMIT 20";
    $debug_result = $conn->query($debug_sql);
    error_log("DEBUG: Recent readings in database:");
    if ($debug_result) {
        while ($debug_row = $debug_result->fetch_assoc()) {
            error_log("  - ID: {$debug_row['id']}, Client: {$debug_row['client_id']}, Status: {$debug_row['status']}, Upload: {$debug_row['upload_date']}, Image: {$debug_row['image_path']}");
        }
    }
}

// Fetch pending readings with billing cycle info
// Use LEFT JOIN to show readings even if client is missing/inactive
$pending_sql = "SELECT pmr.*, cl.firstname, cl.lastname, cl.meter_code, cl.status as client_status,
                bc.cycle_name, bc.due_date as cycle_due_date
    FROM pending_meter_readings pmr 
    LEFT JOIN client_list cl ON pmr.client_id = cl.id 
    LEFT JOIN billing_cycles bc ON pmr.billing_cycle_id = bc.id
    WHERE pmr.status = 'pending'
    ORDER BY pmr.upload_date DESC";
$pending_result = $conn->query($pending_sql);
if (!$pending_result) {
    error_log("Error fetching pending readings: " . $conn->error);
    $pending_result = $conn->query("SELECT * FROM pending_meter_readings WHERE status = 'pending' LIMIT 0");
}

// Fetch processed readings with billing cycle info
// Prioritize verified_reading (manually corrected) over ocr_reading
// Use LEFT JOIN to show readings even if client is missing/inactive
$processed_sql = "SELECT pmr.*, cl.firstname, cl.lastname, cl.meter_code, cl.status as client_status,
                  bc.cycle_name, bc.due_date as cycle_due_date,
                  COALESCE(pmr.verified_reading, pmr.ocr_reading, pmr.reading_value, 0) as reading_value
    FROM pending_meter_readings pmr 
    LEFT JOIN client_list cl ON pmr.client_id = cl.id 
    LEFT JOIN billing_cycles bc ON pmr.billing_cycle_id = bc.id
    WHERE pmr.status = 'processed'
    ORDER BY pmr.processed_at DESC, pmr.processed_date DESC";
$processed_result = $conn->query($processed_sql);
if (!$processed_result) {
    error_log("Error fetching processed readings: " . $conn->error);
    $processed_result = $conn->query("SELECT * FROM pending_meter_readings WHERE status = 'processed' LIMIT 0");
}

// Fetch failed readings with billing cycle info
// Use LEFT JOIN to show readings even if client is missing/inactive
$failed_sql = "SELECT pmr.*, cl.firstname, cl.lastname, cl.meter_code, cl.status as client_status,
               bc.cycle_name, bc.due_date as cycle_due_date,
               pmr.admin_notes as error_message
    FROM pending_meter_readings pmr 
    LEFT JOIN client_list cl ON pmr.client_id = cl.id 
    LEFT JOIN billing_cycles bc ON pmr.billing_cycle_id = bc.id
    WHERE pmr.status = 'failed'
    ORDER BY pmr.processed_at DESC";
$failed_result = $conn->query($failed_sql);
if (!$failed_result) {
    error_log("Error fetching failed readings: " . $conn->error);
    $failed_result = $conn->query("SELECT * FROM pending_meter_readings WHERE status = 'failed' LIMIT 0");
}

?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meter Readings - Water Billing System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Theme variables */
        :root[data-theme="light"] {
            --bg-color: #f8f9fa;
            --sidebar-bg: #fff;
            --text-color: #333;
            --card-bg: #fff;
            --border-color: #dee2e6;
            --hover-bg: #f0f2f5;
            --hover-text: #007bff;
            --muted-text: #6c757d;
            --card-text: #333;
            --table-header-bg: #f8f9fa;
            --table-header-text: #333;
            --table-cell-text: #333;
            --table-bg: #fff;
            --table-hover-bg: #f8f9fa;
            --modal-bg: #fff;
            --input-bg: #fff;
            --input-border: #dee2e6;
            --input-text: #333;
            --input-placeholder: #6c757d;
            --nav-tab-active: #007bff;
        }

        :root[data-theme="dark"] {
            --bg-color: #1a1d21;
            --sidebar-bg: #242529;
            --text-color: #e4e6eb;
            --card-bg: #2d2f34;
            --border-color: #393b40;
            --hover-bg: #393b40;
            --hover-text: #4e9eff;
            --muted-text: #a0a0a0;
            --card-text: #e4e6eb;
            --table-header-bg: #242529;
            --table-header-text: #e4e6eb;
            --table-cell-text: #e4e6eb;
            --table-bg: #2d2f34;
            --table-hover-bg: #32353a;
            --modal-bg: #2d2f34;
            --input-bg: #242529;
            --input-border: #393b40;
            --input-text: #e4e6eb;
            --input-placeholder: #6c757d;
            --nav-tab-active: #4e9eff;
        }

        body {
            font-family: 'Open Sans', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            transition: background-color 0.3s, color 0.3s;
            min-height: 100vh;
            margin: 0;
        }

        .sidebar {
            height: 100vh;
            background-color: var(--sidebar-bg);
            border-right: 1px solid var(--border-color);
            padding-top: 20px;
            position: fixed;
            width: 250px;
            display: flex;
            flex-direction: column;
        }

        .sidebar-header {
            padding: 20px;
            margin-bottom: 20px;
            text-align: center;
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            margin: 0 20px 20px;
            border-radius: 12px;
            transition: background-color 0.3s, border-color 0.3s;
            overflow: hidden;
        }

        .sidebar-header img {
            max-width: 100%;
            height: auto;
            object-fit: contain;
            filter: none !important;
        }

        /* Prevent logo from being affected by dark mode filters */
        html[data-theme="dark"] .sidebar-header img,
        [data-theme="dark"] .sidebar-header img {
            filter: none !important;
            opacity: 1 !important;
            mix-blend-mode: normal !important;
        }

        /* Keep sidebar-header background light in dark mode for logo visibility */
        html[data-theme="dark"] .sidebar-header,
        [data-theme="dark"] .sidebar-header {
            background-color: #fff !important;
        }

        .nav-content {
            flex: 1;
            overflow-y: auto;
        }

        .sidebar-footer {
            padding: 20px;
            border-top: 1px solid var(--border-color);
            margin-top: auto;
        }

        .sidebar a {
            padding: 12px 20px;
            display: flex;
            align-items: center;
            color: var(--text-color);
            font-weight: 600;
            text-decoration: none;
            border-radius: 12px;
            margin: 0 8px 8px;
            transition: all 0.3s ease;
        }

        .sidebar a i {
            min-width: 24px;
            margin-right: 10px;
            text-align: center;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background-color: var(--hover-bg);
            color: var(--hover-text);
        }

        .main-content {
            margin-left: 250px;
            padding: 30px;
        }

        .theme-switch-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px;
            border-radius: 10px;
            margin: 10px 20px;
            background-color: var(--hover-bg);
        }

        .theme-switch-wrapper i {
            margin: 0 5px;
            color: var(--text-color);
        }

        .theme-switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
            margin: 0 10px;
        }

        .theme-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: #2196F3;
        }

        input:checked + .slider:before {
            transform: translateX(26px);
        }

        /* Additional styles for tables, cards, and other elements */
        .card {
            background-color: var(--card-bg);
            border-color: var(--border-color);
            color: var(--card-text);
        }

        .table {
            color: var(--table-cell-text);
            background-color: var(--table-bg);
        }

        .table thead th {
            background-color: var(--table-header-bg);
            color: var(--table-header-text);
            border-bottom: 2px solid var(--border-color);
        }

        .table td, .table th {
            background-color: var(--table-bg);
            border-color: var(--border-color);
            color: var(--table-cell-text);
        }

        .table-hover tbody tr:hover {
            background-color: var(--table-hover-bg);
        }

        .modal-content {
            background-color: var(--modal-bg);
            color: var(--text-color);
        }

        .form-control {
            background-color: var(--input-bg);
            border-color: var(--input-border);
            color: var(--input-text);
        }

        .form-control::placeholder {
            color: var(--input-placeholder);
        }

        .nav-tabs .nav-link.active {
            background-color: var(--nav-tab-active);
            color: #fff;
        }

        /* Enhanced Card Styles */
        .card-soft {
            border-radius: 15px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            border: none;
            background-color: var(--card-bg);
            color: var(--text-color);
            margin-bottom: 20px;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .card-soft:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15);
        }

        /* Stats Cards */
        .stat-card {
            padding: 25px;
            border-radius: 15px;
            color: white;
            height: 100%;
            border: none;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(45deg, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 100%);
            z-index: 1;
        }

        .stat-icon {
            font-size: 2.5rem;
            opacity: 0.8;
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            z-index: 0;
            opacity: 0.2;
        }

        /* Status Badges */
        .status-badge {
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .status-pending {
            background-color: #ffc10715;
            color: #ffc107;
            border: 1px solid #ffc10740;
        }

        .status-processed {
            background-color: #19875415;
            color: #198754;
            border: 1px solid #19875440;
        }

        .status-failed {
            background-color: #dc354515;
            color: #dc3545;
            border: 1px solid #dc354540;
        }

        /* Dark mode styles for status badges */
        html[data-theme="dark"] .status-pending,
        [data-theme="dark"] .status-pending {
            background-color: #ffc10730 !important;
            color: #ffc107 !important;
            border-color: #ffc10760 !important;
        }

        html[data-theme="dark"] .status-failed,
        [data-theme="dark"] .status-failed {
            background-color: #dc354530 !important;
            color: #ff6b6b !important;
            border-color: #dc354560 !important;
        }

        /* Dark mode styles for text-muted in Automated Bill Creation card */
        html[data-theme="dark"] .card-soft .text-muted,
        [data-theme="dark"] .card-soft .text-muted {
            color: var(--muted-text) !important;
        }

        html[data-theme="dark"] .card-soft p.text-muted,
        [data-theme="dark"] .card-soft p.text-muted {
            color: #b0b0b0 !important;
        }

        /* General dark mode fix for all text-muted elements */
        html[data-theme="dark"] .text-muted,
        [data-theme="dark"] .text-muted {
            color: var(--muted-text) !important;
        }

        html[data-theme="dark"] small.text-muted,
        [data-theme="dark"] small.text-muted {
            color: #b0b0b0 !important;
        }

        /* Table Enhancements */
        .table {
            margin-bottom: 0;
            vertical-align: middle;
        }

        .table thead th {
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
            padding: 1rem;
            background-color: var(--table-header-bg);
            border-bottom: 2px solid var(--border-color);
        }

        .table td {
            padding: 1rem;
            vertical-align: middle;
        }

        /* Avatar Styles */
        .avatar-sm {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            margin-right: 12px;
            font-size: 0.9rem;
        }

        /* Meter Image */
        .meter-image {
            max-width: 120px;
            cursor: pointer;
            border-radius: 8px;
            transition: transform 0.2s;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
        }

        .meter-image:hover {
            transform: scale(1.05);
        }

        /* Button Enhancements */
        .btn {
            padding: 0.5rem 1rem;
            font-weight: 500;
            border-radius: 8px;
            transition: all 0.2s;
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }

        .btn i {
            margin-right: 0.5rem;
        }

        .btn-primary {
            background: linear-gradient(45deg, #4e73df 0%, #224abe 100%);
            border: none;
        }

        .btn-primary:hover {
            background: linear-gradient(45deg, #224abe 0%, #1a3997 100%);
            transform: translateY(-1px);
        }

        .btn-outline-primary {
            border-color: var(--hover-text);
            color: var(--hover-text);
        }

        .btn-outline-primary:hover {
            background-color: var(--hover-text);
            color: white;
            transform: translateY(-1px);
        }

        /* Tab Navigation */
        .nav-tabs {
            border-bottom: none;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }

        .nav-tabs .nav-link {
            border: none;
            padding: 0.75rem 1.25rem;
            border-radius: 10px;
            font-weight: 500;
            color: var(--text-color);
            transition: all 0.2s;
        }

        .nav-tabs .nav-link:hover {
            background-color: var(--hover-bg);
            transform: translateY(-1px);
        }

        .nav-tabs .nav-link.active {
            background: linear-gradient(45deg, var(--nav-tab-active) 0%, #224abe 100%);
            color: white;
            transform: translateY(-1px);
        }

        .nav-tabs .nav-link .badge {
            padding: 0.35em 0.65em;
            font-size: 0.75em;
        }

        /* Modal Enhancements */
        .modal-content {
            border-radius: 15px;
            border: none;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .modal-header {
            border-bottom: 1px solid var(--border-color);
            padding: 1.25rem;
        }

        .modal-footer {
            border-top: 1px solid var(--border-color);
            padding: 1.25rem;
        }

        .modal-body {
            padding: 1.5rem;
        }

        /* Form Controls */
        .form-control {
            padding: 0.75rem 1rem;
            border-radius: 8px;
            transition: all 0.2s;
        }

        .form-control:focus {
            border-color: var(--hover-text);
            box-shadow: 0 0 0 0.2rem rgba(78, 115, 223, 0.1);
        }

        /* Responsive Sidebar and Hamburger Toggle */
        @media (max-width: 991.98px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: 0;
                height: 100vh;
                width: 250px;
                background-color: var(--sidebar-bg);
                border-right: 1px solid var(--border-color);
                transform: translateX(-250px);
                transition: transform 0.3s ease;
                z-index: 1050;
                display: block;
            }
            .sidebar.open {
                transform: translateX(0);
            }
            .sidebar-footer {
                position: absolute;
                bottom: 0;
                width: 100%;
            }
            .main-content {
                margin-left: 0 !important;
                padding: 20px 10px;
                transition: margin-left 0.3s ease;
            }
            #sidebarToggle {
                display: block;
                position: fixed;
                top: 15px;
                left: 15px;
                z-index: 1100;
                background-color: var(--sidebar-bg);
                border: none;
                padding: 8px 12px;
                border-radius: 5px;
                box-shadow: 0 0 5px rgba(0,0,0,0.2);
                cursor: pointer;
            }
        }
        @media (min-width: 992px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: 0;
                height: 100vh;
                width: 250px;
                background-color: var(--sidebar-bg);
                border-right: 1px solid var(--border-color);
                display: flex;
                flex-direction: column;
                transform: none !important;
            }
            .main-content {
                margin-left: 250px;
                padding: 30px;
            }
            #sidebarToggle {
                display: none;
            }
        }

        /* Action buttons improvements */
        .table td .btn-group,
        .table td .btn {
            margin: 0;
        }

        .table td .btn-group {
            display: inline-flex;
            gap: 0;
        }

        .table td .btn-sm {
            padding: 8px 12px !important;
            margin: 0 4px 0 0 !important;
            border-radius: 6px !important;
            min-width: 40px;
            height: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            transition: all 0.2s ease;
            border-width: 2px;
        }

        .table td .btn-sm:last-child {
            margin-right: 0 !important;
        }

        .table td .btn-sm i {
            font-size: 1rem;
        }

        .table td .btn-sm:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        .table td .btn-outline-primary {
            border-color: #0d6efd;
            color: #0d6efd;
        }

        .table td .btn-outline-primary:hover {
            background-color: #0d6efd;
            color: #fff;
        }

        .table td .btn-outline-warning {
            border-color: #ffc107;
            color: #ffc107;
        }

        .table td .btn-outline-warning:hover {
            background-color: #ffc107;
            color: #000;
        }

        .table td .btn-outline-danger {
            border-color: #dc3545 !important;
            color: #dc3545 !important;
        }

        .table td .btn-outline-danger:hover {
            background-color: #dc3545 !important;
            color: #fff !important;
        }

        /* Dark mode improvements for action buttons */
        html[data-theme="dark"] .table td .btn-outline-primary,
        [data-theme="dark"] .table td .btn-outline-primary {
            border-color: #4e9eff;
            color: #4e9eff;
        }

        html[data-theme="dark"] .table td .btn-outline-primary:hover,
        [data-theme="dark"] .table td .btn-outline-primary:hover {
            background-color: #4e9eff;
            color: #fff;
        }

        html[data-theme="dark"] .table td .btn-outline-warning,
        [data-theme="dark"] .table td .btn-outline-warning {
            border-color: #ffc107;
            color: #ffc107;
        }

        html[data-theme="dark"] .table td .btn-outline-warning:hover,
        [data-theme="dark"] .table td .btn-outline-warning:hover {
            background-color: #ffc107;
            color: #000;
        }
    </style>
</head>
<body>
    <button id="sidebarToggle" aria-label="Toggle sidebar">
        <i class="fas fa-bars"></i>
    </button>
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <img src="icons/Logo.png" alt="Water Billing Logo" class="img-fluid" style="max-height: 120px;" />
        </div>
        
        <div class="nav-content">
            <a href="adminlandingpage.php">
                <i class="fas fa-chart-line"></i>
                <span>Dashboard</span>
            </a>
            <a href="view_clients.php">
                <i class="fas fa-users"></i>
                <span>Customers</span>
            </a>
            <a href="billing_list.php">
                <i class="fas fa-file-invoice-dollar"></i>
                <span>Bills</span>
            </a>
            <a href="pending_readings.php" class="active">
                <i class="fas fa-camera"></i>
                <span>Meter Readings</span>
            </a>

            <a href="payments.php">
                <i class="fas fa-money-bill-wave"></i>
                <span>Payments</span>
            </a>
            <a href="customer_accounts.php">
                <i class="fas fa-user-circle"></i>
                <span>Customer Accounts</span>
            </a>
            <a href="reports.php">
                <i class="fas fa-chart-line"></i>
                <span>Reports</span>
            </a>
            <a href="client_reports.php">
                <i class="fas fa-chart-bar"></i>
                <span>Water Outage Reports</span>
            </a>
            <a href="disconnection_notices.php">
                <i class="fas fa-exclamation-triangle"></i>
                <span>Disconnection Notices</span>
            </a>
            <a href="settings_rate.php">
                <i class="fas fa-cog"></i>
                <span>Settings</span>
            </a>
        </div>

        <div class="sidebar-footer">
            <div class="theme-switch-wrapper">
                <i class="fas fa-sun"></i>
                <label class="theme-switch">
                    <input type="checkbox" id="theme-toggle">
                    <span class="slider"></span>
                </label>
                <i class="fas fa-moon"></i>
            </div>
            
            <form method="POST" action="logout.php" class="mt-3">
                <button type="submit" class="btn btn-outline-primary w-100">
                    <i class="fas fa-sign-out-alt me-2"></i>Logout
                </button>
            </form>
        </div>
    </div>

    <div class="main-content">
        <!-- Header Section -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">Meter Reading Management</h2>
        </div>

        <!-- Current Billing Cycle Info -->
        <?php if ($active_cycle): ?>
            <div class="alert alert-success d-flex align-items-center mb-4" style="border-left: 4px solid #28a745;">
                <i class="fas fa-calendar-check fa-2x me-3 text-success"></i>
                <div class="flex-grow-1">
                    <h6 class="mb-1 text-success">
                        <i class="fas fa-circle text-success" style="font-size: 0.5rem;"></i>
                        Active Billing Cycle: <strong><?php echo htmlspecialchars($active_cycle['cycle_name']); ?></strong>
                    </h6>
                    <div class="row">
                        <div class="col-md-4">
                            <small class="text-muted">
                                <i class="fas fa-calendar-alt me-1"></i>
                                Period: <?php echo date('M d', strtotime($active_cycle['start_date'])); ?> - <?php echo date('M d, Y', strtotime($active_cycle['end_date'])); ?>
                            </small>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted">
                                <i class="fas fa-clock me-1"></i>
                                Due Date: <?php echo date('M d, Y', strtotime($active_cycle['due_date'])); ?>
                            </small>
                        </div>
                        <div class="col-md-4">
                            <small class="text-primary">
                                <i class="fas fa-mobile-alt me-1"></i>
                                Mobile readings will auto-assign to this cycle
                            </small>
                        </div>
                    </div>
                </div>
                <div class="text-end">
                    <a href="settings_rate.php#billing-cycles" class="btn btn-outline-success btn-sm">
                        <i class="fas fa-cog me-1"></i>Manage Cycles
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-warning d-flex align-items-center mb-4" style="border-left: 4px solid #ffc107;">
                <i class="fas fa-exclamation-triangle fa-2x me-3 text-warning"></i>
                <div class="flex-grow-1">
                    <h6 class="mb-1 text-warning">
                        <strong>No Active Billing Cycle</strong>
                    </h6>
                    <p class="mb-0">
                        Mobile app meter readings cannot be submitted without an active billing cycle. 
                        <a href="settings_rate.php#billing-cycles" class="alert-link">Create and activate a billing cycle</a> to enable meter reading collection.
                    </p>
                </div>
                <div class="text-end">
                    <a href="settings_rate.php#billing-cycles" class="btn btn-warning btn-sm">
                        <i class="fas fa-plus me-1"></i>Create Cycle
                    </a>
                </div>
            </div>
                        <?php endif; ?>

        <!-- Automated Bill Creation Messages -->
        <?php if (isset($_GET['auto_bills_created'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <strong>Automated Bill Creation Completed!</strong> 
                Successfully processed <?php echo isset($_GET['count']) ? (int)$_GET['count'] : 0; ?> meter readings and created bills automatically.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['auto_bills_error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <strong>Automated Bill Creation Error:</strong> 
                <?php echo htmlspecialchars($_GET['auto_bills_error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Automated Bill Creation Controls -->
        <?php if ($active_cycle): ?>
            <div class="card card-soft mb-4" style="border-left: 4px solid #007bff;">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h6 class="mb-1 text-primary">
                                <i class="fas fa-magic me-2"></i>
                                <strong>Automated Bill Creation</strong>
                            </h6>
                            <p class="mb-0 text-muted">
                                Automatically create bills for all <strong>scanned and processed</strong> meter readings with OCR values in the current billing cycle.
                                This will create bills for all customers who have submitted meter readings and apply water rates plus any additional fees.
                            </p>
                        </div>
                        <div class="col-md-4 text-end">
                            <form method="POST" id="autoCreateBillsForm" style="display: inline;">
                                <button type="submit" name="auto_create_bills" id="autoCreateBillsBtn" class="btn btn-primary">
                                    <i class="fas fa-bolt me-2"></i>Auto-Create Bills for All Scanned Readings
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Stats Row -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card card-soft stat-card" style="background: linear-gradient(45deg, #4e73df 0%, #224abe 100%);">
                    <div class="d-flex justify-content-between align-items-center position-relative" style="z-index: 2;">
                        <div>
                            <h6 class="text-white-50 mb-2">Total Readings</h6>
                            <h3 class="mb-1 text-white"><?php echo $total_readings; ?></h3>
                            <small class="text-white-50"><?php echo $today_count; ?> today</small>
                        </div>
                        <i class="fas fa-tachometer-alt stat-icon"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-soft stat-card" style="background: linear-gradient(45deg, #1cc88a 0%, #13855c 100%);">
                    <div class="d-flex justify-content-between align-items-center position-relative" style="z-index: 2;">
                        <div>
                            <h6 class="text-white-50 mb-2">Processed</h6>
                            <h3 class="mb-1 text-white"><?php echo $processed_count; ?></h3>
                            <small class="text-white-50"><?php echo $success_rate; ?>% success rate</small>
                        </div>
                        <i class="fas fa-check-circle stat-icon"></i>
                    </div>
                </div>
            </div>
            <?php if ($pending_count > 0): ?>
            <div class="col-md-3">
                <div class="card card-soft stat-card" style="background: linear-gradient(45deg, #f6c23e 0%, #dda20a 100%);">
                    <div class="d-flex justify-content-between align-items-center position-relative" style="z-index: 2;">
                        <div>
                            <h6 class="text-white-50 mb-2">Pending (Legacy)</h6>
                            <h3 class="mb-1 text-white"><?php echo $pending_count; ?></h3>
                            <small class="text-white-50">Old readings</small>
                        </div>
                        <i class="fas fa-clock stat-icon"></i>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <div class="col-md-3">
                <div class="card card-soft stat-card" style="background: linear-gradient(45deg, #e74a3b 0%, #be2617 100%);">
                    <div class="d-flex justify-content-between align-items-center position-relative" style="z-index: 2;">
                        <div>
                            <h6 class="text-white-50 mb-2">Failed</h6>
                            <h3 class="mb-1 text-white"><?php echo $failed_count; ?></h3>
                            <small class="text-white-50">Need attention</small>
                        </div>
                        <i class="fas fa-exclamation-circle stat-icon"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Auto-Processing Notice -->
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <i class="fas fa-info-circle me-2"></i>
            <strong>Auto-Processing Enabled:</strong> Meter readings uploaded from mobile devices are now automatically processed with OCR. No manual processing required! 
            <strong>Batch upload</strong> is available via <code>api/batch_upload_readings.php</code> for uploading multiple readings at once.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-4" id="readingTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $pending_count > 0 ? 'active' : ''; ?>" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending" type="button" role="tab">
                    <i class="fas fa-clock me-2"></i>Pending
                    <?php if ($pending_count > 0): ?>
                    <span class="badge bg-warning ms-2"><?php echo $pending_count; ?></span>
                    <?php endif; ?>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $pending_count == 0 ? 'active' : ''; ?>" id="processed-tab" data-bs-toggle="tab" data-bs-target="#processed" type="button" role="tab">
                    <i class="fas fa-check me-2"></i>Processed
                    <span class="badge bg-success ms-2"><?php echo $processed_count; ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="failed-tab" data-bs-toggle="tab" data-bs-target="#failed" type="button" role="tab">
                    <i class="fas fa-exclamation-triangle me-2"></i>Failed
                    <span class="badge bg-danger ms-2"><?php echo $failed_count; ?></span>
                </button>
            </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content" id="readingTabsContent">
            <!-- Pending Readings Tab - Always visible for readings that need manual OCR processing -->
            <div class="tab-pane fade <?php echo $pending_count > 0 ? 'show active' : ''; ?>" id="pending" role="tabpanel">
                <div class="card card-soft">
                    <div class="card-header d-flex justify-content-between align-items-center py-3">
                        <div>
                            <h5 class="mb-0">Pending Readings</h5>
                            <small class="text-muted">Readings that need manual OCR processing. Batch uploads auto-process OCR, but failed OCR goes here for manual review.</small>
                        </div>
                        <form method="POST" id="processingForm" class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-primary" id="selectAllPendingBtn">
                                <i class="fas fa-check-square me-2"></i>Select All
                            </button>
                            <button type="submit" name="process_selected" class="btn btn-primary" id="processSelectedBtn" disabled>
                                <i class="fas fa-cogs me-2"></i>Process Selected
                            </button>
                            <button type="button" class="btn btn-outline-danger" id="deleteSelectedPendingBtn" disabled onclick="deleteSelectedReadings('pending')">
                                <i class="fas fa-trash me-2"></i>Delete Selected
                            </button>
                        </form>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th width="50px"><input type="checkbox" id="selectAllPending"></th>
                                        <th>Customer</th>
                                        <th>Billing Cycle</th>
                                        <th>Meter Image</th>
                                        <th>Upload Date</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($pending_result->num_rows > 0): ?>
                                        <?php while($row = $pending_result->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" name="selected_readings[]" 
                                                       form="processingForm" 
                                                       value="<?php echo $row['id']; ?>" 
                                                       class="pending-checkbox">
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="avatar-sm">
                                                        <?php 
                                                            if (!empty($row['firstname']) && !empty($row['lastname'])) {
                                                                $initials = strtoupper(substr($row['firstname'], 0, 1) . substr($row['lastname'], 0, 1));
                                                                echo $initials;
                                                            } else {
                                                                echo '?';
                                                            }
                                                        ?>
                                                    </div>
                                                    <div>
                                                        <div class="fw-bold">
                                                            <?php 
                                                                if (!empty($row['firstname']) && !empty($row['lastname'])) {
                                                                    echo htmlspecialchars($row['firstname'] . ' ' . $row['lastname']);
                                                                } elseif (!empty($row['client_id'])) {
                                                                    echo 'Client ID: ' . htmlspecialchars($row['client_id']);
                                                                } else {
                                                                    echo 'Unknown Client';
                                                                }
                                                            ?>
                                                        </div>
                                                        <div class="text-muted">
                                                            <?php 
                                                                if (!empty($row['meter_code'])) {
                                                                    echo htmlspecialchars($row['meter_code']);
                                                                } else {
                                                                    echo 'No Meter Code';
                                                                }
                                                            ?>
                                                            <?php if (isset($row['client_status']) && $row['client_status'] != 1): ?>
                                                                <span class="badge bg-warning ms-1">Inactive</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($row['cycle_name']): ?>
                                                    <span class="badge bg-primary"><?php echo htmlspecialchars($row['cycle_name']); ?></span>
                                                    <br><small class="text-muted">Due: <?php echo date('M d, Y', strtotime($row['cycle_due_date'])); ?></small>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">No Cycle</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <img src="<?php echo htmlspecialchars($row['image_path']); ?>" 
                                                     class="meter-image" 
                                                     data-bs-toggle="modal" 
                                                     data-bs-target="#imageModal"
                                                     data-image="<?php echo htmlspecialchars($row['image_path']); ?>">
                                            </td>
                                            <td><?php echo date('M d, Y H:i', strtotime($row['upload_date'])); ?></td>
                                            <td>
                                                <span class="status-badge status-pending">
                                                    <i class="fas fa-clock"></i>
                                                    Pending
                                                </span>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-primary" onclick="viewImage('<?php echo htmlspecialchars($row['image_path']); ?>')">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-danger" onclick="deleteReading(<?php echo $row['id']; ?>)">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-4">
                                                <i class="fas fa-inbox fa-3x mb-3"></i>
                                                <p class="mb-0">No pending readings found</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Processed Readings Tab -->
            <div class="tab-pane fade <?php echo $pending_count == 0 ? 'show active' : ''; ?>" id="processed" role="tabpanel">
                <div class="card card-soft">
                    <div class="card-header d-flex justify-content-between align-items-center py-3">
                        <h5 class="mb-0">Processed Readings</h5>
                        <form method="POST" id="billCreationForm" class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-primary" id="selectAllProcessedBtn">
                                <i class="fas fa-check-square me-2"></i>Select All
                            </button>
                            <button type="submit" name="create_bills" class="btn btn-success" id="createBillsBtn" disabled>
                                <i class="fas fa-file-invoice-dollar me-2"></i>Create Bills
                            </button>
                            <button type="button" class="btn btn-outline-danger" id="deleteSelectedProcessedBtn" disabled onclick="deleteSelectedReadings('processed')">
                                <i class="fas fa-trash me-2"></i>Delete Selected
                            </button>
                        </form>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th width="50px"><input type="checkbox" id="selectAllProcessed"></th>
                                        <th>Customer</th>
                                        <th>Billing Cycle</th>
                                        <th>Meter Image</th>
                                        <th>Reading Value</th>
                                        <th>Process Date</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($processed_result->num_rows > 0): ?>
                                        <?php while($row = $processed_result->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" name="selected_processed[]" 
                                                       form="billCreationForm" 
                                                       value="<?php echo $row['id']; ?>" 
                                                       class="processed-checkbox">
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="avatar-sm bg-success">
                                                        <?php 
                                                            $firstname = $row['firstname'] ?? '';
                                                            $lastname = $row['lastname'] ?? '';
                                                            $initials = strtoupper(substr($firstname, 0, 1) . substr($lastname, 0, 1));
                                                            echo $initials ?: '?';
                                                        ?>
                                                    </div>
                                                    <div>
                                                        <div class="fw-bold">
                                                            <?php 
                                                                if ($firstname || $lastname) {
                                                                    echo htmlspecialchars(trim($firstname . ' ' . $lastname));
                                                                } else {
                                                                    echo '<span class="text-muted">Client ID: ' . $row['client_id'] . '</span>';
                                                                    if (isset($row['client_status']) && $row['client_status'] != 1) {
                                                                        echo ' <span class="badge bg-warning">Inactive</span>';
                                                                    }
                                                                }
                                                            ?>
                                                        </div>
                                                        <div class="text-muted"><?php echo htmlspecialchars($row['meter_code'] ?? 'N/A'); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($row['cycle_name']): ?>
                                                    <span class="badge bg-success"><?php echo htmlspecialchars($row['cycle_name']); ?></span>
                                                    <br><small class="text-muted">Due: <?php echo date('M d, Y', strtotime($row['cycle_due_date'])); ?></small>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">No Cycle</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <img src="<?php echo htmlspecialchars($row['image_path']); ?>" 
                                                     class="meter-image" 
                                                     data-bs-toggle="modal" 
                                                     data-bs-target="#imageModal"
                                                     data-image="<?php echo htmlspecialchars($row['image_path']); ?>">
                                            </td>
                                            <td>
                                                <?php 
                                                    $ocrReading = $row['ocr_reading'] ?? null;
                                                    $verifiedReading = $row['verified_reading'] ?? null;
                                                    $reading = $verifiedReading ?? $ocrReading ?? $row['reading_value'] ?? 0;
                                                    
                                                    // Show verified reading prominently if it exists
                                                    if ($verifiedReading !== null):
                                                ?>
                                                    <div class="d-flex align-items-center">
                                                        <span class="badge bg-success me-2">
                                                            <i class="fas fa-check-circle"></i> Verified
                                                        </span>
                                                        <strong class="text-success"><?php echo number_format($verifiedReading, 2); ?></strong>
                                                    </div>
                                                    <?php if ($ocrReading !== null && abs($ocrReading - $verifiedReading) > 0.01): ?>
                                                        <small class="text-muted d-block mt-1">
                                                            OCR: <?php echo number_format($ocrReading, 2); ?> 
                                                            <i class="fas fa-arrow-right mx-1"></i>
                                                            Corrected: <?php echo number_format($verifiedReading, 2); ?>
                                                        </small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <?php echo number_format($reading, 2); ?>
                                                    <?php if ($ocrReading !== null): ?>
                                                        <br><small class="text-muted">
                                                            <i class="fas fa-robot"></i> OCR: <?php echo number_format($ocrReading, 2); ?>
                                                        </small>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                
                                                <?php if (!empty($row['extracted_text'])): ?>
                                                    <br><small class="text-muted" title="<?php echo htmlspecialchars($row['extracted_text']); ?>">
                                                        <i class="fas fa-info-circle"></i> Details
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php 
                                                    $processedDate = $row['processed_at'] ?? $row['processed_date'] ?? null;
                                                    if ($processedDate) {
                                                        echo date('M d, Y H:i', strtotime($processedDate));
                                                    } else {
                                                        echo 'N/A';
                                                    }
                                                ?>
                                            </td>
                                            <td>
                                                <span class="status-badge status-processed">
                                                    <i class="fas fa-check-circle"></i>
                                                    Processed
                                                </span>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-primary" onclick="viewImage('<?php echo htmlspecialchars($row['image_path']); ?>')">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-warning" onclick="editReading(<?php echo $row['id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-danger" onclick="deleteReading(<?php echo $row['id']; ?>)">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted py-4">
                                                <i class="fas fa-check-circle fa-3x mb-3"></i>
                                                <p class="mb-0">No processed readings found</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Failed Readings Tab -->
            <div class="tab-pane fade" id="failed" role="tabpanel">
                <div class="card card-soft">
                    <div class="card-header d-flex justify-content-between align-items-center py-3">
                        <h5 class="mb-0">Failed Readings</h5>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-primary" id="selectAllFailedBtn">
                                <i class="fas fa-check-square me-2"></i>Select All
                            </button>
                            <button class="btn btn-danger" onclick="retryAllFailed()">
                                <i class="fas fa-redo me-2"></i>Retry All
                            </button>
                            <button type="button" class="btn btn-outline-danger" id="deleteSelectedFailedBtn" disabled onclick="deleteSelectedReadings('failed')">
                                <i class="fas fa-trash me-2"></i>Delete Selected
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th width="50px"><input type="checkbox" id="selectAllFailed"></th>
                                        <th>Customer</th>
                                        <th>Billing Cycle</th>
                                        <th>Meter Image</th>
                                        <th>Error Message</th>
                                        <th>Process Date</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($failed_result->num_rows > 0): ?>
                                        <?php while($row = $failed_result->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" 
                                                       value="<?php echo $row['id']; ?>" 
                                                       class="failed-checkbox">
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="avatar-sm bg-danger">
                                                        <?php 
                                                            $initials = strtoupper(substr($row['firstname'], 0, 1) . substr($row['lastname'], 0, 1));
                                                            echo $initials;
                                                        ?>
                                                    </div>
                                                    <div>
                                                        <div class="fw-bold"><?php echo htmlspecialchars($row['firstname'] . ' ' . $row['lastname']); ?></div>
                                                        <div class="text-muted"><?php echo htmlspecialchars($row['meter_code']); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($row['cycle_name']): ?>
                                                    <span class="badge bg-danger"><?php echo htmlspecialchars($row['cycle_name']); ?></span>
                                                    <br><small class="text-muted">Due: <?php echo date('M d, Y', strtotime($row['cycle_due_date'])); ?></small>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">No Cycle</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <img src="<?php echo htmlspecialchars($row['image_path']); ?>" 
                                                     class="meter-image" 
                                                     data-bs-toggle="modal" 
                                                     data-bs-target="#imageModal"
                                                     data-image="<?php echo htmlspecialchars($row['image_path']); ?>">
                                            </td>
                                            <td>
                                                <?php if (!empty($row['error_message']) || !empty($row['admin_notes'])): ?>
                                                    <div class="text-danger small" style="max-width: 300px;">
                                                        <strong>Error:</strong><br>
                                                        <?php echo htmlspecialchars($row['error_message'] ?? $row['admin_notes'] ?? 'Unknown error'); ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted">No error details</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo $row['processed_at'] ? date('M d, Y H:i', strtotime($row['processed_at'])) : 'N/A'; ?></td>
                                            <td>
                                                <span class="status-badge status-failed">
                                                    <i class="fas fa-exclamation-circle"></i>
                                                    Failed
                                                </span>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-primary" onclick="viewImage('<?php echo htmlspecialchars($row['image_path']); ?>')">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-warning" onclick="retryReading(<?php echo $row['id']; ?>)">
                                                    <i class="fas fa-redo"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-danger" onclick="deleteReading(<?php echo $row['id']; ?>)">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-4">
                                                <i class="fas fa-check-double fa-3x mb-3"></i>
                                                <p class="mb-0">No failed readings found</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Image Modal -->
    <div class="modal fade" id="imageModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Meter Reading Image</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <img src="" id="modalImage" class="img-fluid rounded">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="downloadImage()">
                        <i class="fas fa-download me-2"></i>Download
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Processing Loading Modal -->
    <div class="modal fade" id="processingLoadingModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body text-center py-5">
                    <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;">
                        <span class="visually-hidden">Processing...</span>
                    </div>
                    <h5 class="mb-2">Processing Meter Readings...</h5>
                    <p class="text-muted mb-0" id="processingStatus">Please wait while we process the OCR...</p>
                    <div class="progress mt-3" style="height: 5px;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 100%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Processing Result Modal -->
    <div class="modal fade" id="processingResultModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" id="resultModalHeader">
                    <h5 class="modal-title" id="resultModalTitle">
                        <i class="fas fa-check-circle me-2"></i>Processing Complete
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center py-4" id="resultModalBody">
                    <div id="resultIcon" class="mb-3">
                        <i class="fas fa-check-circle text-success" style="font-size: 4rem;"></i>
                    </div>
                    <h5 id="resultMessage">Processing completed successfully!</h5>
                    <p class="text-muted" id="resultDetails"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="location.reload()">
                        <i class="fas fa-sync me-2"></i>Refresh Page
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS and other scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Notification System -->
    <script src="assets/js/notifications.js"></script>
    <script>
        // Theme toggle functionality
        document.addEventListener('DOMContentLoaded', function() {
            const themeToggle = document.getElementById('theme-toggle');
            const root = document.documentElement;
            
            // Check for saved theme preference or default to light
            const savedTheme = localStorage.getItem('theme') || 'light';
            root.setAttribute('data-theme', savedTheme);
            themeToggle.checked = savedTheme === 'dark';
            
            // Theme toggle event listener
            themeToggle.addEventListener('change', function() {
                const theme = this.checked ? 'dark' : 'light';
                root.setAttribute('data-theme', theme);
                localStorage.setItem('theme', theme);
            });
        });

        document.addEventListener('DOMContentLoaded', function() {
            // Handle auto-create bills form confirmation
            const autoCreateBillsForm = document.getElementById('autoCreateBillsForm');
            if (autoCreateBillsForm) {
                autoCreateBillsForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    showConfirm('Create bills automatically for all scanned and processed meter readings in the current billing cycle? This will create bills for all customers with valid OCR readings. This action cannot be undone.', function() {
                        // User confirmed - submit the form
                        autoCreateBillsForm.submit();
                    });
                });
            }
            
            // Handle image modal
            const imageModal = document.getElementById('imageModal');
            imageModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const imagePath = button.getAttribute('data-image');
                document.getElementById('modalImage').src = imagePath;
            });

            // Handle Processing Form Submission with AJAX
            const processingForm = document.getElementById('processingForm');
            if (processingForm) {
                processingForm.addEventListener('submit', function(e) {
                    e.preventDefault(); // Prevent default form submission
                    
                    // Get selected readings
                    const selectedReadings = document.querySelectorAll('input[name="selected_readings[]"]:checked');
                    
                    if (selectedReadings.length === 0) {
                        showWarning('Please select at least one reading to process.');
                        return;
                    }
                    
                    // Show loading modal
                    const loadingModal = new bootstrap.Modal(document.getElementById('processingLoadingModal'));
                    loadingModal.show();
                    
                    // Update status text
                    document.getElementById('processingStatus').textContent = 
                        `Processing ${selectedReadings.length} reading(s)... This may take a moment.`;
                    
                    // Prepare form data
                    const formData = new FormData(this);
                    formData.append('process_selected', '1');
                    
                    // Create AbortController for timeout
                    const controller = new AbortController();
                    const timeoutId = setTimeout(() => {
                        controller.abort();
                        loadingModal.hide();
                        showError('Processing timed out after 5 minutes. The Roboflow API may be slow. Please try again or check server logs.');
                    }, 300000); // 5 minute timeout (increased from 2 minutes)
                    
                    // Submit via AJAX
                    fetch('pending_readings.php', {
                        method: 'POST',
                        body: formData,
                        redirect: 'manual', // Don't follow redirects automatically
                        signal: controller.signal // Add abort signal
                    })
                    .then(response => {
                        clearTimeout(timeoutId); // Clear timeout on success
                        
                        // Check if it's a redirect (302, 303, 307, etc.)
                        if (response.type === 'opaqueredirect' || response.status === 0 || (response.status >= 300 && response.status < 400)) {
                            // Get redirect location from headers
                            const redirectUrl = response.headers.get('Location') || response.url;
                            
                            // Parse URL parameters from redirect
                            try {
                                const url = new URL(redirectUrl, window.location.origin);
                                const status = url.searchParams.get('status') || 'success';
                                const message = decodeURIComponent(url.searchParams.get('message') || 'Processing completed!');
                                
                                // Hide loading modal
                                loadingModal.hide();
                                
                                // Show result modal
                                setTimeout(() => {
                                    showProcessingResult(status, message);
                                }, 300);
                                
                                return;
                            } catch (e) {
                                console.error('Error parsing redirect URL:', e);
                            }
                        }
                        
                        // If not a redirect, check response
                        return response.text();
                    })
                    .then(html => {
                        if (!html) return; // Already handled as redirect
                        
                        // Hide loading modal
                        loadingModal.hide();
                        
                        // Try to parse the response for redirect URL in the HTML
                        const match = html.match(/Location:\s*pending_readings\.php\?(.+?)[\s"'<]/);
                        if (match && match[1]) {
                            const urlParams = new URLSearchParams(match[1]);
                            const status = urlParams.get('status') || 'success';
                            const message = decodeURIComponent(urlParams.get('message') || 'Processing completed!');
                            
                            setTimeout(() => {
                                showProcessingResult(status, message);
                            }, 300);
                        } else {
                            // Fallback: assume success
                            setTimeout(() => {
                                showProcessingResult('success', 'Processing completed! Refreshing page...');
                                setTimeout(() => location.reload(), 2000);
                            }, 300);
                        }
                    })
                    .catch(error => {
                        clearTimeout(timeoutId); // Clear timeout on error
                        
                        // Hide loading modal
                        loadingModal.hide();
                        
                        // Show error result
                        console.error('Processing error:', error);
                        let errorMsg = 'An error occurred while processing.';
                        if (error.name === 'AbortError') {
                            errorMsg = 'Processing timed out. The request took too long. Please try again or check if Roboflow API is accessible.';
                        }
                        setTimeout(() => {
                            showProcessingResult('error', errorMsg);
                        }, 300);
                    });
                });
            }

            // Handle checkboxes for pending readings (only if pending tab exists)
            const selectAllPending = document.getElementById('selectAllPending');
            const pendingCheckboxes = document.querySelectorAll('.pending-checkbox');
            const processSelectedBtn = document.getElementById('processSelectedBtn');

            function updateProcessButton() {
                const checkedCount = document.querySelectorAll('.pending-checkbox:checked').length;
                if (processSelectedBtn) {
                    processSelectedBtn.disabled = checkedCount === 0;
                }
                const deleteSelectedPendingBtn = document.getElementById('deleteSelectedPendingBtn');
                if (deleteSelectedPendingBtn) {
                    deleteSelectedPendingBtn.disabled = checkedCount === 0;
                }
            }

            if (selectAllPending && pendingCheckboxes.length > 0) {
                selectAllPending.addEventListener('change', function() {
                    pendingCheckboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                    updateProcessButton();
                });

                pendingCheckboxes.forEach(checkbox => {
                    checkbox.addEventListener('change', updateProcessButton);
                });
            }

            // Handle checkboxes for processed readings
            const selectAllProcessed = document.getElementById('selectAllProcessed');
            const processedCheckboxes = document.querySelectorAll('.processed-checkbox');
            const createBillsBtn = document.getElementById('createBillsBtn');

            selectAllProcessed.addEventListener('change', function() {
                processedCheckboxes.forEach(checkbox => {
                    checkbox.checked = this.checked;
                });
                updateCreateBillsButton();
            });

            processedCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', updateCreateBillsButton);
            });

            function updateCreateBillsButton() {
                const checkedCount = document.querySelectorAll('.processed-checkbox:checked').length;
                createBillsBtn.disabled = checkedCount === 0;
                const deleteSelectedProcessedBtn = document.getElementById('deleteSelectedProcessedBtn');
                if (deleteSelectedProcessedBtn) {
                    deleteSelectedProcessedBtn.disabled = checkedCount === 0;
                }
            }

            // Handle checkboxes for failed readings
            const selectAllFailed = document.getElementById('selectAllFailed');
            const failedCheckboxes = document.querySelectorAll('.failed-checkbox');
            const deleteSelectedFailedBtn = document.getElementById('deleteSelectedFailedBtn');

            if (selectAllFailed) {
                selectAllFailed.addEventListener('change', function() {
                    failedCheckboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                    updateDeleteFailedButton();
                });
            }

            failedCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', updateDeleteFailedButton);
            });

            function updateDeleteFailedButton() {
                const checkedCount = document.querySelectorAll('.failed-checkbox:checked').length;
                if (deleteSelectedFailedBtn) {
                    deleteSelectedFailedBtn.disabled = checkedCount === 0;
                }
            }

            // Select All buttons
            const selectAllPendingBtn = document.getElementById('selectAllPendingBtn');
            if (selectAllPendingBtn && selectAllPending && pendingCheckboxes.length > 0) {
                selectAllPendingBtn.addEventListener('click', function() {
                    const allChecked = Array.from(pendingCheckboxes).every(cb => cb.checked);
                    pendingCheckboxes.forEach(checkbox => {
                        checkbox.checked = !allChecked;
                    });
                    if (selectAllPending) {
                        selectAllPending.checked = !allChecked;
                    }
                    updateProcessButton();
                });
            }

            const selectAllProcessedBtn = document.getElementById('selectAllProcessedBtn');
            if (selectAllProcessedBtn) {
                selectAllProcessedBtn.addEventListener('click', function() {
                    const allChecked = Array.from(processedCheckboxes).every(cb => cb.checked);
                    processedCheckboxes.forEach(checkbox => {
                        checkbox.checked = !allChecked;
                    });
                    selectAllProcessed.checked = !allChecked;
                    updateCreateBillsButton();
                });
            }

            const selectAllFailedBtn = document.getElementById('selectAllFailedBtn');
            if (selectAllFailedBtn) {
                selectAllFailedBtn.addEventListener('click', function() {
                    const allChecked = Array.from(failedCheckboxes).every(cb => cb.checked);
                    failedCheckboxes.forEach(checkbox => {
                        checkbox.checked = !allChecked;
                    });
                    if (selectAllFailed) {
                        selectAllFailed.checked = !allChecked;
                    }
                    updateDeleteFailedButton();
                });
            }

            // Handle file upload
        });

        function viewImage(imagePath) {
            document.getElementById('modalImage').src = imagePath;
            new bootstrap.Modal(document.getElementById('imageModal')).show();
        }

        // Function to show processing result modal
        function showProcessingResult(status, message) {
            const resultModal = new bootstrap.Modal(document.getElementById('processingResultModal'));
            const resultIcon = document.getElementById('resultIcon');
            const resultTitle = document.getElementById('resultModalTitle');
            const resultMessage = document.getElementById('resultMessage');
            const resultDetails = document.getElementById('resultDetails');
            const resultHeader = document.getElementById('resultModalHeader');
            
            // Configure modal based on status
            if (status === 'success') {
                resultIcon.innerHTML = '<i class="fas fa-check-circle text-success" style="font-size: 4rem;"></i>';
                resultTitle.innerHTML = '<i class="fas fa-check-circle me-2"></i>Processing Complete';
                resultHeader.className = 'modal-header bg-success text-white';
                resultMessage.textContent = 'Successfully processed all readings!';
                resultDetails.textContent = message || 'All selected meter readings have been processed successfully.';
            } else if (status === 'partial') {
                resultIcon.innerHTML = '<i class="fas fa-exclamation-circle text-warning" style="font-size: 4rem;"></i>';
                resultTitle.innerHTML = '<i class="fas fa-exclamation-circle me-2"></i>Partially Complete';
                resultHeader.className = 'modal-header bg-warning text-dark';
                resultMessage.textContent = 'Some readings processed with warnings';
                resultDetails.textContent = message || 'Some readings were processed, but some failed. Check the failed tab for details.';
            } else if (status === 'warning') {
                resultIcon.innerHTML = '<i class="fas fa-info-circle text-info" style="font-size: 4rem;"></i>';
                resultTitle.innerHTML = '<i class="fas fa-info-circle me-2"></i>Notice';
                resultHeader.className = 'modal-header bg-info text-white';
                resultMessage.textContent = 'Processing Notice';
                resultDetails.textContent = message || 'Please check your selection and try again.';
            } else {
                resultIcon.innerHTML = '<i class="fas fa-times-circle text-danger" style="font-size: 4rem;"></i>';
                resultTitle.innerHTML = '<i class="fas fa-times-circle me-2"></i>Processing Failed';
                resultHeader.className = 'modal-header bg-danger text-white';
                resultMessage.textContent = 'Failed to process readings';
                resultDetails.textContent = message || 'An error occurred while processing. Please try again or contact support.';
            }
            
            resultModal.show();
        }

        function downloadImage() {
            const image = document.getElementById('modalImage');
            const link = document.createElement('a');
            link.href = image.src;
            link.download = image.src.split('/').pop();
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        function deleteReading(id) {
            showConfirm('Are you sure you want to delete this reading?', function() {
                fetch('delete_reading.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `id=${id}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showSuccess('Reading deleted successfully!');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showError('Error deleting reading: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showError('Error deleting reading');
                });
            });
        }

        function deleteSelectedReadings(type) {
            let checkboxes;
            let count = 0;
            
            if (type === 'pending') {
                checkboxes = document.querySelectorAll('.pending-checkbox:checked');
            } else if (type === 'processed') {
                checkboxes = document.querySelectorAll('.processed-checkbox:checked');
            } else if (type === 'failed') {
                checkboxes = document.querySelectorAll('.failed-checkbox:checked');
            }
            
            if (!checkboxes || checkboxes.length === 0) {
                showWarning('Please select at least one reading to delete.');
                return;
            }
            
            count = checkboxes.length;
            const confirmMessage = `Are you sure you want to delete ${count} reading(s)? This action cannot be undone.`;
            
            showConfirm(confirmMessage, function() {
                // User confirmed - proceed with deletion
                proceedWithDeletion();
            });
            
            function proceedWithDeletion() {
            
            const ids = Array.from(checkboxes).map(cb => cb.value);
            
            // Show loading
            const loadingHtml = `
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <div class="d-flex align-items-center">
                        <div class="spinner-border spinner-border-sm me-2" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <div>Deleting ${count} reading(s)...</div>
                    </div>
                </div>
            `;
            document.querySelector('.main-content').insertAdjacentHTML('afterbegin', loadingHtml);
            
            // Prepare form data
            const formData = new URLSearchParams();
            ids.forEach(id => {
                formData.append('ids[]', id);
            });
            
            fetch('delete_reading.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData.toString()
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showSuccess(data.message);
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showError('Error deleting readings: ' + data.message);
                    const loadingAlert = document.querySelector('.alert-info');
                    if (loadingAlert) loadingAlert.remove();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('Error deleting readings');
                const loadingAlert = document.querySelector('.alert-info');
                if (loadingAlert) loadingAlert.remove();
            });
            }
        }

        function editReading(id) {
            // Remove any existing modals first
            const existingLoadingModal = document.getElementById('loadingModal');
            if (existingLoadingModal) {
                existingLoadingModal.remove();
            }
            const existingEditModal = document.getElementById('editReadingModal');
            if (existingEditModal) {
                existingEditModal.remove();
            }
            
            // Show loading state
            const loadingHtml = `
                <div class="modal fade" id="loadingModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-body text-center py-4">
                                <div class="spinner-border text-primary mb-3" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <h5 class="mb-0">Loading reading details...</h5>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', loadingHtml);
            const loadingModalElement = document.getElementById('loadingModal');
            const loadingModal = new bootstrap.Modal(loadingModalElement, {
                backdrop: 'static',
                keyboard: false
            });
            loadingModal.show();

            // Fetch meter reading details with timeout
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout
            
            fetch(`get_meter_reading.php?id=${id}`, {
                signal: controller.signal
            })
                .then(response => {
                    clearTimeout(timeoutId);
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.text(); // Get as text first to see raw response
                })
                .then(text => {
                    console.log('Raw response:', text); // Debug log
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('JSON parse error:', e);
                        console.error('Response text:', text);
                        throw new Error('Invalid JSON response from server. Check console for details.');
                    }
                })
                .then(data => {
                    // Hide and remove loading modal FIRST
                    try {
                        loadingModal.hide();
                        // Wait for modal to finish hiding animation
                        loadingModalElement.addEventListener('hidden.bs.modal', function onHidden() {
                            loadingModalElement.remove();
                            loadingModalElement.removeEventListener('hidden.bs.modal', onHidden);
                        }, { once: true });
                        
                        // Force remove if animation doesn't complete
                        setTimeout(() => {
                            const loadingModalEl = document.getElementById('loadingModal');
                            if (loadingModalEl) {
                                loadingModalEl.remove();
                            }
                        }, 300);
                    } catch (e) {
                        console.warn('Error hiding loading modal:', e);
                        // Force remove on error
                        const loadingModalEl = document.getElementById('loadingModal');
                        if (loadingModalEl) {
                            loadingModalEl.remove();
                        }
                    }
                    
                    if (!data.success) {
                        const errorMsg = data.message || 'Unknown error occurred';
                        const debugInfo = data.debug ? '\nDebug: ' + JSON.stringify(data.debug) : '';
                        throw new Error(errorMsg + debugInfo);
                    }
                    
                    const reading = data.data;
                    const ocrReading = reading.ocr_reading !== null && reading.ocr_reading !== undefined ? reading.ocr_reading : 'N/A';
                    const currentReading = reading.current_reading !== null && reading.current_reading !== undefined ? reading.current_reading : 0;
                    
                    // Small delay to ensure loading modal is removed before showing edit modal
                    setTimeout(() => {
                        // Create edit modal with image preview
                    const modalHtml = `
                        <div class="modal fade" id="editReadingModal" tabindex="-1">
                            <div class="modal-dialog modal-xl">
                                <div class="modal-content">
                                    <div class="modal-header bg-primary text-white">
                                        <h5 class="modal-title">
                                            <i class="fas fa-edit me-2"></i>Correct Meter Reading
                                        </h5>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                    </div>
                                    <form id="editReadingForm">
                                        <div class="modal-body">
                                            <input type="hidden" name="reading_id" value="${id}">
                                            
                                            <!-- Customer Info -->
                                            <div class="row mb-3">
                                                <div class="col-md-4">
                                                    <label class="form-label fw-bold">Customer</label>
                                                    <input type="text" class="form-control" value="${reading.client_name}" readonly>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label fw-bold">Meter Code</label>
                                                    <input type="text" class="form-control" value="${reading.meter_code}" readonly>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label fw-bold">Status</label>
                                                    <input type="text" class="form-control" value="${reading.status.charAt(0).toUpperCase() + reading.status.slice(1)}" readonly>
                                                </div>
                                            </div>
                                            
                                            <div class="row">
                                                <!-- Image Preview -->
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label fw-bold">
                                                        <i class="fas fa-image me-2"></i>Meter Image
                                                    </label>
                                                    <div class="card" style="max-height: 500px; overflow: auto;">
                                                        <img src="${reading.image_path}" 
                                                             class="img-fluid" 
                                                             alt="Meter Reading Image"
                                                             style="width: 100%; height: auto; cursor: pointer;"
                                                             onclick="window.open('${reading.image_path}', '_blank')">
                                                    </div>
                                                    <small class="text-muted">Click image to view full size</small>
                                                </div>
                                                
                                                <!-- Reading Correction Form -->
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label fw-bold">
                                                        <i class="fas fa-tachometer-alt me-2"></i>Reading Values
                                                    </label>
                                                    
                                                    <!-- OCR Reading (for reference) -->
                                                    <div class="mb-3">
                                                        <label class="form-label text-muted">OCR Detected Reading</label>
                                                        <div class="input-group">
                                                            <span class="input-group-text bg-light">
                                                                <i class="fas fa-robot"></i>
                                                            </span>
                                                            <input type="text" 
                                                                   class="form-control" 
                                                                   value="${ocrReading}" 
                                                                   readonly
                                                                   style="background-color: #f8f9fa;">
                                                            <span class="input-group-text bg-light">m³</span>
                                                        </div>
                                                        <small class="text-muted">This is what the OCR system detected. Please verify and correct if needed.</small>
                                                    </div>
                                                    
                                                    <!-- Corrected Reading -->
                                                    <div class="mb-3">
                                                        <label class="form-label fw-bold text-primary">
                                                            <i class="fas fa-check-circle me-2"></i>Corrected Reading Value *
                                                        </label>
                                                        <div class="input-group">
                                                            <span class="input-group-text bg-primary text-white">
                                                                <i class="fas fa-edit"></i>
                                                            </span>
                                                            <input type="number" 
                                                                   class="form-control" 
                                                                   name="verified_reading" 
                                                                   id="verified_reading"
                                                                   value="${currentReading}" 
                                                                   step="0.01" 
                                                                   min="0" 
                                                                   max="99999"
                                                                   required
                                                                   placeholder="Enter correct reading">
                                                            <span class="input-group-text bg-primary text-white">m³</span>
                                                        </div>
                                                        <small class="text-muted">Enter the correct 5-digit reading value from the image above.</small>
                                                    </div>
                                                    
                                                    <!-- Admin Notes -->
                                                    <div class="mb-3">
                                                        <label class="form-label">
                                                            <i class="fas fa-sticky-note me-2"></i>Admin Notes (Optional)
                                                        </label>
                                                        <textarea class="form-control" 
                                                                  name="admin_notes" 
                                                                  rows="3" 
                                                                  placeholder="Add any notes about this correction..."></textarea>
                                                        <small class="text-muted">Optional: Add notes explaining why the reading was corrected.</small>
                                                    </div>
                                                    
                                                    <!-- Extracted Text (if available) -->
                                                    ${reading.extracted_text ? `
                                                    <div class="mb-3">
                                                        <label class="form-label text-muted">OCR Extracted Text</label>
                                                        <div class="card bg-light">
                                                            <div class="card-body p-2">
                                                                <small class="text-muted" style="font-family: monospace;">${reading.extracted_text}</small>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    ` : ''}
                                                </div>
                                            </div>
                                            
                                            <!-- Warning Alert -->
                                            <div class="alert alert-warning mb-0">
                                                <i class="fas fa-exclamation-triangle me-2"></i>
                                                <strong>Important:</strong> Make sure to carefully review the meter image and enter the correct reading value. 
                                                This correction will be used for billing calculations.
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                <i class="fas fa-times me-2"></i>Cancel
                                            </button>
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-save me-2"></i>Save Corrected Reading
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    `;
                    
                        // Remove any existing edit modal
                        const existingModal = document.getElementById('editReadingModal');
                        if (existingModal) {
                            existingModal.remove();
                        }
                        
                        // Clean up any existing modals/backdrops first
                        const existingBackdrops = document.querySelectorAll('.modal-backdrop');
                        existingBackdrops.forEach(backdrop => backdrop.remove());
                        document.body.classList.remove('modal-open');
                        document.body.style.overflow = '';
                        document.body.style.paddingRight = '';
                        
                        // Add new modal to the page
                        document.body.insertAdjacentHTML('beforeend', modalHtml);
                        
                        // Show the modal
                        const editModalElement = document.getElementById('editReadingModal');
                        const editModal = new bootstrap.Modal(editModalElement, {
                            backdrop: true,
                            keyboard: true
                        });
                        editModal.show();
                        
                        // Cleanup function to remove modal and backdrop
                        function cleanupEditModal() {
                            setTimeout(() => {
                                // Remove all backdrops (in case multiple exist)
                                const backdrops = document.querySelectorAll('.modal-backdrop');
                                backdrops.forEach(backdrop => backdrop.remove());
                                
                                // Remove modal from DOM
                                if (editModalElement && editModalElement.parentNode) {
                                    editModalElement.remove();
                                }
                                
                                // Remove body classes and styles
                                document.body.classList.remove('modal-open');
                                document.body.style.overflow = '';
                                document.body.style.paddingRight = '';
                            }, 150); // Small delay to allow Bootstrap animation to complete
                        }
                        
                        // Handle modal hidden event (when modal finishes closing animation)
                        // This fires after Bootstrap completes the close animation
                        editModalElement.addEventListener('hidden.bs.modal', function() {
                            cleanupEditModal();
                        }, { once: true });
                        
                        // Also handle hide event as backup (fires immediately when hide() is called)
                        editModalElement.addEventListener('hide.bs.modal', function() {
                            // Ensure cleanup happens even if hidden event doesn't fire
                            setTimeout(() => {
                                cleanupEditModal();
                            }, 500);
                        }, { once: true });
                        
                        // Handle form submission
                        document.getElementById('editReadingForm').addEventListener('submit', function(e) {
                            e.preventDefault();
                            
                            const verifiedReading = document.getElementById('verified_reading').value;
                            if (!verifiedReading || verifiedReading < 0 || verifiedReading > 99999) {
                                showWarning('Please enter a valid reading value between 0 and 99999');
                                return;
                            }
                            
                            const formData = new FormData(this);
                            
                            // Show loading
                            const submitBtn = this.querySelector('button[type="submit"]');
                            const originalText = submitBtn.innerHTML;
                            submitBtn.disabled = true;
                            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';
                            
                            fetch('update_meter_reading.php', {
                                method: 'POST',
                                body: formData
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    editModal.hide();
                                    // Cleanup modal
                                    setTimeout(() => {
                                        const backdrop = document.querySelector('.modal-backdrop');
                                        if (backdrop) backdrop.remove();
                                        if (editModalElement) editModalElement.remove();
                                        document.body.classList.remove('modal-open');
                                        document.body.style.overflow = '';
                                        document.body.style.paddingRight = '';
                                    }, 300);
                                    // Show success message
                                    showSuccess('Reading value updated successfully!');
                                    setTimeout(() => location.reload(), 1000);
                                } else {
                                    throw new Error(data.message);
                                }
                            })
                            .catch(error => {
                                showError('Error updating reading: ' + error.message);
                                submitBtn.disabled = false;
                                submitBtn.innerHTML = originalText;
                            });
                        });
                    }, 350); // Small delay to ensure loading modal is fully removed
                })
                .catch(error => {
                    clearTimeout(timeoutId);
                    // Hide and remove loading modal
                    try {
                        loadingModal.hide();
                    } catch (e) {
                        console.warn('Error hiding loading modal:', e);
                    }
                    
                    const loadingModalEl = document.getElementById('loadingModal');
                    if (loadingModalEl) {
                        loadingModalEl.remove();
                    }
                    
                    console.error('Error fetching reading details:', error);
                    
                    let errorMessage = 'Error fetching reading details: ';
                    if (error.name === 'AbortError') {
                        errorMessage += 'Request timed out. The server may be slow or unresponsive.';
                    } else {
                        errorMessage += error.message;
                    }
                    errorMessage += '\n\nPlease check the browser console (F12) for more details.';
                    
                    showError(errorMessage);
                });
        }

        function retryReading(id) {
            fetch('retry_reading.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `id=${id}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showSuccess('Reading retry initiated successfully!');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showError('Error retrying reading: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('Error retrying reading');
            });
        }

        function retryAllFailed() {
            showConfirm('Are you sure you want to retry processing all failed readings?', function() {
                fetch('retry_all_readings.php', {
                    method: 'POST'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showSuccess('Retry process initiated successfully!');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showError('Error retrying readings: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showError('Error retrying readings');
                });
            });
        }

        // Display processing result message
        document.addEventListener('DOMContentLoaded', function() {
            // Check for processing result in URL
            const urlParams = new URLSearchParams(window.location.search);
            const processed = urlParams.get('processed');
            const status = urlParams.get('status');
            const message = urlParams.get('message');
            
            if (processed === 'true' && message) {
                // Determine alert type based on status
                let alertType = 'info';
                if (status === 'success') {
                    alertType = 'success';
                } else if (status === 'error') {
                    alertType = 'danger';
                } else if (status === 'warning') {
                    alertType = 'warning';
                } else if (status === 'partial') {
                    alertType = 'warning';
                }
                
                // Create and show alert
                const alertDiv = document.createElement('div');
                alertDiv.className = `alert alert-${alertType} alert-dismissible fade show`;
                alertDiv.setAttribute('role', 'alert');
                alertDiv.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px; max-width: 500px;';
                alertDiv.innerHTML = `
                    <strong>${status === 'success' ? 'Success!' : status === 'error' ? 'Error!' : 'Notice'}</strong> ${decodeURIComponent(message)}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                `;
                document.body.appendChild(alertDiv);
                
                // Auto-remove after 5 seconds
                setTimeout(function() {
                    alertDiv.remove();
                }, 5000);
                
                // Clean URL
                window.history.replaceState({}, document.title, window.location.pathname);
            }
            
            // Sidebar toggle for mobile
            var sidebar = document.querySelector('.sidebar');
            var sidebarToggle = document.getElementById('sidebarToggle');
            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('open');
                });
            }
            // Optional: close sidebar when clicking outside on mobile
            document.addEventListener('click', function(e) {
                if (window.innerWidth <= 991 && sidebar.classList.contains('open')) {
                    if (!sidebar.contains(e.target) && e.target !== sidebarToggle) {
                        sidebar.classList.remove('open');
                    }
                }
            });
        });
    </script>
</body>
</html>