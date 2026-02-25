<?php
session_start();
if (!isset($_SESSION['client_id'])) {
    header("Location: login.php");
    exit();
}

include 'db.php';

// Mark notices as "viewed" so the navbar badge count goes to zero when user visits this page
$_SESSION['notices_last_viewed_at'] = date('Y-m-d H:i:s');

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

$active_notices = array_values(array_filter($all_notices, function($notice) {
    $status = strtolower((string)($notice['status'] ?? ''));
    return in_array($status, ['ongoing', 'scheduled'], true);
}));
$recent_notices = array_slice($all_notices, 0, 12);
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
        .notice-table th, .notice-table td {
            font-size: 0.9rem;
            padding: 0.6rem 0.7rem;
            vertical-align: middle;
        }
    </style>
</head>
<body class="bg-light">
    <?php include 'client_navbar.php'; ?>

    <div class="container py-5">
        <div class="card border-primary shadow-sm mb-4">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-bell me-2"></i>Service Notices & Announcements</h5>
            </div>
            <div class="card-body">
                <?php if (count($active_notices) > 0): ?>
                    <div class="row">
                        <?php foreach ($active_notices as $notice): ?>
                            <?php
                            $icon_class = 'fa-info-circle text-info';
                            if (($notice['type'] ?? '') === 'interruption') {
                                $icon_class = 'fa-tint-slash text-danger';
                            } elseif (($notice['type'] ?? '') === 'maintenance') {
                                $icon_class = 'fa-wrench text-warning';
                            }
                            $status_class = 'bg-secondary';
                            if (($notice['status'] ?? '') === 'ongoing') {
                                $status_class = 'bg-warning text-dark';
                            } elseif (($notice['status'] ?? '') === 'scheduled') {
                                $status_class = 'bg-info text-white';
                            } elseif (($notice['status'] ?? '') === 'completed') {
                                $status_class = 'bg-success text-white';
                            }
                            $affected_areas = $notice['affected_areas'] ?? '';
                            if (is_string($affected_areas)) {
                                $decoded = json_decode($affected_areas, true);
                                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                    $affected_areas = implode(', ', $decoded);
                                }
                            }
                            $anchor = ($notice['source_type'] ?? 'notice') . '-' . (int)($notice['id'] ?? 0);
                            ?>
                            <div class="col-12 col-md-6 mb-3">
                                <div class="card notice-card h-100 border-0 shadow-sm" id="notice-<?php echo $anchor; ?>">
                                    <div class="card-body position-relative">
                                        <i class="fas <?php echo $icon_class; ?> notice-icon"></i>
                                        <span class="badge <?php echo $status_class; ?> priority-badge"><?php echo ucfirst($notice['status'] ?? 'notice'); ?></span>
                                        <h5 class="card-title mt-1"><?php echo htmlspecialchars($notice['title'] ?? 'Notice'); ?></h5>
                                        <p class="card-text mb-2"><?php echo nl2br(htmlspecialchars($notice['description'] ?? '')); ?></p>
                                        <p class="mb-1"><strong><i class="fas fa-map-marker-alt me-2"></i>Affected Areas:</strong> <?php echo htmlspecialchars((string)$affected_areas); ?></p>
                                        <p class="mb-2"><strong><i class="fas fa-clock me-2"></i>Duration:</strong>
                                            <?php
                                            echo date('M d, Y h:i A', strtotime($notice['start_date']));
                                            if (!empty($notice['end_date'])) {
                                                echo ' to ' . date('M d, Y h:i A', strtotime($notice['end_date']));
                                            }
                                            ?>
                                        </p>
                                        <small class="text-muted">
                                            <i class="fas fa-user me-1"></i>Posted by <?php echo htmlspecialchars($notice['admin_name'] ?? 'admin'); ?>
                                            on <?php echo date('M d, Y', strtotime($notice['created_at'] ?? $notice['start_date'])); ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-check-circle text-success fa-2x mb-2"></i>
                        <p class="text-muted mb-0">No active service notices right now.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recent Notice History</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 notice-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Title</th>
                                <th class="d-none d-md-table-cell">Type</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($recent_notices) > 0): ?>
                                <?php foreach ($recent_notices as $notice): ?>
                                    <?php
                                    $status_class = 'bg-secondary';
                                    if (($notice['status'] ?? '') === 'ongoing') {
                                        $status_class = 'bg-warning text-dark';
                                    } elseif (($notice['status'] ?? '') === 'scheduled') {
                                        $status_class = 'bg-info text-white';
                                    } elseif (($notice['status'] ?? '') === 'completed') {
                                        $status_class = 'bg-success text-white';
                                    }
                                    $anchor = ($notice['source_type'] ?? 'notice') . '-' . (int)($notice['id'] ?? 0);
                                    ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($notice['start_date'] ?? $notice['created_at'])); ?></td>
                                        <td><?php echo htmlspecialchars($notice['title'] ?? 'Notice'); ?></td>
                                        <td class="d-none d-md-table-cell"><?php echo ucfirst(htmlspecialchars($notice['type'] ?? 'notice')); ?></td>
                                        <td><span class="badge <?php echo $status_class; ?>"><?php echo ucfirst($notice['status'] ?? 'notice'); ?></span></td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-primary" href="#notice-<?php echo $anchor; ?>">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">No notice history available.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 