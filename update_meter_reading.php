<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

include 'db.php';
include 'image_cleanup_utility.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Only POST method allowed']);
    exit();
}

$reading_id = isset($_POST['reading_id']) ? intval($_POST['reading_id']) : 0;
$verified_reading = isset($_POST['verified_reading']) ? trim($_POST['verified_reading']) : '';
$admin_notes = isset($_POST['admin_notes']) ? trim($_POST['admin_notes']) : '';

if ($reading_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid reading ID']);
    exit();
}

if (empty($verified_reading)) {
    echo json_encode(['success' => false, 'message' => 'Reading value is required']);
    exit();
}

// Validate reading is numeric and reasonable (0-99999)
$verified_reading = floatval($verified_reading);
if ($verified_reading < 0 || $verified_reading > 99999) {
    echo json_encode(['success' => false, 'message' => 'Reading value must be between 0 and 99999']);
    exit();
}

try {
    // Get current reading data
    $stmt = $conn->prepare("SELECT * FROM pending_meter_readings WHERE id = ?");
    $stmt->bind_param("i", $reading_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Reading not found']);
        exit();
    }
    
    $reading = $result->fetch_assoc();
    
    // Update verified reading and admin notes
    // Check if updated_at column exists, if not, don't include it
    $check_updated_at = $conn->query("SHOW COLUMNS FROM pending_meter_readings LIKE 'updated_at'");
    $has_updated_at = $check_updated_at && $check_updated_at->num_rows > 0;
    
    if ($has_updated_at) {
        $update_sql = "UPDATE pending_meter_readings SET 
                        verified_reading = ?,
                        admin_notes = ?,
                        updated_at = NOW()
                       WHERE id = ?";
    } else {
        $update_sql = "UPDATE pending_meter_readings SET 
                        verified_reading = ?,
                        admin_notes = ?
                       WHERE id = ?";
    }
    
    $update_stmt = $conn->prepare($update_sql);
    
    // Combine admin notes if there are existing notes
    $final_notes = '';
    if (!empty($reading['admin_notes'])) {
        $final_notes = $reading['admin_notes'] . "\n---\n";
    }
    $final_notes .= date('Y-m-d H:i:s') . ' - Reading corrected by admin: ' . $verified_reading;
    if (!empty($admin_notes)) {
        $final_notes .= "\nNote: " . $admin_notes;
    }
    
    $update_stmt->bind_param("dsi", $verified_reading, $final_notes, $reading_id);
    
    if ($update_stmt->execute()) {
        // Delete image after reading is updated/verified
        deleteImageAfterProcessing($reading_id, $conn);
        
        echo json_encode([
            'success' => true,
            'message' => 'Reading value updated successfully',
            'data' => [
                'reading_id' => $reading_id,
                'verified_reading' => $verified_reading,
                'previous_ocr_reading' => $reading['ocr_reading'] ?? null
            ]
        ]);
    } else {
        throw new Exception('Database update failed: ' . $conn->error);
    }
    
    $update_stmt->close();
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error updating reading: ' . $e->getMessage()
    ]);
}

