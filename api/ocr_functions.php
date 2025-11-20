<?php
/**
 * OCR Functions for Server-Side Processing
 * This file contains OCR functions that can be used by both API and web interface
 * No API authentication required - functions only
 * 
 * Now uses Roboflow YOLOv8 for digit detection instead of Tesseract OCR
 */

/**
 * Preprocess image for better OCR accuracy
 * Enhances contrast, converts to grayscale, and sharpens
 */
function preprocessImageForOCR($imagePath) {
    if (!extension_loaded('gd')) {
        error_log('GD extension not loaded, skipping image preprocessing');
        return $imagePath; // Return original if GD not available
    }
    
    try {
        $imageInfo = @getimagesize($imagePath);
        if (!$imageInfo) {
            return $imagePath; // Invalid image, return original
        }
        
        $imageType = $imageInfo[2];
        $image = null;
        
        // Load image based on type
        switch ($imageType) {
            case IMAGETYPE_JPEG:
                $image = imagecreatefromjpeg($imagePath);
                break;
            case IMAGETYPE_PNG:
                $image = imagecreatefrompng($imagePath);
                break;
            case IMAGETYPE_GIF:
                $image = imagecreatefromgif($imagePath);
                break;
            default:
                return $imagePath; // Unsupported type
        }
        
        if (!$image) {
            return $imagePath;
        }
        
        // Convert to grayscale (better for OCR)
        imagefilter($image, IMG_FILTER_GRAYSCALE);
        
        // Enhance contrast
        imagefilter($image, IMG_FILTER_CONTRAST, -20);
        
        // Sharpen
        imagefilter($image, IMG_FILTER_SMOOTH, -2);
        
        // Save preprocessed image
        $preprocessedPath = $imagePath . '_preprocessed.jpg';
        imagejpeg($image, $preprocessedPath, 90);
        imagedestroy($image);
        
        return $preprocessedPath;
    } catch (Exception $e) {
        error_log('Image preprocessing failed: ' . $e->getMessage());
        return $imagePath; // Return original on error
    }
}

/**
 * Process image with Tesseract OCR (server-side)
 * Returns extracted text and meter reading
 */
function processImageWithTesseract($imagePath) {
    global $conn;
    
    // Preprocess image for better OCR accuracy
    $processedImagePath = preprocessImageForOCR($imagePath);
    $cleanupPreprocessed = ($processedImagePath !== $imagePath);
    
    // Check if Tesseract is installed
    // Try multiple possible installation paths
    $possiblePaths = [
        'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',  // 64-bit default
        'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',  // 32-bit
        'C:\\Tesseract-OCR\\tesseract.exe',  // Custom location
        'tesseract',  // From PATH (if added during installation)
    ];
    
    $tesseractPath = null;
    $tesseractFound = false;
    
    // Try each possible path
    foreach ($possiblePaths as $path) {
        // Check if file exists (for Windows paths)
        if (strpos($path, '\\') !== false && file_exists($path)) {
            $tesseractPath = $path;
            $tesseractFound = true;
            break;
        }
        
        // Test if command works (for PATH-based or Linux paths)
        $testCommand = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') 
            ? "\"$path\" --version 2>nul" 
            : "$path --version 2>/dev/null";
        
        $output = [];
        $returnVar = 0;
        @exec($testCommand, $output, $returnVar);
        
        if ($returnVar === 0 && !empty($output)) {
            $tesseractPath = $path;
            $tesseractFound = true;
            break;
        }
    }
    
    // If still not found, return error with helpful message
    if (!$tesseractFound) {
        return [
            'success' => false,
            'extracted_text' => '',
            'meter_reading' => null,
            'error' => 'Tesseract OCR is not installed. Please install from: https://github.com/UB-Mannheim/tesseract/wiki. Expected locations: C:\\Program Files\\Tesseract-OCR\\tesseract.exe'
        ];
    }
    
    // Prepare Tesseract command
    $outputFile = $imagePath . '_ocr_output';
    $language = 'eng'; // Can be changed to trained model like 'eng+watermeter'
    
    // Try multiple PSM modes for better accuracy
    // PSM 6: Assume uniform block of text
    // PSM 7: Treat image as single text line
    // PSM 8: Treat word as single word
    // PSM 11: Sparse text
    // PSM 13: Raw line (treat as single text line, no specific structure)
    $psmModes = [6, 7, 8, 11, 13];
    $extractedText = '';
    $errorOutput = '';
    $lastReturnVar = 0;
    
    foreach ($psmModes as $psmMode) {
        // Build command - use preprocessed image if available
        $imageToProcess = $processedImagePath;
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $command = escapeshellarg($tesseractPath) . ' ' . 
                       escapeshellarg($imageToProcess) . ' ' . 
                       escapeshellarg($outputFile) . ' -l ' . escapeshellarg($language) . ' --psm ' . $psmMode . ' 2>&1';
        } else {
            $command = escapeshellarg($tesseractPath) . ' ' . 
                       escapeshellarg($imageToProcess) . ' ' . 
                       escapeshellarg($outputFile) . ' -l ' . escapeshellarg($language) . ' --psm ' . $psmMode . ' 2>&1';
        }
        
        // Execute Tesseract OCR
        $output = [];
        $returnVar = 0;
        exec($command, $output, $returnVar);
        $lastReturnVar = $returnVar;
        $errorOutput .= "\nPSM $psmMode attempt: " . implode("\n", $output);
        
        // Read OCR result
        $resultFile = $outputFile . '.txt';
        if (file_exists($resultFile)) {
            $text = trim(file_get_contents($resultFile));
            unlink($resultFile); // Clean up
            
            if (!empty($text)) {
                $extractedText = $text;
                error_log("✓ Tesseract OCR successful with PSM mode $psmMode");
                break; // Success, stop trying other modes
            }
        }
    }
    
    // If still no text extracted, return error with details
    if (empty($extractedText)) {
        $errorMsg = 'No text extracted from image after trying PSM modes: ' . implode(', ', $psmModes);
        if ($lastReturnVar !== 0) {
            $errorMsg .= '. Tesseract exit code: ' . $lastReturnVar;
        }
        if (!empty($errorOutput)) {
            // Show last 300 chars of error output
            $errorMsg .= '. Last output: ' . substr(trim($errorOutput), -300);
        }
        if (!file_exists($imagePath)) {
            $errorMsg .= '. Image file does not exist at: ' . $imagePath;
        } else {
            // Check image file size
            $fileSize = filesize($imagePath);
            $errorMsg .= '. Image file exists (' . round($fileSize / 1024, 2) . ' KB)';
            
            // Check if image is readable
            $imageInfo = @getimagesize($imagePath);
            if ($imageInfo === false) {
                $errorMsg .= '. Image file is corrupted or not a valid image';
            } else {
                $errorMsg .= '. Image dimensions: ' . $imageInfo[0] . 'x' . $imageInfo[1];
            }
        }
        
        error_log('✗ Tesseract OCR failed: ' . $errorMsg);
        
        return [
            'success' => false,
            'extracted_text' => '',
            'meter_reading' => null,
            'error' => $errorMsg
        ];
    }
    
    // Clean up preprocessed image if created
    if ($cleanupPreprocessed && file_exists($processedImagePath)) {
        @unlink($processedImagePath);
    }
    
    // Extract meter reading from text
    $meterReading = extractMeterReadingFromText($extractedText);
    
    return [
        'success' => true,
        'extracted_text' => $extractedText,
        'meter_reading' => $meterReading
    ];
}

/**
 * Extract 5-digit meter reading from OCR text
 */
function extractMeterReadingFromText($text) {
    if (empty($text)) {
        return null;
    }
    
    // Normalize text - replace common OCR mistakes
    $normalized = $text;
    
    // Replace common OCR mistakes: O -> 0, I -> 1, l -> 1, S -> 5, Z -> 2
    $normalized = preg_replace('/\b([O])(?=\d)/i', '0', $normalized);
    $normalized = preg_replace('/\b([I])(?=\d)/i', '1', $normalized);
    $normalized = preg_replace('/\b([S])(?=\d)/i', '5', $normalized);
    $normalized = preg_replace('/\b([Z])(?=\d)/i', '2', $normalized);
    
    // Remove spaces between digits (e.g., "0 0 7 9 2" -> "00792")
    $normalized = preg_replace('/(\d)\s+(\d)/', '$1$2', $normalized);
    
    // Remove all non-digit characters except spaces and newlines (for line-by-line processing)
    // But keep digits together
    $normalized = preg_replace('/[^\d\s\n]/u', '', $normalized);
    
    // Try to find digits that might be split across lines or mixed with symbols
    $lines = explode("\n", $normalized);
    $allDigits = '';
    foreach ($lines as $line) {
        // Extract all digits from line, even if mixed with symbols
        $digits = preg_replace('/\D/', '', $line);
        $allDigits .= $digits;
    }
    
    // Also try extracting digits from the original text (before normalization)
    $originalDigits = preg_replace('/\D/', '', $text);
    
    // Combine both approaches
    $combinedDigits = $allDigits . $originalDigits;
    
    // If we found digits, try to extract 5-digit reading
    if (!empty($combinedDigits)) {
        // Look for 5 consecutive digits
        if (preg_match('/(\d{5})/', $combinedDigits, $matches)) {
            return $matches[1];
        }
        // Look for 4-6 digits and normalize
        if (preg_match('/(\d{4,6})/', $combinedDigits, $matches)) {
            $reading = $matches[1];
            if (strlen($reading) == 4) {
                $reading = '0' . $reading;
            } elseif (strlen($reading) == 6) {
                $reading = substr($reading, 0, 5);
            }
            if (strlen($reading) === 5) {
                return $reading;
            }
        }
    }
    
    // Last resort: Look for ANY sequence of 3-7 digits and try to normalize
    if (preg_match_all('/(\d{3,7})/', $text, $allNumberMatches)) {
        foreach ($allNumberMatches[1] as $num) {
            // Prefer numbers that look like meter readings (small values, start with 0)
            if (strlen($num) >= 3 && strlen($num) <= 7) {
                // Normalize to 5 digits
                $reading = str_pad($num, 5, '0', STR_PAD_LEFT);
                if (strlen($reading) > 5) {
                    $reading = substr($reading, 0, 5);
                }
                // Prefer readings that start with 0 or are small values
                if (substr($reading, 0, 1) === '0' || intval($reading) < 50000) {
                    return $reading;
                }
            }
        }
    }
    
    // Strategy 0: Look for any sequence of 4-6 digits (most flexible)
    // This catches readings even if OCR splits them slightly
    if (preg_match_all('/(\d{4,6})/', $normalized, $allMatches)) {
        foreach ($allMatches[1] as $match) {
            // Normalize to 5 digits
            $reading = $match;
            if (strlen($reading) == 4) {
                $reading = '0' . $reading;
            } elseif (strlen($reading) == 6) {
                $reading = substr($reading, 0, 5);
            }
            if (strlen($reading) === 5) {
                // Prefer readings that look like meter readings (starting with 0 or small values)
                if (substr($reading, 0, 1) === '0' || intval($reading) < 100000) {
                    return $reading;
                }
            }
        }
    }
    
    // Strategy 1: Look for 5 consecutive digits (most common pattern)
    if (preg_match('/(\d{5})/', $normalized, $matches)) {
        $reading = $matches[1];
        // Verify it's a reasonable reading (00000-99999)
        if (strlen($reading) === 5) {
            return $reading;
        }
    }
    
    // Strategy 2: Look for 5-digit number beside "m³" or "m3"
    $pattern1 = '/(\d{5})\s*(?:m[³3]|m\s*cubed|m\s*3)/i';
    if (preg_match($pattern1, $normalized, $matches)) {
        return str_pad($matches[1], 5, '0', STR_PAD_LEFT);
    }
    
    // Strategy 3: Look for pattern "m³" followed by 5 digits
    $pattern2 = '/(?:m[³3]|m\s*cubed|m\s*3)\s*(\d{5})/i';
    if (preg_match($pattern2, $normalized, $matches)) {
        return str_pad($matches[1], 5, '0', STR_PAD_LEFT);
    }
    
    // Strategy 4: Look for 4-6 digit numbers and normalize to 5
    if (preg_match('/(\d{4,6})/', $normalized, $matches)) {
        $reading = $matches[1];
        // Normalize to 5 digits
        if (strlen($reading) == 4) {
            $reading = '0' . $reading;
        } elseif (strlen($reading) == 6) {
            $reading = substr($reading, 0, 5);
        }
        if (strlen($reading) === 5) {
            return $reading;
        }
    }
    
    // Strategy 5: Look for patterns like "00792" with OCR errors (O instead of 0)
    if (preg_match('/([O0]{1,2}\d{3,4})/i', $text, $matches)) {
        $reading = str_replace(['O', 'o'], '0', $matches[1]);
        $reading = preg_replace('/\D/', '', $reading); // Remove non-digits
        if (strlen($reading) >= 4 && strlen($reading) <= 6) {
            $reading = str_pad($reading, 5, '0', STR_PAD_LEFT);
            if (strlen($reading) === 5) {
                return $reading;
            }
        }
    }
    
    // Strategy 6: Look for 5-digit number in lines containing "m³"
    $lines = explode("\n", $normalized);
    foreach ($lines as $line) {
        if (preg_match('/m[³3]|m\s*3/i', $line)) {
            if (preg_match('/(\d{4,6})/', $line, $matches)) {
                $reading = $matches[1];
                if (strlen($reading) == 4) {
                    $reading = '0' . $reading;
                } elseif (strlen($reading) == 6) {
                    $reading = substr($reading, 0, 5);
                }
                if (strlen($reading) === 5) {
                    return $reading;
                }
            }
        }
    }
    
    // Strategy 7: Extract all numbers and find the one that looks like a meter reading
    preg_match_all('/\d+/', $text, $allNumbers);
    foreach ($allNumbers[0] as $num) {
        if (strlen($num) >= 4 && strlen($num) <= 6) {
            $reading = str_pad($num, 5, '0', STR_PAD_LEFT);
            if (strlen($reading) === 5) {
                // Prefer numbers that start with 0 (like 00792)
                if (substr($reading, 0, 1) === '0') {
                    return $reading;
                }
            }
        }
    }
    
    // Strategy 8: Return the first 5-digit number found
    if (preg_match('/(\d{5})/', $text, $matches)) {
        return $matches[1];
    }
    
    return null;
}

/**
 * Process image with Roboflow YOLOv8 digit detection (NEW METHOD)
 * Replaces Tesseract OCR with Roboflow API for better accuracy and Verpex hosting compatibility
 * @param string $imagePath Full path to image file
 * @return array ['success' => bool, 'extracted_text' => string, 'meter_reading' => string|null, 'error' => string]
 */
function processImageWithRoboflowDigits($imagePath) {
    // Include Roboflow service functions
    if (!function_exists('detectDigitsWithRoboflow')) {
        require_once __DIR__ . '/roboflow_service.php';
    }
    
    if (!file_exists($imagePath)) {
        return [
            'success' => false,
            'extracted_text' => '',
            'meter_reading' => null,
            'error' => 'Image file not found: ' . $imagePath
        ];
    }
    
    try {
        error_log("=== ROBOFLOW OCR PROCESSING START ===");
        error_log("Image path: $imagePath");
        error_log("Image exists: " . (file_exists($imagePath) ? 'Yes' : 'No'));
        if (file_exists($imagePath)) {
            error_log("Image size: " . filesize($imagePath) . " bytes");
            $imgInfo = @getimagesize($imagePath);
            if ($imgInfo) {
                error_log("Image dimensions: " . $imgInfo[0] . "x" . $imgInfo[1]);
            }
        }
        
        // Step 1: Detect digits using Roboflow API (YOLOv8)
        error_log("Calling detectDigitsWithRoboflow() (YOLOv8)...");
        $startTime = microtime(true);
        $digitResult = detectDigitsWithRoboflow($imagePath);
        $elapsedTime = microtime(true) - $startTime;
        
        error_log("detectDigitsWithRoboflow() returned in " . round($elapsedTime, 2) . "s:");
        error_log("  success: " . ($digitResult['success'] ? 'true' : 'false'));
        error_log("  digits count: " . count($digitResult['digits'] ?? []));
        error_log("  message: " . ($digitResult['message'] ?? 'N/A'));
        if (isset($digitResult['api_response'])) {
            error_log("  api_response keys: " . implode(', ', array_keys($digitResult['api_response'])));
        }
        
        // If Roboflow took too long (>10s) or failed, return quickly for Tesseract fallback
        if ($elapsedTime > 10) {
            error_log('⚠ Roboflow took too long (' . round($elapsedTime, 2) . 's), skipping for Tesseract fallback');
            return [
                'success' => false,
                'extracted_text' => '',
                'meter_reading' => null,
                'error' => 'Roboflow API timeout (>10s)'
            ];
        }
        
        if (!$digitResult['success']) {
            $errorMsg = 'Roboflow digit detection failed';
            if (isset($digitResult['message']) && !empty($digitResult['message'])) {
                $errorMsg .= ': ' . $digitResult['message'];
            }
            error_log('✗ Roboflow OCR: ' . $errorMsg);
            error_log("=== ROBOFLOW OCR PROCESSING END (FAILED) ===");
            error_log("NOTE: Roboflow YOLOv8 is the only OCR method - no fallback");
            // Return failure - let calling function handle Tesseract fallback
            return [
                'success' => false,
                'extracted_text' => '',
                'meter_reading' => null,
                'error' => $errorMsg
            ];
        }
        
        $digits = $digitResult['digits'] ?? [];
        error_log("Digits array count: " . count($digits));
        
        if (empty($digits)) {
            $errorMsg = 'No digits detected in image';
            if (isset($digitResult['message']) && !empty($digitResult['message'])) {
                $errorMsg .= ': ' . $digitResult['message'];
            }
            if (isset($digitResult['all_predictions']) && !empty($digitResult['all_predictions'])) {
                error_log("⚠ Found " . count($digitResult['all_predictions']) . " predictions but none were valid digits");
                foreach ($digitResult['all_predictions'] as $idx => $pred) {
                    error_log("  Prediction #$idx: " . json_encode($pred));
                }
            }
            error_log('✗ Roboflow OCR: ' . $errorMsg);
            error_log("=== ROBOFLOW OCR PROCESSING END (NO DIGITS) ===");
            return [
                'success' => false,
                'extracted_text' => '',
                'meter_reading' => null,
                'error' => $errorMsg
            ];
        }
        
        // Step 2: Extract meter reading from detected digits
        if (!function_exists('extractMeterReadingFromDigits')) {
            require_once __DIR__ . '/roboflow_service.php';
        }
        
        $meterReading = extractMeterReadingFromDigits($digits);
        
        // Create extracted text representation (for compatibility)
        $extractedText = 'Detected digits: ';
        foreach ($digits as $digit) {
            $extractedText .= $digit['digit'] . ' (confidence: ' . round($digit['confidence'], 2) . ', x: ' . round($digit['x']) . ') ';
        }
        
        if ($meterReading) {
            error_log("✓ Roboflow OCR: Successfully extracted reading: $meterReading");
            return [
                'success' => true,
                'extracted_text' => $extractedText,
                'meter_reading' => $meterReading
            ];
        } else {
            $errorMsg = 'Could not form 5-digit reading from detected digits. Found ' . count($digits) . ' digit(s): ' . implode(', ', array_map(function($d) { return $d['digit']; }, $digits));
            error_log('✗ Roboflow OCR: ' . $errorMsg);
            return [
                'success' => false,
                'extracted_text' => $extractedText,
                'meter_reading' => null,
                'error' => $errorMsg
            ];
        }
        
    } catch (Exception $e) {
        $errorMsg = 'Roboflow OCR processing error: ' . $e->getMessage();
        error_log('✗ Roboflow OCR Exception: ' . $errorMsg);
        return [
            'success' => false,
            'extracted_text' => '',
            'meter_reading' => null,
            'error' => $errorMsg
        ];
    }
}

