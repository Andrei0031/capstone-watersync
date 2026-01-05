<?php
session_start();
if (!isset($_SESSION['client_id'])) {
    header("Location: login.php");
    exit();
}

include 'db.php';

// Get active notices
$notices_query = "
    SELECT n.*, a.username as admin_name
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
$notices = $conn->query($notices_query);

// Get water interruptions
$interruptions_query = "
    SELECT 
        wi.id,
        wi.title,
        wi.description,
        JSON_UNQUOTE(JSON_EXTRACT(wi.affected_areas, '$[0]')) as affected_areas,
        wi.estimated_restoration as start_date,
        NULL as end_date,
        wi.status,
        wi.created_at,
        wi.updated_at,
        'interruption' as type,
        'System' as admin_name
    FROM water_interruptions wi
    WHERE wi.status IN ('active', 'resolved')
    ORDER BY 
        CASE wi.status
            WHEN 'active' THEN 1
            WHEN 'resolved' THEN 2
        END,
        wi.created_at DESC";
$interruptions = $conn->query($interruptions_query);
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

        <?php 
        $total_notices = ($notices ? $notices->num_rows : 0) + ($interruptions ? $interruptions->num_rows : 0);
        if ($total_notices > 0): ?>
            <div class="row">
                <?php 
                // Display regular notices
                if ($notices && $notices->num_rows > 0):
                    while ($notice = $notices->fetch_assoc()): ?>
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
                                        <?php echo htmlspecialchars($notice['affected_areas']); ?>
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
                <?php 
                    endwhile;
                endif;
                
                // Display water interruptions
                if ($interruptions && $interruptions->num_rows > 0):
                    while ($interruption = $interruptions->fetch_assoc()): 
                        $notice = $interruption; // Use same variable name for consistency
                        $notice['type'] = 'interruption';
                        ?>
                    <div class="col-md-6 mb-4">
                        <div class="card notice-card h-100 border-danger shadow-sm">
                            <div class="card-body position-relative">
                                <i class="fas fa-tint-slash text-danger notice-icon"></i>
                                
                                <span class="badge <?php 
                                    $status_class = 'bg-secondary';
                                    if ($notice['status'] === 'active') {
                                        $status_class = 'bg-danger';
                                    } elseif ($notice['status'] === 'resolved') {
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
                                        <?php echo htmlspecialchars($notice['affected_areas']); ?>
                                    </p>
                                    
                                    <?php if ($notice['start_date']): ?>
                                    <p class="mb-2">
                                        <strong><i class="fas fa-clock me-2"></i>Estimated Restoration:</strong><br>
                                        <?php echo htmlspecialchars($notice['start_date']); ?>
                                    </p>
                                    <?php endif; ?>

                                    <small class="text-muted">
                                        <i class="fas fa-user me-1"></i>Reported by <?php echo htmlspecialchars($notice['admin_name']); ?>
                                        on <?php echo date('M d, Y', strtotime($notice['created_at'])); ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php 
                    endwhile;
                endif;
                ?>
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