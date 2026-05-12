<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: adminlogin.php');
    exit();
}

include 'db.php';
include 'timezone_helper.php';

watersync_force_timezone($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id = intval($_POST['client_id'] ?? 0);

    if ($client_id <= 0) {
        $_SESSION['bulk_message'] = 'Invalid customer';
        $_SESSION['bulk_status'] = 'danger';
        header('Location: bulk_billing_entry.php');
        exit();
    }

    // Get client details
    $client_sql = "SELECT category_id FROM client_list WHERE id = ? AND delete_flag = 0";
    $stmt = $conn->prepare($client_sql);
    $stmt->bind_param("i", $client_id);
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

    // Get rate for category
    $rate_sql = "SELECT rate, excess_rate FROM category_rates WHERE category_id = ?";
    $rate_stmt = $conn->prepare($rate_sql);
    $rate_stmt->bind_param("i", $category_id);
    $rate_stmt->execute();
    $rate_result = $rate_stmt->get_result();
    $rate_row = $rate_result->fetch_assoc();
    $rate_stmt->close();

    $base_rate = $rate_row['rate'] ?? 0;
    $excess_rate = $rate_row['excess_rate'] ?? 0;

    // Define the 5 months (December 2025 to April 2026)
    $months = [
        'dec' => ['month' => 12, 'year' => 2025, 'label' => 'December 2025'],
        'jan' => ['month' => 1, 'year' => 2026, 'label' => 'January 2026'],
        'feb' => ['month' => 2, 'year' => 2026, 'label' => 'February 2026'],
        'mar' => ['month' => 3, 'year' => 2026, 'label' => 'March 2026'],
        'apr' => ['month' => 4, 'year' => 2026, 'label' => 'April 2026']
    ];

    $success_count = 0;
    $error_count = 0;
    $errors = [];

    $conn->begin_transaction();

    foreach ($months as $key => $month_info) {
        $prev_key = $key . '_prev';
        $curr_key = $key . '_curr';

        $prev_reading = floatval($_POST[$prev_key] ?? 0);
        $curr_reading = floatval($_POST[$curr_key] ?? 0);

        // Skip if no readings entered
        if ($prev_reading == 0 && $curr_reading == 0) {
            continue;
        }

        // Calculate consumption and bill
        $consumption = $curr_reading - $prev_reading;
        
        if ($consumption < 0) {
            $error_count++;
            $errors[] = $month_info['label'] . ": Current reading cannot be less than previous reading";
            continue;
        }

        // Apply water rate calculation (2-tier pricing)
        if ($consumption <= 6) {
            $amount = ($consumption / 6) * $base_rate;
        } else {
            $excess_usage = $consumption - 6;
            $amount = $base_rate + ($excess_usage * $excess_rate);
        }

        // Check if billing already exists for this month
        $check_sql = "SELECT id FROM billing_list 
                     WHERE client_id = ? 
                     AND MONTH(reading_date) = ? 
                     AND YEAR(reading_date) = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("iii", $client_id, $month_info['month'], $month_info['year']);
        $check_stmt->execute();
        
        if ($check_stmt->get_result()->num_rows > 0) {
            $error_count++;
            $errors[] = $month_info['label'] . ": Billing record already exists";
            $check_stmt->close();
            continue;
        }
        $check_stmt->close();

        // Create reading date (last day of the month or today if same month)
        $reading_date = date('Y-m-d H:i:s', mktime(0, 0, 0, $month_info['month'] + 1, 0, $month_info['year']));
        $due_date = date('Y-m-d', mktime(0, 0, 0, $month_info['month'] + 2, 15, $month_info['year']));

        // Insert billing record
        $insert_sql = "INSERT INTO billing_list 
                      (client_id, reading_date, previous, reading, total, status, due_date) 
                      VALUES (?, ?, ?, ?, ?, 'pending', ?)";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("isddds", $client_id, $reading_date, $prev_reading, $curr_reading, $amount, $due_date);

        if ($insert_stmt->execute()) {
            $success_count++;
        } else {
            $error_count++;
            $errors[] = $month_info['label'] . ": " . $insert_stmt->error;
        }
        $insert_stmt->close();
    }

    if ($success_count > 0) {
        $conn->commit();
        $_SESSION['bulk_message'] = "Successfully added $success_count billing record(s)";
        if ($error_count > 0) {
            $_SESSION['bulk_message'] .= " ($error_count failed)";
        }
        $_SESSION['bulk_status'] = 'success';
    } else {
        $conn->rollback();
        $_SESSION['bulk_message'] = "No billing records were added";
        if (!empty($errors)) {
            $_SESSION['bulk_message'] .= ": " . implode(", ", $errors);
        }
        $_SESSION['bulk_status'] = 'danger';
    }

    header('Location: bulk_billing_entry.php');
    exit();
}
?>


    if ($rate_result->num_rows === 0) {
        $_SESSION['bulk_message'] = 'Category rate not found';
        $_SESSION['bulk_status'] = 'danger';
        header('Location: bulk_billing_entry.php');
        exit();
    }

    $rate_data = $rate_result->fetch_assoc();
    $rate_stmt->close();

    $success_count = 0;
    $error_count = 0;
    $errors = [];

    $conn->begin_transaction();

    try {
        foreach ($months as $idx => $month) {
            $month_date = $month . '-01'; // Convert month to first day of month
            
            // Parse month string (YYYY-MM)
            $date_check = DateTime::createFromFormat('Y-m-01', $month_date);
            if (!$date_check) {
                $error_count++;
                $errors[] = "Row " . ($idx + 1) . ": Invalid date format";
                continue;
            }

            $reading_date = $date_check->format('Y-m-d');
            $prev_reading = floatval($prev_readings[$idx] ?? 0);
            $curr_reading = floatval($curr_readings[$idx] ?? 0);
            $consumption = floatval($consumptions[$idx] ?? 0);
            $amount = floatval($amounts[$idx] ?? 0);
            $status = ($statuses[$idx] ?? 'pending') === 'paid' ? 1 : 0;
            $due_date = $date_check->modify('+30 days')->format('Y-m-d');

            // Validate readings
            if ($curr_reading < $prev_reading) {
                $error_count++;
                $errors[] = "Month " . $month . ": Current reading cannot be less than previous reading";
                continue;
            }

            // Check if billing record already exists for this month
            $check_sql = "SELECT id FROM billing_list WHERE client_id = ? AND YEAR(reading_date) = ? AND MONTH(reading_date) = ?";
            $check_stmt = $conn->prepare($check_sql);
            $year = $date_check->format('Y');
            $month_num = $date_check->format('m');
            $check_stmt->bind_param("iii", $client_id, $year, $month_num);
            $check_stmt->execute();
            
            if ($check_stmt->get_result()->num_rows > 0) {
                $error_count++;
                $errors[] = "Month " . $month . ": Billing record already exists";
                $check_stmt->close();
                continue;
            }
            $check_stmt->close();

            // Insert billing record
            $insert_sql = "INSERT INTO billing_list (client_id, reading_date, previous, reading, total, status, due_date) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("isddids", $client_id, $reading_date, $prev_reading, $curr_reading, $amount, $status, $due_date);

            if ($insert_stmt->execute()) {
                $success_count++;
            } else {
                $error_count++;
                $errors[] = "Month " . $month . ": " . $insert_stmt->error;
            }
            $insert_stmt->close();
        }

        if ($success_count > 0) {
            $conn->commit();
            $_SESSION['bulk_message'] = "Successfully added $success_count billing record(s)";
            if ($error_count > 0) {
                $_SESSION['bulk_message'] .= " ($error_count failed)";
            }
            $_SESSION['bulk_status'] = 'success';
        } else {
            $conn->rollback();
            $_SESSION['bulk_message'] = 'No billing records were added: ' . implode(', ', $errors);
            $_SESSION['bulk_status'] = 'danger';
        }
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['bulk_message'] = 'Error: ' . $e->getMessage();
        $_SESSION['bulk_status'] = 'danger';
    }

    header('Location: bulk_billing_entry.php');
    exit();
} else {
    header('Location: bulk_billing_entry.php');
    exit();
}
?>
