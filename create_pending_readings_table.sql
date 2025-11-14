CREATE TABLE pending_meter_readings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    upload_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending', 'processed', 'failed') DEFAULT 'pending',
    reading_value DECIMAL(10,2) DEFAULT NULL,
    error_message TEXT,
    processed_date DATETIME DEFAULT NULL,
    mobile_upload_id VARCHAR(50),
    FOREIGN KEY (client_id) REFERENCES client_list(id),
    INDEX (status),
    INDEX (upload_date)
); 