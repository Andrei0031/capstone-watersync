<?php
/**
 * Automated Payment Reminder and Overdue Notifications
 * Run this via cron job daily to send reminders
 */

include 'db.php';
include 'simple_notifications.php';

// Ensure notification_settings table exists (shared with settings page)
$createSettingsTable = "
    CREATE TABLE IF NOT EXISTS notification_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
$conn->query($createSettingsTable);

function getNotificationSetting($conn, $key, $default = '')
{
    $stmt = $conn->prepare("SELECT setting_value FROM notification_settings WHERE setting_key = ?");
    if (!$stmt) {
        return $default;
    }
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $row = $result->fetch_assoc()) {
        return $row['setting_value'];
    }
    return $default;
}

function scheduleAllowsSend($mode, $scheduled_time, $current_time)
{
    if ($mode === 'immediate' || $mode === '' || $mode === null) {
        return true;
    }
    if ($mode === 'scheduled') {
        return !empty($scheduled_time) && $scheduled_time === $current_time;
    }
    if ($mode === 'disabled') {
        return false;
    }
    return true;
}

$sms_enabled = getNotificationSetting($conn, 'sms_enabled', '1') === '1';
$email_enabled = getNotificationSetting($conn, 'email_enabled', '1') === '1';

$sms_bill_schedule_mode = getNotificationSetting($conn, 'sms_bill_schedule_mode', 'immediate');
$sms_bill_schedule_time = getNotificationSetting($conn, 'sms_bill_schedule_time', '08:00');
$sms_overdue_schedule_mode = getNotificationSetting($conn, 'sms_overdue_schedule_mode', 'scheduled');
$sms_overdue_schedule_time = getNotificationSetting($conn, 'sms_overdue_schedule_time', '09:00');

$email_bill_schedule_mode = getNotificationSetting($conn, 'email_bill_schedule_mode', 'immediate');
$email_bill_schedule_time = getNotificationSetting($conn, 'email_bill_schedule_time', '08:00');
$email_overdue_schedule_mode = getNotificationSetting($conn, 'email_overdue_schedule_mode', 'scheduled');
$email_overdue_schedule_time = getNotificationSetting($conn, 'email_overdue_schedule_time', '09:00');

$current_time = date('H:i');
$sms_bill_schedule_ok = scheduleAllowsSend($sms_bill_schedule_mode, $sms_bill_schedule_time, $current_time);
$sms_overdue_schedule_ok = scheduleAllowsSend($sms_overdue_schedule_mode, $sms_overdue_schedule_time, $current_time);
$email_bill_schedule_ok = scheduleAllowsSend($email_bill_schedule_mode, $email_bill_schedule_time, $current_time);
$email_overdue_schedule_ok = scheduleAllowsSend($email_overdue_schedule_mode, $email_overdue_schedule_time, $current_time);

// Get all unpaid bills
$unpaid_bills_sql = "
    SELECT 
        b.id as bill_id,
        b.client_id,
        b.due_date,
        b.total,
        b.reading_date,
        DATEDIFF(CURRENT_DATE(), b.due_date) as days_overdue,
        cl.firstname,
        cl.lastname,
        cl.contact as phone,
        cl.email,
        ca.email as registered_email,
        ca.id as account_id
    FROM billing_list b
    JOIN client_list cl ON b.client_id = cl.id
    LEFT JOIN customer_accounts ca ON cl.id = ca.client_id
    WHERE b.status = 0
    AND cl.status = 1
    AND ca.id IS NOT NULL  -- Only registered customers
    ORDER BY b.due_date ASC
";

$result = $conn->query($unpaid_bills_sql);
$notifications_sent = 0;

while ($bill = $result->fetch_assoc()) {
    $days_overdue = $bill['days_overdue'];
    $is_overdue = $days_overdue > 0;
    $days_until_due = -$days_overdue; // Negative means days until due
    
    $email_to_use = !empty($bill['registered_email']) ? $bill['registered_email'] : $bill['email'];
    $customer_name = $bill['firstname'] . ' ' . $bill['lastname'];
    $amount = number_format($bill['total'], 2);
    $due_date = date('M d, Y', strtotime($bill['due_date']));
    
    // Send payment deadline reminder (3 days before due date)
    if ($days_until_due == 3 && !$is_overdue) {
        $sent_any = false;
        // SMS
        if ($sms_enabled && $sms_bill_schedule_ok && !empty($bill['phone'])) {
            $sms_message = "Hi {$bill['firstname']}! Bill ₱$amount due in 3 days ($due_date). Pay on time to avoid disconnection. Thank you!";
            sendDummySMS($bill['phone'], $sms_message, [
                'first_name' => $bill['firstname'],
                'last_name' => $bill['lastname']
            ]);
            logNotification($bill['client_id'], $bill['bill_id'], 'sms', $bill['phone'], $sms_message, 'sent');
            $sent_any = true;
        }
        
        // Email
        if ($email_enabled && $email_bill_schedule_ok && !empty($email_to_use)) {
            $email_subject = "Payment Reminder - Bill Due in 3 Days";
            $email_message = "Dear $customer_name,\n\nThis is a reminder that your water bill payment is due in 3 days:\n\n" .
                           "Amount Due: ₱$amount\n" .
                           "Due Date: $due_date\n\n" .
                           "Please make your payment on or before the due date to avoid late fees.\n\n" .
                           "Thank you,\nWaterSync Team";
            sendDummyEmail($email_to_use, $email_subject, $email_message);
            logNotification($bill['client_id'], $bill['bill_id'], 'email', $email_to_use, $email_message, 'sent');
            $sent_any = true;
        }
        if ($sent_any) {
            $notifications_sent++;
        }
    }
    
    // Send overdue notifications (daily for overdue bills)
    if ($is_overdue) {
        $sent_any_overdue = false;
        // SMS
        if ($sms_enabled && $sms_overdue_schedule_ok && !empty($bill['phone'])) {
            $sms_message = "Hi {$bill['firstname']}! Bill ₱$amount OVERDUE! Pay now to avoid disconnection. Thank you!";
            sendDummySMS($bill['phone'], $sms_message, [
                'first_name' => $bill['firstname'],
                'last_name' => $bill['lastname']
            ]);
            logNotification($bill['client_id'], $bill['bill_id'], 'sms', $bill['phone'], $sms_message, 'sent');
            $sent_any_overdue = true;
        }
        
        // Email
        if ($email_enabled && $email_overdue_schedule_ok && !empty($email_to_use)) {
            $email_subject = "URGENT: Overdue Water Bill - $days_overdue Day(s) Late";
            $email_message = "Dear $customer_name,\n\n" .
                           "URGENT: Your water bill payment is OVERDUE!\n\n" .
                           "Amount Due: ₱$amount\n" .
                           "Due Date: $due_date\n" .
                           "Days Overdue: $days_overdue day(s)\n\n" .
                           "Please pay immediately to avoid late fees and potential service disconnection.\n\n" .
                           "Thank you,\nWaterSync Team";
            sendDummyEmail($email_to_use, $email_subject, $email_message);
            logNotification($bill['client_id'], $bill['bill_id'], 'email', $email_to_use, $email_message, 'sent');
            $sent_any_overdue = true;
        }
        if ($sent_any_overdue) {
            $notifications_sent++;
        }
    }
}

echo "Sent $notifications_sent notifications\n";
?>

