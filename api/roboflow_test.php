<?php
// Simple debug script to run Roboflow digit detection on a given image
// Usage (CLI): php api/roboflow_test.php "/full/path/to/image.jpg"
// Usage (web): http://yourserver/api/roboflow_test.php?image=/full/path/to/image.jpg

require_once __DIR__ . '/ocr_functions.php'; // This will include roboflow_service.php as needed

// Determine image path from CLI arg or GET param
$imagePath = null;
if (PHP_SAPI === 'cli') {
    if (isset($argv[1])) {
        $imagePath = $argv[1];
    }
} else {
    if (isset($_GET['image'])) {
        $imagePath = $_GET['image'];
    }
}

header('Content-Type: application/json');
if (empty($imagePath)) {
    $msg = ['success' => false, 'error' => 'No image path provided. Use CLI arg or ?image=/path/to/file.jpg'];
    echo json_encode($msg, JSON_PRETTY_PRINT);
    exit(1);
}

// Normalize path
$imagePath = trim($imagePath);

$result = processImageWithRoboflowDigits($imagePath);

// Save debug output to a file for inspection
$debugDir = __DIR__ . '/debug_logs';
if (!is_dir($debugDir)) {
    @mkdir($debugDir, 0755, true);
}
$basename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', basename($imagePath));
$logPath = $debugDir . '/roboflow_test_' . $basename . '_' . date('Ymd_His') . '.json';
@file_put_contents($logPath, json_encode(['image' => $imagePath, 'result' => $result], JSON_PRETTY_PRINT));

// Also output to stdout / HTTP response
echo json_encode(['image' => $imagePath, 'result' => $result, 'log_file' => $logPath], JSON_PRETTY_PRINT);

?>