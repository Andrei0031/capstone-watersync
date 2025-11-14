<?php
require_once 'config.php';

validateApiKey();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Only GET method allowed', null, 405);
}

try {
    $stats = [];
    
    // 1. Total Client Count
    $stmt = $conn->prepare("SELECT COUNT(*) as total_clients FROM client_list WHERE delete_flag = 0");
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stats['total_clients'] = (int)$result['total_clients'];
    
    // 2. Active Customer Accounts
    $stmt = $conn->prepare("SELECT COUNT(*) as active_customers FROM customer_accounts WHERE status = 'active'");
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stats['active_customers'] = (int)$result['active_customers'];
    
    // 3. Total Bills This Month
    $stmt = $conn->prepare("
        SELECT COUNT(*) as bills_this_month 
        FROM billing_list 
        WHERE MONTH(reading_date) = MONTH(CURRENT_DATE()) 
        AND YEAR(reading_date) = YEAR(CURRENT_DATE())
    ");
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stats['bills_this_month'] = (int)$result['bills_this_month'];
    
    // 4. Unpaid Bills Count
    $stmt = $conn->prepare("SELECT COUNT(*) as unpaid_bills FROM billing_list WHERE status = 0");
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stats['unpaid_bills'] = (int)$result['unpaid_bills'];
    
    // 5. Paid Bills Count
    $stmt = $conn->prepare("SELECT COUNT(*) as paid_bills FROM billing_list WHERE status = 1");
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stats['paid_bills'] = (int)$result['paid_bills'];
    
    // 6. Recent Activity (last 7 days)
    $stmt = $conn->prepare("
        SELECT COUNT(*) as recent_bills 
        FROM billing_list 
        WHERE reading_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 7 DAY)
    ");
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stats['recent_bills'] = (int)$result['recent_bills'];
    
    // 7. Collection Rate (percentage of paid bills)
    $total_bills = $stats['paid_bills'] + $stats['unpaid_bills'];
    if ($total_bills > 0) {
        $stats['collection_rate'] = round(($stats['paid_bills'] / $total_bills) * 100, 2);
    } else {
        $stats['collection_rate'] = 0;
    }
    
    // 8. System Status
    $stats['system_status'] = 'active';
    $stats['last_updated'] = date('Y-m-d H:i:s');
    
    sendResponse(true, 'Dashboard statistics retrieved successfully', $stats);
    
} catch (Exception $e) {
    sendResponse(false, 'Error retrieving dashboard statistics: ' . $e->getMessage(), null, 500);
}
?> 