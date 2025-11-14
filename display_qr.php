<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("HTTP/1.1 403 Forbidden");
    exit('Access denied');
}

// Get the filename from the query string
$filename = isset($_GET['file']) ? basename($_GET['file']) : '';
if (empty($filename)) {
    header("HTTP/1.1 400 Bad Request");
    exit('No file specified');
}

// Validate filename format
if (!preg_match('/^qr_meter_[a-zA-Z0-9\-_]+_client_\d+_\d+\.png$/', $filename)) {
    header("HTTP/1.1 400 Bad Request");
    exit('Invalid file format');
}

// Set the full path to the QR code file
$filepath = __DIR__ . '/qr_codes/' . $filename;

// Check if file exists
if (!file_exists($filepath)) {
    header("HTTP/1.1 404 Not Found");
    exit('File not found');
}

// Set proper content type and cache headers
header('Content-Type: image/png');
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: public, max-age=86400');
header('Pragma: public');

// Output the file
readfile($filepath);
exit(); 