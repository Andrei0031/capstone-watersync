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
define('ROBOFLOW_MODEL_VERSION', '8'); // Reading-area model
define('ROBOFLOW_MODEL_ID', 'watersync-oekrf/8'); // Model ID format: project/version
// Serverless API endpoint format: https://serverless.roboflow.com/{model_id}
define('ROBOFLOW_INFERENCE_URL', 'https://serverless.roboflow.com/' . ROBOFLOW_MODEL_ID . '?api_key=' . ROBOFLOW_API_KEY);

// Digit Detection Model Configuration
// Using model_id format from Roboflow "Hosted Image Inference"
// Format: "project-name/version" (e.g., "watersync-oekrf/7")
define('ROBOFLOW_DIGIT_MODEL_ID', 'watersync-oekrf/7'); // Digit detection for register reading

// Build inference URL (serverless endpoint only)
// Serverless API endpoint format: https://serverless.roboflow.com/{model_id}
define('ROBOFLOW_DIGIT_INFERENCE_URL', 'https://serverless.roboflow.com/' . ROBOFLOW_DIGIT_MODEL_ID . '?api_key=' . ROBOFLOW_API_KEY);

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
        
        // Padding: fixed minimum + % of box so the full odometer row stays in frame (reduces single-spurious-digit crops)
        $padding = max(36, (int) round(0.14 * max($width, $height)));
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
 * Upscale small crops so the digit model sees larger characters (reduces "only one digit" failures).
 * @return string|null Path to temp JPEG, or null if upscale not needed / GD unavailable
 */
function roboflowCreateUpscaledImageForDigitInference($imagePath) {
    if (!extension_loaded('gd') || !file_exists($imagePath)) {
        return null;
    }
    $info = @getimagesize($imagePath);
    if (!$info) {
        return null;
    }
    $w = $info[0];
    $h = $info[1];
    $minSide = min($w, $h);
    $targetMin = 900;
    if ($minSide >= $targetMin) {
        return null;
    }
    $scale = min(3.8, $targetMin / max(1, $minSide));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));

    $imageType = $info[2];
    switch ($imageType) {
        case IMAGETYPE_JPEG:
            $src = @imagecreatefromjpeg($imagePath);
            break;
        case IMAGETYPE_PNG:
            $src = @imagecreatefrompng($imagePath);
            break;
        case IMAGETYPE_GIF:
            $src = @imagecreatefromgif($imagePath);
            break;
        default:
            return null;
    }
    if (!$src) {
        return null;
    }
    $dst = imagecreatetruecolor($nw, $nh);
    if (!$dst) {
        imagedestroy($src);
        return null;
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $outPath = $imagePath . '_roboflow_digit_upscale.jpg';
    imagejpeg($dst, $outPath, 94);
    imagedestroy($src);
    imagedestroy($dst);
    error_log("Roboflow digit upscale: {$w}x{$h} -> {$nw}x{$nh} saved " . basename($outPath));
    return $outPath;
}

/**
 * IoU for Roboflow boxes (center x, y, width, height).
 */
function roboflowDetectionBoxIoU(array $a, array $b) {
    $ax = floatval($a['x'] ?? 0);
    $ay = floatval($a['y'] ?? 0);
    $aw = floatval($a['width'] ?? 0);
    $ah = floatval($a['height'] ?? 0);
    $bx = floatval($b['x'] ?? 0);
    $by = floatval($b['y'] ?? 0);
    $bw = floatval($b['width'] ?? 0);
    $bh = floatval($b['height'] ?? 0);

    $a_x1 = $ax - $aw / 2.0;
    $a_y1 = $ay - $ah / 2.0;
    $a_x2 = $ax + $aw / 2.0;
    $a_y2 = $ay + $ah / 2.0;
    $b_x1 = $bx - $bw / 2.0;
    $b_y1 = $by - $bh / 2.0;
    $b_x2 = $bx + $bw / 2.0;
    $b_y2 = $by + $bh / 2.0;

    $ix1 = max($a_x1, $b_x1);
    $iy1 = max($a_y1, $b_y1);
    $ix2 = min($a_x2, $b_x2);
    $iy2 = min($a_y2, $b_y2);
    $iw = max(0.0, $ix2 - $ix1);
    $ih = max(0.0, $iy2 - $iy1);
    $inter = $iw * $ih;
    $areaA = max(0.0, $a_x2 - $a_x1) * max(0.0, $a_y2 - $a_y1);
    $areaB = max(0.0, $b_x2 - $b_x1) * max(0.0, $b_y2 - $b_y1);
    $union = $areaA + $areaB - $inter;
    return ($union > 1e-9) ? ($inter / $union) : 0.0;
}

function roboflowPredictionConfidence(array $p) {
    if (isset($p['confidence'])) {
        return floatval($p['confidence']);
    }
    if (isset($p['confidence_score'])) {
        return floatval($p['confidence_score']);
    }
    if (isset($p['score'])) {
        return floatval($p['score']);
    }
    if (isset($p['prob'])) {
        return floatval($p['prob']);
    }
    return 0.0;
}

/**
 * Merge duplicate boxes from multi-scale inference (keep higher confidence).
 */
function roboflowMergeOverlappingDetections(array $predictions, $iouThreshold = 0.55) {
    if (count($predictions) < 2) {
        return $predictions;
    }
    usort($predictions, function ($x, $y) {
        return roboflowPredictionConfidence($y) <=> roboflowPredictionConfidence($x);
    });
    $kept = [];
    foreach ($predictions as $p) {
        $duplicate = false;
        foreach ($kept as $k) {
            if (roboflowDetectionBoxIoU($p, $k) >= $iouThreshold) {
                $duplicate = true;
                break;
            }
        }
        if (!$duplicate) {
            $kept[] = $p;
        }
    }
    return $kept;
}

function roboflowExtractPredictionsArrayFromApiData($data) {
    if (!is_array($data)) {
        return [];
    }
    if (isset($data['predictions']) && is_array($data['predictions'])) {
        return $data['predictions'];
    }
    if (isset($data['detections']) && is_array($data['detections'])) {
        return $data['detections'];
    }
    if (isset($data['results']) && is_array($data['results'])) {
        return $data['results'];
    }
    if (isset($data['data']) && is_array($data['data'])) {
        return $data['data'];
    }
    if (isset($data['objects']) && is_array($data['objects'])) {
        return $data['objects'];
    }
    if (isset($data[0]) && is_array($data)) {
        return $data;
    }
    return [];
}

/**
 * Hosted serverless infer: raw base64 in POST body.
 * @return array [httpCode, responseBody, curlError]
 */
function roboflowDigitModelServerlessInfer($imageBinary, $modelId) {
    $inferenceUrl = 'https://serverless.roboflow.com/' . $modelId . '?api_key=' . ROBOFLOW_API_KEY;
    $base64Image = base64_encode($imageBinary);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $inferenceUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $base64Image);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return [$httpCode, $response, $error];
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

        $upscaledPath = roboflowCreateUpscaledImageForDigitInference($imagePath);
        $inferencePaths = [];
        if ($upscaledPath && file_exists($upscaledPath)) {
            $inferencePaths[] = $upscaledPath;
        }
        $inferencePaths[] = $imagePath;
        $inferencePaths = array_values(array_unique(array_filter($inferencePaths)));

        $mergedRawPredictions = [];
        $anyHttp200 = false;
        $lastHttpCode = 0;
        $lastCurlError = '';
        $lastResponseBody = '';

        foreach ($inferencePaths as $inferPath) {
            $imageData = @file_get_contents($inferPath);
            if ($imageData === false || $imageData === '') {
                continue;
            }
            list($httpCode, $response, $curlErr) = roboflowDigitModelServerlessInfer($imageData, $modelId);
            $lastHttpCode = (int) $httpCode;
            $lastCurlError = $curlErr ? (string) $curlErr : '';
            $lastResponseBody = is_string($response) ? $response : '';
            if ($lastCurlError !== '' || $lastHttpCode !== 200 || $lastResponseBody === '') {
                error_log('Roboflow digit pass ' . basename($inferPath) . ': HTTP ' . $lastHttpCode . ' err=' . ($lastCurlError ?: '-'));
                continue;
            }
            $anyHttp200 = true;
            $decoded = json_decode($lastResponseBody, true);
            if (!is_array($decoded)) {
                continue;
            }
            $batch = roboflowExtractPredictionsArrayFromApiData($decoded);
            foreach ($batch as $pr) {
                $mergedRawPredictions[] = $pr;
            }
            error_log('Roboflow digit pass ' . basename($inferPath) . ': raw detections=' . count($batch));
        }

        if ($upscaledPath && $upscaledPath !== $imagePath && file_exists($upscaledPath)) {
            @unlink($upscaledPath);
        }

        if (!$anyHttp200) {
            if ($lastCurlError !== '') {
                return [
                    'success' => false,
                    'digits' => [],
                    'message' => 'Roboflow API error: ' . $lastCurlError,
                ];
            }
            $errorMsg = 'Roboflow Digit Detection API error: HTTP ' . $lastHttpCode;
            if ($lastHttpCode === 401) {
                $errorMsg .= ' - Unauthorized. Check your API key in roboflow_service.php';
            } elseif ($lastHttpCode === 404) {
                $errorMsg .= ' - Not found. Deploy the digit model for Hosted Image Inference.';
            } elseif ($lastHttpCode === 405) {
                $errorMsg .= ' - Method Not Allowed. Deploy the digit model in Roboflow.';
            } else {
                $errorMsg .= ' - ' . substr($lastResponseBody, 0, 500);
            }
            return [
                'success' => false,
                'digits' => [],
                'message' => $errorMsg,
            ];
        }

        $beforeMerge = count($mergedRawPredictions);
        $mergedRawPredictions = roboflowMergeOverlappingDetections($mergedRawPredictions, 0.55);
        error_log('Roboflow digit multi-scale merge: ' . $beforeMerge . ' -> ' . count($mergedRawPredictions) . ' boxes');

        $response = $lastResponseBody;
        $httpCode = $lastHttpCode;
        $error = $lastCurlError;
        $data = json_decode($response, true);
        if (!is_array($data)) {
            $data = ['predictions' => $mergedRawPredictions];
        }
        $data['predictions'] = $mergedRawPredictions;
        $predictions = $mergedRawPredictions;
        // Normalized response always includes a predictions array (possibly empty).
        $predictionsFound = true;

        error_log('Roboflow Digit Detection merged predictions: ' . count($predictions));

        if (count($predictions) === 0) {
            error_log('⚠ Roboflow Digit Detection: merged multi-scale pass returned no digit boxes (check crop, image size, or model).');
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
            // Named digit classes only — avoid "m3", "v8", etc. being read as digits.
            elseif (preg_match('/^(?:digit|d|n|num|number)[_-]?([0-9])$/i', $className, $matches)) {
                $isDigit = true;
                $digitValue = $matches[1];
                error_log("   Extracted digit '$digitValue' from class name '$className'");
            } elseif (preg_match('/^(\d)[_-](?:digit|class)?$/i', $className, $matches)) {
                $isDigit = true;
                $digitValue = $matches[1];
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

