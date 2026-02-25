<?php
ob_start();
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: adminlogin.php");
    exit();
}

include 'db.php';
if (file_exists('comprehensive_fee_manager.php')) {
    include 'comprehensive_fee_manager.php';
}

function appendSystemImportLog($level, $message, $context = []) {
    $logDir = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }
    $logFile = $logDir . DIRECTORY_SEPARATOR . 'system_php_error.log';
    $adminId = $_SESSION['admin_id'] ?? '';
    $contextJson = !empty($context) ? json_encode($context, JSON_UNESCAPED_SLASHES) : '';
    $line = sprintf(
        "[%s] [CSV_IMPORT] [admin:%s] [%s] %s%s",
        date('Y-m-d H:i:s'),
        $adminId,
        strtoupper($level),
        $message,
        $contextJson !== '' ? " | context=" . $contextJson : ""
    );
    error_log($line . PHP_EOL, 3, $logFile);
}

function normalizeCsvNumber($rawValue) {
    if ($rawValue === null) {
        return null;
    }
    $value = trim((string)$rawValue);
    if ($value === '') {
        return null;
    }
    // Accept values like "₱1,234.50", "1,234.50", "1234.50"
    $value = preg_replace('/[^0-9.\-]/', '', $value);
    if ($value === '' || $value === '-' || $value === '.' || $value === '-.') {
        return null;
    }
    return floatval($value);
}

$notification = '';
$notificationClass = '';
$showNotificationModal = false;

// Get total clients count
$total_clients = 0;
$total_query = "SELECT COUNT(*) as total FROM client_list WHERE delete_flag = 0";
$total_result = $conn->query($total_query);
if ($total_result) {
    $row = $total_result->fetch_assoc();
    $total_clients = $row['total'];
}

// Get active clients percentage
$active_clients = 0;
$active_query = "SELECT COUNT(*) as active FROM client_list WHERE status = 1 AND delete_flag = 0";
$active_result = $conn->query($active_query);
if ($active_result) {
    $row = $active_result->fetch_assoc();
    $active_clients = $row['active'];
}
$active_percentage = $total_clients > 0 ? round(($active_clients / $total_clients) * 100) : 0;

// Get pending connections count
$pending_connections = 0;
$pending_query = "SELECT COUNT(*) as pending FROM client_list WHERE status = 0 AND delete_flag = 0";
$pending_result = $conn->query($pending_query);
if ($pending_result) {
    $row = $pending_result->fetch_assoc();
    $pending_connections = $row['pending'];
}

// Calculate monthly growth rate
$last_month = date('Y-m-d', strtotime('first day of last month'));
$this_month = date('Y-m-d', strtotime('first day of this month'));
$growth_query = "SELECT 
    (SELECT COUNT(*) FROM client_list WHERE DATE(date_created) >= '$this_month' AND delete_flag = 0) as this_month,
    (SELECT COUNT(*) FROM client_list WHERE DATE(date_created) >= '$last_month' AND DATE(date_created) < '$this_month' AND delete_flag = 0) as last_month";
$growth_result = $conn->query($growth_query);
$growth_rate = 0;
if ($growth_result) {
    $row = $growth_result->fetch_assoc();
    $last_month_count = $row['last_month'];
    $this_month_count = $row['this_month'];
    if ($last_month_count > 0) {
        $growth_rate = round((($this_month_count - $last_month_count) / $last_month_count) * 100, 1);
    }
}

// Fetch categories for add client modal
$category_sql = "SELECT id, name FROM categories";
$category_result = $conn->query($category_sql);

// Fetch distinct addresses for address dropdown (excluding puroks)
$address_sql = "SELECT DISTINCT address FROM client_list WHERE address IS NOT NULL AND address != '' AND delete_flag = 0 AND address NOT IN ('Purok 1-A', 'Purok 1-B', 'Purok 1-C', 'Purok 2', 'Purok 3', 'Purok 4', 'Purok 5') ORDER BY address ASC";
$address_result = $conn->query($address_sql);

// Define standard puroks
$standard_puroks = ['Purok 1-A', 'Purok 1-B', 'Purok 1-C', 'Purok 2', 'Purok 3', 'Purok 4', 'Purok 5'];

// Check for delete_status query parameter to show notification after redirect
if (isset($_GET['delete_status'])) {
    if ($_GET['delete_status'] === 'success') {
        $notification = 'Client deleted successfully.';
        $notificationClass = 'alert-success';
    } elseif ($_GET['delete_status'] === 'error') {
        $notification = 'Error deleting client: ' . (isset($_GET['message']) ? htmlspecialchars($_GET['message']) : '');
        $notificationClass = 'alert-danger';
    }
}

// Check for update_status query parameter to show notification after redirect
if (isset($_GET['update_status'])) {
    if ($_GET['update_status'] === 'success') {
        $notification = 'Client updated successfully.';
        $notificationClass = 'alert-success';
    } elseif ($_GET['update_status'] === 'error') {
        $notification = 'Error updating client: ' . (isset($_GET['message']) ? htmlspecialchars($_GET['message']) : '');
        $notificationClass = 'alert-danger';
    }
}

// Check for add_status query parameter to show notification after redirect
if (isset($_GET['add_status'])) {
    if ($_GET['add_status'] === 'success') {
        $notification = 'Client added successfully.';
        $notificationClass = 'alert-success';
    } elseif ($_GET['add_status'] === 'error') {
        $notification = 'Error adding client: ' . (isset($_GET['message']) ? htmlspecialchars($_GET['message']) : '');
        $notificationClass = 'alert-danger';
    }
}

// Handle fee status messages
if (isset($_GET['fee_status'])) {
    if ($_GET['fee_status'] === 'success') {
        $notification = isset($_GET['message']) ? htmlspecialchars($_GET['message']) : 'Fee applied successfully.';
        $notificationClass = 'alert-success';
    } elseif ($_GET['fee_status'] === 'error') {
        $notification = 'Error applying fee: ' . (isset($_GET['message']) ? htmlspecialchars($_GET['message']) : '');
        $notificationClass = 'alert-danger';
    }
}

// Handle historical reading status messages
if (isset($_GET['historical_status'])) {
    if ($_GET['historical_status'] === 'success') {
        $notification = isset($_GET['message']) ? htmlspecialchars($_GET['message']) : 'Historical reading added successfully.';
        $notificationClass = 'alert-success';
    } elseif ($_GET['historical_status'] === 'error') {
        $notification = 'Error adding historical reading: ' . (isset($_GET['message']) ? htmlspecialchars($_GET['message']) : '');
        $notificationClass = 'alert-danger';
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['delete_client'])) {
        // Handle client soft deletion
        $client_id = $_POST['delete_client_id'];
        $stmt = $conn->prepare("UPDATE client_list SET delete_flag = 1, status = 0 WHERE id = ?");
        $stmt->bind_param("i", $client_id);
        if ($stmt->execute()) {
            $notification = 'Client deleted successfully.';
            $notificationClass = 'alert-success';
            $stmt->close();
            // Redirect to avoid form resubmission and show updated list
            header("Location: view_clients.php?delete_status=success");
            exit();
        } else {
            $notification = 'Error deleting client: ' . $stmt->error;
            $notificationClass = 'alert-danger';
            $stmt->close();
        }
    } elseif (isset($_POST['bulk_delete_customers'])) {
        // Handle bulk delete of customers
        $selected_ids = $_POST['selected_customers'] ?? [];
        
        if (empty($selected_ids)) {
            $notification = 'No customers selected for deletion.';
            $notificationClass = 'alert-warning';
            header("Location: view_clients.php?delete_status=error&message=" . urlencode($notification));
            exit();
        }
        
        $success_count = 0;
        $error_count = 0;
        $errors = [];
        
        $conn->begin_transaction();
        
        try {
            foreach ($selected_ids as $client_id) {
                $client_id = intval($client_id);
                if ($client_id > 0) {
                    $stmt = $conn->prepare("UPDATE client_list SET delete_flag = 1, status = 0 WHERE id = ?");
                    $stmt->bind_param("i", $client_id);
                    if ($stmt->execute()) {
                        $success_count++;
                    } else {
                        $error_count++;
                        $errors[] = "Failed to delete customer ID {$client_id}: " . $stmt->error;
                    }
                    $stmt->close();
                }
            }
            
            $conn->commit();
            
            if ($success_count > 0) {
                $notification = "Successfully deleted {$success_count} customer(s).";
                if ($error_count > 0) {
                    $notification .= " {$error_count} failed.";
                }
                header("Location: view_clients.php?delete_status=success&message=" . urlencode($notification));
            } else {
                $notification = "Failed to delete customers. " . implode(', ', array_slice($errors, 0, 5));
                header("Location: view_clients.php?delete_status=error&message=" . urlencode($notification));
            }
        } catch (Exception $e) {
            $conn->rollback();
            $notification = "Error during bulk delete: " . $e->getMessage();
            header("Location: view_clients.php?delete_status=error&message=" . urlencode($notification));
        }
        exit();
    } elseif (isset($_POST['update_client'])) {
        // Retrieve values from POST data
        $client_id = $_POST['client_id'];
        $code = $_POST['code'];
        $category_id = $_POST['category_id'];
        $firstname = $_POST['firstname'];
        $middlename = $_POST['middlename'];
        $lastname = $_POST['lastname'];
        $contact = $_POST['contact'];
        $address = $_POST['address'];
        $meter_code = $_POST['meter_code'];

        // Validate meter code (numbers only)
        if (!preg_match('/^[0-9]+$/', $meter_code)) {
            $notification = "Invalid meter code format. Meter code must contain only numbers.";
            $notificationClass = "alert-danger";
            header("Location: view_clients.php?update_status=error&message=" . urlencode($notification));
            exit();
        }

        // Update client record in the database
        $stmt = $conn->prepare("UPDATE client_list SET code = ?, category_id = ?, firstname = ?, middlename = ?, lastname = ?, contact = ?, address = ?, meter_code = ? WHERE id = ?");
        
        // Bind parameters
        $stmt->bind_param("sissssssi", $code, $category_id, $firstname, $middlename, $lastname, $contact, $address, $meter_code, $client_id);

    // Execute the statement
    if ($stmt->execute()) {
        $stmt->close();
        header("Location: view_clients.php?update_status=success");
        exit();
    } else {
        $error_message = $stmt->error;
        $stmt->close();
        header("Location: view_clients.php?update_status=error&message=" . urlencode($error_message));
        exit();
    }
    } elseif (isset($_POST['add_client'])) {
        // Handle add client
        $category_id = $_POST['category_id'];
        $firstname = $_POST['firstname'];
        $middlename = $_POST['middlename'] ?? '';
        $lastname = $_POST['lastname'];
        $contact = $_POST['contact'];
        $address = $_POST['address'];
        $meter_code = $_POST['meter_code'];
        $status = 1;
        $delete_flag = 0;

        // Validate contact number format (11 digits starting with 09)
        if (!preg_match('/^09[0-9]{9}$/', $contact)) {
            $notification = "Invalid contact number format. Must be 11 digits starting with 09 (e.g., 09123456789).";
            $notificationClass = "alert-danger";
            header("Location: view_clients.php?add_status=error&message=" . urlencode($notification));
            exit();
        }

        // Validate meter code (numbers only)
        if (!preg_match('/^[0-9]+$/', $meter_code)) {
            $notification = "Invalid meter code format. Meter code must contain only numbers.";
            $notificationClass = "alert-danger";
            header("Location: view_clients.php?add_status=error&message=" . urlencode($notification));
            exit();
        }

        // Handle new address if selected
        if ($address === '__NEW__' && isset($_POST['address_new'])) {
            $address = trim($_POST['address_new']);
            if (empty($address)) {
                $notification = "Please enter a new address.";
                $notificationClass = "alert-danger";
                header("Location: view_clients.php?add_status=error&message=" . urlencode($notification));
                exit();
            }
        }

        // Generate client code with format YEAR/NNN
        $year_prefix = date('Y') . '/';
        $code = $year_prefix . '001'; // default code

        $last_number = 0;
        $code_sql = "SELECT code FROM client_list WHERE code LIKE '{$year_prefix}%' ORDER BY code DESC LIMIT 1";
        $code_result = $conn->query($code_sql);
        if ($code_result && $row = $code_result->fetch_assoc()) {
            $last_code = $row['code'];
            if (!empty($last_code)) {
                $last_number = (int)substr($last_code, strlen($year_prefix));
            }
            $next_number = $last_number + 1;
            if ($next_number <= 999) {
                $code = $year_prefix . str_pad($next_number, 3, '0', STR_PAD_LEFT);
            } else {
                $notification = "Maximum client code reached for the year {$year_prefix}. Please contact admin.";
                $notificationClass = "alert-danger";
                header("Location: view_clients.php?add_status=error&message=" . urlencode($notification));
                exit();
            }
        }

        // Prepare and execute SQL statement to insert new client
        $stmt = $conn->prepare("INSERT INTO client_list (code, category_id, firstname, middlename, lastname, contact, address, meter_code, status, delete_flag) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sissssssii", $code, $category_id, $firstname, $middlename, $lastname, $contact, $address, $meter_code, $status, $delete_flag);

        if ($stmt->execute()) {
            $notification = "Client added successfully.";
            $notificationClass = "alert-success";
            $stmt->close();
            header("Location: view_clients.php?add_status=success");
            exit();
        } else {
            $notification = "Error adding client: " . $stmt->error;
            $notificationClass = "alert-danger";
            $stmt->close();
            header("Location: view_clients.php?add_status=error&message=" . urlencode($stmt->error));
            exit();
        }
    } elseif (isset($_POST['bulk_import_customers'])) {
        // Handle bulk import of customers from CSV
        appendSystemImportLog('start', 'Bulk CSV import started.', [
            'uploaded_name' => $_FILES['bulk_csv_file']['name'] ?? '',
            'uploaded_size' => $_FILES['bulk_csv_file']['size'] ?? 0
        ]);
        if (!isset($_FILES['bulk_csv_file']) || $_FILES['bulk_csv_file']['error'] !== UPLOAD_ERR_OK) {
            $notification = 'Error uploading CSV file. Please try again.';
            $notificationClass = 'alert-danger';
            appendSystemImportLog('error', 'Upload failed before parsing.', [
                'upload_error_code' => $_FILES['bulk_csv_file']['error'] ?? 'unknown'
            ]);
            header("Location: view_clients.php?add_status=error&message=" . urlencode($notification));
            exit();
        }

        $csv_file = $_FILES['bulk_csv_file']['tmp_name'];
        $errors = [];
        $success_count = 0;
        $billing_count = 0;
        $skip_count = 0;
        $year_prefix = date('Y') . '/';

        // Get the last code number for this year
        $code_sql = "SELECT code FROM client_list WHERE code LIKE '{$year_prefix}%' ORDER BY code DESC LIMIT 1";
        $code_result = $conn->query($code_sql);
        $last_number = 0;
        if ($code_result && $row = $code_result->fetch_assoc()) {
            $last_code = $row['code'];
            if (!empty($last_code)) {
                $last_number = (int)substr($last_code, strlen($year_prefix));
            }
        }

        // Fetch categories for mapping
        $category_map = [];
        $category_query = "SELECT id, name FROM categories";
        $cat_result = $conn->query($category_query);
        if ($cat_result) {
            while ($cat_row = $cat_result->fetch_assoc()) {
                $category_map[strtolower(trim($cat_row['name']))] = $cat_row['id'];
            }
        }

        if (($handle = fopen($csv_file, "r")) !== FALSE) {
            $row_number = 0;
            $conn->begin_transaction();
            
            // Group customers by meter code to handle multiple billing records per customer
            $customers_data = [];
            
            try {
                // First pass: Read all rows and group by meter code
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    $row_number++;
                    
                    // Skip header row
                    if ($row_number === 1) {
                        continue;
                    }

                    // CSV format: 11 columns min (Reading Date, Status); 15 columns full (Consumption, Amount, Paid, Balance)
                    $has_reading_date = count($data) >= 11;
                    $has_extended = count($data) >= 15;
                    
                    $category_name = trim($data[0] ?? '');
                    $firstname = trim($data[1] ?? '');
                    $middlename = trim($data[2] ?? '');
                    $lastname = trim($data[3] ?? '');
                    $contact = trim($data[4] ?? '');
                    $address = trim($data[5] ?? '');
                    $meter_code = trim($data[6] ?? '');
                    
                    if ($has_reading_date) {
                        $reading_date = trim($data[7] ?? '');
                        $previous_reading = normalizeCsvNumber($data[8] ?? null);
                        $current_reading = normalizeCsvNumber($data[9] ?? null);
                        $bill_status_raw = strtolower(trim((string)($data[10] ?? 'pending')));
                        $consumption_csv = $has_extended ? normalizeCsvNumber($data[11] ?? null) : null;
                        $amount_csv = $has_extended ? normalizeCsvNumber($data[12] ?? null) : null;
                        $paid_csv = $has_extended ? normalizeCsvNumber($data[13] ?? null) : null;
                        $balance_csv = $has_extended ? normalizeCsvNumber($data[14] ?? null) : null;
                    } else {
                        $reading_date = date('Y-m-d');
                        $previous_reading = normalizeCsvNumber($data[7] ?? null);
                        $current_reading = normalizeCsvNumber($data[8] ?? null);
                        $bill_status_raw = 'pending';
                        $consumption_csv = null;
                        $amount_csv = null;
                        $paid_csv = null;
                        $balance_csv = null;
                    }
                    
                    // Validate required fields
                    if (empty($category_name) || empty($firstname) || empty($lastname) || empty($contact) || empty($address) || empty($meter_code)) {
                        $errors[] = "Row {$row_number}: Missing required fields.";
                        $skip_count++;
                        continue;
                    }
                    
                    // Validate reading date if provided
                    if ($has_reading_date && !empty($reading_date)) {
                        $date_check = DateTime::createFromFormat('Y-m-d', $reading_date);
                        if (!$date_check || $date_check->format('Y-m-d') !== $reading_date) {
                            $errors[] = "Row {$row_number}: Invalid reading date format. Expected YYYY-MM-DD.";
                            $skip_count++;
                            continue;
                        }
                    }
                    
                    // Normalize bill status
                    if (in_array($bill_status_raw, ['paid', '1', 'true', 'yes', 'fully paid'])) {
                        $bill_status = 'paid';
                    } elseif (in_array($bill_status_raw, ['pending', 'unpaid', '0', 'false', 'no'])) {
                        $bill_status = 'pending';
                    } else {
                        $bill_status = 'pending';
                    }

                    // If status is Paid and Paid is empty, assume full payment equals amount
                    if ($bill_status === 'paid' && $paid_csv === null && $amount_csv !== null) {
                        $paid_csv = $amount_csv;
                    }
                    
                    // Group by meter code
                    if (!isset($customers_data[$meter_code])) {
                        $customers_data[$meter_code] = [
                            'info' => [
                                'category_name' => $category_name,
                                'firstname' => $firstname,
                                'middlename' => $middlename,
                                'lastname' => $lastname,
                                'contact' => $contact,
                                'address' => $address,
                                'meter_code' => $meter_code
                            ],
                            'billing_records' => []
                        ];
                    }
                    
                    // Add billing record if readings are provided
                    if ($current_reading !== null) {
                        $customers_data[$meter_code]['billing_records'][] = [
                            'reading_date' => $reading_date,
                            'previous_reading' => $previous_reading,
                            'current_reading' => $current_reading,
                            'status' => $bill_status,
                            'row_number' => $row_number,
                            'consumption' => $consumption_csv,
                            'amount' => $amount_csv,
                            'paid' => $paid_csv,
                            'balance' => $balance_csv
                        ];
                    }
                }
                fclose($handle);
                
                // Second pass: Process each customer and their billing records
                foreach ($customers_data as $meter_code => $customer_data) {
                    $customer_info = $customer_data['info'];
                    $billing_records = $customer_data['billing_records'];
                    
                    // Normalize contact from CSV:
                    // - allow 9XXXXXXXXX (10 digits) and auto-prefix to 09XXXXXXXXX
                    // - keep 09XXXXXXXXX as-is
                    $normalized_contact = preg_replace('/\D+/', '', $customer_info['contact']);
                    if (preg_match('/^9[0-9]{9}$/', $normalized_contact)) {
                        $normalized_contact = '0' . $normalized_contact;
                    }

                    // Validate final contact number format
                    if (!preg_match('/^09[0-9]{9}$/', $normalized_contact)) {
                        $errors[] = "Meter Code {$meter_code}: Invalid contact number format. Must be 11 digits starting with 09.";
                        $skip_count++;
                        continue;
                    }
                    $customer_info['contact'] = $normalized_contact;

                    // Validate meter code (numbers only)
                    if (!preg_match('/^[0-9]+$/', $meter_code)) {
                        $errors[] = "Meter Code {$meter_code}: Invalid meter code format. Meter code must contain only numbers.";
                        $skip_count++;
                        continue;
                    }

                    // Map category name to ID
                    $category_id = $category_map[strtolower($customer_info['category_name'])] ?? null;
                    if (!$category_id) {
                        $errors[] = "Meter Code {$meter_code}: Category '{$customer_info['category_name']}' not found.";
                        $skip_count++;
                        continue;
                    }

                    // Check if meter code already exists as active client.
                    // If yes, reuse that client and continue importing/updating billing rows.
                    $check_sql = "SELECT id, category_id FROM client_list WHERE meter_code = ? AND delete_flag = 0 LIMIT 1";
                    $check_stmt = $conn->prepare($check_sql);
                    $check_stmt->bind_param("s", $meter_code);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    $client_id = null;
                    $is_existing_client = false;
                    if ($check_result && $check_result->num_rows > 0) {
                        $existing = $check_result->fetch_assoc();
                        $client_id = intval($existing['id']);
                        $existing_category_id = intval($existing['category_id']);
                        if ($existing_category_id > 0) {
                            $category_id = $existing_category_id;
                        }
                        $is_existing_client = true;
                    }
                    $check_stmt->close();

                    if (!$is_existing_client) {
                        // Generate client code
                        $last_number++;
                        if ($last_number > 999) {
                            $errors[] = "Meter Code {$meter_code}: Maximum client code reached for the year.";
                            $skip_count++;
                            continue;
                        }
                        $code = $year_prefix . str_pad($last_number, 3, '0', STR_PAD_LEFT);

                        // Insert client
                        $status = 1;
                        $delete_flag = 0;
                        $stmt = $conn->prepare("INSERT INTO client_list (code, category_id, firstname, middlename, lastname, contact, address, meter_code, status, delete_flag) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("sissssssii", $code, $category_id, $customer_info['firstname'], $customer_info['middlename'], $customer_info['lastname'], $customer_info['contact'], $customer_info['address'], $meter_code, $status, $delete_flag);

                        if ($stmt->execute()) {
                            $client_id = $conn->insert_id;
                            $success_count++;
                        } else {
                            $errors[] = "Meter Code {$meter_code}: " . $stmt->error;
                            $skip_count++;
                            $stmt->close();
                            continue;
                        }
                        $stmt->close();
                    } else {
                        // Existing active meter code reused
                        $success_count++;
                    }
                    
                    // Process billing records chronologically
                    if (!empty($billing_records)) {
                            // Sort billing records by reading date
                            usort($billing_records, function($a, $b) {
                                return strtotime($a['reading_date']) - strtotime($b['reading_date']);
                            });
                            
                            // Get rate for category
                            $rate_stmt = $conn->prepare("SELECT rate, excess_rate FROM category_rates WHERE category_id = ?");
                            $rate_stmt->bind_param("i", $category_id);
                            $rate_stmt->execute();
                            $rate_result = $rate_stmt->get_result();
                            $rate_data = $rate_result->fetch_assoc();
                            $rate_stmt->close();
                            
                            if (!$rate_data) {
                                $errors[] = "Meter Code {$meter_code}: No rate found for category. Billing records not created.";
                                continue;
                            }
                            
                            // Process each billing record
                            foreach ($billing_records as $bill_record) {
                                $reading_date = $bill_record['reading_date'];
                                $prev_reading = $bill_record['previous_reading'] !== null ? $bill_record['previous_reading'] : 0;
                                $current_reading = $bill_record['current_reading'];
                                $bill_status = $bill_record['status'];
                                
                                // Validate readings
                                if ($current_reading < 0) {
                                    $errors[] = "Meter Code {$meter_code}, Row {$bill_record['row_number']}: Invalid current reading value.";
                                    continue;
                                }
                                if ($prev_reading < 0) {
                                    $errors[] = "Meter Code {$meter_code}, Row {$bill_record['row_number']}: Invalid previous reading value.";
                                    continue;
                                }
                                if ($current_reading < $prev_reading) {
                                    $errors[] = "Meter Code {$meter_code}, Row {$bill_record['row_number']}: Current reading cannot be less than previous reading.";
                                    continue;
                                }
                                
                                $is_old_bill = strtotime($reading_date) < strtotime('-30 days');
                                // Calculate consumption (use CSV value if provided and valid, else compute)
                                $consumption = max(0, $current_reading - $prev_reading);
                                if (isset($bill_record['consumption']) && $bill_record['consumption'] !== null && $bill_record['consumption'] >= 0) {
                                    $consumption = floatval($bill_record['consumption']);
                                }
                                
                                // Total: use CSV Amount if provided and > 0, else calculate from rates
                                if (isset($bill_record['amount']) && $bill_record['amount'] !== null && $bill_record['amount'] > 0) {
                                    $base_total = floatval($bill_record['amount']);
                                    $final_total = $base_total;
                                } else {
                                    if ($consumption <= 6) {
                                        $base_total = $rate_data['rate'];
                                    } else {
                                        $excess = $consumption - 6;
                                        $base_total = $rate_data['rate'] + ($excess * $rate_data['excess_rate']);
                                    }
                                    $additional_fees = 0;
                                    $is_old_bill = strtotime($reading_date) < strtotime('-30 days');
                                    if (!$is_old_bill && function_exists('getApplicableFees')) {
                                        $fees_result = getApplicableFees($client_id, $conn, 'regular_bill', $base_total);
                                        $additional_fees = $fees_result['success'] ? $fees_result['total_fees'] : 0;
                                    }
                                    $final_total = $base_total + $additional_fees;
                                }
                                
                                // Determine bill status
                                // Old bills (more than 30 days) are automatically marked as paid
                                // Or if explicitly marked as "paid" in CSV
                                $bill_status_value = 0; // Default to pending
                                if ($is_old_bill || $bill_status === 'paid') {
                                    $bill_status_value = 1; // Mark as paid
                                }
                                
                                // Calculate due date (30 days from reading date)
                                $due_date = date('Y-m-d', strtotime($reading_date . ' +30 days'));
                                
                                // Upsert billing record by (client_id, reading_date) so re-imports update status/amount
                                $billing_id = null;
                                $existing_bill_stmt = $conn->prepare("SELECT id FROM billing_list WHERE client_id = ? AND reading_date = ? LIMIT 1");
                                $existing_bill_stmt->bind_param("is", $client_id, $reading_date);
                                $existing_bill_stmt->execute();
                                $existing_bill_result = $existing_bill_stmt->get_result();
                                $existing_bill = $existing_bill_result ? $existing_bill_result->fetch_assoc() : null;
                                $existing_bill_stmt->close();

                                if ($existing_bill) {
                                    $billing_id = intval($existing_bill['id']);
                                    $update_bill_stmt = $conn->prepare("UPDATE billing_list SET due_date = ?, reading = ?, previous = ?, rate = ?, total = ?, status = ? WHERE id = ?");
                                    $update_bill_stmt->bind_param("sddddii", $due_date, $current_reading, $prev_reading, $base_total, $final_total, $bill_status_value, $billing_id);
                                    if ($update_bill_stmt->execute()) {
                                        $billing_count++;
                                    } else {
                                        $errors[] = "Meter Code {$meter_code}, Row {$bill_record['row_number']}: Failed to update billing record - " . $update_bill_stmt->error;
                                    }
                                    $update_bill_stmt->close();
                                } else {
                                    $bill_stmt = $conn->prepare("INSERT INTO billing_list (client_id, reading_date, due_date, reading, previous, rate, total, status, date_created) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                                    $bill_stmt->bind_param("issddddi", $client_id, $reading_date, $due_date, $current_reading, $prev_reading, $base_total, $final_total, $bill_status_value);
                                    if ($bill_stmt->execute()) {
                                        $billing_count++;
                                        $billing_id = $conn->insert_id;
                                    } else {
                                        $errors[] = "Meter Code {$meter_code}, Row {$bill_record['row_number']}: Failed to create billing record - " . $bill_stmt->error;
                                    }
                                    $bill_stmt->close();
                                }

                                // If CSV has Paid amount, top-up payments to match CSV paid amount.
                                // Cap at bill total to avoid overpayment/negative remaining balance.
                                $paid_amt = isset($bill_record['paid']) && $bill_record['paid'] !== null && $bill_record['paid'] > 0 ? floatval($bill_record['paid']) : 0;
                                if ($billing_id && $paid_amt > 0) {
                                    $paid_sum_stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS total_paid FROM payment_list WHERE billing_id = ? AND status = 1");
                                    $paid_sum_stmt->bind_param("i", $billing_id);
                                    $paid_sum_stmt->execute();
                                    $paid_sum_result = $paid_sum_stmt->get_result();
                                    $paid_sum_row = $paid_sum_result ? $paid_sum_result->fetch_assoc() : ['total_paid' => 0];
                                    $already_paid = floatval($paid_sum_row['total_paid'] ?? 0);
                                    $paid_sum_stmt->close();

                                    $target_paid = min($paid_amt, $final_total);
                                    $remaining_to_record = max(0, $target_paid - $already_paid);
                                    if ($remaining_to_record > 0) {
                                        $pay_stmt = $conn->prepare("INSERT INTO payment_list (client_id, billing_id, payment_date, amount, payment_method, reference_number, status) VALUES (?, ?, ?, ?, 'csv_import', '', 1)");
                                        $pay_stmt->bind_param("iisd", $client_id, $billing_id, $reading_date, $remaining_to_record);
                                        $pay_stmt->execute();
                                        $pay_stmt->close();
                                    }

                                    if ($target_paid >= $final_total) {
                                        $conn->query("UPDATE billing_list SET status = 1 WHERE id = " . intval($billing_id));
                                    }
                                }
                            }
                        }
                }

                $conn->commit();

                if ($success_count > 0) {
                    $notification = "Successfully imported {$success_count} customer(s).";
                    if ($billing_count > 0) {
                        $notification .= " {$billing_count} billing record(s) created.";
                    }
                    if ($skip_count > 0) {
                        $notification .= " {$skip_count} row(s) skipped.";
                    }
                    if (!empty($errors)) {
                        $notification .= " Errors: " . implode(', ', array_slice($errors, 0, 5));
                        if (count($errors) > 5) {
                            $notification .= " and " . (count($errors) - 5) . " more.";
                        }
                    }
                    appendSystemImportLog('success', 'Bulk import completed with at least one customer.', [
                        'success_count' => $success_count,
                        'billing_count' => $billing_count,
                        'skip_count' => $skip_count,
                        'error_count' => count($errors),
                        'sample_errors' => array_slice($errors, 0, 10)
                    ]);
                    header("Location: view_clients.php?add_status=success&message=" . urlencode($notification));
                } else {
                    $notification = "Failed to import any customers. " . implode(', ', array_slice($errors, 0, 10));
                    appendSystemImportLog('error', 'Bulk import failed: no customers imported.', [
                        'success_count' => $success_count,
                        'billing_count' => $billing_count,
                        'skip_count' => $skip_count,
                        'error_count' => count($errors),
                        'sample_errors' => array_slice($errors, 0, 20)
                    ]);
                    header("Location: view_clients.php?add_status=error&message=" . urlencode($notification));
                }
            } catch (Exception $e) {
                $conn->rollback();
                $notification = "Error during import: " . $e->getMessage();
                appendSystemImportLog('exception', 'Bulk import exception.', [
                    'exception' => $e->getMessage(),
                    'success_count' => $success_count,
                    'billing_count' => $billing_count,
                    'skip_count' => $skip_count,
                    'error_count' => count($errors),
                    'sample_errors' => array_slice($errors, 0, 20)
                ]);
                header("Location: view_clients.php?add_status=error&message=" . urlencode($notification));
            }
        } else {
            $notification = "Error reading CSV file.";
            appendSystemImportLog('error', 'Unable to open uploaded CSV file for reading.', [
                'tmp_name' => $csv_file
            ]);
            header("Location: view_clients.php?add_status=error&message=" . urlencode($notification));
        }
        exit();
    } elseif (isset($_POST['add_historical_reading'])) {
        // Handle historical meter reading submission
        $client_id = $_POST['client_id'];
        $billing_cycle_id = $_POST['billing_cycle_id'];
        $reading_date = $_POST['reading_date'];
        $meter_reading = floatval($_POST['meter_reading']);
        $previous_reading = !empty($_POST['previous_reading']) ? floatval($_POST['previous_reading']) : null;
        $admin_notes = $_POST['admin_notes'] ?? '';
        
        // Validate inputs
        if ($meter_reading <= 0) {
            $notification = 'Error: Invalid meter reading value';
            $notificationClass = 'alert-danger';
        } else {
            try {
                // Check if reading already exists for this client and billing cycle
                $check_stmt = $conn->prepare("
                    SELECT id FROM pending_meter_readings 
                    WHERE client_id = ? AND billing_cycle_id = ? AND status != 'failed'
                ");
                $check_stmt->bind_param("ii", $client_id, $billing_cycle_id);
                $check_stmt->execute();
                $existing = $check_stmt->get_result()->fetch_assoc();
                
                if ($existing) {
                    $notification = 'Error: Reading already exists for this billing cycle';
                    $notificationClass = 'alert-danger';
                } else {
                    // If no previous reading provided, get the last reading for this client
                    if ($previous_reading === null) {
                        $prev_stmt = $conn->prepare("
                            SELECT reading FROM billing_list 
                            WHERE client_id = ? 
                            ORDER BY reading_date DESC 
                            LIMIT 1
                        ");
                        $prev_stmt->bind_param("i", $client_id);
                        $prev_stmt->execute();
                        $prev_result = $prev_stmt->get_result();
                        if ($prev_result->num_rows > 0) {
                            $prev_reading = $prev_result->fetch_assoc()['reading'];
                            $previous_reading = $prev_reading;
                        } else {
                            $previous_reading = 0;
                        }
                    }
                    
                    // Create bill for this historical reading
                    $client_stmt = $conn->prepare("
                        SELECT cl.*, cr.rate, cr.excess_rate 
                        FROM client_list cl 
                        LEFT JOIN category_rates cr ON cl.category_id = cr.category_id 
                        WHERE cl.id = ?
                    ");
                    $client_stmt->bind_param("i", $client_id);
                    $client_stmt->execute();
                    $client = $client_stmt->get_result()->fetch_assoc();
                    
                    if ($client) {
                        $consumption = max(0, $meter_reading - $previous_reading);
                        $rate = $client['rate'] ?? 0;
                        $excess_rate = $client['excess_rate'] ?? 0;
                        
                        // Calculate bill amount
                        $base_amount = $consumption * $rate;
                        $excess_amount = 0;
                        if ($consumption > 10 && $excess_rate > 0) {
                            $excess_consumption = $consumption - 10;
                            $excess_amount = $excess_consumption * $excess_rate;
                        }
                        $total_amount = $base_amount + $excess_amount;
                        
                        // Insert bill
                        $due_date = date('Y-m-d', strtotime($reading_date . ' +30 days'));
                        $bill_stmt = $conn->prepare("
                            INSERT INTO billing_list 
                            (client_id, reading_date, due_date, reading, previous, total, status) 
                            VALUES (?, ?, ?, ?, ?, ?, 0)
                        ");
                        $bill_stmt->bind_param("issddd", 
                            $client_id, $reading_date, $due_date, $meter_reading, $previous_reading, $total_amount
                        );
                        
                        if ($bill_stmt->execute()) {
                            $notification = 'Historical reading added successfully and bill created!';
                            $notificationClass = 'alert-success';
                        } else {
                            $notification = 'Historical reading added but failed to create bill: ' . $bill_stmt->error;
                            $notificationClass = 'alert-warning';
                        }
                    } else {
                        $notification = 'Historical reading added but failed to create bill: Client not found';
                        $notificationClass = 'alert-warning';
                    }
                }
            } catch (Exception $e) {
                $notification = 'Error adding historical reading: ' . $e->getMessage();
                $notificationClass = 'alert-danger';
            }
        }
        
        header("Location: view_clients.php?historical_status=" . ($notificationClass === 'alert-success' ? 'success' : 'error') . "&message=" . urlencode($notification));
        exit();
    } elseif (isset($_POST['add_bulk_historical_readings'])) {
        // Handle bulk historical readings
        $client_id = $_POST['client_id'];
        $reading_dates = $_POST['bulk_reading_dates'] ?? [];
        $reading_values = $_POST['bulk_reading_values'] ?? [];
        $cycle_ids = $_POST['bulk_cycle_ids'] ?? [];
        
        $success_count = 0;
        $error_count = 0;
        $errors = [];
        
        for ($i = 0; $i < count($reading_dates); $i++) {
            if (empty($reading_dates[$i]) || empty($reading_values[$i]) || empty($cycle_ids[$i])) {
                continue; // Skip empty rows
            }
            
            try {
                $reading_date = $reading_dates[$i];
                $meter_reading = floatval($reading_values[$i]);
                $billing_cycle_id = intval($cycle_ids[$i]);
                
                // Get previous reading
                $prev_stmt = $conn->prepare("
                    SELECT reading FROM billing_list 
                    WHERE client_id = ? AND reading_date < ? 
                    ORDER BY reading_date DESC 
                    LIMIT 1
                ");
                $prev_stmt->bind_param("is", $client_id, $reading_date);
                $prev_stmt->execute();
                $prev_result = $prev_stmt->get_result();
                $previous_reading = 0;
                if ($prev_result->num_rows > 0) {
                    $previous_reading = $prev_result->fetch_assoc()['reading'];
                }
                
                // Skip pending_meter_readings for bulk historical data - go directly to billing_list
                
                // Create bill
                $client_stmt = $conn->prepare("
                    SELECT cl.*, cr.rate, cr.excess_rate 
                    FROM client_list cl 
                    LEFT JOIN category_rates cr ON cl.category_id = cr.category_id 
                    WHERE cl.id = ?
                ");
                $client_stmt->bind_param("i", $client_id);
                $client_stmt->execute();
                $client = $client_stmt->get_result()->fetch_assoc();
                
                if ($client) {
                    $consumption = max(0, $meter_reading - $previous_reading);
                    $rate = $client['rate'] ?? 0;
                    $excess_rate = $client['excess_rate'] ?? 0;
                    
                    $base_amount = $consumption * $rate;
                    $excess_amount = 0;
                    if ($consumption > 10 && $excess_rate > 0) {
                        $excess_consumption = $consumption - 10;
                        $excess_amount = $excess_consumption * $excess_rate;
                    }
                    $total_amount = $base_amount + $excess_amount;
                    
                    $due_date = date('Y-m-d', strtotime($reading_date . ' +30 days'));
                    $bill_stmt = $conn->prepare("
                        INSERT INTO billing_list 
                        (client_id, reading_date, due_date, reading, previous, total, status) 
                        VALUES (?, ?, ?, ?, ?, ?, 0)
                    ");
                    $bill_stmt->bind_param("issddd", 
                        $client_id, $reading_date, $due_date, $meter_reading, $previous_reading, $total_amount
                    );
                    
                    if ($bill_stmt->execute()) {
                        $success_count++;
                    } else {
                        $error_count++;
                        $errors[] = "Failed to create bill for reading on {$reading_date}";
                    }
                } else {
                    $error_count++;
                    $errors[] = "Client not found for reading on {$reading_date}";
                }
            } catch (Exception $e) {
                $error_count++;
                $errors[] = "Error processing reading on {$reading_dates[$i]}: " . $e->getMessage();
            }
        }
        
        if ($success_count > 0) {
            $notification = "Successfully added {$success_count} historical readings";
            if ($error_count > 0) {
                $notification .= " with {$error_count} errors";
            }
            $notificationClass = $error_count > 0 ? 'alert-warning' : 'alert-success';
        } else {
            $notification = "Failed to add any readings. " . implode(', ', $errors);
            $notificationClass = 'alert-danger';
        }
        
        header("Location: view_clients.php?historical_status=" . ($notificationClass === 'alert-success' ? 'success' : 'error') . "&message=" . urlencode($notification));
        exit();
    } elseif (isset($_POST['import_historical_readings'])) {
        // Handle CSV import
        $client_id = $_POST['client_id'];
        
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $notification = 'Error uploading CSV file';
            $notificationClass = 'alert-danger';
        } else {
            $csv_file = $_FILES['csv_file']['tmp_name'];
            $success_count = 0;
            $error_count = 0;
            $errors = [];
            
            if (($handle = fopen($csv_file, "r")) !== FALSE) {
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    if (count($data) < 3) continue; // Skip invalid rows
                    
                    $reading_date = $data[0];
                    $meter_reading = floatval($data[1]);
                    $billing_cycle_id = intval($data[2]);
                    $admin_notes = isset($data[3]) ? $data[3] : '';
                    
                    if (empty($reading_date) || $meter_reading <= 0 || $billing_cycle_id <= 0) {
                        $error_count++;
                        continue;
                    }
                    
                    try {
                        // Get previous reading
                        $prev_stmt = $conn->prepare("
                            SELECT reading FROM billing_list 
                            WHERE client_id = ? AND reading_date < ? 
                            ORDER BY reading_date DESC 
                            LIMIT 1
                        ");
                        $prev_stmt->bind_param("is", $client_id, $reading_date);
                        $prev_stmt->execute();
                        $prev_result = $prev_stmt->get_result();
                        $previous_reading = 0;
                        if ($prev_result->num_rows > 0) {
                            $previous_reading = $prev_result->fetch_assoc()['reading'];
                        }
                        
                        // Skip pending_meter_readings for CSV import - go directly to billing_list
                        
                        // Create bill
                        $client_stmt = $conn->prepare("
                            SELECT cl.*, cr.rate, cr.excess_rate 
                            FROM client_list cl 
                            LEFT JOIN category_rates cr ON cl.category_id = cr.category_id 
                            WHERE cl.id = ?
                        ");
                        $client_stmt->bind_param("i", $client_id);
                        $client_stmt->execute();
                        $client = $client_stmt->get_result()->fetch_assoc();
                        
                        if ($client) {
                            $consumption = max(0, $meter_reading - $previous_reading);
                            $rate = $client['rate'] ?? 0;
                            $excess_rate = $client['excess_rate'] ?? 0;
                            
                            $base_amount = $consumption * $rate;
                            $excess_amount = 0;
                            if ($consumption > 10 && $excess_rate > 0) {
                                $excess_consumption = $consumption - 10;
                                $excess_amount = $excess_consumption * $excess_rate;
                            }
                            $total_amount = $base_amount + $excess_amount;
                            
                            $due_date = date('Y-m-d', strtotime($reading_date . ' +30 days'));
                            $bill_stmt = $conn->prepare("
                                INSERT INTO billing_list 
                                (client_id, reading_date, due_date, reading, previous, total, status) 
                                VALUES (?, ?, ?, ?, ?, ?, 0)
                            ");
                            $bill_stmt->bind_param("issddd", 
                                $client_id, $reading_date, $due_date, $meter_reading, $previous_reading, $total_amount
                            );
                            
                            if ($bill_stmt->execute()) {
                                $success_count++;
                            } else {
                                $error_count++;
                            }
                        } else {
                            $error_count++;
                        }
                    } catch (Exception $e) {
                        $error_count++;
                        $errors[] = "Error processing row: " . $e->getMessage();
                    }
                }
                fclose($handle);
            }
            
            if ($success_count > 0) {
                $notification = "Successfully imported {$success_count} historical readings";
                if ($error_count > 0) {
                    $notification .= " with {$error_count} errors";
                }
                $notificationClass = $error_count > 0 ? 'alert-warning' : 'alert-success';
            } else {
                $notification = "Failed to import any readings. " . implode(', ', $errors);
                $notificationClass = 'alert-danger';
            }
        }
        
        header("Location: view_clients.php?historical_status=" . ($notificationClass === 'alert-success' ? 'success' : 'error') . "&message=" . urlencode($notification));
        exit();
    }
}

$search = '';
if (isset($_GET['search'])) {
    $search = $conn->real_escape_string($_GET['search']);
    $sql = "SELECT cl.*, cr.rate FROM client_list cl 
            LEFT JOIN category_rates cr ON cl.category_id = cr.category_id 
            WHERE (cl.firstname LIKE '%$search%' OR cl.lastname LIKE '%$search%' OR cl.code LIKE '%$search%')
            AND cl.delete_flag = 0";
} else {
    $sql = "SELECT cl.*, cr.rate FROM client_list cl 
            LEFT JOIN category_rates cr ON cl.category_id = cr.category_id 
            WHERE cl.delete_flag = 0";
}
$result = $conn->query($sql);

?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="icon" href="logo.png" />
    <title>Customers - Water Billing System</title>
    <!-- Bootstrap CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <!-- Google Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" />
    <style>
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
            --text-color-filter: invert(1);
        }

        body {
            font-family: 'Open Sans', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            transition: background-color 0.3s, color 0.3s;
        }

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

        .table {
            color: var(--table-cell-text);
            background-color: var(--table-bg);
        }

        .table thead th {
            background-color: var(--table-header-bg);
            color: var(--table-header-text);
            border-bottom: 2px solid var(--border-color);
            padding: 15px;
        }

        .table td {
            padding: 15px;
            vertical-align: middle;
            border-color: var(--border-color);
            color: var(--text-color) !important;
            background-color: var(--card-bg);
        }

        .table tbody tr:hover {
            background-color: var(--hover-bg);
        }

        .customer-name {
            color: var(--text-color);
            font-weight: 500;
        }

        .avatar-sm {
            background: linear-gradient(45deg, #4e73df 0%, #224abe 100%);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white !important;
            font-weight: 600;
            margin-right: 12px;
        }

        .action-links {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .action-links .btn-group {
            display: inline-flex;
            gap: 0;
            margin: 0;
        }

        .action-links .btn-group .btn {
            margin: 0 !important;
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

        .action-links .btn-outline-info {
            border-color: #0dcaf0;
            color: #0dcaf0;
        }

        .action-links .btn-outline-info:hover {
            background-color: #0dcaf0;
            color: #000;
        }

        .action-links .btn-outline-secondary {
            border-color: #6c757d;
            color: #6c757d;
        }

        .action-links .btn-outline-secondary:hover {
            background-color: #6c757d;
            color: #fff;
        }

        .action-links .btn-outline-warning {
            border-color: #ffc107;
            color: #ffc107;
        }

        .action-links .btn-outline-warning:hover {
            background-color: #ffc107;
            color: #000;
        }

        .action-links .delete-btn {
            border-color: #dc3545 !important;
            color: #dc3545 !important;
        }

        .action-links .delete-btn i {
            color: #dc3545 !important;
        }

        .action-links .delete-btn:hover {
            background-color: #dc3545 !important;
            color: #fff !important;
            border-color: #dc3545 !important;
        }

        .action-links .delete-btn:hover i {
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

        html[data-theme="dark"] .action-links .btn-outline-info,
        [data-theme="dark"] .action-links .btn-outline-info {
            border-color: #0dcaf0;
            color: #0dcaf0;
        }

        html[data-theme="dark"] .action-links .btn-outline-info:hover,
        [data-theme="dark"] .action-links .btn-outline-info:hover {
            background-color: #0dcaf0;
            color: #000;
        }

        html[data-theme="dark"] .action-links .btn-outline-warning,
        [data-theme="dark"] .action-links .btn-outline-warning {
            border-color: #ffc107;
            color: #ffc107;
        }

        html[data-theme="dark"] .action-links .btn-outline-warning:hover,
        [data-theme="dark"] .action-links .btn-outline-warning:hover {
            background-color: #ffc107;
            color: #000;
        }

        /* Dark mode improvements for form help text */
        html[data-theme="dark"] .form-text.text-muted,
        [data-theme="dark"] .form-text.text-muted {
            color: #e0e0e0 !important;
            font-size: 0.875rem;
            font-weight: 400;
        }

        html[data-theme="dark"] .form-text,
        [data-theme="dark"] .form-text {
            color: #e0e0e0 !important;
        }

        html[data-theme="dark"] small.form-text,
        [data-theme="dark"] small.form-text {
            color: #e0e0e0 !important;
        }

        /* CSV Format Example Card - Dark Mode */
        html[data-theme="dark"] .csv-format-card,
        [data-theme="dark"] .csv-format-card {
            background-color: var(--card-bg) !important;
            border: 1px solid var(--border-color) !important;
        }

        html[data-theme="dark"] .csv-format-card h6,
        [data-theme="dark"] .csv-format-card h6 {
            color: var(--text-color) !important;
            font-weight: 600;
        }

        html[data-theme="dark"] .csv-example-table,
        [data-theme="dark"] .csv-example-table {
            color: var(--text-color) !important;
            background-color: var(--card-bg) !important;
        }

        html[data-theme="dark"] .csv-example-table thead th,
        [data-theme="dark"] .csv-example-table thead th {
            background-color: var(--sidebar-bg) !important;
            color: var(--text-color) !important;
            border-color: var(--border-color) !important;
            font-weight: 600;
            white-space: nowrap;
        }

        html[data-theme="dark"] .csv-example-table tbody td,
        [data-theme="dark"] .csv-example-table tbody td {
            background-color: var(--card-bg) !important;
            color: var(--text-color) !important;
            border-color: var(--border-color) !important;
            white-space: nowrap;
        }

        /* CSV table styles for readability */
        .csv-example-table {
            width: 100%;
            table-layout: auto;
            min-width: 900px;
        }

        .csv-example-table th,
        .csv-example-table td {
            white-space: nowrap;
        }

        /* Ensure table is readable in dark mode */
        html[data-theme="dark"] .csv-example-table th,
        [data-theme="dark"] .csv-example-table th {
            font-size: 0.875rem !important;
        }

        html[data-theme="dark"] .csv-example-table td,
        [data-theme="dark"] .csv-example-table td {
            font-size: 0.875rem !important;
        }

        html[data-theme="dark"] .csv-format-note,
        [data-theme="dark"] .csv-format-note {
            color: #e0e0e0 !important;
        }

        html[data-theme="dark"] .csv-format-note strong,
        [data-theme="dark"] .csv-format-note strong {
            color: #ffffff !important;
            font-weight: 600;
        }

        .modal-content {
            background-color: var(--card-bg);
            color: var(--text-color);
            border-radius: 15px;
        }

        .modal-header {
            border-bottom: 1px solid var(--border-color);
            background-color: var(--card-bg);
        }

        .modal-header .btn-close {
            color: var(--text-color);
            filter: var(--text-color-filter);
        }

        .modal-body {
            background-color: var(--card-bg);
        }

        .modal-footer {
            border-top: 1px solid var(--border-color);
            background-color: var(--card-bg);
        }

        .form-control, .form-select {
            background-color: var(--bg-color);
            border: 1px solid var(--border-color);
            color: var(--text-color);
        }

        .form-control:focus, .form-select:focus {
            background-color: var(--bg-color);
            border-color: var(--hover-text);
            color: var(--text-color);
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }

        .form-label {
            color: var(--text-color);
        }

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

        .search-input::placeholder {
            color: var(--muted-text);
            opacity: 0.8;
        }

        .search-input:focus {
            background-color: var(--card-bg);
            border-color: var(--hover-text);
            color: var(--text-color);
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }

        /* Update the form-control class to ensure search input visibility */
        .form-control {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-color);
        }

        .form-control::placeholder {
            color: var(--muted-text);
            opacity: 0.8;
        }

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
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
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

        .stats-row {
            margin-bottom: 30px;
        }

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
        @media (max-width: 767.98px) {
            .card-soft,
            .main-content .row > [class^='col-'],
            .main-content .table-responsive {
                margin-bottom: 0.75rem !important;
            }
            .main-content {
                padding: 8px !important;
            }
            .card-body,
            .card-header {
                padding: 0.75rem !important;
            }
            .mb-4 {
                margin-bottom: 0.75rem !important;
            }
            .table {
                font-size: 0.92rem;
            }
            .table th, .table td {
                padding: 6px 4px !important;
                word-break: break-word;
                vertical-align: middle;
            }
            .table th {
                white-space: nowrap;
            }
            .table td {
                max-width: 90px;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .action-links {
                gap: 6px;
            }

            .action-links a,
            .action-links button {
                padding: 6px 10px !important;
                min-width: 36px;
                height: 34px;
                font-size: 0.85rem;
            }

            .action-links a i,
            .action-links button i {
                font-size: 0.9rem;
            }
            .customer-name {
                font-size: 0.98em;
            }
            .avatar-sm {
                width: 32px;
                height: 32px;
                font-size: 1em;
                margin-right: 7px;
            }
        }
    </style>
</head>
<body>

<!-- Hamburger Sidebar Toggle Button for Mobile -->
<button id="sidebarToggle" aria-label="Toggle sidebar">
  <i class="fas fa-bars"></i>
</button>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <img src="icons/Logo.png" alt="Water Billing Logo" class="img-fluid" style="max-height: 120px;" />
    </div>
    
    <div class="nav-content">
        <a href="adminlandingpage.php">
            <i class="fas fa-chart-line"></i>
            <span>Dashboard</span>
        </a>
        <a href="view_clients.php" class="active">
            <i class="fas fa-users"></i>
            <span>Customers</span>
        </a>
        <a href="billing_list.php">
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
        <!-- Theme Switch -->
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

<!-- Main Content -->
<div class="main-content">
    <!-- Header Section -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">Customer Management</h2>
        <div>
            <button class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#addClientModal">
                <i class="fas fa-plus me-2"></i>Add Customer
            </button>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="row stats-row">
        <div class="col-md-3">
            <div class="card card-soft stat-card" style="background: linear-gradient(45deg, #4e73df 0%, #224abe 100%);">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-white-50">Total Customers</h6>
                        <h3 class="mb-0"><?php echo $total_clients; ?></h3>
                        <small class="text-white-50">Active Accounts</small>
                    </div>
                    <i class="fas fa-users stat-icon"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-soft stat-card" style="background: linear-gradient(45deg, #1cc88a 0%, #13855c 100%);">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-white-50">Active Rate</h6>
                        <h3 class="mb-0"><?php echo $active_percentage; ?>%</h3>
                        <small class="text-white-50">Customer Activity</small>
                    </div>
                    <i class="fas fa-chart-line stat-icon"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-soft stat-card" style="background: linear-gradient(45deg, #f6c23e 0%, #dda20a 100%);">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-white-50">Pending</h6>
                        <h3 class="mb-0"><?php echo $pending_connections; ?></h3>
                        <small class="text-white-50">New Connections</small>
                    </div>
                    <i class="fas fa-clock stat-icon"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-soft stat-card" style="background: linear-gradient(45deg, #36b9cc 0%, #258391 100%);">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-white-50">Monthly Growth</h6>
                        <h3 class="mb-0"><?php echo $growth_rate; ?>%</h3>
                        <small class="text-white-50">New Customers</small>
                    </div>
                    <i class="fas fa-chart-bar stat-icon"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Search and Table Section -->
    <div class="card card-soft">
        <div class="card-body">
            <form method="GET" action="view_clients.php" class="search-form mb-4">
                <input type="text" name="search" class="form-control search-input" placeholder="Search customers..." value="<?php echo htmlspecialchars($search); ?>" />
                <button type="submit" class="btn btn-outline-primary">
                    <i class="fas fa-search me-2"></i>Search
                </button>
            </form>

            <?php if ($result->num_rows > 0): ?>
            <div class="mb-3 d-flex justify-content-between align-items-center">
                <div>
                    <button type="button" class="btn btn-outline-danger" id="bulkDeleteBtn" disabled>
                        <i class="fas fa-trash me-2"></i>Delete Selected (<span id="selectedCount">0</span>)
                    </button>
                </div>
                <div>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="selectAllBtn">
                        <i class="fas fa-check-square me-2"></i>Select All
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="deselectAllBtn" style="display: none;">
                        <i class="fas fa-square me-2"></i>Deselect All
                    </button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th style="width: 40px;">
                                <input type="checkbox" id="selectAllCheckbox" title="Select All">
                            </th>
                            <th>Customer</th>
                            <th>Contact</th>
                            <th>Address</th>
                            <th>Meter Code</th>
                            <th>Rate</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $result->fetch_assoc()): 
                            $json_data = htmlspecialchars(json_encode($row));
                        ?>
                        <tr>
                            <td>
                                <input type="checkbox" class="customer-checkbox" name="selected_customers[]" value="<?php echo $row['id']; ?>" data-customer-name="<?php echo htmlspecialchars($row['firstname'] . ' ' . $row['lastname']); ?>">
                            </td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="customer-name">
                                        <?php echo htmlspecialchars($row['firstname'] . ' ' . $row['lastname']); ?>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($row['contact']); ?></td>
                            <td><?php echo htmlspecialchars($row['address']); ?></td>
                            <td><?php echo htmlspecialchars($row['meter_code']); ?></td>
                            <td><?php echo htmlspecialchars($row['rate']); ?></td>
                            <td class="action-links">
                                <a href="#" class="btn btn-sm btn-outline-primary edit-btn" data-client='<?php echo $json_data; ?>' data-bs-toggle="modal" data-bs-target="#editClientModal" title="Edit Client">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="view_client.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-info" title="View Details">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <div class="btn-group" role="group">
                                <a href="#" class="btn btn-sm btn-outline-warning historical-reading-btn" 
                                   data-client-id="<?php echo $row['id']; ?>" 
                                   data-client-name="<?php echo htmlspecialchars($row['firstname'] . ' ' . $row['lastname']); ?>"
                                   data-meter-code="<?php echo htmlspecialchars($row['meter_code']); ?>"
                                   data-bs-toggle="modal" data-bs-target="#historicalReadingModal" title="Add Historical Reading">
                                    <i class="fas fa-history"></i>
                                </a>
                                <a href="#" class="btn btn-sm btn-outline-danger delete-btn" data-client-id="<?php echo $row['id']; ?>" title="Delete Client">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <p class="text-center text-muted">No customers found</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Client Modal -->
<div class="modal fade" id="addClientModal" tabindex="-1" aria-labelledby="addClientModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="addClientModalLabel">Add Customer</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <!-- Tabs Navigation -->
        <ul class="nav nav-tabs mb-3" id="addClientTabs" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link active" id="single-tab" data-bs-toggle="tab" data-bs-target="#single" type="button" role="tab" aria-controls="single" aria-selected="true">
              <i class="fas fa-user me-2"></i>Single Customer
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="bulk-tab" data-bs-toggle="tab" data-bs-target="#bulk-import" type="button" role="tab" aria-controls="bulk-import" aria-selected="false">
              <i class="fas fa-users me-2"></i>Bulk Import
            </button>
          </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content" id="addClientTabContent">
          <!-- Single Customer Tab -->
          <div class="tab-pane fade show active" id="single" role="tabpanel" aria-labelledby="single-tab">
            <form method="POST" id="addClientForm" action="view_clients.php">
              <div class="row">
                <div class="col-md-6 mb-3">
                  <label for="add_category_id" class="form-label">Category <span class="text-danger">*</span></label>
                  <select id="add_category_id" name="category_id" class="form-select" required>
                    <option value="">Select Category</option>
                    <?php
                    if ($category_result->num_rows > 0) {
                        $category_result->data_seek(0); // Reset pointer
                        while ($row = $category_result->fetch_assoc()) {
                            echo "<option value='{$row['id']}'>{$row['name']}</option>";
                        }
                    }
                    ?>
                  </select>
                </div>
                <div class="col-md-6 mb-3">
                  <label for="add_meter_code" class="form-label">Meter Code <span class="text-danger">*</span></label>
                  <input type="text" id="add_meter_code" name="meter_code" class="form-control" placeholder="Enter meter code (numbers only)" pattern="[0-9]+" required />
                  <div class="invalid-feedback" id="meterCodeError"></div>
                  <small class="text-muted d-block">Last meter code used: <span id="lastMeterCodeDisplay">Loading…</span></small>
                  <small class="form-text text-muted">Automatically filled with the next available number, but you can adjust if needed.</small>
                </div>
              </div>
              <div class="row">
                <div class="col-md-4 mb-3">
                  <label for="add_firstname" class="form-label">Firstname <span class="text-danger">*</span></label>
                  <input type="text" id="add_firstname" name="firstname" class="form-control" placeholder="Enter firstname" required />
                </div>
                <div class="col-md-4 mb-3">
                  <label for="add_middlename" class="form-label">Middlename</label>
                  <input type="text" id="add_middlename" name="middlename" class="form-control" placeholder="Enter middlename (optional)" />
                </div>
                <div class="col-md-4 mb-3">
                  <label for="add_lastname" class="form-label">Lastname <span class="text-danger">*</span></label>
                  <input type="text" id="add_lastname" name="lastname" class="form-control" placeholder="Enter lastname" required />
                </div>
              </div>
              <div class="row">
                <div class="col-md-6 mb-3">
                  <label for="add_contact" class="form-label">Contact Number <span class="text-danger">*</span></label>
                  <input type="tel" id="add_contact" name="contact" class="form-control" placeholder="09123456789" pattern="^09[0-9]{9}$" maxlength="11" required />
                  <small class="form-text text-muted">Format: 09XXXXXXXXX (11 digits, numbers only)</small>
                </div>
                <div class="col-md-6 mb-3">
                  <label for="add_address" class="form-label">Address <span class="text-danger">*</span></label>
                  <select id="add_address" name="address" class="form-select" required>
                    <option value="">Select Address</option>
                    <optgroup label="Standard Puroks">
                      <?php
                      foreach ($standard_puroks as $purok) {
                          echo "<option value='{$purok}'>{$purok}</option>";
                      }
                      ?>
                    </optgroup>
                    <?php
                    if ($address_result && $address_result->num_rows > 0) {
                        echo "<optgroup label='Custom Addresses'>";
                        while ($addr_row = $address_result->fetch_assoc()) {
                            $address = htmlspecialchars($addr_row['address']);
                            echo "<option value='{$address}'>{$address}</option>";
                        }
                        echo "</optgroup>";
                    }
                    ?>
                    <option value="__NEW__">+ Add New Address</option>
                  </select>
                  <input type="text" id="add_address_new" name="address_new" class="form-control mt-2" placeholder="Enter new address (e.g., Purok 1-A, Custom Street Name)" style="display: none;" />
                  <small class="form-text text-muted">Select from standard puroks, custom addresses, or add a new one</small>
                </div>
              </div>
            </form>
          </div>

          <!-- Bulk Import Tab -->
          <div class="tab-pane fade" id="bulk-import" role="tabpanel" aria-labelledby="bulk-tab">
            <form method="POST" id="bulkImportForm" action="view_clients.php" enctype="multipart/form-data">
              <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                <strong>Bulk Import Instructions:</strong>
                <ul class="mb-0 mt-2">
                  <li>Upload a CSV file with customer data</li>
                  <li><strong>Required columns:</strong> Category, Firstname, Lastname, Contact, Address, Meter Code</li>
                  <li><strong>Optional columns:</strong> Middlename, Reading Date, Previous Reading, Current Reading, Status, Consumption, Amount, Paid, Balance</li>
                  <li><strong>Contact:</strong> Accepts <code>09XXXXXXXXX</code> or <code>9XXXXXXXXX</code>. If CSV starts with <code>9</code>, it is auto-saved as <code>09XXXXXXXXX</code>.</li>
                  <li><strong>Address:</strong> Use standard puroks (Purok 1-A, Purok 1-B, Purok 1-C, Purok 2, Purok 3, Purok 4, Purok 5) or custom addresses</li>
                  <li><strong>Meter Readings:</strong> If provided, Previous Reading and Current Reading create billing records. Use Amount for bill total, Paid for payment amount; Balance is informational.</li>
                  <li>Download the template below for the correct column order and format</li>
                </ul>
              </div>

              <div class="mb-3">
                <label for="bulk_csv_file" class="form-label">Upload CSV File <span class="text-danger">*</span></label>
                <input type="file" id="bulk_csv_file" name="bulk_csv_file" class="form-control" accept=".csv" required />
                <small class="form-text text-muted">Only CSV files are supported. Maximum file size: 5MB</small>
              </div>

              <div class="mb-3">
                <a href="customer_import_template.csv" class="btn btn-outline-secondary btn-sm" download id="downloadTemplateBtn">
                  <i class="fas fa-download me-2"></i>Download CSV Template
                </a>
                <a href="system_log_sheet.php" class="btn btn-outline-danger btn-sm ms-2" target="_blank" rel="noopener">
                  <i class="fas fa-clipboard-list me-2"></i>Open System Log Sheet
                </a>
              </div>

              <div class="card bg-light p-3 mb-3 csv-format-card">
                <h6 class="mb-3" style="font-size: 1rem; font-weight: 600;">CSV Format Example (columns in order):</h6>
                <div class="table-responsive" style="max-height: 220px; overflow-y: auto;">
                  <table class="table table-sm table-bordered mb-3 csv-example-table" style="font-size: 0.8rem; margin-bottom: 1rem !important;">
                    <thead>
                      <tr>
                        <th>Category</th>
                        <th>Firstname</th>
                        <th>Middlename</th>
                        <th>Lastname</th>
                        <th>Contact</th>
                        <th>Address</th>
                        <th>Meter Code</th>
                        <th>Reading Date</th>
                        <th>Previous Reading</th>
                        <th>Current Reading</th>
                        <th>Status</th>
                        <th>Consumption</th>
                        <th>Amount</th>
                        <th>Paid</th>
                        <th>Balance</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr>
                        <td>Residential</td>
                        <td>Juan</td>
                        <td>Dela</td>
                        <td>Cruz</td>
                        <td>09123456789</td>
                        <td>Purok 1-A</td>
                        <td>1001</td>
                        <td>2024-01-15</td>
                        <td>0</td>
                        <td>50.5</td>
                        <td>Paid</td>
                        <td>50.5</td>
                        <td>100</td>
                        <td>100</td>
                        <td>0</td>
                      </tr>
                      <tr>
                        <td>Residential</td>
                        <td>Juan</td>
                        <td>Dela</td>
                        <td>Cruz</td>
                        <td>09123456789</td>
                        <td>Purok 1-A</td>
                        <td>1001</td>
                        <td>2024-02-15</td>
                        <td>50.5</td>
                        <td>125.5</td>
                        <td>Paid</td>
                        <td>75</td>
                        <td>198</td>
                        <td>198</td>
                        <td>0</td>
                      </tr>
                      <tr>
                        <td>Residential</td>
                        <td>Juan</td>
                        <td>Dela</td>
                        <td>Cruz</td>
                        <td>09123456789</td>
                        <td>Purok 1-A</td>
                        <td>1001</td>
                        <td>2024-03-15</td>
                        <td>125.5</td>
                        <td>200.25</td>
                        <td>Pending</td>
                        <td>74.75</td>
                        <td>249.50</td>
                        <td>0</td>
                        <td>249.50</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <small class="text-muted csv-format-note" style="font-size: 0.875rem; line-height: 1.5;">
                  <strong>Note:</strong> Multiple rows with the same Meter Code create multiple billing records (processed by Reading Date). Amount = bill total (used when provided); Paid = payment to record; Balance is informational. Old bills (30+ days) can be marked Paid automatically.
                </small>
              </div>

            </form>
          </div>
        </div>
        
        <!-- Modal Footer (shared for both tabs) -->
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" form="addClientForm" name="add_client" class="btn btn-primary" id="addClientSubmitBtn">
            <i class="fas fa-plus me-2"></i>Add Customer
          </button>
          <button type="submit" form="bulkImportForm" name="bulk_import_customers" class="btn btn-success" id="bulkImportSubmitBtn" style="display: none;">
            <i class="fas fa-upload me-2"></i>Import Customers
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Edit Client Modal -->
<div class="modal fade" id="editClientModal" tabindex="-1" aria-labelledby="editClientModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="editClientForm" action="view_clients.php">
                <div class="modal-header">
                    <h5 class="modal-title" id="editClientModalLabel">Edit Client</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="client_id" name="client_id" />
                    <input type="hidden" id="code" name="code" />
                    <div class="mb-3">
                        <label for="category_id" class="form-label">Category</label>
                        <select id="category_id" name="category_id" class="form-select" required>
                            <?php
                            mysqli_data_seek($category_result, 0);
                            if ($category_result->num_rows > 0) {
                                while ($row = $category_result->fetch_assoc()) {
                                    echo "<option value='{$row['id']}'>{$row['name']}</option>";
                                }
                            } else {
                                echo "<option value=''>No categories available</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="firstname" class="form-label">Firstname</label>
                        <input type="text" id="firstname" name="firstname" class="form-control" required />
                    </div>
                    <div class="mb-3">
                        <label for="middlename" class="form-label">Middlename</label>
                        <input type="text" id="middlename" name="middlename" class="form-control" />
                    </div>
                    <div class="mb-3">
                        <label for="lastname" class="form-label">Lastname</label>
                        <input type="text" id="lastname" name="lastname" class="form-control" required />
                    </div>
                    <div class="mb-3">
                        <label for="contact" class="form-label">Contact</label>
                        <input type="text" id="contact" name="contact" class="form-control" required />
                    </div>
                    <div class="mb-3">
                        <label for="address" class="form-label">Address</label>
                        <input type="text" id="address" name="address" class="form-control" required />
                    </div>
                    <div class="mb-3">
                        <label for="meter_code" class="form-label">Meter Code</label>
                        <input type="text" id="meter_code" name="meter_code" class="form-control" pattern="[0-9]+" required />
                        <small class="form-text text-muted">Only numbers are allowed</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" name="update_client" class="btn btn-primary">Update Client</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title" id="deleteConfirmModalLabel">
          <i class="fas fa-exclamation-triangle me-2"></i>Confirm Delete Customer
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-warning">
          <i class="fas fa-warning me-2"></i>
          <strong>Warning:</strong> This action will permanently delete this customer and all associated data.
        </div>
        <p class="mb-2"><strong>This will delete:</strong></p>
        <ul class="mb-3">
          <li>Customer information</li>
          <li>All billing records</li>
          <li>All payment records</li>
          <li>All meter reading records</li>
        </ul>
        <p class="text-danger mb-0"><strong>This action cannot be undone!</strong></p>
        <p class="mt-3 mb-0" id="deleteClientInfo"></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
          <i class="fas fa-trash me-2"></i>Delete Customer
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Toast Notification -->
<div class="position-fixed top-0 end-0 p-3" style="z-index: 1100">
  <div id="deleteToast" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body">
        Client deleted successfully.
      </div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  </div>
</div>

<!-- View Customer Modal -->
<div class="modal fade" id="viewClientModal" tabindex="-1" aria-labelledby="viewClientModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewClientModalLabel">Customer Information</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-12 mb-4 text-center">
                        <div class="avatar-lg bg-primary rounded-circle text-white d-inline-flex align-items-center justify-content-center mb-3" style="width: 100px; height: 100px; font-size: 2.5rem;">
                            <span id="viewClientInitials"></span>
                        </div>
                        <h4 id="viewClientFullName" class="mb-1"></h4>
                        <p class="text-muted mb-0" id="viewClientCode"></p>
                    </div>
                </div>
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="card card-soft h-100">
                            <div class="card-body">
                                <h6 class="card-subtitle mb-3">Personal Information</h6>
                                <div class="mb-2">
                                    <label class="fw-bold mb-1">First Name</label>
                                    <p id="viewFirstName" class="mb-2"></p>
                                </div>
                                <div class="mb-2">
                                    <label class="fw-bold mb-1">Middle Name</label>
                                    <p id="viewMiddleName" class="mb-2"></p>
                                </div>
                                <div class="mb-2">
                                    <label class="fw-bold mb-1">Last Name</label>
                                    <p id="viewLastName" class="mb-2"></p>
                                </div>
                                <div class="mb-2">
                                    <label class="fw-bold mb-1">Contact Number</label>
                                    <p id="viewContact" class="mb-2"></p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card card-soft h-100">
                            <div class="card-body">
                                <h6 class="card-subtitle mb-3">Billing Information</h6>
                                <div class="mb-2">
                                    <label class="fw-bold mb-1">Category</label>
                                    <p id="viewCategory" class="mb-2"></p>
                                </div>
                                <div class="mb-2">
                                    <label class="fw-bold mb-1">Meter Code</label>
                                    <p id="viewMeterCode" class="mb-2"></p>
                                </div>
                                <div class="mb-2">
                                    <label class="fw-bold mb-1">Rate</label>
                                    <p id="viewRate" class="mb-2"></p>
                                </div>
                                <div class="mb-2">
                                    <label class="fw-bold mb-1">Address</label>
                                    <p id="viewAddress" class="mb-2"></p>
                                </div>
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

<!-- Historical Reading Modal -->
<div class="modal fade" id="historicalReadingModal" tabindex="-1" aria-labelledby="historicalReadingModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="historicalReadingModalLabel">Historical Meter Readings</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Navigation Tabs -->
                <ul class="nav nav-tabs" id="historicalTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="view-tab" data-bs-toggle="tab" data-bs-target="#view" type="button" role="tab">
                            <i class="fas fa-eye me-2"></i>View All Readings
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="single-tab" data-bs-toggle="tab" data-bs-target="#single" type="button" role="tab">
                            <i class="fas fa-plus-circle me-2"></i>Single Reading
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="bulk-tab" data-bs-toggle="tab" data-bs-target="#bulk" type="button" role="tab">
                            <i class="fas fa-list me-2"></i>Multiple Readings
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="import-tab" data-bs-toggle="tab" data-bs-target="#import" type="button" role="tab">
                            <i class="fas fa-file-import me-2"></i>Bulk Import
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="historicalTabContent">
                    <!-- View All Readings Tab -->
                    <div class="tab-pane fade show active" id="view" role="tabpanel">
                        <div class="alert alert-info mt-3">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Customer:</strong> <span id="view_client_name"></span><br>
                            <strong>Meter Code:</strong> <span id="view_meter_code"></span>
                        </div>
                        
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <h6 class="mb-0">Historical Meter Readings</h6>
                                <div class="d-flex gap-2">
                                    <div class="input-group" style="width: 250px;">
                                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                                        <input type="text" id="searchReadings" class="form-control" placeholder="Search..." onkeyup="filterReadings()">
                                    </div>
                                    <button class="btn btn-outline-primary btn-sm" onclick="refreshReadings()">
                                        <i class="fas fa-sync-alt"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div id="readings-container">
                            <div class="text-center py-4">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <p class="mt-2 text-muted">Loading historical readings...</p>
                            </div>
                        </div>
                        
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>

                    <!-- Delete Confirmation Modal -->
                    <div class="modal fade" id="deleteReadingModal" tabindex="-1" aria-labelledby="deleteReadingModalLabel" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header bg-danger text-white">
                                    <h5 class="modal-title" id="deleteReadingModalLabel">
                                        <i class="fas fa-exclamation-triangle me-2"></i>Confirm Deletion
                                    </h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="alert alert-warning">
                                        <i class="fas fa-warning me-2"></i>
                                        <strong>Warning:</strong> This action will permanently delete the historical meter reading and any related payment records. This cannot be undone!
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="adminPassword" class="form-label">
                                            <i class="fas fa-lock me-2"></i>Enter Admin Password to Confirm
                                        </label>
                                        <input type="password" id="adminPassword" class="form-control" placeholder="Enter your admin password" autocomplete="current-password">
                                        <small class="form-text text-muted">This action requires administrator authentication.</small>
                                        <div id="passwordStatus" class="mt-1"></div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="confirmDelete">
                                            <label class="form-check-label" for="confirmDelete">
                                                I understand this action cannot be undone
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn" onclick="handleDeleteClick()">
                                        <i class="fas fa-trash me-2"></i>Delete Reading
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Single Reading Tab -->
                    <div class="tab-pane fade" id="single" role="tabpanel">
                        <form method="POST" id="singleReadingForm" action="view_clients.php">
                            <input type="hidden" id="historical_client_id" name="client_id" />
                            
                            <div class="alert alert-info mt-3">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Customer:</strong> <span id="historical_client_name"></span><br>
                                <strong>Meter Code:</strong> <span id="historical_meter_code"></span>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="billing_cycle_id" class="form-label">Billing Cycle</label>
                                        <select id="billing_cycle_id" name="billing_cycle_id" class="form-select" required>
                                            <option value="">Select Billing Cycle</option>
                                            <?php
                                            // Fetch all billing cycles
                                            $cycles_sql = "SELECT id, cycle_name, start_date, end_date FROM billing_cycles ORDER BY start_date DESC";
                                            $cycles_result = $conn->query($cycles_sql);
                                            if ($cycles_result && $cycles_result->num_rows > 0) {
                                                while ($cycle = $cycles_result->fetch_assoc()) {
                                                    echo "<option value='{$cycle['id']}'>{$cycle['cycle_name']} ({$cycle['start_date']} to {$cycle['end_date']})</option>";
                                                }
                                            }
                                            ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="reading_date" class="form-label">Reading Date</label>
                                        <input type="date" id="reading_date" name="reading_date" class="form-control" required />
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="meter_reading" class="form-label">Meter Reading</label>
                                        <input type="number" id="meter_reading" name="meter_reading" class="form-control" 
                                               step="0.01" min="0" placeholder="Enter meter reading" required />
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="previous_reading" class="form-label">Previous Reading (Optional)</label>
                                        <input type="number" id="previous_reading" name="previous_reading" class="form-control" 
                                               step="0.01" min="0" placeholder="Enter previous reading" />
                                        <small class="form-text text-muted">Leave blank to auto-calculate from last reading</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="admin_notes" class="form-label">Admin Notes (Optional)</label>
                                <textarea id="admin_notes" name="admin_notes" class="form-control" rows="3" 
                                          placeholder="Add any notes about this historical reading..."></textarea>
                            </div>
                            
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Note:</strong> This will create a historical meter reading entry. Make sure the reading date and billing cycle are correct as this will affect billing calculations.
                            </div>
                            
                            <div class="modal-footer">
                                <button type="submit" name="add_historical_reading" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>Add Historical Reading
                                </button>
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            </div>
                        </form>
                    </div>

                    <!-- Multiple Readings Tab -->
                    <div class="tab-pane fade" id="bulk" role="tabpanel">
                        <form method="POST" id="bulkReadingForm" action="view_clients.php">
                            <input type="hidden" id="bulk_client_id" name="client_id" />
                            
                            <div class="alert alert-info mt-3">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Customer:</strong> <span id="bulk_client_name"></span><br>
                                <strong>Meter Code:</strong> <span id="bulk_meter_code"></span>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="bulk_start_date" class="form-label">Start Date</label>
                                    <input type="date" id="bulk_start_date" name="bulk_start_date" class="form-control" />
                                </div>
                                <div class="col-md-6">
                                    <label for="bulk_end_date" class="form-label">End Date</label>
                                    <input type="date" id="bulk_end_date" name="bulk_end_date" class="form-control" />
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Historical Readings</label>
                                <div id="bulk-readings-container">
                                    <div class="bulk-reading-row row mb-2">
                                        <div class="col-md-3">
                                            <input type="date" name="bulk_reading_dates[]" class="form-control" placeholder="Date" />
                                        </div>
                                        <div class="col-md-3">
                                            <input type="number" name="bulk_reading_values[]" class="form-control" step="0.01" placeholder="Reading" />
                                        </div>
                                        <div class="col-md-3">
                                            <select name="bulk_cycle_ids[]" class="form-select">
                                                <option value="">Select Cycle</option>
                                                <?php
                                                mysqli_data_seek($cycles_result, 0);
                                                if ($cycles_result && $cycles_result->num_rows > 0) {
                                                    while ($cycle = $cycles_result->fetch_assoc()) {
                                                        echo "<option value='{$cycle['id']}'>{$cycle['cycle_name']}</option>";
                                                    }
                                                }
                                                ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <button type="button" class="btn btn-outline-danger btn-sm remove-reading-row">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-outline-primary btn-sm" id="add-reading-row">
                                    <i class="fas fa-plus me-2"></i>Add Reading
                                </button>
                            </div>
                            
                            <div class="alert alert-info">
                                <i class="fas fa-lightbulb me-2"></i>
                                <strong>Tip:</strong> You can add multiple readings at once. The system will automatically calculate consumption and create bills for each reading.
                            </div>
                            
                            <div class="modal-footer">
                                <button type="submit" name="add_bulk_historical_readings" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>Add All Readings
                                </button>
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            </div>
                        </form>
                    </div>

                    <!-- Bulk Import Tab -->
                    <div class="tab-pane fade" id="import" role="tabpanel">
                        <form method="POST" id="importForm" action="view_clients.php" enctype="multipart/form-data">
                            <input type="hidden" id="import_client_id" name="client_id" />
                            
                            <div class="alert alert-info mt-3">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Customer:</strong> <span id="import_client_name"></span><br>
                                <strong>Meter Code:</strong> <span id="import_meter_code"></span>
                            </div>
                            
                            <div class="mb-3">
                                <label for="csv_file" class="form-label">Upload CSV File</label>
                                <input type="file" id="csv_file" name="csv_file" class="form-control" accept=".csv" />
                                <small class="form-text text-muted">CSV format: Date, Reading, Billing Cycle ID, Notes (optional)</small>
                                <div class="mt-2">
                                    <a href="historical_readings_template.csv" class="btn btn-outline-secondary btn-sm" download>
                                        <i class="fas fa-download me-2"></i>Download CSV Template
                                    </a>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">CSV Format Example:</label>
                                <div class="bg-light p-3 rounded">
                                    <code>
                                        2024-01-15,1250.50,1,January reading<br>
                                        2024-02-15,1280.75,2,February reading<br>
                                        2024-03-15,1310.25,3,March reading
                                    </code>
                                </div>
                            </div>
                            
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Important:</strong> Make sure your CSV file has the correct format. Invalid data will be skipped.
                            </div>
                            
                            <div class="modal-footer">
                                <button type="submit" name="import_historical_readings" class="btn btn-primary">
                                    <i class="fas fa-upload me-2"></i>Import Readings
                                </button>
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
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

    // Address dropdown handler - show/hide new address input
    const addressSelect = document.getElementById('add_address');
    const addressNewInput = document.getElementById('add_address_new');
    
    if (addressSelect && addressNewInput) {
        addressSelect.addEventListener('change', function() {
            if (this.value === '__NEW__') {
                addressNewInput.style.display = 'block';
                addressNewInput.required = true;
                addressNewInput.value = '';
            } else {
                addressNewInput.style.display = 'none';
                addressNewInput.required = false;
                addressNewInput.value = '';
            }
        });
    }

    // Contact number validation - only allow numbers
    const contactInput = document.getElementById('add_contact');
    if (contactInput) {
        // Only allow numbers
        contactInput.addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
        });

        // Validate format on blur
        contactInput.addEventListener('blur', function() {
            const value = this.value.trim();
            if (value && !/^09[0-9]{9}$/.test(value)) {
                this.setCustomValidity('Contact number must be 11 digits starting with 09 (e.g., 09123456789)');
                this.classList.add('is-invalid');
            } else {
                this.setCustomValidity('');
                this.classList.remove('is-invalid');
            }
        });

        // Remove invalid class on input
        contactInput.addEventListener('input', function() {
            if (this.classList.contains('is-invalid')) {
                const value = this.value.trim();
                if (/^09[0-9]{9}$/.test(value)) {
                    this.setCustomValidity('');
                    this.classList.remove('is-invalid');
                }
            }
        });
    }

    // Meter code validation function
    function validateMeterCode(code) {
        if (!code || code.trim() === '') {
            meterCodeInput.classList.remove('is-invalid', 'is-valid');
            meterCodeError.textContent = '';
            return;
        }
        
        // Check if meter code already exists
        fetch(`check_meter_code.php?meter_code=${encodeURIComponent(code)}`)
            .then(response => response.json())
            .then(data => {
                if (data.exists) {
                    meterCodeInput.classList.add('is-invalid');
                    meterCodeInput.classList.remove('is-valid');
                    meterCodeError.textContent = 'This meter code already exists.';
                } else {
                    meterCodeInput.classList.add('is-valid');
                    meterCodeInput.classList.remove('is-invalid');
                    meterCodeError.textContent = '';
                }
            })
            .catch(error => {
                console.error('Error validating meter code:', error);
            });
    }
    
    // Meter code validation - only allow numbers
    const meterCodeInput = document.getElementById('add_meter_code');
    if (meterCodeInput) {
        meterCodeInput.addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
        });
        
        // Validate on blur
        meterCodeInput.addEventListener('blur', function() {
            if (this.value.trim()) {
                validateMeterCode(this.value.trim());
            }
        });
    }

    // Edit form meter code validation
    const editMeterCodeInput = document.getElementById('meter_code');
    if (editMeterCodeInput) {
        editMeterCodeInput.addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
        });
    }

    // Form submission handler for address
    const addClientForm = document.getElementById('addClientForm');
    if (addClientForm) {
        addClientForm.addEventListener('submit', function(e) {
            const addressSelect = document.getElementById('add_address');
            const addressNewInput = document.getElementById('add_address_new');
            
            if (addressSelect && addressSelect.value === '__NEW__') {
                if (!addressNewInput.value.trim()) {
                    e.preventDefault();
                    addressNewInput.focus();
                    showWarning('Please enter a new address');
                    return false;
                }
                // Replace the select value with the new address
                addressSelect.value = addressNewInput.value.trim();
            }
        });
    }

    // Bulk Delete Functionality
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    const customerCheckboxes = document.querySelectorAll('.customer-checkbox');
    const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
    const selectedCountSpan = document.getElementById('selectedCount');
    const selectAllBtn = document.getElementById('selectAllBtn');
    const deselectAllBtn = document.getElementById('deselectAllBtn');

    function updateBulkDeleteButton() {
        const selected = document.querySelectorAll('.customer-checkbox:checked').length;
        if (selectedCountSpan) {
            selectedCountSpan.textContent = selected;
        }
        if (bulkDeleteBtn) {
            bulkDeleteBtn.disabled = selected === 0;
        }
    }

    // Select all checkbox
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            customerCheckboxes.forEach(function(checkbox) {
                checkbox.checked = this.checked;
            }.bind(this));
            updateBulkDeleteButton();
            if (selectAllBtn) selectAllBtn.style.display = this.checked ? 'none' : 'inline-block';
            if (deselectAllBtn) deselectAllBtn.style.display = this.checked ? 'inline-block' : 'none';
        });
    }

    // Individual checkboxes
    customerCheckboxes.forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            updateBulkDeleteButton();
            const allChecked = Array.from(customerCheckboxes).every(cb => cb.checked);
            if (selectAllCheckbox) selectAllCheckbox.checked = allChecked;
            if (selectAllBtn) selectAllBtn.style.display = allChecked ? 'none' : 'inline-block';
            if (deselectAllBtn) deselectAllBtn.style.display = allChecked ? 'inline-block' : 'none';
        });
    });

    // Select All button
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function() {
            customerCheckboxes.forEach(function(checkbox) {
                checkbox.checked = true;
            });
            if (selectAllCheckbox) selectAllCheckbox.checked = true;
            updateBulkDeleteButton();
            selectAllBtn.style.display = 'none';
            if (deselectAllBtn) deselectAllBtn.style.display = 'inline-block';
        });
    }

    // Deselect All button
    if (deselectAllBtn) {
        deselectAllBtn.addEventListener('click', function() {
            customerCheckboxes.forEach(function(checkbox) {
                checkbox.checked = false;
            });
            if (selectAllCheckbox) selectAllCheckbox.checked = false;
            updateBulkDeleteButton();
            if (selectAllBtn) selectAllBtn.style.display = 'inline-block';
            deselectAllBtn.style.display = 'none';
        });
    }

    // Bulk delete button
    if (bulkDeleteBtn) {
        bulkDeleteBtn.addEventListener('click', function() {
            const selected = document.querySelectorAll('.customer-checkbox:checked');
            if (selected.length === 0) {
                showWarning('Please select at least one customer to delete.');
                return;
            }

            const customerNames = Array.from(selected).map(function(cb) {
                return cb.getAttribute('data-customer-name');
            }).join(', ');

            if (confirm('Are you sure you want to delete the following ' + selected.length + ' customer(s)?\n\n' + customerNames + '\n\nThis action cannot be undone!')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'view_clients.php';

                selected.forEach(function(checkbox) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'selected_customers[]';
                    input.value = checkbox.value;
                    form.appendChild(input);
                });

                const submitInput = document.createElement('input');
                submitInput.type = 'hidden';
                submitInput.name = 'bulk_delete_customers';
                submitInput.value = '1';
                form.appendChild(submitInput);

                document.body.appendChild(form);
                form.submit();
            }
        });
    }

    // Show/hide submit buttons & auto meter code generation in Add Customer Modal
    const addClientModal = document.getElementById('addClientModal');
    const meterCodeInputAuto = document.getElementById('add_meter_code');
    const meterCodeError = document.getElementById('meterCodeError');

    function fetchNextMeterCode(focusInput = false) {
        if (!meterCodeInputAuto) {
            return;
        }
        fetch('get_last_meter_code.php')
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    if (data.next_meter_code) {
                        meterCodeInputAuto.value = data.next_meter_code;
                        if (focusInput) {
                            meterCodeInputAuto.focus();
                            meterCodeInputAuto.select();
                        }
                    }
                    if (meterCodeError) {
                        meterCodeError.textContent = '';
                        meterCodeError.style.display = 'none';
                    }
                } else if (meterCodeError) {
                    meterCodeError.textContent = data.message || 'Unable to fetch meter code.';
                    meterCodeError.style.display = 'block';
                }
            })
            .catch(() => {
                if (meterCodeError) {
                    meterCodeError.textContent = 'Unable to fetch meter code.';
                    meterCodeError.style.display = 'block';
                }
            });
    }

    if (addClientModal) {
        const addClientSubmitBtn = document.getElementById('addClientSubmitBtn');
        const bulkImportSubmitBtn = document.getElementById('bulkImportSubmitBtn');
        const singleTab = document.getElementById('single-tab');
        const bulkTab = document.getElementById('bulk-tab');

        addClientModal.addEventListener('shown.bs.modal', function () {
            fetchNextMeterCode(false);
        });
        
        // Show correct button when modal opens (default to Single Customer)
        if (addClientSubmitBtn) {
            addClientSubmitBtn.style.display = 'inline-block';
        }
        if (bulkImportSubmitBtn) {
            bulkImportSubmitBtn.style.display = 'none';
        }
        
        // Handle tab switching
        if (singleTab && bulkTab) {
            singleTab.addEventListener('shown.bs.tab', function() {
                if (addClientSubmitBtn) addClientSubmitBtn.style.display = 'inline-block';
                if (bulkImportSubmitBtn) bulkImportSubmitBtn.style.display = 'none';
            });
            
            bulkTab.addEventListener('shown.bs.tab', function() {
                if (addClientSubmitBtn) addClientSubmitBtn.style.display = 'none';
                if (bulkImportSubmitBtn) bulkImportSubmitBtn.style.display = 'inline-block';
            });
        }
    }

    // Edit Client Functionality
    var editButtons = document.querySelectorAll('.edit-btn');
    var editModal = document.getElementById('editClientModal');

    editButtons.forEach(function(button) {
        button.addEventListener('click', function() {
            var clientData = JSON.parse(this.getAttribute('data-client'));
            editModal.querySelector('#client_id').value = clientData.id;
            editModal.querySelector('#code').value = clientData.code;
            editModal.querySelector('#category_id').value = clientData.category_id;
            editModal.querySelector('#firstname').value = clientData.firstname;
            editModal.querySelector('#middlename').value = clientData.middlename;
            editModal.querySelector('#lastname').value = clientData.lastname;
            editModal.querySelector('#contact').value = clientData.contact;
            editModal.querySelector('#address').value = clientData.address;
            editModal.querySelector('#meter_code').value = clientData.meter_code;
        });
    });

    // Delete Client Functionality
    var deleteClientId = null;
    var deleteConfirmModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
    var deleteToast = new bootstrap.Toast(document.getElementById('deleteToast'));

    document.querySelectorAll('.delete-btn').forEach(function(button) {
        button.addEventListener('click', function(event) {
            event.preventDefault();
            deleteClientId = this.getAttribute('data-client-id');
            
            // Get client data from the table row
            var row = this.closest('tr');
            if (row) {
                var customerName = row.querySelector('.customer-name')?.textContent?.trim() || 'this customer';
                var meterCode = row.cells[4]?.textContent?.trim() || '';
                var address = row.cells[3]?.textContent?.trim() || '';
                
                // Update modal with client info
                var infoText = '<strong>Customer:</strong> ' + customerName;
                if (meterCode) {
                    infoText += '<br><strong>Meter Code:</strong> ' + meterCode;
                }
                if (address) {
                    infoText += '<br><strong>Address:</strong> ' + address;
                }
                document.getElementById('deleteClientInfo').innerHTML = infoText;
            }
            
            deleteConfirmModal.show();
        });
    });

    document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
        if (deleteClientId) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = 'view_clients.php';

            var inputId = document.createElement('input');
            inputId.type = 'hidden';
            inputId.name = 'delete_client_id';
            inputId.value = deleteClientId;
            form.appendChild(inputId);

            var inputDelete = document.createElement('input');
            inputDelete.type = 'hidden';
            inputDelete.name = 'delete_client';
            inputDelete.value = '1';
            form.appendChild(inputDelete);

            document.body.appendChild(form);
            deleteConfirmModal.hide();
            form.submit();
        }
    });

    // Notification Toast
    <?php if (!empty($notification)): ?>
        var notificationToast = new bootstrap.Toast(document.getElementById('notificationToast'), { delay: 4000 });
        notificationToast.show();
    <?php endif; ?>

    // Add View Client Functionality
    var viewButtons = document.querySelectorAll('a[href^="view_client.php"]');
    var viewModal = new bootstrap.Modal(document.getElementById('viewClientModal'));

    viewButtons.forEach(function(button) {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            var row = this.closest('tr');
            var clientData = JSON.parse(row.querySelector('.edit-btn').getAttribute('data-client'));
            
            // Set the client information in the modal
            document.getElementById('viewClientInitials').textContent = 
                (clientData.firstname.charAt(0) + clientData.lastname.charAt(0)).toUpperCase();
            document.getElementById('viewClientFullName').textContent = 
                clientData.firstname + ' ' + clientData.lastname;
            document.getElementById('viewClientCode').textContent = 'Code: ' + (clientData.code || 'N/A');
            
            document.getElementById('viewFirstName').textContent = clientData.firstname || 'N/A';
            document.getElementById('viewMiddleName').textContent = clientData.middlename || 'N/A';
            document.getElementById('viewLastName').textContent = clientData.lastname || 'N/A';
            document.getElementById('viewContact').textContent = clientData.contact || 'N/A';
            document.getElementById('viewAddress').textContent = clientData.address || 'N/A';
            document.getElementById('viewMeterCode').textContent = clientData.meter_code || 'N/A';
            document.getElementById('viewRate').textContent = clientData.rate || 'N/A';
            
            // Get category name from the select option
            try {
                var categorySelect = document.getElementById('category_id');
                var categoryOption = categorySelect ? categorySelect.querySelector(`option[value="${clientData.category_id}"]`) : null;
                document.getElementById('viewCategory').textContent = categoryOption ? categoryOption.textContent : 'N/A';
            } catch (error) {
                document.getElementById('viewCategory').textContent = 'N/A';
            }
            
            viewModal.show();
        });
    });

    // Historical Reading Modal Functionality
    var historicalReadingBtns = document.querySelectorAll('.historical-reading-btn');
    var historicalModal = document.getElementById('historicalReadingModal');
    
    historicalReadingBtns.forEach(function(button) {
        button.addEventListener('click', function() {
            var clientId = this.getAttribute('data-client-id');
            var clientName = this.getAttribute('data-client-name');
            var meterCode = this.getAttribute('data-meter-code');
            
            // Set client info in all tabs
            document.getElementById('historical_client_id').value = clientId;
            document.getElementById('historical_client_name').textContent = clientName;
            document.getElementById('historical_meter_code').textContent = meterCode;
            
            document.getElementById('bulk_client_id').value = clientId;
            document.getElementById('bulk_client_name').textContent = clientName;
            document.getElementById('bulk_meter_code').textContent = meterCode;
            
            document.getElementById('import_client_id').value = clientId;
            document.getElementById('import_client_name').textContent = clientName;
            document.getElementById('import_meter_code').textContent = meterCode;
            
            // Set client info for view tab
            document.getElementById('view_client_name').textContent = clientName;
            document.getElementById('view_meter_code').textContent = meterCode;
            
            // Load historical readings for the view tab
            loadHistoricalReadings(clientId);
        });
    });
    
    // Bulk Reading Functionality
    var addReadingRowBtn = document.getElementById('add-reading-row');
    var bulkReadingsContainer = document.getElementById('bulk-readings-container');
    
    if (addReadingRowBtn) {
        addReadingRowBtn.addEventListener('click', function() {
            var newRow = document.createElement('div');
            newRow.className = 'bulk-reading-row row mb-2';
            newRow.innerHTML = `
                <div class="col-md-3">
                    <input type="date" name="bulk_reading_dates[]" class="form-control" placeholder="Date" />
                </div>
                <div class="col-md-3">
                    <input type="number" name="bulk_reading_values[]" class="form-control" step="0.01" placeholder="Reading" />
                </div>
                <div class="col-md-3">
                    <select name="bulk_cycle_ids[]" class="form-select">
                        <option value="">Select Cycle</option>
                        ${getBillingCycleOptions()}
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="button" class="btn btn-outline-danger btn-sm remove-reading-row">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            `;
            
            bulkReadingsContainer.appendChild(newRow);
            
            // Add remove functionality to new row
            newRow.querySelector('.remove-reading-row').addEventListener('click', function() {
                newRow.remove();
            });
        });
    }
    
    // Remove reading row functionality
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-reading-row') || e.target.closest('.remove-reading-row')) {
            var row = e.target.closest('.bulk-reading-row');
            if (row) {
                row.remove();
            }
        }
    });
    
    // Function to get billing cycle options (reuse existing options)
    function getBillingCycleOptions() {
        var select = document.querySelector('select[name="billing_cycle_id"]');
        if (select) {
            var options = Array.from(select.options).map(option => 
                `<option value="${option.value}">${option.textContent}</option>`
            ).join('');
            return options;
        }
        return '';
    }
    
    // Auto-fill date ranges for bulk entry
    var bulkStartDate = document.getElementById('bulk_start_date');
    var bulkEndDate = document.getElementById('bulk_end_date');
    
    if (bulkStartDate && bulkEndDate) {
        bulkStartDate.addEventListener('change', function() {
            if (this.value && !bulkEndDate.value) {
                // Set end date to one month later
                var startDate = new Date(this.value);
                var endDate = new Date(startDate);
                endDate.setMonth(endDate.getMonth() + 1);
                bulkEndDate.value = endDate.toISOString().split('T')[0];
            }
        });
    }
    
    // CSV file validation
    var csvFileInput = document.getElementById('csv_file');
    if (csvFileInput) {
        csvFileInput.addEventListener('change', function() {
            var file = this.files[0];
            if (file) {
                var fileName = file.name.toLowerCase();
                if (!fileName.endsWith('.csv')) {
                    showWarning('Please select a CSV file');
                    this.value = '';
                }
            }
        });
    }
    
    // Global variables for readings data
    var allReadings = [];
    var filteredReadings = [];
    
    // Load Historical Readings Function
    function loadHistoricalReadings(clientId) {
        var container = document.getElementById('readings-container');
        container.innerHTML = `
            <div class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2 text-muted">Loading historical readings...</p>
            </div>
        `;
        
        // Fetch historical readings via AJAX
        fetch('get_historical_readings.php?client_id=' + clientId)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    allReadings = data.readings;
                    filteredReadings = [...allReadings];
                    displayHistoricalReadings(filteredReadings);
                } else {
                    container.innerHTML = `
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            ${data.message || 'No historical readings found for this customer.'}
                        </div>
                    `;
                }
            })
            .catch(error => {
                container.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        Error loading historical readings: ${error.message}
                    </div>
                `;
            });
    }
    
    // Display Historical Readings Function
    function displayHistoricalReadings(readings) {
        var container = document.getElementById('readings-container');
        
        if (readings.length === 0) {
            container.innerHTML = `
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    No historical readings found for this customer. Use the other tabs to add readings.
                </div>
            `;
            return;
        }
        
        var html = `
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Reading</th>
                            <th>Previous Reading</th>
                            <th>Consumption</th>
                            <th>Billing Cycle</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
        `;
        
        readings.forEach(function(reading) {
            var consumption = reading.consumption || (reading.reading - reading.previous_reading);
            var statusBadge = reading.status === 'paid' ? 
                '<span class="badge bg-success">Paid</span>' : 
                '<span class="badge bg-warning">Unpaid</span>';
            
            html += `
                <tr>
                    <td>${reading.reading_date}</td>
                    <td>${reading.reading}</td>
                    <td>${reading.previous_reading || 'N/A'}</td>
                    <td>${consumption.toFixed(2)}</td>
                    <td>${reading.cycle_name || 'N/A'}</td>
                    <td>₱${reading.amount ? parseFloat(reading.amount).toFixed(2) : '0.00'}</td>
                    <td>${statusBadge}</td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-danger" onclick="confirmDeleteReading(${reading.id})" title="Delete Reading">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                </tr>
            `;
        });
        
        html += `
                    </tbody>
                </table>
            </div>
        `;
        
        container.innerHTML = html;
    }
    
    // Filter Readings Function (Simplified)
    window.filterReadings = function() {
        var searchTerm = document.getElementById('searchReadings').value.toLowerCase();
        
        filteredReadings = allReadings.filter(function(reading) {
            // Simple text search across all fields
            if (searchTerm) {
                return reading.reading.toString().includes(searchTerm) ||
                       reading.previous_reading.toString().includes(searchTerm) ||
                       reading.consumption.toString().includes(searchTerm) ||
                       reading.amount.toString().includes(searchTerm) ||
                       reading.reading_date.includes(searchTerm) ||
                       reading.status.includes(searchTerm);
            }
            return true;
        });
        
        displayHistoricalReadings(filteredReadings);
    }
    
    
    // Refresh Readings Function
    window.refreshReadings = function() {
        var clientId = document.getElementById('historical_client_id').value;
        if (clientId) {
            loadHistoricalReadings(clientId);
        }
    }
    
    // Global variable to store reading ID for deletion
    var readingToDelete = null;
    
    // Confirm Delete Reading Function
    window.confirmDeleteReading = function(readingId) {
        readingToDelete = readingId;
        var deleteModal = new bootstrap.Modal(document.getElementById('deleteReadingModal'));
        deleteModal.show();
        
        // Reset form
        document.getElementById('adminPassword').value = '';
        document.getElementById('confirmDelete').checked = false;
    }
    
    // Simple delete click handler
    window.handleDeleteClick = function() {
        var adminPassword = document.getElementById('adminPassword');
        var confirmCheckbox = document.getElementById('confirmDelete');
        
        if (!adminPassword || !confirmCheckbox) {
            showError('Error: Form elements not found');
            return;
        }
        
        var passwordEntered = adminPassword.value.trim() !== '';
        var checkboxChecked = confirmCheckbox.checked;
        
        console.log('Delete click - Password:', passwordEntered, 'Checkbox:', checkboxChecked);
        
        if (passwordEntered && checkboxChecked) {
            if (readingToDelete) {
                deleteHistoricalReading(readingToDelete);
            } else {
                showError('Error: No reading selected for deletion');
            }
        } else {
            showWarning('Please enter your admin password and confirm you understand the action.');
        }
    }
    
    // Delete Historical Reading Function (with password verification)
    function deleteHistoricalReading(readingId) {
        var password = document.getElementById('adminPassword').value;
        
        fetch('delete_historical_reading.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'reading_id=' + readingId + '&admin_password=' + encodeURIComponent(password)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Close modal
                var deleteModal = bootstrap.Modal.getInstance(document.getElementById('deleteReadingModal'));
                deleteModal.hide();
                
                // Show success message
                showSuccess('Historical reading deleted successfully');
                refreshReadings();
            } else {
                showError('Error deleting reading: ' + data.message);
            }
        })
        .catch(error => {
            showError('Error deleting reading: ' + error.message);
        });
    }

    // Sidebar toggle for mobile
    var sidebar = document.querySelector('.sidebar');
    var sidebarToggle = document.getElementById('sidebarToggle');
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

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Notification System -->
<script src="assets/js/notifications.js"></script>

</body>
</html>
