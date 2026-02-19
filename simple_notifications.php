<?php
/**
 * Simple SMS & Email Notification System for WaterSync
 * Currently in DUMMY mode - can be upgraded to real APIs later
 */

function getNotificationSettingValue($key, $default = '') {
    global $conn;
    $stmt = $conn->prepare("SELECT setting_value FROM notification_settings WHERE setting_key = ? LIMIT 1");
    if (!$stmt) {
        return $default;
    }
    $stmt->bind_param("s", $key);
    if (!$stmt->execute()) {
        $stmt->close();
        return $default;
    }
    $result = $stmt->get_result();
    $value = $result && $result->num_rows > 0 ? $result->fetch_assoc()['setting_value'] : $default;
    $stmt->close();
    if (is_string($value)) {
        $value = trim($value);
    }
    return $value;
}

function getSMSProviderSettingsCache() {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [
        'provider' => getNotificationSettingValue('sms_provider', 'dummy'),
        'sender_name' => getNotificationSettingValue('sms_sender_name', 'WaterSync'),
        'philsms_api_token' => getNotificationSettingValue('sms_philsms_api_token', ''),
        'philsms_group_id' => getNotificationSettingValue('sms_philsms_group_id', ''),
        'philsms_sync_contacts' => getNotificationSettingValue('sms_philsms_sync_contacts', '0'),
        'iprogsms_api_token' => getNotificationSettingValue('sms_iprogsms_api_token', ''),
        'iprogsms_provider' => getNotificationSettingValue('sms_iprogsms_provider', '')
    ];
    return $cache;
}

function normalizePhilSMSNumber($phone) {
    $digits = preg_replace('/\D+/', '', $phone);
    if (empty($digits)) {
        return '';
    }
    if (strpos($digits, '63') === 0) {
        return $digits;
    }
    if ($digits[0] === '0') {
        return '63' . substr($digits, 1);
    }
    if ($digits[0] === '9' && strlen($digits) === 10) {
        return '63' . $digits;
    }
    return $digits;
}

function maybeSyncPhilSMSContact($phone, $settings, $contactDetails = []) {
    if (($settings['philsms_sync_contacts'] ?? '0') !== '1') {
        return;
    }
    $groupId = $settings['philsms_group_id'] ?? '';
    $apiToken = $settings['philsms_api_token'] ?? '';
    if (empty($groupId) || empty($apiToken)) {
        return;
    }
    $url = "https://dashboard.philsms.com/api/v3/contacts/{$groupId}/store";
    $payload = [
        'phone' => $phone
    ];
    if (!empty($contactDetails['first_name'])) {
        $payload['first_name'] = $contactDetails['first_name'];
    }
    if (!empty($contactDetails['last_name'])) {
        $payload['last_name'] = $contactDetails['last_name'];
    }
    $headers = [
        'Authorization: Bearer ' . $apiToken,
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    if ($response === false) {
        error_log('PhilSMS contact sync failed: ' . curl_error($ch));
    }
    curl_close($ch);
}

function sendSMSViaPhilSMS($phone, $message, $contactDetails = []) {
    $settings = getSMSProviderSettingsCache();
    $apiToken = $settings['philsms_api_token'] ?? '';
    if (empty($apiToken)) {
        return [
            'success' => false,
            'status' => 'failed',
            'error' => 'PhilSMS API token is missing'
        ];
    }
    $recipient = normalizePhilSMSNumber($phone);
    if (empty($recipient)) {
        return [
            'success' => false,
            'status' => 'failed',
            'error' => 'Invalid phone number'
        ];
    }

    if (!empty($settings['philsms_group_id'])) {
        maybeSyncPhilSMSContact($recipient, $settings, $contactDetails);
    }

    $payload = [
        'recipient' => $recipient,
        'message' => $message
    ];

    $headers = [
        'Authorization: Bearer ' . $apiToken,
        'Content-Type: application/json',
        'Accept: application/json'
    ];

    $ch = curl_init('https://dashboard.philsms.com/api/v3/sms/send');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return [
            'success' => false,
            'status' => 'failed',
            'error' => 'cURL error: ' . $curl_error
        ];
    }

    $decoded = json_decode($response, true);
    if ($http_code >= 200 && $http_code < 300 && isset($decoded['status']) && $decoded['status'] === 'success') {
        return [
            'success' => true,
            'status' => 'sent',
            'provider_response' => $decoded
        ];
    }

    $error_message = $decoded['message'] ?? ('HTTP ' . $http_code);
    return [
        'success' => false,
        'status' => 'failed',
        'error' => $error_message,
        'provider_response' => $decoded
    ];
}

function sendSMSViaIprogSMS($phone, $message) {
    $settings = getSMSProviderSettingsCache();
    $apiToken = $settings['iprogsms_api_token'] ?? '';
    if (empty($apiToken)) {
        return [
            'success' => false,
            'status' => 'failed',
            'error' => 'IPROGSMS API token is missing'
        ];
    }

    $recipient = normalizePhilSMSNumber($phone);
    if (empty($recipient)) {
        return [
            'success' => false,
            'status' => 'failed',
            'error' => 'Invalid phone number'
        ];
    }

    $payload = [
        'api_token' => $apiToken,
        'phone_number' => $recipient,
        'message' => $message
    ];
    if ($settings['iprogsms_provider'] !== '' && $settings['iprogsms_provider'] !== null) {
        $payload['sms_provider'] = intval($settings['iprogsms_provider']);
    }

    $ch = curl_init('https://www.iprogsms.com/api/v1/sms_messages');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return [
            'success' => false,
            'status' => 'failed',
            'error' => 'cURL error: ' . $curl_error
        ];
    }

    $decoded = json_decode($response, true);
    if ($http_code >= 200 && $http_code < 300 && isset($decoded['status']) && (int)$decoded['status'] === 200) {
        return [
            'success' => true,
            'status' => 'sent',
            'provider_response' => $decoded
        ];
    }

    $error_message = $decoded['message'] ?? ('HTTP ' . $http_code);
    return [
        'success' => false,
        'status' => 'failed',
        'error' => $error_message,
        'provider_response' => $decoded
    ];
}

// Simple notification function
function sendBillingNotification($client_id, $bill_id, $event_type = 'bill_approved') {
    global $conn;
    
    try {
        // Get client information - check both client_list and customer_accounts for registered customers
        $stmt = $conn->prepare("
            SELECT 
                cl.id, cl.firstname, cl.lastname, cl.contact as phone, cl.email,
                ca.email as registered_email, ca.id as account_id
            FROM client_list cl
            LEFT JOIN customer_accounts ca ON cl.id = ca.client_id
            WHERE cl.id = ? AND cl.status = 1
        ");
        $stmt->bind_param("i", $client_id);
        $stmt->execute();
        $client = $stmt->get_result()->fetch_assoc();
        
        if (!$client) {
            return ['success' => false, 'error' => 'Client not found'];
        }
        
        // Use registered email if available, otherwise use client email
        $email_to_use = !empty($client['registered_email']) ? $client['registered_email'] : $client['email'];
        $is_registered = !empty($client['account_id']);
        
        // Send notifications to customers with email addresses (registered or not)
        // Prefer registered email, but fall back to client email if available
        if (empty($email_to_use)) {
            return ['success' => false, 'error' => 'Customer has no email address'];
        }
        
        // Get bill information
        $stmt = $conn->prepare("SELECT * FROM billing_list WHERE id = ?");
        $stmt->bind_param("i", $bill_id);
        $stmt->execute();
        $bill = $stmt->get_result()->fetch_assoc();
        
        if (!$bill) {
            return ['success' => false, 'error' => 'Bill not found'];
        }
        
        $customer_name = $client['firstname'] . ' ' . $client['lastname'];
        $first_name = $client['firstname'];
        $amount = number_format($bill['total'], 2);
        $due_date = date('M d, Y', strtotime($bill['due_date']));
        $reading_date = isset($bill['reading_date']) ? $bill['reading_date'] : date('Y-m-d');
        $billing_month = date('F Y', strtotime($reading_date)); // e.g., "November 2025"
        $billing_month_short = date('M Y', strtotime($reading_date)); // e.g., "Feb 2026" for SMS
        $consumption = $bill['reading'] - $bill['previous'];
        
        // Check if bill is overdue
        $is_overdue = false;
        $days_overdue = 0;
        $overdue_message = '';
        if ($bill['status'] == 0 && !empty($bill['due_date'])) {
            $due_timestamp = strtotime($bill['due_date']);
            $current_timestamp = time();
            if ($current_timestamp > $due_timestamp) {
                $is_overdue = true;
                $days_overdue = floor(($current_timestamp - $due_timestamp) / (60 * 60 * 24));
                $overdue_message = "\n⚠️ OVERDUE: This bill is {$days_overdue} day(s) overdue. Please pay immediately to avoid disconnection.";
            }
        }
        
        $results = [];
        
        // Send SMS if phone number exists
        if (!empty($client['phone'])) {
            $sms_overdue = $is_overdue ? "OVERDUE! " : "";
            $sms_message = "Hi $first_name! $billing_month_short bill ₱$amount | {$consumption} m³. Due $due_date. {$sms_overdue}Pay on time to avoid disconnection. Thank you!";
            $sms_result = sendDummySMS($client['phone'], $sms_message, [
                'first_name' => $client['firstname'],
                'last_name' => $client['lastname']
            ]);
            $results['sms'] = $sms_result;
            
            // Log SMS notification
            logNotification($client_id, $bill_id, 'sms', $client['phone'], $sms_message, $sms_result['status']);
        }
        
        // Send Email if email exists (prefer registered email)
        if (!empty($email_to_use)) {
            // Remove peso sign from subject to avoid encoding issues - use PHP instead
            $subject_overdue = $is_overdue ? " - OVERDUE by {$days_overdue} day(s)" : "";
            $email_subject = "Water Bill Created for $billing_month - Amount Due: PHP $amount$subject_overdue";
            $email_message = "Dear $customer_name,\n\nYour water bill has been created:\n\n" .
                           "Billing Month: $billing_month\n" .
                           "Amount Due: ₱$amount\n" .
                           "Due Date: $due_date\n" .
                           "Current Reading: {$bill['reading']}\n" .
                           "Previous Reading: {$bill['previous']}\n" .
                           "Consumption: $consumption cubic meters" .
                           ($is_overdue ? $overdue_message : "") . "\n\n" .
                           "Please pay on or before the due date to avoid late fees.\n\n" .
                           "Thank you,\nWaterSync Team";
            
            $email_result = sendDummyEmail($email_to_use, $email_subject, $email_message);
            $results['email'] = $email_result;
            
            // Log detailed email notification result
            $log_status = $email_result['status'] ?? 'unknown';
            if (!$email_result['success']) {
                $log_status .= '_failed';
                error_log("EMAIL NOTIFICATION FAILED for client $client_id, bill $bill_id: " . ($email_result['error'] ?? 'Unknown error'));
            }
            logNotification($client_id, $bill_id, 'email', $email_to_use, $email_message, $log_status);
        }
        
        return ['success' => true, 'results' => $results];
        
    } catch (Exception $e) {
        error_log("Notification error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// SMS helper that automatically routes to the configured provider
function sendDummySMS($phone, $message, $contactDetails = []) {
    $settings = getSMSProviderSettingsCache();
    if (($settings['provider'] ?? '') === 'philsms') {
        return sendSMSViaPhilSMS($phone, $message, $contactDetails);
    }
    if (($settings['provider'] ?? '') === 'iprogsms') {
        return sendSMSViaIprogSMS($phone, $message);
    }

    return [
        'success' => true,
        'status' => 'dummy_sent',
        'message' => 'SMS logged successfully (dummy mode)',
        'phone' => $phone,
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

// Email function - supports both dummy mode and real email sending
function sendDummyEmail($email, $subject, $message) {
    // Set to true to enable real email sending, false for dummy mode
    $ENABLE_REAL_EMAIL = true; // ENABLED for production deployment
    
    if (!$ENABLE_REAL_EMAIL) {
        // Dummy mode - logs but doesn't send
        error_log("EMAIL (DUMMY MODE): To: $email | Subject: $subject");
        return [
            'success' => true,
            'status' => 'dummy_sent',
            'message' => 'Email logged successfully (dummy mode)',
            'email' => $email,
            'subject' => $subject,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    // Real email sending using PHP mail() function
    // Note: This requires proper mail server configuration
    // For production, you may need to configure SMTP or use a mail service
    
    // Use a valid email address - IMPORTANT: This must be a real email address on your domain
    // Change this to your actual email address (e.g., admin@yourdomain.com or info@yourdomain.com)
    $domain = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'brgymalitbog-watersync.site';
    // Use a real email address that exists on your domain, not noreply@
    $from_email = 'brgymali@brgymalitbog-watersync.site'; // Use your actual working email address
    $from_name = 'New Malitbog WaterSync'; // Email only; SMS sender configured in Settings
    
    // Prepare headers with proper encoding
    $headers = "From: $from_name <$from_email>\r\n";
    $headers .= "Reply-To: $from_email\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    $headers .= "MIME-Version: 1.0";
    
    // Encode subject line properly to avoid invalid characters
    $encoded_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    
    // Clear any previous errors
    error_clear_last();
    
    // Send email with properly encoded subject to avoid invalid character errors
    $sent = mail($email, $encoded_subject, $message, $headers);
    
    // Get the last error if any
    $last_error = error_get_last();
    $error_message = $last_error ? $last_error['message'] : null;
    
    // Log detailed information
    error_log("EMAIL ATTEMPT: To: $email | Subject: $subject | From: $from_email | Sent: " . ($sent ? 'YES' : 'NO') . ($error_message ? " | Error: $error_message" : ''));
    
    if ($sent) {
        // Note: mail() returning true doesn't guarantee delivery
        // It only means PHP accepted the request
        // Log the attempt with full details for debugging
        error_log("EMAIL QUEUED: To: $email | From: $from_email | Subject: $subject | Status: Accepted by PHP mail()");
        
        return [
            'success' => true,
            'status' => 'sent',
            'message' => 'Email queued for sending (check inbox and spam folder)',
            'email' => $email,
            'subject' => $subject,
            'from' => $from_email,
            'timestamp' => date('Y-m-d H:i:s'),
            'note' => 'PHP mail() accepted the request. Check spam folder if not received. For reliable delivery, configure SMTP.'
        ];
    } else {
        // Email sending failed
        $error_msg = $error_message ?? 'Mail server not configured or unavailable';
        error_log("EMAIL FAILED: To: $email | Subject: $subject | Error: $error_msg");
        
        return [
            'success' => false,
            'status' => 'failed',
            'message' => 'Failed to send email. Server mail configuration may be missing.',
            'error' => $error_msg,
            'email' => $email,
            'subject' => $subject,
            'from' => $from_email,
            'timestamp' => date('Y-m-d H:i:s'),
            'suggestion' => 'Configure SMTP settings or use a mail service provider (SendGrid, Mailgun, etc.)'
        ];
    }
}

// Log notifications to database
function logNotification($client_id, $bill_id, $type, $recipient, $message, $status) {
    global $conn;
    
    // Create notifications table if it doesn't exist
    $create_table = "CREATE TABLE IF NOT EXISTS notification_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NOT NULL,
        bill_id INT NULL,
        notification_type ENUM('sms', 'email') NOT NULL,
        recipient VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        status VARCHAR(50) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(client_id),
        INDEX(bill_id),
        FOREIGN KEY (client_id) REFERENCES client_list(id) ON DELETE CASCADE ON UPDATE CASCADE,
        FOREIGN KEY (bill_id) REFERENCES billing_list(id) ON DELETE SET NULL ON UPDATE CASCADE
    )";
    $conn->query($create_table);
    
    // Insert log entry
    $stmt = $conn->prepare("INSERT INTO notification_logs (client_id, bill_id, notification_type, recipient, message, status) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iissss", $client_id, $bill_id, $type, $recipient, $message, $status);
    $stmt->execute();
}

// Get notification logs for admin view
function getNotificationLogs($limit = 50) {
    global $conn;
    
    // Create notifications table if it doesn't exist
    $create_table = "CREATE TABLE IF NOT EXISTS notification_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NOT NULL,
        bill_id INT NULL,
        notification_type ENUM('sms', 'email') NOT NULL,
        recipient VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        status VARCHAR(50) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(client_id),
        INDEX(bill_id),
        FOREIGN KEY (client_id) REFERENCES client_list(id) ON DELETE CASCADE ON UPDATE CASCADE,
        FOREIGN KEY (bill_id) REFERENCES billing_list(id) ON DELETE SET NULL ON UPDATE CASCADE
    )";
    $conn->query($create_table);
    
    // Check if table has any records
    $count_result = $conn->query("SELECT COUNT(*) as count FROM notification_logs");
    $count = $count_result->fetch_assoc()['count'];
    
    if ($count == 0) {
        return []; // Return empty array if no logs exist
    }
    
    $sql = "SELECT nl.*, CONCAT(cl.firstname, ' ', cl.lastname) as client_name, bl.total as bill_amount
            FROM notification_logs nl
            LEFT JOIN client_list cl ON nl.client_id = cl.id
            LEFT JOIN billing_list bl ON nl.bill_id = bl.id
            ORDER BY nl.created_at DESC
            LIMIT ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Send test notification
function sendTestNotification($client_id) {
    global $conn;
    
    // Create a dummy bill for testing
    $test_data = [
        'id' => 'TEST-' . time(),
        'total' => 150.00,
        'due_date' => date('Y-m-d', strtotime('+7 days')),
        'reading' => 1250,
        'previous' => 1235
    ];
    
    // Temporarily create test bill data
    $stmt = $conn->prepare("SELECT firstname, lastname, phone, email FROM client_list WHERE id = ?");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $client = $stmt->get_result()->fetch_assoc();
    
    if (!$client) {
        return ['success' => false, 'error' => 'Client not found'];
    }
    
    $customer_name = $client['firstname'] . ' ' . $client['lastname'];
    $results = [];
    
    // Send test SMS
    if (!empty($client['phone'])) {
        $sms_message = "TEST: Hi $customer_name! This is a test notification from WaterSync. Your system is working properly!";
        $sms_result = sendDummySMS($client['phone'], $sms_message, [
            'first_name' => $client['firstname'] ?? '',
            'last_name' => $client['lastname'] ?? ''
        ]);
        $results['sms'] = $sms_result;
        logNotification($client_id, null, 'sms', $client['phone'], $sms_message, $sms_result['status']);
    }
    
    // Send test Email
    if (!empty($client['email'])) {
        $email_subject = "Test Notification from WaterSync";
        $email_message = "Dear $customer_name,\n\nThis is a test notification to verify your WaterSync notification system is working properly.\n\nIf you received this message, your notifications are set up correctly!\n\nBest regards,\nWaterSync Team";
        $email_result = sendDummyEmail($client['email'], $email_subject, $email_message);
        $results['email'] = $email_result;
        logNotification($client_id, null, 'email', $client['email'], $email_message, $email_result['status']);
    }
    
    return ['success' => true, 'results' => $results];
} 