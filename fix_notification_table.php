<?php
// Fix notification_logs table to have proper foreign key relationships
include 'db.php';

echo "🔧 Fixing notification_logs table relationships...\n";

try {
    // First, drop the existing table if it exists
    $conn->query("DROP TABLE IF EXISTS notification_logs");
    echo "✅ Dropped existing notification_logs table\n";

    // Create the table with proper foreign key relationships
    $create_table = "CREATE TABLE notification_logs (
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
            
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 
      COMMENT='Stores SMS and email notification logs with proper relationships to clients and bills'";

    if ($conn->query($create_table)) {
        echo "✅ Created notification_logs table with proper foreign key relationships\n";
        
        // Verify the relationships
        $check_fk = "SELECT 
            CONSTRAINT_NAME,
            TABLE_NAME,
            COLUMN_NAME,
            REFERENCED_TABLE_NAME,
            REFERENCED_COLUMN_NAME
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
        WHERE TABLE_SCHEMA = 'watersync' 
        AND TABLE_NAME = 'notification_logs' 
        AND REFERENCED_TABLE_NAME IS NOT NULL";
        
        $result = $conn->query($check_fk);
        
        if ($result->num_rows > 0) {
            echo "\n📋 Foreign key relationships created:\n";
            while ($row = $result->fetch_assoc()) {
                echo "   🔗 {$row['COLUMN_NAME']} → {$row['REFERENCED_TABLE_NAME']}.{$row['REFERENCED_COLUMN_NAME']}\n";
            }
        }
        
        echo "\n🎉 SUCCESS! The notification_logs table now has proper relationships.\n";
        echo "   → Your database diagram will now show the relationship lines!\n";
        
    } else {
        throw new Exception("Failed to create table: " . $conn->error);
    }

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "\n💡 You can now refresh your database diagram to see the relationships.\n";
?> 