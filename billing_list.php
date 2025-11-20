<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: adminlogin.php");
    exit();
}

include 'db.php';
include 'comprehensive_fee_manager.php';
include 'simple_notifications.php';

$showNotificationModal = false; // Initialize to avoid undefined variable warning
$message = ''; // Initialize message variable

// Calculate total revenue for current month
$total_revenue = 0;
$revenue_query = "SELECT SUM(total) as total FROM billing_list 
                 WHERE MONTH(reading_date) = MONTH(CURRENT_DATE()) 
                 AND YEAR(reading_date) = YEAR(CURRENT_DATE())";
$revenue_result = $conn->query($revenue_query);
if ($revenue_result && $row = $revenue_result->fetch_assoc()) {
    $total_revenue = $row['total'] ?? 0;
}

// Calculate number of paid bills this month
$paid_bills = 0;
$paid_query = "SELECT COUNT(*) as count FROM billing_list 
               WHERE status = 1 
               AND MONTH(reading_date) = MONTH(CURRENT_DATE()) 
               AND YEAR(reading_date) = YEAR(CURRENT_DATE())";
$paid_result = $conn->query($paid_query);
if ($paid_result && $row = $paid_result->fetch_assoc()) {
    $paid_bills = $row['count'];
}

// Calculate number of unpaid bills
$unpaid_bills = 0;
$unpaid_query = "SELECT COUNT(*) as count FROM billing_list 
                 WHERE status = 0 
                 AND due_date >= CURRENT_DATE()";
$unpaid_result = $conn->query($unpaid_query);
if ($unpaid_result && $row = $unpaid_result->fetch_assoc()) {
    $unpaid_bills = $row['count'];
}

// Calculate number of overdue bills
$overdue_bills = 0;
$overdue_query = "SELECT COUNT(*) as count FROM billing_list 
                 WHERE status = 0 
                 AND due_date < CURRENT_DATE()";
$overdue_result = $conn->query($overdue_query);
if ($overdue_result && $row = $overdue_result->fetch_assoc()) {
    $overdue_bills = $row['count'];
}

// Fetch list of active clients for selection in add billing modal
$sql_clients = "SELECT id, firstname, lastname, category_id FROM client_list WHERE status = 1";
$result_clients = $conn->query($sql_clients);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'create') {
        // Debug log
        error_log('Creating/Updating bill: ' . print_r($_POST, true));
        
        // Validate and sanitize inputs
        $client_id = intval($_POST['client_id']);
        $reading_date = $_POST['reading_date'];
        $due_date = $_POST['due_date'];
        $reading = floatval($_POST['reading']);
        $status = intval($_POST['status']);

        // Validate required fields
        if (empty($client_id) || empty($reading_date) || empty($due_date) || $reading === '') {
            error_log('Validation failed: Missing required fields');
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'All fields are required']);
            exit;
        }

        try {
            // Check if customer already has a bill
            $check_stmt = $conn->prepare("SELECT id, reading, total FROM billing_list WHERE client_id = ? AND status = 0 ORDER BY reading_date DESC LIMIT 1");
            $check_stmt->bind_param("i", $client_id);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            $existing_bill = $result->fetch_assoc();
            $check_stmt->close();

            // Get previous reading
            $stmt = $conn->prepare("SELECT reading FROM billing_list WHERE client_id = ? ORDER BY reading_date DESC LIMIT 1");
            $stmt->bind_param("i", $client_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $previous = ($result->num_rows > 0) ? $result->fetch_assoc()['reading'] : 0;
            $stmt->close();

            error_log("Previous reading for client $client_id: $previous");

            // Calculate consumption and total with comprehensive fees
            require_once 'comprehensive_fee_manager.php';
            $bill_calculation = calculateBillWithFees($client_id, $reading, $previous, $conn, 'regular_bill');
            
            if (!$bill_calculation['success']) {
                throw new Exception("Failed to calculate bill: " . $bill_calculation['error']);
            }
            
            $consumption = $bill_calculation['consumption'];
            $current_bill_total = $bill_calculation['final_total'];
            $fees_to_apply = $bill_calculation['fees_data'];

            // Begin transaction
            $conn->begin_transaction();

            try {
                if ($existing_bill) {
                    // Update existing bill
                    $total = $current_bill_total + $existing_bill['total']; // Add new charges to existing total
                    $stmt = $conn->prepare("UPDATE billing_list SET reading_date = ?, due_date = ?, reading = ?, previous = ?, total = ?, status = ? WHERE id = ?");
                    $stmt->bind_param("ssdddii", $reading_date, $due_date, $reading, $previous, $total, $status, $existing_bill['id']);
                } else {
                    // Insert new billing record
                    $stmt = $conn->prepare("INSERT INTO billing_list (client_id, reading_date, due_date, reading, previous, total, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("issdddi", $client_id, $reading_date, $due_date, $reading, $previous, $current_bill_total, $status);
                }

                if (!$stmt->execute()) {
                    throw new Exception("Failed to " . ($existing_bill ? "update" : "insert") . " billing record: " . $stmt->error);
                }

                $bill_id = $existing_bill ? $existing_bill['id'] : $conn->insert_id;
                error_log("Bill " . ($existing_bill ? "updated" : "created") . " with ID: $bill_id");

                // Apply additional fees to the bill
                if (!$existing_bill && !empty($fees_to_apply['fees'])) {
                    $fee_result = applyFeesToBill($bill_id, $fees_to_apply, $conn);
                    if (!$fee_result['success']) {
                        error_log("Warning: Failed to apply fees to bill $bill_id: " . $fee_result['error']);
                        // Continue with bill creation even if fees fail
                    } else {
                        error_log("Applied " . $fee_result['fees_count'] . " fees totaling ₱" . number_format($fee_result['total_applied'], 2) . " to bill $bill_id");
                    }
                }

                // Commit transaction
                $conn->commit();

                // Send notification after successful bill creation/update
                try {
                    $notification_result = sendBillingNotification($client_id, $bill_id, 'bill_approved');
                    if ($notification_result['success']) {
                        error_log("Notification sent for bill $bill_id: " . json_encode($notification_result['results']));
                    } else {
                        error_log("Notification failed for bill $bill_id: " . ($notification_result['error'] ?? 'Unknown error'));
                    }
                } catch (Exception $e) {
                    error_log("Notification system error: " . $e->getMessage());
                    // Don't fail the bill creation if notifications fail
                }

                // Return success response
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Billing record ' . ($existing_bill ? 'updated' : 'created') . ' successfully',
                    'bill_id' => $bill_id
                ]);
                exit;

            } catch (Exception $e) {
                // Rollback transaction on error
                $conn->rollback();
                throw $e;
            }

        } catch (Exception $e) {
            error_log("Error creating/updating bill: " . $e->getMessage());
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Error ' . ($existing_bill ? 'updating' : 'creating') . ' billing record: ' . $e->getMessage()
            ]);
            exit;
        }
    } elseif (isset($_POST['delete_client'])) {
        // Handle client deletion
        $client_id = $_POST['delete_client_id'];
        $stmt = $conn->prepare("DELETE FROM client_list WHERE id = ?");
        $stmt->bind_param("i", $client_id);
        if ($stmt->execute()) {
            $notification = 'Client deleted successfully.';
            $notificationClass = 'alert-success';
            $stmt->close();
            // Redirect to avoid form resubmission and show updated list
            header("Location: billing_list.php?delete_status=success");
            exit();
        } else {
            $notification = 'Error deleting client: ' . $stmt->error;
            $notificationClass = 'alert-danger';
            $stmt->close();
            header("Location: billing_list.php?delete_status=error&message=" . urlencode($stmt->error));
            exit();
        }
        $showNotificationModal = true;
    } elseif (isset($_POST['bulk_delete_bills'])) {
        // Handle bulk delete of bills
        $selected_ids = $_POST['selected_bills'] ?? [];
        
        if (empty($selected_ids)) {
            header("Location: billing_list.php?bulk_delete_status=error&message=" . urlencode('No bills selected for deletion.'));
            exit();
        }
        
        $success_count = 0;
        $error_count = 0;
        $errors = [];
        
        $conn->begin_transaction();
        
        try {
            foreach ($selected_ids as $bill_id) {
                $bill_id = intval($bill_id);
                if ($bill_id > 0) {
                    // Delete associated payments first
                    $delete_payments = $conn->prepare("DELETE FROM payment_list WHERE billing_id = ?");
                    $delete_payments->bind_param("i", $bill_id);
                    $delete_payments->execute();
                    $delete_payments->close();
                    
                    // Delete the bill
                    $stmt = $conn->prepare("DELETE FROM billing_list WHERE id = ?");
                    $stmt->bind_param("i", $bill_id);
                    if ($stmt->execute()) {
                        $success_count++;
                    } else {
                        $error_count++;
                        $errors[] = "Failed to delete bill ID {$bill_id}: " . $stmt->error;
                    }
                    $stmt->close();
                }
            }
            
            $conn->commit();
            
            if ($success_count > 0) {
                $message = "Successfully deleted {$success_count} bill(s).";
                if ($error_count > 0) {
                    $message .= " {$error_count} failed.";
                }
                header("Location: billing_list.php?bulk_delete_status=success&message=" . urlencode($message));
            } else {
                $message = "Failed to delete bills. " . implode(', ', array_slice($errors, 0, 5));
                header("Location: billing_list.php?bulk_delete_status=error&message=" . urlencode($message));
            }
        } catch (Exception $e) {
            $conn->rollback();
            header("Location: billing_list.php?bulk_delete_status=error&message=" . urlencode("Error during bulk delete: " . $e->getMessage()));
        }
        exit();
    } elseif (isset($_POST['update_client'])) {
        // Retrieve values from POST data
            $client_id = $_POST['client_id'];
            $firstname = $_POST['firstname'];
            $lastname = $_POST['lastname'];

            // Update client record in the database
            $stmt = $conn->prepare("UPDATE client_list SET firstname = ?, lastname = ? WHERE id = ?");
            
            // Bind parameters
            $stmt->bind_param("ssi", $firstname, $lastname, $client_id);

            // Execute the statement
            if ($stmt->execute()) {
                $notification = 'Client updated successfully.';
                $notificationClass = 'alert-success';
            } else {
                $notification = 'Error updating client: ' . $stmt->error;
                $notificationClass = 'alert-danger';
            }

        $showNotificationModal = true;

        // Close the statement
        $stmt->close();
    } elseif (isset($_POST['update_rates'])) {
        // This block can be removed or left as is since settings_rate.php will handle rates
        // For safety, keep it but it won't be triggered from this page anymore
        $residential_rate = $_POST['residential_rate'];
        $residential_excess_rate = $_POST['residential_excess_rate'];
        $commercial_rate = $_POST['commercial_rate'];
        $commercial_excess_rate = $_POST['commercial_excess_rate'];

        // Update residential rates
        $stmt_residential = $conn->prepare("UPDATE category_rates SET rate = ?, excess_rate = ? WHERE category_id = 1");
        $stmt_residential->bind_param("dd", $residential_rate, $residential_excess_rate);
        $residential_update_success = $stmt_residential->execute();
        $stmt_residential->close();

        // Update commercial rates
        $stmt_commercial = $conn->prepare("UPDATE category_rates SET rate = ?, excess_rate = ? WHERE category_id = 2");
        $stmt_commercial->bind_param("dd", $commercial_rate, $commercial_excess_rate);
        $commercial_update_success = $stmt_commercial->execute();
        $stmt_commercial->close();

        if ($residential_update_success && $commercial_update_success) {
            $notification = 'Rates updated successfully.';
            $notificationClass = 'alert-success';
        } else {
            $notification = 'Error updating rates.';
            $notificationClass = 'alert-danger';
        }

        $showNotificationModal = true;
    } elseif (isset($_POST['update_billing'])) {
        // Handle updating billing record from edit modal form
        $id = $_POST['id'];
        $client_id = $_POST['client_id'];
        $reading_date = $_POST['reading_date'];
        $due_date = $_POST['due_date'];
        $reading = $_POST['reading'];
        $previous = $_POST['previous'];
        $status = $_POST['status'];

        // Fetch the category_id of the client
        $sql_category = "SELECT category_id FROM client_list WHERE id = ?";
        $stmt_category = $conn->prepare($sql_category);
        $stmt_category->bind_param("i", $client_id);
        $stmt_category->execute();
        $stmt_category->bind_result($category_id);
        $stmt_category->fetch();
        $stmt_category->close();

        // Fetch the rate and excess_rate for the client's category
        $sql_rate = "SELECT rate, excess_rate FROM category_rates WHERE category_id = ?";
        $stmt_rate = $conn->prepare($sql_rate);
        $stmt_rate->bind_param("i", $category_id);
        $stmt_rate->execute();
        $stmt_rate->bind_result($rate, $excess_rate);
        $stmt_rate->fetch();
        $stmt_rate->close();

        // Recalculate total based on updated reading and previous using fetched rate and excess rate
        $usage = $reading - $previous;
        if ($usage <= 6) {
            $total = $usage * $rate;
        } else {
            $total = 6 * $rate + ($usage - 6) * $excess_rate;
        }

        $stmt = $conn->prepare("UPDATE billing_list SET client_id = ?, reading_date = ?, due_date = ?, reading = ?, previous = ?, total = ?, status = ? WHERE id = ?");
        $stmt->bind_param("issdddii", $client_id, $reading_date, $due_date, $reading, $previous, $total, $status, $id);

        if ($stmt->execute()) {
            $message = "Billing record updated successfully";
            $messageClass = "success-message";
        } else {
            $message = "Error updating billing record: " . $stmt->error;
            $messageClass = "error-message";
        }

        $stmt->close();
    }
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$billing_month = isset($_GET['billing_month']) ? $_GET['billing_month'] : '';

// Build the WHERE clause based on filters
$where_conditions = [];
$params = [];
$types = '';

if ($search) {
    $where_conditions[] = "(cl.firstname LIKE ? OR cl.lastname LIKE ? OR cl.code LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'sss';
}

if ($status_filter) {
    switch($status_filter) {
        case 'paid':
            $where_conditions[] = "b.status = 1";
            break;
        case 'pending':
            $where_conditions[] = "b.status = 0 AND b.due_date >= CURRENT_DATE()";
            break;
        case 'overdue':
            $where_conditions[] = "b.status = 0 AND b.due_date < CURRENT_DATE()";
            break;
    }
}

if ($billing_month) {
    $where_conditions[] = "DATE_FORMAT(b.reading_date, '%Y-%m') = ?";
    $params[] = $billing_month;
    $types .= 's';
}

$where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Modified query to get only the latest bill per customer
$sql = "WITH LatestBills AS (
            SELECT client_id, MAX(reading_date) as latest_date
            FROM billing_list
            GROUP BY client_id
        )
        SELECT b.*, 
               COALESCE(cl.firstname, 'Deleted') as firstname, 
               COALESCE(cl.lastname, 'Client') as lastname, 
               COALESCE(cl.meter_code, 'N/A') as meter_code,
               CASE WHEN cl.id IS NULL THEN true ELSE false END as is_deleted_client,
               (SELECT reading FROM billing_list prev 
                WHERE prev.client_id = b.client_id 
                AND prev.reading_date < b.reading_date 
                ORDER BY reading_date DESC LIMIT 1) as previous_reading,
               (SELECT SUM(total) 
                FROM billing_list prev 
                WHERE prev.client_id = b.client_id 
                AND prev.status = 0 
                AND prev.id != b.id) as previous_balance
        FROM billing_list b
        INNER JOIN LatestBills lb ON b.client_id = lb.client_id AND b.reading_date = lb.latest_date
        LEFT JOIN client_list cl ON b.client_id = cl.id
        $where_clause
        ORDER BY 
            LOWER(COALESCE(cl.lastname, '')) ASC,
            LOWER(COALESCE(cl.firstname, '')) ASC,
            CAST(COALESCE(cl.meter_code, '0') AS UNSIGNED) ASC";

if ($params) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

?>
<!DOCTYPE html><html lang="en"><head>    <meta charset="UTF-8" />    <meta name="viewport" content="width=device-width, initial-scale=1" />    <link rel="icon" href="logo.png" />    <!-- Theme initialization -->    <script src="billing_list.js"></script>
    <title>Billing Management - Water Billing System</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <!-- Google Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" />
    <style>
        /* Include the same theme variables and base styles as dashboard */
        :root[data-theme="light"] {
            --bg-color: #f8f9fa;
            --sidebar-bg: #fff;
            --text-color: #333;
            --card-bg: #fff;
            --border-color: #dee2e6;
            --hover-bg: #f0f2f5;
            --hover-text: #007bff;
            --muted-text: #6c757d;
            --card-text: #333;
            --table-header-bg: #f8f9fa;
            --table-header-text: #333;
            --table-cell-text: #333;
            --table-bg: #fff;
        }

        :root[data-theme="dark"] {
            --bg-color: #1a1d21;
            --sidebar-bg: #242529;
            --text-color: #e4e6eb;
            --card-bg: #2d2f34;
            --border-color: #393b40;
            --hover-bg: #393b40;
            --hover-text: #4e9eff;
            --muted-text: #a0a0a0;
            --card-text: #e4e6eb;
            --table-header-bg: #242529;
            --table-header-text: #e4e6eb;
            --table-cell-text: #e4e6eb;
            --table-bg: #2d2f34;
        }

        body {
            font-family: 'Open Sans', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            transition: background-color 0.3s, color 0.3s;
        }

        /* Sidebar styles */
        .sidebar {
            height: 100vh;
            background-color: var(--sidebar-bg);
            border-right: 1px solid var(--border-color);
            padding-top: 20px;
            position: fixed;
            width: 250px;
            display: flex;
            flex-direction: column;
        }

        .sidebar-header {
            padding: 20px;
            margin-bottom: 20px;
            text-align: center;
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            margin: 0 20px 20px;
            border-radius: 12px;
            transition: background-color 0.3s, border-color 0.3s;
            overflow: hidden;
        }

        .sidebar-header img {
            max-width: 100%;
            height: auto;
            object-fit: contain;
            filter: none !important;
        }

        /* Prevent logo from being affected by dark mode filters */
        html[data-theme="dark"] .sidebar-header img,
        [data-theme="dark"] .sidebar-header img {
            filter: none !important;
            opacity: 1 !important;
            mix-blend-mode: normal !important;
        }

        /* Keep sidebar-header background light in dark mode for logo visibility */
        html[data-theme="dark"] .sidebar-header,
        [data-theme="dark"] .sidebar-header {
            background-color: #fff !important;
        }

        .nav-content {
            flex: 1;
            overflow-y: auto;
        }

        .sidebar-footer {
            padding: 20px;
            border-top: 1px solid var(--border-color);
            margin-top: auto;
        }

        .sidebar a {
            padding: 12px 20px;
            display: flex;
            align-items: center;
            color: var(--text-color);
            font-weight: 600;
            text-decoration: none;
            border-radius: 12px;
            margin: 0 8px 8px;
            transition: all 0.3s ease;
        }

        .sidebar a i {
            min-width: 24px;
            margin-right: 10px;
            text-align: center;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background-color: var(--hover-bg);
            color: var(--hover-text);
        }

        /* Theme switch */
        .theme-switch-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px;
            border-radius: 10px;
            margin: 10px 20px;
            background-color: var(--hover-bg);
        }

        .theme-switch-wrapper i {
            margin: 0 5px;
            color: var(--text-color);
        }

        .theme-switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
            margin: 0 10px;
        }

        .theme-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #27292a;
            transition: .4s;
            border-radius: 20px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: #2196F3;
        }

        input:checked + .slider:before {
            transform: translateX(26px);
        }

        /* Logout button */
        .sidebar-footer form {
            margin: 0;
        }

        .sidebar-footer .btn-outline-primary {
            width: 100%;
            border-radius: 8px;
            padding: 8px 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: #2196F3;
            border: 1px solid #2196F3;
            background: transparent;
            transition: all 0.3s ease;
            font-size: 14px;
        }

        .sidebar-footer .btn-outline-primary:hover {
            background-color: rgba(33, 150, 243, 0.1);
        }

        .sidebar-footer .btn-outline-primary i {
            font-size: 14px;
        }

        /* Main content styles */
        .main-content {
            margin-left: 250px;
            padding: 30px;
        }

        .card-soft {
            border-radius: 15px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            border: none;
            background-color: var(--card-bg);
            color: var(--card-text);
            margin-bottom: 20px;
        }

        /* Stats cards */
        .stat-card {
            padding: 20px;
            border-radius: 15px;
            color: white;
            height: 100%;
        }

        .stat-icon {
            font-size: 2rem;
            opacity: 0.8;
        }

        /* Table styles */
        .table {
            color: var(--table-cell-text);
            background-color: var(--table-bg);
            margin-bottom: 0;
        }

        .table thead th {
            background-color: var(--table-header-bg);
            color: var(--table-header-text);
            border-bottom: 2px solid var(--border-color);
            padding: 15px;
            font-weight: 600;
        }

        .table tbody tr {
            border-bottom: 1px solid var(--border-color);
        }

        .table td {
            padding: 15px;
            vertical-align: middle;
            border-color: var(--border-color);
            color: var(--text-color) !important;
            background-color: transparent;
        }

        .table tbody tr:hover {
            background-color: var(--hover-bg);
        }



        .customer-name {
            color: var(--text-color);
            font-weight: 500;
        }

        .customer-code {
            color: var(--muted-text);
            font-size: 0.875rem;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .action-buttons .btn {
            padding: 6px;
            line-height: 1;
            border-radius: 6px;
        }

        .action-buttons .btn i {
            font-size: 1rem;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .status-paid {
            background-color: #1cc88a20;
            color: #1cc88a;
        }

        .status-pending, .status-unpaid {
            background-color: #f6c23e20;
            color: #f6c23e;
        }

        .status-overdue {
            background-color: #e74a3b20;
            color: #e74a3b;
        }

        /* Search and filter styles */
        .search-form {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .search-input {
            max-width: 300px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background-color: var(--card-bg);
            color: var(--text-color);
        }

        .filter-section {
            background-color: var(--card-bg);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }

        /* Modal styles */
        .modal-content {
            background-color: var(--card-bg);
            color: var(--text-color);
        }

        .modal-header, .modal-footer {
            border-color: var(--border-color);
        }

        .modal-title {
            color: var(--text-color);
        }

        .modal-body {
            padding: 1.25rem;
        }

        .customer-header {
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .customer-header .avatar-lg {
            width: 60px !important;
            height: 60px !important;
            font-size: 1.5rem !important;
            margin-bottom: 0.75rem;
        }

        .customer-name {
            color: var(--text-color);
            font-weight: 600;
            font-size: 1.25rem;
            margin: 0.25rem 0;
        }

        .customer-code {
            color: var(--muted-text);
            font-size: 0.813rem;
            margin-bottom: 0;
        }

        .modal-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem !important;
        }

        .card-subtitle {
            color: var(--text-color);
            font-weight: 600;
            font-size: 0.875rem;
            margin-bottom: 1rem;
        }

        .info-section {
            margin-bottom: 0.75rem;
        }

        .info-section:last-child {
            margin-bottom: 0;
        }

        .bill-detail-label {
            color: var(--muted-text);
            font-size: 0.75rem;
            margin-bottom: 0.125rem;
        }

        .bill-detail-value {
            color: var(--text-color);
            font-weight: 500;
            font-size: 0.875rem;
            margin-bottom: 0;
        }

        .payment-summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
            font-size: 0.875rem;
            padding: 0.5rem 0;
        }

        .payment-summary-label {
            color: var(--text-color);
            font-weight: 500;
        }

        .payment-summary-value {
            color: var(--text-color);
            font-weight: 600;
        }

        .payment-total {
            margin-top: 1rem;
            padding: 1rem;
            border-top: 2px solid var(--border-color);
            background-color: rgba(0, 123, 255, 0.05);
            border-radius: 0.5rem;
        }

        .total-amount {
            color: var(--hover-text);
            font-size: 1.25rem;
            font-weight: 700;
        }

        .previous-balance {
            color: var(--text-color);
            padding: 0.5rem;
            margin: 0.5rem 0;
            background-color: rgba(255, 193, 7, 0.1);
            border-radius: 0.5rem;
        }

        .row.g-4 {
            --bs-gutter-x: 1rem;
            --bs-gutter-y: 1rem;
        }

        .modal-footer {
            padding: 0.75rem 1.25rem;
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }

        .status-badge {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
        }

        @media print {
            body * {
                visibility: hidden;
            }
            #viewBillModal, #viewBillModal * {
                visibility: visible;
            }
            #viewBillModal {
                position: absolute;
                left: 0;
                top: 0;
            }
            .modal-footer {
                display: none;
            }
        }

        /* Responsive Sidebar and Hamburger Toggle */
        @media (max-width: 991.98px) {
          .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: 250px;
            background-color: var(--sidebar-bg);
            border-right: 1px solid var(--border-color);
            transform: translateX(-250px);
            transition: transform 0.3s ease;
            z-index: 1050;
            display: block;
          }
          .sidebar.open {
            transform: translateX(0);
          }
          .sidebar-footer {
            position: absolute;
            bottom: 0;
            width: 100%;
          }
          .main-content {
            margin-left: 0 !important;
            padding: 20px 10px;
            transition: margin-left 0.3s ease;
          }
          #sidebarToggle {
            display: block;
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1100;
            background-color: var(--sidebar-bg);
            border: none;
            padding: 8px 12px;
            border-radius: 5px;
            box-shadow: 0 0 5px rgba(0,0,0,0.2);
            cursor: pointer;
          }
        }
        @media (min-width: 992px) {
          .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: 250px;
            background-color: var(--sidebar-bg);
            border-right: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            transform: none !important;
          }
          .main-content {
            margin-left: 250px;
            padding: 30px;
          }
          #sidebarToggle {
            display: none;
          }
        }

        .text-muted {
            color: var(--muted-text) !important;
        }

        /* Action buttons improvements */
        .action-links {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .action-links a,
        .action-links button {
            padding: 8px 12px !important;
            margin: 0 !important;
            border-radius: 6px !important;
            min-width: 40px;
            height: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            transition: all 0.2s ease;
            border-width: 2px;
        }

        .action-links a i,
        .action-links button i {
            font-size: 1rem;
        }

        .action-links a:hover,
        .action-links button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        .action-links .btn-outline-primary {
            border-color: #0d6efd;
            color: #0d6efd;
        }

        .action-links .btn-outline-primary:hover {
            background-color: #0d6efd;
            color: #fff;
        }

        .action-links .btn-outline-success {
            border-color: #198754;
            color: #198754;
        }

        .action-links .btn-outline-success:hover {
            background-color: #198754;
            color: #fff;
        }

        .action-links .btn-outline-danger {
            border-color: #dc3545 !important;
            color: #dc3545 !important;
        }

        .action-links .btn-outline-danger:hover {
            background-color: #dc3545 !important;
            color: #fff !important;
        }

        /* Dark mode improvements for action buttons */
        html[data-theme="dark"] .action-links .btn-outline-primary,
        [data-theme="dark"] .action-links .btn-outline-primary {
            border-color: #4e9eff;
            color: #4e9eff;
        }

        html[data-theme="dark"] .action-links .btn-outline-primary:hover,
        [data-theme="dark"] .action-links .btn-outline-primary:hover {
            background-color: #4e9eff;
            color: #fff;
        }

        html[data-theme="dark"] .action-links .btn-outline-success,
        [data-theme="dark"] .action-links .btn-outline-success {
            border-color: #4caf50;
            color: #4caf50;
        }

        html[data-theme="dark"] .action-links .btn-outline-success:hover,
        [data-theme="dark"] .action-links .btn-outline-success:hover {
            background-color: #4caf50;
            color: #fff;
        }
    </style>
</head>
<body>

<!-- Responsive Sidebar and Hamburger Toggle -->
<button id="sidebarToggle" aria-label="Toggle sidebar">
  <i class="fas fa-bars"></i>
</button>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <img src="icons/Logo.png" alt="Water Billing Logo" class="img-fluid" style="max-height: 120px;" />
    </div>
    <div class="nav-content">
        <a href="adminlandingpage.php">
            <i class="fas fa-chart-line"></i>
            <span>Billing Dashboard</span>
        </a>
        <a href="view_clients.php">
            <i class="fas fa-users"></i>
            <span>Customers</span>
        </a>
        <a href="billing_list.php" class="active">
            <i class="fas fa-file-invoice-dollar"></i>
            <span>Bills</span>
        </a>
        <a href="pending_readings.php">
            <i class="fas fa-camera"></i>
            <span>Meter Readings</span>
        </a>
        <a href="payments.php">
            <i class="fas fa-money-bill-wave"></i>
            <span>Payments</span>
        </a>
        <a href="customer_accounts.php">
            <i class="fas fa-user-circle"></i>
            <span>Customer Accounts</span>
        </a>
        <a href="reports.php">
            <i class="fas fa-chart-line"></i>
            <span>Reports</span>
        </a>
        <a href="client_reports.php">
            <i class="fas fa-chart-bar"></i>
            <span>Water Outage Reports</span>
        </a>
        <a href="disconnection_notices.php">
            <i class="fas fa-exclamation-triangle"></i>
            <span>Disconnection Notices</span>
        </a>
        <a href="settings_rate.php">
            <i class="fas fa-cog"></i>
            <span>Settings</span>
        </a>
    </div>
    <div class="sidebar-footer">
        <div class="theme-switch-wrapper">
            <i class="fas fa-sun"></i>
            <label class="theme-switch">
                <input type="checkbox" id="theme-toggle">
                <span class="slider"></span>
            </label>
            <i class="fas fa-moon"></i>
        </div>
        <form method="POST" action="logout.php" class="mt-3">
            <button type="submit" class="btn btn-outline-primary w-100">
                <i class="fas fa-sign-out-alt me-2"></i>
                Logout
            </button>
        </form>
    </div>
</div>

<script>
  // Sidebar toggle for mobile
  document.addEventListener('DOMContentLoaded', function() {
    var sidebar = document.getElementById('sidebar');
    var sidebarToggle = document.getElementById('sidebarToggle');
    var mainContent = document.querySelector('.main-content');
    if (sidebarToggle) {
      sidebarToggle.addEventListener('click', function() {
        sidebar.classList.toggle('open');
      });
    }
    // Optional: close sidebar when clicking outside on mobile
    document.addEventListener('click', function(e) {
      if (window.innerWidth <= 991 && sidebar.classList.contains('open')) {
        if (!sidebar.contains(e.target) && e.target !== sidebarToggle) {
          sidebar.classList.remove('open');
        }
      }
    });
  });
</script>

<!-- Main Content -->
<div class="main-content">
    <!-- Header Section -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">Billing Management</h2>
        <div class="d-flex gap-2">
            <button id="bulkDeleteBtn" class="btn btn-danger" style="display: none;" disabled>
                <i class="fas fa-trash me-2"></i>Delete Selected (<span id="selectedCount">0</span>)
            </button>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBillingModal">
                <i class="fas fa-plus me-2"></i>Create New Bill
            </button>
        </div>
    </div>
    
    <!-- Bulk Action Controls -->
    <div class="card card-soft mb-3" id="bulkActionControls" style="display: none;">
        <div class="card-body py-2">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <button id="selectAllBtn" class="btn btn-sm btn-outline-primary me-2">
                        <i class="fas fa-check-square me-1"></i>Select All
                    </button>
                    <button id="deselectAllBtn" class="btn btn-sm btn-outline-secondary" style="display: none;">
                        <i class="fas fa-square me-1"></i>Deselect All
                    </button>
                </div>
                <div>
                    <span id="selectedBillsCount" class="text-muted me-3">0 bills selected</span>
                    <button id="bulkDeleteBtn2" class="btn btn-sm btn-danger" disabled>
                        <i class="fas fa-trash me-1"></i>Delete Selected
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card card-soft stat-card" style="background: linear-gradient(45deg, #4e73df 0%, #224abe 100%);">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-white-50">Total Revenue</h6>
                        <h3 class="mb-0">₱<?php echo number_format($total_revenue, 2); ?></h3>
                        <small class="text-white-50">This Month</small>
                    </div>
                    <i class="fas fa-dollar-sign stat-icon"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-soft stat-card" style="background: linear-gradient(45deg, #1cc88a 0%, #13855c 100%);">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-white-50">Paid Bills</h6>
                        <h3 class="mb-0"><?php echo $paid_bills; ?></h3>
                        <small class="text-white-50">This Month</small>
                    </div>
                    <i class="fas fa-check-circle stat-icon"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-soft stat-card" style="background: linear-gradient(45deg, #f6c23e 0%, #dda20a 100%);">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-white-50">Unpaid Bills</h6>
                        <h3 class="mb-0"><?php echo $unpaid_bills; ?></h3>
                        <small class="text-white-50">Needs Action</small>
                    </div>
                    <i class="fas fa-clock stat-icon"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-soft stat-card" style="background: linear-gradient(45deg, #e74a3b 0%, #be2617 100%);">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-white-50">Overdue Bills</h6>
                        <h3 class="mb-0"><?php echo $overdue_bills; ?></h3>
                        <small class="text-white-50">Past Due Date</small>
                    </div>
                    <i class="fas fa-exclamation-circle stat-icon"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="card card-soft mb-4">
        <div class="card-body">
            <form id="filterForm" class="row g-3" method="GET" action="billing_list.php">
                <div class="col-md-4">
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" id="searchInput" class="form-control" placeholder="Search bills..." name="search" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <select class="form-select" id="statusSelect" name="status">
                        <option value="">All Status</option>
                        <option value="paid" <?php echo $status_filter === 'paid' ? 'selected' : ''; ?>>Paid</option>
                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Unpaid</option>
                        <option value="overdue" <?php echo $status_filter === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="month" id="billingMonthSelect" class="form-control" name="billing_month" value="<?php echo htmlspecialchars($billing_month); ?>">
                </div>
                <div class="col-md-2">
                    <button type="button" id="resetFilters" class="btn btn-outline-secondary w-100">Reset</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Billing Table -->
    <div class="card card-soft">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th style="width: 40px;">
                                <input type="checkbox" id="selectAllCheckbox" title="Select All">
                            </th>
                            <th>Customer</th>
                            <th>Meter Reading</th>
                            <th>Previous Reading</th>
                            <th>Consumption</th>
                            <th>Amount</th>
                            <th>Due Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result->num_rows > 0): 
                            while($row = $result->fetch_assoc()): 
                                // Calculate consumption
                                $consumption = max(0, $row['reading'] - $row['previous']);
                                
                                // Calculate charges using fixed rates
                                $base_rate = 100; // Base rate for first 6 cubic meters
                                $excess_rate = 20; // Rate per cubic meter for excess
                                
                                // Calculate total
                                $base_charge = $base_rate;
                                $excess_charge = 0;
                                
                                if ($consumption > 6) {
                                    $excess_units = $consumption - 6;
                                    $excess_charge = $excess_units * $excess_rate;
                                }
                                
                                $total = $base_charge + $excess_charge;
                                
                                // Update the total in the database if it's different
                                if ($total != $row['total']) {
                                    $update_stmt = $conn->prepare("UPDATE billing_list SET total = ? WHERE id = ?");
                                    $update_stmt->bind_param("di", $total, $row['id']);
                                    $update_stmt->execute();
                                    $update_stmt->close();
                                    $row['total'] = $total; // Update the row data for display
                                }
                            ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="bill-checkbox bulk-delete-checkbox" value="<?php echo $row['id']; ?>" data-customer="<?php echo htmlspecialchars($row['firstname'] . ' ' . $row['lastname']); ?>" data-status="<?php echo $status_text; ?>">
                                </td>
                                <td>
                                    <div>
                                        <div class="customer-name" style="cursor: pointer; color: inherit;" 
                                             onclick="viewCustomerDetails(<?php echo $row['client_id']; ?>)"
                                             onmouseover="this.style.color='#0d6efd';" 
                                             onmouseout="this.style.color='inherit';"
                                             title="Click to view customer details and billing history">
                                            <?php echo htmlspecialchars($row['firstname'] . ' ' . $row['lastname']); ?>
                                        </div>
                                        <div class="customer-code">
                                            <?php echo htmlspecialchars($row['meter_code']); ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-bold"><?php echo number_format($row['reading'], 2); ?></span>
                                        <small class="text-muted">(Current)</small>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-bold"><?php echo number_format($row['previous'], 2); ?></span>
                                        <small class="text-muted">(Previous)</small>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-bold"><?php echo number_format($consumption, 2); ?></span>
                                        <small class="text-muted">(Used)</small>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-bold">₱<?php echo number_format($total, 2); ?></span>
                                        <small class="text-muted">
                                            Base: ₱<?php echo number_format($base_charge, 2); ?><br>
                                            <?php if ($excess_charge > 0): ?>
                                            Excess: ₱<?php echo number_format($excess_charge, 2); ?>
                                            <?php endif; ?>
                                            <?php 
                                            $previous_balance = $row['previous_balance'] ?? 0;
                                            if ($previous_balance > 0): 
                                            ?>
                                            Previous Balance: ₱<?php echo number_format($previous_balance, 2); ?><br>
                                            Total Due: ₱<?php echo number_format($total + $previous_balance, 2); ?>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </td>
                                <td><?php echo $row['due_date'] ? date('M d, Y', strtotime($row['due_date'])) : '-'; ?></td>
                                <td>
                                    <?php
                                        // Initialize variables
                                        $status_class = '';
                                        $status_text = 'Unpaid';
                                        $status = $row['status'] ?? 0;

                                        // Recalculate total based on current rates if status is not paid
                                        if ($status == 0) {
                                            // Get current rates for the client's category
                                            $rate_sql = "SELECT cr.rate, cr.excess_rate 
                                                       FROM client_list cl 
                                                       JOIN category_rates cr ON cl.category_id = cr.category_id 
                                                       WHERE cl.id = ?";
                                            $rate_stmt = $conn->prepare($rate_sql);
                                            $rate_stmt->bind_param("i", $row['client_id']);
                                            $rate_stmt->execute();
                                            $rate_result = $rate_stmt->get_result();
                                            if ($rate_data = $rate_result->fetch_assoc()) {
                                                $consumption = $row['reading'] - $row['previous'];
                                                if ($consumption <= 6) {
                                                    $new_total = $rate_data['rate'];
                                                } else {
                                                    $excess = $consumption - 6;
                                                    $new_total = $rate_data['rate'] + ($excess * $rate_data['excess_rate']);
                                                }
                                                // Update the bill with new total
                                                $update_sql = "UPDATE billing_list SET total = ? WHERE id = ?";
                                                $update_stmt = $conn->prepare($update_sql);
                                                $update_stmt->bind_param("di", $new_total, $row['bill_id']);
                                                $update_stmt->execute();
                                                $row['total'] = $new_total;
                                            }
                                        }

                                        // Determine status class and text
                                        switch($status) {
                                            case 1:
                                                $status_class = 'status-paid';
                                                $status_text = 'Paid';
                                                break;
                                            case 0:
                                                if (strtotime($row['due_date']) < time()) {
                                                    $status_class = 'status-overdue';
                                                    $status_text = 'Overdue';
                                                } else {
                                                    $status_class = 'status-unpaid';
                                                    $status_text = 'Unpaid';
                                                }
                                                break;
                                            default:
                                                $status_class = 'status-unpaid';
                                                $status_text = 'Unpaid';
                                        }
                                    ?>
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <?php echo $status_text; ?>
                                    </span>
                                </td>
                                <td class="action-links">
                                    <a href="javascript:void(0)" class="btn btn-sm btn-outline-primary view-btn" data-id="<?php echo $row['id']; ?>" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="javascript:void(0)" 
                                       class="btn btn-sm btn-outline-danger delete-btn" 
                                       data-id="<?php echo $row['id']; ?>" 
                                       data-customer="<?php echo htmlspecialchars($row['firstname'] . ' ' . $row['lastname']); ?>"
                                       data-amount="<?php echo number_format($total, 2); ?>"
                                       data-status="<?php echo $status_text; ?>"
                                       data-status-class="<?php echo $status_class; ?>"
                                       title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; 
                        else: ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted">No billing records found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add this after the existing billing table card -->
    <div class="card card-soft mt-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h5 class="card-title mb-0">Paid Bills History</h5>
                <div class="d-flex gap-2">
                    <select class="form-select form-select-sm" id="monthFilter" style="width: 150px;">
                        <?php
                        for ($i = 0; $i < 12; $i++) {
                            $date = date('Y-m', strtotime("-$i months"));
                            $formatted = date('F Y', strtotime($date));
                            echo "<option value='$date'" . ($i === 0 ? ' selected' : '') . ">$formatted</option>";
                        }
                        ?>
                    </select>
                                    <div class="btn-group" role="group">
                    <button class="btn btn-sm btn-outline-primary" id="exportPaidBills">
                        <i class="fas fa-download me-1"></i>Export Paid Bills
                    </button>
                    <a href="reports.php?type=billing" class="btn btn-sm btn-success">
                        <i class="fas fa-chart-bar me-1"></i>Billing Reports
                    </a>
                    <a href="reports.php?type=collections" class="btn btn-sm btn-info">
                        <i class="fas fa-money-bill-wave me-1"></i>Collections Reports
                    </a>
                </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover" id="paidBillsTable">
                    <thead>
                        <tr>
                            <th>Bill #</th>
                            <th>Customer</th>
                            <th>Reading Date</th>
                            <th>Consumption</th>
                            <th>Amount</th>
                            <th>Payment Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Get current month and year
                        $current_month = date('Y-m');
                        
                        // Fetch paid bills for current month
                        $paid_bills_sql = "SELECT b.*, c.firstname, c.lastname, c.meter_code 
                                         FROM billing_list b 
                                         LEFT JOIN client_list c ON b.client_id = c.id 
                                         WHERE b.status = 1 
                                         AND DATE_FORMAT(b.reading_date, '%Y-%m') = ?
                                         ORDER BY b.reading_date DESC";
                        
                        $stmt = $conn->prepare($paid_bills_sql);
                        $stmt->bind_param("s", $current_month);
                        $stmt->execute();
                        $paid_result = $stmt->get_result();

                        if ($paid_result->num_rows > 0):
                            while($row = $paid_result->fetch_assoc()):
                                // Calculate consumption and charges
                                $consumption = max(0, $row['reading'] - $row['previous']);
                                
                                // Calculate charges using fixed rates
                                $base_rate = 100; // Base rate for first 6 cubic meters
                                $excess_rate = 20; // Rate per cubic meter for excess
                                
                                // Calculate total
                                $base_charge = $base_rate;
                                $excess_charge = 0;
                                
                                if ($consumption > 6) {
                                    $excess_units = $consumption - 6;
                                    $excess_charge = $excess_units * $excess_rate;
                                }
                                
                                $total = $base_charge + $excess_charge;
                                
                                // Update the total in the database if it's different
                                if ($total != $row['total']) {
                                    $update_stmt = $conn->prepare("UPDATE billing_list SET total = ? WHERE id = ?");
                                    $update_stmt->bind_param("di", $total, $row['id']);
                                    $update_stmt->execute();
                                    $update_stmt->close();
                                    $row['total'] = $total; // Update the row data for display
                                }
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['id']); ?></td>
                            <td>
                                <div>
                                    <div class="customer-name">
                                        <?php echo htmlspecialchars($row['firstname'] . ' ' . $row['lastname']); ?>
                                    </div>
                                    <div class="customer-code">
                                        <?php echo htmlspecialchars($row['meter_code']); ?>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($row['reading_date'])); ?></td>
                            <td>
                                <div class="d-flex flex-column">
                                    <span class="fw-bold"><?php echo number_format($consumption, 2); ?></span>
                                    <small class="text-muted">
                                        Previous: <?php echo number_format($row['previous'], 2); ?><br>
                                        Current: <?php echo number_format($row['reading'], 2); ?>
                                    </small>
                                </div>
                            </td>
                            <td>
                                <div class="d-flex flex-column">
                                    <span class="fw-bold">₱<?php echo number_format($total, 2); ?></span>
                                    <small class="text-muted">
                                        Base: ₱<?php echo number_format($base_charge, 2); ?><br>
                                        <?php if ($excess_charge > 0): ?>
                                        Excess: ₱<?php echo number_format($excess_charge, 2); ?>
                                        <?php endif; ?>
                                        <?php 
                                        $previous_balance = $row['previous_balance'] ?? 0;
                                        if ($previous_balance > 0): 
                                        ?>
                                        Previous Balance: ₱<?php echo number_format($previous_balance, 2); ?><br>
                                        Total Due: ₱<?php echo number_format($total + $previous_balance, 2); ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($row['updated_at'] ?? $row['reading_date'])); ?></td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary view-receipt" data-id="<?php echo $row['id']; ?>" title="View Receipt">
                                    <i class="fas fa-receipt"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-secondary print-receipt" data-id="<?php echo $row['id']; ?>" title="Print Receipt">
                                    <i class="fas fa-print"></i>
                                </button>
                            </td>
                        </tr>
                        <?php 
                            endwhile;
                        else:
                        ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">No paid bills found for this month</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Billing Modal -->
<div class="modal fade" id="addBillingModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Billing</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="addBillingForm" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create">
                    <div class="mb-3">
                        <label class="form-label">Client</label>
                        <select class="form-select" name="client_id" id="clientSelect" required>
                            <option value="">Select Client</option>
                            <?php
                            $client_sql = "SELECT id, CONCAT(firstname, ' ', lastname, ' (', meter_code, ')') as client_name FROM client_list ORDER BY firstname";
                            $client_result = $conn->query($client_sql);
                            while ($client = $client_result->fetch_assoc()) {
                                echo "<option value='" . $client['id'] . "'>" . htmlspecialchars($client['client_name']) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Reading Date</label>
                            <input type="date" class="form-control" name="reading_date" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Due Date</label>
                            <input type="date" class="form-control" name="due_date" required>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Previous Reading</label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="previous" id="previousReading" step="0.01" required>
                                <button type="button" class="btn btn-outline-secondary" id="getPreviousReadingBtn" title="Get last reading">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                            <small class="text-muted">Click the button to auto-fill from last bill</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Current Reading</label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="reading" step="0.01" required>
                                <span class="input-group-text">cu.m</span>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status" required>
                            <option value="0">Unpaid</option>
                            <option value="1">Paid</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Bill</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Bill Modal -->
<div class="modal fade" id="viewBillModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title">Billing Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="customer-header">
                    <div class="avatar-lg bg-primary rounded-circle text-white d-inline-flex align-items-center justify-content-center">
                        <span id="viewCustomerInitials"></span>
                    </div>
                    <h4 class="customer-name" id="viewCustomerName"></h4>
                    <p class="customer-code" id="viewMeterCode"></p>
                </div>

                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="modal-card">
                            <h6 class="card-subtitle">Billing Information</h6>
                            <div class="info-section">
                                <div class="bill-detail-label">Bill Number</div>
                                <div class="bill-detail-value" id="viewBillNumber"></div>
                            </div>
                            <div class="info-section">
                                <div class="bill-detail-label">Reading Date</div>
                                <div class="bill-detail-value" id="viewReadingDate"></div>
                            </div>
                            <div class="info-section">
                                <div class="bill-detail-label">Due Date</div>
                                <div class="bill-detail-value" id="viewDueDate"></div>
                            </div>
                            <div class="info-section">
                                <div class="bill-detail-label">Status</div>
                                <div class="bill-detail-value">
                                    <span id="viewStatus" class="status-badge"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="modal-card">
                            <h6 class="card-subtitle">Consumption Details</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="info-section">
                                        <div class="bill-detail-label">Previous Reading</div>
                                        <div class="bill-detail-value" id="viewPreviousReading"></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-section">
                                        <div class="bill-detail-label">Current Reading</div>
                                        <div class="bill-detail-value" id="viewCurrentReading"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="info-section mt-3">
                                <div class="bill-detail-label">Total Consumption</div>
                                <div class="bill-detail-value" id="viewConsumption"></div>
                            </div>
                            <div class="info-section">
                                <div class="bill-detail-label">Rate Information</div>
                                <div class="bill-detail-value">
                                    <small class="text-muted">Base Rate (up to 6 cu.m):</small> <span id="viewBaseRate"></span><br>
                                    <small class="text-muted">Excess Rate (per cu.m):</small> <span id="viewExcessRate"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-card mt-3">
                    <h6 class="card-subtitle">Payment Summary</h6>
                    <div class="payment-summary-row">
                        <span class="payment-summary-label">Base Charge (First 6 cu.m)</span>
                        <span class="payment-summary-value" id="viewBaseCharge"></span>
                    </div>
                    <div class="payment-summary-row">
                        <span class="payment-summary-label">Excess Charge</span>
                        <span class="payment-summary-value" id="viewExcessCharge"></span>
                    </div>
                    <div class="payment-summary-row">
                        <span class="payment-summary-label">Current Bill Amount</span>
                        <span class="payment-summary-value" id="viewCurrentAmount"></span>
                    </div>
                    <div class="payment-summary-row previous-balance" id="previousBalanceRow">
                        <span class="payment-summary-label">Previous Balance</span>
                        <span class="payment-summary-value" id="viewPreviousBalance"></span>
                    </div>
                    <div class="payment-summary-row payment-total">
                        <span class="payment-summary-label">Total Amount Due</span>
                        <span class="total-amount" id="viewTotalAmount"></span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-sm btn-success" id="markAsPaidBtn">Mark as Paid</button>
                <button type="button" class="btn btn-sm btn-primary" id="printBillBtn">
                    <i class="fas fa-print me-1"></i>Print
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Customer Details Modal -->
<div class="modal fade" id="customerDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header border-bottom" style="background: linear-gradient(to right, #f8f9fa 0%, #e9ecef 100%);">
                <h5 class="modal-title fw-bold text-primary">
                    <i class="fas fa-user-circle me-2"></i>Customer Details & Billing History
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="customerDetailsLoading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading customer details...</p>
                </div>
                
                <div id="customerDetailsContent" style="display: none;">
                    <!-- Customer Information Section -->
                    <div class="card mb-3 border shadow-sm" style="background: #ffffff;">
                        <div class="card-header" style="background: linear-gradient(to right, #e3f2fd 0%, #bbdefb 100%); border-bottom: 2px solid #2196f3;">
                            <h6 class="mb-0 fw-bold text-primary">
                                <i class="fas fa-info-circle me-2"></i>Customer Information
                            </h6>
                        </div>
                        <div class="card-body" style="background: #fafafa;">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3 p-2 rounded" style="background: #ffffff;">
                                        <small class="text-muted d-block mb-1"><i class="fas fa-user text-primary me-1"></i>Name</small>
                                        <div class="fw-semibold text-dark" id="customerFullName"></div>
                                    </div>
                                    <div class="mb-3 p-2 rounded" style="background: #ffffff;">
                                        <small class="text-muted d-block mb-1"><i class="fas fa-tachometer-alt text-primary me-1"></i>Meter Code</small>
                                        <div class="fw-semibold text-dark" id="customerMeterCode"></div>
                                    </div>
                                    <div class="mb-3 p-2 rounded" style="background: #ffffff;">
                                        <small class="text-muted d-block mb-1"><i class="fas fa-phone text-primary me-1"></i>Contact</small>
                                        <div id="customerContact"></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3 p-2 rounded" style="background: #ffffff;">
                                        <small class="text-muted d-block mb-1"><i class="fas fa-map-marker-alt text-primary me-1"></i>Address</small>
                                        <div id="customerAddress"></div>
                                    </div>
                                    <div class="mb-3 p-2 rounded" style="background: #ffffff;">
                                        <small class="text-muted d-block mb-1"><i class="fas fa-tag text-primary me-1"></i>Category</small>
                                        <div id="customerCategory"></div>
                                    </div>
                                    <div class="mb-3 p-2 rounded" style="background: #ffffff;">
                                        <small class="text-muted d-block mb-1"><i class="fas fa-money-bill-wave text-primary me-1"></i>Rate</small>
                                        <div>₱<span id="customerRate"></span> (Base) / ₱<span id="customerExcessRate"></span> (Excess per cu.m)</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Statistics Section -->
                    <div class="row mb-3 g-2">
                        <div class="col-md-3">
                            <div class="card border-0 shadow-sm text-center" style="background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);">
                                <div class="card-body py-3">
                                    <i class="fas fa-file-invoice fa-lg text-primary mb-2"></i>
                                    <div class="text-muted small mb-1">Total Bills</div>
                                    <div class="h4 mb-0 fw-bold text-primary" id="statTotalBills">0</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card border-0 shadow-sm text-center" style="background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);">
                                <div class="card-body py-3">
                                    <i class="fas fa-check-circle fa-lg text-success mb-2"></i>
                                    <div class="text-muted small mb-1">Paid</div>
                                    <div class="h4 mb-0 fw-bold text-success" id="statPaidBills">0</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card border-0 shadow-sm text-center" style="background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%);">
                                <div class="card-body py-3">
                                    <i class="fas fa-clock fa-lg text-warning mb-2"></i>
                                    <div class="text-muted small mb-1">Unpaid</div>
                                    <div class="h4 mb-0 fw-bold text-warning" id="statUnpaidBills">0</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card border-0 shadow-sm text-center" style="background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%);">
                                <div class="card-body py-3">
                                    <i class="fas fa-exclamation-triangle fa-lg text-danger mb-2"></i>
                                    <div class="text-muted small mb-1">Overdue</div>
                                    <div class="h4 mb-0 fw-bold text-danger" id="statOverdueBills">0</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mb-3 g-2">
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm" style="background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);">
                                <div class="card-body py-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="text-muted small mb-1"><i class="fas fa-chart-line me-1"></i>Total Billed</div>
                                            <div class="h4 mb-0 fw-bold text-primary">₱<span id="statTotalBilled">0.00</span></div>
                                        </div>
                                        <i class="fas fa-coins fa-2x text-primary opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm" style="background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%);">
                                <div class="card-body py-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="text-muted small mb-1"><i class="fas fa-wallet me-1"></i>Total Outstanding</div>
                                            <div class="h4 mb-0 fw-bold text-danger">₱<span id="statTotalOutstanding">0.00</span></div>
                                        </div>
                                        <i class="fas fa-exclamation-circle fa-2x text-danger opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Billing History Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header" style="background: linear-gradient(to right, #e3f2fd 0%, #bbdefb 100%); border-bottom: 2px solid #2196f3;">
                            <h6 class="mb-0 fw-bold text-primary">
                                <i class="fas fa-history me-2"></i>Billing History
                            </h6>
                        </div>
                        <div class="card-body p-0" style="background: #fafafa;">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0 align-middle">
                                    <thead style="background: linear-gradient(to right, #f5f5f5 0%, #eeeeee 100%);">
                                        <tr>
                                            <th class="fw-semibold py-3">Billing Month</th>
                                            <th class="fw-semibold py-3">Reading Date</th>
                                            <th class="fw-semibold py-3">Due Date</th>
                                            <th class="fw-semibold py-3">Consumption</th>
                                            <th class="text-end fw-semibold py-3">Amount</th>
                                            <th class="text-end fw-semibold py-3">Paid</th>
                                            <th class="text-end fw-semibold py-3">Balance</th>
                                            <th class="text-center fw-semibold py-3">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="billingHistoryTableBody" style="background: #ffffff;">
                                        <tr>
                                            <td colspan="8" class="text-center text-muted py-4">Loading...</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Theme Toggle
    const themeToggle = document.getElementById('theme-toggle');
    const html = document.documentElement;
    
    const savedTheme = localStorage.getItem('theme') || 'light';
    html.setAttribute('data-theme', savedTheme);
    themeToggle.checked = savedTheme === 'dark';

    themeToggle.addEventListener('change', function() {
        const theme = this.checked ? 'dark' : 'light';
        html.setAttribute('data-theme', theme);
        localStorage.setItem('theme', theme);
    });

    // Modal cleanup function
    function cleanupModal(modalElement) {
        const backdrop = document.querySelector('.modal-backdrop');
        if (backdrop) {
            backdrop.remove();
        }
        modalElement.classList.remove('show');
        modalElement.style.display = 'none';
        document.body.classList.remove('modal-open');
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';
    }

    // View Bill Modal Functionality
    // Real-time search functionality
    const searchInput = document.getElementById('searchInput');
    const statusSelect = document.getElementById('statusSelect');
    const billingMonthSelect = document.getElementById('billingMonthSelect');
    const filterForm = document.getElementById('filterForm');
    const resetFiltersBtn = document.getElementById('resetFilters');
    
    let searchTimeout;
    
    // Real-time search with debouncing
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() {
                filterForm.submit();
            }, 500); // Wait 500ms after user stops typing
        });
    }
    
    // Auto-submit when status changes
    if (statusSelect) {
        statusSelect.addEventListener('change', function() {
            filterForm.submit();
        });
    }
    
    // Auto-submit when billing month changes
    if (billingMonthSelect) {
        billingMonthSelect.addEventListener('change', function() {
            filterForm.submit();
        });
    }
    
    // Reset filters
    if (resetFiltersBtn) {
        resetFiltersBtn.addEventListener('click', function() {
            searchInput.value = '';
            statusSelect.value = '';
            billingMonthSelect.value = '';
            filterForm.submit();
        });
    }

    const viewModalElement = document.getElementById('viewBillModal');
    const viewModal = new bootstrap.Modal(viewModalElement, {
        backdrop: 'static',
        keyboard: false
    });

    // Add modal cleanup on hide for view modal
    viewModalElement.addEventListener('hidden.bs.modal', function() {
        cleanupModal(viewModalElement);
    });

    // Handle close button (X) for view modal
    document.querySelector('#viewBillModal .btn-close').addEventListener('click', function() {
        viewModal.hide();
        cleanupModal(viewModalElement);
    });

    // Handle close button for view modal
    document.querySelector('#viewBillModal .btn-secondary').addEventListener('click', function() {
        viewModal.hide();
        cleanupModal(viewModalElement);
    });

    document.querySelectorAll('.view-btn').forEach(button => {
        button.addEventListener('click', async function() {
            try {
                const billId = this.getAttribute('data-id');
                if (!billId) {
                    throw new Error('Bill ID not found');
                }
                
                // Show loading state
                this.setAttribute('disabled', 'disabled');
                
                // Fetch bill details from server
                const response = await fetch(`get_bill_details.php?id=${billId}`);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const text = await response.text();
                let data;
                
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    console.error('JSON parse error:', e);
                    console.error('Raw response:', text);
                    throw new Error('Invalid response format from server');
                }
                
                if (!data.success) {
                    throw new Error(data.message || 'Failed to fetch bill details');
                }
                
                const bill = data.data;
                if (!bill) {
                    throw new Error('No bill data received');
                }
                
                // Get customer details from row (fallback if API doesn't provide)
                const row = this.closest('tr');
                let customerName = '';
                let meterCode = '';
                let initials = '';
                
                if (row) {
                    const customerDiv = row.querySelector('td:nth-child(2)');
                    if (customerDiv) {
                        const customerNameElem = customerDiv.querySelector('.customer-name');
                        const meterCodeElem = customerDiv.querySelector('.customer-code');
                        if (customerNameElem) customerName = customerNameElem.textContent.trim();
                        if (meterCodeElem) meterCode = meterCodeElem.textContent.trim();
                    }
                }
                
                // Use API data if available, otherwise use table data
                customerName = (bill.firstname && bill.lastname) ? `${bill.firstname} ${bill.lastname}` : customerName;
                meterCode = bill.meter_code || meterCode;
                
                // Generate initials from customer name
                const nameParts = customerName.split(' ').filter(p => p.length > 0);
                initials = nameParts.map(part => part.charAt(0)).join('').toUpperCase();
                
                // Get rates from API or use defaults - ensure they are numbers
                const baseRate = parseFloat(bill.rate) || 100;
                const excessRate = parseFloat(bill.excess_rate) || 20;
                
                // Calculate consumption
                const reading = parseFloat(bill.reading) || 0;
                const previous = parseFloat(bill.previous) || 0;
                const consumption = parseFloat(bill.consumption) || Math.max(0, reading - previous);
                
                // Calculate charges - ensure they are numbers
                const baseCharge = parseFloat(bill.base_charge) || baseRate;
                const excessCharge = parseFloat(bill.excess_charge) || 0;
                const currentBillAmount = parseFloat(bill.total) || (baseCharge + excessCharge);
                
                // Get previous balance from API or calculate
                let previousBalance = 0;
                if (row) {
                    const amountDiv = row.querySelector('td:nth-child(6)');
                    if (amountDiv) {
                        const previousBalanceMatch = amountDiv.textContent.match(/Previous Balance: ₱([\d,]+\.?\d*)/);
                        if (previousBalanceMatch) {
                            previousBalance = parseFloat(previousBalanceMatch[1].replace(/,/g, ''));
                        }
                    }
                }
                
                const totalAmountDue = currentBillAmount + previousBalance;
                
                // Get status from API or table
                let status = 'Unpaid';
                if (bill.status == 1) {
                    status = 'Paid';
                } else if (bill.due_date) {
                    const dueDate = new Date(bill.due_date);
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);
                    if (dueDate < today) {
                        status = 'Overdue';
                    }
                }
                
                // Format dates
                const readingDate = bill.reading_date_formatted ? new Date(bill.reading_date_formatted).toLocaleDateString() : (bill.reading_date ? new Date(bill.reading_date).toLocaleDateString() : '');
                const dueDate = bill.due_date_formatted ? new Date(bill.due_date_formatted).toLocaleDateString() : (bill.due_date ? new Date(bill.due_date).toLocaleDateString() : '');

                // Update modal content - check if elements exist before updating
                const updateElement = (id, value) => {
                    const elem = document.getElementById(id);
                    if (elem) elem.textContent = value;
                };
                
                updateElement('viewCustomerInitials', initials);
                updateElement('viewCustomerName', customerName);
                updateElement('viewMeterCode', meterCode);
                updateElement('viewBillNumber', billId);
                updateElement('viewReadingDate', readingDate);
                updateElement('viewDueDate', dueDate);
                updateElement('viewPreviousReading', `${previous.toFixed(2)}`);
                updateElement('viewCurrentReading', `${reading.toFixed(2)}`);
                updateElement('viewConsumption', `${consumption.toFixed(2)}`);
                updateElement('viewBaseRate', `₱${baseRate.toFixed(2)}`);
                updateElement('viewExcessRate', `₱${excessRate.toFixed(2)}`);
                updateElement('viewBaseCharge', `₱${baseCharge.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`);
                updateElement('viewExcessCharge', `₱${excessCharge.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`);
                updateElement('viewCurrentAmount', `₱${currentBillAmount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`);
                updateElement('viewTotalAmount', `₱${totalAmountDue.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`);
                
                // Show/hide and update previous balance
                const previousBalanceRow = document.getElementById('previousBalanceRow');
                if (previousBalanceRow) {
                    if (previousBalance > 0) {
                        previousBalanceRow.style.display = 'flex';
                        updateElement('viewPreviousBalance', `₱${previousBalance.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`);
                    } else {
                        previousBalanceRow.style.display = 'none';
                    }
                }

                // Update status
                const statusElem = document.getElementById('viewStatus');
                if (statusElem) {
                    statusElem.textContent = status;
                    statusElem.className = 'status-badge ' + (
                        status === 'Paid' ? 'status-paid' :
                        status === 'Overdue' ? 'status-overdue' : 'status-unpaid'
                    );
                }

                // Show/hide "Mark as Paid" button based on status
                const markAsPaidBtn = document.getElementById('markAsPaidBtn');
                if (markAsPaidBtn) {
                    markAsPaidBtn.style.display = status === 'Paid' ? 'none' : 'block';
                }

                // Remove loading state
                this.removeAttribute('disabled');
                
                viewModal.show();
            } catch (error) {
                // Remove loading state
                this.removeAttribute('disabled');
                console.error('Error viewing bill:', error);
                alert('Error viewing bill details: ' + error.message);
            }
        });
    });

    // Handle "Mark as Paid" button click
    document.getElementById('markAsPaidBtn').addEventListener('click', async function() {
        try {
            const billId = document.getElementById('viewBillNumber').textContent;
            const response = await fetch('mark_bill_paid.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `bill_id=${billId}`
            });
            
            const data = await response.json();
            
            if (data.success) {
                // Show success message
                const toast = document.createElement('div');
                toast.className = 'alert alert-success position-fixed top-0 end-0 m-3';
                toast.style.zIndex = '9999';
                toast.innerHTML = `
                    <i class="fas fa-check-circle me-2"></i>
                    Bill marked as paid successfully
                `;
                document.body.appendChild(toast);
                
                // Hide toast after 3 seconds
                setTimeout(() => {
                    toast.remove();
                }, 3000);
                
                // Close modal and refresh
                viewModal.hide();
                cleanupModal(viewModalElement);
                location.reload();
            } else {
                throw new Error(data.message || 'Failed to mark bill as paid');
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Error marking bill as paid: ' + error.message);
        }
    });

    // Handle print button click
    document.getElementById('printBillBtn').addEventListener('click', function() {
        window.print();
    });

    // Month filter functionality
    const monthFilter = document.getElementById('monthFilter');
    monthFilter.addEventListener('change', function() {
        const selectedMonth = this.value;
        
        // Show loading state
        const tbody = document.querySelector('#paidBillsTable tbody');
        tbody.innerHTML = '<tr><td colspan="7" class="text-center"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></td></tr>';
        
        // Fetch paid bills for selected month
        fetch(`get_paid_bills.php?month=${selectedMonth}`)
            .then(response => response.json())
            .then(data => {
                tbody.innerHTML = ''; // Clear loading state
                
                if (data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No paid bills found for this month</td></tr>';
                    return;
                }
                
                data.forEach(bill => {
                    const consumption = bill.reading - bill.previous;
                    const row = `
                        <tr>
                            <td>${bill.id}</td>
                            <td>
                                <div>
                                    <div class="customer-name">
                                        ${bill.firstname} ${bill.lastname}
                                    </div>
                                    <div class="customer-code">
                                        ${bill.meter_code}
                                    </div>
                                </div>
                            </td>
                            <td>${new Date(bill.reading_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</td>
                            <td>${consumption.toFixed(2)}</td>
                            <td>₱${parseFloat(bill.total).toFixed(2)}</td>
                            <td>${new Date(bill.updated_at || bill.reading_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary view-receipt" data-id="${bill.id}" title="View Receipt">
                                    <i class="fas fa-receipt"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-secondary print-receipt" data-id="${bill.id}" title="Print Receipt">
                                    <i class="fas fa-print"></i>
                                </button>
                            </td>
                        </tr>
                    `;
                    tbody.insertAdjacentHTML('beforeend', row);
                });
                
                // Reattach event listeners to new buttons
                attachReceiptHandlers();
            })
            .catch(error => {
                console.error('Error:', error);
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger">Error loading paid bills</td></tr>';
            });
    });

    // Export functionality
    document.getElementById('exportPaidBills').addEventListener('click', function() {
        const selectedMonth = monthFilter.value;
        window.location.href = `export_paid_bills.php?month=${selectedMonth}`;
    });

    // Receipt handlers
    function attachReceiptHandlers() {
        document.querySelectorAll('.view-receipt').forEach(button => {
            button.addEventListener('click', function() {
                const billId = this.getAttribute('data-id');
                // Add your receipt viewing logic here
                alert('View receipt for bill #' + billId);
            });
        });

        document.querySelectorAll('.print-receipt').forEach(button => {
            button.addEventListener('click', function() {
                const billId = this.getAttribute('data-id');
                // Add your receipt printing logic here
                alert('Print receipt for bill #' + billId);
            });
        });
    }

    // Initial attachment of receipt handlers
    attachReceiptHandlers();

    // Calculate totals function
    async function calculateTotals() {
        try {
            const previousReading = parseFloat(document.getElementById('editPreviousReading').value) || 0;
            const currentReading = parseFloat(document.getElementById('editCurrentReading').value) || 0;
            const billId = document.getElementById('editBillId').value;
            
            if (!billId) {
                throw new Error('Bill ID is missing');
            }

            // Calculate consumption
            const consumption = Math.max(0, currentReading - previousReading);
            
            // Get rates for this bill
            const response = await fetch(`get_bill_rates.php?bill_id=${billId}`);
            const data = await response.json();
            
            if (!data.success) {
                throw new Error(data.message || 'Failed to get rate information');
            }
            
            const baseRate = parseFloat(data.rate);
            const excessRate = parseFloat(data.excess_rate);
            
            if (isNaN(baseRate) || isNaN(excessRate)) {
                throw new Error('Invalid rate values received');
            }
            
            let total;
            let baseCharge = baseRate;
            let excessCharge = 0;
            
            if (consumption > 6) {
                const excessConsumption = consumption - 6;
                excessCharge = excessConsumption * excessRate;
                total = baseRate + excessCharge;
            } else {
                total = baseRate;
            }

            // Update the display
            document.getElementById('editConsumption').textContent = `${consumption.toFixed(2)}`;
            document.getElementById('editBaseCharge').textContent = `₱${baseCharge.toFixed(2)}`;
            document.getElementById('editExcessCharge').textContent = `₱${excessCharge.toFixed(2)}`;
            document.getElementById('editTotalAmount').textContent = `₱${total.toFixed(2)}`;
            document.getElementById('editTotalInput').value = total.toFixed(2);
        } catch (error) {
            console.error('Error calculating totals:', error);
            alert('Error calculating bill totals: ' + error.message);
        }
    }

    // Add calculation listeners
    ['editPreviousReading', 'editCurrentReading'].forEach(id => {
        const element = document.getElementById(id);
        if (element) {
            element.addEventListener('input', calculateTotals);
        }
    });

    // Customer Details Modal Functionality
    window.viewCustomerDetails = function(clientId) {
        const modal = new bootstrap.Modal(document.getElementById('customerDetailsModal'));
        const loadingDiv = document.getElementById('customerDetailsLoading');
        const contentDiv = document.getElementById('customerDetailsContent');
        
        // Show loading, hide content
        loadingDiv.style.display = 'block';
        contentDiv.style.display = 'none';
        
        // Show modal
        modal.show();
        
        // Fetch customer details
        fetch(`get_customer_billing_details.php?client_id=${clientId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Populate customer information
                    document.getElementById('customerFullName').textContent = 
                        `${data.client.firstname} ${data.client.middlename || ''} ${data.client.lastname}`.trim();
                    document.getElementById('customerMeterCode').textContent = data.client.meter_code || 'N/A';
                    document.getElementById('customerContact').textContent = data.client.contact || 'N/A';
                    document.getElementById('customerAddress').textContent = data.client.address || 'N/A';
                    document.getElementById('customerCategory').textContent = data.client.category_name || 'N/A';
                    document.getElementById('customerRate').textContent = parseFloat(data.client.rate || 0).toFixed(2);
                    document.getElementById('customerExcessRate').textContent = parseFloat(data.client.excess_rate || 0).toFixed(2);
                    
                    // Populate statistics
                    document.getElementById('statTotalBills').textContent = data.statistics.total_bills || 0;
                    document.getElementById('statPaidBills').textContent = data.statistics.paid_bills || 0;
                    document.getElementById('statUnpaidBills').textContent = data.statistics.unpaid_bills || 0;
                    document.getElementById('statOverdueBills').textContent = data.statistics.overdue_bills || 0;
                    document.getElementById('statTotalBilled').textContent = parseFloat(data.statistics.total_billed || 0).toFixed(2);
                    document.getElementById('statTotalOutstanding').textContent = parseFloat(data.statistics.total_outstanding || 0).toFixed(2);
                    
                    // Populate billing history
                    const tbody = document.getElementById('billingHistoryTableBody');
                    if (data.bills && data.bills.length > 0) {
                        tbody.innerHTML = data.bills.map(bill => {
                            const statusClass = bill.status_text === 'Paid' ? 'status-paid' : 
                                              bill.status_text === 'Overdue' ? 'status-overdue' : 'status-unpaid';
                            const overdueBadge = bill.days_overdue > 0 ? 
                                `<span class="badge bg-danger ms-2">${bill.days_overdue} day(s) overdue</span>` : '';
                            
                            return `
                                <tr>
                                    <td>${bill.billing_month || 'N/A'}</td>
                                    <td>${bill.reading_date_formatted}</td>
                                    <td>${bill.due_date_formatted}${overdueBadge}</td>
                                    <td>${parseFloat(bill.consumption).toFixed(2)} cu.m</td>
                                    <td class="text-end"><strong>₱${parseFloat(bill.total).toFixed(2)}</strong></td>
                                    <td class="text-end">₱${parseFloat(bill.amount_paid).toFixed(2)}</td>
                                    <td class="text-end"><strong>₱${parseFloat(bill.remaining_balance).toFixed(2)}</strong></td>
                                    <td class="text-center"><span class="status-badge ${statusClass}">${bill.status_text}</span></td>
                                </tr>
                            `;
                        }).join('');
                    } else {
                        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No billing history found</td></tr>';
                    }
                    
                    // Hide loading, show content
                    loadingDiv.style.display = 'none';
                    contentDiv.style.display = 'block';
                } else {
                    alert('Error loading customer details: ' + (data.message || 'Unknown error'));
                    modal.hide();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error loading customer details: ' + error.message);
                modal.hide();
            });
    };
    
    document.querySelectorAll('.delete-btn').forEach(button => {
        button.addEventListener('click', async function() {
            const billId = this.getAttribute('data-id');
            const customer = this.getAttribute('data-customer') || 'Unknown Customer';
            const amount = this.getAttribute('data-amount') || '0.00';
            const status = this.getAttribute('data-status') || 'Unknown';
            const statusClass = this.getAttribute('data-status-class') || '';
            
            console.log('Attempting to delete bill ID:', billId);
            
            // Create a more detailed confirmation message
            const confirmMessage = `Are you sure you want to delete this bill?\n\n` +
                `Bill ID: #${billId}\n` +
                `Customer: ${customer}\n` +
                `Amount: ₱${amount}\n` +
                `Status: ${status}\n\n` +
                `⚠️ WARNING: This will permanently delete:\n` +
                `• The billing record\n` +
                `• All associated payment records\n\n` +
                `This action cannot be undone!`;
            
            if (confirm(confirmMessage)) {
                try {
                    // Disable the delete button and show loading state
                    this.disabled = true;
                    const originalHtml = this.innerHTML;
                    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

                    console.log('Sending delete request for bill ID:', billId);
                    const response = await fetch('delete_bill.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `bill_id=${billId}`
                    });
                    
                    const data = await response.json();
                    console.log('Delete response:', data);
                    
                    if (data.success) {
                        // Show success message
                        const toast = document.createElement('div');
                        toast.className = 'alert alert-success position-fixed top-0 end-0 m-3';
                        toast.style.zIndex = '9999';
                        toast.innerHTML = `
                            <i class="fas fa-check-circle me-2"></i>
                            ${data.message}
                        `;
                        document.body.appendChild(toast);
                        
                        // Remove the row from the table
                        const row = this.closest('tr');
                        if (row) {
                            row.remove();
                        }
                        
                        // Hide toast after 3 seconds
                        setTimeout(() => {
                            toast.remove();
                        }, 3000);

                        // Reload the page to refresh the data
                        location.reload();
                    } else {
                        throw new Error(data.message || 'Failed to delete bill');
                    }
                } catch (error) {
                    console.error('Error:', error);
                    alert('Error deleting bill: ' + error.message);
                    // Restore the button state
                    this.disabled = false;
                    this.innerHTML = originalHtml;
                }
            }
        });
    });

    // Bulk Delete Functionality
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    const billCheckboxes = document.querySelectorAll('.bulk-delete-checkbox');
    const selectAllBtn = document.getElementById('selectAllBtn');
    const deselectAllBtn = document.getElementById('deselectAllBtn');
    const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
    const bulkDeleteBtn2 = document.getElementById('bulkDeleteBtn2');
    const bulkActionControls = document.getElementById('bulkActionControls');
    const selectedBillsCount = document.getElementById('selectedBillsCount');
    const selectedCount = document.getElementById('selectedCount');

    function updateBulkDeleteButtons() {
        const selected = document.querySelectorAll('.bulk-delete-checkbox:checked');
        const count = selected.length;
        
        if (count > 0) {
            bulkActionControls.style.display = 'block';
            bulkDeleteBtn.style.display = 'inline-block';
            bulkDeleteBtn.disabled = false;
            bulkDeleteBtn2.disabled = false;
            selectedBillsCount.textContent = `${count} bill(s) selected`;
            selectedCount.textContent = count;
        } else {
            bulkActionControls.style.display = 'none';
            bulkDeleteBtn.style.display = 'none';
            bulkDeleteBtn.disabled = true;
            bulkDeleteBtn2.disabled = true;
            selectedBillsCount.textContent = '0 bills selected';
            selectedCount.textContent = '0';
        }
        
        // Update select all checkbox
        if (selectAllCheckbox) {
            selectAllCheckbox.checked = count === billCheckboxes.length && billCheckboxes.length > 0;
        }
        
        // Update select/deselect all buttons
        if (selectAllBtn && deselectAllBtn) {
            if (count > 0) {
                selectAllBtn.style.display = 'none';
                deselectAllBtn.style.display = 'inline-block';
            } else {
                selectAllBtn.style.display = 'inline-block';
                deselectAllBtn.style.display = 'none';
            }
        }
    }

    // Select All Checkbox
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            billCheckboxes.forEach(function(checkbox) {
                checkbox.checked = this.checked;
            }, this);
            updateBulkDeleteButtons();
        });
    }

    // Individual checkboxes
    billCheckboxes.forEach(function(checkbox) {
        checkbox.addEventListener('change', updateBulkDeleteButtons);
    });

    // Select All Button
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function() {
            billCheckboxes.forEach(function(checkbox) {
                checkbox.checked = true;
            });
            if (selectAllCheckbox) selectAllCheckbox.checked = true;
            updateBulkDeleteButtons();
        });
    }

    // Deselect All Button
    if (deselectAllBtn) {
        deselectAllBtn.addEventListener('click', function() {
            billCheckboxes.forEach(function(checkbox) {
                checkbox.checked = false;
            });
            if (selectAllCheckbox) selectAllCheckbox.checked = false;
            updateBulkDeleteButtons();
        });
    }

    // Bulk Delete Button Handler
    function handleBulkDelete() {
        const selected = document.querySelectorAll('.bulk-delete-checkbox:checked');
        if (selected.length === 0) {
            alert('Please select at least one bill to delete.');
            return;
        }

        const billIds = Array.from(selected).map(function(cb) {
            return cb.value;
        });
        
        const billDetails = Array.from(selected).map(function(cb) {
            return `Bill #${cb.value} (${cb.getAttribute('data-customer')} - ${cb.getAttribute('data-status')})`;
        });

        const confirmMessage = `Are you sure you want to delete ${selected.length} bill(s)?\n\n` +
            `Selected Bills:\n${billDetails.slice(0, 5).join('\n')}` +
            (billDetails.length > 5 ? `\n... and ${billDetails.length - 5} more` : '') +
            `\n\n⚠️ WARNING: This will permanently delete:\n` +
            `• The selected billing records\n` +
            `• All associated payment records\n\n` +
            `This action cannot be undone!`;

        if (confirm(confirmMessage)) {
            // Create form and submit
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'billing_list.php';
            
            billIds.forEach(function(id) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'selected_bills[]';
                input.value = id;
                form.appendChild(input);
            });
            
            const submitInput = document.createElement('input');
            submitInput.type = 'hidden';
            submitInput.name = 'bulk_delete_bills';
            submitInput.value = '1';
            form.appendChild(submitInput);

            document.body.appendChild(form);
            form.submit();
        }
    }

    if (bulkDeleteBtn) {
        bulkDeleteBtn.addEventListener('click', handleBulkDelete);
    }
    
    if (bulkDeleteBtn2) {
        bulkDeleteBtn2.addEventListener('click', handleBulkDelete);
    }

    // Initialize bulk delete buttons state
    updateBulkDeleteButtons();

    // Show notification for bulk delete status
    const urlParams = new URLSearchParams(window.location.search);
    const bulkDeleteStatus = urlParams.get('bulk_delete_status');
    const bulkDeleteMessage = urlParams.get('message');
    
    if (bulkDeleteStatus) {
        const alertClass = bulkDeleteStatus === 'success' ? 'alert-success' : 'alert-danger';
        const icon = bulkDeleteStatus === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        const toast = document.createElement('div');
        toast.className = `alert ${alertClass} position-fixed top-0 end-0 m-3`;
        toast.style.zIndex = '9999';
        toast.innerHTML = `
            <i class="fas ${icon} me-2"></i>
            ${bulkDeleteMessage ? decodeURIComponent(bulkDeleteMessage) : (bulkDeleteStatus === 'success' ? 'Bills deleted successfully!' : 'Error deleting bills!')}
        `;
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.remove();
        }, 5000);
        
        // Clean URL
        window.history.replaceState({}, document.title, window.location.pathname);
    }

    const addBillingForm = document.getElementById('addBillingForm');
    const clientSelect = document.getElementById('clientSelect');

    // Function to fetch previous reading
    async function fetchPreviousReading(clientId) {
        try {
            const response = await fetch(`get_previous_reading.php?client_id=${clientId}`);
            const data = await response.json();
            return data.previous_reading || 0;
        } catch (error) {
            console.error('Error fetching previous reading:', error);
            return 0;
        }
    }
    
    // Auto-fill previous reading when client is selected
    const previousReadingInput = document.getElementById('previousReading');
    const getPreviousReadingBtn = document.getElementById('getPreviousReadingBtn');
    
    if (clientSelect && previousReadingInput && getPreviousReadingBtn) {
        // Auto-fill when client changes
        clientSelect.addEventListener('change', async function() {
            const clientId = this.value;
            if (clientId) {
                const previousReading = await fetchPreviousReading(clientId);
                previousReadingInput.value = previousReading.toFixed(2);
            } else {
                previousReadingInput.value = '';
            }
        });
        
        // Button to manually fetch previous reading
        getPreviousReadingBtn.addEventListener('click', async function() {
            const clientId = clientSelect.value;
            if (!clientId) {
                alert('Please select a client first');
                return;
            }
            const previousReading = await fetchPreviousReading(clientId);
            previousReadingInput.value = previousReading.toFixed(2);
        });
    }

    // Function to generate reference number
    function generateReferenceNumber() {
        const timestamp = new Date().getTime();
        const random = Math.floor(Math.random() * 1000).toString().padStart(3, '0');
        return `REF-${timestamp}-${random}`;
    }

    // Handle client selection
    if (clientSelect) {
        clientSelect.addEventListener('change', async function() {
            const clientId = this.value;
            if (!clientId) {
                billsList.innerHTML = '';
                totalDue.value = '0.00';
                amountToPay.value = '0.00';
                remainingBalance.value = '0.00';
                return;
            }

            try {
                const response = await fetch(`get_client_bills.php?client_id=${clientId}`);
                const bills = await response.json();
                
                billsList.innerHTML = bills.map(bill => `
                    <div class="bill-item border rounded p-3 mb-2">
                        <div class="form-check d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center gap-3">
                                <input class="form-check-input bill-checkbox" type="checkbox" 
                                       value="${bill.id}" 
                                       data-amount="${bill.total}"
                                       data-reading-date="${bill.reading_date}"
                                       style="transform: scale(1.2);">
                                <div>
                                    <div class="fw-bold mb-1">Reading Date: ${bill.reading_date}</div>
                                    <div class="text-muted small">
                                        Current: ${bill.reading} | Previous: ${bill.previous}
                                        <br>
                                        Consumption: ${bill.reading - bill.previous}
                                    </div>
                                </div>
                            </div>
                            <div class="text-end">
                                <div class="fw-bold text-primary h5 mb-1">₱${parseFloat(bill.total).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
                                <small class="text-muted">Bill Amount</small>
                            </div>
                        </div>
                    </div>
                `).join('');

                // Generate reference number when client is selected
                document.getElementById('referenceNumber').value = generateReferenceNumber();

                // Add event listeners to checkboxes
                const totalDue = document.getElementById('totalDue');
                const amountToPay = document.getElementById('amountToPay');
                const remainingBalance = document.getElementById('remainingBalance');

                document.querySelectorAll('.bill-checkbox').forEach(checkbox => {
                    checkbox.addEventListener('change', function() {
                        // Calculate total due from selected bills
                        const selectedBills = document.querySelectorAll('.bill-checkbox:checked');
                        const totalAmount = Array.from(selectedBills)
                            .reduce((sum, cb) => sum + parseFloat(cb.dataset.amount), 0);
                        
                        totalDue.value = totalAmount.toFixed(2);
                        amountToPay.value = totalAmount.toFixed(2);
                        remainingBalance.value = '0.00';
                    });
                });

                // Handle amount to pay changes
                amountToPay.addEventListener('input', function() {
                    const totalDueAmount = parseFloat(totalDue.value) || 0;
                    const amountToPayValue = parseFloat(this.value) || 0;
                    
                    if (amountToPayValue > totalDueAmount) {
                        this.value = totalDueAmount.toFixed(2);
                        remainingBalance.value = '0.00';
                    } else {
                        remainingBalance.value = (totalDueAmount - amountToPayValue).toFixed(2);
                    }
                });

            } catch (error) {
                console.error('Error:', error);
                billsList.innerHTML = '<div class="text-danger">Error loading bills</div>';
            }
        });
    }

    // Handle form submission
    if (addBillingForm) {
        addBillingForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            console.log('Form submitted'); // Debug log

            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Creating...';

            try {
                const formData = new FormData(this);
                
                // Debug log form data
                for (let [key, value] of formData.entries()) {
                    console.log(`${key}: ${value}`);
                }

                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const contentType = response.headers.get('content-type');
                let result;
                
                if (contentType && contentType.includes('application/json')) {
                    result = await response.json();
                } else {
                    const text = await response.text();
                    console.log('Unexpected response:', text);
                    throw new Error('Server did not return JSON response');
                }

                console.log('Server response:', result); // Debug log

                if (result.success) {
                    // Show success message
                    const toast = document.createElement('div');
                    toast.className = 'alert alert-success position-fixed top-0 end-0 m-3';
                    toast.style.zIndex = '9999';
                    toast.innerHTML = `
                        <i class="fas fa-check-circle me-2"></i>
                        ${result.message}
                    `;
                    document.body.appendChild(toast);

                    // Hide toast after 3 seconds
                    setTimeout(() => {
                        toast.remove();
                    }, 3000);

                    // Close modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('addBillingModal'));
                    if (modal) {
                        modal.hide();
                    }

                    // Refresh the page after a short delay
                    setTimeout(() => {
                        window.location.reload();
                    }, 500);
                } else {
                    throw new Error(result.message || 'Failed to create bill');
                }

            } catch (error) {
                console.error('Error:', error);
                
                // Show error message in the form
                const errorDiv = document.createElement('div');
                errorDiv.className = 'alert alert-danger mt-3';
                errorDiv.innerHTML = `
                    <i class="fas fa-exclamation-circle me-2"></i>
                    ${error.message}
                `;

                // Remove any existing error messages
                const existingError = this.querySelector('.alert-danger');
                if (existingError) {
                    existingError.remove();
                }

                // Add new error message at the top of the form
                this.insertBefore(errorDiv, this.firstChild);

            } finally {
                // Restore button state
                submitBtn.disabled = false;
                submitBtn.innerHTML = 'Create Bill';
            }
        });
    }
});

</script>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Custom JS -->
<script src="assets/js/billing_list.js"></script>
</body>
</html>
