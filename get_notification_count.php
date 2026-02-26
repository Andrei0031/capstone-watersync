<?php
/**
 * Get notification count for current user
 * Returns JSON with count of new notifications
 */
session_start();
include 'db.php';

$count = 0;
$response = ['count' => 0, 'success' => true];

try {
    // Check if user is logged in
    if (isset($_SESSION['client_id'])) {
        // Customer/Client view - count only service notices created after last time they opened Notices page
        $client_id = $_SESSION['client_id'];
        $latest_notice_at = null;
        $notices_since = isset($_SESSION['notices_last_viewed_at']) ? $conn->real_escape_string($_SESSION['notices_last_viewed_at']) : null;
        $notices_since_sql = $notices_since ? " AND created_at > '$notices_since'" : "";

        // Count active notices (optionally only since last view)
        $notices_query = "
            SELECT COUNT(*) as count, MAX(created_at) as latest_at
            FROM notices 
            WHERE (status = 'ongoing' OR 
                  (status = 'scheduled' AND start_date <= DATE_ADD(NOW(), INTERVAL 24 HOUR)) OR
                  (status = 'completed' AND end_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)))
            AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            $notices_since_sql
        ";
        $notices_result = $conn->query($notices_query);
        if ($notices_result) {
            $notices_row = $notices_result->fetch_assoc();
            $notices_count = $notices_row['count'] ?? 0;
            $latest_notice_at = $notices_row['latest_at'] ?? null;
            $count += $notices_count;
        }

        // Build a stable client event key so UI can detect "new" notifications
        $latest_ts = 0;
        if (!empty($latest_notice_at)) {
            $latest_ts = max($latest_ts, strtotime($latest_notice_at) ?: 0);
        }
        $response['event_key'] = 'client-' . $client_id . '-' . $latest_ts . '-' . intval($count);
        $response['latest_timestamp'] = $latest_ts;
        
    } elseif (isset($_SESSION['admin_id'])) {
        // Admin view - count pending items that need attention
        // Count pending meter readings
        $readings_query = "SELECT COUNT(*) as count FROM pending_meter_readings WHERE status = 'pending'";
        $readings_result = $conn->query($readings_query);
        if ($readings_result) {
            $readings_count = $readings_result->fetch_assoc()['count'];
            $count += $readings_count;
        }
        
        // Count unresolved client reports
        $reports_query = "SELECT COUNT(*) as count FROM client_reports WHERE status = 0";
        $reports_result = $conn->query($reports_query);
        if ($reports_result) {
            $reports_count = $reports_result->fetch_assoc()['count'];
            $count += $reports_count;
        }
        
        // Count pending disconnection notices
        $disconnection_query = "SELECT COUNT(*) as count FROM disconnection_notices WHERE status = 'pending'";
        $disconnection_result = $conn->query($disconnection_query);
        if ($disconnection_result) {
            $disconnection_count = $disconnection_result->fetch_assoc()['count'];
            $count += $disconnection_count;
        }
    }
    
    $response['count'] = $count;
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['error'] = $e->getMessage();
}

header('Content-Type: application/json');
echo json_encode($response);
?>


