<?php
/**
 * Setup Notification Database Tables
 * Run this once to create the necessary tables
 */

include 'db.php';

echo "<h2>Setting up Notification Database Tables...</h2>";

try {
    // Create notification_settings table
    $sql1 = "CREATE TABLE IF NOT EXISTS notification_settings (
        id INT PRIMARY KEY AUTO_INCREMENT,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT,
        description TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    
    if ($conn->query($sql1)) {
        echo "✅ notification_settings table created successfully<br>";
    } else {
        echo "❌ Error creating notification_settings table: " . $conn->error . "<br>";
    }
    
    // Create water_interruptions table
    $sql2 = "CREATE TABLE IF NOT EXISTS water_interruptions (
        id INT PRIMARY KEY AUTO_INCREMENT,
        title VARCHAR(255) NOT NULL,
        description TEXT NOT NULL,
        affected_areas JSON NOT NULL,
        estimated_restoration VARCHAR(255),
        reported_by VARCHAR(100) NOT NULL,
        status ENUM('active', 'resolved', 'cancelled') DEFAULT 'active',
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (status),
        INDEX (created_at)
    )";
    
    if ($conn->query($sql2)) {
        echo "✅ water_interruptions table created successfully<br>";
    } else {
        echo "❌ Error creating water_interruptions table: " . $conn->error . "<br>";
    }
    
    // Create interruption_notifications table
    $sql3 = "CREATE TABLE IF NOT EXISTS interruption_notifications (
        id INT PRIMARY KEY AUTO_INCREMENT,
        interruption_id INT NOT NULL,
        type ENUM('interruption_notice', 'service_restored') NOT NULL,
        result JSON,
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (interruption_id) REFERENCES water_interruptions(id) ON DELETE CASCADE,
        INDEX (interruption_id),
        INDEX (sent_at)
    )";
    
    if ($conn->query($sql3)) {
        echo "✅ interruption_notifications table created successfully<br>";
    } else {
        echo "❌ Error creating interruption_notifications table: " . $conn->error . "<br>";
    }
    
    // Create notification_logs table
    $sql4 = "CREATE TABLE IF NOT EXISTS notification_logs (
        id INT PRIMARY KEY AUTO_INCREMENT,
        client_id INT,
        bill_id INT,
        interruption_id INT,
        type ENUM('sms', 'email') NOT NULL,
        recipient VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        status ENUM('sent', 'failed', 'pending', 'test_mode', 'disabled') DEFAULT 'pending',
        provider VARCHAR(50),
        provider_response JSON,
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (client_id) REFERENCES client_list(id) ON DELETE CASCADE,
        FOREIGN KEY (bill_id) REFERENCES billing_list(id) ON DELETE CASCADE,
        FOREIGN KEY (interruption_id) REFERENCES water_interruptions(id) ON DELETE CASCADE,
        INDEX (client_id),
        INDEX (bill_id),
        INDEX (interruption_id),
        INDEX (type),
        INDEX (status),
        INDEX (sent_at)
    )";
    
    if ($conn->query($sql4)) {
        echo "✅ notification_logs table created successfully<br>";
    } else {
        echo "❌ Error creating notification_logs table: " . $conn->error . "<br>";
    }
    
    // Insert default notification settings
    $default_settings = [
        ['sms_enabled', '1', 'Enable SMS notifications'],
        ['email_enabled', '1', 'Enable email notifications'],
        ['sms_provider', 'semaphore', 'SMS provider (semaphore, twilio, nexmo)'],
        ['email_provider', 'smtp', 'Email provider (smtp, sendgrid, mailgun)'],
        ['sms_api_key', '', 'SMS API key'],
        ['sms_sender_name', 'WaterSync', 'SMS sender name'],
        ['sms_test_mode', '0', 'SMS test mode (1=log only, 0=send real)'],
        ['smtp_host', 'mail.yourdomain.com', 'SMTP host'],
        ['smtp_port', '587', 'SMTP port'],
        ['smtp_username', '', 'SMTP username'],
        ['smtp_password', '', 'SMTP password'],
        ['from_email', '', 'From email address'],
        ['from_name', 'WaterSync', 'From name'],
        ['email_test_mode', '0', 'Email test mode (1=log only, 0=send real)'],
        ['billing_notifications', '1', 'Send billing notifications'],
        ['payment_reminders', '1', 'Send payment reminders'],
        ['interruption_notifications', '1', 'Send water interruption notifications'],
        ['test_mode', '0', 'Global test mode (1=log only, 0=send real notifications)']
    ];
    
    $stmt = $conn->prepare("
        INSERT INTO notification_settings (setting_key, setting_value, description) 
        VALUES (?, ?, ?) 
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    
    foreach ($default_settings as $setting) {
        $stmt->bind_param("sss", $setting[0], $setting[1], $setting[2]);
        if ($stmt->execute()) {
            echo "✅ Default setting '{$setting[0]}' inserted<br>";
        } else {
            echo "❌ Error inserting setting '{$setting[0]}': " . $stmt->error . "<br>";
        }
    }
    
    // Add area/zone field to client_list if not exists
    $sql5 = "ALTER TABLE client_list 
             ADD COLUMN IF NOT EXISTS area VARCHAR(100) DEFAULT 'Zone 1',
             ADD COLUMN IF NOT EXISTS phone VARCHAR(20),
             ADD COLUMN IF NOT EXISTS email VARCHAR(255)";
    
    if ($conn->query($sql5)) {
        echo "✅ client_list table updated with area, phone, email fields<br>";
    } else {
        echo "❌ Error updating client_list table: " . $conn->error . "<br>";
    }
    
    // Create indexes for better performance
    $indexes = [
        "CREATE INDEX IF NOT EXISTS idx_client_area ON client_list(area)",
        "CREATE INDEX IF NOT EXISTS idx_client_phone ON client_list(phone)",
        "CREATE INDEX IF NOT EXISTS idx_client_email ON client_list(email)"
    ];
    
    foreach ($indexes as $index_sql) {
        if ($conn->query($index_sql)) {
            echo "✅ Index created successfully<br>";
        } else {
            echo "❌ Error creating index: " . $conn->error . "<br>";
        }
    }
    
    echo "<br><h3>🎉 Database setup completed!</h3>";
    echo "<p><strong>Next steps:</strong></p>";
    echo "<ul>";
    echo "<li>Go to <a href='notification_settings_admin.php'>Notification Settings</a> to configure your APIs</li>";
    echo "<li>Add phone numbers and email addresses to your customers</li>";
    echo "<li>Test your notification system</li>";
    echo "</ul>";
    
} catch (Exception $e) {
    echo "❌ Database setup failed: " . $e->getMessage();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup - WaterSync</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="card">
            <div class="card-header bg-success text-white">
                <h4 class="mb-0">
                    <i class="fas fa-database me-2"></i>Database Setup Complete
                </h4>
            </div>
            <div class="card-body">
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i>
                    <strong>Success!</strong> All notification database tables have been created successfully.
                </div>
                
                <h5>What was created:</h5>
                <ul>
                    <li><strong>notification_settings</strong> - Stores all API configuration</li>
                    <li><strong>water_interruptions</strong> - Tracks water service issues</li>
                    <li><strong>interruption_notifications</strong> - Logs interruption alerts</li>
                    <li><strong>notification_logs</strong> - Tracks all sent notifications</li>
                    <li><strong>client_list updates</strong> - Added area, phone, email fields</li>
                </ul>
                
                <div class="mt-4">
                    <a href="notification_settings_admin.php" class="btn btn-primary">
                        <i class="fas fa-cog me-2"></i>Configure Notification Settings
                    </a>
                    <a href="view_clients.php" class="btn btn-secondary">
                        <i class="fas fa-users me-2"></i>Manage Customers
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
