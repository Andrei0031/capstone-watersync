<?php
header('Content-Type: application/json');

include 'db.php';
include 'timezone_helper.php';

watersync_force_timezone($conn);

// Check if we should return all customers with readings
if (isset($_GET['all']) && $_GET['all'] == '1') {
    // Customers who already finished bulk billing for Jan–Apr 2026 (same window as bulk_billing_entry).
    // December is the starting point in the UI and does not get a bill row from bulk save.
    $sql = "SELECT
                cl.id AS client_id,
                cl.firstname,
                cl.lastname,
                cl.meter_code,
                (
                    SELECT bl.reading
                    FROM billing_list bl
                    WHERE bl.client_id = cl.id
                      AND YEAR(bl.reading_date) = 2026
                      AND MONTH(bl.reading_date) = 4
                    ORDER BY bl.reading_date DESC, bl.id DESC
                    LIMIT 1
                ) AS reading_value,
                'Dec 2025 – Apr 2026 (complete)' AS cycle_name,
                (
                    SELECT MAX(bl2.reading_date)
                    FROM billing_list bl2
                    WHERE bl2.client_id = cl.id
                      AND YEAR(bl2.reading_date) = 2026
                      AND MONTH(bl2.reading_date) IN (1, 2, 3, 4)
                ) AS sort_date
            FROM client_list cl
            INNER JOIN (
                SELECT bl3.client_id
                FROM billing_list bl3
                WHERE YEAR(bl3.reading_date) = 2026
                  AND MONTH(bl3.reading_date) IN (1, 2, 3, 4)
                GROUP BY bl3.client_id
                HAVING COUNT(DISTINCT MONTH(bl3.reading_date)) >= 4
            ) bulk_done ON bulk_done.client_id = cl.id
            WHERE cl.delete_flag = 0
              AND cl.status = 1
            HAVING reading_value IS NOT NULL AND reading_value > 0
            ORDER BY sort_date DESC
            LIMIT 200";

    $result = $conn->query($sql);
    $customers = [];

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $customers[] = [
                'client_id' => (int) $row['client_id'],
                'firstname' => $row['firstname'],
                'lastname' => $row['lastname'],
                'meter_code' => $row['meter_code'],
                'verified_reading' => floatval($row['reading_value']),
                'cycle_name' => $row['cycle_name'] ?: 'Billing / reading on file',
                'processed_date' => $row['sort_date'],
            ];
        }
    }

    echo json_encode($customers);
    exit;
}

// Otherwise, return billing status for a single customer
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;

if (!$client_id) {
    echo json_encode(['success' => false, 'has_billing' => false]);
    exit;
}

// Check if customer has existing billing records
$sql = "SELECT COUNT(*) as count, MAX(reading_date) as last_billing_date 
        FROM billing_list 
        WHERE client_id = ? 
        ORDER BY reading_date DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $client_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

$hasBilling = $row['count'] > 0;
$lastBillingDate = $row['last_billing_date'];

echo json_encode([
    'success' => true,
    'has_billing' => $hasBilling,
    'billing_count' => intval($row['count']),
    'last_billing_date' => $lastBillingDate ? date('F j, Y', strtotime($lastBillingDate)) : null
]);

$stmt->close();
?>
