<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: adminlogin.php");
    exit();
}

include 'db.php';

// Ensure mobile_users table exists for managing mobile meter readers
$createMobileUsersTableSql = "
    CREATE TABLE IF NOT EXISTS mobile_users (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        username VARCHAR(50) NOT NULL,
        full_name VARCHAR(150) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        api_token VARCHAR(128) NOT NULL,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        last_login_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_mobile_username (username),
        UNIQUE KEY uniq_mobile_token (api_token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

if (!$conn->query($createMobileUsersTableSql)) {
    error_log('Failed to ensure mobile_users table exists: ' . $conn->error);
}

$message = '';
$messageClass = '';

// Check for messages from redirect (to prevent form resubmission)
if (isset($_GET['cycle_status']) && isset($_GET['message'])) {
    $cycle_status = $_GET['cycle_status'];
    $message = urldecode($_GET['message']);
    
    if ($cycle_status === 'error') {
        $messageClass = 'alert-danger';
    } else {
        $messageClass = 'alert-success';
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_rates'])) {
        $residential_rate = $_POST['residential_rate'];
        $residential_excess_rate = $_POST['residential_excess_rate'];
        $commercial_rate = $_POST['commercial_rate'];
        $commercial_excess_rate = $_POST['commercial_excess_rate'];

        // Start transaction
        $conn->begin_transaction();

        try {
            // Update residential rates
            $stmt = $conn->prepare("UPDATE category_rates SET rate = ?, excess_rate = ? WHERE category_id = 1");
            $stmt->bind_param("dd", $residential_rate, $residential_excess_rate);
            $residential_success = $stmt->execute();
            $stmt->close();

            // Update commercial rates
            $stmt = $conn->prepare("UPDATE category_rates SET rate = ?, excess_rate = ? WHERE category_id = 2");
            $stmt->bind_param("dd", $commercial_rate, $commercial_excess_rate);
            $commercial_success = $stmt->execute();
            $stmt->close();

            if ($residential_success && $commercial_success) {
                // Update all unpaid bills with new rates
                $update_bills_sql = "
                    UPDATE billing_list bl
                    JOIN client_list cl ON bl.client_id = cl.id
                    JOIN category_rates cr ON cl.category_id = cr.category_id
                    SET bl.total = 
                        CASE 
                            WHEN (bl.reading - bl.previous) <= 6 
                            THEN cr.rate
                            ELSE cr.rate + ((bl.reading - bl.previous - 6) * cr.excess_rate)
                        END
                    WHERE bl.status = 0";
                
                if ($conn->query($update_bills_sql)) {
                    $conn->commit();
                    $message = "Rates and all unpaid bills updated successfully!";
                    $messageClass = "alert-success";
                } else {
                    throw new Exception("Error updating bills");
                }
            } else {
                throw new Exception("Error updating rates");
            }
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error updating rates and bills: " . $e->getMessage();
            $messageClass = "alert-danger";
        }
    }
    
    // Handle billing cycle actions
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'create_cycle') {
            $cycle_name = $_POST['cycle_name'];
            $start_date = $_POST['start_date'];
            $end_date = $_POST['end_date'];
            $due_date = $_POST['due_date'];
            $description = $_POST['description'] ?? '';
            
            $stmt = $conn->prepare("INSERT INTO billing_cycles (cycle_name, start_date, end_date, due_date, description, created_by) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssi", $cycle_name, $start_date, $end_date, $due_date, $description, $_SESSION['admin_id']);
            
            if ($stmt->execute()) {
                // Redirect to prevent form resubmission on refresh
                header("Location: settings_rate.php?cycle_status=created&message=" . urlencode("Billing cycle created successfully!") . "#billing-cycles");
                exit();
            } else {
                // Redirect with error message
                header("Location: settings_rate.php?cycle_status=error&message=" . urlencode("Error creating billing cycle: " . $conn->error) . "#billing-cycles");
                exit();
            }
        }
        
        if ($action === 'activate_cycle') {
            $cycle_id = $_POST['cycle_id'];
            
            // Deactivate all other cycles first
            $conn->query("UPDATE billing_cycles SET status = 'completed' WHERE status = 'active'");
            
            // Activate the selected cycle
            $stmt = $conn->prepare("UPDATE billing_cycles SET status = 'active', activated_at = NOW() WHERE id = ?");
            $stmt->bind_param("i", $cycle_id);
            
            if ($stmt->execute()) {
                // Redirect to prevent form resubmission on refresh
                header("Location: settings_rate.php?cycle_status=activated&message=" . urlencode("Billing cycle activated successfully!") . "#billing-cycles");
                exit();
            } else {
                // Redirect with error message
                header("Location: settings_rate.php?cycle_status=error&message=" . urlencode("Error activating billing cycle: " . $conn->error) . "#billing-cycles");
                exit();
            }
        }
        
        if ($action === 'complete_cycle') {
            $cycle_id = $_POST['cycle_id'];
            
            $stmt = $conn->prepare("UPDATE billing_cycles SET status = 'completed', completed_at = NOW() WHERE id = ?");
            $stmt->bind_param("i", $cycle_id);
            
            if ($stmt->execute()) {
                // Redirect to prevent form resubmission on refresh
                header("Location: settings_rate.php?cycle_status=completed&message=" . urlencode("Billing cycle completed successfully!") . "#billing-cycles");
                exit();
            } else {
                // Redirect with error message
                header("Location: settings_rate.php?cycle_status=error&message=" . urlencode("Error completing billing cycle: " . $conn->error) . "#billing-cycles");
                exit();
            }
        }
        
        if ($action === 'deactivate_cycle') {
            $cycle_id = $_POST['cycle_id'];
            
            // Check if cycle is active
            $check_stmt = $conn->prepare("SELECT status FROM billing_cycles WHERE id = ?");
            $check_stmt->bind_param("i", $cycle_id);
            $check_stmt->execute();
            $cycle_result = $check_stmt->get_result();
            $cycle_data = $cycle_result->fetch_assoc();
            $check_stmt->close();
            
            if (!$cycle_data) {
                header("Location: settings_rate.php?cycle_status=error&message=" . urlencode("Billing cycle not found!") . "#billing-cycles");
                exit();
            } elseif ($cycle_data['status'] !== 'active') {
                header("Location: settings_rate.php?cycle_status=error&message=" . urlencode("Only active billing cycles can be deactivated.") . "#billing-cycles");
                exit();
            } else {
                // Deactivate the cycle (change status back to 'planned')
                $stmt = $conn->prepare("UPDATE billing_cycles SET status = 'planned', activated_at = NULL WHERE id = ?");
                $stmt->bind_param("i", $cycle_id);
                
                if ($stmt->execute()) {
                    // Redirect to prevent form resubmission on refresh
                    header("Location: settings_rate.php?cycle_status=deactivated&message=" . urlencode("Billing cycle deactivated successfully!") . "#billing-cycles");
                    exit();
                } else {
                    // Redirect with error message
                    header("Location: settings_rate.php?cycle_status=error&message=" . urlencode("Error deactivating billing cycle: " . $conn->error) . "#billing-cycles");
                    exit();
                }
            }
        }
        
        if ($action === 'delete_cycle') {
            $cycle_id = $_POST['cycle_id'];
            $admin_password = isset($_POST['admin_password']) ? $_POST['admin_password'] : '';
            
            // Check if cycle exists and get its status
            $check_stmt = $conn->prepare("SELECT status FROM billing_cycles WHERE id = ?");
            $check_stmt->bind_param("i", $cycle_id);
            $check_stmt->execute();
            $cycle_result = $check_stmt->get_result();
            $cycle_data = $cycle_result->fetch_assoc();
            $check_stmt->close();
            
            if (!$cycle_data) {
                // Redirect with error message
                header("Location: settings_rate.php?cycle_status=error&message=" . urlencode("Billing cycle not found!") . "#billing-cycles");
                exit();
            } elseif ($cycle_data['status'] === 'active') {
                // Cannot delete active cycles
                header("Location: settings_rate.php?cycle_status=error&message=" . urlencode("Cannot delete an active billing cycle. Please deactivate or complete it first.") . "#billing-cycles");
                exit();
            } else {
                // For completed cycles, require password authentication
                if ($cycle_data['status'] === 'completed') {
                    if (empty($admin_password)) {
                        header("Location: settings_rate.php?cycle_status=error&message=" . urlencode("Admin password is required to delete completed billing cycles.") . "#billing-cycles");
                        exit();
                    }
                    
                    // Verify admin password
                    $admin_id = $_SESSION['admin_id'];
                    $admin_query = "SELECT password FROM admin WHERE id = ?";
                    $admin_stmt = $conn->prepare($admin_query);
                    $admin_stmt->bind_param("i", $admin_id);
                    $admin_stmt->execute();
                    $admin_result = $admin_stmt->get_result();
                    
                    if ($admin_result->num_rows === 0) {
                        header("Location: settings_rate.php?cycle_status=error&message=" . urlencode("Admin not found.") . "#billing-cycles");
                        exit();
                    }
                    
                    $admin_data = $admin_result->fetch_assoc();
                    $stored_password = $admin_data['password'];
                    
                    // Try different password verification methods
                    $password_valid = false;
                    
                    // Method 1: Try password_verify (for hashed passwords)
                    if (password_verify($admin_password, $stored_password)) {
                        $password_valid = true;
                    }
                    // Method 2: Try direct comparison (for plain text passwords)
                    elseif ($admin_password === $stored_password) {
                        $password_valid = true;
                    }
                    // Method 3: Try MD5 comparison (for MD5 hashed passwords)
                    elseif (md5($admin_password) === $stored_password) {
                        $password_valid = true;
                    }
                    // Method 4: Try SHA1 comparison (for SHA1 hashed passwords)
                    elseif (sha1($admin_password) === $stored_password) {
                        $password_valid = true;
                    }
                    
                    if (!$password_valid) {
                        header("Location: settings_rate.php?cycle_status=error&message=" . urlencode("Invalid admin password. Please enter your correct admin password.") . "#billing-cycles");
                        exit();
                    }
                }
                
                // For completed or planned cycles, check readings count for warning
                $readings_check = $conn->prepare("SELECT COUNT(*) as count FROM pending_meter_readings WHERE billing_cycle_id = ?");
                $readings_check->bind_param("i", $cycle_id);
                $readings_check->execute();
                $readings_result = $readings_check->get_result();
                $readings_data = $readings_result->fetch_assoc();
                $readings_check->close();
                
                $readings_count = $readings_data['count'] ?? 0;
                
                // Check for bills associated with this cycle
                $bills_check = $conn->prepare("SELECT COUNT(*) as count FROM billing_list WHERE billing_cycle_id = ?");
                $bills_check->bind_param("i", $cycle_id);
                $bills_check->execute();
                $bills_result = $bills_check->get_result();
                $bills_data = $bills_result->fetch_assoc();
                $bills_check->close();
                
                $bills_count = $bills_data['count'] ?? 0;
                
                // Delete the cycle (readings and bills will remain but lose cycle association)
                // Note: We're not deleting readings/bills, just removing the cycle reference
                $stmt = $conn->prepare("DELETE FROM billing_cycles WHERE id = ?");
                $stmt->bind_param("i", $cycle_id);
                
                if ($stmt->execute()) {
                    // Optionally: Set billing_cycle_id to NULL for associated readings and bills
                    // This keeps the data but removes the cycle association
                    if ($readings_count > 0) {
                        $conn->query("UPDATE pending_meter_readings SET billing_cycle_id = NULL WHERE billing_cycle_id = $cycle_id");
                    }
                    if ($bills_count > 0) {
                        $conn->query("UPDATE billing_list SET billing_cycle_id = NULL WHERE billing_cycle_id = $cycle_id");
                    }
                    
                    $message = "Billing cycle deleted successfully!";
                    if ($readings_count > 0 || $bills_count > 0) {
                        $message .= " ($readings_count reading(s) and $bills_count bill(s) are now unassigned from this cycle)";
                    }
                    
                    // Redirect to prevent form resubmission on refresh
                    header("Location: settings_rate.php?cycle_status=deleted&message=" . urlencode($message) . "#billing-cycles");
                    exit();
                } else {
                    // Redirect with error message
                    header("Location: settings_rate.php?cycle_status=error&message=" . urlencode("Error deleting billing cycle: " . $conn->error) . "#billing-cycles");
                    exit();
                }
                $stmt->close();
            }
        }
    }
    
    // Handle additional fees actions
    if (isset($_POST['fee_action'])) {
        $fee_action = $_POST['fee_action'];
        
        if ($fee_action === 'add_fee') {
            $fee_name = $_POST['fee_name'];
            $fee_type = $_POST['fee_type'];
            $fee_amount = $_POST['fee_amount'];
            $description = $_POST['description'];
            $applies_to = $_POST['applies_to'];
            
            $stmt = $conn->prepare("INSERT INTO additional_fees (fee_name, fee_type, fee_amount, description, applies_to) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("ssdss", $fee_name, $fee_type, $fee_amount, $description, $applies_to);
            
            if ($stmt->execute()) {
                $message = "Additional fee added successfully!";
                $messageClass = "alert-success";
            } else {
                $message = "Error adding additional fee: " . $conn->error;
                $messageClass = "alert-danger";
            }
        }
        
        if ($fee_action === 'toggle_fee') {
            $fee_id = $_POST['fee_id'];
            $new_status = $_POST['new_status'];
            
            $stmt = $conn->prepare("UPDATE additional_fees SET is_active = ? WHERE id = ?");
            $stmt->bind_param("ii", $new_status, $fee_id);
            
            if ($stmt->execute()) {
                $status_text = $new_status ? "activated" : "deactivated";
                $message = "Additional fee $status_text successfully!";
                $messageClass = "alert-success";
            } else {
                $message = "Error updating additional fee: " . $conn->error;
                $messageClass = "alert-danger";
            }
        }
        
        if ($fee_action === 'update_fee') {
            $fee_id = $_POST['fee_id'];
            $fee_name = $_POST['fee_name'];
            $fee_type = $_POST['fee_type'];
            $fee_amount = $_POST['fee_amount'];
            $description = $_POST['description'];
            $applies_to = $_POST['applies_to'];
            
            $stmt = $conn->prepare("UPDATE additional_fees SET fee_name = ?, fee_type = ?, fee_amount = ?, description = ?, applies_to = ? WHERE id = ?");
            $stmt->bind_param("ssdssi", $fee_name, $fee_type, $fee_amount, $description, $applies_to, $fee_id);
            
            if ($stmt->execute()) {
                $message = "Additional fee updated successfully!";
                $messageClass = "alert-success";
            } else {
                $message = "Error updating additional fee: " . $conn->error;
                $messageClass = "alert-danger";
            }
        }
        
        if ($fee_action === 'delete_fee') {
            $fee_id = $_POST['fee_id'];
            
            $stmt = $conn->prepare("DELETE FROM additional_fees WHERE id = ?");
            $stmt->bind_param("i", $fee_id);
            
            if ($stmt->execute()) {
                $message = "Additional fee deleted successfully!";
                $messageClass = "alert-success";
            } else {
                $message = "Error deleting additional fee: " . $conn->error;
                $messageClass = "alert-danger";
            }
        }
    }

    // Handle mobile reader account actions
    if (isset($_POST['mobile_action'])) {
        $mobile_action = $_POST['mobile_action'];

        try {
            if ($mobile_action === 'create_user') {
                $username = trim($_POST['mobile_username'] ?? '');
                $full_name = trim($_POST['mobile_full_name'] ?? '');
                $password = $_POST['mobile_password'] ?? '';

                if (strlen($username) < 4 || !preg_match('/^[A-Za-z0-9_\-.]+$/', $username)) {
                    throw new Exception("Username must be at least 4 characters and can only include letters, numbers, and ._-");
                }

                if (strlen($full_name) < 3) {
                    throw new Exception("Please provide the meter reader's full name.");
                }

                if (strlen($password) < 6) {
                    throw new Exception("Password must be at least 6 characters long.");
                }

                // Check if username already exists
                $check_stmt = $conn->prepare("SELECT id FROM mobile_users WHERE username = ? LIMIT 1");
                $check_stmt->bind_param("s", $username);
                $check_stmt->execute();
                $existing = $check_stmt->get_result()->fetch_assoc();
                $check_stmt->close();

                if ($existing) {
                    throw new Exception("Username already exists. Please choose a different one.");
                }

                $password_hash = password_hash($password, PASSWORD_BCRYPT);
                $api_token = bin2hex(random_bytes(32));

                $insert_stmt = $conn->prepare("
                    INSERT INTO mobile_users (username, full_name, password_hash, api_token, status)
                    VALUES (?, ?, ?, ?, 'active')
                ");
                $insert_stmt->bind_param("ssss", $username, $full_name, $password_hash, $api_token);

                if ($insert_stmt->execute()) {
                    $message = "Mobile meter reader account created successfully!";
                    $messageClass = "alert-success";
                } else {
                    throw new Exception("Failed to create mobile user: " . $conn->error);
                }
            }

            if ($mobile_action === 'reset_token') {
                $user_id = intval($_POST['mobile_user_id'] ?? 0);
                if ($user_id <= 0) {
                    throw new Exception("Invalid mobile user selected.");
                }

                $new_token = bin2hex(random_bytes(32));
                $reset_stmt = $conn->prepare("UPDATE mobile_users SET api_token = ?, updated_at = NOW() WHERE id = ?");
                $reset_stmt->bind_param("si", $new_token, $user_id);

                if ($reset_stmt->execute()) {
                    $message = "API token reset successfully!";
                    $messageClass = "alert-success";
                } else {
                    throw new Exception("Failed to reset API token: " . $conn->error);
                }
            }

            if ($mobile_action === 'toggle_status') {
                $user_id = intval($_POST['mobile_user_id'] ?? 0);
                $new_status = $_POST['new_status'] ?? '';

                if ($user_id <= 0 || !in_array($new_status, ['active', 'inactive'])) {
                    throw new Exception("Invalid mobile user data.");
                }

                $toggle_stmt = $conn->prepare("UPDATE mobile_users SET status = ?, updated_at = NOW() WHERE id = ?");
                $toggle_stmt->bind_param("si", $new_status, $user_id);

                if ($toggle_stmt->execute()) {
                    $status_text = $new_status === 'active' ? 'activated' : 'deactivated';
                    $message = "Mobile account {$status_text} successfully!";
                    $messageClass = "alert-success";
                } else {
                    throw new Exception("Failed to update mobile account status: " . $conn->error);
                }
            }

            if ($mobile_action === 'delete_user') {
                $user_id = intval($_POST['mobile_user_id'] ?? 0);
                if ($user_id <= 0) {
                    throw new Exception("Invalid mobile user selected.");
                }

                $delete_stmt = $conn->prepare("DELETE FROM mobile_users WHERE id = ?");
                $delete_stmt->bind_param("i", $user_id);

                if ($delete_stmt->execute()) {
                    $message = "Mobile meter reader account removed.";
                    $messageClass = "alert-success";
                } else {
                    throw new Exception("Failed to delete mobile account: " . $conn->error);
                }
            }
        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageClass = "alert-danger";
        }
    }
    
    // Handle email settings form submission
    if (isset($_POST['save_email_settings'])) {
        $email_enabled = isset($_POST['email_enabled']) ? 1 : 0;
        $smtp_host = $_POST['smtp_host'] ?? '';
        $smtp_port = intval($_POST['smtp_port'] ?? 587);
        $smtp_username = $_POST['smtp_username'] ?? '';
        $smtp_password = $_POST['smtp_password'] ?? '';
        $from_email = $_POST['from_email'] ?? '';
        $from_name = $_POST['from_name'] ?? 'WaterSync';
        $email_test_mode = isset($_POST['email_test_mode']) ? 1 : 0;
        $email_bill_schedule_mode = $_POST['email_bill_schedule_mode'] ?? 'immediate';
        $email_bill_schedule_time = $_POST['email_bill_schedule_time'] ?? '08:00';
        $email_overdue_schedule_mode = $_POST['email_overdue_schedule_mode'] ?? 'scheduled';
        $email_overdue_schedule_time = $_POST['email_overdue_schedule_time'] ?? '09:00';
        
        // Ensure notification_settings table exists
        $createSettingsTable = "
            CREATE TABLE IF NOT EXISTS notification_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(100) UNIQUE NOT NULL,
                setting_value TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        $conn->query($createSettingsTable);
        
        // Save email settings
        updateEmailSetting($conn, 'email_enabled', $email_enabled);
        updateEmailSetting($conn, 'smtp_host', $smtp_host);
        updateEmailSetting($conn, 'smtp_port', $smtp_port);
        updateEmailSetting($conn, 'smtp_username', $smtp_username);
        updateEmailSetting($conn, 'smtp_password', $smtp_password);
        updateEmailSetting($conn, 'from_email', $from_email);
        updateEmailSetting($conn, 'from_name', $from_name);
        updateEmailSetting($conn, 'email_test_mode', $email_test_mode);
        updateEmailSetting($conn, 'email_bill_schedule_mode', $email_bill_schedule_mode);
        updateEmailSetting($conn, 'email_bill_schedule_time', $email_bill_schedule_time);
        updateEmailSetting($conn, 'email_overdue_schedule_mode', $email_overdue_schedule_mode);
        updateEmailSetting($conn, 'email_overdue_schedule_time', $email_overdue_schedule_time);
        
        $message = "Email settings saved successfully!";
        $messageClass = "alert-success";
    }
    
    // Handle email test
    if (isset($_POST['test_email'])) {
        $test_email = $_POST['test_email'] ?? '';
        $test_name = $_POST['test_name'] ?? 'Test User';
        
        if (!empty($test_email)) {
            include 'notification_manager.php';
            $notification_manager = new NotificationManager($conn);
            
            $email_subject = "Test Email from WaterSync";
            $email_message = "Dear $test_name,\n\nThis is a test email to verify your WaterSync email system is working properly.\n\nIf you received this message, your email notifications are set up correctly!\n\nBest regards,\nWaterSync Team";
            
            $email_result = $notification_manager->sendEmail($test_email, $email_subject, $email_message);
            
            if (isset($email_result['status']) && $email_result['status'] === 'sent') {
                $message = "✅ Email test sent successfully! Check your inbox.";
                $messageClass = "alert-success";
            } else {
                $error_msg = $email_result['error'] ?? 'Unknown error';
                $message = "❌ Email test failed: " . $error_msg;
                $messageClass = "alert-danger";
            }
        } else {
            $message = "Please provide an email address for testing.";
            $messageClass = "alert-warning";
        }
    }
    
    // Handle overdue email test
    if (isset($_POST['test_overdue_email'])) {
        $test_email = $_POST['test_overdue_email'] ?? '';
        $test_name = $_POST['test_overdue_name'] ?? 'Test User';
        $test_amount = $_POST['test_overdue_amount'] ?? '1,500.00';
        $test_days_overdue = intval($_POST['test_overdue_days'] ?? 5);
        
        if (!empty($test_email)) {
            include 'simple_notifications.php';
            
            $due_date = date('M d, Y', strtotime("-{$test_days_overdue} days"));
            $email_subject = "URGENT: Overdue Water Bill - $test_days_overdue Day(s) Late";
            $email_message = "Dear $test_name,\n\n" .
                           "URGENT: Your water bill payment is OVERDUE!\n\n" .
                           "Amount Due: ₱$test_amount\n" .
                           "Due Date: $due_date\n" .
                           "Days Overdue: $test_days_overdue day(s)\n\n" .
                           "Please pay immediately to avoid late fees and potential service disconnection.\n\n" .
                           "Thank you,\nWaterSync Team";
            
            $email_result = sendDummyEmail($test_email, $email_subject, $email_message);
            
            if (isset($email_result['success']) && $email_result['success']) {
                $message = "✅ Overdue email test sent successfully! Check your inbox.";
                $messageClass = "alert-success";
            } else {
                $error_msg = $email_result['error'] ?? $email_result['message'] ?? 'Unknown error';
                $message = "❌ Overdue email test failed: " . $error_msg;
                $messageClass = "alert-danger";
            }
        } else {
            $message = "Please provide an email address for testing.";
            $messageClass = "alert-warning";
        }
    }
    
    // Handle SMS settings form submission
    if (isset($_POST['save_sms_settings'])) {
        $sms_enabled = isset($_POST['sms_enabled']) ? 1 : 0;
        $sms_provider = $_POST['sms_provider'] ?? 'semaphore';
        $sms_api_key = $_POST['sms_api_key'] ?? '';
        $sms_api_secret = $_POST['sms_api_secret'] ?? '';
        $sms_sender_name = $_POST['sms_sender_name'] ?? 'WaterSync';
        $sms_from_number = $_POST['sms_from_number'] ?? '';
        $sms_account_sid = $_POST['sms_account_sid'] ?? '';
        $sms_auth_token = $_POST['sms_auth_token'] ?? '';
        $sms_test_mode = isset($_POST['sms_test_mode']) ? 1 : 0;
        $sms_bill_schedule_mode = $_POST['sms_bill_schedule_mode'] ?? 'immediate';
        $sms_bill_schedule_time = $_POST['sms_bill_schedule_time'] ?? '08:00';
        $sms_overdue_schedule_mode = $_POST['sms_overdue_schedule_mode'] ?? 'scheduled';
        $sms_overdue_schedule_time = $_POST['sms_overdue_schedule_time'] ?? '09:00';
        
        // Ensure notification_settings table exists
        $createSettingsTable = "
            CREATE TABLE IF NOT EXISTS notification_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(100) UNIQUE NOT NULL,
                setting_value TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        $conn->query($createSettingsTable);
        
        // Save SMS settings
        updateEmailSetting($conn, 'sms_enabled', $sms_enabled);
        updateEmailSetting($conn, 'sms_provider', $sms_provider);
        updateEmailSetting($conn, 'sms_api_key', $sms_api_key);
        updateEmailSetting($conn, 'sms_api_secret', $sms_api_secret);
        updateEmailSetting($conn, 'sms_sender_name', $sms_sender_name);
        updateEmailSetting($conn, 'sms_from_number', $sms_from_number);
        updateEmailSetting($conn, 'sms_account_sid', $sms_account_sid);
        updateEmailSetting($conn, 'sms_auth_token', $sms_auth_token);
        updateEmailSetting($conn, 'sms_test_mode', $sms_test_mode);
        updateEmailSetting($conn, 'sms_bill_schedule_mode', $sms_bill_schedule_mode);
        updateEmailSetting($conn, 'sms_bill_schedule_time', $sms_bill_schedule_time);
        updateEmailSetting($conn, 'sms_overdue_schedule_mode', $sms_overdue_schedule_mode);
        updateEmailSetting($conn, 'sms_overdue_schedule_time', $sms_overdue_schedule_time);
        
        $message = "SMS settings saved successfully!";
        $messageClass = "alert-success";
    }
    
    // Handle SMS test
    if (isset($_POST['test_sms'])) {
        $test_phone = $_POST['test_phone'] ?? '';
        $test_name = $_POST['test_sms_name'] ?? 'Test User';
        
        if (!empty($test_phone)) {
            include 'notification_manager.php';
            $notification_manager = new NotificationManager($conn);
            
            $sms_message = "TEST: Hi $test_name! This is a test SMS from WaterSync. Your SMS system is working properly!";
            $sms_result = $notification_manager->sendSMS($test_phone, $sms_message);
            
            if (isset($sms_result['status']) && $sms_result['status'] === 'sent') {
                $message = "✅ SMS test sent successfully! Check your phone.";
                $messageClass = "alert-success";
            } else {
                $error_msg = $sms_result['error'] ?? 'Unknown error';
                $message = "❌ SMS test failed: " . $error_msg;
                $messageClass = "alert-danger";
            }
        } else {
            $message = "Please provide a phone number for testing.";
            $messageClass = "alert-warning";
        }
    }
}

// Function to update email setting
function updateEmailSetting($conn, $key, $value) {
    $stmt = $conn->prepare("
        INSERT INTO notification_settings (setting_key, setting_value) 
        VALUES (?, ?) 
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->bind_param("ss", $key, $value);
    $stmt->execute();
    $stmt->close();
}

// Function to get email setting
function getEmailSetting($conn, $key, $default = '') {
    $stmt = $conn->prepare("SELECT setting_value FROM notification_settings WHERE setting_key = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return $result->fetch_assoc()['setting_value'];
    }
    return $default;
}

// Get current SMS settings
$sms_enabled = getEmailSetting($conn, 'sms_enabled', '1');
$sms_provider = getEmailSetting($conn, 'sms_provider', 'semaphore');
$sms_api_key = getEmailSetting($conn, 'sms_api_key', '');
$sms_api_secret = getEmailSetting($conn, 'sms_api_secret', '');
$sms_sender_name = getEmailSetting($conn, 'sms_sender_name', 'WaterSync');
$sms_from_number = getEmailSetting($conn, 'sms_from_number', '');
$sms_account_sid = getEmailSetting($conn, 'sms_account_sid', '');
$sms_auth_token = getEmailSetting($conn, 'sms_auth_token', '');
$sms_test_mode = getEmailSetting($conn, 'sms_test_mode', '0');
$sms_bill_schedule_mode = getEmailSetting($conn, 'sms_bill_schedule_mode', 'immediate');
$sms_bill_schedule_time = getEmailSetting($conn, 'sms_bill_schedule_time', '08:00');
$sms_overdue_schedule_mode = getEmailSetting($conn, 'sms_overdue_schedule_mode', 'scheduled');
$sms_overdue_schedule_time = getEmailSetting($conn, 'sms_overdue_schedule_time', '09:00');

// Get current email settings
$email_enabled = getEmailSetting($conn, 'email_enabled', '1');
$smtp_host = getEmailSetting($conn, 'smtp_host', 'mail.yourdomain.com');
$smtp_port = getEmailSetting($conn, 'smtp_port', '587');
$smtp_username = getEmailSetting($conn, 'smtp_username', '');
$smtp_password = getEmailSetting($conn, 'smtp_password', '');
$from_email = getEmailSetting($conn, 'from_email', '');
$from_name = getEmailSetting($conn, 'from_name', 'WaterSync');
$email_test_mode = getEmailSetting($conn, 'email_test_mode', '0');
$email_bill_schedule_mode = getEmailSetting($conn, 'email_bill_schedule_mode', 'immediate');
$email_bill_schedule_time = getEmailSetting($conn, 'email_bill_schedule_time', '08:00');
$email_overdue_schedule_mode = getEmailSetting($conn, 'email_overdue_schedule_mode', 'scheduled');
$email_overdue_schedule_time = getEmailSetting($conn, 'email_overdue_schedule_time', '09:00');

// Fetch current rates
$rates = [];
$sql = "SELECT * FROM category_rates ORDER BY category_id";
$result = $conn->query($sql);
while ($row = $result->fetch_assoc()) {
    $rates[$row['category_id']] = $row;
}

// Fetch billing cycles data
$cycles_sql = "SELECT bc.*, a.username as created_by_name,
               COUNT(pmr.id) as total_readings,
               SUM(CASE WHEN pmr.status = 'pending' THEN 1 ELSE 0 END) as pending_readings,
               SUM(CASE WHEN pmr.status = 'processed' THEN 1 ELSE 0 END) as processed_readings
               FROM billing_cycles bc
               LEFT JOIN admin a ON bc.created_by = a.id
               LEFT JOIN pending_meter_readings pmr ON bc.id = pmr.billing_cycle_id
               GROUP BY bc.id
               ORDER BY bc.created_at DESC";
$cycles_result = $conn->query($cycles_sql);

// Get current active cycle
$active_cycle_sql = "SELECT * FROM billing_cycles WHERE status = 'active' LIMIT 1";
$active_cycle_result = $conn->query($active_cycle_sql);
$active_cycle = $active_cycle_result->fetch_assoc();

// Get total clients for statistics
$total_clients_sql = "SELECT COUNT(*) as total FROM client_list WHERE status = 1";
$total_clients_result = $conn->query($total_clients_sql);
$total_clients = $total_clients_result->fetch_assoc()['total'];

// Fetch additional fees
$fees_sql = "SELECT * FROM additional_fees ORDER BY is_active DESC, fee_name ASC";
$fees_result = $conn->query($fees_sql);

// Fetch mobile meter reader accounts
$mobile_users = [];
$active_mobile_users_count = 0;
$mobile_users_sql = "
    SELECT id, username, full_name, api_token, status, last_login_at, created_at, updated_at
    FROM mobile_users
    ORDER BY created_at DESC
";
$mobile_users_result = $conn->query($mobile_users_sql);
if ($mobile_users_result) {
    while ($row = $mobile_users_result->fetch_assoc()) {
        $mobile_users[] = $row;
        if ($row['status'] === 'active') {
            $active_mobile_users_count++;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="icon" href="logo.png" />
    <title>Settings & Billing Cycles - Water Billing System</title>
    <!-- Bootstrap 5 CSS -->
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
            --print-bg: #ffffff; /* White background for printing */
            --print-text: #000000; /* Black text for printing */
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
            --print-bg: #ffffff; /* Consistent white background for printing */
            --print-text: #000000; /* Consistent black text for printing */
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
            transition: transform 0.3s ease-in-out;
            z-index: 1030; /* Ensure sidebar is above overlay */
        }
        
        .sidebar.show {
            transform: translateX(0);
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
            transition: margin-left 0.3s ease-in-out;
        }

        .card-soft {
            border-radius: 15px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            border: none;
            background-color: var(--card-bg);
            color: var(--card-text);
            margin-bottom: 20px;
        }

        .form-control, .form-select {
            background-color: var(--bg-color); /* Changed from --card-bg */
            border: 1px solid var(--border-color);
            color: var(--text-color);
        }

        .form-control:focus, .form-select:focus {
            background-color: var(--bg-color); /* Changed from --card-bg */
            border-color: var(--hover-text);
            color: var(--text-color);
            box-shadow: 0 0 0 0.25rem rgba(13,110,253,.25); /* Standard Bootstrap focus */
        }
        
        .btn-primary {
            background-color: var(--hover-text);
            border-color: var(--hover-text);
        }
        .btn-primary:hover {
            opacity: 0.9;
        }

        .theme-switch-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px;
            border-radius: 10px;
            margin: 10px 20px;
            background-color: var(--hover-bg); /* Use hover-bg for consistency */
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
            background-color: #ccc; /* Default slider background */
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
            background-color: var(--hover-text); /* Use theme color for active slider */
        }

        input:checked + .slider:before {
            transform: translateX(26px);
        }

        /* Mobile Navigation */
        .mobile-nav-toggle {
            display: none; /* Hidden by default */
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1040; /* Above sidebar */
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            justify-content: center;
            align-items: center;
            color: var(--text-color);
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1020; /* Below sidebar but above content */
        }
        
        /* QR Code Specific Styles */
        .qr-code-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 15px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            background-color: var(--card-bg);
            margin-bottom: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .qr-code-card img {
            max-width: 150px; /* Adjust as needed */
            height: auto;
            margin-bottom: 10px;
            border: 1px solid var(--border-color);
        }
        .qr-code-info {
            font-size: 0.9rem;
            text-align: center;
            color: var(--muted-text);
        }
        .qr-code-info strong {
            color: var(--card-text);
        }
        .qr-code-actions button {
            margin-top: 10px;
        }
        #qrCodeDisplayArea .col-md-4 { /* Ensure columns have spacing */
            padding: 10px;
        }
        .loading-spinner {
            border: 4px solid var(--hover-bg);
            border-top: 4px solid var(--hover-text);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Print Styles for QR Stickers */
        @media print {
            body * {
                visibility: hidden; /* Hide everything by default */
            }
            .print-area, .print-area * {
                visibility: visible; /* Show only the print area and its children */
            }
            .print-area {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%; /* Ensure it takes up the page */
                margin: 0;
                padding: 0;
                background-color: var(--print-bg) !important; /* Force white background */
            }
            .sticker {
                width: 2.5in;
                height: 1.5in;
                padding: 0.1in; /* Small padding inside sticker */
                margin: 0.05in auto; /* Center sticker on page if printing one */
                border: 1px dashed var(--print-text); /* Optional: outline for cutting */
                box-sizing: border-box;
                display: flex; /* Use flexbox for layout */
                flex-direction: row; /* QR on left, text on right */
                align-items: center; /* Vertically align items */
                overflow: hidden; /* Prevent content spill */
                page-break-inside: avoid !important; /* Try to keep sticker on one page */
                background-color: var(--print-bg) !important; /* Force white background */
                color: var(--print-text) !important; /* Force black text */
            }
            .sticker-qr {
                width: 1in;
                height: 1in;
                margin-right: 0.1in; /* Space between QR and text */
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .sticker-qr img {
                max-width: 100%;
                max-height: 100%;
                object-fit: contain;
                border: none !important; /* Remove border for print */
            }
            .sticker-info {
                flex-grow: 1; /* Text takes remaining space */
                font-size: 8pt; /* Small font for sticker */
                line-height: 1.2;
                display: flex;
                flex-direction: column;
                justify-content: center; /* Center text vertically if possible */
                color: var(--print-text) !important; /* Force black text */
            }
            .sticker-info p {
                margin: 0;
                padding: 0;
                color: var(--print-text) !important; /* Force black text */
            }
            .sticker-info strong {
                 color: var(--print-text) !important; /* Force black text */
            }
            @page {
                size: letter; /* Or your target paper size */
                margin: 0.25in; /* Adjust margins as needed */
            }
            /* Hide buttons and other non-essential elements in print view */
            .no-print, .no-print * {
                display: none !important;
            }
        }

        /* Responsive adjustments */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%); /* Hide sidebar by default */
                /* box-shadow: 0 0 15px rgba(0,0,0,0.2); optional shadow for pushed out menu */
            }
            .sidebar.show {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 0;
            }
            .mobile-nav-toggle {
                display: flex; /* Show toggle on smaller screens */
            }
            .sidebar-overlay.show {
                display: block;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }
            .card-soft {
                padding: 15px;
            }
             #qrCodeDisplayArea .col-md-4 {
                flex: 0 0 50%; /* Two cards per row */
                max-width: 50%;
            }
        }
        @media (max-width: 576px) {
            #qrCodeDisplayArea .col-md-4 {
                flex: 0 0 100%; /* One card per row */
                max-width: 100%;
            }
        }

        /* Table styles for dark mode */
        .table {
            color: var(--text-color);
            background-color: var(--card-bg);
        }

        .table thead th {
            background-color: var(--sidebar-bg);
            color: var(--text-color);
            border-color: var(--border-color);
        }

        .table tbody td {
            background-color: var(--card-bg);
            color: var(--text-color);
            border-color: var(--border-color);
        }

        .table tbody tr:hover {
            background-color: var(--hover-bg);
        }

        /* Dark mode table improvements */
        html[data-theme="dark"] .table tbody td,
        [data-theme="dark"] .table tbody td {
            color: var(--text-color) !important;
        }

        html[data-theme="dark"] .table tbody td strong,
        [data-theme="dark"] .table tbody td strong {
            color: var(--text-color) !important;
        }

        /* Dark mode text-muted improvements */
        html[data-theme="dark"] .text-muted,
        [data-theme="dark"] .text-muted {
            color: #b0b0b0 !important;
        }

        html[data-theme="dark"] .table .text-muted,
        [data-theme="dark"] .table .text-muted {
            color: #b0b0b0 !important;
        }

        html[data-theme="dark"] small.text-muted,
        [data-theme="dark"] small.text-muted {
            color: #b0b0b0 !important;
        }

        html[data-theme="dark"] .form-text.text-muted,
        [data-theme="dark"] .form-text.text-muted {
            color: #b0b0b0 !important;
        }

        /* Dark mode badge improvements */
        html[data-theme="dark"] .badge.bg-warning,
        [data-theme="dark"] .badge.bg-warning {
            background-color: #ffc107 !important;
            color: #000 !important;
        }

        html[data-theme="dark"] .badge.bg-danger,
        [data-theme="dark"] .badge.bg-danger {
            background-color: #dc3545 !important;
            color: #fff !important;
        }

        html[data-theme="dark"] .badge.bg-info,
        [data-theme="dark"] .badge.bg-info {
            background-color: #0dcaf0 !important;
            color: #000 !important;
        }

        html[data-theme="dark"] .badge.bg-primary,
        [data-theme="dark"] .badge.bg-primary {
            background-color: #0d6efd !important;
            color: #fff !important;
        }

        html[data-theme="dark"] .badge.bg-success,
        [data-theme="dark"] .badge.bg-success {
            background-color: #198754 !important;
            color: #fff !important;
        }

        html[data-theme="dark"] .badge.bg-secondary,
        [data-theme="dark"] .badge.bg-secondary {
            background-color: #6c757d !important;
            color: #fff !important;
        }
    </style>
</head>
<body>
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
            <a href="settings_rate.php" class="active">
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

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <button class="mobile-nav-toggle" id="mobileNavToggle"><i class="fas fa-bars"></i></button>

    <div class="main-content" id="mainContent">
        <!-- Settings Buttons -->
        <div class="d-flex justify-content-end mb-3 gap-2">
            <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#waterRatesModal">
                <i class="fas fa-tint me-2"></i>Update Water Rates
            </button>
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#additionalFeesModal">
                <i class="fas fa-plus-circle me-2"></i>Additional Fees
            </button>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#smsSettingsModal">
                <i class="fas fa-sms me-2"></i>SMS Settings
            </button>
            <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#emailSettingsModal">
                <i class="fas fa-envelope me-2"></i>Email Settings
            </button>
            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mobileUsersModal">
                <i class="fas fa-mobile-alt me-2"></i>Mobile Meter Readers
            </button>
        </div>
        
        <h3 class="mb-4">System Settings & Configuration</h3>

    <?php if ($message): ?>
    <div class="alert <?php echo $messageClass; ?> alert-dismissible fade show" role="alert">
        <?php echo $message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

        <!-- Water Rates Section - HIDDEN (Available via button and modal) -->
        <!-- 
        <div class="card card-soft">
            <div class="card-body">
                <h5 class="card-title mb-4">Update Water Rates</h5>
                <form method="POST" action="">
                    <div class="row">
                        <div class="col-md-6">
                            <fieldset class="mb-4 p-3 border rounded">
                                <legend class="w-auto px-2 h6">Residential Rates</legend>
                                <div class="mb-3">
                                    <label for="residential_rate" class="form-label">Rate per 6 m³ (₱)</label>
                                    <input type="number" step="0.01" class="form-control" id="residential_rate" name="residential_rate" value="<?php echo htmlspecialchars($rates[1]['rate'] ?? '0.00'); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label for="residential_excess_rate" class="form-label">Excess Rate per m³ (₱)</label>
                                    <input type="number" step="0.01" class="form-control" id="residential_excess_rate" name="residential_excess_rate" value="<?php echo htmlspecialchars($rates[1]['excess_rate'] ?? '0.00'); ?>" required>
                                </div>
                            </fieldset>
                        </div>
                        <div class="col-md-6">
                            <fieldset class="mb-4 p-3 border rounded">
                                <legend class="w-auto px-2 h6">Commercial Rates</legend>
                                <div class="mb-3">
                                    <label for="commercial_rate" class="form-label">Rate per 6 m³ (₱)</label>
                                    <input type="number" step="0.01" class="form-control" id="commercial_rate" name="commercial_rate" value="<?php echo htmlspecialchars($rates[2]['rate'] ?? '0.00'); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label for="commercial_excess_rate" class="form-label">Excess Rate per m³ (₱)</label>
                                    <input type="number" step="0.01" class="form-control" id="commercial_excess_rate" name="commercial_excess_rate" value="<?php echo htmlspecialchars($rates[2]['excess_rate'] ?? '0.00'); ?>" required>
                                </div>
                            </fieldset>
                        </div>
                    </div>
                    <button type="submit" name="update_rates" class="btn btn-primary"><i class="fas fa-save me-2"></i>Update Rates & Unpaid Bills</button>
                </form>
            </div>
        </div>
        -->

        <!-- Billing Cycle Management Section -->
        <div class="card card-soft mt-4" id="billing-cycles">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="card-title mb-0"><i class="fas fa-calendar-alt me-2"></i>Billing Cycle Management</h5>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCycleModal">
                        <i class="fas fa-plus me-2"></i>Create New Cycle
                    </button>
                </div>

                <!-- Current Active Cycle Info -->
                <?php if ($active_cycle): ?>
                    <div class="alert alert-success mb-4" style="border-left: 4px solid #28a745;">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h6 class="mb-1 text-success">
                                    <i class="fas fa-circle text-success" style="font-size: 0.5rem;"></i>
                                    Active Cycle: <strong><?php echo htmlspecialchars($active_cycle['cycle_name']); ?></strong>
                                </h6>
                                <div class="row">
                                    <div class="col-md-4">
                                        <small class="text-muted">
                                            <i class="fas fa-calendar-alt me-1"></i>
                                            Period: <?php echo date('M d', strtotime($active_cycle['start_date'])); ?> - <?php echo date('M d, Y', strtotime($active_cycle['end_date'])); ?>
                                        </small>
                                    </div>
                                    <div class="col-md-4">
                                        <small class="text-muted">
                                            <i class="fas fa-clock me-1"></i>
                                            Due: <?php echo date('M d, Y', strtotime($active_cycle['due_date'])); ?>
                                        </small>
                                    </div>
                                    <div class="col-md-4">
                                        <?php
                                        $days_left = max(0, ceil((strtotime($active_cycle['end_date']) - time()) / (24 * 3600)));
                                        ?>
                                        <strong class="text-primary"><?php echo $days_left; ?> days remaining</strong>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 text-end">
                                <small class="text-primary d-block">
                                    <i class="fas fa-mobile-alt me-1"></i>
                                    Mobile readings auto-assign to this cycle
                                </small>
                                <a href="pending_readings.php" class="btn btn-outline-success btn-sm mt-1">
                                    <i class="fas fa-eye me-1"></i>View Readings
                                </a>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning mb-4" style="border-left: 4px solid #ffc107;">
                        <h6 class="mb-1 text-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>No Active Billing Cycle</strong>
                        </h6>
                        <p class="mb-0">
                            Mobile app meter readings cannot be submitted without an active billing cycle. 
                            Create and activate a billing cycle to enable meter reading collection.
                        </p>
                    </div>
                <?php endif; ?>

                <!-- Billing Cycles Table -->
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Cycle Name</th>
                                <th>Period</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th>Readings</th>
                                <th>Created By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($cycles_result && $cycles_result->num_rows > 0): ?>
                                <?php while ($cycle = $cycles_result->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($cycle['cycle_name']); ?></strong>
                                            <?php if ($cycle['description']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($cycle['description']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo date('M d', strtotime($cycle['start_date'])); ?> - 
                                            <?php echo date('M d, Y', strtotime($cycle['end_date'])); ?>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($cycle['due_date'])); ?></td>
                                        <td>
                                            <?php
                                            $status_classes = [
                                                'planned' => 'badge bg-secondary',
                                                'active' => 'badge bg-success',
                                                'completed' => 'badge bg-primary',
                                                'cancelled' => 'badge bg-danger'
                                            ];
                                            $status_class = $status_classes[$cycle['status']] ?? 'badge bg-secondary';
                                            ?>
                                            <span class="<?php echo $status_class; ?>">
                                                <?php echo ucfirst($cycle['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?php echo $cycle['total_readings']; ?></span>
                                            <small class="text-muted d-block">
                                                <?php echo $cycle['pending_readings']; ?> pending, 
                                                <?php echo $cycle['processed_readings']; ?> processed
                                            </small>
                                        </td>
                                        <td><?php echo htmlspecialchars($cycle['created_by_name']); ?></td>
                                        <td>
                                            <?php if ($cycle['status'] === 'planned'): ?>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="activate_cycle">
                                                    <input type="hidden" name="cycle_id" value="<?php echo $cycle['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-success" 
                                                            onclick="return confirm('Activate this billing cycle? This will deactivate the current active cycle.')">
                                                        <i class="fas fa-play"></i> Activate
                                                    </button>
                                                </form>
                                            <?php elseif ($cycle['status'] === 'active'): ?>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="deactivate_cycle">
                                                    <input type="hidden" name="cycle_id" value="<?php echo $cycle['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-secondary"
                                                            onclick="return confirm('Deactivate this billing cycle? It will be changed back to planned status.')">
                                                        <i class="fas fa-pause"></i> Deactivate
                                                    </button>
                                                </form>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="complete_cycle">
                                                    <input type="hidden" name="cycle_id" value="<?php echo $cycle['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-warning"
                                                            onclick="return confirm('Complete this billing cycle? This action cannot be undone.')">
                                                        <i class="fas fa-check"></i> Complete
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            
                                            <a href="pending_readings.php?cycle_id=<?php echo $cycle['id']; ?>" 
                                               class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            
                                            <?php 
                                            // Show delete button for cycles that can be deleted
                                            // Conditions: Not active (completed or planned cycles can be deleted)
                                            $canDelete = ($cycle['status'] !== 'active');
                                            ?>
                                            <?php if ($canDelete): ?>
                                                <?php if ($cycle['status'] === 'completed'): ?>
                                                    <!-- For completed cycles, use modal with password -->
                                                    <button type="button" class="btn btn-sm btn-outline-danger" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#deleteCycleModal"
                                                            data-cycle-id="<?php echo $cycle['id']; ?>"
                                                            data-cycle-name="<?php echo htmlspecialchars($cycle['cycle_name'], ENT_QUOTES); ?>"
                                                            data-cycle-readings="<?php echo $cycle['total_readings']; ?>">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                <?php else: ?>
                                                    <!-- For planned cycles, use simple confirmation -->
                                                    <?php 
                                                    $deleteConfirmMsg = 'Are you sure you want to delete this billing cycle?';
                                                    if ($cycle['total_readings'] > 0) {
                                                        $deleteConfirmMsg .= " This cycle has {$cycle['total_readings']} reading(s). Readings will remain but will be unassigned from this cycle.";
                                                    }
                                                    $deleteConfirmMsg .= ' This action cannot be undone.';
                                                    ?>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('<?php echo htmlspecialchars($deleteConfirmMsg, ENT_QUOTES); ?>');">
                                                        <input type="hidden" name="action" value="delete_cycle">
                                                        <input type="hidden" name="cycle_id" value="<?php echo $cycle['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                                            <i class="fas fa-trash"></i> Delete
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted">
                                        <i class="fas fa-calendar-times fa-2x mb-2"></i>
                                        <p>No billing cycles found. Create your first billing cycle to get started.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Additional Fees Management Section - HIDDEN (Available via button and modal) -->
        <!-- 
        <div class="card card-soft mt-4" id="additional-fees">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="card-title mb-0"><i class="fas fa-plus-circle me-2"></i>Additional Fees Management</h5>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addFeeModal">
                        <i class="fas fa-plus me-2"></i>Add New Fee
                    </button>
                </div>

                <p class="text-muted mb-4">
                    Manage additional fees that are automatically applied to bills during the automated billing process.
                    These fees will be added to the base water consumption charges.
                </p>

                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Fee Name</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Applies To</th>
                                <th>Status</th>
                                <th>Description</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($fees_result && $fees_result->num_rows > 0): ?>
                                <?php while ($fee = $fees_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($fee['fee_name']); ?></strong></td>
                                        <td>
                                            <?php if ($fee['fee_type'] === 'fixed'): ?>
                                                <span class="badge bg-info">Fixed Amount</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">Percentage</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($fee['fee_type'] === 'fixed'): ?>
                                                ₱<?php echo number_format($fee['fee_amount'], 2); ?>
                                            <?php else: ?>
                                                <?php echo number_format($fee['fee_amount'], 2); ?>%
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary">
                                                <?php echo ucfirst($fee['applies_to']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($fee['is_active']): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($fee['description']); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="fee_action" value="toggle_fee">
                                                <input type="hidden" name="fee_id" value="<?php echo $fee['id']; ?>">
                                                <input type="hidden" name="new_status" value="<?php echo $fee['is_active'] ? 0 : 1; ?>">
                                                <button type="submit" class="btn btn-sm <?php echo $fee['is_active'] ? 'btn-outline-warning' : 'btn-outline-success'; ?>" 
                                                        onclick="return confirm('<?php echo $fee['is_active'] ? 'Deactivate' : 'Activate'; ?> this fee?')">
                                                    <?php if ($fee['is_active']): ?>
                                                        <i class="fas fa-pause"></i> Deactivate
                                                    <?php else: ?>
                                                        <i class="fas fa-play"></i> Activate
                                                    <?php endif; ?>
                                                </button>
                                            </form>
                                            
                                            <button class="btn btn-sm btn-outline-primary" 
                                                    onclick="editFee(<?php echo htmlspecialchars(json_encode($fee)); ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="fee_action" value="delete_fee">
                                                <input type="hidden" name="fee_id" value="<?php echo $fee['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" 
                                                        onclick="return confirm('Delete this fee? This action cannot be undone.')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted">
                                        <i class="fas fa-plus-circle fa-2x mb-2"></i>
                                        <p>No additional fees found. Add your first fee to get started.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="alert alert-info mt-3">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>How Additional Fees Work:</strong>
                    <ul class="mb-0 mt-2">
                        <li><strong>Fixed fees</strong> are added as a flat amount to each bill</li>
                        <li><strong>Percentage fees</strong> are calculated as a percentage of the base water consumption charge</li>
                        <li>Only <strong>active fees</strong> are applied during automated bill creation</li>
                        <li>Fees can be applied to <strong>All</strong> customers, only <strong>Residential</strong>, or only <strong>Commercial</strong></li>
                    </ul>
                </div>
            </div>
        </div>
        -->

        <!-- QR Code Generator Section -->
        <div class="card card-soft mt-4 no-print">
            <div class="card-body">
                <h5 class="card-title mb-4">QR Code Generator</h5>
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label for="clientSelectQR" class="form-label">Select Client:</label>
                        <div class="position-relative">
                            <input type="text" class="form-control" id="qrClientSearch" placeholder="Search client by name or meter code..." autocomplete="off">
                            <select id="clientSelectQR" class="form-select" style="display: none;">
                                <option value="all">All Active Clients</option>
                                <!-- Options will be populated by JavaScript -->
                            </select>
                            <div id="qrClientDropdown" class="position-absolute w-100 bg-white border rounded shadow-lg" style="max-height: 200px; overflow-y: auto; z-index: 1000; display: none; top: 100%;"></div>
                        </div>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button id="generateQRCodesBtn" class="btn btn-success me-2"><i class="fas fa-qrcode me-2"></i>Generate Selected QR Codes</button>
                         <button id="printAllStickersBtn" class="btn btn-info" disabled><i class="fas fa-print me-2"></i>Print All Displayed</button>
                            </div>
                     <div class="col-md-4 d-flex align-items-end">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="" id="regenerateExistingQR">
                            <label class="form-check-label" for="regenerateExistingQR">
                                Regenerate existing QR codes
                            </label>
                        </div>
                    </div>
                </div>
                
                <div id="qrGeneratorMessage" class="alert d-none" role="alert"></div>
                <div id="qrLoadingSpinner" class="loading-spinner d-none"></div>

                <div id="qrCodeDisplayArea" class="row mt-4">
                    <!-- QR Codes will be displayed here -->
                </div>
            </div>
        </div>
        <!-- End QR Code Generator Section -->

    </div> <!-- End Main Content -->

    <!-- Create Cycle Modal -->
    <div class="modal fade" id="createCycleModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Billing Cycle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create_cycle">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="cycle_name" class="form-label">Cycle Name *</label>
                                    <input type="text" class="form-control" id="cycle_name" name="cycle_name" 
                                           placeholder="e.g., January 2024 Billing" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="due_date" class="form-label">Bill Due Date *</label>
                                    <input type="date" class="form-control" id="due_date" name="due_date" required>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="start_date" class="form-label">Reading Period Start *</label>
                                    <input type="date" class="form-control" id="start_date" name="start_date" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="end_date" class="form-label">Reading Period End *</label>
                                    <input type="date" class="form-control" id="end_date" name="end_date" required>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description (Optional)</label>
                            <textarea class="form-control" id="description" name="description" rows="2" 
                                      placeholder="Additional notes about this billing cycle..."></textarea>
                        </div>

                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Mobile App Integration:</strong> Once activated, meter readers using the mobile app 
                            will automatically submit readings to this billing cycle when connected to the same network.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Billing Cycle</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Fee Modal -->
    <div class="modal fade" id="addFeeModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Additional Fee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="fee_action" value="add_fee">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="fee_name" class="form-label">Fee Name *</label>
                                    <input type="text" class="form-control" id="fee_name" name="fee_name" 
                                           placeholder="e.g., Service Fee" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="fee_type" class="form-label">Fee Type *</label>
                                    <select class="form-select" id="fee_type" name="fee_type" required>
                                        <option value="fixed">Fixed Amount</option>
                                        <option value="percentage">Percentage</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="fee_amount" class="form-label">Amount *</label>
                                    <div class="input-group">
                                        <span class="input-group-text" id="amount-prefix">₱</span>
                                        <input type="number" step="0.01" min="0" class="form-control" 
                                               id="fee_amount" name="fee_amount" required>
                                    </div>
                                    <small class="form-text text-muted">
                                        For fixed fees, enter amount in pesos. For percentage, enter percentage value.
                                    </small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="applies_to" class="form-label">Applies To *</label>
                                    <select class="form-select" id="applies_to" name="applies_to" required>
                                        <option value="all">All Customers</option>
                                        <option value="residential">Residential Only</option>
                                        <option value="commercial">Commercial Only</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="2" 
                                      placeholder="Brief description of this fee..."></textarea>
                        </div>

                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Note:</strong> This fee will be automatically applied to all new bills created 
                            through the automated billing process. You can activate/deactivate fees at any time.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Fee</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Cycle Modal (for completed cycles with password) -->
    <div class="modal fade" id="deleteCycleModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle me-2"></i>Delete Completed Billing Cycle
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="deleteCycleForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete_cycle">
                        <input type="hidden" name="cycle_id" id="delete_cycle_id">
                        
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Warning:</strong> You are about to delete a completed billing cycle. This action requires administrator authentication and cannot be undone!
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Cycle Information:</label>
                            <div class="card bg-light">
                                <div class="card-body">
                                    <p class="mb-1"><strong>Cycle Name:</strong> <span id="delete_cycle_name"></span></p>
                                    <p class="mb-0"><strong>Total Readings:</strong> <span id="delete_cycle_readings"></span></p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="delete_admin_password" class="form-label">
                                <i class="fas fa-lock me-2"></i>Enter Admin Password to Confirm
                            </label>
                            <input type="password" id="delete_admin_password" name="admin_password" 
                                   class="form-control" placeholder="Enter your admin password" 
                                   autocomplete="current-password" required>
                            <small class="form-text text-muted">This action requires administrator authentication.</small>
                            <div id="delete_password_status" class="mt-1"></div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="delete_confirm_check" required>
                                <label class="form-check-label" for="delete_confirm_check">
                                    I understand this action cannot be undone and will unassign readings/bills from this cycle
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger" id="confirmDeleteCycleBtn">
                            <i class="fas fa-trash me-2"></i>Delete Cycle
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Fee Modal -->
    <div class="modal fade" id="editFeeModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Additional Fee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="fee_action" value="update_fee">
                        <input type="hidden" name="fee_id" id="edit_fee_id">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_fee_name" class="form-label">Fee Name *</label>
                                    <input type="text" class="form-control" id="edit_fee_name" name="fee_name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_fee_type" class="form-label">Fee Type *</label>
                                    <select class="form-select" id="edit_fee_type" name="fee_type" required>
                                        <option value="fixed">Fixed Amount</option>
                                        <option value="percentage">Percentage</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_fee_amount" class="form-label">Amount *</label>
                                    <div class="input-group">
                                        <span class="input-group-text" id="edit-amount-prefix">₱</span>
                                        <input type="number" step="0.01" min="0" class="form-control" 
                                               id="edit_fee_amount" name="fee_amount" required>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_applies_to" class="form-label">Applies To *</label>
                                    <select class="form-select" id="edit_applies_to" name="applies_to" required>
                                        <option value="all">All Customers</option>
                                        <option value="residential">Residential Only</option>
                                        <option value="commercial">Commercial Only</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="edit_description" class="form-label">Description</label>
                            <textarea class="form-control" id="edit_description" name="description" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Fee</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS Bundle (Popper.js included) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Theme switcher logic
        const themeToggle = document.getElementById('theme-toggle');
        const currentTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', currentTheme);
        if (currentTheme === 'dark') {
            themeToggle.checked = true;
        }
        themeToggle.addEventListener('change', function() {
            const selectedTheme = this.checked ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', selectedTheme);
            localStorage.setItem('theme', selectedTheme);
        });

        // Mobile navigation
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const mobileNavToggle = document.getElementById('mobileNavToggle');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        if (mobileNavToggle) {
            mobileNavToggle.addEventListener('click', () => {
                sidebar.classList.toggle('show');
                sidebarOverlay.classList.toggle('show');
            });
        }
        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', () => {
                sidebar.classList.remove('show');
                sidebarOverlay.classList.remove('show');
            });
        }
        
        function checkScreenWidth() {
            if (window.innerWidth >= 992) {
                sidebar.classList.remove('show');
                sidebarOverlay.classList.remove('show');
            }
        }
        window.addEventListener('resize', checkScreenWidth);
        checkScreenWidth(); // Initial check


        // QR Code Generator JavaScript
        const clientSelectQR = document.getElementById('clientSelectQR');
        const generateQRCodesBtn = document.getElementById('generateQRCodesBtn');
        const printAllStickersBtn = document.getElementById('printAllStickersBtn');
        const regenerateExistingQRCheckbox = document.getElementById('regenerateExistingQR');
        const qrCodeDisplayArea = document.getElementById('qrCodeDisplayArea');
        const qrGeneratorMessage = document.getElementById('qrGeneratorMessage');
        const qrLoadingSpinner = document.getElementById('qrLoadingSpinner');
        let displayedQRData = []; // To store data of currently displayed QR codes

        // Function to show messages
        function showQRMessage(message, type = 'info') {
            qrGeneratorMessage.textContent = message;
            qrGeneratorMessage.className = `alert alert-${type} show`; // Added 'show'
            setTimeout(() => {
                 qrGeneratorMessage.className = 'alert d-none';
            }, 5000);
        }
        
        // Function to show/hide loading spinner
        function setLoading(isLoading) {
            if (isLoading) {
                qrLoadingSpinner.classList.remove('d-none');
                generateQRCodesBtn.disabled = true;
                printAllStickersBtn.disabled = true;
            } else {
                qrLoadingSpinner.classList.add('d-none');
                generateQRCodesBtn.disabled = false;
                // printAllStickersBtn will be enabled if there are QRs
            }
        }

        // QR Client Search functionality
        const qrClientSearch = document.getElementById('qrClientSearch');
        const qrClientDropdown = document.getElementById('qrClientDropdown');
        let qrClientsData = [];
        
        if (qrClientSearch && qrClientDropdown) {
            qrClientSearch.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase().trim();
                
                if (searchTerm === '') {
                    qrClientDropdown.style.display = 'none';
                    clientSelectQR.value = 'all';
                    return;
                }
                
                const filtered = qrClientsData.filter(client => {
                    const name = (client.name || '').toLowerCase();
                    const meter = (client.meter_code || '').toLowerCase();
                    return name.includes(searchTerm) || meter.includes(searchTerm);
                });
                
                if (filtered.length > 0) {
                    qrClientDropdown.innerHTML = filtered.map(client => 
                        `<div class="p-2 border-bottom cursor-pointer qr-client-option" data-value="${client.id}" style="cursor: pointer;">
                            ${client.name} (Meter: ${client.meter_code})
                        </div>`
                    ).join('');
                    qrClientDropdown.style.display = 'block';
                } else {
                    qrClientDropdown.innerHTML = '<div class="p-2 text-muted">No clients found</div>';
                    qrClientDropdown.style.display = 'block';
                }
            });
            
            qrClientDropdown.addEventListener('click', function(e) {
                if (e.target.classList.contains('qr-client-option')) {
                    const value = e.target.dataset.value;
                    const text = e.target.textContent.trim();
                    clientSelectQR.value = value;
                    qrClientSearch.value = text;
                    qrClientDropdown.style.display = 'none';
                    fetchAndDisplayQRCodes(value === 'all' ? null : value);
                }
            });
            
            document.addEventListener('click', function(e) {
                if (qrClientSearch && qrClientDropdown && !qrClientSearch.contains(e.target) && !qrClientDropdown.contains(e.target)) {
                    qrClientDropdown.style.display = 'none';
                }
            });
        }
        
        // Fetch clients for dropdown
        async function fetchClientsForQR() {
            try {
                const response = await fetch('get_clients_for_qr.php'); // Fetches ALL clients for dropdown initially
                if (!response.ok) {
                    const errorData = await response.json().catch(() => ({ message: 'Server error, not valid JSON' }));
                    throw new Error(errorData.message || `HTTP error! status: ${response.status}`);
                }
                const data = await response.json();
                if (data.success && data.clients) {
                    qrClientsData = data.clients; // Store for search
                    clientSelectQR.innerHTML = '<option value="all">All Active Clients</option>'; // Reset
                    data.clients.forEach(client => {
                        const option = document.createElement('option');
                        option.value = client.id;
                        option.textContent = `${client.name} (Meter: ${client.meter_code})`;
                        option.dataset.name = (client.name || '').toLowerCase();
                        option.dataset.meter = (client.meter_code || '').toLowerCase();
                        clientSelectQR.appendChild(option);
                    });
                } else {
                    showQRMessage(data.message || 'Could not load clients.', 'danger');
                }
            } catch (error) {
                console.error('Error fetching clients:', error);
                showQRMessage('Error fetching clients: ' + error.message, 'danger');
            }
        }

        // Display a single QR code card
        function displaySingleQRCard(client) {
            const col = document.createElement('div');
            col.className = 'col-md-4'; // Adjust column size as needed

            const card = document.createElement('div');
            card.className = 'qr-code-card';

            const img = document.createElement('img');
            // Ensure the image path is correct relative to the CAPSTONE root
            img.src = client.qr_image_path; // qr_image_path should be like 'qr_codes/filename.png'
            img.alt = 'QR Code for ' + client.name;

            const info = document.createElement('div');
            info.className = 'qr-code-info';
            info.innerHTML = `
                <strong>${client.name}</strong><br>
                Meter: ${client.meter_code}<br>
                <small>Generated: ${client.qr_created_at ? new Date(client.qr_created_at).toLocaleDateString() : 'N/A'}</small><br>
                <small>Printed: ${client.print_count || 0} times</small>
            `;
            
            const actions = document.createElement('div');
            actions.className = 'qr-code-actions';

            const printButton = document.createElement('button');
            printButton.className = 'btn btn-sm btn-outline-secondary me-2';
            printButton.innerHTML = '<i class="fas fa-print"></i> Print Sticker';
            printButton.onclick = () => printSingleClientSticker(client);
            
            // Add more actions if needed, e.g., download
            // const downloadButton = document.createElement('button');
            // downloadButton.className = 'btn btn-sm btn-outline-primary';
            // downloadButton.innerHTML = '<i class="fas fa-download"></i> Download';
            // downloadButton.onclick = () => { /* Download logic */ };

            actions.appendChild(printButton);
            // actions.appendChild(downloadButton);

            card.appendChild(img);
            card.appendChild(info);
            card.appendChild(actions);
            col.appendChild(card);
            qrCodeDisplayArea.appendChild(col);
        }
        
        // Fetch and display QR codes (called after generation or for a specific client)
        async function fetchAndDisplayQRCodes(clientId = null) {
            setLoading(true);
            qrCodeDisplayArea.innerHTML = ''; // Clear previous QRs
            displayedQRData = [];

            let url = 'get_clients_for_qr.php';
            if (clientId && clientId !== 'all') {
                url += `?client_id=${clientId}`;
            }

            try {
                const response = await fetch(url);
                 if (!response.ok) {
                    const errorText = await response.text(); // Get raw text for debugging
                    console.error('Fetch QR Error Response:', errorText);
                    let errorMessage = `HTTP error! status: ${response.status}`;
                    try {
                        const errorData = JSON.parse(errorText);
                        errorMessage = errorData.message || errorMessage;
                    } catch (e) {
                        // If parsing fails, use the raw text if it's not HTML
                        if (!errorText.trim().startsWith('<')) {
                             errorMessage = errorText;
                        } else {
                            errorMessage = 'Server returned an HTML error page instead of JSON.';
                        }
                    }
                    throw new Error(errorMessage);
                }
                const data = await response.json();
                
                console.log('Fetched QR data:', data); // Debug log

                if (data.success && data.clients) {
                    if (data.clients.length > 0) {
                        data.clients.forEach(client => {
                            // Only display if qr_image_path exists
                            if(client.qr_image_path) {
                                displaySingleQRCard(client);
                                displayedQRData.push(client);
                            }
                        });
                        
                        if (displayedQRData.length > 0) {
                            showQRMessage(`${displayedQRData.length} QR code(s) displayed.`, 'success');
                            printAllStickersBtn.disabled = false;
                        } else {
                            // Show helpful message if no QR codes exist yet
                            const totalClients = data.clients.length;
                            qrCodeDisplayArea.innerHTML = `
                                <div class="col-12">
                                    <div class="alert alert-info text-center">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <strong>No QR codes generated yet.</strong><br>
                                        <p class="mb-2 mt-2">Found ${totalClients} active client(s). Click "Generate Selected QR Codes" above to create QR codes for them.</p>
                                    </div>
                                </div>
                            `;
                            printAllStickersBtn.disabled = true;
                        }
                    } else {
                        qrCodeDisplayArea.innerHTML = `
                            <div class="col-12">
                                <div class="alert alert-warning text-center">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    No active clients found.
                                </div>
                            </div>
                        `;
                        printAllStickersBtn.disabled = true;
                    }
                } else {
                    showQRMessage(data.message || 'Could not load QR codes.', 'danger');
                    printAllStickersBtn.disabled = true;
                }
            } catch (error) {
                console.error('Error fetching QR codes:', error);
                showQRMessage('Error loading QR codes: ' + error.message, 'danger');
                qrCodeDisplayArea.innerHTML = `
                    <div class="col-12">
                        <div class="alert alert-danger text-center">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Error loading QR codes: ${error.message}<br>
                            <small>Check the browser console (F12) for more details.</small>
                        </div>
                    </div>
                `;
                printAllStickersBtn.disabled = true;
            } finally {
                setLoading(false);
            }
        }


        // Generate QR Codes
        generateQRCodesBtn.addEventListener('click', async () => {
            try {
                setLoading(true);
                const selectedClientId = clientSelectQR.value;
                const regenerate = regenerateExistingQRCheckbox.checked;

                const formData = new FormData();
                formData.append('client_id', selectedClientId);
                formData.append('regenerate', regenerate ? 'true' : 'false');

                const response = await fetch('generate_qr_codes.php', {
                    method: 'POST',
                    body: formData
                });

                // First check if the response is ok
                if (!response.ok) {
                    const text = await response.text();
                    console.error('Server Error Response:', text);
                    throw new Error(`Server error: ${response.status}`);
                }

                // Try to parse the response as JSON
                let data;
                try {
                    const text = await response.text();
                    console.log('Raw server response:', text); // Debug log
                    if (!text.trim()) {
                        throw new Error('Empty response from server');
                    }
                    data = JSON.parse(text);
                } catch (e) {
                    console.error('JSON Parse Error:', e);
                    throw new Error('Invalid response format from server');
                }

                if (data.success) {
                    showQRMessage(`Successfully processed ${data.count || 0} QR code(s).`, 'success');
                    // After generation, fetch and display relevant QRs
                    await fetchAndDisplayQRCodes(selectedClientId === 'all' ? null : selectedClientId);
                } else {
                    throw new Error(data.message || 'QR code generation failed.');
                }
            } catch (error) {
                console.error('Error generating QR codes:', error);
                showQRMessage('Error generating QR codes: ' + error.message, 'danger');
            } finally {
                setLoading(false);
            }
        });
        
        // Track QR Print
        async function trackPrint(clientId) {
            try {
                const formData = new FormData();
                formData.append('client_id', clientId);
                const response = await fetch('track_qr_print.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (!data.success) {
                    console.warn('Failed to track print for client ID:', clientId, data.message);
                }
            } catch (error) {
                console.error('Error tracking print:', error);
            }
        }
        
        // Print single client sticker
        function printSingleClientSticker(client) {
            if (!client || !client.qr_image_path || !client.qr_code_data) {
                showQRMessage('Cannot print: QR data is missing for this client.', 'warning');
                return;
            }

            const printWindow = window.open('', '_blank', 'width=800,height=600');
            const stickerHTML = `
                <html>
                <head>
                    <title>Print QR Sticker - ${client.name}</title>
                    <style>
                        body { margin: 0; font-family: Arial, sans-serif; background-color: #fff !important; }
                        @media print {
                            .no-print { display: none !important; }
                            body { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
                        }
                        .print-controls {
                            padding: 10px;
                            text-align: center;
                            background: #f8f9fa;
                            margin-bottom: 20px;
                        }
                        .print-button {
                            padding: 8px 16px;
                            background: #0d6efd;
                            color: white;
                            border: none;
                            border-radius: 4px;
                            cursor: pointer;
                        }
                        .print-button:hover {
                            background: #0b5ed7;
                        }
                        .sticker {
                            width: 2.5in;
                            height: 1.5in;
                            padding: 0.1in;
                            margin: 0.1in auto;
                            border: 1px dashed #ccc;
                            box-sizing: border-box;
                            display: flex;
                            flex-direction: row;
                            align-items: center;
                            overflow: hidden;
                            background-color: #fff !important;
                            color: #000 !important;
                        }
                        .sticker-qr {
                            width: 1in;
                            height: 1in;
                            margin-right: 0.1in;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                        }
                        .sticker-qr img {
                            max-width: 100%;
                            max-height: 100%;
                            object-fit: contain;
                        }
                        .sticker-info {
                            flex-grow: 1;
                            font-size: 8pt;
                            line-height: 1.2;
                            color: #000 !important;
                        }
                        .sticker-info p {
                            margin: 0;
                            padding: 0;
                            color: #000 !important;
                        }
                        .sticker-info strong {
                            color: #000 !important;
                        }
                        @page {
                            size: 2.5in 1.5in;
                            margin: 0;
                        }
                    </style>
                </head>
                <body>
                    <div class="print-controls no-print">
                        <button onclick="window.print(); window.opener.trackPrintAndRefresh('${client.id}');" class="print-button">
                            Print Sticker
                        </button>
                    </div>
                    <div class="print-area">
                        <div class="sticker">
                            <div class="sticker-qr">
                                <img src="${client.qr_image_path}" alt="QR Code">
                            </div>
                            <div class="sticker-info">
                                <p><strong>Meter:</strong> ${client.meter_code}</p>
                                <p><strong>Client:</strong> ${client.name}</p>
                                <p><small>ID: ${client.id}</small></p>
                            </div>
                        </div>
                    </div>
                </body>
                </html>
            `;

            printWindow.document.write(stickerHTML);
            printWindow.document.close();
        }

        // Function to handle print tracking and refresh
        window.trackPrintAndRefresh = function(clientId) {
            trackPrint(clientId);
            setTimeout(() => {
                fetchAndDisplayQRCodes(clientSelectQR.value === 'all' ? null : clientSelectQR.value);
            }, 2000);
        };

        // Print All Displayed Stickers
        printAllStickersBtn.addEventListener('click', () => {
            if (displayedQRData.length === 0) {
                showQRMessage('No QR codes displayed to print.', 'warning');
                return;
            }

            const printWindow = window.open('', '_blank', 'width=800,height=600');
            
            // Generate stickers HTML
            const stickersHTML = displayedQRData
                .filter(client => client && client.qr_image_path && client.qr_code_data)
                .map(client => `
                    <div class="sticker">
                        <div class="sticker-qr">
                            <img src="${client.qr_image_path}" alt="QR Code">
                </div>
                        <div class="sticker-info">
                            <p><strong>Meter:</strong> ${client.meter_code}</p>
                            <p><strong>Client:</strong> ${client.name}</p>
                            <p><small>ID: ${client.id}</small></p>
            </div>
        </div>
                `).join('');

            const clientIds = displayedQRData
                .filter(client => client && client.qr_image_path && client.qr_code_data)
                .map(client => client.id);

            const printHTML = `
                <html>
                <head>
                    <title>Print All QR Stickers</title>
                    <style>
                        body { margin: 0; font-family: Arial, sans-serif; background-color: #fff !important; }
                        @media print {
                            .no-print { display: none !important; }
                            body { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
                        }
                        .print-controls {
                            padding: 10px;
                            text-align: center;
                            background: #f8f9fa;
                            margin-bottom: 20px;
                        }
                        .print-button {
                            padding: 8px 16px;
                            background: #0d6efd;
                            color: white;
                            border: none;
                            border-radius: 4px;
                            cursor: pointer;
                        }
                        .print-button:hover {
                            background: #0b5ed7;
                        }
                        .sticker {
                            width: 2.5in;
                            height: 1.5in;
                            padding: 0.1in;
                            margin: 0.05in;
                            border: 1px dashed #ccc;
                            box-sizing: border-box;
                            display: flex;
                            flex-direction: row;
                            align-items: center;
                            overflow: hidden;
                            page-break-inside: avoid !important;
                            background-color: #fff !important;
                            color: #000 !important;
                        }
                        .sticker-qr {
                            width: 1in;
                            height: 1in;
                            margin-right: 0.1in;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                        }
                        .sticker-qr img {
                            max-width: 100%;
                            max-height: 100%;
                            object-fit: contain;
                        }
                        .sticker-info {
                            flex-grow: 1;
                            font-size: 8pt;
                            line-height: 1.2;
                            color: #000 !important;
                        }
                        .sticker-info p {
                            margin: 0;
                            padding: 0;
                            color: #000 !important;
                        }
                        .sticker-info strong {
                            color: #000 !important;
                        }
                        .print-page {
                            display: flex;
                            flex-wrap: wrap;
                            justify-content: flex-start;
                            align-items: flex-start;
                        }
                        @page {
                            size: letter;
                            margin: 0.25in;
                        }
                    </style>
                </head>
                <body>
                    <div class="print-controls no-print">
                        <button onclick="window.print(); window.opener.handleBulkPrint(${JSON.stringify(clientIds)});" class="print-button">
                            Print All Stickers
            </button>
        </div>
                    <div class="print-area print-page">
                        ${stickersHTML}
</div>
                </body>
                </html>
            `;

            printWindow.document.write(printHTML);
            printWindow.document.close();
        });

        // Function to handle bulk print tracking and refresh
        window.handleBulkPrint = function(clientIds) {
            clientIds.forEach(id => trackPrint(id));
            setTimeout(() => {
                fetchAndDisplayQRCodes(clientSelectQR.value === 'all' ? null : clientSelectQR.value);
            }, 2000);
        };

        // Initial load
        fetchClientsForQR();
        fetchAndDisplayQRCodes();
        
        // Listen for changes on the client select
        clientSelectQR.addEventListener('change', () => {
            const selectedClientId = clientSelectQR.value;
            if (selectedClientId) {
                fetchAndDisplayQRCodes(selectedClientId === 'all' ? null : selectedClientId);
            } else {
                qrCodeDisplayArea.innerHTML = '';
                displayedQRData = [];
                printAllStickersBtn.disabled = true;
                showQRMessage('Please select a client or "All Active Clients".', 'info');
            }
});

// Auto-scroll to billing cycles section if hash is present
window.addEventListener('load', function() {
    // Check if hash contains billing-cycles (works with query params too)
    if (window.location.hash && window.location.hash.includes('billing-cycles')) {
        setTimeout(function() {
            const element = document.getElementById('billing-cycles');
            if (element) {
                element.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }, 100); // Small delay to ensure page is fully loaded
    }
});

// Delete Cycle Modal Handler
document.addEventListener('DOMContentLoaded', function() {
    const deleteCycleModal = document.getElementById('deleteCycleModal');
    if (deleteCycleModal) {
        deleteCycleModal.addEventListener('show.bs.modal', function(event) {
            // Button that triggered the modal
            const button = event.relatedTarget;
            // Extract info from data-bs-* attributes
            const cycleId = button.getAttribute('data-cycle-id');
            const cycleName = button.getAttribute('data-cycle-name');
            const cycleReadings = button.getAttribute('data-cycle-readings');
            
            // Update modal content
            document.getElementById('delete_cycle_id').value = cycleId;
            document.getElementById('delete_cycle_name').textContent = cycleName;
            document.getElementById('delete_cycle_readings').textContent = cycleReadings;
            
            // Clear password and checkbox
            document.getElementById('delete_admin_password').value = '';
            document.getElementById('delete_confirm_check').checked = false;
            document.getElementById('delete_password_status').innerHTML = '';
        });
        
        // Handle form submission
        const deleteCycleForm = document.getElementById('deleteCycleForm');
        if (deleteCycleForm) {
            deleteCycleForm.addEventListener('submit', function(e) {
                const password = document.getElementById('delete_admin_password').value;
                const confirmCheck = document.getElementById('delete_confirm_check').checked;
                
                if (!password || password.trim() === '') {
                    e.preventDefault();
                    document.getElementById('delete_password_status').innerHTML = 
                        '<div class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>Password is required</div>';
                    return false;
                }
                
                if (!confirmCheck) {
                    e.preventDefault();
                    alert('Please confirm that you understand this action cannot be undone.');
                    return false;
                }
                
                // Form will submit normally
                return true;
            });
        }
    }
});

// Billing Cycle Management JavaScript
document.addEventListener('DOMContentLoaded', function() {
    // Auto-fill dates when start date is selected
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    const dueDateInput = document.getElementById('due_date');
    
    if (startDateInput) {
        startDateInput.addEventListener('change', function() {
            const startDate = new Date(this.value);
            if (startDate) {
                // Set end date to last day of the same month
                const endDate = new Date(startDate.getFullYear(), startDate.getMonth() + 1, 0);
                
                // Set due date to 15 days after end date
                const dueDate = new Date(endDate);
                dueDate.setDate(dueDate.getDate() + 15);
                
                endDateInput.value = endDate.toISOString().split('T')[0];
                dueDateInput.value = dueDate.toISOString().split('T')[0];
            }
        });
    }
    
    // Generate suggested cycle name based on start date
    if (startDateInput) {
        startDateInput.addEventListener('change', function() {
            const startDate = new Date(this.value);
            if (startDate) {
                const monthNames = ["January", "February", "March", "April", "May", "June",
                                  "July", "August", "September", "October", "November", "December"];
                const month = monthNames[startDate.getMonth()];
                const year = startDate.getFullYear();
                const suggestedName = `${month} ${year} Billing`;
                
                const cycleNameInput = document.getElementById('cycle_name');
                if (cycleNameInput && !cycleNameInput.value) {
                    cycleNameInput.value = suggestedName;
                }
            }
        });
         }
});

// Additional Fees Management JavaScript
function editFee(fee) {
    document.getElementById('edit_fee_id').value = fee.id;
    document.getElementById('edit_fee_name').value = fee.fee_name;
    document.getElementById('edit_fee_type').value = fee.fee_type;
    document.getElementById('edit_fee_amount').value = fee.fee_amount;
    document.getElementById('edit_applies_to').value = fee.applies_to;
    document.getElementById('edit_description').value = fee.description || '';
    
    // Update prefix based on fee type
    updateEditFeePrefix(fee.fee_type);
    
    const editModal = new bootstrap.Modal(document.getElementById('editFeeModal'));
    editModal.show();
}

function updateFeePrefix(type) {
    const prefix = document.getElementById('amount-prefix');
    prefix.textContent = type === 'percentage' ? '%' : '₱';
}

function updateEditFeePrefix(type) {
    const prefix = document.getElementById('edit-amount-prefix');
    prefix.textContent = type === 'percentage' ? '%' : '₱';
}

// Fee type change handlers
document.getElementById('fee_type').addEventListener('change', function() {
    updateFeePrefix(this.value);
});

document.getElementById('edit_fee_type').addEventListener('change', function() {
    updateEditFeePrefix(this.value);
});

function attachTokenCopyHandlers() {
    const buttons = document.querySelectorAll('.copy-token-btn');
    buttons.forEach(button => {
        if (button.dataset.copyBound === '1') {
            return;
        }

        button.addEventListener('click', function() {
            const token = this.getAttribute('data-token');
            if (!token) {
                return;
            }

            const copyToClipboard = async (text) => {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    await navigator.clipboard.writeText(text);
                } else {
                    const tempInput = document.createElement('textarea');
                    tempInput.value = text;
                    tempInput.style.position = 'fixed';
                    tempInput.style.opacity = '0';
                    document.body.appendChild(tempInput);
                    tempInput.focus();
                    tempInput.select();
                    document.execCommand('copy');
                    document.body.removeChild(tempInput);
                }
            };

            copyToClipboard(token).then(() => {
                const originalLabel = this.innerHTML;
                this.innerHTML = '<i class="fas fa-check me-1"></i>Copied';
                this.classList.remove('btn-outline-secondary');
                this.classList.add('btn-success');

                setTimeout(() => {
                    this.innerHTML = originalLabel || 'Copy';
                    this.classList.add('btn-outline-secondary');
                    this.classList.remove('btn-success');
                }, 2000);
            }).catch(() => {
                alert('Unable to copy token automatically. Please copy it manually.');
            });
        });

        button.dataset.copyBound = '1';
    });
}

document.addEventListener('DOMContentLoaded', function() {
    attachTokenCopyHandlers();

    const mobileUsersModal = document.getElementById('mobileUsersModal');
    if (mobileUsersModal) {
        mobileUsersModal.addEventListener('shown.bs.modal', attachTokenCopyHandlers);
    }
    
    // Initialize SMS fields on page load
    if (document.getElementById('sms_provider')) {
        updateSMSFields();
    }
});

// SMS Settings - Update fields based on provider selection
function updateSMSFields() {
    const provider = document.getElementById('sms_provider').value;
    
    // Hide all provider fields
    const allFields = document.querySelectorAll('.provider-fields');
    allFields.forEach(field => {
        field.style.display = 'none';
    });
    
    // Show fields for selected provider
    switch(provider) {
        case 'semaphore':
            document.getElementById('semaphore_fields').style.display = 'block';
            break;
        case 'twilio':
            document.getElementById('twilio_fields').style.display = 'block';
            break;
        case 'nexmo':
            document.getElementById('nexmo_fields').style.display = 'block';
            break;
        case 'custom':
            document.getElementById('custom_fields').style.display = 'block';
            break;
    }
}

function toggleScheduleTime(selectEl, wrapper) {
    if (!selectEl || !wrapper) {
        return;
    }
    if (selectEl.value === 'scheduled') {
        wrapper.classList.remove('d-none');
    } else {
        wrapper.classList.add('d-none');
    }
}

function initNotificationSchedulerFields() {
    const schedulePairs = [
        { selectId: 'sms_bill_schedule_mode', wrapperId: 'sms_bill_schedule_time_wrapper' },
        { selectId: 'sms_overdue_schedule_mode', wrapperId: 'sms_overdue_schedule_time_wrapper' },
        { selectId: 'email_bill_schedule_mode', wrapperId: 'email_bill_schedule_time_wrapper' },
        { selectId: 'email_overdue_schedule_mode', wrapperId: 'email_overdue_schedule_time_wrapper' }
    ];

    schedulePairs.forEach(pair => {
        const selectEl = document.getElementById(pair.selectId);
        const wrapper = document.getElementById(pair.wrapperId);
        if (!selectEl || !wrapper) {
            return;
        }
        toggleScheduleTime(selectEl, wrapper);
        selectEl.addEventListener('change', () => toggleScheduleTime(selectEl, wrapper));
    });
}

document.addEventListener('DOMContentLoaded', initNotificationSchedulerFields);

</script>

<!-- Water Rates Modal -->
<div class="modal fade" id="waterRatesModal" tabindex="-1" aria-labelledby="waterRatesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="waterRatesModalLabel">
                    <i class="fas fa-tint me-2"></i>Update Water Rates
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <iframe src="water_rates_modal.php" width="100%" height="600" frameborder="0" style="border: none;"></iframe>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Additional Fees Modal -->
<div class="modal fade" id="additionalFeesModal" tabindex="-1" aria-labelledby="additionalFeesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="additionalFeesModalLabel">
                    <i class="fas fa-plus-circle me-2"></i>Additional Fees Management
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <iframe src="additional_fees_modal.php" width="100%" height="800" frameborder="0" style="border: none;"></iframe>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Mobile Meter Readers Modal -->
<div class="modal fade" id="mobileUsersModal" tabindex="-1" aria-labelledby="mobileUsersModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="mobileUsersModalLabel">
                    <i class="fas fa-mobile-alt me-2"></i>Mobile Meter Reader Accounts
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-4">
                    <div class="col-lg-5">
                        <div class="card shadow-sm">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0"><i class="fas fa-user-plus me-2"></i>Create Mobile Account</h6>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="">
                                    <input type="hidden" name="mobile_action" value="create_user">
                                    <div class="mb-3">
                                        <label for="mobile_username" class="form-label">Username</label>
                                        <input type="text" class="form-control" id="mobile_username" name="mobile_username" placeholder="e.g. reader.juan" required>
                                        <small class="text-muted">Letters, numbers, and ._- only (min 4 characters)</small>
                                    </div>
                                    <div class="mb-3">
                                        <label for="mobile_full_name" class="form-label">Full Name</label>
                                        <input type="text" class="form-control" id="mobile_full_name" name="mobile_full_name" placeholder="Meter reader full name" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="mobile_password" class="form-label">Password</label>
                                        <input type="password" class="form-control" id="mobile_password" name="mobile_password" minlength="6" required>
                                        <small class="text-muted">Share this password with the meter reader for login.</small>
                                    </div>
                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-save me-2"></i>Create Account
                                        </button>
                                    </div>
                                </form>
                                <div class="alert alert-info mt-3 mb-0 small">
                                    <i class="fas fa-info-circle me-1"></i>
                                    New accounts appear in the list on the right with their API token for the mobile app.
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-7">
                        <div class="card shadow-sm">
                            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                <h6 class="mb-0"><i class="fas fa-users me-2"></i>Registered Mobile Readers</h6>
                                <span class="badge bg-primary">
                                    Active: <?php echo $active_mobile_users_count; ?> / Total: <?php echo count($mobile_users); ?>
                                </span>
                            </div>
                            <div class="card-body p-0">
                                <?php if (!empty($mobile_users)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Reader</th>
                                                    <th>API Token</th>
                                                    <th>Status</th>
                                                    <th>Last Login</th>
                                                    <th>Options</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($mobile_users as $user): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?php echo htmlspecialchars($user['full_name']); ?></strong><br>
                                                            <small class="text-muted">@<?php echo htmlspecialchars($user['username']); ?></small><br>
                                                            <small class="text-muted">Created <?php echo date('M d, Y', strtotime($user['created_at'])); ?></small>
                                                        </td>
                                                        <td style="max-width: 280px;">
                                                            <div class="d-flex align-items-center gap-2">
                                                                <span class="font-monospace small token-text" data-token="<?php echo htmlspecialchars($user['api_token']); ?>">
                                                                    <?php echo htmlspecialchars(substr($user['api_token'], 0, 20)); ?>…
                                                                </span>
                                                                <button type="button" class="btn btn-outline-secondary btn-sm copy-token-btn" data-token="<?php echo htmlspecialchars($user['api_token']); ?>">
                                                                    Copy
                                                                </button>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <?php if ($user['status'] === 'active'): ?>
                                                                <span class="badge bg-success">Active</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-secondary">Inactive</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if (!empty($user['last_login_at'])): ?>
                                                                <small><?php echo date('M d, Y h:i A', strtotime($user['last_login_at'])); ?></small>
                                                            <?php else: ?>
                                                                <small class="text-muted">No activity</small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <div class="d-flex flex-wrap gap-2">
                                                                <form method="POST" action="" class="d-inline">
                                                                    <input type="hidden" name="mobile_action" value="reset_token">
                                                                    <input type="hidden" name="mobile_user_id" value="<?php echo (int)$user['id']; ?>">
                                                                    <button type="submit" class="btn btn-outline-primary btn-sm">
                                                                        <i class="fas fa-key me-1"></i>Reset Token
                                                                    </button>
                                                                </form>
                                                                <form method="POST" action="" class="d-inline">
                                                                    <input type="hidden" name="mobile_action" value="toggle_status">
                                                                    <input type="hidden" name="mobile_user_id" value="<?php echo (int)$user['id']; ?>">
                                                                    <input type="hidden" name="new_status" value="<?php echo $user['status'] === 'active' ? 'inactive' : 'active'; ?>">
                                                                    <?php if ($user['status'] === 'active'): ?>
                                                                        <button type="submit" class="btn btn-outline-warning btn-sm">
                                                                            <i class="fas fa-user-slash me-1"></i>Deactivate
                                                                        </button>
                                                                    <?php else: ?>
                                                                        <button type="submit" class="btn btn-outline-success btn-sm">
                                                                            <i class="fas fa-user-check me-1"></i>Activate
                                                                        </button>
                                                                    <?php endif; ?>
                                                                </form>
                                                                <form method="POST" action="" class="d-inline" onsubmit="return confirm('Remove this mobile account? This action cannot be undone.');">
                                                                    <input type="hidden" name="mobile_action" value="delete_user">
                                                                    <input type="hidden" name="mobile_user_id" value="<?php echo (int)$user['id']; ?>">
                                                                    <button type="submit" class="btn btn-outline-danger btn-sm">
                                                                        <i class="fas fa-trash me-1"></i>Delete
                                                                    </button>
                                                                </form>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="p-4 text-center text-muted">
                                        <i class="fas fa-info-circle fa-2x mb-3"></i>
                                        <p class="mb-1">No mobile meter reader accounts yet.</p>
                                        <p class="mb-0">Use the form on the left to create the first account.</p>
                                    </div>
                                <?php endif; ?>
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

<!-- SMS Settings Modal -->
<div class="modal fade" id="smsSettingsModal" tabindex="-1" aria-labelledby="smsSettingsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="smsSettingsModalLabel">
                    <i class="fas fa-sms me-2"></i>SMS Settings
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if (isset($message) && (strpos($message, 'SMS') !== false || strpos($message, 'sms') !== false)): ?>
                    <div class="alert <?php echo $messageClass; ?> alert-dismissible fade show" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="card mb-3">
                        <div class="card-header bg-primary text-white">
                            <h6 class="mb-0"><i class="fas fa-cog me-2"></i>SMS API Configuration</h6>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="sms_enabled" name="sms_enabled" 
                                           <?php echo $sms_enabled ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="sms_enabled">
                                        <strong>Enable SMS Notifications</strong>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="sms_provider" class="form-label">SMS Provider</label>
                                <select class="form-select" id="sms_provider" name="sms_provider" onchange="updateSMSFields()">
                                    <option value="semaphore" <?php echo $sms_provider == 'semaphore' ? 'selected' : ''; ?>>Semaphore (Philippines)</option>
                                    <option value="twilio" <?php echo $sms_provider == 'twilio' ? 'selected' : ''; ?>>Twilio (Global)</option>
                                    <option value="nexmo" <?php echo $sms_provider == 'nexmo' ? 'selected' : ''; ?>>Nexmo/Vonage (Global)</option>
                                    <option value="custom" <?php echo $sms_provider == 'custom' ? 'selected' : ''; ?>>Custom API</option>
                                </select>
                                <small class="form-text text-muted">Choose your SMS service provider</small>
                            </div>
                            
                            <!-- Semaphore Fields -->
                            <div id="semaphore_fields" class="provider-fields" style="display: <?php echo $sms_provider == 'semaphore' ? 'block' : 'none'; ?>;">
                                <div class="mb-3">
                                    <label for="sms_api_key" class="form-label">API Key</label>
                                    <input type="text" class="form-control" id="sms_api_key" name="sms_api_key" 
                                           value="<?php echo htmlspecialchars($sms_api_key); ?>" 
                                           placeholder="Your Semaphore API key">
                                    <small class="form-text text-muted">Get this from <a href="https://semaphore.co" target="_blank">semaphore.co</a></small>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="sms_sender_name" class="form-label">Sender Name</label>
                                    <input type="text" class="form-control" id="sms_sender_name" name="sms_sender_name" 
                                           value="<?php echo htmlspecialchars($sms_sender_name); ?>" 
                                           placeholder="WaterSync" maxlength="11">
                                    <small class="form-text text-muted">Max 11 characters (alphanumeric)</small>
                                </div>
                            </div>
                            
                            <!-- Twilio Fields -->
                            <div id="twilio_fields" class="provider-fields" style="display: <?php echo $sms_provider == 'twilio' ? 'block' : 'none'; ?>;">
                                <div class="mb-3">
                                    <label for="sms_account_sid" class="form-label">Account SID</label>
                                    <input type="text" class="form-control" id="sms_account_sid" name="sms_account_sid" 
                                           value="<?php echo htmlspecialchars($sms_account_sid); ?>" 
                                           placeholder="Your Twilio Account SID">
                                    <small class="form-text text-muted">Get this from <a href="https://twilio.com" target="_blank">twilio.com</a></small>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="sms_auth_token" class="form-label">Auth Token</label>
                                    <input type="password" class="form-control" id="sms_auth_token" name="sms_auth_token" 
                                           value="<?php echo htmlspecialchars($sms_auth_token); ?>" 
                                           placeholder="Your Twilio Auth Token">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="sms_from_number" class="form-label">From Phone Number</label>
                                    <input type="text" class="form-control" id="sms_from_number" name="sms_from_number" 
                                           value="<?php echo htmlspecialchars($sms_from_number); ?>" 
                                           placeholder="+1234567890">
                                    <small class="form-text text-muted">Your Twilio phone number (with country code)</small>
                                </div>
                            </div>
                            
                            <!-- Nexmo/Vonage Fields -->
                            <div id="nexmo_fields" class="provider-fields" style="display: <?php echo $sms_provider == 'nexmo' ? 'block' : 'none'; ?>;">
                                <div class="mb-3">
                                    <label for="sms_api_key" class="form-label">API Key</label>
                                    <input type="text" class="form-control" id="sms_api_key_nexmo" name="sms_api_key" 
                                           value="<?php echo htmlspecialchars($sms_api_key); ?>" 
                                           placeholder="Your Nexmo API key">
                                    <small class="form-text text-muted">Get this from <a href="https://vonage.com" target="_blank">vonage.com</a></small>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="sms_api_secret" class="form-label">API Secret</label>
                                    <input type="password" class="form-control" id="sms_api_secret" name="sms_api_secret" 
                                           value="<?php echo htmlspecialchars($sms_api_secret); ?>" 
                                           placeholder="Your Nexmo API secret">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="sms_from_number" class="form-label">From Number/Name</label>
                                    <input type="text" class="form-control" id="sms_from_number_nexmo" name="sms_from_number" 
                                           value="<?php echo htmlspecialchars($sms_from_number); ?>" 
                                           placeholder="WaterSync or phone number">
                                    <small class="form-text text-muted">Sender ID or phone number</small>
                                </div>
                            </div>
                            
                            <!-- Custom API Fields -->
                            <div id="custom_fields" class="provider-fields" style="display: <?php echo $sms_provider == 'custom' ? 'block' : 'none'; ?>;">
                                <div class="mb-3">
                                    <label for="sms_api_key" class="form-label">API Key/Token</label>
                                    <input type="text" class="form-control" id="sms_api_key_custom" name="sms_api_key" 
                                           value="<?php echo htmlspecialchars($sms_api_key); ?>" 
                                           placeholder="Your custom API key">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="sms_api_secret" class="form-label">API Secret (if required)</label>
                                    <input type="password" class="form-control" id="sms_api_secret_custom" name="sms_api_secret" 
                                           value="<?php echo htmlspecialchars($sms_api_secret); ?>" 
                                           placeholder="Your custom API secret">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="sms_sender_name" class="form-label">Sender Name/ID</label>
                                    <input type="text" class="form-control" id="sms_sender_name_custom" name="sms_sender_name" 
                                           value="<?php echo htmlspecialchars($sms_sender_name); ?>" 
                                           placeholder="WaterSync">
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="sms_test_mode" name="sms_test_mode" 
                                           <?php echo $sms_test_mode ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="sms_test_mode">
                                        Test Mode (Log only, don't send real SMS)
                                    </label>
                                </div>
                            </div>
                            
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Getting Started:</strong><br>
                                1. Choose your SMS provider from the dropdown above<br>
                                2. Sign up for an account with your chosen provider<br>
                                3. Get your API credentials from their dashboard<br>
                                4. Enter your credentials in the fields above<br>
                                5. Test your configuration using the test button below
                            </div>

                            <div class="card mt-3">
                                <div class="card-header bg-dark text-white">
                                    <h6 class="mb-0"><i class="fas fa-clock me-2"></i>Notification Scheduler</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row g-4">
                                        <div class="col-md-6">
                                            <label class="form-label">Bill Creation SMS</label>
                                            <select class="form-select" id="sms_bill_schedule_mode" name="sms_bill_schedule_mode">
                                                <option value="immediate" <?php echo $sms_bill_schedule_mode === 'immediate' ? 'selected' : ''; ?>>Send immediately</option>
                                                <option value="scheduled" <?php echo $sms_bill_schedule_mode === 'scheduled' ? 'selected' : ''; ?>>Send at a specific time</option>
                                            </select>
                                            <div class="mt-2 schedule-time-wrapper <?php echo $sms_bill_schedule_mode === 'scheduled' ? '' : 'd-none'; ?>" id="sms_bill_schedule_time_wrapper">
                                                <label for="sms_bill_schedule_time" class="form-label small text-muted mb-1">Scheduled Time</label>
                                                <input type="time" class="form-control" id="sms_bill_schedule_time" name="sms_bill_schedule_time" value="<?php echo htmlspecialchars($sms_bill_schedule_time); ?>">
                                                <small class="text-muted">The reminder cron can run hourly; SMS will only send when it matches this time.</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Overdue SMS Reminders</label>
                                            <select class="form-select" id="sms_overdue_schedule_mode" name="sms_overdue_schedule_mode">
                                                <option value="immediate" <?php echo $sms_overdue_schedule_mode === 'immediate' ? 'selected' : ''; ?>>Send whenever script runs</option>
                                                <option value="scheduled" <?php echo $sms_overdue_schedule_mode === 'scheduled' ? 'selected' : ''; ?>>Send at a specific time</option>
                                            </select>
                                            <div class="mt-2 schedule-time-wrapper <?php echo $sms_overdue_schedule_mode === 'scheduled' ? '' : 'd-none'; ?>" id="sms_overdue_schedule_time_wrapper">
                                                <label for="sms_overdue_schedule_time" class="form-label small text-muted mb-1">Scheduled Time</label>
                                                <input type="time" class="form-control" id="sms_overdue_schedule_time" name="sms_overdue_schedule_time" value="<?php echo htmlspecialchars($sms_overdue_schedule_time); ?>">
                                                <small class="text-muted">Use this to batch all overdue SMS into a single daily send.</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h6 class="mb-0"><i class="fas fa-vial me-2"></i>Test SMS</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="test_sms_name" class="form-label">Test Name</label>
                                        <input type="text" class="form-control" id="test_sms_name" name="test_sms_name" 
                                               placeholder="John Doe" value="Test User">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="test_phone" class="form-label">Test Phone Number</label>
                                        <input type="tel" class="form-control" id="test_phone" name="test_phone" 
                                               placeholder="+639123456789" required>
                                        <small class="form-text text-muted">Include country code (e.g., +63 for Philippines)</small>
                                    </div>
                                </div>
                            </div>
                            <button type="submit" name="test_sms" class="btn btn-success">
                                <i class="fas fa-paper-plane me-2"></i>Send Test SMS
                            </button>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <button type="submit" name="save_sms_settings" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save SMS Settings
                        </button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Email Settings Modal -->
<div class="modal fade" id="emailSettingsModal" tabindex="-1" aria-labelledby="emailSettingsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="emailSettingsModalLabel">
                    <i class="fas fa-envelope me-2"></i>Email Settings (Verpex/cPanel)
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if (isset($message) && (strpos($message, 'Email') !== false || strpos($message, 'email') !== false)): ?>
                    <div class="alert <?php echo $messageClass; ?> alert-dismissible fade show" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="card mb-3">
                        <div class="card-header bg-info text-white">
                            <h6 class="mb-0"><i class="fas fa-cog me-2"></i>SMTP Configuration</h6>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="email_enabled" name="email_enabled" 
                                           <?php echo $email_enabled ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="email_enabled">
                                        <strong>Enable Email Notifications</strong>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="smtp_host" class="form-label">SMTP Host</label>
                                        <input type="text" class="form-control" id="smtp_host" name="smtp_host" 
                                               value="<?php echo htmlspecialchars($smtp_host); ?>" 
                                               placeholder="mail.yourdomain.com or localhost">
                                        <small class="form-text text-muted">For Verpex: Usually <code>mail.yourdomain.com</code> or <code>localhost</code></small>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="smtp_port" class="form-label">SMTP Port</label>
                                        <input type="number" class="form-control" id="smtp_port" name="smtp_port" 
                                               value="<?php echo htmlspecialchars($smtp_port); ?>" placeholder="587">
                                        <small class="form-text text-muted">Common ports: 587 (TLS) or 465 (SSL)</small>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="smtp_username" class="form-label">Email Address</label>
                                        <input type="email" class="form-control" id="smtp_username" name="smtp_username" 
                                               value="<?php echo htmlspecialchars($smtp_username); ?>" 
                                               placeholder="billing@yourdomain.com">
                                        <small class="form-text text-muted">Your cPanel email account</small>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="smtp_password" class="form-label">Email Password</label>
                                        <input type="password" class="form-control" id="smtp_password" name="smtp_password" 
                                               value="<?php echo htmlspecialchars($smtp_password); ?>" 
                                               placeholder="Your email password">
                                        <small class="form-text text-muted">Password for your email account</small>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="from_email" class="form-label">From Email</label>
                                        <input type="email" class="form-control" id="from_email" name="from_email" 
                                               value="<?php echo htmlspecialchars($from_email); ?>" 
                                               placeholder="billing@yourdomain.com">
                                        <small class="form-text text-muted">Sender email address</small>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="from_name" class="form-label">From Name</label>
                                        <input type="text" class="form-control" id="from_name" name="from_name" 
                                               value="<?php echo htmlspecialchars($from_name); ?>" 
                                               placeholder="WaterSync">
                                        <small class="form-text text-muted">Display name for sent emails</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="email_test_mode" name="email_test_mode" 
                                           <?php echo $email_test_mode ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="email_test_mode">
                                        Test Mode (Log only, don't send real emails)
                                    </label>
                                </div>
                            </div>
                            
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Verpex/cPanel Setup:</strong><br>
                                1. Create an email account in cPanel (e.g., <code>billing@yourdomain.com</code>)<br>
                                2. Use SMTP Host: <code>mail.yourdomain.com</code> or <code>localhost</code><br>
                                3. Port: <code>587</code> (TLS) or <code>465</code> (SSL)<br>
                                4. Use the email address and password you created
                            </div>

                            <div class="card mt-3">
                                <div class="card-header bg-dark text-white">
                                    <h6 class="mb-0"><i class="fas fa-clock me-2"></i>Email Scheduler</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row g-4">
                                        <div class="col-md-6">
                                            <label class="form-label">Bill Creation Emails</label>
                                            <select class="form-select" id="email_bill_schedule_mode" name="email_bill_schedule_mode">
                                                <option value="immediate" <?php echo $email_bill_schedule_mode === 'immediate' ? 'selected' : ''; ?>>Send immediately</option>
                                                <option value="scheduled" <?php echo $email_bill_schedule_mode === 'scheduled' ? 'selected' : ''; ?>>Send at a specific time</option>
                                            </select>
                                            <div class="mt-2 schedule-time-wrapper <?php echo $email_bill_schedule_mode === 'scheduled' ? '' : 'd-none'; ?>" id="email_bill_schedule_time_wrapper">
                                                <label for="email_bill_schedule_time" class="form-label small text-muted mb-1">Scheduled Time</label>
                                                <input type="time" class="form-control" id="email_bill_schedule_time" name="email_bill_schedule_time" value="<?php echo htmlspecialchars($email_bill_schedule_time); ?>">
                                                <small class="text-muted">Pair with an hourly cron to delay sending new-bill emails.</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Overdue Email Reminders</label>
                                            <select class="form-select" id="email_overdue_schedule_mode" name="email_overdue_schedule_mode">
                                                <option value="immediate" <?php echo $email_overdue_schedule_mode === 'immediate' ? 'selected' : ''; ?>>Send whenever script runs</option>
                                                <option value="scheduled" <?php echo $email_overdue_schedule_mode === 'scheduled' ? 'selected' : ''; ?>>Send at a specific time</option>
                                            </select>
                                            <div class="mt-2 schedule-time-wrapper <?php echo $email_overdue_schedule_mode === 'scheduled' ? '' : 'd-none'; ?>" id="email_overdue_schedule_time_wrapper">
                                                <label for="email_overdue_schedule_time" class="form-label small text-muted mb-1">Scheduled Time</label>
                                                <input type="time" class="form-control" id="email_overdue_schedule_time" name="email_overdue_schedule_time" value="<?php echo htmlspecialchars($email_overdue_schedule_time); ?>">
                                                <small class="text-muted">Daily batch time for overdue notices.</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h6 class="mb-0"><i class="fas fa-vial me-2"></i>Test Email</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="test_name" class="form-label">Test Name</label>
                                        <input type="text" class="form-control" id="test_name" name="test_name" 
                                               placeholder="John Doe" value="Test User">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="test_email" class="form-label">Test Email</label>
                                        <input type="email" class="form-control" id="test_email" name="test_email" 
                                               placeholder="test@example.com">
                                    </div>
                                </div>
                            </div>
                            <button type="submit" name="test_email" class="btn btn-success">
                                <i class="fas fa-paper-plane me-2"></i>Send Test Email
                            </button>
                        </div>
                    </div>
                    
                    <div class="card mt-3">
                        <div class="card-header bg-danger text-white">
                            <h6 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Test Overdue Email</h6>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small mb-3">Test the overdue bill email notification format</p>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="test_overdue_name" class="form-label">Customer Name</label>
                                        <input type="text" class="form-control" id="test_overdue_name" name="test_overdue_name" 
                                               placeholder="John Doe" value="Test User">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="test_overdue_email" class="form-label">Test Email</label>
                                        <input type="email" class="form-control" id="test_overdue_email" name="test_overdue_email" 
                                               placeholder="test@example.com">
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="test_overdue_amount" class="form-label">Amount Due</label>
                                        <input type="text" class="form-control" id="test_overdue_amount" name="test_overdue_amount" 
                                               placeholder="1,500.00" value="1,500.00">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="test_overdue_days" class="form-label">Days Overdue</label>
                                        <input type="number" class="form-control" id="test_overdue_days" name="test_overdue_days" 
                                               placeholder="5" value="5" min="1">
                                    </div>
                                </div>
                            </div>
                            <button type="submit" name="test_overdue_email" class="btn btn-danger">
                                <i class="fas fa-paper-plane me-2"></i>Send Overdue Email Test
                            </button>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <button type="submit" name="save_email_settings" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Email Settings
                        </button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

</body>
</html>
