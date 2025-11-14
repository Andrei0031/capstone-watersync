CREATE TABLE IF NOT EXISTS customer_accounts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_id INT NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    status TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    FOREIGN KEY (client_id) REFERENCES client_list(id),
    INDEX (email)
); 