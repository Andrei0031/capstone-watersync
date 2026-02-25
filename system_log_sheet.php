<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: adminlogin.php");
    exit();
}

function readLastLines($filePath, $maxLines = 300) {
    if (!file_exists($filePath) || !is_readable($filePath)) {
        return [];
    }
    $lines = @file($filePath, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return [];
    }
    return array_slice($lines, -1 * $maxLines);
}

$appLog = __DIR__ . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'system_php_error.log';
$apacheLog = 'C:\\xampp\\apache\\logs\\error.log';

$appLines = readLastLines($appLog, 300);
$apacheLines = readLastLines($apacheLog, 300);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Log Sheet</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .log-box {
            background: #111827;
            color: #e5e7eb;
            border-radius: 8px;
            padding: 12px;
            max-height: 420px;
            overflow: auto;
            font-family: Consolas, Monaco, monospace;
            font-size: 12px;
            white-space: pre-wrap;
            word-break: break-word;
        }
    </style>
</head>
<body>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">System Log Sheet</h4>
        <a href="view_clients.php" class="btn btn-secondary btn-sm">Back to View Clients</a>
    </div>

    <div class="alert alert-info">
        Use this page to diagnose CSV import issues. Newest entries are at the bottom of each log.
    </div>

    <div class="card mb-3">
        <div class="card-header"><strong>App Import Log (PHP)</strong> <span class="text-muted">- <?php echo htmlspecialchars($appLog); ?></span></div>
        <div class="card-body">
            <?php if (empty($appLines)): ?>
                <div class="text-muted">No entries found.</div>
            <?php else: ?>
                <div class="log-box"><?php echo htmlspecialchars(implode(PHP_EOL, $appLines)); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><strong>Apache/PHP Runtime Log</strong> <span class="text-muted">- <?php echo htmlspecialchars($apacheLog); ?></span></div>
        <div class="card-body">
            <?php if (empty($apacheLines)): ?>
                <div class="text-muted">No entries found or log is not readable.</div>
            <?php else: ?>
                <div class="log-box"><?php echo htmlspecialchars(implode(PHP_EOL, $apacheLines)); ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
