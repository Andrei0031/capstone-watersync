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
// Using serverless.roboflow.com API endpoint (Hosted Image Inference)
define('ROBOFLOW_PROJECT', 'watersync-oekrf');
define('ROBOFLOW_MODEL_VERSION', '7'); // Using version 7
define('ROBOFLOW_MODEL_ID', 'watersync-oekrf/7'); // Model ID format: project/version
// Serverless API endpoint format: https://serverless.roboflow.com/{model_id}
define('ROBOFLOW_INFERENCE_URL', 'https://serverless.roboflow.com/' . ROBOFLOW_MODEL_ID . '?api_key=' . ROBOFLOW_API_KEY);

// Digit Detection Model Configuration
// Using model_id format from Roboflow "Hosted Image Inference"
// Format: "project-name/version" (e.g., "watersync-oekrf/7")
define('ROBOFLOW_DIGIT_MODEL_ID', 'watersync-oekrf/7'); // Using version 7

// Option 2: Use separate project and version (alternative format - kept for compatibility)
define('ROBOFLOW_DIGIT_PROJECT', 'watersync-digits'); // Change this to your digit detection project name
define('ROBOFLOW_DIGIT_MODEL_VERSION', '7'); // Using version 7

// Build inference URL - try both serverless and detect endpoints
// Serverless API endpoint format: https://serverless.roboflow.com/{model_id}
// Alternative: https://detect.roboflow.com/{workspace}/{project}/{version}
// If model_id is set, use it directly; otherwise use project/version format
if (defined('ROBOFLOW_DIGIT_MODEL_ID') && !empty(ROBOFLOW_DIGIT_MODEL_ID)) {
    // Primary: Use serverless endpoint
    define('ROBOFLOW_DIGIT_INFERENCE_URL', 'https://serverless.roboflow.com/' . ROBOFLOW_DIGIT_MODEL_ID . '?api_key=' . ROBOFLOW_API_KEY);
    // Alternative: Use detect endpoint (fallback)
    define('ROBOFLOW_DIGIT_INFERENCE_URL_ALT', 'https://detect.roboflow.com/' . ROBOFLOW_WORKSPACE . '/' . ROBOFLOW_PROJECT . '/' . ROBOFLOW_MODEL_VERSION . '?api_key=' . ROBOFLOW_API_KEY);
} else {
    // Use project/version format (legacy)
    define('ROBOFLOW_DIGIT_INFERENCE_URL', 'https://serverless.roboflow.com/' . ROBOFLOW_WORKSPACE . '/' . ROBOFLOW_DIGIT_PROJECT . '/' . ROBOFLOW_DIGIT_MODEL_VERSION . '?api_key=' . ROBOFLOW_API_KEY);
    define('ROBOFLOW_DIGIT_INFERENCE_URL_ALT', 'https://detect.roboflow.com/' . ROBOFLOW_WORKSPACE . '/' . ROBOFLOW_DIGIT_PROJECT . '/' . ROBOFLOW_DIGIT_MODEL_VERSION . '?api_key=' . ROBOFLOW_API_KEY);
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
        
        // Prepare image data - encode to base64 for POST body
        // Method: base64 YOUR_IMAGE.jpg | curl -d @- (sends base64 data in POST body)
        $imageData = file_get_contents($imagePath);
        if (!$imageData) {
            return [
                'success' => false,
                'detection' => null,
                'message' => 'Failed to read image file'
            ];
        }
        
        // Encode image to base64 (matching: base64 YOUR_IMAGE.jpg | curl -d @-)
        $base64Image = base64_encode($imageData);
        
        // Initialize cURL
        // Roboflow serverless API: POST with base64-encoded image data in body
        // API key is in URL as query parameter
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, ROBOFLOW_INFERENCE_URL);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $base64Image); // Send base64 data in POST body
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15); // Reduced timeout to fail faster
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
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
        
        // Check if image file exists and is readable
        if (!file_exists($imagePath)) {
            error_log("✗ Roboflow Digit Detection: Image file does not exist: $imagePath");
            return [
                'success' => false,
                'digits' => [],
                'message' => 'Image file not found: ' . $imagePath
            ];
        }
        
        // Check image file size
        $fileSize = filesize($imagePath);
        error_log("Roboflow Digit Detection: Image file size: " . round($fileSize / 1024, 2) . " KB");
        
        if ($fileSize === false || $fileSize === 0) {
            error_log("✗ Roboflow Digit Detection: Image file is empty or unreadable");
            return [
                'success' => false,
                'digits' => [],
                'message' => 'Image file is empty or unreadable'
            ];
        }
        
        // Verify it's a valid image
        $imageInfo = @getimagesize($imagePath);
        if ($imageInfo === false) {
            error_log("✗ Roboflow Digit Detection: Invalid image file format");
            return [
                'success' => false,
                'digits' => [],
                'message' => 'Invalid image file format'
            ];
        }
        
        error_log("Roboflow Digit Detection: Image dimensions: " . $imageInfo[0] . "x" . $imageInfo[1] . ", type: " . $imageInfo['mime']);
        
        // Prepare image data - encode to base64 for POST body
        // Method: base64 YOUR_IMAGE.jpg | curl -d @- (sends base64 data in POST body)
        $imageData = file_get_contents($imagePath);
        if (!$imageData) {
            return [
                'success' => false,
                'digits' => [],
                'message' => 'Failed to read image file'
            ];
        }
        
        // Try multiple methods to send image to Roboflow API
        // Method 1: Base64 in POST body (original method)
        // Method 2: Multipart form-data with file
        // Method 3: Base64 with different content type
        
        $response = null;
        $httpCode = 0;
        $error = '';
        $methodUsed = '';
        
        // METHOD 1: Try base64 in POST body (matching cURL: base64 IMAGE | curl -d @-)
        // This is the EXACT format from Roboflow cURL example
        error_log("Roboflow Digit Detection: Trying Method 1 - Base64 POST body (matching cURL format)");
        $base64Image = base64_encode($imageData);
        error_log("Roboflow Digit Detection: Base64 encoded size: " . round(strlen($base64Image) / 1024, 2) . " KB");
        error_log("Roboflow Digit Detection: API URL: " . ROBOFLOW_DIGIT_INFERENCE_URL);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, ROBOFLOW_DIGIT_INFERENCE_URL);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $base64Image); // Send raw base64 data (matching: curl -d @-)
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // Don't set Content-Type header - let cURL handle it (matching cURL behavior)
        curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 10 seconds max - fail fast
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // Connection timeout 5 seconds
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1); // Force HTTP/1.1
        
        error_log("Roboflow Digit Detection: Sending request to: " . ROBOFLOW_DIGIT_INFERENCE_URL);
        $startTime = microtime(true);
        $response = curl_exec($ch);
        $elapsedTime = microtime(true) - $startTime;
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $curlInfo = curl_getinfo($ch);
        curl_close($ch);
        
        error_log("Roboflow Digit Detection: Method 1 Response - HTTP: $httpCode, Time: " . round($elapsedTime, 2) . "s, Error: " . ($error ?: 'None') . ", Size: " . strlen($response ?? '') . " bytes");
        
        // If request took too long or failed, log the issue
        if ($elapsedTime > 8 || $error || $httpCode !== 200) {
            error_log("⚠ Roboflow request too slow or failed - HTTP: $httpCode, Time: " . round($elapsedTime, 2) . "s, Error: " . ($error ?: 'None'));
        }
        
        // Check if Method 1 returned valid predictions
        $method1Success = false;
        // Only process if we got a valid response quickly
        if ($httpCode === 200 && !$error && !empty($response) && $elapsedTime < 8) {
            error_log("Roboflow Digit Detection: Method 1 - HTTP 200, response length: " . strlen($response));
            $testData = json_decode($response, true);
            
            if ($testData) {
                error_log("Roboflow Digit Detection: Method 1 - JSON decoded successfully");
                error_log("Roboflow Digit Detection: Method 1 - Response keys: " . implode(', ', array_keys($testData)));
                error_log("Roboflow Digit Detection: Method 1 - Full response: " . json_encode($testData, JSON_PRETTY_PRINT));
                
                // Check multiple possible response formats
                $hasPredictions = false;
                $predictionCount = 0;
                
                if (isset($testData['predictions']) && is_array($testData['predictions'])) {
                    $hasPredictions = true;
                    $predictionCount = count($testData['predictions']);
                    error_log("Roboflow Digit Detection: Method 1 - Found 'predictions' array with $predictionCount items");
                } elseif (isset($testData['detections']) && is_array($testData['detections'])) {
                    $hasPredictions = true;
                    $predictionCount = count($testData['detections']);
                    error_log("Roboflow Digit Detection: Method 1 - Found 'detections' array with $predictionCount items");
                } elseif (isset($testData['results']) && is_array($testData['results'])) {
                    $hasPredictions = true;
                    $predictionCount = count($testData['results']);
                    error_log("Roboflow Digit Detection: Method 1 - Found 'results' array with $predictionCount items");
                } else {
                    error_log("Roboflow Digit Detection: Method 1 - No predictions/detections/results found. Full response structure:");
                    error_log(json_encode($testData, JSON_PRETTY_PRINT));
                }
                
                if ($hasPredictions && $predictionCount > 0) {
                    $method1Success = true;
                    $methodUsed = 'base64-form';
                    error_log("✓ Roboflow Digit Detection: Method 1 (base64-form) succeeded with $predictionCount predictions");
                } else {
                    error_log("✗ Roboflow Digit Detection: Method 1 returned HTTP 200 but no predictions found. Response keys: " . implode(', ', array_keys($testData)));
                }
            } else {
                $jsonError = json_last_error_msg();
                error_log("✗ Roboflow Digit Detection: Method 1 returned HTTP 200 but JSON decode failed: $jsonError");
                error_log("✗ Roboflow Digit Detection: Method 1 - Raw response: " . substr($response, 0, 1000));
            }
        } else {
            error_log("✗ Roboflow Digit Detection: Method 1 failed - HTTP Code: $httpCode, Error: $error, Response length: " . strlen($response ?? ''));
        }
        
        // If Method 1 failed or returned empty predictions, try Method 2: Multipart form-data
        if (!$method1Success) {
            error_log("Roboflow Digit Detection: Method 1 failed or no predictions, trying Method 2 - Multipart form-data");
            $methodUsed = 'multipart';
            
            $boundary = uniqid();
            $delimiter = '-------------' . $boundary;
            
            $postData = '';
            $postData .= '--' . $delimiter . "\r\n";
            $postData .= 'Content-Disposition: form-data; name="file"; filename="meter_image.jpg"' . "\r\n";
            $postData .= 'Content-Type: image/jpeg' . "\r\n\r\n";
            $postData .= $imageData . "\r\n";
            $postData .= '--' . $delimiter . '--';
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, ROBOFLOW_DIGIT_INFERENCE_URL);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: multipart/form-data; boundary=' . $delimiter
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15); // Reduced timeout to fail faster
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            // Check if Method 2 returned valid predictions
            $method2Success = false;
            if ($httpCode === 200 && !$error && !empty($response)) {
                $testData = json_decode($response, true);
                if ($testData) {
                    // Check multiple possible response formats
                    $hasPredictions = false;
                    $predictionCount = 0;
                    
                    if (isset($testData['predictions']) && is_array($testData['predictions'])) {
                        $hasPredictions = true;
                        $predictionCount = count($testData['predictions']);
                    } elseif (isset($testData['detections']) && is_array($testData['detections'])) {
                        $hasPredictions = true;
                        $predictionCount = count($testData['detections']);
                    } elseif (isset($testData['results']) && is_array($testData['results'])) {
                        $hasPredictions = true;
                        $predictionCount = count($testData['results']);
                    }
                    
                    if ($hasPredictions && $predictionCount > 0) {
                        $method2Success = true;
                        error_log("Roboflow Digit Detection: Method 2 (multipart) succeeded with $predictionCount predictions");
                    }
                }
            }
            
            // If Method 2 also failed, try Method 3: Base64 with text/plain
            if (!$method2Success) {
                error_log("Roboflow Digit Detection: Method 2 failed, trying Method 3 - Base64 with text/plain");
                $methodUsed = 'base64-text';
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, ROBOFLOW_DIGIT_INFERENCE_URL);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $base64Image);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: text/plain'
                ]);
                curl_setopt($ch, CURLOPT_TIMEOUT, 15); // Reduced timeout to fail faster
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);
                
                // Check if Method 3 returned valid predictions
                $method3Success = false;
                if ($httpCode === 200 && !$error && !empty($response)) {
                    $testData = json_decode($response, true);
                    if ($testData) {
                        // Check multiple possible response formats
                        $hasPredictions = false;
                        $predictionCount = 0;
                        
                        if (isset($testData['predictions']) && is_array($testData['predictions'])) {
                            $hasPredictions = true;
                            $predictionCount = count($testData['predictions']);
                        } elseif (isset($testData['detections']) && is_array($testData['detections'])) {
                            $hasPredictions = true;
                            $predictionCount = count($testData['detections']);
                        } elseif (isset($testData['results']) && is_array($testData['results'])) {
                            $hasPredictions = true;
                            $predictionCount = count($testData['results']);
                        }
                        
                        if ($hasPredictions && $predictionCount > 0) {
                            $method3Success = true;
                            error_log("Roboflow Digit Detection: Method 3 (base64-text) succeeded with $predictionCount predictions");
                        }
                    }
                }
                
                // If Method 3 failed, try Method 4: CURLFile (PHP 5.5+)
                if (!$method3Success && class_exists('CURLFile')) {
                    error_log("Roboflow Digit Detection: Method 3 failed, trying Method 4 - CURLFile");
                    $methodUsed = 'curlfile';
                    
                    $cfile = new CURLFile($imagePath, 'image/jpeg', 'meter_image.jpg');
                    $postData = ['file' => $cfile];
                    
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, ROBOFLOW_DIGIT_INFERENCE_URL);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 15); // Reduced timeout to fail faster
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $error = curl_error($ch);
                    curl_close($ch);
                }
                
                // If all methods failed with serverless endpoint, try detect.roboflow.com endpoint
                if ($httpCode !== 200 || $error || empty($response)) {
                    if (defined('ROBOFLOW_DIGIT_INFERENCE_URL_ALT')) {
                        error_log("Roboflow Digit Detection: All methods failed with serverless endpoint, trying alternative detect.roboflow.com endpoint");
                        $methodUsed = 'detect-endpoint-base64';
                        
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, ROBOFLOW_DIGIT_INFERENCE_URL_ALT);
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $base64Image);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Content-Type: application/x-www-form-urlencoded'
                        ]);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 15); // Reduced timeout to fail faster
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                        
                        $response = curl_exec($ch);
                        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $error = curl_error($ch);
                        curl_close($ch);
                    }
                }
            }
        }
        
        error_log("=== ROBOFLOW DIGIT DETECTION SUMMARY ===");
        error_log("Final method used: $methodUsed");
        error_log("HTTP Code: $httpCode");
        error_log("API URL: " . ROBOFLOW_DIGIT_INFERENCE_URL);
        error_log("Model ID: " . ROBOFLOW_DIGIT_MODEL_ID);
        error_log("Response length: " . strlen($response ?? '') . " bytes");
        error_log("Has cURL error: " . ($error ? 'Yes - ' . $error : 'No'));
        error_log("Response preview: " . substr($response ?? '', 0, 1000));
        error_log("==========================================");
        
        if ($error) {
            error_log("Roboflow Digit Detection: cURL Error: $error");
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
                $errorMsg .= ' - Not found. Model version 7 may not be deployed. Check Roboflow dashboard and deploy the model.';
                $errorMsg .= ' If version 2 works, you may need to deploy version 7 in Roboflow.';
            } elseif ($httpCode === 405) {
                $errorMsg .= ' - Method Not Allowed. The digit detection model version 7 may not be deployed yet.';
                $errorMsg .= ' Please deploy your model version 7 in Roboflow dashboard.';
            } else {
                $errorMsg .= ' - Response: ' . substr($response, 0, 500);
            }
            error_log('✗ Roboflow Digit Detection API HTTP Error: ' . $errorMsg);
            error_log('✗ API URL: ' . ROBOFLOW_DIGIT_INFERENCE_URL);
            error_log('✗ Model ID: ' . ROBOFLOW_DIGIT_MODEL_ID);
            error_log('✗ Full API Response: ' . substr($response, 0, 1000));
            return [
                'success' => false,
                'digits' => [],
                'message' => $errorMsg
            ];
        }
        
        // Log raw response for debugging
        error_log("Roboflow Digit Detection: Raw API Response (first 2000 chars): " . substr($response ?? '', 0, 2000));
        error_log("Roboflow Digit Detection: Full response length: " . strlen($response ?? '') . " bytes");
        
        // Check if response is completely empty
        if (empty($response) || trim($response) === '') {
            error_log('✗ Roboflow Digit Detection: API returned EMPTY response');
            error_log('   HTTP Code: ' . $httpCode);
            error_log('   cURL Error: ' . ($error ?: 'None'));
            error_log('   This usually means the model is not deployed or the endpoint is wrong');
            return [
                'success' => false,
                'digits' => [],
                'message' => 'Roboflow API returned empty response. HTTP Code: ' . $httpCode . '. Check if model version 7 is deployed for "Hosted Image Inference" in Roboflow dashboard.'
            ];
        }
        
        $data = json_decode($response, true);
        
        if (!$data) {
            $jsonError = json_last_error_msg();
            error_log('✗ Roboflow Digit Detection: JSON decode failed: ' . $jsonError);
            error_log('✗ Roboflow Digit Detection: Response type: ' . gettype($response));
            error_log('✗ Roboflow Digit Detection: Full response (first 2000 chars): ' . substr($response, 0, 2000));
            
            // Check if it's HTML (error page) or other non-JSON
            if (stripos($response, '<html') !== false || stripos($response, '<!DOCTYPE') !== false) {
                error_log('⚠ Response appears to be HTML (error page), not JSON');
                return [
                    'success' => false,
                    'digits' => [],
                    'message' => 'Roboflow API returned HTML instead of JSON. This usually means the endpoint is wrong or the model is not deployed. Check: ' . ROBOFLOW_DIGIT_INFERENCE_URL
                ];
            }
            
            return [
                'success' => false,
                'digits' => [],
                'message' => 'Invalid JSON response from Roboflow API: ' . $jsonError . '. Response preview: ' . substr($response, 0, 200)
            ];
        }
        
        // Log full API response structure
        error_log("=== ROBOFLOW API RESPONSE STRUCTURE ===");
        error_log(json_encode($data, JSON_PRETTY_PRINT));
        error_log("========================================");
        
        // Check if response is empty object/array
        if (empty($data) || (is_array($data) && count($data) === 0)) {
            error_log('⚠ WARNING: API returned empty data structure');
            error_log('   Response type: ' . gettype($data));
            error_log('   Is array: ' . (is_array($data) ? 'Yes' : 'No'));
            error_log('   Array count: ' . (is_array($data) ? count($data) : 'N/A'));
            error_log('   This usually means:');
            error_log('   1. Version 7 is NOT deployed for "Hosted Image Inference"');
            error_log('   2. You need to go to Roboflow → Deploy → "Hosted Image Inference" → Click "View Code"');
            error_log('   3. The model may only be deployed for "Embedded Device" (not for API calls)');
            error_log('   4. Check if version 7 shows a cURL example in "Hosted Image Inference" section');
        }
        
        // Parse digit detections - check multiple possible response formats
        $predictions = [];
        if (isset($data['predictions']) && is_array($data['predictions'])) {
            $predictions = $data['predictions'];
            error_log("✓ Found predictions array with " . count($predictions) . " items");
        } elseif (isset($data['detections']) && is_array($data['detections'])) {
            $predictions = $data['detections'];
            error_log("✓ Found detections array with " . count($predictions) . " items");
        } elseif (isset($data['results']) && is_array($data['results'])) {
            $predictions = $data['results'];
            error_log("✓ Found results array with " . count($predictions) . " items");
        } elseif (isset($data['data']) && is_array($data['data'])) {
            // Some APIs wrap predictions in 'data' key
            $predictions = $data['data'];
            error_log("✓ Found data array with " . count($predictions) . " items");
        } elseif (isset($data['objects']) && is_array($data['objects'])) {
            // Some APIs use 'objects' key
            $predictions = $data['objects'];
            error_log("✓ Found objects array with " . count($predictions) . " items");
        } else {
            // Check if response is directly an array
            if (is_array($data) && isset($data[0])) {
                $predictions = $data;
                error_log("✓ Response is directly an array with " . count($predictions) . " items");
            }
        }
        
        error_log('Roboflow Digit Detection API response keys: ' . implode(', ', array_keys($data)));
        error_log('Roboflow Digit Detection API full response (first 2000 chars): ' . substr(json_encode($data), 0, 2000));
        error_log('Number of digit predictions found: ' . count($predictions));
        
        // If no predictions found, log the full response structure for debugging
        if (count($predictions) === 0) {
            error_log('⚠ Roboflow Digit Detection: No predictions array found. Full response structure:');
            error_log('   Response type: ' . gettype($data));
            if (is_array($data)) {
                error_log('   Top-level keys: ' . implode(', ', array_keys($data)));
                foreach ($data as $key => $value) {
                    if (is_array($value)) {
                        error_log("   Key '$key' is array with " . count($value) . " items");
                        if (count($value) > 0 && isset($value[0])) {
                            error_log("   First item keys: " . implode(', ', array_keys($value[0])));
                        }
                    } else {
                        error_log("   Key '$key' = " . (is_string($value) ? substr($value, 0, 100) : gettype($value)));
                    }
                }
            }
        }
        
        if (count($predictions) === 0 && !empty($data)) {
            error_log('⚠ Roboflow Digit Detection: API returned data but no predictions array found. Response structure: ' . json_encode(array_keys($data)));
        }
        
        // Extract digits with their positions
        $digits = [];
        foreach ($predictions as $prediction) {
            // Try multiple possible class name fields (class, class_name, name, or class_id)
            $className = '';
            $digitValue = null;
            
            if (isset($prediction['class'])) {
                $className = trim(strval($prediction['class']));
            } elseif (isset($prediction['class_name'])) {
                $className = trim(strval($prediction['class_name']));
            } elseif (isset($prediction['name'])) {
                $className = trim(strval($prediction['name']));
            } elseif (isset($prediction['class_id'])) {
                // class_id is numeric (0-9), convert to string
                $className = strval($prediction['class_id']);
            }
            
            $confidence = isset($prediction['confidence']) ? floatval($prediction['confidence']) : 0.0;
            
            // Log all predictions for debugging
            error_log("Roboflow prediction #" . (count($digits) + 1) . ": class='$className', class_id=" . ($prediction['class_id'] ?? 'N/A') . ", confidence=$confidence, x=" . ($prediction['x'] ?? 'N/A') . ", y=" . ($prediction['y'] ?? 'N/A'));
            error_log("   Full prediction data: " . json_encode($prediction));
            
            // Validate that it's a digit (0-9)
            // Handle both string class names ("6") and numeric class_id (6)
            $isDigit = false;
            
            // Check if class name is a single digit (0-9) as string
            if (preg_match('/^[0-9]$/', $className)) {
                $isDigit = true;
                $digitValue = $className;
            } 
            // Check if class_id is a digit (0-9)
            elseif (isset($prediction['class_id']) && is_numeric($prediction['class_id'])) {
                $classId = intval($prediction['class_id']);
                if ($classId >= 0 && $classId <= 9) {
                    $isDigit = true;
                    $digitValue = strval($classId);
                }
            }
            // Check if class name contains digit (e.g., "digit_5", "5_digit", "number_3", etc.)
            elseif (preg_match('/[0-9]/', $className, $matches)) {
                $isDigit = true;
                $digitValue = $matches[0]; // Extract the first digit found
                error_log("   Extracted digit '$digitValue' from class name '$className'");
            }
            // Check if prediction has 'predictions' key with class inside (nested structure)
            elseif (isset($prediction['predictions']) && is_array($prediction['predictions'])) {
                // Handle nested predictions structure
                foreach ($prediction['predictions'] as $nestedPred) {
                    $nestedClass = $nestedPred['class'] ?? $nestedPred['class_name'] ?? $nestedPred['name'] ?? '';
                    if (preg_match('/^[0-9]$/', $nestedClass)) {
                        $isDigit = true;
                        $digitValue = $nestedClass;
                        $confidence = isset($nestedPred['confidence']) ? floatval($nestedPred['confidence']) : $confidence;
                        error_log("   Found nested digit '$digitValue' with confidence $confidence");
                        break;
                    }
                }
            }
            
            // Use confidence threshold of 0.1 (10%) - very low to catch all detections
            // Version 7 might have different confidence distribution
            // Lower threshold ensures we catch all valid digits
            if ($isDigit && $confidence > 0.1) {
                $digits[] = [
                    'digit' => $digitValue,
                    'x' => isset($prediction['x']) ? floatval($prediction['x']) : 0,
                    'y' => isset($prediction['y']) ? floatval($prediction['y']) : 0,
                    'width' => isset($prediction['width']) ? floatval($prediction['width']) : 0,
                    'height' => isset($prediction['height']) ? floatval($prediction['height']) : 0,
                    'confidence' => $confidence
                ];
                error_log("✓ Roboflow Digit Detection: Found digit '$digitValue' with confidence $confidence at position ({$digits[count($digits)-1]['x']}, {$digits[count($digits)-1]['y']})");
            } elseif ($isDigit && $confidence <= 0.1) {
                error_log("⚠ Roboflow Digit Detection: Digit '$digitValue' found but confidence $confidence is below threshold (0.1)");
            } elseif ($isDigit) {
                // Digit found and above threshold
                error_log("✓ Roboflow Digit Detection: Digit '$digitValue' accepted with confidence $confidence");
            } else {
                // Not a digit - log what it is
                error_log("⚠ Roboflow Digit Detection: Detection found but not a digit - class='$className', class_id=" . ($prediction['class_id'] ?? 'N/A') . ", confidence=$confidence");
            }
        }
        
        // If we have predictions but no digits, log ALL prediction details for debugging
        if (count($predictions) > 0 && count($digits) === 0) {
            error_log('⚠ Roboflow Digit Detection: Found ' . count($predictions) . ' predictions but none recognized as digits');
            error_log('   Checking all predictions for digit patterns...');
            foreach ($predictions as $idx => $pred) {
                $allKeys = array_keys($pred);
                $classVal = $pred['class'] ?? $pred['class_name'] ?? $pred['name'] ?? $pred['class_id'] ?? 'N/A';
                $confVal = $pred['confidence'] ?? 'N/A';
                error_log("   Prediction #$idx: keys=[" . implode(',', $allKeys) . "], class='$classVal', confidence=$confVal");
                error_log("   Full prediction: " . json_encode($pred));
            }
        }
        
        if (count($digits) > 0) {
            error_log('✓ Roboflow Digit Detection: Found ' . count($digits) . ' digit(s)');
            error_log('   Digits: ' . implode(', ', array_map(function($d) { return $d['digit'] . '(' . round($d['confidence'], 2) . ')'; }, $digits)));
            return [
                'success' => true,
                'digits' => $digits,
                'all_predictions' => $predictions
            ];
        } else {
            $errorMsg = 'No digits detected in image';
            if (count($predictions) > 0) {
                $classNames = [];
                $allPredDetails = [];
                foreach ($predictions as $idx => $pred) {
                    $class = $pred['class'] ?? $pred['class_name'] ?? $pred['name'] ?? 'unknown';
                    $classId = $pred['class_id'] ?? 'N/A';
                    $conf = round(($pred['confidence'] ?? 0) * 100, 1);
                    $classNames[] = "$class ($conf%)";
                    $allPredDetails[] = "Prediction #$idx: class='$class', class_id=$classId, confidence=$conf, keys=" . implode(',', array_keys($pred));
                }
                $errorMsg .= '. Found ' . count($predictions) . ' detection(s): ' . implode(', ', $classNames);
                $errorMsg .= ' but none were recognized as valid digits (0-9).';
                error_log('✗ Roboflow Digit Detection: All prediction details:');
                foreach ($allPredDetails as $detail) {
                    error_log('   ' . $detail);
                }
                $errorMsg .= ' Check logs for full prediction details.';
            } else {
                $errorMsg .= '. No detections returned from Roboflow API.';
                $errorMsg .= ' The model may not be detecting anything, or the image may be too small/cropped.';
                $errorMsg .= ' IMPORTANT: If version 2 works but version 7 does not, version 7 is likely NOT DEPLOYED.';
                $errorMsg .= ' Go to Roboflow → Your Project → Deploy → "Integrate with my app or website" → Deploy version 7.';
            }
            error_log('✗ Roboflow Digit Detection: ' . $errorMsg);
            error_log('✗ API URL: ' . ROBOFLOW_DIGIT_INFERENCE_URL);
            error_log('✗ Model ID: ' . ROBOFLOW_DIGIT_MODEL_ID);
            error_log('✗ HTTP Code: ' . $httpCode);
            error_log('✗ Roboflow API Response (full): ' . json_encode($data, JSON_PRETTY_PRINT));
            
            // If we got a 200 response but no predictions, the model might not be deployed
            if ($httpCode === 200 && empty($predictions)) {
                error_log('⚠ WARNING: Got HTTP 200 but no predictions. This usually means:');
                error_log('   1. Model version 7 is NOT DEPLOYED in Roboflow dashboard');
                error_log('   2. Model version 7 is deployed but not accessible via API');
                error_log('   3. The response format is different from version 2');
                error_log('   4. The model is detecting something but not digits (wrong classes)');
                error_log('   SOLUTION: Deploy model version 7 in Roboflow dashboard, or use version 2 which is working.');
                error_log('   To deploy: Go to Roboflow → Project → Deploy → "Integrate with my app or website" → Select version 7 → Deploy');
            }
            return [
                'success' => false,
                'digits' => [],
                'message' => $errorMsg,
                'all_predictions' => $predictions,
                'api_response' => $data
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

