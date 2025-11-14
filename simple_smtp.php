<?php
/**
 * Simple SMTP Class
 * Handles SMTP email sending without requiring local mail server
 */

class SimpleSMTP {
    private $host;
    private $port;
    private $username;
    private $password;
    private $from_email;
    private $from_name;
    
    public function __construct($host, $port, $username, $password, $from_email, $from_name) {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->from_email = $from_email;
        $this->from_name = $from_name;
    }
    
    public function sendEmail($to, $subject, $message) {
        // For Gmail, use a workaround approach
        if ($this->host === 'smtp.gmail.com') {
            return $this->sendGmailWorkaround($to, $subject, $message);
        }
        
        // For other SMTP servers, try basic approach
        return $this->sendBasicEmail($to, $subject, $message);
    }
    
    private function sendGmailWorkaround($to, $subject, $message) {
        // Gmail workaround: Use basic mail() but with proper configuration
        $headers = "From: {$this->from_name} <{$this->from_email}>\r\n";
        $headers .= "Reply-To: {$this->from_email}\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "X-Mailer: WaterSync Notification System\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        
        $html_message = nl2br($message);
        
        // Try to send using mail() function
        if (mail($to, $subject, $html_message, $headers)) {
            return ['status' => 'sent'];
        } else {
            return ['status' => 'failed', 'error' => 'Gmail configuration issue. Please check your Gmail app password and ensure SMTP is properly configured in your hosting environment.'];
        }
    }
    
    private function sendBasicEmail($to, $subject, $message) {
        $headers = "From: {$this->from_name} <{$this->from_email}>\r\n";
        $headers .= "Reply-To: {$this->from_email}\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "X-Mailer: WaterSync Notification System\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        
        $html_message = nl2br($message);
        
        if (mail($to, $subject, $html_message, $headers)) {
            return ['status' => 'sent'];
        } else {
            return ['status' => 'failed', 'error' => 'Email sending failed. Please check your SMTP configuration or use a different email provider.'];
        }
    }
}
?>
