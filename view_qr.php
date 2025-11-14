<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("HTTP/1.1 403 Forbidden");
    exit('Access denied');
}

if (!isset($_GET['id']) || !isset($_GET['meter'])) {
    header("HTTP/1.1 400 Bad Request");
    exit('Missing parameters');
}

$client_id = intval($_GET['id']);
$meter_code = preg_replace('/[^A-Za-z0-9\-_]/', '', $_GET['meter']);

// Set the content type to PNG
header('Content-Type: image/png');

// Generate QR code data
$qr_data = json_encode([
    'meter_code' => $meter_code,
    'client_id' => $client_id,
    'timestamp' => time()
]);

// Include QR library
require_once __DIR__ . '/phpqrcode/qrlib.php';

// Output QR code directly to browser
QRcode::png($qr_data, false, 'M', 8, 2);
exit(); 