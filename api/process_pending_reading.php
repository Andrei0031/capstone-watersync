<?php
require_once __DIR__ . '/../timezone_helper.php';
watersync_force_timezone();

session_start();
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Session expired. Please refresh and log in again.'
    ]);
    exit();
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/roboflow_service.php';
require_once __DIR__ . '/ocr_functions.php';

header('Content-Type: application/json');

if (!isset($_POST['reading_id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Missing reading_id.'
    ]);
    exit();
}

$reading_id = (int)$_POST['reading_id'];

function processPendingReading($conn, $reading_id) {
    $stmt = $conn->prepare("SELECT * FROM pending_meter_readings WHERE id = ? AND status = 'pending'");
    $stmt->bind_param("i", $reading_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $reading = $result->fetch_assoc();

    if (!$reading) {
        return [
            'success' => false,
            'status' => 'skipped',
            'message' => 'Reading not found or not pending.',
            'reading_id' => $reading_id
        ];
    }

    try {
        $storedPath = $reading['image_path'];
        if (strpos($storedPath, '/') !== 0 && strpos($storedPath, '\\') !== 0 && strpos($storedPath, 'C:') !== 0) {
            $possiblePaths = [
                __DIR__ . '/../' . $storedPath,
                realpath(__DIR__ . '/../' . $storedPath),
                $storedPath,
            ];
            if (strpos($storedPath, '../') === false) {
                $possiblePaths[] = __DIR__ . '/../../' . $storedPath;
            }
        } else {
            $possiblePaths = [$storedPath, realpath($storedPath)];
        }

        $imagePath = null;
        foreach ($possiblePaths as $path) {
            if ($path && file_exists($path)) {
                $imagePath = $path;
                break;
            }
        }

        if (!$imagePath || !file_exists($imagePath)) {
            throw new Exception('Image file not found. Tried: ' . implode(', ', $possiblePaths) . '. Stored path: ' . $storedPath);
        }

        $croppedImagePath = $imagePath;
        $roboflowError = null;

        try {
            if (function_exists('detectAndCropMeterWithRoboflow')) {
                $croppedImagePath = detectAndCropMeterWithRoboflow($imagePath);
                if ($croppedImagePath === null || !file_exists($croppedImagePath)) {
                    $croppedImagePath = $imagePath;
                }
            }
        } catch (Exception $e) {
            $roboflowError = 'Roboflow crop error: ' . $e->getMessage();
            $croppedImagePath = $imagePath;
        }

        $ocrProcessed = false;
        $ocrReading = null;
        $extractedText = '';
        $ocrError = null;
        $ocrResult = null;

        if ($croppedImagePath !== $imagePath && file_exists($croppedImagePath)) {
            $ocrResult = processImageWithRoboflowDigits($croppedImagePath);
            if ($ocrResult['success'] && !empty($ocrResult['meter_reading'])) {
                $ocrReading = !empty($ocrResult['is_provisional']) ? null : $ocrResult['meter_reading'];
                $extractedText = $ocrResult['extracted_text'] ?? '';
                $ocrProcessed = true;
            } else {
                $ocrError = $ocrResult['error'] ?? 'Roboflow OCR failed on cropped image';
            }
        }

        if (!$ocrProcessed && file_exists($imagePath)) {
            $ocrResult = processImageWithRoboflowDigits($imagePath);
            if ($ocrResult['success'] && !empty($ocrResult['meter_reading'])) {
                $ocrReading = !empty($ocrResult['is_provisional']) ? null : $ocrResult['meter_reading'];
                $extractedText = $ocrResult['extracted_text'] ?? '';
                $ocrProcessed = true;
            } else {
                $ocrError = $ocrResult['error'] ?? 'Roboflow OCR failed on original image';
            }
        }

        if (!$ocrProcessed) {
            $errorMsg = 'Roboflow OCR failed. ' . ($ocrError ?: 'No OCR result.') . ' ' . ($roboflowError ?: '');
            throw new Exception(trim($errorMsg));
        }

        $statusToSet = 'needs_review';
        $digitStats = $ocrResult['digit_stats'] ?? null;
        if ($digitStats) {
            $digitCount = (int)($digitStats['count'] ?? 0);
            $minConf = (float)($digitStats['min_confidence'] ?? 0.0);
            if ($digitCount >= 5 && $minConf >= 0.5) {
                $statusToSet = 'verified';
            }
        }

        $processedAt = date('Y-m-d H:i:s');
        $update = $conn->prepare("UPDATE pending_meter_readings SET 
            status = ?,
            ocr_reading = ?,
            extracted_text = ?,
            processed_at = ?
            WHERE id = ?");
        $update->bind_param("sdssi", $statusToSet, $ocrReading, $extractedText, $processedAt, $reading_id);

        if (!$update->execute()) {
            throw new Exception('Failed to update database: ' . $conn->error);
        }

        return [
            'success' => true,
            'status' => $statusToSet,
            'message' => 'Reading processed successfully.',
            'reading_id' => $reading_id
        ];
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
        $failedProcessedAt = date('Y-m-d H:i:s');
        $update = $conn->prepare("UPDATE pending_meter_readings SET 
            status = 'failed',
            admin_notes = ?,
            processed_at = ?
            WHERE id = ?");
        $update->bind_param("ssi", $error_msg, $failedProcessedAt, $reading_id);
        $update->execute();

        return [
            'success' => false,
            'status' => 'failed',
            'message' => $error_msg,
            'reading_id' => $reading_id
        ];
    }
}

$result = processPendingReading($conn, $reading_id);
if (!$result['success']) {
    http_response_code(400);
}

echo json_encode($result);
