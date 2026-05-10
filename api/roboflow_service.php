<?php
/**
 * Roboflow Service for Server-Side Meter Region Detection and Digit Recognition
 * Uses Roboflow API to:
 * 1. Detect and crop meter reading regions
 * 2. Detect individual digits (0-9) for OCR
 * 
 * @phpstan-ignore-next-line
 * @suppress P1007
 */

// Roboflow API Configuration
define('ROBOFLOW_API_KEY', 'plVsmWuM0KjEA8Pz6RqB'); // Private API Key
define('ROBOFLOW_WORKSPACE', 'watersync');

// Meter Detection Model Configuration
// Using serverless.roboflow.com API endpoint (Hosted Image Inference)
define('ROBOFLOW_PROJECT', 'watersync-oekrf');
define('ROBOFLOW_MODEL_VERSION', '8'); // Using version 8
define('ROBOFLOW_MODEL_ID', 'watersync-oekrf/8'); // Model ID format: project/version
// Serverless API endpoint format: https://serverless.roboflow.com/{model_id}
define('ROBOFLOW_INFERENCE_URL', 'https://serverless.roboflow.com/' . ROBOFLOW_MODEL_ID . '?api_key=' . ROBOFLOW_API_KEY);

// Digit Detection Model Configuration
// Using model_id format from Roboflow "Hosted Image Inference"
// Format: "project-name/version" (e.g., "watersync-oekrf/8")
define('ROBOFLOW_DIGIT_MODEL_ID', 'watersync-oekrf/8'); // Using version 8 - newly trained model
define('ROBOFLOW_DIGIT_FALLBACK_MODEL_ID', 'watersync-oekrf/7'); // Validation/fallback model

// Build inference URL (serverless endpoint only)
// Serverless API endpoint format: https://serverless.roboflow.com/{model_id}
define('ROBOFLOW_DIGIT_INFERENCE_URL', 'https://serverless.roboflow.com/' . ROBOFLOW_DIGIT_MODEL_ID . '?api_key=' . ROBOFLOW_API_KEY);
define('ROBOFLOW_DIGIT_FALLBACK_INFERENCE_URL', 'https://serverless.roboflow.com/' . ROBOFLOW_DIGIT_FALLBACK_MODEL_ID . '?api_key=' . ROBOFLOW_API_KEY);

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
        curl_setopt($ch, CURLOPT_TIMEOUT, 90); // Increased to 90 seconds - Roboflow API can be slow
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        error_log("Roboflow: Sending request to API...");
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        // @phpstan-ignore-next-line
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
            // @phpstan-ignore-next-line
            imagedestroy($image);
            return null;
        }
        
        // Save cropped image
        $croppedPath = $originalImagePath . '_cropped.jpg';
        imagejpeg($croppedImage, $croppedPath, 95);
        
        // Clean up
        // @phpstan-ignore-next-line
        imagedestroy($image);
        // @phpstan-ignore-next-line
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
function detectDigitsWithRoboflow($imagePath, $modelId = null) {
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
    
    try {
        $modelId = $modelId ?: ROBOFLOW_DIGIT_MODEL_ID;
        $inferenceUrl = 'https://serverless.roboflow.com/' . $modelId . '?api_key=' . ROBOFLOW_API_KEY;

        error_log("Roboflow Digit Detection: Calling API for image: $imagePath");
        error_log("Roboflow Digit Detection: Model ID: " . $modelId);
        error_log("Roboflow Digit Detection: API URL: " . $inferenceUrl);
        
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
        error_log("Roboflow Digit Detection: API URL: " . $inferenceUrl);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $inferenceUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $base64Image); // Send raw base64 data (matching: curl -d @-)
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // Don't set Content-Type header - let cURL handle it (matching cURL behavior)
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Shorter timeout to avoid long blocking
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // Shorter connect timeout
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1); // Force HTTP/1.1
        
        error_log("Roboflow Digit Detection: Sending request to: " . $inferenceUrl);
        $startTime = microtime(true);
        $response = curl_exec($ch);
        $elapsedTime = microtime(true) - $startTime;
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $curlInfo = curl_getinfo($ch);
        curl_close($ch);
        
        error_log("Roboflow Digit Detection: Method 1 Response - HTTP: $httpCode, Time: " . round($elapsedTime, 2) . "s, Error: " . ($error ?: 'None') . ", Size: " . strlen($response ?? '') . " bytes");
        
        // If request failed, log the issue (but don't fail on slow responses)
        if ($error || $httpCode !== 200) {
            error_log("⚠ Roboflow request failed - HTTP: $httpCode, Time: " . round($elapsedTime, 2) . "s, Error: " . ($error ?: 'None'));
        } else {
            error_log("✓ Roboflow request completed - HTTP: $httpCode, Time: " . round($elapsedTime, 2) . "s");
        }
        
        // Check if Method 1 returned valid predictions
        $method1Success = false;
        // Process if we got a valid response (removed time limit - API can be slow but still work)
        if ($httpCode === 200 && !$error && !empty($response)) {
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
        
        // Skip additional upload methods to avoid long retry chains
        
        error_log("=== ROBOFLOW DIGIT DETECTION SUMMARY ===");
        error_log("Final method used: $methodUsed");
        error_log("HTTP Code: $httpCode");
        error_log("API URL: " . $inferenceUrl);
        error_log("Model ID: " . $modelId);
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
            error_log('✗ API URL: ' . $inferenceUrl);
            error_log('✗ Model ID: ' . $modelId);
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
                    'message' => 'Roboflow API returned HTML instead of JSON. This usually means the endpoint is wrong or the model is not deployed. Check: ' . $inferenceUrl
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
        $predictionsFound = false;
        
        if (isset($data['predictions']) && is_array($data['predictions'])) {
            $predictions = $data['predictions'];
            $predictionsFound = true;
            error_log("✓ Found 'predictions' array with " . count($predictions) . " items");
        } elseif (isset($data['detections']) && is_array($data['detections'])) {
            $predictions = $data['detections'];
            $predictionsFound = true;
            error_log("✓ Found 'detections' array with " . count($predictions) . " items");
        } elseif (isset($data['results']) && is_array($data['results'])) {
            $predictions = $data['results'];
            $predictionsFound = true;
            error_log("✓ Found 'results' array with " . count($predictions) . " items");
        } elseif (isset($data['data']) && is_array($data['data'])) {
            // Some APIs wrap predictions in 'data' key
            $predictions = $data['data'];
            $predictionsFound = true;
            error_log("✓ Found 'data' array with " . count($predictions) . " items");
        } elseif (isset($data['objects']) && is_array($data['objects'])) {
            // Some APIs use 'objects' key
            $predictions = $data['objects'];
            $predictionsFound = true;
            error_log("✓ Found 'objects' array with " . count($predictions) . " items");
        } else {
            // Check if response is directly an array
            if (is_array($data) && isset($data[0])) {
                $predictions = $data;
                $predictionsFound = true;
                error_log("✓ Response is directly an array with " . count($predictions) . " items");
            }
        }
        
        // CRITICAL: If predictions array exists but is empty, this means the model ran but detected nothing
        if ($predictionsFound && count($predictions) === 0) {
            error_log("⚠⚠⚠ CRITICAL: API returned predictions array but it's EMPTY (no detections) ⚠⚠⚠");
            error_log("   This means:");
            error_log("   1. The API call succeeded (HTTP 200)");
            error_log("   2. The model is deployed and accessible");
            error_log("   3. BUT the model detected NO digits in the image");
            error_log("   Possible reasons:");
            error_log("   - Image quality is too poor");
            error_log("   - Image is too small or cropped incorrectly");
            error_log("   - Digits are not visible or too blurry");
            error_log("   - Model confidence threshold is too high (but we're using 0.05)");
            error_log("   - Model version 7 might need retraining or different preprocessing");
            error_log("   Full API response: " . json_encode($data, JSON_PRETTY_PRINT));
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
            
            // Try multiple possible class name fields (check all possible formats)
            if (isset($prediction['class'])) {
                $className = trim(strval($prediction['class']));
            } elseif (isset($prediction['class_name'])) {
                $className = trim(strval($prediction['class_name']));
            } elseif (isset($prediction['name'])) {
                $className = trim(strval($prediction['name']));
            } elseif (isset($prediction['label'])) {
                $className = trim(strval($prediction['label']));
            } elseif (isset($prediction['class_id'])) {
                // class_id is numeric (0-9), convert to string
                $className = strval($prediction['class_id']);
            } elseif (isset($prediction['id'])) {
                // Some APIs use 'id' for class
                $className = trim(strval($prediction['id']));
            } elseif (isset($prediction['label'])) {
                $className = trim(strval($prediction['label']));
            } elseif (isset($prediction['id'])) {
                // Some APIs use 'id' for class
                $className = trim(strval($prediction['id']));
            }
            
            // Check multiple possible confidence field names
            // If missing, treat as high confidence to avoid dropping valid digits
            $confidence = 1.0;
            if (isset($prediction['confidence'])) {
                $confidence = floatval($prediction['confidence']);
            } elseif (isset($prediction['confidence_score'])) {
                $confidence = floatval($prediction['confidence_score']);
            } elseif (isset($prediction['score'])) {
                $confidence = floatval($prediction['score']);
            } elseif (isset($prediction['prob'])) {
                $confidence = floatval($prediction['prob']);
            }
            
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
            
            // Use confidence threshold of 0.01 (1%) - keep faint digits, filter only near-zero confidence
            if ($isDigit && $confidence > 0.01) {
                $digits[] = [
                    'digit' => $digitValue,
                    'x' => isset($prediction['x']) ? floatval($prediction['x']) : 0,
                    'y' => isset($prediction['y']) ? floatval($prediction['y']) : 0,
                    'width' => isset($prediction['width']) ? floatval($prediction['width']) : 0,
                    'height' => isset($prediction['height']) ? floatval($prediction['height']) : 0,
                    'confidence' => $confidence,
                    'model_id' => $modelId
                ];
                error_log("✓ Roboflow Digit Detection: Found digit '$digitValue' with confidence $confidence at position ({$digits[count($digits)-1]['x']}, {$digits[count($digits)-1]['y']})");
            } elseif ($isDigit && $confidence <= 0.01) {
                error_log("⚠ Roboflow Digit Detection: Digit '$digitValue' found but confidence $confidence is below threshold (0.01)");
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
            error_log('⚠⚠⚠ Roboflow Digit Detection: Found ' . count($predictions) . ' predictions but NONE recognized as digits ⚠⚠⚠');
            error_log('   This means the API returned predictions but they are not in expected format.');
            error_log('   Checking all predictions for digit patterns...');
            foreach ($predictions as $idx => $pred) {
                $allKeys = array_keys($pred);
                $classVal = $pred['class'] ?? $pred['class_name'] ?? $pred['name'] ?? $pred['label'] ?? $pred['class_id'] ?? 'N/A';
                $confVal = $pred['confidence'] ?? $pred['confidence_score'] ?? $pred['score'] ?? 'N/A';
                error_log("   Prediction #$idx: keys=[" . implode(',', $allKeys) . "], class='$classVal', confidence=$confVal");
                error_log("   Full prediction: " . json_encode($pred, JSON_PRETTY_PRINT));
                
                // Try to manually extract digit from this prediction
                $manualDigit = null;
                foreach (['class', 'class_name', 'name', 'label', 'class_id'] as $field) {
                    if (isset($pred[$field])) {
                        $val = strval($pred[$field]);
                        if (preg_match('/^[0-9]$/', $val)) {
                            $manualDigit = $val;
                            error_log("   ⚠ MANUAL EXTRACTION: Found digit '$manualDigit' in field '$field' but it wasn't recognized!");
                            break;
                        } elseif (preg_match('/[0-9]/', $val, $matches)) {
                            $manualDigit = $matches[0];
                            error_log("   ⚠ MANUAL EXTRACTION: Found digit '$manualDigit' in field '$field' (extracted from '$val') but it wasn't recognized!");
                            break;
                        }
                    }
                }
            }
            error_log('   ACTION REQUIRED: Check the prediction format above and update the parsing logic to match version 7 response format.');
        }
        
        if (count($digits) > 0) {
            error_log('✓ Roboflow Digit Detection: Found ' . count($digits) . ' digit(s)');
            error_log('   Digits: ' . implode(', ', array_map(function($d) { return $d['digit'] . '(' . round($d['confidence'], 2) . ')'; }, $digits)));
            return [
                'success' => true,
                'digits' => $digits,
                'all_predictions' => $predictions,
                'model_id' => $modelId
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
                // Check if we got a response but predictions array was empty
                if ($predictionsFound && count($predictions) === 0) {
                    $errorMsg .= '. The API returned an empty predictions array - model ran but detected NO digits.';
                    $errorMsg .= ' This means the model is deployed and working, but it cannot see any digits in this image.';
                    $errorMsg .= ' Possible causes: image too blurry, digits not visible, wrong image region, or model needs retraining.';
                } else {
                    $errorMsg .= '. No detections returned from Roboflow API.';
                    $errorMsg .= ' The model may not be detecting anything, or the image may be too small/cropped.';
                    $errorMsg .= ' IMPORTANT: If version 2 works but version 7 does not, version 7 is likely NOT DEPLOYED.';
                    $errorMsg .= ' Go to Roboflow → Your Project → Deploy → "Integrate with my app or website" → Deploy version 7.';
                }
            }
            error_log('✗ Roboflow Digit Detection: ' . $errorMsg);
            error_log('✗ API URL: ' . $inferenceUrl);
            error_log('✗ Model ID: ' . $modelId);
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
                'api_response' => $data,
                'model_id' => $modelId
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
 * Pick the best contiguous sequence of 5 digits along X (register windows are evenly spaced).
 * For 4 detections, left-pad to 5. For 6+, choose the window with lowest gap variance.
 *
 * @param array $rowDigits Digit entries sorted left-to-right by x
 * @return string|null
 */
function selectRegisterReadingFromRowDigits(array $rowDigits) {
    $n = count($rowDigits);
    if ($n < 4) {
        return null;
    }

    if ($n === 4) {
        $s = implode('', array_map(function ($d) {
            return strval($d['digit'] ?? '');
        }, $rowDigits));
        if (!preg_match('/^\d{4}$/', $s)) {
            return null;
        }
        return str_pad($s, 5, '0', STR_PAD_LEFT);
    }

    if ($n === 5) {
        $s = implode('', array_map(function ($d) {
            return strval($d['digit'] ?? '');
        }, $rowDigits));
        return preg_match('/^\d{5}$/', $s) ? $s : null;
    }

    $bestStart = 0;
    $bestVar = null;
    $bestConfSum = -1.0;
    $bestHSum = -1.0;

    for ($start = 0; $start <= $n - 5; $start++) {
        $win = array_slice($rowDigits, $start, 5);
        $xs = array_map(function ($d) {
            return floatval($d['x'] ?? 0);
        }, $win);
        $gaps = [];
        for ($i = 0; $i < 4; $i++) {
            $gaps[] = max(0.0, $xs[$i + 1] - $xs[$i]);
        }
        $mean = array_sum($gaps) / 4.0;
        $var = 0.0;
        foreach ($gaps as $g) {
            $d = $g - $mean;
            $var += $d * $d;
        }
        $var /= 4.0;

        $confSum = 0.0;
        foreach ($win as $d) {
            $confSum += floatval($d['confidence'] ?? 0);
        }
        $hSum = 0.0;
        foreach ($win as $d) {
            $hSum += floatval($d['height'] ?? 0);
        }

        // Lower variance is better; tie-break: higher confidence, then taller boxes, then more leftward window
        $pick = false;
        if ($bestVar === null) {
            $pick = true;
        } elseif ($var < $bestVar - 1e-9) {
            $pick = true;
        } elseif (abs($var - $bestVar) <= 1e-9) {
            if ($confSum > $bestConfSum + 1e-9) {
                $pick = true;
            } elseif (abs($confSum - $bestConfSum) <= 1e-9) {
                if ($hSum > $bestHSum + 1e-9) {
                    $pick = true;
                } elseif (abs($hSum - $bestHSum) <= 1e-9 && $start < $bestStart) {
                    $pick = true;
                }
            }
        }
        if ($pick) {
            $bestVar = $var;
            $bestConfSum = $confSum;
            $bestHSum = $hSum;
            $bestStart = $start;
        }
    }

    $chosen = array_slice($rowDigits, $bestStart, 5);
    $s = implode('', array_map(function ($d) {
        return strval($d['digit'] ?? '');
    }, $chosen));

    return preg_match('/^\d{5}$/', $s) ? $s : null;
}

/**
 * Extract 5-digit meter reading from detected digits
 * Improved algorithm that handles:
 * - Multi-row digit displays
 * - Overlapping detections (deduplication)
 * - Adaptive row clustering (median digit height) so small dials are not merged with the register
 * - Sliding-window selection when more than 5 boxes appear on the register row
 * @param array $digits Array of digit detections from Roboflow
 * @return string|null 5-digit reading or null if not enough digits
 */
function extractMeterReadingFromDigits($digits) {
    // Require at least 4 digits to form a valid reading candidate.
    // Meter windows are expected to show 5 digits; 4 digits may still be valid if one leading 0 is missed.
    if (empty($digits) || count($digits) < 4) {
        return null;
    }
    
    // Step 1: Filter digits by confidence (remove very low-confidence detections)
    // Must match the confidence threshold in detectDigitsWithRoboflow (0.01)
    $minConfidence = 0.01;
    $filteredDigits = array_filter($digits, function($digit) use ($minConfidence) {
        if (!isset($digit['confidence'])) {
            return true;
        }
        return $digit['confidence'] >= $minConfidence;
    });
    
    if (count($filteredDigits) < 5) {
        error_log("⚠ Less than 5 high-confidence digits. Found " . count($filteredDigits) . " digits with confidence >= $minConfidence");
        if (count($filteredDigits) < 4) {
            error_log("✗ Insufficient high-confidence digits: " . count($filteredDigits) . " < 4 required");
            return null;
        }
    }
    
    // Step 2: Remove only truly overlapping detections.
    // Use bounding-box overlap ratio instead of center-distance so adjacent digits are preserved.
    $deduplicatedDigits = [];
    $overlapThreshold = 0.45; // Consider duplicate only if one box heavily overlaps another

    foreach ($filteredDigits as $digit) {
        $isDuplicate = false;
        $digitConfidence = floatval($digit['confidence']);

        $ax1 = floatval($digit['x']) - (floatval($digit['width']) / 2.0);
        $ay1 = floatval($digit['y']) - (floatval($digit['height']) / 2.0);
        $ax2 = floatval($digit['x']) + (floatval($digit['width']) / 2.0);
        $ay2 = floatval($digit['y']) + (floatval($digit['height']) / 2.0);
        $areaA = max(0.0, ($ax2 - $ax1)) * max(0.0, ($ay2 - $ay1));

        foreach ($deduplicatedDigits as $index => $existingDigit) {
            $existingConfidence = floatval($existingDigit['confidence']);

            $bx1 = floatval($existingDigit['x']) - (floatval($existingDigit['width']) / 2.0);
            $by1 = floatval($existingDigit['y']) - (floatval($existingDigit['height']) / 2.0);
            $bx2 = floatval($existingDigit['x']) + (floatval($existingDigit['width']) / 2.0);
            $by2 = floatval($existingDigit['y']) + (floatval($existingDigit['height']) / 2.0);
            $areaB = max(0.0, ($bx2 - $bx1)) * max(0.0, ($by2 - $by1));

            $ix1 = max($ax1, $bx1);
            $iy1 = max($ay1, $by1);
            $ix2 = min($ax2, $bx2);
            $iy2 = min($ay2, $by2);
            $interArea = max(0.0, ($ix2 - $ix1)) * max(0.0, ($iy2 - $iy1));

            $minArea = min($areaA, $areaB);
            $overlapRatio = ($minArea > 0.0) ? ($interArea / $minArea) : 0.0;

            if ($overlapRatio >= $overlapThreshold) {
                if ($digitConfidence > $existingConfidence) {
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
    
    if (count($deduplicatedDigits) < 4) {
        error_log("⚠ Not enough digits after deduplication. Found " . count($deduplicatedDigits) . " unique digits (need at least 4)");
        error_log("   Original digits: " . count($digits) . ", After confidence filter: " . count($filteredDigits));
        return null;
    }

    // Step 3: Adaptive Y clustering: register digits share similar box height; dial digits are usually smaller / different band.
    $heightsForTol = array_map(function ($d) {
        return floatval($d['height'] ?? 0);
    }, $deduplicatedDigits);
    sort($heightsForTol);
    $medianH = $heightsForTol[(int) floor(count($heightsForTol) / 2)] ?? 0.0;
    $yTolerance = max(18.0, min(45.0, $medianH > 0 ? ($medianH * 0.65) : 35.0));

    $rows = [];
    foreach ($deduplicatedDigits as $digit) {
        $digitY = floatval($digit['y']);
        $assigned = false;
        foreach ($rows as $rowIndex => $rowDigits) {
            $rowAvgY = array_sum(array_map(function ($d) {
                return floatval($d['y']);
            }, $rowDigits)) / count($rowDigits);
            if (abs($digitY - $rowAvgY) <= $yTolerance) {
                $rows[$rowIndex][] = $digit;
                $assigned = true;
                break;
            }
        }
        if (!$assigned) {
            $rows[] = [$digit];
        }
    }

    foreach ($rows as &$row) {
        usort($row, function ($a, $b) {
            return floatval($a['x'] ?? 0) <=> floatval($b['x'] ?? 0);
        });
    }
    unset($row);

    // Step 4: Score rows: prefer a register-like row (about 5 digits, large median height), never merge digits across rows.
    $scoreRow = function (array $row) {
        $c = count($row);
        $hs = array_map(function ($d) {
            return floatval($d['height'] ?? 0);
        }, $row);
        sort($hs);
        $medianRowH = $hs[(int) floor(count($hs) / 2)] ?? 0.0;

        if ($c === 5) {
            $tier = 400;
        } elseif ($c === 4) {
            $tier = 350;
        } elseif ($c === 6) {
            $tier = 330;
        } elseif ($c >= 7) {
            $tier = max(200, 300 - ($c - 7) * 15);
        } else {
            $tier = 100 + $c * 20;
        }

        return $tier * 10000 + $medianRowH;
    };

    usort($rows, function ($a, $b) use ($scoreRow) {
        return $scoreRow($b) <=> $scoreRow($a);
    });

    $reading = null;
    foreach ($rows as $row) {
        if (count($row) < 4) {
            continue;
        }
        $reading = selectRegisterReadingFromRowDigits($row);
        if ($reading !== null) {
            error_log("✓ Extracted meter reading from digits: $reading (row size " . count($row) . ", yTolerance=" . round($yTolerance, 1) . ", from " . count($digits) . " detected / " . count($deduplicatedDigits) . " deduped)");
            return $reading;
        }
    }

    error_log("✗ Failed to extract valid 5-digit reading from any row (rows=" . count($rows) . ", yTolerance=" . round($yTolerance, 1) . ")");
    return null;
}

