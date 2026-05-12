<?php
header('Content-Type: application/json');

include 'db.php';
include 'timezone_helper.php';

watersync_force_timezone($conn);

$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;

if ($client_id <= 0) {
    echo json_encode(['success' => false, 'verified_reading' => null]);
    exit();
}

// Get the latest verified reading from April billing cycle for this customer
// Try multiple search patterns: "April", "04/", "2026-04", or just use the most recent verified reading
$sql = "SELECT 
            pmr.id,
            pmr.verified_reading,
            pmr.ocr_reading,
            pmr.reading_value,
            pmr.processed_date,
            pmr.processed_at,
            bc.name as cycle_name,
            pmr.status
        FROM pending_meter_readings pmr
        JOIN billing_cycles bc ON pmr.billing_cycle_id = bc.id
        WHERE pmr.client_id = ? 
        AND pmr.status = 'verified'
        AND (
            bc.name LIKE '%April%'
            OR bc.name LIKE '%04%'
            OR bc.name LIKE '%4%'
            OR MONTH(bc.start_date) = 4
        )
        ORDER BY pmr.processed_date DESC, pmr.processed_at DESC
        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $client_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();

if ($row && $row['status'] === 'verified') {
    // Use verified_reading if available, otherwise use ocr_reading, otherwise reading_value
    $readingValue = $row['verified_reading'] ?? $row['ocr_reading'] ?? $row['reading_value'] ?? null;
    
    if ($readingValue !== null) {
        $processDate = $row['processed_date'] ?? $row['processed_at'];
        
        echo json_encode([
            'success' => true,
            'verified_reading' => floatval($readingValue),
            'cycle_name' => $row['cycle_name'],
            'processed_date' => $processDate,
            'reading_id' => $row['id']
        ]);
        exit;
    }
}

// Fallback: If no April reading found, try to get the most recent verified reading
$fallback_sql = "SELECT 
            pmr.id,
            pmr.verified_reading,
            pmr.ocr_reading,
            pmr.reading_value,
            pmr.processed_date,
            pmr.processed_at,
            bc.name as cycle_name,
            pmr.status
        FROM pending_meter_readings pmr
        LEFT JOIN billing_cycles bc ON pmr.billing_cycle_id = bc.id
        WHERE pmr.client_id = ? 
        AND pmr.status = 'verified'
        ORDER BY pmr.processed_date DESC, pmr.processed_at DESC
        LIMIT 1";

$fallback_stmt = $conn->prepare($fallback_sql);
$fallback_stmt->bind_param("i", $client_id);
$fallback_stmt->execute();
$fallback_result = $fallback_stmt->get_result();
$fallback_row = $fallback_result->fetch_assoc();
$fallback_stmt->close();

if ($fallback_row && $fallback_row['status'] === 'verified') {
    $readingValue = $fallback_row['verified_reading'] ?? $fallback_row['ocr_reading'] ?? $fallback_row['reading_value'] ?? null;
    
    if ($readingValue !== null) {
        $processDate = $fallback_row['processed_date'] ?? $fallback_row['processed_at'];
        
        echo json_encode([
            'success' => true,
            'verified_reading' => floatval($readingValue),
            'cycle_name' => $fallback_row['cycle_name'] ?? 'Verified Reading',
            'processed_date' => $processDate,
            'reading_id' => $fallback_row['id']
        ]);
        exit;
    }
}

// No verified reading found
echo json_encode(['success' => false, 'verified_reading' => null]);

