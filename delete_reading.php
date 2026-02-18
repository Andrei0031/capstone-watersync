<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

include 'db.php';

header('Content-Type: application/json');

// Support both form-encoded ($_POST) and raw JSON bodies
$rawInput = file_get_contents('php://input');
$jsonData = [];
if (!empty($rawInput)) {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $jsonData = $decoded;
    }
}

// Optional: password can be sent together with the delete request
$provided_password = null;
if (isset($jsonData['password'])) {
    $provided_password = $jsonData['password'];
} elseif (isset($_POST['password'])) {
    $provided_password = $_POST['password'];
}

try {
    // Determine if this is a bulk delete request
    $ids = null;
    if (isset($_POST['ids']) && is_array($_POST['ids'])) {
        $ids = $_POST['ids'];
    } elseif (isset($jsonData['ids']) && is_array($jsonData['ids'])) {
        $ids = $jsonData['ids'];
    }

    // Handle bulk deletion
    if ($ids !== null) {
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids, function($id) { return $id > 0; });
        
        if (empty($ids)) {
            throw new Exception('No valid reading IDs provided');
        }
        
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        
        // Get all readings to delete their image files
        $stmt = $conn->prepare("SELECT id, image_path FROM pending_meter_readings WHERE id IN ($placeholders)");
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $deleted_count = 0;
        $image_deleted_count = 0;
        
        while ($reading = $result->fetch_assoc()) {
            // Delete the image file if it exists
            if (!empty($reading['image_path'])) {
                $image_path = '../' . $reading['image_path'];
                $image_path_alt = $reading['image_path'];
                
                if (file_exists($image_path) && is_file($image_path)) {
                    @unlink($image_path);
                    $image_deleted_count++;
                } elseif (file_exists($image_path_alt) && is_file($image_path_alt)) {
                    @unlink($image_path_alt);
                    $image_deleted_count++;
                }
            }
        }
        
        // Delete all readings from database
        $delete_stmt = $conn->prepare("DELETE FROM pending_meter_readings WHERE id IN ($placeholders)");
        $delete_stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        
        if (!$delete_stmt->execute()) {
            throw new Exception('Failed to delete readings: ' . $conn->error);
        }
        
        $deleted_count = $delete_stmt->affected_rows;
        
        echo json_encode([
            'success' => true,
            'message' => "Successfully deleted $deleted_count reading(s)",
            'deleted_count' => $deleted_count
        ]);
        
    } 
    // Handle single deletion
    else {
        // reading_id may come from form fields or JSON body
        $reading_id = null;
        if (isset($_POST['reading_id']) && $_POST['reading_id'] !== '') {
            $reading_id = intval($_POST['reading_id']);
        } elseif (isset($jsonData['reading_id']) && $jsonData['reading_id'] !== '') {
            $reading_id = intval($jsonData['reading_id']);
        }

        if ($reading_id === null) {
            throw new Exception('Reading ID(s) not provided');
        }
        
        if ($reading_id <= 0) {
            throw new Exception('Invalid reading ID');
        }

        // Check if delete actions are enabled in settings
        $settings_result = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('delete_enabled', 'delete_password')");
        $delete_enabled = false;
        $stored_hash = null;
        if ($settings_result) {
            while ($row = $settings_result->fetch_assoc()) {
                if ($row['setting_key'] === 'delete_enabled' && $row['setting_value'] === '1') {
                    $delete_enabled = true;
                }
                if ($row['setting_key'] === 'delete_password' && !empty($row['setting_value'])) {
                    $stored_hash = $row['setting_value'];
                }
            }
        }

        if (!$delete_enabled) {
            throw new Exception('Delete actions are currently disabled in settings.');
        }

        if (!$stored_hash) {
            throw new Exception('Delete password not configured. Please set it in Settings > Additional Fees.');
        }

        // Verify password provided in this request
        if ($provided_password === null || $provided_password === '') {
            throw new Exception('Delete password is required.');
        }

        if (!password_verify($provided_password, $stored_hash)) {
            throw new Exception('Incorrect delete password. Please try again.');
        }

        // First, get the reading to check if it exists and get the image path
        $stmt = $conn->prepare("SELECT id, image_path, status FROM pending_meter_readings WHERE id = ?");
        $stmt->bind_param("i", $reading_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $reading = $result->fetch_assoc();
        
        if (!$reading) {
            throw new Exception('Reading not found');
        }

        // Delete the image file if it exists
        if (!empty($reading['image_path'])) {
            $image_path = '../' . $reading['image_path'];
            // Also try without the ../
            $image_path_alt = $reading['image_path'];
            
            if (file_exists($image_path) && is_file($image_path)) {
                @unlink($image_path);
            } elseif (file_exists($image_path_alt) && is_file($image_path_alt)) {
                @unlink($image_path_alt);
            }
        }

        // Delete the reading record from database
        $delete_stmt = $conn->prepare("DELETE FROM pending_meter_readings WHERE id = ?");
        $delete_stmt->bind_param("i", $reading_id);
        
        if (!$delete_stmt->execute()) {
            throw new Exception('Failed to delete reading: ' . $conn->error);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Reading deleted successfully'
        ]);
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>

