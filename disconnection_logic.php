<?php
include_once 'db.php';

/**
 * Business Logic for Disconnection Notices
 * Defines when and what type of notices to issue
 */

// Disconnection notice thresholds (in days)
define('FIRST_WARNING_DAYS', 30);
define('FINAL_NOTICE_DAYS', 60);
define('DISCONNECTION_ORDER_DAYS', 90);
define('HIGH_BALANCE_THRESHOLD', 5000.00);
define('MULTIPLE_BILLS_THRESHOLD', 3);

/**
 * Check if a client needs any disconnection notices
 * @param int $client_id
 * @return array Array of notice recommendations
 */
function checkClientForDisconnectionNotices($client_id, $billing_cycle_id = null) {
    global $conn;
    $notices_needed = [];
    
    // Get all unpaid bills for the client with overdue information
    $sql = "SELECT b.*, 
                   DATEDIFF(CURRENT_DATE, b.due_date) as overdue_days,
                   COALESCE(SUM(p.amount), 0) as amount_paid,
                   COALESCE(b.total - COALESCE(SUM(p.amount), 0), b.total) as remaining_balance
            FROM billing_list b
            LEFT JOIN payment_list p ON b.id = p.billing_id AND p.status = 1
            WHERE b.client_id = ? AND b.status = 0";

    // Optional filter by billing cycle
    if (!empty($billing_cycle_id)) {
        $sql .= " AND b.billing_cycle_id = ?";
    }

    $sql .= " GROUP BY b.id
              HAVING remaining_balance > 0 AND overdue_days > 0
              ORDER BY b.due_date ASC";
    
    if (!empty($billing_cycle_id)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $client_id, $billing_cycle_id);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $client_id);
    }
    $stmt->execute();
    $overdue_bills = $stmt->get_result();
    
    if ($overdue_bills->num_rows == 0) {
        return $notices_needed; // No overdue bills
    }
    
    $total_overdue_amount = 0;
    $consecutive_unpaid_count = 0;
    $oldest_overdue_days = 0;
    $oldest_bill = null;
    
    while ($bill = $overdue_bills->fetch_assoc()) {
        $total_overdue_amount += $bill['remaining_balance'];
        $consecutive_unpaid_count++;
        
        if ($bill['overdue_days'] > $oldest_overdue_days) {
            $oldest_overdue_days = $bill['overdue_days'];
            $oldest_bill = $bill;
        }
    }
    
    // Check existing notices to avoid duplicates
    $existing_notices = getExistingNotices($client_id);
    
    // Determine what type of notice is needed based on conditions
    if ($oldest_overdue_days >= DISCONNECTION_ORDER_DAYS) {
        // Disconnection Order - 90+ days overdue
        if (!hasActiveNotice($existing_notices, 'disconnection_order')) {
            $notices_needed[] = [
                'type' => 'disconnection_order',
                'priority' => 'critical',
                'bill' => $oldest_bill,
                'total_amount' => $total_overdue_amount,
                'overdue_days' => $oldest_overdue_days,
                'reason' => 'Bill overdue for ' . $oldest_overdue_days . ' days'
            ];
        }
    } elseif ($oldest_overdue_days >= FINAL_NOTICE_DAYS) {
        // Final Notice - 60+ days overdue
        if (!hasActiveNotice($existing_notices, 'final_notice')) {
            $notices_needed[] = [
                'type' => 'final_notice',
                'priority' => 'high',
                'bill' => $oldest_bill,
                'total_amount' => $total_overdue_amount,
                'overdue_days' => $oldest_overdue_days,
                'reason' => 'Bill overdue for ' . $oldest_overdue_days . ' days'
            ];
        }
    } elseif ($oldest_overdue_days >= FIRST_WARNING_DAYS) {
        // First Warning - 30+ days overdue
        if (!hasActiveNotice($existing_notices, 'first_warning')) {
            $notices_needed[] = [
                'type' => 'first_warning',
                'priority' => 'medium',
                'bill' => $oldest_bill,
                'total_amount' => $total_overdue_amount,
                'overdue_days' => $oldest_overdue_days,
                'reason' => 'Bill overdue for ' . $oldest_overdue_days . ' days'
            ];
        }
    }
    
    // Additional conditions for escalated notices
    
    // Multiple consecutive unpaid bills
    if ($consecutive_unpaid_count >= MULTIPLE_BILLS_THRESHOLD && 
        !hasActiveNotice($existing_notices, 'final_notice') && 
        !hasActiveNotice($existing_notices, 'disconnection_order')) {
        $notices_needed[] = [
            'type' => 'final_notice',
            'priority' => 'high',
            'bill' => $oldest_bill,
            'total_amount' => $total_overdue_amount,
            'overdue_days' => $oldest_overdue_days,
            'reason' => 'Multiple unpaid bills (' . $consecutive_unpaid_count . ' bills)'
        ];
    }
    
    // High outstanding balance
    if ($total_overdue_amount >= HIGH_BALANCE_THRESHOLD && 
        !hasActiveNotice($existing_notices, 'final_notice') && 
        !hasActiveNotice($existing_notices, 'disconnection_order')) {
        $notices_needed[] = [
            'type' => 'final_notice',
            'priority' => 'high',
            'bill' => $oldest_bill,
            'total_amount' => $total_overdue_amount,
            'overdue_days' => $oldest_overdue_days,
            'reason' => 'High outstanding balance: ₱' . number_format($total_overdue_amount, 2)
        ];
    }
    
    return $notices_needed;
}

/**
 * Get existing active notices for a client
 */
function getExistingNotices($client_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM disconnection_notices 
                           WHERE client_id = ? AND status IN ('pending', 'sent') 
                           ORDER BY created_at DESC");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Check if client has an active notice of a specific type
 */
function hasActiveNotice($existing_notices, $notice_type) {
    foreach ($existing_notices as $notice) {
        if ($notice['notice_type'] == $notice_type && 
            in_array($notice['status'], ['pending', 'sent'])) {
            return true;
        }
    }
    return false;
}

/**
 * Create a disconnection notice
 */
function createDisconnectionNotice($client_id, $notice_data, $admin_id) {
    global $conn;
    
    $notice_type = $notice_data['type'];
    $bill = $notice_data['bill'];
    $total_amount = $notice_data['total_amount'];
    $overdue_days = $notice_data['overdue_days'];
    $reason = $notice_data['reason'];
    
    // Generate notice content based on type
    $notice_content = generateNoticeContent($notice_type, $bill, $total_amount, $overdue_days, $reason);
    
    // Calculate disconnection date (grace period)
    $grace_days = ($notice_type == 'disconnection_order') ? 3 : 7;
    $disconnection_date = date('Y-m-d', strtotime("+{$grace_days} days"));
    
    $stmt = $conn->prepare("INSERT INTO disconnection_notices 
                           (client_id, billing_id, notice_type, title, description, 
                            amount_due, overdue_days, due_date, grace_period_days, 
                            disconnection_date, status, created_by) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)");
    
    $stmt->bind_param("iississsisi", 
        $client_id,
        $bill['id'],
        $notice_type,
        $notice_content['title'],
        $notice_content['description'],
        $total_amount,
        $overdue_days,
        $bill['due_date'],
        $grace_days,
        $disconnection_date,
        $admin_id
    );
    
    if ($stmt->execute()) {
        return $stmt->insert_id;
    }
    return false;
}

/**
 * Generate notice content based on type
 */
function generateNoticeContent($notice_type, $bill, $total_amount, $overdue_days, $reason) {
    $content = ['title' => '', 'description' => ''];
    
    switch ($notice_type) {
        case 'first_warning':
            $content['title'] = 'Payment Reminder - Water Bill Overdue';
            $content['description'] = "Dear Valued Customer,\n\n" .
                "This is a friendly reminder that your water bill is now overdue by {$overdue_days} days.\n\n" .
                "Outstanding Amount: ₱" . number_format($total_amount, 2) . "\n" .
                "Original Due Date: " . date('F j, Y', strtotime($bill['due_date'])) . "\n\n" .
                "Reason: {$reason}\n\n" .
                "Please settle your account immediately to avoid service interruption. " .
                "You have 7 days from this notice to make payment before further action is taken.\n\n" .
                "For questions or payment arrangements, please contact our office immediately.";
            break;
            
        case 'final_notice':
            $content['title'] = 'FINAL NOTICE - Water Service Disconnection Warning';
            $content['description'] = "URGENT NOTICE TO CUSTOMER,\n\n" .
                "This is your FINAL NOTICE regarding your overdue water bill account.\n\n" .
                "Outstanding Amount: ₱" . number_format($total_amount, 2) . "\n" .
                "Days Overdue: {$overdue_days} days\n" .
                "Original Due Date: " . date('F j, Y', strtotime($bill['due_date'])) . "\n\n" .
                "Reason: {$reason}\n\n" .
                "IMMEDIATE PAYMENT REQUIRED: Your water service will be DISCONNECTED if payment " .
                "is not received within 7 days from this notice date.\n\n" .
                "To avoid disconnection, please pay the full amount immediately or contact our office " .
                "to arrange a payment plan.";
            break;
            
        case 'disconnection_order':
            $disconnection_date = date('F j, Y', strtotime('+3 days'));
            $content['title'] = 'DISCONNECTION ORDER - Water Service Termination';
            $content['description'] = "NOTICE OF WATER SERVICE DISCONNECTION\n\n" .
                "Your water service will be DISCONNECTED on {$disconnection_date} due to non-payment.\n\n" .
                "Outstanding Amount: ₱" . number_format($total_amount, 2) . "\n" .
                "Days Overdue: {$overdue_days} days\n" .
                "Original Due Date: " . date('F j, Y', strtotime($bill['due_date'])) . "\n\n" .
                "Reason: {$reason}\n\n" .
                "FINAL WARNING: You have 3 days to settle your account to prevent disconnection. " .
                "After disconnection, additional reconnection fees will apply.\n\n" .
                "Contact our office IMMEDIATELY to avoid service termination.";
            break;
    }
    
    return $content;
}

/**
 * Get all clients who need disconnection notices
 */
function getAllClientsNeedingNotices($billing_cycle_id = null) {
    global $conn;
    
    // Get all clients with overdue bills (optionally filtered by billing cycle)
    $sql = "SELECT DISTINCT cl.id, cl.firstname, cl.lastname, cl.contact, cl.address
            FROM client_list cl
            JOIN billing_list b ON cl.id = b.client_id
            WHERE cl.status = 1 
            AND b.status = 0 
            AND b.due_date < CURRENT_DATE";

    if (!empty($billing_cycle_id)) {
        $sql .= " AND b.billing_cycle_id = ?";
        $sql .= " ORDER BY cl.lastname, cl.firstname";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $billing_cycle_id);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $sql .= " ORDER BY cl.lastname, cl.firstname";
        $result = $conn->query($sql);
    }
    $clients_needing_notices = [];
    
    while ($client = $result->fetch_assoc()) {
        $notices_needed = checkClientForDisconnectionNotices($client['id'], $billing_cycle_id);
        if (!empty($notices_needed)) {
            $client['notices_needed'] = $notices_needed;
            $clients_needing_notices[] = $client;
        }
    }
    
    return $clients_needing_notices;
}

/**
 * Auto-generate notices for all eligible clients
 */
function autoGenerateDisconnectionNotices($admin_id, $billing_cycle_id = null) {
    $clients = getAllClientsNeedingNotices($billing_cycle_id);
    $generated_count = 0;
    
    foreach ($clients as $client) {
        foreach ($client['notices_needed'] as $notice_data) {
            if (createDisconnectionNotice($client['id'], $notice_data, $admin_id)) {
                $generated_count++;
            }
        }
    }
    
    return $generated_count;
}

?> 