<?php
/**
 * Vendor Folder Diagnostic Tool
 * Check if vendor folder and dependencies are properly installed
 */

// Enable error display
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Vendor Folder Diagnostic</h1>";
echo "<pre>";

// Check 1: Vendor folder exists
echo "1. Checking vendor folder...\n";
if (file_exists(__DIR__ . '/vendor')) {
    echo "   ✓ vendor/ folder exists\n";
} else {
    echo "   ✗ vendor/ folder NOT FOUND\n";
    echo "   Location checked: " . __DIR__ . "/vendor\n";
    exit;
}

// Check 2: autoload.php exists
echo "\n2. Checking vendor/autoload.php...\n";
$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    echo "   ✓ vendor/autoload.php exists\n";
} else {
    echo "   ✗ vendor/autoload.php NOT FOUND\n";
    echo "   Location checked: " . $autoloadPath . "\n";
    exit;
}

// Check 3: Try to load autoload.php
echo "\n3. Testing vendor/autoload.php...\n";
try {
    require_once $autoloadPath;
    echo "   ✓ vendor/autoload.php loaded successfully\n";
} catch (Exception $e) {
    echo "   ✗ Error loading vendor/autoload.php: " . $e->getMessage() . "\n";
    exit;
}

// Check 4: Check Rubix ML classes
echo "\n4. Checking Rubix ML classes...\n";
$classes = [
    '\\Rubix\\ML\\Regressors\\GradientBoost',
    '\\Rubix\\ML\\Regressors\\RegressionTree',
    '\\Rubix\\ML\\PersistentModel',
    '\\Rubix\\ML\\Persisters\\Filesystem'
];

foreach ($classes as $class) {
    if (class_exists($class)) {
        echo "   ✓ {$class} exists\n";
    } else {
        echo "   ✗ {$class} NOT FOUND\n";
    }
}

// Check 5: Storage folder
echo "\n5. Checking storage/models folder...\n";
$storageDir = __DIR__ . '/storage/models';
if (file_exists($storageDir)) {
    echo "   ✓ storage/models/ folder exists\n";
    if (is_writable($storageDir)) {
        echo "   ✓ storage/models/ is writable\n";
    } else {
        echo "   ⚠ storage/models/ is NOT writable (permissions: " . substr(sprintf('%o', fileperms($storageDir)), -4) . ")\n";
        echo "   Fix: Set permissions to 755 or 777\n";
    }
} else {
    echo "   ⚠ storage/models/ folder NOT FOUND (will be created automatically)\n";
    if (@mkdir($storageDir, 0775, true)) {
        echo "   ✓ Created storage/models/ folder\n";
    } else {
        echo "   ✗ Failed to create storage/models/ folder\n";
        echo "   Fix: Create manually and set permissions to 755\n";
    }
}

// Check 6: Check vendor subdirectories
echo "\n6. Checking vendor subdirectories...\n";
$requiredDirs = ['rubix', 'bacon', 'composer', 'amphp'];
foreach ($requiredDirs as $dir) {
    $path = __DIR__ . '/vendor/' . $dir;
    if (is_dir($path)) {
        echo "   ✓ vendor/{$dir}/ exists\n";
    } else {
        echo "   ✗ vendor/{$dir}/ NOT FOUND\n";
    }
}

// Check 7: Test ML forecast function
echo "\n7. Testing ML forecast function...\n";
try {
    include 'db.php';
    
    // Check if we have enough data
    $result = $conn->query("SELECT COUNT(DISTINCT DATE_FORMAT(reading_date, '%Y-%m')) as months FROM billing_list WHERE status = 1");
    $row = $result->fetch_assoc();
    $months = $row['months'] ?? 0;
    
    echo "   Historical data points: {$months} months\n";
    
    if ($months >= 6) {
        echo "   ✓ Enough data for ML forecasting (need at least 6 months)\n";
    } else {
        echo "   ⚠ Not enough data for ML forecasting (need at least 6 months, have {$months})\n";
        echo "   The system will fall back to ensemble forecasting\n";
    }
    
} catch (Exception $e) {
    echo "   ✗ Error checking database: " . $e->getMessage() . "\n";
}

// Check 8: File permissions
echo "\n8. Checking file permissions...\n";
$vendorPerms = substr(sprintf('%o', fileperms(__DIR__ . '/vendor')), -4);
echo "   vendor/ permissions: {$vendorPerms}\n";
if ($vendorPerms >= '0755') {
    echo "   ✓ Permissions OK\n";
} else {
    echo "   ⚠ Permissions might be too restrictive\n";
}

echo "\n=== Diagnostic Complete ===\n";
echo "</pre>";

echo "<h2>Next Steps:</h2>";
echo "<ul>";
echo "<li>If vendor folder is missing: Extract vendor.zip in public_html/</li>";
echo "<li>If storage/models is not writable: Set permissions to 755 or 777</li>";
echo "<li>If classes not found: Re-run 'composer install' locally and re-upload vendor.zip</li>";
echo "<li>If not enough data: Add more billing records with status = 1 (paid bills)</li>";
echo "</ul>";

echo "<p><a href='adminlandingpage.php'>Go to Admin Dashboard</a></p>";
?>

