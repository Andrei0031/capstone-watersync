<?php
/**
 * Roboflow Service for Server-Side Meter Region Detection and Digit Recognition
 * Uses Roboflow API to:
 * 1. Detect and crop meter reading regions
 * 2. Detect individual digits (0-9) for OCR
 */

// Roboflow API Configuration
define('ROBOFLOW_API_KEY', 'plVsmWuM0KjEA8Pz6RqB'); // Private API Key
define('ROBOFLOW_WORKSPACE', 'watersync');

// Meter Detection Model Configuration
define('ROBOFLOW_PROJECT', 'watersync-oekrf');
define('ROBOFLOW_MODEL_VERSION', '3'); // Version number for "instant-3" model
define('ROBOFLOW_INFERENCE_URL', 'https://detect.roboflow.com/' . ROBOFLOW_WORKSPACE . '/' . ROBOFLOW_PROJECT . '/' . ROBOFLOW_MODEL_VERSION . '?api_key=' . ROBOFLOW_API_KEY);

// Digit Detection Model Configuration
// Using model_id format from Roboflow "Hosted Image Inference"
// Format: "project-name/version" (e.g., "watersync-oekrf/2")
define('ROBOFLOW_DIGIT_MODEL_ID', 'watersync-oekrf/2'); // Your digit detection model_id from Roboflow

// Option 2: Use separate project and version (alternative format)
define('ROBOFLOW_DIGIT_PROJECT', 'watersync-digits'); // Change this to your digit detection project name
define('ROBOFLOW_DIGIT_MODEL_VERSION', '1'); // Change this to your digit model version

// Build inference URL - supports both detect.roboflow.com and serverless formats
// If model_id is set, use it; otherwise use project/version format
if (defined('ROBOFLOW_DIGIT_MODEL_ID') && !empty(ROBOFLOW_DIGIT_MODEL_ID)) {
    // Use model_id format (serverless API)
    define('ROBOFLOW_DIGIT_INFERENCE_URL', 'https://detect.roboflow.com/' . ROBOFLOW_DIGIT_MODEL_ID . '?api_key=' . ROBOFLOW_API_KEY);
} else {
    // Use project/version format (legacy)
    define('ROBOFLOW_DIGIT_INFERENCE_URL', 'https://detect.roboflow.com/' . ROBOFLOW_WORKSPACE . '/' . ROBOFLOW_DIGIT_PROJECT . '/' . ROBOFLOW_DIGIT_MODEL_VERSION . '?api_key=' . ROBOFLOW_API_KEY);
}

/**
 * Detect meter region using Roboflow API
 * @param string $imagePath Full path to image file
 * @return array ['success' => bool, 'detection' => array|null, 'message' => string]
 */
function detectMeterRegionWithRoboflow($imagePath) {
    if (!file_exists($imagePath)) {
        return [
            'success' => false,
            'detection' => null,
            'message' => 'Image file not found'
        ];
    }
    
    // Check if API key is configured
    if (ROBOFLOW_API_KEY === 'YOUR_ROBOFLOW_API_KEY') {
        return [
            'success' => false,
            'detection' => null,
            'message' => 'Roboflow API key not configured'
        ];
    }
    
    try {
        error_log("Roboflow: Calling API for image: $imagePath");
        error_log("Roboflow: API URL: " . ROBOFLOW_INFERENCE_URL);
        error_log("Roboflow: Workspace: " . ROBOFLOW_WORKSPACE . ", Project: " . ROBOFLOW_PROJECT . ", Version: " . ROBOFLOW_MODEL_VERSION);
        
        // Prepare multipart form data
        $imageData = file_get_contents($imagePath);
        if (!$imageData) {
            return [
                'success' => false,
                'detection' => null,
                'message' => 'Failed to read image file'
            ];
        }
        
        $boundary = uniqid();
        $delimiter = '-------------' . $boundary;
        
        $postData = '';
        $postData .= '--' . $delimiter . "\r\n";
        $postData .= 'Content-Disposition: form-data; name="file"; filename="meter_image.jpg"' . "\r\n";
        $postData .= 'Content-Type: image/jpeg' . "\r\n\r\n";
        $postData .= $imageData . "\r\n";
        $postData .= '--' . $delimiter . "\r\n";
        $postData .= 'Content-Disposition: form-data; name="confidence"' . "\r\n\r\n";
        $postData .= '0.3' . "\r\n"; // Lower confidence threshold
        $postData .= '--' . $delimiter . "\r\n";
        $postData .= 'Content-Disposition: form-data; name="overlap"' . "\r\n\r\n";
        $postData .= '0.5' . "\r\n";
        $postData .= '--' . $delimiter . '--';
        
        // Initialize cURL
        // Roboflow API requires POST with multipart/form-data
        // API key is in URL as query parameter
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, ROBOFLOW_INFERENCE_URL);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: multipart/form-data; boundary=' . $delimiter
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        error_log("Roboflow: Sending request to API...");
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        error_log("Roboflow: HTTP Response Code: $httpCode");
        if ($error) {
            error_log("Roboflow: cURL Error: $error");
        }
        
        if ($error) {
            return [
                'success' => false,
                'detection' => null,
                'message' => 'Roboflow API error: ' . $error
            ];
        }
        
        if ($httpCode !== 200) {
            $errorMsg = 'Roboflow API error: HTTP ' . $httpCode;
            if ($httpCode === 401) {
                $errorMsg .= ' - Unauthorized. Check your API key in roboflow_service.php';
            } elseif ($httpCode === 404) {
                $errorMsg .= ' - Not found. Check workspace/project/model version. Model may need to be deployed first.';
            } elseif ($httpCode === 405) {
                $errorMsg .= ' - Method Not Allowed. The model may not be deployed yet. Please deploy your model in Roboflow dashboard: Go to your project → Deploy → "Integrate with my app or website" → Copy the endpoint URL and update ROBOFLOW_INFERENCE_URL in roboflow_service.php';
            } else {
                $errorMsg .= ' - Response: ' . substr($response, 0, 200);
            }
            error_log('✗ Roboflow API HTTP Error: ' . $errorMsg);
            error_log('✗ Full response: ' . $response);
            return [
                'success' => false,
                'detection' => null,
                'message' => $errorMsg
            ];
        }
        
        $data = json_decode($response, true);
        
        if (!$data) {
            return [
                'success' => false,
                'detection' => null,
                'message' => 'Invalid response from Roboflow API'
            ];
        }
        
        // Parse detections
        $predictions = isset($data['predictions']) ? $data['predictions'] : [];
        
        error_log('Roboflow API response: ' . json_encode($data));
        error_log('Number of predictions: ' . count($predictions));
        
        // Find the "WaterMeter" detection (highest confidence)
        $bestDetection = null;
        $bestConfidence = 0.0;
        
        foreach ($predictions as $prediction) {
            $className = isset($prediction['class']) ? strtolower($prediction['class']) : '';
            $confidence = isset($prediction['confidence']) ? floatval($prediction['confidence']) : 0.0;
            
            error_log("Roboflow prediction: class='$className', confidence=$confidence");
            
            // Accept any detection with confidence > 0.3 (lowered threshold)
            // Check for class name "Watersync" (case-insensitive) and other meter-related names
            $isMeterClass = (
                strpos($className, 'watersync') !== false || 
                strpos($className, 'watermeter') !== false || 
                strpos($className, 'meter') !== false ||
                strpos($className, 'water') !== false ||
                strpos($className, 'reading') !== false
            );
            
            // Accept if it's a meter class OR has good confidence
            if ($isMeterClass || $confidence > 0.3) {
                if ($confidence > $bestConfidence) {
                    $bestConfidence = $confidence;
                    $bestDetection = [
                        'x' => isset($prediction['x']) ? floatval($prediction['x']) : 0,
                        'y' => isset($prediction['y']) ? floatval($prediction['y']) : 0,
                        'width' => isset($prediction['width']) ? floatval($prediction['width']) : 0,
                        'height' => isset($prediction['height']) ? floatval($prediction['height']) : 0,
                        'confidence' => $confidence,
                        'class' => isset($prediction['class']) ? $prediction['class'] : ''
                    ];
                }
            }
        }
        
        if ($bestDetection) {
            error_log('✓ Roboflow: Best detection found - Class: ' . $bestDetection['class'] . ', Confidence: ' . $bestConfidence);
            return [
                'success' => true,
                'detection' => $bestDetection,
                'all_detections' => $predictions
            ];
        } else {
            $errorMsg = 'No water meter region detected in image';
            if (count($predictions) > 0) {
                $classNames = [];
                foreach ($predictions as $pred) {
                    $classNames[] = ($pred['class'] ?? 'unknown') . ' (' . ($pred['confidence'] ?? 0) . ')';
                }
                $errorMsg .= '. Found ' . count($predictions) . ' detection(s): ' . implode(', ', $classNames) . ' but none matched meter criteria (looking for: Watersync, WaterMeter, meter, water, reading)';
            } else {
                $errorMsg .= '. No detections returned from Roboflow API';
            }
            error_log('✗ Roboflow: ' . $errorMsg);
            return [
                'success' => false,
                'detection' => null,
                'message' => $errorMsg,
                'all_detections' => $predictions
            ];
        }
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'detection' => null,
            'message' => 'Roboflow detection error: ' . $e->getMessage()
        ];
    }
}

/**
 * Crop image to detected meter region
 * @param string $originalImagePath Full path to original image
 * @param array $detection Detection data from Roboflow
 * @return string|null Path to cropped image, or null on failure
 */
function cropMeterRegion($originalImagePath, $detection) {
    if (!file_exists($originalImagePath)) {
        return null;
    }
    
    try {
        // Load image
        $imageInfo = getimagesize($originalImagePath);
        if (!$imageInfo) {
            return null;
        }
        
        $imageType = $imageInfo[2];
        $imageWidth = $imageInfo[0];
        $imageHeight = $imageInfo[1];
        
        // Create image resource based on type
        switch ($imageType) {
            case IMAGETYPE_JPEG:
                $image = imagecreatefromjpeg($originalImagePath);
                break;
            case IMAGETYPE_PNG:
                $image = imagecreatefrompng($originalImagePath);
                break;
            case IMAGETYPE_GIF:
                $image = imagecreatefromgif($originalImagePath);
                break;
            default:
                return null;
        }
        
        if (!$image) {
            return null;
        }
        
        // Get detection coordinates
        // Roboflow returns center coordinates, convert to top-left
        $centerX = floatval($detection['x']);
        $centerY = floatval($detection['y']);
        $width = floatval($detection['width']);
        $height = floatval($detection['height']);
        
        // Calculate crop coordinates (with padding)
        $padding = 20; // Add padding around detection
        $x = max(0, intval($centerX - $width / 2 - $padding));
        $y = max(0, intval($centerY - $height / 2 - $padding));
        $cropWidth = min($imageWidth - $x, intval($width + $padding * 2));
        $cropHeight = min($imageHeight - $y, intval($height + $padding * 2));
        
        // Crop image
        $croppedImage = imagecrop($image, [
            'x' => $x,
            'y' => $y,
            'width' => $cropWidth,
            'height' => $cropHeight
        ]);
        
        if (!$croppedImage) {
            imagedestroy($image);
            return null;
        }
        
        // Save cropped image
        $croppedPath = $originalImagePath . '_cropped.jpg';
        imagejpeg($croppedImage, $croppedPath, 95);
        
        // Clean up
        imagedestroy($image);
        imagedestroy($croppedImage);
        
        return $croppedPath;
        
    } catch (Exception $e) {
        error_log('Crop error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Complete workflow: Detect meter region and crop image
 * @param string $imagePath Full path to image file
 * @return string|null Path to cropped image, or original path if detection fails
 */
function detectAndCropMeterWithRoboflow($imagePath) {
    // Step 1: Detect meter region
    error_log("Roboflow: Starting detection for image: $imagePath");
    $detectionResult = detectMeterRegionWithRoboflow($imagePath);
    
    if (!$detectionResult['success']) {
        $errorMsg = $detectionResult['message'] ?? 'Unknown error';
        error_log('✗ Roboflow detection failed: ' . $errorMsg);
        return $imagePath; // Fallback to original image
    }
    
    $detection = $detectionResult['detection'];
    if (!$detection) {
        error_log('✗ Roboflow: Detection succeeded but no detection data returned');
        return $imagePath;
    }
    
    error_log('✓ Roboflow: Detection successful. Confidence: ' . ($detection['confidence'] ?? 'N/A'));
    
    // Step 2: Crop to detected region
    $croppedPath = cropMeterRegion($imagePath, $detection);
    
    if ($croppedPath && file_exists($croppedPath)) {
        error_log('✓ Roboflow: Image cropped successfully to: ' . $croppedPath);
        return $croppedPath;
    } else {
        error_log('✗ Roboflow cropping failed, using original image. Cropped path: ' . ($croppedPath ?? 'null'));
        return $imagePath; // Fallback to original image
    }
}

/**
 * Detect digits (0-9) in image using Roboflow API
 * @param string $imagePath Full path to image file
 * @return array ['success' => bool, 'digits' => array, 'message' => string]
 */
function detectDigitsWithRoboflow($imagePath) {
    if (!file_exists($imagePath)) {
        return [
            'success' => false,
            'digits' => [],
            'message' => 'Image file not found'
        ];
    }
    
    // Check if API key is configured
    if (ROBOFLOW_API_KEY === 'YOUR_ROBOFLOW_API_KEY') {
        return [
            'success' => false,
            'digits' => [],
            'message' => 'Roboflow API key not configured'
        ];
    }
    
    // Check if digit detection project is configured
    if (ROBOFLOW_DIGIT_PROJECT === 'watersync-digits' && !defined('ROBOFLOW_DIGIT_PROJECT_CONFIGURED')) {
        // This is a placeholder - user needs to update with actual project name
        error_log('⚠ Roboflow Digit Detection: Project not configured. Please update ROBOFLOW_DIGIT_PROJECT and ROBOFLOW_DIGIT_MODEL_VERSION in roboflow_service.php');
    }
    
    try {
        error_log("Roboflow Digit Detection: Calling API for image: $imagePath");
        error_log("Roboflow Digit Detection: API URL: " . ROBOFLOW_DIGIT_INFERENCE_URL);
        
        // Prepare multipart form data
        $imageData = file_get_contents($imagePath);
        if (!$imageData) {
            return [
                'success' => false,
                'digits' => [],
                'message' => 'Failed to read image file'
            ];
        }
        
        $boundary = uniqid();
        $delimiter = '-------------' . $boundary;
        
        $postData = '';
        $postData .= '--' . $delimiter . "\r\n";
        $postData .= 'Content-Disposition: form-data; name="file"; filename="meter_image.jpg"' . "\r\n";
        $postData .= 'Content-Type: image/jpeg' . "\r\n\r\n";
        $postData .= $imageData . "\r\n";
        $postData .= '--' . $delimiter . "\r\n";
        $postData .= 'Content-Disposition: form-data; name="confidence"' . "\r\n\r\n";
        $postData .= '0.3' . "\r\n"; // Lower confidence threshold for digits
        $postData .= '--' . $delimiter . "\r\n";
        $postData .= 'Content-Disposition: form-data; name="overlap"' . "\r\n\r\n";
        $postData .= '0.5' . "\r\n";
        $postData .= '--' . $delimiter . '--';
        
        // Initialize cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, ROBOFLOW_DIGIT_INFERENCE_URL);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: multipart/form-data; boundary=' . $delimiter
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        error_log("Roboflow Digit Detection: Sending request to API...");
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        error_log("Roboflow Digit Detection: HTTP Response Code: $httpCode");
        if ($error) {
            error_log("Roboflow Digit Detection: cURL Error: $error");
        }
        
        if ($error) {
            return [
                'success' => false,
                'digits' => [],
                'message' => 'Roboflow API error: ' . $error
            ];
        }
        
        if ($httpCode !== 200) {
            $errorMsg = 'Roboflow Digit Detection API error: HTTP ' . $httpCode;
            if ($httpCode === 401) {
                $errorMsg .= ' - Unauthorized. Check your API key in roboflow_service.php';
            } elseif ($httpCode === 404) {
                $errorMsg .= ' - Not found. Check workspace/project/model version. Model may need to be deployed first.';
            } elseif ($httpCode === 405) {
                $errorMsg .= ' - Method Not Allowed. The digit detection model may not be deployed yet. Please deploy your model in Roboflow dashboard.';
            } else {
                $errorMsg .= ' - Response: ' . substr($response, 0, 200);
            }
            error_log('✗ Roboflow Digit Detection API HTTP Error: ' . $errorMsg);
            return [
                'success' => false,
                'digits' => [],
                'message' => $errorMsg
            ];
        }
        
        $data = json_decode($response, true);
        
        if (!$data) {
            return [
                'success' => false,
                'digits' => [],
                'message' => 'Invalid response from Roboflow Digit Detection API'
            ];
        }
        
        // Parse digit detections
        $predictions = isset($data['predictions']) ? $data['predictions'] : [];
        
        error_log('Roboflow Digit Detection API response: ' . json_encode($data));
        error_log('Number of digit predictions: ' . count($predictions));
        
        // Extract digits with their positions
        $digits = [];
        foreach ($predictions as $prediction) {
            $className = isset($prediction['class']) ? trim($prediction['class']) : '';
            $confidence = isset($prediction['confidence']) ? floatval($prediction['confidence']) : 0.0;
            
            // Validate that it's a digit (0-9) with higher confidence threshold
            if (preg_match('/^[0-9]$/', $className) && $confidence > 0.35) {
                $digits[] = [
                    'digit' => $className,
                    'x' => isset($prediction['x']) ? floatval($prediction['x']) : 0,
                    'y' => isset($prediction['y']) ? floatval($prediction['y']) : 0,
                    'width' => isset($prediction['width']) ? floatval($prediction['width']) : 0,
                    'height' => isset($prediction['height']) ? floatval($prediction['height']) : 0,
                    'confidence' => $confidence
                ];
                error_log("Roboflow Digit Detection: Found digit '$className' with confidence $confidence at position ({$digits[count($digits)-1]['x']}, {$digits[count($digits)-1]['y']})");
            }
        }
        
        if (count($digits) > 0) {
            error_log('✓ Roboflow Digit Detection: Found ' . count($digits) . ' digit(s)');
            return [
                'success' => true,
                'digits' => $digits,
                'all_predictions' => $predictions
            ];
        } else {
            $errorMsg = 'No digits detected in image';
            if (count($predictions) > 0) {
                $classNames = [];
                foreach ($predictions as $pred) {
                    $classNames[] = ($pred['class'] ?? 'unknown') . ' (' . ($pred['confidence'] ?? 0) . ')';
                }
                $errorMsg .= '. Found ' . count($predictions) . ' detection(s): ' . implode(', ', $classNames) . ' but none were valid digits (0-9)';
            } else {
                $errorMsg .= '. No detections returned from Roboflow API';
            }
            error_log('✗ Roboflow Digit Detection: ' . $errorMsg);
            return [
                'success' => false,
                'digits' => [],
                'message' => $errorMsg,
                'all_predictions' => $predictions
            ];
        }
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'digits' => [],
            'message' => 'Roboflow digit detection error: ' . $e->getMessage()
        ];
    }
}

/**
 * Extract 5-digit meter reading from detected digits
 * Improved algorithm that handles:
 * - Multi-row digit displays
 * - Overlapping detections (deduplication)
 * - Better sorting by Y position first, then X position
 * - Confidence filtering
 * @param array $digits Array of digit detections from Roboflow
 * @return string|null 5-digit reading or null if not enough digits
 */
function extractMeterReadingFromDigits($digits) {
    if (empty($digits) || count($digits) < 3) {
        return null;
    }
    
    // Step 1: Filter digits by confidence (remove low-confidence detections)
    $minConfidence = 0.4; // Increased from 0.3 for better accuracy
    $filteredDigits = array_filter($digits, function($digit) use ($minConfidence) {
        return isset($digit['confidence']) && $digit['confidence'] >= $minConfidence;
    });
    
    if (count($filteredDigits) < 3) {
        error_log("⚠ Not enough high-confidence digits. Found " . count($filteredDigits) . " digits with confidence >= $minConfidence");
        // Fallback: use all digits if we don't have enough high-confidence ones
        $filteredDigits = $digits;
    }
    
    // Step 2: Remove duplicate/overlapping detections
    // If two digits have very similar positions and same value, keep the one with higher confidence
    $deduplicatedDigits = [];
    $positionTolerance = 30; // Pixels - digits closer than this are considered overlapping
    
    foreach ($filteredDigits as $digit) {
        $isDuplicate = false;
        $digitX = floatval($digit['x']);
        $digitY = floatval($digit['y']);
        $digitValue = $digit['digit'];
        $digitConfidence = floatval($digit['confidence']);
        
        foreach ($deduplicatedDigits as $index => $existingDigit) {
            $existingX = floatval($existingDigit['x']);
            $existingY = floatval($existingDigit['y']);
            $existingValue = $existingDigit['digit'];
            $existingConfidence = floatval($existingDigit['confidence']);
            
            // Check if positions are very close (overlapping)
            $distanceX = abs($digitX - $existingX);
            $distanceY = abs($digitY - $existingY);
            
            if ($distanceX < $positionTolerance && $distanceY < $positionTolerance) {
                // Overlapping detection - keep the one with higher confidence
                if ($digitConfidence > $existingConfidence) {
                    // Replace existing with this one
                    $deduplicatedDigits[$index] = $digit;
                }
                $isDuplicate = true;
                break;
            }
        }
        
        if (!$isDuplicate) {
            $deduplicatedDigits[] = $digit;
        }
    }
    
    if (count($deduplicatedDigits) < 3) {
        error_log("⚠ Not enough digits after deduplication. Found " . count($deduplicatedDigits) . " unique digits");
        return null;
    }
    
    // Step 3: Group digits by Y position (to handle multi-row displays)
    // Calculate average Y position to determine if digits are on same row
    $yPositions = array_map(function($d) { return floatval($d['y']); }, $deduplicatedDigits);
    $avgY = array_sum($yPositions) / count($yPositions);
    $yTolerance = 50; // Pixels - digits within this Y range are considered same row
    
    // Group digits into rows
    $rows = [];
    foreach ($deduplicatedDigits as $digit) {
        $digitY = floatval($digit['y']);
        $assigned = false;
        
        // Try to assign to existing row
        foreach ($rows as $rowIndex => $rowDigits) {
            $rowAvgY = array_sum(array_map(function($d) { return floatval($d['y']); }, $rowDigits)) / count($rowDigits);
            if (abs($digitY - $rowAvgY) <= $yTolerance) {
                $rows[$rowIndex][] = $digit;
                $assigned = true;
                break;
            }
        }
        
        // Create new row if not assigned
        if (!$assigned) {
            $rows[] = [$digit];
        }
    }
    
    // Step 4: Sort rows by Y position (top to bottom), then sort digits within each row by X (left to right)
    usort($rows, function($a, $b) {
        $avgYA = array_sum(array_map(function($d) { return floatval($d['y']); }, $a)) / count($a);
        $avgYB = array_sum(array_map(function($d) { return floatval($d['y']); }, $b)) / count($b);
        return $avgYA <=> $avgYB;
    });
    
    // Sort digits within each row by X position
    foreach ($rows as &$row) {
        usort($row, function($a, $b) {
            return floatval($a['x']) <=> floatval($b['x']);
        });
    }
    unset($row);
    
    // Step 5: Extract digits from rows (prefer top row, or combine if needed)
    $digitValues = [];
    
    // If we have multiple rows, prefer the row with most digits (likely the reading)
    if (count($rows) > 1) {
        // Find row with most digits
        $bestRow = $rows[0];
        $maxDigits = count($rows[0]);
        foreach ($rows as $row) {
            if (count($row) > $maxDigits) {
                $maxDigits = count($row);
                $bestRow = $row;
            }
        }
        
        // Use best row
        foreach ($bestRow as $digit) {
            $digitValues[] = $digit['digit'];
        }
        
        // If best row doesn't have 5 digits, try to supplement from other rows
        if (count($digitValues) < 5) {
            foreach ($rows as $row) {
                if ($row === $bestRow) continue;
                foreach ($row as $digit) {
                    if (count($digitValues) >= 5) break;
                    $digitValues[] = $digit['digit'];
                }
                if (count($digitValues) >= 5) break;
            }
        }
    } else {
        // Single row - just extract all digits
        foreach ($rows[0] as $digit) {
            $digitValues[] = $digit['digit'];
        }
    }
    
    if (empty($digitValues)) {
        error_log("✗ No digit values extracted after processing");
        return null;
    }
    
    // Step 6: Combine digits into reading
    $reading = implode('', $digitValues);
    
    // Step 7: Normalize to 5 digits
    $originalLength = strlen($reading);
    if ($originalLength < 5) {
        // Pad with leading zeros if less than 5 digits
        $reading = str_pad($reading, 5, '0', STR_PAD_LEFT);
        error_log("⚠ Padded reading from $originalLength to 5 digits: $reading");
    } elseif ($originalLength > 5) {
        // Take first 5 digits if more than 5 (most likely the reading)
        $reading = substr($reading, 0, 5);
        error_log("⚠ Truncated reading from $originalLength to 5 digits: $reading");
    }
    
    // Step 8: Validate it's exactly 5 digits
    if (strlen($reading) === 5 && preg_match('/^\d{5}$/', $reading)) {
        error_log("✓ Extracted meter reading from digits: $reading (from " . count($digits) . " detected digits, " . count($deduplicatedDigits) . " after deduplication)");
        return $reading;
    }
    
    error_log("✗ Failed to extract valid 5-digit reading. Got: '$reading' (length: " . strlen($reading) . ")");
    return null;
}

