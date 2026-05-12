<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: adminlogin.php');
    exit();
}

include 'db.php';
include 'timezone_helper.php';

watersync_force_timezone($conn);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: bulk_billing_entry.php');
    exit();
}

$client_id = intval($_POST['client_id'] ?? 0);

if ($client_id <= 0) {
    $_SESSION['bulk_message'] = 'Invalid customer';
    $_SESSION['bulk_status'] = 'danger';
    header('Location: bulk_billing_entry.php');
    exit();
}

$client_sql = 'SELECT category_id FROM client_list WHERE id = ? AND delete_flag = 0';
$stmt = $conn->prepare($client_sql);
$stmt->bind_param('i', $client_id);
$stmt->execute();
$client_result = $stmt->get_result();

if ($client_result->num_rows === 0) {
    $_SESSION['bulk_message'] = 'Customer not found';
    $_SESSION['bulk_status'] = 'danger';
    header('Location: bulk_billing_entry.php');
    exit();
}

$client = $client_result->fetch_assoc();
$category_id = $client['category_id'];
$stmt->close();

$rate_sql = 'SELECT rate, excess_rate FROM category_rates WHERE category_id = ?';
$rate_stmt = $conn->prepare($rate_sql);
$rate_stmt->bind_param('i', $category_id);
$rate_stmt->execute();
$rate_result = $rate_stmt->get_result();
$rate_row = $rate_result->fetch_assoc();
$rate_stmt->close();

if (!$rate_row) {
    $_SESSION['bulk_message'] = 'Category rate not found';
    $_SESSION['bulk_status'] = 'danger';
    header('Location: bulk_billing_entry.php');
    exit();
}

$base_rate = floatval($rate_row['rate'] ?? 0);
$excess_rate = floatval($rate_row['excess_rate'] ?? 0);

// Bulk save: December–March when marked Paid (November reading anchors December bill).
// April verified reading is UI reference only (create April bill elsewhere).
$months = [
    'dec' => ['month' => 12, 'year' => 2025, 'label' => 'December 2025'],
    'jan' => ['month' => 1, 'year' => 2026, 'label' => 'January 2026'],
    'feb' => ['month' => 2, 'year' => 2026, 'label' => 'February 2026'],
    'mar' => ['month' => 3, 'year' => 2026, 'label' => 'March 2026'],
];

$readings = [];
$readings['nov'] = round(floatval($_POST['nov_reading'] ?? 0));
$statuses = [];
foreach ($months as $key => $_info) {
    $readings[$key] = round(floatval($_POST[$key . '_reading'] ?? 0));
    $statuses[$key] = strtolower(trim((string) ($_POST[$key . '_status'] ?? 'pending'))) === 'paid' ? 'paid' : 'pending';
}

$success_count = 0;
$error_count = 0;
$errors = [];
$saved_labels = [];

$conn->begin_transaction();

foreach ($months as $key => $month_info) {
    if ($statuses[$key] !== 'paid') {
        continue;
    }

    $curr_reading = $readings[$key];
    $prev_key = null;
    if ($key === 'dec') {
        $prev_key = 'nov';
    } elseif ($key === 'jan') {
        $prev_key = 'dec';
    } elseif ($key === 'feb') {
        $prev_key = 'jan';
    } elseif ($key === 'mar') {
        $prev_key = 'feb';
    }

    if ($curr_reading <= 0 || !$prev_key || $readings[$prev_key] <= 0) {
        $error_count++;
        $errors[] = $month_info['label'] . ': Set meter readings before marking this month as Paid';
        continue;
    }

    $prev_reading = $readings[$prev_key];
    $consumption = $curr_reading - $prev_reading;

    if ($consumption < 0) {
        $error_count++;
        $errors[] = $month_info['label'] . ': Current reading cannot be less than previous reading';
        continue;
    }

    if ($consumption <= 6) {
        $amount = ($consumption / 6) * $base_rate;
    } else {
        $excess_usage = $consumption - 6;
        $amount = $base_rate + ($excess_usage * $excess_rate);
    }

    $check_sql = 'SELECT id FROM billing_list
                 WHERE client_id = ?
                 AND MONTH(reading_date) = ?
                 AND YEAR(reading_date) = ?';
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param('iii', $client_id, $month_info['month'], $month_info['year']);
    $check_stmt->execute();

    if ($check_stmt->get_result()->num_rows > 0) {
        $error_count++;
        $errors[] = $month_info['label'] . ': Billing record already exists for this month';
        $check_stmt->close();
        continue;
    }
    $check_stmt->close();

    $reading_date = date('Y-m-d H:i:s', mktime(0, 0, 0, $month_info['month'] + 1, 0, $month_info['year']));
    $due_date = date('Y-m-d', mktime(0, 0, 0, $month_info['month'] + 2, 15, $month_info['year']));
    // ✓ Paid on bulk form = bill is saved and treated as fully settled (matches billing list + customer modal).
    $bill_status = 1;

    $insert_sql = 'INSERT INTO billing_list
                  (client_id, reading_date, previous, reading, total, status, due_date)
                  VALUES (?, ?, ?, ?, ?, ?, ?)';
    $insert_stmt = $conn->prepare($insert_sql);
    $insert_stmt->bind_param('isdddis', $client_id, $reading_date, $prev_reading, $curr_reading, $amount, $bill_status, $due_date);

    if ($insert_stmt->execute()) {
        $new_bill_id = (int) $conn->insert_id;
        $payment_ts = date('Y-m-d H:i:s');
        $pay_sql = 'INSERT INTO payment_list (client_id, billing_id, payment_date, amount, payment_method, reference_number, status, verified_date)
                    VALUES (?, ?, ?, ?, ?, ?, 1, ?)';
        $pay_stmt = $conn->prepare($pay_sql);
        if (!$pay_stmt) {
            $del = $conn->prepare('DELETE FROM billing_list WHERE id = ?');
            $del->bind_param('i', $new_bill_id);
            $del->execute();
            $del->close();
            $error_count++;
            $errors[] = $month_info['label'] . ': Could not prepare payment record (' . $conn->error . ')';
        } else {
            $pm = 'bulk_billing';
            $ref = 'BULK';
            $pay_stmt->bind_param('iisdsss', $client_id, $new_bill_id, $payment_ts, $amount, $pm, $ref, $payment_ts);
            if (!$pay_stmt->execute()) {
                $del = $conn->prepare('DELETE FROM billing_list WHERE id = ?');
                $del->bind_param('i', $new_bill_id);
                $del->execute();
                $del->close();
                $error_count++;
                $errors[] = $month_info['label'] . ': Payment record failed (' . $pay_stmt->error . ')';
            } else {
                $success_count++;
                $saved_labels[] = $month_info['label'];
            }
            $pay_stmt->close();
        }
    } else {
        $error_count++;
        $errors[] = $month_info['label'] . ': ' . $insert_stmt->error;
    }
    $insert_stmt->close();
}

if ($success_count > 0) {
    $conn->commit();
    $pending_left = [];
    foreach ($months as $key => $month_info) {
        if (($statuses[$key] ?? '') !== 'paid') {
            $pending_left[] = $month_info['label'];
        }
    }
    $_SESSION['bulk_message'] = 'Saved ' . $success_count . ' bill(s) as fully paid: ' . implode(', ', $saved_labels)
        . '. Each bill has a matching verified payment (bulk billing).';
    if (!empty($pending_left)) {
        $_SESSION['bulk_message'] .= ' No bill created this submit for (still ⏳ Pending): ' . implode(', ', $pending_left) . '.';
    }
    if ($error_count > 0) {
        $_SESSION['bulk_message'] .= ' Issues: ' . implode('; ', array_slice($errors, 0, 8));
    }
    $_SESSION['bulk_status'] = 'success';
} else {
    $conn->rollback();
    if ($error_count > 0) {
        $_SESSION['bulk_message'] = 'No bills saved. ' . implode(', ', $errors);
    } else {
        $_SESSION['bulk_message'] = 'No bills saved. Mark December–March as ✓ Paid for each month you want to record (enter November + December readings for a December bill). April is reference-only on this form and is not inserted from bulk save.';
    }
    $_SESSION['bulk_status'] = 'danger';
}

header('Location: bulk_billing_entry.php');
exit();
