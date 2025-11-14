-- Fix notification_logs table to add proper foreign key relationships
-- Run this to establish proper database relationships

-- First, check if the table exists and drop/recreate it with proper constraints
DROP TABLE IF EXISTS notification_logs;

-- Create the table with proper foreign key relationships
CREATE TABLE notification_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    bill_id INT NULL,
    notification_type ENUM('sms', 'email') NOT NULL,
    recipient VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    status VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Add indexes for better performance
    INDEX idx_client_id (client_id),
    INDEX idx_bill_id (bill_id),
    INDEX idx_created_at (created_at),
    INDEX idx_status (status),
    
    -- Add foreign key constraints for proper relationships
    CONSTRAINT fk_notification_client 
        FOREIGN KEY (client_id) REFERENCES client_list(id) 
        ON DELETE CASCADE ON UPDATE CASCADE,
        
    CONSTRAINT fk_notification_bill 
        FOREIGN KEY (bill_id) REFERENCES billing_list(id) 
        ON DELETE SET NULL ON UPDATE CASCADE
        
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add comments for clarity
ALTER TABLE notification_logs 
    COMMENT = 'Stores SMS and email notification logs with proper relationships to clients and bills'; 