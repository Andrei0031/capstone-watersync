<?php
session_start();
include 'db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$report_id = $_POST['report_id'];
$status = $_POST['status'];
$notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';

$valid_statuses = ['pending', 'investigating', 'fixing', 'resolved'];
if (!in_array($status, $valid_statuses)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit;
}

$sql = "UPDATE outage_reports SET status = ?, resolution_notes = ?";
if ($status === 'resolved') {
    $sql .= ", resolved_date = CURRENT_TIMESTAMP";
}
$sql .= " WHERE id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ssi", $status, $notes, $report_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Report status updated successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Error updating report status']);
}

$stmt->close();
$conn->close();
?> 