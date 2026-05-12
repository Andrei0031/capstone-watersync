<?php
header('Content-Type: application/json');

include 'db.php';
include 'timezone_helper.php';

watersync_force_timezone($conn);

// Check if we should return all customers with readings
if (isset($_GET['all']) && $_GET['all'] == '1') {
    // Bulk entry saves Dec 2025 + Jan–Mar 2026 when complete; April is separate.
    $sql = "SELECT
                cl.id AS client_id,
                cl.firstname,
                cl.lastname,
                cl.meter_code,
                (
                    SELECT COALESCE(pmr.verified_reading, pmr.ocr_reading, pmr.reading_value)
                    FROM pending_meter_readings pmr
                    WHERE pmr.client_id = cl.id
                      AND pmr.status IN ('verified', 'needs_review', 'processed', 'pending')
                      AND COALESCE(pmr.verified_reading, pmr.ocr_reading, pmr.reading_value, 0) > 0
                    ORDER BY pmr.processed_date DESC, pmr.processed_at DESC
                    LIMIT 1
                ) AS reading_value,
                'Dec 2025 – Mar 2026 bulk complete' AS cycle_name,
                (
                    SELECT MAX(bl2.reading_date)
                    FROM billing_list bl2
                    WHERE bl2.client_id = cl.id
                      AND (
                          (YEAR(bl2.reading_date) = 2025 AND MONTH(bl2.reading_date) = 12)
                          OR (YEAR(bl2.reading_date) = 2026 AND MONTH(bl2.reading_date) IN (1, 2, 3))
                      )
                ) AS sort_date
            FROM client_list cl
            INNER JOIN (
                SELECT bl3.client_id
                FROM billing_list bl3
                WHERE (YEAR(bl3.reading_date) = 2025 AND MONTH(bl3.reading_date) = 12)
                   OR (YEAR(bl3.reading_date) = 2026 AND MONTH(bl3.reading_date) IN (1, 2, 3))
                GROUP BY bl3.client_id
                HAVING COUNT(DISTINCT (YEAR(bl3.reading_date) * 100 + MONTH(bl3.reading_date))) >= 4
            ) bulk_done ON bulk_done.client_id = cl.id
            WHERE cl.delete_flag = 0
              AND cl.status = 1
            ORDER BY sort_date DESC
            LIMIT 200";

    $result = $conn->query($sql);
    $customers = [];

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $ref = $row['reading_value'];
            $customers[] = [
                'client_id' => (int) $row['client_id'],
                'firstname' => $row['firstname'],
                'lastname' => $row['lastname'],
                'meter_code' => $row['meter_code'],
                'verified_reading' => ($ref !== null && $ref !== '' && floatval($ref) > 0) ? floatval($ref) : null,
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
