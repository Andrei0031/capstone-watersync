<?php
/**
 * Email Workaround for local development
 * Provides alternative email solutions when a local mail server is not available
 */

class EmailWorkaround {
    
    public static function sendEmail($to, $subject, $message, $from_email, $from_name) {
        // Check if we're in development mode first
        if (self::isDevelopmentMode()) {
            return self::handleXAMPPEmail($to, $subject, $message, $from_email, $from_name);
        }
        
        // Check if we're in XAMPP environment
        if (self::isXAMPP()) {
            return self::handleXAMPPEmail($to, $subject, $message, $from_email, $from_name);
        }
        
        // Try regular mail() function for production
        return self::sendRegularEmail($to, $subject, $message, $from_email, $from_name);
    }
    
    private static function isXAMPP() {
        // Check if we're running in XAMPP
        return (strpos($_SERVER['SERVER_SOFTWARE'], 'Apache') !== false && 
                strpos($_SERVER['DOCUMENT_ROOT'], 'xampp') !== false);
    }
    
    private static function handleXAMPPEmail($to, $subject, $message, $from_email, $from_name) {
        // Check if we're in development mode (XAMPP) vs production
        if (self::isDevelopmentMode()) {
            // For development, don't try to send email - just return guidance
            return [
                'status' => 'development_limitation',
                'message' => 'Email sending limited in development environment',
                'error' => 'This will work when deployed to web hosting with proper SMTP configuration.',
                'suggestion' => 'Deploy to Namecheap/cPanel hosting for full email functionality',
                'details' => "Email would be sent to: $to\nSubject: $subject\nFrom: $from_name <$from_email>"
            ];
        }
        
        // For production, try regular email
        return self::sendRegularEmail($to, $subject, $message, $from_email, $from_name);
    }
    
    private static function isDevelopmentMode() {
        // Check if we're in development (XAMPP) vs production
        $is_xampp = isset($_SERVER['DOCUMENT_ROOT']) && strpos($_SERVER['DOCUMENT_ROOT'], 'xampp') !== false;
        $is_localhost = isset($_SERVER['HTTP_HOST']) && (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false);
        $is_cli = php_sapi_name() === 'cli';
        
        return $is_xampp || $is_localhost || $is_cli;
    }
    
    private static function sendRegularEmail($to, $subject, $message, $from_email, $from_name) {
        $headers = "From: $from_name <$from_email>\r\n";
        $headers .= "Reply-To: $from_email\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "X-Mailer: WaterSync Notification System\r\n";
        
        $html_message = nl2br($message);
        
        if (mail($to, $subject, $html_message, $headers)) {
            return ['status' => 'sent'];
        } else {
            return [
                'status' => 'failed',
                'error' => 'Mail server not available. Please configure SMTP or use web hosting.'
            ];
        }
    }
    
    public static function getXAMPPGuidance() {
        return [
            'title' => 'XAMPP Email Configuration',
            'message' => 'Local development does not include a mail server by default.',
            'solutions' => [
                [
                    'title' => 'Use Web Hosting (Recommended)',
                    'description' => 'Deploy your application to Namecheap/cPanel hosting that supports SMTP',
                    'steps' => [
                        'Upload your files to web hosting',
                        'Configure SMTP settings in the admin panel',
                        'Test email notifications'
                    ]
                ],
                [
                    'title' => 'Configure XAMPP with Mail Server',
                    'description' => 'Set up a mail server for XAMPP (Advanced)',
                    'steps' => [
                        'Install Mercury Mail Server for XAMPP',
                        'Configure SMTP settings',
                        'Test email functionality'
                    ]
                ],
                [
                    'title' => 'Use Third-Party Email Service',
                    'description' => 'Use services like SendGrid, Mailgun, or similar',
                    'steps' => [
                        'Sign up for email service',
                        'Get API credentials',
                        'Configure in notification settings'
                    ]
                ]
            ]
        ];
    }
}
?>
