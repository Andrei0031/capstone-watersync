<?php
/**
 * Simple SMS & Email Notification System for WaterSync
 * Currently in DUMMY mode - can be upgraded to real APIs later
 */

// Simple notification function
function sendBillingNotification($client_id, $bill_id, $event_type = 'bill_approved') {
    global $conn;
    
    try {
        // Get client information
        $stmt = $conn->prepare("SELECT firstname, lastname, phone, email FROM client_list WHERE id = ?");
        $stmt->bind_param("i", $client_id);
        $stmt->execute();
        $client = $stmt->get_result()->fetch_assoc();
        
        if (!$client) {
            return ['success' => false, 'error' => 'Client not found'];
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
        $amount = number_format($bill['total'], 2);
        $due_date = date('M d, Y', strtotime($bill['due_date']));
        $consumption = $bill['reading'] - $bill['previous'];
        
        $results = [];
        
        // Send SMS if phone number exists
        if (!empty($client['phone'])) {
            $sms_message = "Hi $customer_name! Your water bill has been approved. Amount: ₱$amount. Due: $due_date. Consumption: {$consumption} cubic meters. Thank you! - WaterSync";
            $sms_result = sendDummySMS($client['phone'], $sms_message);
            $results['sms'] = $sms_result;
            
            // Log SMS notification
            logNotification($client_id, $bill_id, 'sms', $client['phone'], $sms_message, $sms_result['status']);
        }
        
        // Send Email if email exists
        if (!empty($client['email'])) {
            $email_subject = "Water Bill Approved - Amount Due: ₱$amount";
            $email_message = "Dear $customer_name,\n\nYour water bill has been approved:\n\n" .
                           "Bill ID: $bill_id\n" .
                           "Amount Due: ₱$amount\n" .
                           "Due Date: $due_date\n" .
                           "Current Reading: {$bill['reading']}\n" .
                           "Previous Reading: {$bill['previous']}\n" .
                           "Consumption: $consumption cubic meters\n\n" .
                           "Please pay on or before the due date to avoid late fees.\n\n" .
                           "Thank you,\nWaterSync Team";
            
            $email_result = sendDummyEmail($client['email'], $email_subject, $email_message);
            $results['email'] = $email_result;
            
            // Log Email notification
            logNotification($client_id, $bill_id, 'email', $client['email'], $email_message, $email_result['status']);
        }
        
        return ['success' => true, 'results' => $results];
        
    } catch (Exception $e) {
        error_log("Notification error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Dummy SMS function (replace with real API later)
function sendDummySMS($phone, $message) {
    // This is dummy mode - no actual SMS sent
    return [
        'success' => true,
        'status' => 'dummy_sent',
        'message' => 'SMS logged successfully (dummy mode)',
        'phone' => $phone,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    // When ready for real SMS API, replace above with:
    /*
    // Example for real SMS API:
    $api_key = 'your_sms_api_key';
    $sender = 'WaterSync';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.semaphore.co/api/v4/messages'); // Example API
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'apikey' => $api_key,
        'number' => $phone,
        'message' => $message,
        'sendername' => $sender
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'success' => $http_code == 200,
        'status' => $http_code == 200 ? 'sent' : 'failed',
        'response' => $response
    ];
    */
}

// Dummy Email function (replace with real API later)
function sendDummyEmail($email, $subject, $message) {
    // This is dummy mode - no actual email sent
    return [
        'success' => true,
        'status' => 'dummy_sent',
        'message' => 'Email logged successfully (dummy mode)',
        'email' => $email,
        'subject' => $subject,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    // When ready for real Email API, replace above with:
    /*
    // Example using PHP mail() function:
    $headers = "From: noreply@watersync.com\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    $sent = mail($email, $subject, $message, $headers);
    
    return [
        'success' => $sent,
        'status' => $sent ? 'sent' : 'failed',
        'message' => $sent ? 'Email sent successfully' : 'Failed to send email'
    ];
    */
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
        $sms_result = sendDummySMS($client['phone'], $sms_message);
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
?> 