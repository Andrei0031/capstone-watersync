<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: adminlogin.php');
    exit();
}

include 'db.php';
date_default_timezone_set('Asia/Manila');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id = intval($_POST['client_id'] ?? 0);
    $months = $_POST['month'] ?? [];
    $prev_readings = $_POST['previous_reading'] ?? [];
    $curr_readings = $_POST['current_reading'] ?? [];
    $consumptions = $_POST['consumption'] ?? [];
    $amounts = $_POST['amount'] ?? [];
    $statuses = $_POST['status'] ?? [];

    if ($client_id <= 0 || empty($months)) {
        $_SESSION['bulk_message'] = 'Invalid customer or no billing records provided';
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
