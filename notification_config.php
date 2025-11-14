<?php
/**
 * Notification Configuration for WaterSync
 * Update these settings with your API credentials
 */

return [
    // SMS Configuration
    'sms' => [
        'enabled' => true,
        'provider' => 'semaphore', // Options: semaphore, twilio, nexmo
        'api_key' => '', // Add your API key here
        'account_sid' => '', // For Twilio
        'auth_token' => '', // For Twilio
        'from_number' => '', // For Twilio (e.g., +1234567890)
        'sender_name' => 'WaterSync',
        'test_mode' => false // Set to true for testing
    ],
    
    // Email Configuration
    'email' => [
        'enabled' => true,
        'provider' => 'smtp', // Options: smtp, sendgrid, mailgun
        'smtp_host' => 'smtp.gmail.com',
        'smtp_port' => 587,
        'smtp_username' => 'your-email@gmail.com', // Replace with your Gmail address
        'smtp_password' => 'your-app-password-here', // Replace with your 16-character app password
        'from_email' => 'your-email@gmail.com', // Same as your Gmail address
        'from_name' => 'WaterSync',
        'sendgrid_api_key' => '', // For SendGrid
        'mailgun_api_key' => '', // For Mailgun
        'test_mode' => false // Set to true for testing
    ],
    
    // Notification Types
    'notifications' => [
        'billing_approved' => true,
        'payment_reminder' => true,
        'overdue_notice' => true,
        'water_interruption' => true,
        'service_restoration' => true,
        'disconnection_warning' => true
    ],
    
    // Message Templates
    'templates' => [
        'billing_sms' => "Hi {name}! Your water bill has been approved. Amount: ₱{amount}. Due: {due_date}. Consumption: {consumption} cubic meters. Thank you! - WaterSync",
        'billing_email_subject' => "Water Bill Approved - ₱{amount}",
        'interruption_sms' => "IMPORTANT: Water service interruption in your area. {message} {restoration_time} We apologize for the inconvenience. - WaterSync",
        'interruption_email_subject' => "Water Service Interruption Notice",
        'payment_reminder_sms' => "REMINDER: Hi {name}! Your water bill of ₱{amount} is {days} days overdue. Please pay immediately to avoid disconnection. - WaterSync"
    ]
];
?>
