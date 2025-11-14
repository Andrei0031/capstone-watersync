<?php
/**
 * Tesseract OCR API for Meter Reading
 * This endpoint processes images using Tesseract OCR
 * You can train Tesseract to improve accuracy for water meters
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/config.php';

// Function to send JSON response
function sendResponse($success, $message, $data = null) {
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) {
        $response['data'] = $data;
    }
    echo json_encode($response);
    exit();
}

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Only POST method allowed');
}

// Get authorization token
$headers = getallheaders();
$authToken = $headers['Authorization'] ?? $headers['authorization'] ?? '';

// Validate authorization (you can customize this)
if (empty($authToken)) {
    sendResponse(false, 'Authorization token required');
}

// Get image data
$input = json_decode(file_get_contents('php://input'), true);
$imageBase64 = $input['image'] ?? '';

if (empty($imageBase64)) {
    sendResponse(false, 'Image data required');
}

try {
    // Decode base64 image
    $imageData = base64_decode($imageBase64);
    if ($imageData === false) {
        throw new Exception('Invalid base64 image data');
    }
    
    // Create temporary file
    $tempDir = sys_get_temp_dir();
    $tempFile = tempnam($tempDir, 'ocr_') . '.png';
    file_put_contents($tempFile, $imageData);
    
    // Check if Tesseract is installed
    $tesseractPath = 'tesseract'; // Default path, adjust if needed
    // For Windows XAMPP, you might need: 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe'
    // For Linux: 'tesseract' (usually in PATH)
    
    // Try to detect Tesseract path
    $possiblePaths = [
        'tesseract',
        'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
        'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
        '/usr/bin/tesseract',
        '/usr/local/bin/tesseract',
    ];
    
    $tesseractFound = false;
    foreach ($possiblePaths as $path) {
        $testCommand = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') 
            ? "\"$path\" --version 2>nul" 
            : "$path --version 2>/dev/null";
        
        $output = [];
        $returnVar = 0;
        exec($testCommand, $output, $returnVar);
        
        if ($returnVar === 0) {
            $tesseractPath = $path;
            $tesseractFound = true;
            break;
        }
    }
    
    if (!$tesseractFound) {
        // Fallback: try direct execution
        $testOutput = [];
        @exec('tesseract --version 2>&1', $testOutput, $testReturn);
        if ($testReturn !== 0) {
            sendResponse(false, 'Tesseract OCR is not installed. OCR feature is disabled. You can still submit meter readings manually.', [
                'tesseract_available' => false,
                'message' => 'Please install Tesseract OCR to enable automatic reading extraction, or enter readings manually.'
            ]);
        }
    }
    
    // Prepare Tesseract command
    // Use PSM mode 6 (Assume uniform block of text) for meter displays
    // Use PSM mode 7 (Treat image as single text line) as alternative
    $outputFile = $tempFile . '_output';
    $language = 'eng'; // Default English, can be changed to trained model like 'eng+watermeter'
    
    // Build command
    $command = sprintf(
        '"%s" "%s" "%s" -l %s --psm 6 2>&1',
        $tesseractPath,
        escapeshellarg($tempFile),
        escapeshellarg($outputFile),
        escapeshellarg($language)
    );
    
    // Execute Tesseract OCR
    $output = [];
    $returnVar = 0;
    exec($command, $output, $returnVar);
    
    // Read OCR result
    $resultFile = $outputFile . '.txt';
    $extractedText = '';
    
    if (file_exists($resultFile)) {
        $extractedText = trim(file_get_contents($resultFile));
        unlink($resultFile); // Clean up
    }
    
    // Clean up temporary files
    if (file_exists($tempFile)) {
        unlink($tempFile);
    }
    
    if ($returnVar !== 0 || empty($extractedText)) {
        // Try alternative PSM mode
        $command2 = sprintf(
            '"%s" "%s" "%s" -l %s --psm 7 2>&1',
            $tesseractPath,
            escapeshellarg($tempFile),
            escapeshellarg($outputFile),
            escapeshellarg($language)
        );
        
        exec($command2, $output2, $returnVar2);
        $resultFile2 = $outputFile . '.txt';
        if (file_exists($resultFile2)) {
            $extractedText = trim(file_get_contents($resultFile2));
            unlink($resultFile2);
        }
    }
    
    if (empty($extractedText)) {
        throw new Exception('OCR failed to extract text. Error: ' . implode(' ', $output));
    }
    
    // Extract 5-digit meter reading from text
    $meterReading = extractMeterReading($extractedText);
    
    sendResponse(true, 'OCR processing successful', [
        'extracted_text' => $extractedText,
        'meter_reading' => $meterReading,
        'ocr_engine' => 'Tesseract',
        'language' => $language
    ]);
    
} catch (Exception $e) {
    sendResponse(false, 'OCR processing failed: ' . $e->getMessage());
}

/**
 * Extract 5-digit meter reading from OCR text
 * Looks for pattern: 5 digits beside "m³"
 */
function extractMeterReading($text) {
    // Normalize text - replace common OCR mistakes
    $normalized = $text;
    // Replace C or O that might be 0 when followed by digits
    $normalized = preg_replace('/\b([CO])(?=\d)/i', '0', $normalized);
    // Remove spaces between digits
    $normalized = preg_replace('/(\d)\s+(\d)/', '$1$2', $normalized);
    
    // Strategy 1: Look for 5-digit number beside "m³" or "m3"
    $pattern1 = '/(\d{5})\s*(?:m[³3]|m\s*cubed|m\s*3)/i';
    if (preg_match($pattern1, $normalized, $matches)) {
        return str_pad($matches[1], 5, '0', STR_PAD_LEFT);
    }
    
    // Strategy 2: Look for pattern "m³" followed by 5 digits
    $pattern2 = '/(?:m[³3]|m\s*cubed|m\s*3)\s*(\d{5})/i';
    if (preg_match($pattern2, $normalized, $matches)) {
        return str_pad($matches[1], 5, '0', STR_PAD_LEFT);
    }
    
    // Strategy 3: Flexible pattern for OCR errors (e.g., "C04 4 2 m" -> "00442")
    $pattern3 = '/([CO0]\s*[CO0]?\s*\d\s*\d\s*\d\s*\d)\s*(?:m[³3]|m\s*cubed|m\s*3)/i';
    if (preg_match($pattern3, $text, $matches)) {
        $reading = preg_replace('/\s+/', '', $matches[1]);
        $reading = str_replace(['C', 'O'], '0', $reading);
        $reading = str_pad($reading, 5, '0', STR_PAD_LEFT);
        if (strlen($reading) === 5) {
            return $reading;
        }
    }
    
    // Strategy 4: Look for 5-digit number in lines containing "m³"
    $lines = explode("\n", $normalized);
    foreach ($lines as $line) {
        if (preg_match('/m[³3]|m\s*3/i', $line)) {
            if (preg_match('/(\d{5})/', $line, $matches)) {
                return str_pad($matches[1], 5, '0', STR_PAD_LEFT);
            }
        }
    }
    
    return '';
}

