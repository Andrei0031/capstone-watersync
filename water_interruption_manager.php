<?php
/**
 * Water Interruption Management System
 * Handles water service interruptions and notifications
 */

class WaterInterruptionManager {
    private $conn;
    private $notification_manager;
    
    public function __construct($database_connection, $notification_manager) {
        $this->conn = $database_connection;
        $this->notification_manager = $notification_manager;
    }
    
    /**
     * Report a water interruption
     */
    public function reportInterruption($data) {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO water_interruptions 
                (title, description, affected_areas, estimated_restoration, reported_by, status, created_at) 
                VALUES (?, ?, ?, ?, ?, 'active', NOW())
            ");
            
            $stmt->bind_param("sssss", 
                $data['title'], 
                $data['description'], 
                $data['affected_areas'], 
                $data['estimated_restoration'], 
                $data['reported_by']
            );
            
            if ($stmt->execute()) {
                $interruption_id = $stmt->insert_id;
                
                // Send notifications to affected customers
                $this->notifyAffectedCustomers($interruption_id, $data);
                
                return ['success' => true, 'interruption_id' => $interruption_id];
            } else {
                return ['success' => false, 'error' => 'Failed to create interruption record'];
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Update interruption status
     */
    public function updateInterruptionStatus($interruption_id, $status, $notes = '') {
        try {
            $stmt = $this->conn->prepare("
                UPDATE water_interruptions 
                SET status = ?, notes = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            
            $stmt->bind_param("ssi", $status, $notes, $interruption_id);
            
            if ($stmt->execute()) {
                // If service is restored, notify customers
                if ($status === 'resolved') {
                    $this->notifyServiceRestoration($interruption_id);
                }
                
                return ['success' => true];
            } else {
                return ['success' => false, 'error' => 'Failed to update status'];
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get active interruptions
     */
    public function getActiveInterruptions() {
        $stmt = $this->conn->prepare("
            SELECT * FROM water_interruptions 
            WHERE status = 'active' 
            ORDER BY created_at DESC
        ");
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    /**
     * Get interruption history
     */
    public function getInterruptionHistory($limit = 50) {
        $stmt = $this->conn->prepare("
            SELECT * FROM water_interruptions 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    /**
     * Notify affected customers about interruption
     */
    private function notifyAffectedCustomers($interruption_id, $data) {
        $affected_areas = json_decode($data['affected_areas'], true);
        $message = $data['description'];
        $estimated_restoration = $data['estimated_restoration'];
        
        // Send notifications using the notification manager
        $result = $this->notification_manager->sendWaterInterruptionNotification(
            $affected_areas, 
            $message, 
            $estimated_restoration
        );
        
        // Log the notification result
        $this->logInterruptionNotification($interruption_id, 'interruption_notice', $result);
        
        return $result;
    }
    
    /**
     * Notify customers about service restoration
     */
    private function notifyServiceRestoration($interruption_id) {
        $stmt = $this->conn->prepare("SELECT * FROM water_interruptions WHERE id = ?");
        $stmt->bind_param("i", $interruption_id);
        $stmt->execute();
        $interruption = $stmt->get_result()->fetch_assoc();
        
        if ($interruption) {
            $affected_areas = json_decode($interruption['affected_areas'], true);
            $message = "Water service has been restored in your area. We apologize for any inconvenience.";
            
            $result = $this->notification_manager->sendWaterInterruptionNotification(
                $affected_areas, 
                $message, 
                null
            );
            
            $this->logInterruptionNotification($interruption_id, 'service_restored', $result);
        }
    }
    
    /**
     * Log interruption notification
     */
    private function logInterruptionNotification($interruption_id, $type, $result) {
        $stmt = $this->conn->prepare("
            INSERT INTO interruption_notifications 
            (interruption_id, type, result, sent_at) 
            VALUES (?, ?, ?, NOW())
        ");
        
        $result_json = json_encode($result);
        $stmt->bind_param("iss", $interruption_id, $type, $result_json);
        $stmt->execute();
    }
    
    /**
     * Get customers in specific areas
     */
    public function getCustomersInAreas($areas) {
        $placeholders = str_repeat('?,', count($areas) - 1) . '?';
        $stmt = $this->conn->prepare("
            SELECT id, firstname, lastname, phone, email, area 
            FROM client_list 
            WHERE area IN ($placeholders)
        ");
        $stmt->bind_param(str_repeat('s', count($areas)), ...$areas);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    /**
     * Create interruption from admin panel
     */
    public function createInterruptionFromAdmin($admin_id, $title, $description, $affected_areas, $estimated_restoration = '') {
        $data = [
            'title' => $title,
            'description' => $description,
            'affected_areas' => json_encode($affected_areas),
            'estimated_restoration' => $estimated_restoration,
            'reported_by' => 'admin_' . $admin_id
        ];
        
        return $this->reportInterruption($data);
    }
}
?>
