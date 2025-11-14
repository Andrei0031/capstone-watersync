<?php
/**
 * Test Revenue Forecast Endpoint
 * Check if forecasting is working properly
 */

// Enable error display
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL & ~E_DEPRECATED);

session_start();

// Check admin session
if (!isset($_SESSION['admin_id'])) {
    die("Please log in first: <a href='adminlogin.php'>Login</a>");
}

include 'db.php';
include 'dashboard_data.php';

$dashboard = new DashboardData($conn);

// Get parameters
$period = $_GET['period'] ?? 'monthly';
$forecastMethod = $_GET['forecast_method'] ?? 'ml';
$forecastMonths = intval($_GET['forecast_months'] ?? 6);

echo "<h1>Revenue Forecast Test</h1>";
echo "<pre>";

echo "=== Test Parameters ===\n";
echo "Period: {$period}\n";
echo "Forecast Method: {$forecastMethod}\n";
echo "Forecast Months: {$forecastMonths}\n\n";

// Test 1: Check actual revenue data
echo "=== Test 1: Actual Revenue Data ===\n";
$actualData = $dashboard->getRevenueData($period);
echo "Actual data points: " . count($actualData) . "\n";
if (count($actualData) > 0) {
    echo "First 3 actual data points:\n";
    foreach (array_slice($actualData, 0, 3) as $item) {
        echo "  - {$item['period']}: ₱" . number_format($item['revenue'], 2) . "\n";
    }
} else {
    echo "⚠ No actual revenue data found!\n";
    echo "You need billing records with status = 1 (paid bills)\n";
}
echo "\n";

// Test 2: Try ML forecast
if ($forecastMethod === 'ml') {
    echo "=== Test 2: ML Forecast ===\n";
    $mlForecast = $dashboard->getRevenueWithForecast($period, 'ml', $forecastMonths);
    echo "ML Forecast Result:\n";
    echo "  Actual data points: " . count($mlForecast['actual'] ?? []) . "\n";
    echo "  Forecast data points: " . count($mlForecast['forecast'] ?? []) . "\n";
    
    if (count($mlForecast['forecast'] ?? []) > 0) {
        echo "  First 3 forecast points:\n";
        foreach (array_slice($mlForecast['forecast'], 0, 3) as $item) {
            echo "    - {$item['period']}: ₱" . number_format($item['revenue'], 2) . "\n";
        }
    } else {
        echo "  ⚠ ML forecast returned empty array\n";
        echo "  This could mean:\n";
        echo "    - Not enough historical data (need at least 6 months)\n";
        echo "    - Rubix ML classes not found\n";
        echo "    - Storage/models folder not writable\n";
    }
    echo "\n";
}

// Test 3: Try Ensemble forecast
echo "=== Test 3: Ensemble Forecast ===\n";
$ensembleForecast = $dashboard->getRevenueWithForecast($period, 'ensemble', $forecastMonths);
echo "Ensemble Forecast Result:\n";
echo "  Actual data points: " . count($ensembleForecast['actual'] ?? []) . "\n";
echo "  Forecast data points: " . count($ensembleForecast['forecast'] ?? []) . "\n";

if (count($ensembleForecast['forecast'] ?? []) > 0) {
    echo "  First 3 forecast points:\n";
    foreach (array_slice($ensembleForecast['forecast'], 0, 3) as $item) {
        echo "    - {$item['period']}: ₱" . number_format($item['revenue'], 2) . "\n";
    }
} else {
    echo "  ⚠ Ensemble forecast returned empty array\n";
    echo "  This could mean:\n";
    echo "    - Not enough historical data\n";
    echo "    - Forecasting function error\n";
}
echo "\n";

// Test 4: Check vendor folder
echo "=== Test 4: Vendor Folder Check ===\n";
$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    echo "✓ vendor/autoload.php exists\n";
    
    // Check Rubix ML classes
    require_once $autoloadPath;
    $classes = [
        '\\Rubix\\ML\\Regressors\\GradientBoost',
        '\\Rubix\\ML\\Regressors\\RegressionTree'
    ];
    foreach ($classes as $class) {
        if (class_exists($class)) {
            echo "✓ {$class} exists\n";
        } else {
            echo "✗ {$class} NOT FOUND\n";
        }
    }
} else {
    echo "✗ vendor/autoload.php NOT FOUND\n";
}
echo "\n";

// Test 5: Check storage folder
echo "=== Test 5: Storage Folder Check ===\n";
$storageDir = __DIR__ . '/storage/models';
if (file_exists($storageDir)) {
    echo "✓ storage/models/ exists\n";
    if (is_writable($storageDir)) {
        echo "✓ storage/models/ is writable\n";
    } else {
        echo "✗ storage/models/ is NOT writable\n";
        echo "  Permissions: " . substr(sprintf('%o', fileperms($storageDir)), -4) . "\n";
    }
} else {
    echo "⚠ storage/models/ does not exist (will be created automatically)\n";
}

echo "\n=== Test Complete ===\n";
echo "</pre>";

echo "<h2>JSON Response (for AJAX testing):</h2>";
echo "<pre>";
$testResponse = $dashboard->getRevenueWithForecast($period, $forecastMethod, $forecastMonths);
echo json_encode($testResponse, JSON_PRETTY_PRINT);
echo "</pre>";

echo "<p><a href='adminlandingpage.php'>Go to Admin Dashboard</a></p>";
?>

