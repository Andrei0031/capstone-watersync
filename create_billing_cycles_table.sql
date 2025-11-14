CREATE TABLE billing_cycles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    cycle_name VARCHAR(100) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    due_date DATE NOT NULL,
    status ENUM('planned', 'active', 'completed', 'cancelled') DEFAULT 'planned',
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    activated_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    description TEXT,
    FOREIGN KEY (created_by) REFERENCES admin(id),
    INDEX (status),
    INDEX (start_date),
    INDEX (end_date)
);

-- Alter pending_meter_readings table to include billing cycle
ALTER TABLE pending_meter_readings 
ADD COLUMN billing_cycle_id INT NULL,
ADD COLUMN reading_date DATE NULL,
ADD FOREIGN KEY (billing_cycle_id) REFERENCES billing_cycles(id);

-- Create index for better performance
CREATE INDEX idx_billing_cycle_readings ON pending_meter_readings (billing_cycle_id, status); 