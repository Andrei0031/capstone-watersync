-- Create notification tables for WaterSync

-- Water interruptions table
CREATE TABLE IF NOT EXISTS water_interruptions (
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
);

-- Interruption notifications log
CREATE TABLE IF NOT EXISTS interruption_notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    interruption_id INT NOT NULL,
    type ENUM('interruption_notice', 'service_restored') NOT NULL,
    result JSON,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (interruption_id) REFERENCES water_interruptions(id) ON DELETE CASCADE,
    INDEX (interruption_id),
    INDEX (sent_at)
);

-- Enhanced notification logs (if not exists)
CREATE TABLE IF NOT EXISTS notification_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_id INT,
    bill_id INT,
    interruption_id INT,
    type ENUM('sms', 'email') NOT NULL,
    recipient VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('sent', 'failed', 'pending') DEFAULT 'pending',
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
);

-- Notification settings table
CREATE TABLE IF NOT EXISTS notification_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default notification settings
INSERT INTO notification_settings (setting_key, setting_value, description) VALUES
('sms_enabled', '1', 'Enable SMS notifications'),
('email_enabled', '1', 'Enable email notifications'),
('sms_provider', 'semaphore', 'SMS provider (semaphore, twilio, nexmo)'),
('email_provider', 'smtp', 'Email provider (smtp, sendgrid, mailgun)'),
('billing_notifications', '1', 'Send billing notifications'),
('payment_reminders', '1', 'Send payment reminders'),
('interruption_notifications', '1', 'Send water interruption notifications'),
('test_mode', '0', 'Test mode (1=log only, 0=send real notifications)')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- Add area/zone field to client_list if not exists
ALTER TABLE client_list 
ADD COLUMN IF NOT EXISTS area VARCHAR(100) DEFAULT 'Zone 1',
ADD COLUMN IF NOT EXISTS phone VARCHAR(20),
ADD COLUMN IF NOT EXISTS email VARCHAR(255);

-- Create index for area-based queries
CREATE INDEX IF NOT EXISTS idx_client_area ON client_list(area);
CREATE INDEX IF NOT EXISTS idx_client_phone ON client_list(phone);
CREATE INDEX IF NOT EXISTS idx_client_email ON client_list(email);
