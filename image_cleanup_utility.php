<?php
/**
 * Image Cleanup Utility
 * Deletes meter reading images after they are successfully processed
 */

/**
 * Delete meter reading image file
 * @param string $image_path The image path stored in database (relative or absolute)
 * @return bool True if deleted successfully, false otherwise
 */
function deleteMeterReadingImage($image_path) {
    if (empty($image_path)) {
        return false;
    }
    
    $deleted = false;
    $possiblePaths = [];
    
    // Try different path variations
    if (strpos($image_path, '/') === 0 || strpos($image_path, 'C:') === 0 || strpos($image_path, 'D:') === 0) {
        // Absolute path
        $possiblePaths[] = $image_path;
        $possiblePaths[] = realpath($image_path);
    } else {
        // Relative path - try multiple variations
        $possiblePaths[] = __DIR__ . '/' . $image_path;
        $possiblePaths[] = __DIR__ . '/../' . $image_path;
        $possiblePaths[] = $image_path;
        
        // If path doesn't start with uploads/, add it
        if (strpos($image_path, 'uploads/') !== 0) {
            $possiblePaths[] = __DIR__ . '/uploads/meter_readings/' . basename($image_path);
        }
    }
    
    // Try to delete from any of the possible paths
    foreach ($possiblePaths as $path) {
        if ($path && file_exists($path) && is_file($path)) {
            if (@unlink($path)) {
                $deleted = true;
                error_log("✓ Deleted meter reading image: $path");
                break;
            }
        }
    }
    
    if (!$deleted) {
        error_log("⚠ Could not delete meter reading image. Tried paths: " . implode(', ', array_filter($possiblePaths)));
    }
    
    return $deleted;
}

/**
 * Delete image after reading is successfully processed
 * @param int $reading_id The reading ID
 * @param object $conn Database connection
 * @return bool True if image was deleted, false otherwise
 */
function deleteImageAfterProcessing($reading_id, $conn) {
    try {
        // Get the image path from database
        $stmt = $conn->prepare("SELECT image_path FROM pending_meter_readings WHERE id = ?");
        $stmt->bind_param("i", $reading_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $row = $result->fetch_assoc()) {
            $image_path = $row['image_path'] ?? null;
            if ($image_path) {
                return deleteMeterReadingImage($image_path);
            }
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Error deleting image after processing reading ID $reading_id: " . $e->getMessage());
        return false;
    }
}
?>

