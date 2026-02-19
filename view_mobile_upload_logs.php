<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: adminlogin.php");
    exit();
}

$logFile = __DIR__ . '/logs/mobile_uploads.log';
$lines = [];
$maxLines = 300;

if (file_exists($logFile) && is_readable($logFile)) {
    $all = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($all !== false) {
        $lines = array_slice($all, -$maxLines);
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mobile Upload Logs - WaterSync</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .log-line { font-family: 'Consolas', 'Monaco', monospace; font-size: 0.85rem; white-space: pre-wrap; word-break: break-all; }
        .log-recv { color: #0d6efd; }
        .log-success { color: #198754; font-weight: 600; }
        .log-error { color: #dc3545; font-weight: 600; }
        .log-ok { color: #0d6efd; }
        .log-auth { color: #6f42c1; }
        pre { background: #1e1e1e; color: #d4d4d4; padding: 1rem; border-radius: 8px; max-height: 70vh; overflow-y: auto; }
        html[data-theme="dark"] pre { background: #0d1117; }
    </style>
</head>
<body class="bg-light">
    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0"><i class="fas fa-mobile-alt me-2"></i>Mobile Upload Logs</h4>
            <div>
                <a href="pending_readings.php" class="btn btn-outline-secondary btn-sm me-2">
                    <i class="fas fa-arrow-left me-1"></i>Back to Meter Readings
                </a>
                <a href="view_mobile_upload_logs.php" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-sync-alt me-1"></i>Refresh
                </a>
            </div>
        </div>
        <p class="text-muted small mb-2">
            Shows whether the server is receiving uploads from the mobile app. Upload from the phone, then refresh this page.
            Log file: <code>logs/mobile_uploads.log</code>
        </p>
        <?php if (empty($lines)): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                No log entries yet. Upload a meter reading from the mobile app, then refresh this page.
                <br><small class="mt-2 d-block">If you uploaded and still see this, the request may not be reaching this server (check URL, network, or API endpoint).</small>
            </div>
        <?php else: ?>
            <pre class="log-output"><?php
            foreach ($lines as $line) {
                $esc = htmlspecialchars($line);
                if (strpos($line, '[RECV]') !== false) echo '<span class="log-recv">' . $esc . '</span>' . "\n";
                elseif (strpos($line, '[SUCCESS]') !== false) echo '<span class="log-success">' . $esc . '</span>' . "\n";
                elseif (strpos($line, '[ERROR]') !== false || strpos($line, '[REJECT]') !== false) echo '<span class="log-error">' . $esc . '</span>' . "\n";
                elseif (strpos($line, '[OK]') !== false || strpos($line, '[AUTH]') !== false) echo '<span class="log-ok">' . $esc . '</span>' . "\n";
                else echo $esc . "\n";
            }
            ?></pre>
            <p class="text-muted small mt-2">Last <?php echo count($lines); ?> lines (newest at bottom)</p>
        <?php endif; ?>
    </div>
</body>
</html>
