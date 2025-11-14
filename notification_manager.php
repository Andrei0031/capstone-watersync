<?php
/**
 * Real SMS & Email Notification Manager for WaterSync
 * Supports multiple providers and notification types
 */

class NotificationManager {
    private $conn;
    private $sms_provider;
    private $email_provider;
    private $settings;
    
    public function __construct($database_connection) {
        $this->conn = $database_connection;
        $this->loadSettings();
    }
    
    private function loadSettings() {
        // Load notification settings from database
        $this->settings = [
            'sms' => [
                'provider' => $this->getSetting('sms_provider', 'semaphore'),
                'api_key' => $this->getSetting('sms_api_key', ''),
                'sender_name' => $this->getSetting('sms_sender_name', 'WaterSync'),
                'enabled' => $this->getSetting('sms_enabled', '1') == '1',
                'test_mode' => $this->getSetting('sms_test_mode', '0') == '1'
            ],
            'email' => [
                'provider' => $this->getSetting('email_provider', 'smtp'),
                'smtp_host' => $this->getSetting('smtp_host', 'smtp.gmail.com'),
                'smtp_port' => intval($this->getSetting('smtp_port', '587')),
                'smtp_username' => $this->getSetting('smtp_username', ''),
                'smtp_password' => $this->getSetting('smtp_password', ''),
                'from_email' => $this->getSetting('from_email', ''),
                'from_name' => $this->getSetting('from_name', 'WaterSync'),
                'enabled' => $this->getSetting('email_enabled', '1') == '1',
                'test_mode' => $this->getSetting('email_test_mode', '0') == '1'
            ]
        ];
    }
    
    private function getSetting($key, $default = '') {
        $stmt = $this->conn->prepare("SELECT setting_value FROM notification_settings WHERE setting_key = ?");
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            return $result->fetch_assoc()['setting_value'];
        }
        return $default;
    }
    
    /**
     * Send billing notification
     */
    public function sendBillingNotification($client_id, $bill_id, $event_type = 'bill_approved', $send_sms = true, $send_email = true) {
        try {
            // Get client and bill information
            $client = $this->getClientInfo($client_id);
            $bill = $this->getBillInfo($bill_id);
            
            if (!$client || !$bill) {
                return ['success' => false, 'error' => 'Client or bill not found'];
            }
            
            $results = [];
            
            // Send SMS notification (if enabled and requested)
            if ($send_sms && !empty($client['phone']) && $this->settings['sms']['enabled']) {
                $sms_message = $this->generateBillingSMS($client, $bill, $event_type);
                $sms_result = $this->sendSMS($client['phone'], $sms_message);
                $results['sms'] = $sms_result;
                $this->logNotification($client_id, $bill_id, 'sms', $client['phone'], $sms_message, $sms_result['status']);
            }
            
            // Send Email notification (if enabled and requested)
            if ($send_email && !empty($client['email']) && $this->settings['email']['enabled']) {
                $email_data = $this->generateBillingEmail($client, $bill, $event_type);
                $email_result = $this->sendEmail($client['email'], $email_data['subject'], $email_data['message']);
                $results['email'] = $email_result;
                $this->logNotification($client_id, $bill_id, 'email', $client['email'], $email_data['message'], $email_result['status']);
            }
            
            return ['success' => true, 'results' => $results];
            
        } catch (Exception $e) {
            error_log("Billing notification error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Send water interruption notification
     */
    public function sendWaterInterruptionNotification($affected_areas = [], $message = '', $estimated_restoration = '') {
        try {
            $results = [];
            
            // Get all customers in affected areas
            $customers = $this->getCustomersInAreas($affected_areas);
            
            foreach ($customers as $customer) {
                // Send SMS
                if (!empty($customer['phone']) && $this->settings['sms']['enabled']) {
                    $sms_message = $this->generateInterruptionSMS($customer, $message, $estimated_restoration);
                    $sms_result = $this->sendSMS($customer['phone'], $sms_message);
                    $results['sms'][] = ['customer' => $customer['name'], 'result' => $sms_result];
                    $this->logNotification($customer['id'], null, 'sms', $customer['phone'], $sms_message, $sms_result['status']);
                }
                
                // Send Email
                if (!empty($customer['email']) && $this->settings['email']['enabled']) {
                    $email_data = $this->generateInterruptionEmail($customer, $message, $estimated_restoration);
                    $email_result = $this->sendEmail($customer['email'], $email_data['subject'], $email_data['message']);
                    $results['email'][] = ['customer' => $customer['name'], 'result' => $email_result];
                    $this->logNotification($customer['id'], null, 'email', $customer['email'], $email_data['message'], $email_result['status']);
                }
            }
            
            return ['success' => true, 'results' => $results];
            
        } catch (Exception $e) {
            error_log("Water interruption notification error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Send payment reminder
     */
    public function sendPaymentReminder($client_id, $bill_id, $days_overdue = 0) {
        try {
            $client = $this->getClientInfo($client_id);
            $bill = $this->getBillInfo($bill_id);
            
            if (!$client || !$bill) {
                return ['success' => false, 'error' => 'Client or bill not found'];
            }
            
            $results = [];
            
            // Send SMS reminder
            if (!empty($client['phone']) && $this->settings['sms']['enabled']) {
                $sms_message = $this->generatePaymentReminderSMS($client, $bill, $days_overdue);
                $sms_result = $this->sendSMS($client['phone'], $sms_message);
                $results['sms'] = $sms_result;
                $this->logNotification($client_id, $bill_id, 'sms', $client['phone'], $sms_message, $sms_result['status']);
            }
            
            // Send Email reminder
            if (!empty($client['email']) && $this->settings['email']['enabled']) {
                $email_data = $this->generatePaymentReminderEmail($client, $bill, $days_overdue);
                $email_result = $this->sendEmail($client['email'], $email_data['subject'], $email_data['message']);
                $results['email'] = $email_result;
                $this->logNotification($client_id, $bill_id, 'email', $client['email'], $email_data['message'], $email_result['status']);
            }
            
            return ['success' => true, 'results' => $results];
            
        } catch (Exception $e) {
            error_log("Payment reminder error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Send SMS using configured provider
     */
    public function sendSMS($phone, $message) {
        // Check if SMS is enabled
        if (!$this->settings['sms']['enabled']) {
            return ['status' => 'disabled', 'message' => 'SMS notifications are disabled'];
        }
        
        // Check if in test mode
        if ($this->settings['sms']['test_mode']) {
            $this->logNotification(null, null, 'sms', $phone, $message, 'test_mode');
            return ['status' => 'test_mode', 'message' => 'SMS logged in test mode'];
        }
        
        $provider = $this->settings['sms']['provider'];
        
        switch ($provider) {
            case 'semaphore':
                return $this->sendSMSViaSemaphore($phone, $message);
            case 'twilio':
                return $this->sendSMSViaTwilio($phone, $message);
            case 'nexmo':
                return $this->sendSMSViaNexmo($phone, $message);
            default:
                return ['status' => 'failed', 'error' => 'SMS provider not configured'];
        }
    }
    
    /**
     * Send Email using configured provider
     */
    public function sendEmail($email, $subject, $message) {
        // Check if Email is enabled
        if (!$this->settings['email']['enabled']) {
            return ['status' => 'disabled', 'message' => 'Email notifications are disabled'];
        }
        
        // Check if in test mode
        if ($this->settings['email']['test_mode']) {
            $this->logNotification(null, null, 'email', $email, $message, 'test_mode');
            return ['status' => 'test_mode', 'message' => 'Email logged in test mode'];
        }
        
        $provider = $this->settings['email']['provider'];
        
        switch ($provider) {
            case 'smtp':
                return $this->sendEmailViaSMTP($email, $subject, $message);
            case 'sendgrid':
                return $this->sendEmailViaSendGrid($email, $subject, $message);
            case 'mailgun':
                return $this->sendEmailViaMailgun($email, $subject, $message);
            default:
                return ['status' => 'failed', 'error' => 'Email provider not configured'];
        }
    }
    
    /**
     * Semaphore SMS API
     */
    private function sendSMSViaSemaphore($phone, $message) {
        $api_key = $this->settings['sms']['api_key'];
        $sender_name = $this->settings['sms']['sender_name'];
        
        $url = 'https://api.semaphore.co/api/v4/messages';
        $data = [
            'apikey' => $api_key,
            'number' => $phone,
            'message' => $message,
            'sendername' => $sender_name
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code == 200) {
            $result = json_decode($response, true);
            return ['status' => 'sent', 'message_id' => $result[0]['message_id'] ?? null];
        } else {
            return ['status' => 'failed', 'error' => 'HTTP ' . $http_code];
        }
    }
    
    /**
     * Twilio SMS API
     */
    private function sendSMSViaTwilio($phone, $message) {
        $account_sid = $this->settings['sms']['account_sid'];
        $auth_token = $this->settings['sms']['auth_token'];
        $from_number = $this->settings['sms']['from_number'];
        
        $url = "https://api.twilio.com/2010-04-01/Accounts/$account_sid/Messages.json";
        $data = [
            'From' => $from_number,
            'To' => $phone,
            'Body' => $message
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, "$account_sid:$auth_token");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code == 201) {
            $result = json_decode($response, true);
            return ['status' => 'sent', 'message_id' => $result['sid']];
        } else {
            return ['status' => 'failed', 'error' => 'HTTP ' . $http_code];
        }
    }
    
    /**
     * Nexmo SMS API
     */
    private function sendSMSViaNexmo($phone, $message) {
        $api_key = $this->settings['sms']['api_key'];
        $api_secret = $this->settings['sms']['api_secret'];
        $from = $this->settings['sms']['from_number'];
        
        $url = 'https://rest.nexmo.com/sms/json';
        $data = [
            'api_key' => $api_key,
            'api_secret' => $api_secret,
            'to' => $phone,
            'from' => $from,
            'text' => $message
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code == 200) {
            $result = json_decode($response, true);
            if (isset($result['messages'][0]['status']) && $result['messages'][0]['status'] == '0') {
                return ['status' => 'sent', 'message_id' => $result['messages'][0]['message-id']];
            } else {
                return ['status' => 'failed', 'error' => $result['messages'][0]['error-text'] ?? 'Unknown error'];
            }
        } else {
            return ['status' => 'failed', 'error' => 'HTTP ' . $http_code];
        }
    }
    
    /**
     * SMTP Email with XAMPP workaround
     */
    private function sendEmailViaSMTP($email, $subject, $message) {
        $from_email = $this->settings['email']['from_email'];
        $from_name = $this->settings['email']['from_name'];
        
        // Include the email workaround
        require_once 'email_workaround.php';
        
        // Send email using workaround
        return EmailWorkaround::sendEmail($email, $subject, $message, $from_email, $from_name);
    }
    
    /**
     * SendGrid Email API
     */
    private function sendEmailViaSendGrid($email, $subject, $message) {
        $api_key = $this->settings['email']['sendgrid_api_key'];
        $from_email = $this->settings['email']['from_email'];
        $from_name = $this->settings['email']['from_name'];
        
        $url = 'https://api.sendgrid.com/v3/mail/send';
        
        $data = [
            'personalizations' => [
                [
                    'to' => [
                        ['email' => $email]
                    ]
                ]
            ],
            'from' => [
                'email' => $from_email,
                'name' => $from_name
            ],
            'subject' => $subject,
            'content' => [
                [
                    'type' => 'text/html',
                    'value' => nl2br($message)
                ]
            ]
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code == 202) {
            return ['status' => 'sent'];
        } else {
            return ['status' => 'failed', 'error' => 'HTTP ' . $http_code . ': ' . $response];
        }
    }
    
    /**
     * Mailgun Email API
     */
    private function sendEmailViaMailgun($email, $subject, $message) {
        $api_key = $this->settings['email']['mailgun_api_key'];
        $domain = $this->settings['email']['mailgun_domain'];
        $from_email = $this->settings['email']['from_email'];
        
        $url = "https://api.mailgun.net/v3/$domain/messages";
        
        $data = [
            'from' => $from_email,
            'to' => $email,
            'subject' => $subject,
            'html' => nl2br($message)
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, "api:$api_key");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code == 200) {
            $result = json_decode($response, true);
            return ['status' => 'sent', 'message_id' => $result['id'] ?? null];
        } else {
            return ['status' => 'failed', 'error' => 'HTTP ' . $http_code . ': ' . $response];
        }
    }
    
    // Message generation methods
    private function generateBillingSMS($client, $bill, $event_type) {
        $name = $client['firstname'] . ' ' . $client['lastname'];
        $amount = number_format($bill['total'], 2);
        $due_date = date('M d, Y', strtotime($bill['due_date']));
        $consumption = $bill['reading'] - $bill['previous'];
        
        return "Hi $name! Your water bill has been approved. Amount: ₱$amount. Due: $due_date. Consumption: {$consumption} cubic meters. Thank you! - WaterSync";
    }
    
    private function generateBillingEmail($client, $bill, $event_type) {
        $name = $client['firstname'] . ' ' . $client['lastname'];
        $amount = number_format($bill['total'], 2);
        $due_date = date('M d, Y', strtotime($bill['due_date']));
        $consumption = $bill['reading'] - $bill['previous'];
        
        $subject = "Water Bill Approved - ₱$amount";
        $message = "Dear $name,\n\nYour water bill has been approved:\n\n" .
                  "Bill ID: {$bill['id']}\n" .
                  "Amount Due: ₱$amount\n" .
                  "Due Date: $due_date\n" .
                  "Current Reading: {$bill['reading']}\n" .
                  "Previous Reading: {$bill['previous']}\n" .
                  "Consumption: $consumption cubic meters\n\n" .
                  "Please pay on or before the due date to avoid late fees.\n\n" .
                  "Thank you,\nWaterSync Team";
        
        return ['subject' => $subject, 'message' => $message];
    }
    
    private function generateInterruptionSMS($customer, $message, $estimated_restoration) {
        $name = $customer['firstname'] . ' ' . $customer['lastname'];
        $restoration_text = $estimated_restoration ? " Estimated restoration: $estimated_restoration." : "";
        
        return "IMPORTANT: Water service interruption in your area. $message$restoration_text We apologize for the inconvenience. - WaterSync";
    }
    
    private function generateInterruptionEmail($customer, $message, $estimated_restoration) {
        $name = $customer['firstname'] . ' ' . $customer['lastname'];
        $restoration_text = $estimated_restoration ? "\n\nEstimated restoration time: $estimated_restoration" : "";
        
        $subject = "Water Service Interruption Notice";
        $email_message = "Dear $name,\n\n" .
                        "We are writing to inform you of a water service interruption in your area.\n\n" .
                        "Details: $message$restoration_text\n\n" .
                        "We apologize for any inconvenience this may cause and are working to restore service as quickly as possible.\n\n" .
                        "For updates, please contact our customer service.\n\n" .
                        "Thank you for your understanding,\nWaterSync Team";
        
        return ['subject' => $subject, 'message' => $email_message];
    }
    
    private function generatePaymentReminderSMS($client, $bill, $days_overdue) {
        $name = $client['firstname'] . ' ' . $client['lastname'];
        $amount = number_format($bill['total'], 2);
        
        if ($days_overdue > 0) {
            return "REMINDER: Hi $name! Your water bill of ₱$amount is $days_overdue days overdue. Please pay immediately to avoid disconnection. - WaterSync";
        } else {
            return "REMINDER: Hi $name! Your water bill of ₱$amount is due soon. Please pay to avoid late fees. - WaterSync";
        }
    }
    
    private function generatePaymentReminderEmail($client, $bill, $days_overdue) {
        $name = $client['firstname'] . ' ' . $client['lastname'];
        $amount = number_format($bill['total'], 2);
        $due_date = date('M d, Y', strtotime($bill['due_date']));
        
        if ($days_overdue > 0) {
            $subject = "URGENT: Overdue Water Bill - ₱$amount";
            $message = "Dear $name,\n\n" .
                      "Your water bill of ₱$amount is $days_overdue days overdue.\n\n" .
                      "Due Date: $due_date\n" .
                      "Days Overdue: $days_overdue\n\n" .
                      "Please pay immediately to avoid service disconnection.\n\n" .
                      "Thank you,\nWaterSync Team";
        } else {
            $subject = "Payment Reminder - Water Bill Due Soon";
            $message = "Dear $name,\n\n" .
                      "This is a friendly reminder that your water bill of ₱$amount is due on $due_date.\n\n" .
                      "Please pay on time to avoid late fees.\n\n" .
                      "Thank you,\nWaterSync Team";
        }
        
        return ['subject' => $subject, 'message' => $message];
    }
    
    // Helper methods
    private function getClientInfo($client_id) {
        $stmt = $this->conn->prepare("SELECT firstname, lastname, phone, email FROM client_list WHERE id = ?");
        $stmt->bind_param("i", $client_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
    
    private function getBillInfo($bill_id) {
        $stmt = $this->conn->prepare("SELECT * FROM billing_list WHERE id = ?");
        $stmt->bind_param("i", $bill_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
    
    private function getCustomersInAreas($areas) {
        // This would need to be implemented based on your area/zone system
        $stmt = $this->conn->prepare("SELECT id, firstname, lastname, phone, email FROM client_list WHERE area IN ('" . implode("','", $areas) . "')");
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    private function logNotification($client_id, $bill_id, $type, $recipient, $message, $status) {
        $stmt = $this->conn->prepare("INSERT INTO notification_logs (client_id, bill_id, type, recipient, message, status, sent_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("iissss", $client_id, $bill_id, $type, $recipient, $message, $status);
        $stmt->execute();
    }
}
?>
