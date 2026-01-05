<?php
session_start();
if (!isset($_SESSION['client_id'])) {
    header("Location: login.php");
    exit();
}

include 'db.php';

// Get active notices from notices table
$notices_query = "
    SELECT n.*, a.username as admin_name, 'notice' as source_type
    FROM notices n
    JOIN admin a ON n.created_by = a.id
    WHERE (n.status = 'ongoing' OR 
          (n.status = 'scheduled' AND n.start_date <= DATE_ADD(NOW(), INTERVAL 24 HOUR)) OR
          (n.status = 'completed' AND n.end_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)))
    ORDER BY 
        CASE n.status
            WHEN 'ongoing' THEN 1
            WHEN 'scheduled' THEN 2
            WHEN 'completed' THEN 3
        END,
        n.start_date DESC";
$notices_result = $conn->query($notices_query);

// Get water interruptions (check if table exists first)
$water_interruptions = [];
try {
    $water_interruptions_query = "
        SELECT 
            id,
            title,
            description,
            affected_areas,
            estimated_restoration,
            reported_by as admin_name,
            status,
            created_at,
            updated_at,
            'interruption' as source_type,
            CASE 
                WHEN status = 'active' THEN 'ongoing'
                WHEN status = 'resolved' THEN 'completed'
                WHEN status = 'cancelled' THEN 'completed'
                ELSE 'ongoing'
            END as notice_status
        FROM water_interruptions
        WHERE status IN ('active', 'resolved')
        ORDER BY created_at DESC";
    $water_interruptions_result = $conn->query($water_interruptions_query);
    if ($water_interruptions_result) {
        while ($row = $water_interruptions_result->fetch_assoc()) {
            // Convert water_interruptions format to match notices format
            $row['type'] = 'interruption';
            $row['start_date'] = $row['created_at'];
            $row['end_date'] = $row['estimated_restoration'];
            $row['status'] = $row['notice_status'];
            // Handle JSON affected_areas - convert to string if needed
            if (is_string($row['affected_areas'])) {
                $decoded = json_decode($row['affected_areas'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $row['affected_areas'] = implode(', ', $decoded);
                }
            }
            $water_interruptions[] = $row;
        }
    }
} catch (Exception $e) {
    // Table might not exist, ignore error
    error_log("Error fetching water interruptions: " . $e->getMessage());
}

// Combine notices and water interruptions
$all_notices = [];
if ($notices_result) {
    while ($notice = $notices_result->fetch_assoc()) {
        $all_notices[] = $notice;
    }
}
$all_notices = array_merge($all_notices, $water_interruptions);

// Sort by date (most recent first)
usort($all_notices, function($a, $b) {
    $dateA = isset($a['start_date']) ? strtotime($a['start_date']) : strtotime($a['created_at']);
    $dateB = isset($b['start_date']) ? strtotime($b['start_date']) : strtotime($b['created_at']);
    return $dateB - $dateA;
});
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Water Service Notices - WaterSync</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        .notice-card {
            transition: transform 0.2s;
        }
        .notice-card:hover {
            transform: translateY(-5px);
        }
        .notice-icon {
            font-size: 2.5rem;
            opacity: 0.2;
            position: absolute;
            right: 1rem;
            top: 1rem;
        }
        .priority-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
        }
    </style>
</head>
<body class="bg-light">
    <?php include 'client_navbar.php'; ?>

    <div class="container py-5">
        <div class="row mb-4">
            <div class="col">
                <h2><i class="fas fa-bell me-2"></i>Water Service Notices</h2>
                <p class="text-muted">Stay informed about water service interruptions, maintenance, and important announcements.</p>
            </div>
        </div>

        <?php if (count($all_notices) > 0): ?>
            <div class="row">
                <?php foreach ($all_notices as $notice): ?>
                    <div class="col-md-6 mb-4">
                        <div class="card notice-card h-100 border-0 shadow-sm">
                            <?php
                            $card_class = '';
                            $icon_class = '';
                            switch($notice['type']) {
                                case 'interruption':
                                    $card_class = 'border-danger';
                                    $icon_class = 'fa-tint-slash text-danger';
                                    break;
                                case 'maintenance':
                                    $card_class = 'border-warning';
                                    $icon_class = 'fa-wrench text-warning';
                                    break;
                                case 'announcement':
                                    $card_class = 'border-info';
                                    $icon_class = 'fa-info-circle text-info';
                                    break;
                            }
                            ?>
                            <div class="card-body position-relative">
                                <i class="fas <?php echo $icon_class; ?> notice-icon"></i>
                                
                                <span class="badge <?php 
                                    $status_class = 'bg-secondary';
                                    if ($notice['status'] === 'ongoing') {
                                        $status_class = 'bg-warning';
                                    } elseif ($notice['status'] === 'scheduled') {
                                        $status_class = 'bg-info';
                                    } elseif ($notice['status'] === 'completed') {
                                        $status_class = 'bg-success';
                                    }
                                    echo $status_class;
                                ?> priority-badge">
                                    <?php echo ucfirst($notice['status']); ?></span>

                                <h4 class="card-title mt-2"><?php echo htmlspecialchars($notice['title']); ?></h4>
                                <p class="card-text"><?php echo nl2br(htmlspecialchars($notice['description'])); ?></p>
                                
                                <div class="mt-3">
                                    <p class="mb-2">
                                        <strong><i class="fas fa-map-marker-alt me-2"></i>Affected Areas:</strong><br>
                                        <?php 
                                        $affected_areas = $notice['affected_areas'];
                                        if (is_string($affected_areas)) {
                                            $decoded = json_decode($affected_areas, true);
                                            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                                $affected_areas = implode(', ', $decoded);
                                            }
                                        }
                                        echo htmlspecialchars($affected_areas); 
                                        ?>
                                    </p>
                                    
                                    <p class="mb-2">
                                        <strong><i class="fas fa-clock me-2"></i>Duration:</strong><br>
                                        <?php 
                                        echo date('M d, Y h:i A', strtotime($notice['start_date']));
                                        if ($notice['end_date']) {
                                            echo ' to ' . date('M d, Y h:i A', strtotime($notice['end_date']));
                                        }
                                        ?>
                                    </p>

                                    <small class="text-muted">
                                        <i class="fas fa-user me-1"></i>Posted by <?php echo htmlspecialchars($notice['admin_name']); ?>
                                        on <?php echo date('M d, Y', strtotime($notice['created_at'])); ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-5">
                    <i class="fas fa-check-circle text-success fa-3x mb-3"></i>
                    <h4>No Active Notices</h4>
                    <p class="text-muted mb-0">There are currently no water service interruptions or important announcements.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 