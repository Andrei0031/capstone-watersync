<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: adminlogin.php");
    exit();
}

include 'db.php';

$month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$month_display = date('F Y', strtotime($month));

$sql = "SELECT b.*, c.firstname, c.lastname, c.meter_code 
        FROM billing_list b 
        LEFT JOIN client_list c ON b.client_id = c.id 
        WHERE b.status = 1 
        AND DATE_FORMAT(b.reading_date, '%Y-%m') = ?
        ORDER BY b.reading_date DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $month);
$stmt->execute();
$result = $stmt->get_result();

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="paid_bills_' . $month . '.csv"');

// Create a file pointer connected to PHP output stream
$output = fopen('php://output', 'w');

// Add UTF-8 BOM for proper Excel encoding
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Set column headers
fputcsv($output, [
    'Bill Number',
    'Customer Name',
    'Meter Code',
    'Reading Date',
    'Previous Reading',
    'Current Reading',
    'Consumption',
    'Amount',
    'Payment Date'
]);

// Add rows to CSV
while ($row = $result->fetch_assoc()) {
    $consumption = $row['reading'] - $row['previous'];
    fputcsv($output, [
        $row['id'],
        $row['firstname'] . ' ' . $row['lastname'],
        $row['meter_code'],
        date('M d, Y', strtotime($row['reading_date'])),
        $row['previous'],
        $row['reading'],
                    number_format($consumption, 2),
        '₱' . number_format($row['total'], 2),
        date('M d, Y', strtotime($row['updated_at'] ?? $row['reading_date']))
    ]);
}

fclose($output); 