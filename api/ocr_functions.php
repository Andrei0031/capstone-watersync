<?php
/**
 * OCR Functions for Server-Side Processing
 * This file contains OCR functions that can be used by both API and web interface
 * No API authentication required - functions only
 * 
 * Now uses Roboflow YOLOv8 for digit detection instead of Tesseract OCR
 */

/** Minimum weakest-digit confidence (0–1) to auto-verify pending readings without manual review. */
if (!defined('OCR_AUTO_VERIFY_MIN_DIGIT_CONFIDENCE')) {
    define('OCR_AUTO_VERIFY_MIN_DIGIT_CONFIDENCE', 0.76);
}
/** Minimum mean digit confidence for auto-verify (both bars must pass). */
if (!defined('OCR_AUTO_VERIFY_AVG_DIGIT_CONFIDENCE')) {
    define('OCR_AUTO_VERIFY_AVG_DIGIT_CONFIDENCE', 0.70);
}
/**
 * Laplacian variance (on a downscaled grayscale copy) below this ⇒ image treated as too blurry for auto-verify.
 * Tune on real samples: lower ⇒ more rows go to needs_review.
 */
if (!defined('METER_IMAGE_BLUR_LAPLACIAN_THRESHOLD')) {
    define('METER_IMAGE_BLUR_LAPLACIAN_THRESHOLD', 110.0);
}
/** Longest edge (px) when measuring blur (smaller = faster, threshold is calibrated for this). */
if (!defined('METER_IMAGE_BLUR_MAX_EDGE')) {
    define('METER_IMAGE_BLUR_MAX_EDGE', 320);
}
/**
 * Weakest digit confidence below this ⇒ numbers look blurry/unreliable to the model ⇒ needs manual review.
 * (Stricter than auto-verify min: gap 0.72–0.76 is review-only, not auto-verified.)
 */
if (!defined('OCR_REVIEW_IF_WEAKEST_DIGIT_BELOW')) {
    define('OCR_REVIEW_IF_WEAKEST_DIGIT_BELOW', 0.72);
}

/**
 * True when OCR is strong enough to set status to verified without admin review.
 * Needs weakest + average digit confidence at the auto-verify bars, no requires_review flag
 * (weak digits, loose reading, soft focus without strong digits, etc.).
 */
function ocrResultQualifiesForAutoVerify(array $ocrResult) {
    if (!empty($ocrResult['requires_review'])) {
        return false;
    }
    $digitStats = $ocrResult['digit_stats'] ?? null;
    if (!$digitStats || !is_array($digitStats)) {
        return false;
    }
    $digitCount = (int) ($digitStats['count'] ?? 0);
    $minConf = (float) ($digitStats['min_confidence'] ?? 0.0);
    $avgConf = (float) ($digitStats['avg_confidence'] ?? 0.0);
    return $digitCount >= 5
        && $minConf >= OCR_AUTO_VERIFY_MIN_DIGIT_CONFIDENCE
        && $avgConf >= OCR_AUTO_VERIFY_AVG_DIGIT_CONFIDENCE;
}

/**
 * Grayscale luminance 0-255 at one pixel (truecolor image).
 */
function ocrGrayAt($im, $x, $y) {
    $rgb = imagecolorat($im, $x, $y);
    $r = ($rgb >> 16) & 0xFF;
    $g = ($rgb >> 8) & 0xFF;
    $b = $rgb & 0xFF;
    return (int) round(0.299 * $r + 0.587 * $g + 0.114 * $b);
}

/**
 * Blur / focus hint via variance of Laplacian (higher = sharper). Used to force needs_review on soft photos.
 *
 * @return array{variance: ?float, is_blurry: bool, skipped: bool}
 */
function assessMeterImageBlurFromPath($imagePath) {
    $out = ['variance' => null, 'is_blurry' => false, 'skipped' => true];
    if (!extension_loaded('gd') || !is_readable($imagePath)) {
        return $out;
    }
    $info = @getimagesize($imagePath);
    if ($info === false) {
        return $out;
    }
    $type = $info[2];
    $src = null;
    switch ($type) {
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
            return $out;
    }
    if (!$src) {
        return $out;
    }

    $w0 = imagesx($src);
    $h0 = imagesy($src);
    if ($w0 < 3 || $h0 < 3) {
        imagedestroy($src);
        return $out;
    }

    $maxEdge = (int) METER_IMAGE_BLUR_MAX_EDGE;
    $scale = min(1.0, $maxEdge / max($w0, $h0));
    $w = max(8, (int) round($w0 * $scale));
    $h = max(8, (int) round($h0 * $scale));

    $gray = imagecreatetruecolor($w, $h);
    if (!$gray) {
        imagedestroy($src);
        return $out;
    }
    imagecopyresampled($gray, $src, 0, 0, 0, 0, $w, $h, $w0, $h0);
    imagedestroy($src);

    $lap = [];
    for ($y = 1; $y < $h - 1; $y++) {
        for ($x = 1; $x < $w - 1; $x++) {
            $c = ocrGrayAt($gray, $x, $y);
            $t = ocrGrayAt($gray, $x, $y - 1);
            $b = ocrGrayAt($gray, $x, $y + 1);
            $l = ocrGrayAt($gray, $x - 1, $y);
            $r = ocrGrayAt($gray, $x + 1, $y);
            $lap[] = (float) (4 * $c - $t - $b - $l - $r);
        }
    }
    imagedestroy($gray);

    $n = count($lap);
    if ($n < 1) {
        return $out;
    }
    $mean = array_sum($lap) / $n;
    $var = 0.0;
    foreach ($lap as $v) {
        $d = $v - $mean;
        $var += $d * $d;
    }
    $var /= $n;

    $out['variance'] = $var;
    $out['skipped'] = false;
    $out['is_blurry'] = $var < (float) METER_IMAGE_BLUR_LAPLACIAN_THRESHOLD;
    return $out;
}

/**
 * Mark review when the weakest digit score is low (model unsure / likely blurry characters).
 *
 * @param array $result OCR result (mutated)
 */
function ocrAttachWeakDigitReviewFlags(array &$result) {
    if (empty($result['success']) || empty($result['meter_reading'])) {
        return;
    }
    $stats = $result['digit_stats'] ?? null;
    if (!$stats || !is_array($stats)) {
        return;
    }
    $minD = (float) ($stats['min_confidence'] ?? 0.0);
    $n = (int) ($stats['count'] ?? 0);
    if ($n >= 4 && $minD < (float) OCR_REVIEW_IF_WEAKEST_DIGIT_BELOW) {
        $result['requires_review'] = true;
        $result['digits_look_unreliable'] = true;
        error_log('OCR: requires_review (weak digit confidence) min_conf=' . round($minD, 3) . ' count=' . $n);
    }
}

/**
 * Soft-focus hint: only force needs_review when digits are not already clearly strong.
 * High digit confidence ⇒ trust the model for auto-verify even if the whole frame is a bit soft.
 *
 * @param array $result OCR result (mutated)
 */
function ocrAttachBlurReviewFlags(array &$result, $imagePathUsed) {
    $blur = assessMeterImageBlurFromPath($imagePathUsed);
    $result['blur_laplacian_variance'] = $blur['variance'];
    $result['image_likely_blurry'] = !empty($blur['is_blurry']);

    $stats = $result['digit_stats'] ?? [];
    $minD = (float) ($stats['min_confidence'] ?? 0.0);
    $avgD = (float) ($stats['avg_confidence'] ?? 0.0);
    $digitsClearlyStrong = $minD >= OCR_AUTO_VERIFY_MIN_DIGIT_CONFIDENCE
        && $avgD >= OCR_AUTO_VERIFY_AVG_DIGIT_CONFIDENCE;

    if (!empty($blur['is_blurry']) && !$digitsClearlyStrong) {
        $result['requires_review'] = true;
        error_log('OCR: requires_review (soft focus + digit scores not all strong) laplacian_var=' . ($blur['variance'] !== null ? round($blur['variance'], 2) : 'null') . ' min_conf=' . round($minD, 3) . ' path=' . basename((string) $imagePathUsed));
    } elseif (!empty($blur['is_blurry']) && $digitsClearlyStrong) {
        error_log('OCR: soft focus but digit confidences high; not forcing review laplacian_var=' . ($blur['variance'] !== null ? round($blur['variance'], 2) : 'null'));
    }
}

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
 * Create focused crops around the likely odometer/register window.
 * Analog meter photos often contain small dial digits that confuse object detection.
 */
function createMeterRegisterCropCandidates($imagePath) {
    if (!extension_loaded('gd') || !file_exists($imagePath)) {
        return [];
    }

    try {
        $imageInfo = @getimagesize($imagePath);
        if (!$imageInfo) {
            return [];
        }

        $imageType = $imageInfo[2];
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
                return [];
        }

        if (!$image) {
            return [];
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $regions = [
            // Close-up photos that are already mostly register: full frame + center band first.
            ['x' => 0.0, 'y' => 0.0, 'w' => 1.0, 'h' => 1.0, 'name' => 'full_frame'],
            ['x' => 0.02, 'y' => 0.12, 'w' => 0.96, 'h' => 0.76, 'name' => 'center_band'],
            // Full-meter shots: odometer usually upper-center.
            ['x' => 0.18, 'y' => 0.04, 'w' => 0.64, 'h' => 0.28, 'name' => 'upper_center'],
            ['x' => 0.12, 'y' => 0.00, 'w' => 0.76, 'h' => 0.36, 'name' => 'upper_wide'],
            ['x' => 0.22, 'y' => 0.10, 'w' => 0.56, 'h' => 0.24, 'name' => 'register_tight'],
            ['x' => 0.05, 'y' => 0.00, 'w' => 0.90, 'h' => 0.45, 'name' => 'top_half'],
        ];

        $candidatePaths = [];
        foreach ($regions as $region) {
            $x = max(0, (int) round($width * $region['x']));
            $y = max(0, (int) round($height * $region['y']));
            $cropW = min($width - $x, (int) round($width * $region['w']));
            $cropH = min($height - $y, (int) round($height * $region['h']));

            if ($cropW < 20 || $cropH < 10) {
                continue;
            }

            $crop = imagecrop($image, [
                'x' => $x,
                'y' => $y,
                'width' => $cropW,
                'height' => $cropH
            ]);
            if (!$crop) {
                continue;
            }

            $scale = max(2, min(4, (int) ceil(900 / max(1, $cropW))));
            $scaledW = $cropW * $scale;
            $scaledH = $cropH * $scale;
            $scaled = imagecreatetruecolor($scaledW, $scaledH);
            imagecopyresampled($scaled, $crop, 0, 0, 0, 0, $scaledW, $scaledH, $cropW, $cropH);

            imagefilter($scaled, IMG_FILTER_GRAYSCALE);
            imagefilter($scaled, IMG_FILTER_CONTRAST, -35);
            imagefilter($scaled, IMG_FILTER_SMOOTH, -1);

            $candidatePath = $imagePath . '_register_' . $region['name'] . '.jpg';
            imagejpeg($scaled, $candidatePath, 95);
            $candidatePaths[] = $candidatePath;

            imagedestroy($crop);
            imagedestroy($scaled);
        }

        imagedestroy($image);
        return $candidatePaths;
    } catch (Exception $e) {
        error_log('Register crop candidate generation failed: ' . $e->getMessage());
        return [];
    }
}

function cleanupOcrCandidateImages($paths) {
    foreach ($paths as $path) {
        if ($path && file_exists($path)) {
            @unlink($path);
        }
    }
}

function processImageWithOcrSpace($imagePath) {
    if (!file_exists($imagePath)) {
        return [
            'success' => false,
            'extracted_text' => '',
            'meter_reading' => null,
            'error' => 'Image file not found: ' . $imagePath
        ];
    }

    if (!function_exists('curl_init')) {
        return [
            'success' => false,
            'extracted_text' => '',
            'meter_reading' => null,
            'error' => 'PHP cURL extension is required for OCR.space'
        ];
    }

    $apiKey = getenv('OCR_SPACE_API_KEY');
    if (!$apiKey) {
        // OCR.space provides "helloworld" as a limited demo key. Use a real key in production.
        $apiKey = 'helloworld';
    }

    $processedImagePath = preprocessImageForOCR($imagePath);
    $cleanupPreprocessed = ($processedImagePath !== $imagePath);

    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.ocr.space/parse/image');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'apikey' => $apiKey,
            'language' => 'eng',
            'OCREngine' => '2',
            'scale' => 'true',
            'isOverlayRequired' => 'false',
            'detectOrientation' => 'false',
            'file' => new CURLFile($processedImagePath)
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return [
                'success' => false,
                'extracted_text' => '',
                'meter_reading' => null,
                'error' => 'OCR.space API error: ' . $curlError
            ];
        }

        if ($httpCode !== 200 || !$response) {
            return [
                'success' => false,
                'extracted_text' => '',
                'meter_reading' => null,
                'error' => 'OCR.space HTTP error: ' . $httpCode . '. Response: ' . substr((string)$response, 0, 200)
            ];
        }

        $data = json_decode($response, true);
        if (!$data) {
            return [
                'success' => false,
                'extracted_text' => '',
                'meter_reading' => null,
                'error' => 'OCR.space returned invalid JSON: ' . json_last_error_msg()
            ];
        }

        if (!empty($data['IsErroredOnProcessing'])) {
            $messages = $data['ErrorMessage'] ?? $data['ErrorDetails'] ?? 'OCR.space processing failed';
            if (is_array($messages)) {
                $messages = implode(' ', $messages);
            }
            return [
                'success' => false,
                'extracted_text' => '',
                'meter_reading' => null,
                'error' => 'OCR.space failed: ' . $messages
            ];
        }

        $parsedText = '';
        if (!empty($data['ParsedResults']) && is_array($data['ParsedResults'])) {
            foreach ($data['ParsedResults'] as $result) {
                $parsedText .= "\n" . ($result['ParsedText'] ?? '');
            }
        }

        $parsedText = trim($parsedText);
        $meterReading = extractMeterReadingFromText($parsedText);

        if ($meterReading) {
            $ocrSpaceOk = [
                'success' => true,
                'extracted_text' => $parsedText,
                'meter_reading' => $meterReading,
                'digit_stats' => [
                    'count' => strlen($meterReading),
                    'min_confidence' => 0.0,
                    'avg_confidence' => 0.0,
                ],
                'requires_review' => true,
            ];
            ocrAttachWeakDigitReviewFlags($ocrSpaceOk);
            ocrAttachBlurReviewFlags($ocrSpaceOk, $imagePath);
            return $ocrSpaceOk;
        }

        return [
            'success' => false,
            'extracted_text' => $parsedText,
            'meter_reading' => null,
            'error' => 'OCR.space could not extract a valid meter reading. Text: ' . substr($parsedText, 0, 120)
        ];
    } finally {
        if ($cleanupPreprocessed && file_exists($processedImagePath)) {
            @unlink($processedImagePath);
        }
    }
}

/**
 * Roboflow digit model first, then OCR.space, then Tesseract.
 * Meter crop from detectAndCropMeterWithRoboflow() in the caller.
 */
function processMeterImageWithFallbacks($imagePath, $croppedImagePath = null) {
    $attempts = [];
    $lastError = null;
    $roboflowError = null;
    $ocrSpaceError = null;
    $tesseractError = null;
    $candidatePaths = [];

    $tryOcr = function ($path, $method, $label) use (&$attempts, &$lastError, &$roboflowError, &$ocrSpaceError, &$tesseractError) {
        if (!$path || !file_exists($path)) {
            return null;
        }

        $attempts[] = $method . ':' . $label;
        error_log("Attempting $method OCR on $label image: $path");

        if ($method === 'Roboflow') {
            $result = processImageWithRoboflowDigits($path);
        } elseif ($method === 'OCRSpace') {
            $result = processImageWithOcrSpace($path);
        } else {
            $result = processImageWithTesseract($path);
        }

        if ($result['success'] && !empty($result['meter_reading'])) {
            $result['extracted_text'] = ($result['extracted_text'] ?? '') . ' [' . strtoupper($method) . '_' . strtoupper($label) . ']';
            $result['ocr_engine'] = $method;
            $result['attempts'] = $attempts;
            error_log("OCR success with $method on $label: " . $result['meter_reading']);
            return $result;
        }

        $error = $result['error'] ?? ($method . ' OCR failed on ' . $label);
        if ($method === 'Roboflow') {
            $roboflowError = $error;
            $lastError = $error;
        } elseif ($method === 'OCRSpace') {
            $ocrSpaceError = $error;
            $lastError = $error;
        } else {
            $tesseractError = $error;
            if ($lastError === null) {
                $lastError = $error;
            }
        }
        error_log("$method OCR failed on $label: $error");
        return null;
    };

    try {
        if ($croppedImagePath && $croppedImagePath !== $imagePath) {
            $result = $tryOcr($croppedImagePath, 'Roboflow', 'cropped');
            if ($result) {
                return $result;
            }

            $result = $tryOcr($croppedImagePath, 'OCRSpace', 'roboflow_crop');
            if ($result) {
                return $result;
            }
        }

        $candidateSources = array_unique(array_filter([$croppedImagePath, $imagePath]));
        foreach ($candidateSources as $sourcePath) {
            if ($sourcePath && file_exists($sourcePath)) {
                $candidatePaths = array_merge($candidatePaths, createMeterRegisterCropCandidates($sourcePath));
            }
        }

        foreach ($candidatePaths as $candidatePath) {
            $result = $tryOcr($candidatePath, 'Roboflow', 'register_crop');
            if ($result) {
                return $result;
            }
        }

        foreach ($candidatePaths as $candidatePath) {
            $result = $tryOcr($candidatePath, 'OCRSpace', 'register_crop');
            if ($result) {
                return $result;
            }
        }

        foreach ($candidatePaths as $candidatePath) {
            $result = $tryOcr($candidatePath, 'Tesseract', 'register_crop');
            if ($result) {
                return $result;
            }
        }

        $result = $tryOcr($imagePath, 'Roboflow', 'original');
        if ($result) {
            return $result;
        }

        $result = $tryOcr($imagePath, 'OCRSpace', 'original');
        if ($result) {
            return $result;
        }

        $result = $tryOcr($imagePath, 'Tesseract', 'original');
        if ($result) {
            return $result;
        }

        $finalError = $roboflowError ?: $ocrSpaceError ?: $lastError ?: 'No OCR result.';
        if ($tesseractError && stripos($tesseractError, 'not installed') === false) {
            $finalError .= ' Optional Tesseract fallback also failed: ' . $tesseractError;
        }

        return [
            'success' => false,
            'extracted_text' => '',
            'meter_reading' => null,
            'error' => 'OCR failed after trying register crops and original image. ' . $finalError,
            'attempts' => $attempts,
        ];
    } finally {
        cleanupOcrCandidateImages($candidatePaths);
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

    $tesseractOk = [
        'success' => true,
        'extracted_text' => $extractedText,
        'meter_reading' => $meterReading,
    ];
    if (!empty($meterReading)) {
        ocrAttachWeakDigitReviewFlags($tesseractOk);
        ocrAttachBlurReviewFlags($tesseractOk, $imagePath);
    }
    return $tesseractOk;
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

    // Do not concatenate digit blobs; that creates fake long strings and wrong first \d{5} matches.
    foreach (array_unique([$allDigits, $originalDigits]) as $digitBlob) {
        if ($digitBlob === '' || $digitBlob === null) {
            continue;
        }
        if (preg_match('/(\d{5})/', $digitBlob, $matches)) {
            return $matches[1];
        }
        if (preg_match('/(\d{4,6})/', $digitBlob, $matches)) {
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
/**
 * @return string|null
 */
function buildLooseReadingFromDetectedDigits($digits) {
    if (count($digits) < 4) {
        return null;
    }

    $workingDigits = array_values(array_filter($digits, function ($digit) {
        return floatval($digit['confidence'] ?? 0.0) > 0.01 && preg_match('/^\d$/', strval($digit['digit'] ?? ''));
    }));

    if (count($workingDigits) < 4) {
        return null;
    }

    usort($workingDigits, function ($a, $b) {
        return floatval($a['x'] ?? 0) <=> floatval($b['x'] ?? 0);
    });

    if (function_exists('selectRegisterReadingFromRowDigits')) {
        $reading = selectRegisterReadingFromRowDigits($workingDigits);
        if ($reading !== null) {
            return $reading;
        }
    }

    $digitString = implode('', array_map(function ($digit) {
        return strval($digit['digit'] ?? '');
    }, array_slice($workingDigits, 0, 5)));

    if (strlen($digitString) === 4) {
        $digitString = str_pad($digitString, 5, '0', STR_PAD_LEFT);
    }

    return preg_match('/^\d{5}$/', $digitString) ? $digitString : null;
}

function buildRoboflowReadingCandidate($digitResult) {
    $digits = $digitResult['digits'] ?? [];
    $modelId = $digitResult['model_id'] ?? 'unknown';
    $reading = !empty($digits) ? extractMeterReadingFromDigits($digits) : null;
    $usedLooseReading = false;
    if ($reading === null && count($digits) >= 4) {
        $reading = buildLooseReadingFromDetectedDigits($digits);
        $usedLooseReading = ($reading !== null);
    }
    $confidences = [];
    $extractedText = "Model $modelId detected digits: ";

    foreach ($digits as $digit) {
        $c = isset($digit['confidence']) ? floatval($digit['confidence']) : 0.0;
        $confidences[] = $c;
        $extractedText .= ($digit['digit'] ?? '?') . ' (confidence: ' . round($c, 2) . ', x: ' . round(floatval($digit['x'] ?? 0)) . ') ';
    }

    return [
        'success' => !empty($reading),
        'model_id' => $modelId,
        'reading' => $reading,
        'used_loose_reading' => $usedLooseReading,
        'digits' => $digits,
        'extracted_text' => trim($extractedText),
        'digit_stats' => [
            'count' => count($digits),
            'min_confidence' => !empty($confidences) ? min($confidences) : 0.0,
            'avg_confidence' => !empty($confidences) ? array_sum($confidences) / count($confidences) : 0.0,
        ],
        'raw_result' => $digitResult,
    ];
}

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
        
        // Step 1: Detect digits using the configured single Roboflow OCR model.
        $modelId = defined('ROBOFLOW_DIGIT_MODEL_ID') ? ROBOFLOW_DIGIT_MODEL_ID : null;

        error_log("Calling detectDigitsWithRoboflow() model: " . ($modelId ?? 'default'));
        $startTime = microtime(true);
        $digitResult = detectDigitsWithRoboflow($imagePath, $modelId);
        $elapsedTime = microtime(true) - $startTime;
        
        error_log("Roboflow detection returned in " . round($elapsedTime, 2) . "s:");
        error_log("  success: " . ($digitResult['success'] ? 'true' : 'false') . ', digits: ' . count($digitResult['digits'] ?? []));
        if (isset($digitResult['api_response'])) {
            error_log("  api_response keys: " . implode(', ', array_keys($digitResult['api_response'])));
        }
        
        // Log timing but don't fail on slow responses - Roboflow API can be slow but still work
        if ($elapsedTime > 30) {
            error_log('⚠ Roboflow took a long time (' . round($elapsedTime, 2) . 's) but continuing...');
        }
        
        if (!$digitResult['success']) {
            $errorMsg = 'Roboflow digit detection failed';
            if (!empty($digitResult['message'])) {
                $errorMsg .= ': ' . $digitResult['message'];
            }
            error_log('✗ Roboflow OCR: ' . $errorMsg);
            error_log("=== ROBOFLOW OCR PROCESSING END (FAILED) ===");
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

        $candidate = buildRoboflowReadingCandidate($digitResult);
        
        if ($candidate['success']) {
            $meterReading = $candidate['reading'];
            $requiresReview = !empty($candidate['used_loose_reading']) || $meterReading === '00000';
            $digitStats = $candidate['digit_stats'];
            if ($requiresReview) {
                // Prevent auto-verify when the result is loose or suspicious.
                $digitStats['min_confidence'] = 0.0;
            }

            $extractedText = $candidate['extracted_text']
                . ' [SINGLE_MODEL model=' . $candidate['model_id']
                . ' loose=' . ($candidate['used_loose_reading'] ? 'yes' : 'no')
                . ' zero=' . ($meterReading === '00000' ? 'yes' : 'no')
                . ' review=' . ($requiresReview ? 'yes' : 'no')
                . ']';

            error_log("✓ Roboflow OCR: Selected reading $meterReading from model {$candidate['model_id']}");
            $roboflowOk = [
                'success' => true,
                'extracted_text' => $extractedText,
                'meter_reading' => $meterReading,
                'digit_stats' => $digitStats,
                'digits' => $candidate['digits'],
                'model_id' => $candidate['model_id'],
                'single_model' => [
                    'model_id' => $candidate['model_id'],
                    'reading' => $candidate['reading'],
                    'used_loose_reading' => $candidate['used_loose_reading'],
                    'requires_review' => $requiresReview,
                ],
                'requires_review' => $requiresReview,
            ];
            ocrAttachWeakDigitReviewFlags($roboflowOk);
            ocrAttachBlurReviewFlags($roboflowOk, $imagePath);
            return $roboflowOk;
        } else {
            $digits = $digitResult['digits'] ?? [];
            $errorMsg = 'Could not form 5-digit reading from Roboflow model ' . ($digitResult['model_id'] ?? ($modelId ?? 'default'))
                . '. Found ' . count($digits)
                . ' digit(s): ' . implode(', ', array_map(function($d) { return $d['digit']; }, $digits));
            error_log('✗ Roboflow OCR: ' . $errorMsg);
            return [
                'success' => false,
                'extracted_text' => $candidate['extracted_text'],
                'meter_reading' => null,
                'error' => $errorMsg,
                'digit_stats' => [
                    'count' => count($digits),
                    'min_confidence' => 0.0,
                    'avg_confidence' => 0.0,
                ],
                'digits' => $digits,
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

