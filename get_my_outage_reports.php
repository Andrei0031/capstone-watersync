<?php
session_start();
if (!isset($_SESSION['client_id'])) {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<div class="text-center py-4 text-muted">Please log in to view your reports.</div>';
    exit;
}
include 'db.php';
include 'timezone_helper.php';
watersync_force_timezone($conn);

if (!function_exists('customerFormatDT')) {
    function customerFormatDT($dt) {
        if (empty($dt)) return '';
        $ts = @strtotime((string)$dt);
        if ($ts === false) return is_string($dt) ? $dt : '';
        return date('M d, Y g:i A', $ts);
    }
}

$client_id = (int)$_SESSION['client_id'];
$reports_query = "SELECT id, location, description, status, created_at, resolved_at, resolution_notes FROM outage_reports WHERE client_id = ? ORDER BY created_at DESC";
$stmt = $conn->prepare($reports_query);
$stmt->bind_param("i", $client_id);
$stmt->execute();
$result = $stmt->get_result();
$reports = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (empty($reports)) {
    echo '<div style="text-align: center; padding: 40px; color: #666;"><i class="fas fa-inbox" style="font-size: 48px; color: #ccc; margin-bottom: 15px;"></i><p style="margin: 0; font-size: 1.1rem;">No reports submitted yet</p><p style="margin: 10px 0 0 0; color: #999; font-size: 0.9rem;">Submit a report above to get started</p></div>';
    exit;
}

echo '<div style="max-height: 600px; overflow-y: auto;">';
foreach ($reports as $report) {
    $is_resolved = (int)$report['status'] === 1;
    $status_color = $is_resolved ? '#4caf50' : '#ff9800';
    $status_text = $is_resolved ? 'Resolved' : 'Pending';
    $status_icon = $is_resolved ? 'fa-check-circle' : 'fa-clock';
    $created_date = customerFormatDT($report['created_at']);
    $resolved_date = !empty($report['resolved_at']) ? customerFormatDT($report['resolved_at']) : null;
    $has_admin_reply = !empty(trim((string)($report['resolution_notes'] ?? '')));
    echo '<div style="border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; margin-bottom: 15px; background: ' . ($is_resolved ? '#f1f8f4' : '#fff8e1') . ';">';
    echo '<div style="display: flex; align-items: center; margin-bottom: 10px;"><span style="background: ' . $status_color . '; color: white; padding: 5px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 600; margin-right: 10px;"><i class="fas ' . $status_icon . ' me-1"></i>' . $status_text . '</span><span style="color: #666; font-size: 0.9rem;"><i class="fas fa-calendar me-1"></i>Submitted: ' . $created_date . '</span></div>';
    echo '<h6 style="margin: 0 0 8px 0; color: #333; font-weight: 600;"><i class="fas fa-map-marker-alt me-2" style="color: #2196f3;"></i>' . htmlspecialchars($report['location']) . '</h6>';
    echo '<p style="margin: 0; color: #555; line-height: 1.6;">' . htmlspecialchars($report['description']) . '</p>';
    echo '<div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #e0e0e0;">';
    if ($is_resolved) {
        echo '<div style="color: #4caf50; font-size: 0.9rem;"><i class="fas fa-check-circle me-2"></i><strong>Status:</strong> Resolved' . ($resolved_date ? ' on ' . $resolved_date : '') . '</div>';
    }
    if ($has_admin_reply) {
        echo '<div style="background: white; padding: 12px; border-radius: 6px; margin-top: 10px; border-left: 3px solid #2196f3;"><strong style="font-size: 0.9rem;">Admin reply:</strong><p style="margin: 5px 0 0 0; color: #555; font-size: 0.9rem;">' . nl2br(htmlspecialchars($report['resolution_notes'])) . '</p></div>';
    }
    echo '</div>';
    echo '</div>';
}
echo '</div>';
