<?php
session_start();
require_once 'session_validation.php';
include 'db.php';

header('Content-Type: application/json');

if (!isset($_POST['report_id']) || !isset($_POST['status'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}

$report_id = $_POST['report_id'];
$new_status = $_POST['status'];
$resolution_notes = $_POST['resolution_notes'] ?? '';

$update_sql = "UPDATE outage_reports SET 
               status = ?, 
               resolution_notes = ?,
               resolved_at = " . ($new_status == 1 ? "CURRENT_TIMESTAMP" : "NULL") . "
               WHERE id = ?";

try {
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param("isi", $new_status, $resolution_notes, $report_id);
    $success = $stmt->execute();
    
    if ($success) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update report']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} 