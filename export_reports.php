<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: adminlogin.php");
    exit();
}

include 'db.php';
include 'comprehensive_fee_manager.php';

$report_type = $_GET['type'] ?? 'dashboard';
$format = $_GET['format'] ?? 'csv';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');

$filename = "water_billing_" . $report_type . "_report_" . date('Y-m-d');

if ($format === 'csv') {
    // Set headers for CSV download (force Save As dialog in most browsers)
    header('Content-Type: application/octet-stream; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Create a file pointer connected to PHP output stream
    $output = fopen('php://output', 'w');
    
    // Export based on report type
    switch ($report_type) {
        case 'collections':
            // Collections Report Export
            fputcsv($output, ['Water Billing System - Collections Report']);
            fputcsv($output, ['Period: ' . date('M d, Y', strtotime($date_from)) . ' to ' . date('M d, Y', strtotime($date_to))]);
            fputcsv($output, []);
            
            // Daily collections
            fputcsv($output, ['DAILY COLLECTIONS']);
            fputcsv($output, ['Date', 'Payment Method', 'Payment Count', 'Total Amount', 'Verified Amount']);
            
            $daily_sql = "SELECT 
                DATE(payment_date) as payment_date,
                payment_method,
                COUNT(*) as payment_count,
                SUM(amount) as total_amount,
                SUM(CASE WHEN status = 1 THEN amount ELSE 0 END) as verified_amount
                FROM payment_list 
                WHERE DATE(payment_date) BETWEEN ? AND ?
                GROUP BY DATE(payment_date), payment_method
                ORDER BY payment_date DESC";
            
            $stmt = $conn->prepare($daily_sql);
            $stmt->bind_param("ss", $date_from, $date_to);
            $stmt->execute();
            $daily_result = $stmt->get_result();
            
            while ($row = $daily_result->fetch_assoc()) {
                fputcsv($output, [
                    date('M d, Y', strtotime($row['payment_date'])),
                    $row['payment_method'],
                    $row['payment_count'],
                    '₱' . number_format($row['total_amount'], 2),
                    '₱' . number_format($row['verified_amount'], 2)
                ]);
            }
            
            fputcsv($output, []);
            
            // Top paying clients
            fputcsv($output, ['TOP PAYING CLIENTS']);
            fputcsv($output, ['Client Name', 'Meter Code', 'Payment Count', 'Total Paid']);
            
            $top_clients_sql = "SELECT 
                cl.firstname, cl.lastname, cl.meter_code,
                COUNT(pl.id) as payment_count,
                SUM(pl.amount) as total_paid
                FROM payment_list pl
                JOIN billing_list bl ON pl.billing_id = bl.id
                JOIN client_list cl ON bl.client_id = cl.id
                WHERE DATE(pl.payment_date) BETWEEN ? AND ? AND pl.status = 1
                GROUP BY cl.id
                ORDER BY total_paid DESC
                LIMIT 20";
            
            $stmt = $conn->prepare($top_clients_sql);
            $stmt->bind_param("ss", $date_from, $date_to);
            $stmt->execute();
            $top_result = $stmt->get_result();
            
            while ($row = $top_result->fetch_assoc()) {
                fputcsv($output, [
                    $row['firstname'] . ' ' . $row['lastname'],
                    $row['meter_code'],
                    $row['payment_count'],
                    '₱' . number_format($row['total_paid'], 2)
                ]);
            }
            break;
            
        case 'clients':
            // Clients Report Export
            fputcsv($output, ['Water Billing System - Clients Report']);
            fputcsv($output, ['Period: ' . date('M d, Y', strtotime($date_from)) . ' to ' . date('M d, Y', strtotime($date_to))]);
            fputcsv($output, []);
            
            fputcsv($output, ['CLIENT ACTIVITY SUMMARY']);
            fputcsv($output, ['Client Name', 'Meter Code', 'Contact', 'Total Bills', 'Total Billed', 'Payments Made', 'Total Paid', 'Status']);
            
            $clients_sql = "SELECT 
                cl.firstname, cl.lastname, cl.meter_code, cl.contact, cl.status,
                COUNT(bl.id) as total_bills,
                SUM(bl.total) as total_billed,
                COUNT(pl.id) as payments_made,
                SUM(pl.amount) as total_paid
                FROM client_list cl
                LEFT JOIN billing_list bl ON cl.id = bl.client_id
                LEFT JOIN payment_list pl ON bl.id = pl.billing_id AND pl.status = 1
                WHERE cl.delete_flag = 0
                GROUP BY cl.id
                ORDER BY total_billed DESC";
            
            $clients_result = $conn->query($clients_sql);
            
            while ($row = $clients_result->fetch_assoc()) {
                fputcsv($output, [
                    $row['firstname'] . ' ' . $row['lastname'],
                    $row['meter_code'],
                    $row['contact'],
                    $row['total_bills'] ?? 0,
                    '₱' . number_format($row['total_billed'] ?? 0, 2),
                    $row['payments_made'] ?? 0,
                    '₱' . number_format($row['total_paid'] ?? 0, 2),
                    $row['status'] == 1 ? 'Active' : 'Inactive'
                ]);
            }
            break;
            
        case 'overdue':
            // Overdue Accounts Report Export
            fputcsv($output, ['Water Billing System - Overdue Accounts Report']);
            fputcsv($output, ['Generated: ' . date('M d, Y H:i:s')]);
            fputcsv($output, []);
            
            fputcsv($output, ['OVERDUE BILLS DETAILS']);
            fputcsv($output, ['Client Name', 'Contact', 'Meter Code', 'Bill Date', 'Due Date', 'Days Overdue', 'Bill Total', 'Amount Paid', 'Balance Due']);
            
            $overdue_sql = "SELECT 
                cl.firstname, cl.lastname, cl.contact, cl.meter_code,
                bl.id as bill_id, bl.reading_date, bl.due_date, bl.total,
                DATEDIFF(CURRENT_DATE(), bl.due_date) as days_overdue,
                COALESCE(SUM(pl.amount), 0) as amount_paid,
                (bl.total - COALESCE(SUM(pl.amount), 0)) as balance_due
                FROM billing_list bl
                JOIN client_list cl ON bl.client_id = cl.id
                LEFT JOIN payment_list pl ON bl.id = pl.billing_id AND pl.status = 1
                WHERE bl.status = 0 AND bl.due_date < CURRENT_DATE()
                GROUP BY bl.id
                HAVING balance_due > 0
                ORDER BY days_overdue DESC, balance_due DESC";
            
            $overdue_result = $conn->query($overdue_sql);
            
            while ($row = $overdue_result->fetch_assoc()) {
                fputcsv($output, [
                    $row['firstname'] . ' ' . $row['lastname'],
                    $row['contact'],
                    $row['meter_code'],
                    date('M d, Y', strtotime($row['reading_date'])),
                    date('M d, Y', strtotime($row['due_date'])),
                    $row['days_overdue'] . ' days',
                    '₱' . number_format($row['total'], 2),
                    '₱' . number_format($row['amount_paid'], 2),
                    '₱' . number_format($row['balance_due'], 2)
                ]);
            }
            break;
            
        case 'billing':
            // Billing Summary Report Export
            fputcsv($output, ['Water Billing System - Billing Summary Report']);
            fputcsv($output, ['Period: ' . date('M d, Y', strtotime($date_from)) . ' to ' . date('M d, Y', strtotime($date_to))]);
            fputcsv($output, []);
            
            fputcsv($output, ['MONTHLY BILLING SUMMARY']);
            fputcsv($output, ['Month/Year', 'Bills Generated', 'Total Amount', 'Average Bill', 'Paid Bills', 'Overdue Bills']);
            
            $monthly_sql = "SELECT 
                YEAR(reading_date) as year,
                MONTH(reading_date) as month,
                MONTHNAME(reading_date) as month_name,
                COUNT(*) as bills_generated,
                SUM(total) as total_amount,
                AVG(total) as average_bill,
                COUNT(CASE WHEN status = 1 THEN 1 END) as paid_bills,
                COUNT(CASE WHEN status = 0 AND due_date < CURRENT_DATE() THEN 1 END) as overdue_bills
                FROM billing_list 
                WHERE DATE(reading_date) BETWEEN ? AND ?
                GROUP BY YEAR(reading_date), MONTH(reading_date)
                ORDER BY year DESC, month DESC";
            
            $stmt = $conn->prepare($monthly_sql);
            $stmt->bind_param("ss", $date_from, $date_to);
            $stmt->execute();
            $monthly_result = $stmt->get_result();
            
            while ($row = $monthly_result->fetch_assoc()) {
                fputcsv($output, [
                    $row['month_name'] . ' ' . $row['year'],
                    $row['bills_generated'],
                    '₱' . number_format($row['total_amount'], 2),
                    '₱' . number_format($row['average_bill'], 2),
                    $row['paid_bills'],
                    $row['overdue_bills']
                ]);
            }
            break;
            
        case 'fees':
            // Additional Fees Report Export
            fputcsv($output, ['Water Billing System - Additional Fees Report']);
            fputcsv($output, ['Period: ' . date('M d, Y', strtotime($date_from)) . ' to ' . date('M d, Y', strtotime($date_to))]);
            fputcsv($output, []);
            
            fputcsv($output, ['FEE BREAKDOWN SUMMARY']);
            fputcsv($output, ['Fee Name', 'Fee Type', 'Fee Rate', 'Times Applied', 'Total Collected']);
            
            $fees_sql = "SELECT 
                af.fee_name, af.fee_type, af.fee_amount,
                COUNT(baf.id) as times_applied,
                SUM(baf.fee_amount) as total_collected
                FROM additional_fees af
                LEFT JOIN bill_additional_fees baf ON af.id = baf.fee_id
                LEFT JOIN billing_list bl ON baf.bill_id = bl.id
                WHERE af.is_active = 1 AND (bl.reading_date IS NULL OR DATE(bl.reading_date) BETWEEN ? AND ?)
                GROUP BY af.id
                ORDER BY total_collected DESC";
            
            $stmt = $conn->prepare($fees_sql);
            $stmt->bind_param("ss", $date_from, $date_to);
            $stmt->execute();
            $fees_result = $stmt->get_result();
            
            while ($row = $fees_result->fetch_assoc()) {
                $rate_display = $row['fee_type'] === 'fixed' ? 
                    '₱' . number_format($row['fee_amount'], 2) : 
                    number_format($row['fee_amount'], 2) . '%';
                    
                fputcsv($output, [
                    $row['fee_name'],
                    ucfirst($row['fee_type']),
                    $rate_display,
                    $row['times_applied'] ?? 0,
                    '₱' . number_format($row['total_collected'] ?? 0, 2)
                ]);
            }
            
            fputcsv($output, []);
            
            // Recent fee applications
            fputcsv($output, ['RECENT FEE APPLICATIONS']);
            fputcsv($output, ['Date Applied', 'Fee Name', 'Client Name', 'Bill Date', 'Fee Amount']);
            
            $recent_fees_sql = "SELECT 
                af.fee_name, baf.fee_amount, baf.applied_at,
                cl.firstname, cl.lastname, bl.reading_date
                FROM bill_additional_fees baf
                JOIN additional_fees af ON baf.fee_id = af.id
                JOIN billing_list bl ON baf.bill_id = bl.id
                JOIN client_list cl ON bl.client_id = cl.id
                WHERE DATE(baf.applied_at) BETWEEN ? AND ?
                ORDER BY baf.applied_at DESC
                LIMIT 100";
            
            $stmt = $conn->prepare($recent_fees_sql);
            $stmt->bind_param("ss", $date_from, $date_to);
            $stmt->execute();
            $recent_result = $stmt->get_result();
            
            while ($row = $recent_result->fetch_assoc()) {
                fputcsv($output, [
                    date('M d, Y H:i', strtotime($row['applied_at'])),
                    $row['fee_name'],
                    $row['firstname'] . ' ' . $row['lastname'],
                    date('M d, Y', strtotime($row['reading_date'])),
                    '₱' . number_format($row['fee_amount'], 2)
                ]);
            }
            break;
            
        default:
            // Dashboard summary export
            fputcsv($output, ['Water Billing System - Dashboard Overview']);
            fputcsv($output, ['Period: ' . date('M d, Y', strtotime($date_from)) . ' to ' . date('M d, Y', strtotime($date_to))]);
            fputcsv($output, ['Generated: ' . date('M d, Y H:i:s')]);
            fputcsv($output, []);
            
            // Summary metrics
            fputcsv($output, ['SUMMARY METRICS']);
            fputcsv($output, ['Metric', 'Value']);
            
            // Get dashboard data
            $collections_sql = "SELECT 
                COUNT(*) as total_payments,
                SUM(amount) as total_collected,
                SUM(CASE WHEN status = 1 THEN amount ELSE 0 END) as verified_collected
                FROM payment_list 
                WHERE DATE(payment_date) BETWEEN ? AND ?";
            $stmt = $conn->prepare($collections_sql);
            $stmt->bind_param("ss", $date_from, $date_to);
            $stmt->execute();
            $collections = $stmt->get_result()->fetch_assoc();
            
            $clients_data = $conn->query("SELECT 
                COUNT(*) as total_clients,
                SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as active_clients,
                SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) as inactive_clients
                FROM client_list WHERE delete_flag = 0")->fetch_assoc();
            
            $overdue_data = $conn->query("SELECT 
                COUNT(DISTINCT bl.client_id) as overdue_clients,
                COUNT(*) as overdue_bills,
                SUM(bl.total) as overdue_amount
                FROM billing_list bl
                WHERE bl.status = 0 AND bl.due_date < CURRENT_DATE()")->fetch_assoc();
            
            fputcsv($output, ['Total Payments', $collections['total_payments']]);
            fputcsv($output, ['Total Collections', '₱' . number_format($collections['verified_collected'], 2)]);
            fputcsv($output, ['Active Clients', $clients_data['active_clients']]);
            fputcsv($output, ['Total Clients', $clients_data['total_clients']]);
            fputcsv($output, ['Overdue Clients', $overdue_data['overdue_clients']]);
            fputcsv($output, ['Overdue Amount', '₱' . number_format($overdue_data['overdue_amount'], 2)]);
            break;
    }
    
    fclose($output);
} else {
    // For future PDF export functionality
    echo "PDF export functionality coming soon!";
}

$conn->close();
?> 