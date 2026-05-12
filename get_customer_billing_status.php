<?php
header('Content-Type: application/json');

include 'db.php';
include 'timezone_helper.php';

watersync_force_timezone($conn);

// Check if we should return all customers with readings
if (isset($_GET['all']) && $_GET['all'] == '1') {
    // Customers with a meter reading on file (pending OCR/verified) OR at least one bill in billing_list
    $sql = "SELECT
                cl.id AS client_id,
                cl.firstname,
                cl.lastname,
                cl.meter_code,
                COALESCE(
                    (
                        SELECT COALESCE(pmr.verified_reading, pmr.ocr_reading, pmr.reading_value)
                        FROM pending_meter_readings pmr
                        WHERE pmr.client_id = cl.id
                          AND pmr.status IN ('verified', 'needs_review', 'processed', 'pending')
                          AND COALESCE(pmr.verified_reading, pmr.ocr_reading, pmr.reading_value, 0) > 0
                        ORDER BY pmr.processed_at DESC, pmr.id DESC
                        LIMIT 1
                    ),
                    (
                        SELECT bl.reading
                        FROM billing_list bl
                        WHERE bl.client_id = cl.id
                        ORDER BY bl.reading_date DESC, bl.id DESC
                        LIMIT 1
                    ),
                    0
                ) AS reading_value,
                (
                    SELECT bc.cycle_name
                    FROM pending_meter_readings pmr2
                    LEFT JOIN billing_cycles bc ON pmr2.billing_cycle_id = bc.id
                    WHERE pmr2.client_id = cl.id
                      AND pmr2.status IN ('verified', 'needs_review', 'processed', 'pending')
                    ORDER BY pmr2.processed_at DESC, pmr2.id DESC
                    LIMIT 1
                ) AS cycle_name,
                COALESCE(
                    (
                        SELECT pmr3.processed_at
                        FROM pending_meter_readings pmr3
                        WHERE pmr3.client_id = cl.id
                          AND pmr3.status IN ('verified', 'needs_review', 'processed', 'pending')
                        ORDER BY pmr3.processed_at DESC, pmr3.id DESC
                        LIMIT 1
                    ),
                    (
                        SELECT bl2.reading_date
                        FROM billing_list bl2
                        WHERE bl2.client_id = cl.id
                        ORDER BY bl2.reading_date DESC, bl2.id DESC
                        LIMIT 1
                    ),
                    NOW()
                ) AS sort_date
            FROM client_list cl
            WHERE cl.delete_flag = 0
              AND cl.status = 1
              AND (
                  EXISTS (
                      SELECT 1 FROM pending_meter_readings pmr
                      WHERE pmr.client_id = cl.id
                        AND pmr.status IN ('verified', 'needs_review', 'processed', 'pending')
                        AND COALESCE(pmr.verified_reading, pmr.ocr_reading, pmr.reading_value, 0) > 0
                  )
                  OR EXISTS (
                      SELECT 1 FROM billing_list bl3 WHERE bl3.client_id = cl.id
                  )
              )
            HAVING reading_value > 0
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
