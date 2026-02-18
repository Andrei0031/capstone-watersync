<?php
if (!isset($_SESSION)) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['client_id'])) {
    header("Location: login.php");
    exit();
}

// Get notification count
$notification_count = 0;
if (isset($_SESSION['client_id'])) {
    include 'db.php';
    $client_id = $_SESSION['client_id'];
    
    // Count active notices
    $notices_query = "
        SELECT COUNT(*) as count 
        FROM notices 
        WHERE (status = 'ongoing' OR 
              (status = 'scheduled' AND start_date <= DATE_ADD(NOW(), INTERVAL 24 HOUR)) OR
              (status = 'completed' AND end_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)))
        AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ";
    $notices_result = $conn->query($notices_query);
    if ($notices_result) {
        $notification_count += $notices_result->fetch_assoc()['count'];
    }
    
    // Count unacknowledged disconnection notices
    $disconnection_query = "
        SELECT COUNT(*) as count 
        FROM disconnection_notices 
        WHERE client_id = ? 
        AND status IN ('pending', 'sent')
    ";
    $stmt = $conn->prepare($disconnection_query);
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $disconnection_result = $stmt->get_result();
    if ($disconnection_result) {
        $notification_count += $disconnection_result->fetch_assoc()['count'];
    }
}
?>

<style>
    @keyframes pulse {
        0%, 100% {
            opacity: 1;
            transform: scale(1);
        }
        50% {
            opacity: 0.8;
            transform: scale(1.1);
        }
    }
    .notification-badge {
        animation: pulse 2s infinite;
        box-shadow: 0 2px 4px rgba(220, 53, 69, 0.4);
    }
</style>
<script>
    // Update notification badge dynamically
    function updateNotificationBadge() {
        fetch('get_notification_count.php')
            .then(response => response.json())
            .then(data => {
                const badge = document.querySelector('.nav-link[href="client_notices.php"] .notification-badge');
                if (badge && data.success) {
                    if (data.count > 0) {
                        badge.textContent = data.count > 99 ? '99+' : data.count;
                        badge.style.display = 'block';
                    } else {
                        badge.style.display = 'none';
                    }
                } else if (data.success && data.count > 0) {
                    // Create badge if it doesn't exist
                    const link = document.querySelector('.nav-link[href="client_notices.php"]');
                    if (link && !badge) {
                        const newBadge = document.createElement('span');
                        newBadge.className = 'badge bg-danger notification-badge';
                        newBadge.style.cssText = 'position: absolute; top: -5px; right: -5px; font-size: 0.7rem; padding: 2px 6px; border-radius: 10px; animation: pulse 2s infinite; box-shadow: 0 2px 4px rgba(220, 53, 69, 0.4);';
                        newBadge.textContent = data.count > 99 ? '99+' : data.count;
                        link.appendChild(newBadge);
                    }
                }
            })
            .catch(error => console.error('Error updating notification badge:', error));
    }
    
    // Update badge on page load and every 30 seconds
    document.addEventListener('DOMContentLoaded', function() {
        updateNotificationBadge();
        setInterval(updateNotificationBadge, 30000);
    });
</script>
<nav class="navbar navbar-expand-lg navbar-dark mb-4" style="background: linear-gradient(45deg, #0D47A1, #2196F3);">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center" href="customer_dashboard.php">
            <img src="icons/Logo.png" alt="WaterSync Logo" style="height: 40px; filter: brightness(0) invert(1); margin-right: 10px;">
            WaterSync
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'customer_dashboard.php' ? 'active' : ''; ?>" 
                       href="customer_dashboard.php">
                        <i class="fas fa-home me-2"></i>Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'client_notices.php' ? 'active' : ''; ?>" 
                       href="client_notices.php" style="position: relative;">
                        <i class="fas fa-bell me-2"></i>Notices
                        <?php if ($notification_count > 0): ?>
                            <span class="badge bg-danger notification-badge" style="position: absolute; top: -5px; right: -5px; font-size: 0.7rem; padding: 2px 6px; border-radius: 10px; animation: pulse 2s infinite;">
                                <?php echo $notification_count > 99 ? '99+' : $notification_count; ?>
                            </span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : ''; ?>" 
                       href="profile.php">
                        <i class="fas fa-user me-2"></i>Profile
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="customer_logout.php">
                        <i class="fas fa-sign-out-alt me-2"></i>Logout
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav> 