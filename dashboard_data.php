<?php
// Check if session is not already active before starting it
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

include 'db.php';
include 'revenue_forecasting.php';
// Load Composer autoloader if available (optional - for Rubix ML)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    // Suppress deprecation warnings from vendor libraries (PHP 8.2+)
    $oldErrorReporting = error_reporting();
    error_reporting($oldErrorReporting & ~E_DEPRECATED);
    require_once __DIR__ . '/vendor/autoload.php';
    error_reporting($oldErrorReporting);
}

class DashboardData {
    private $conn;
    
    public function __construct($conn) {
        $this->conn = $conn;
    }
    
    public function getTotalClients() {
        $result = $this->conn->query("SELECT COUNT(*) AS total FROM client_list WHERE status = 1 AND delete_flag = 0");
        if ($result) {
            $row = $result->fetch_assoc();
            return $row['total'];
        }
        return 0;
    }
    
    public function getCurrentMonthRevenue() {
        $result = $this->conn->query("SELECT SUM(total) AS total FROM billing_list 
                                    WHERE MONTH(reading_date) = MONTH(CURRENT_DATE()) 
                                    AND YEAR(reading_date) = YEAR(CURRENT_DATE())
                                    AND status = 1");
        if ($result) {
            $row = $result->fetch_assoc();
            return $row['total'] ?? 0;
        }
        return 0;
    }
    
    public function getPendingPayments() {
        // Keep this aligned with payments.php "pending" logic (unverified payment records).
        $result = $this->conn->query("SELECT COUNT(*) AS total FROM payment_list WHERE status = 0");
        if ($result) {
            $row = $result->fetch_assoc();
            return $row['total'];
        }
        return 0;
    }
    
    public function getAverageBill() {
        $result = $this->conn->query("SELECT AVG(total) AS avg_bill FROM billing_list 
                                    WHERE MONTH(reading_date) = MONTH(CURRENT_DATE()) 
                                    AND YEAR(reading_date) = YEAR(CURRENT_DATE())");
        if ($result) {
            $row = $result->fetch_assoc();
            return $row['avg_bill'] ?? 0;
        }
        return 0;
    }
    
        public function getRevenueData($period = 'monthly', $includePending = false) {
            $statusCondition = $includePending ? "status IN (0, 1)" : "status = 1";
            $sql = "";
            switch($period) {
                case 'monthly':
                    // Include all historical data, not just last 12 months, to show old imported records
                    $sql = "SELECT DATE_FORMAT(reading_date, '%Y-%m') as period,
                            SUM(total) as revenue
                            FROM billing_list
                            WHERE {$statusCondition}
                            GROUP BY period
                            ORDER BY period ASC";
                    break;
                case 'all_months':
                    $sql = "SELECT DATE_FORMAT(reading_date, '%Y-%m') as period,
                            SUM(total) as revenue
                            FROM billing_list
                            WHERE {$statusCondition}
                            GROUP BY period
                            ORDER BY period ASC";
                    break;
                case 'quarterly':
                    // Include all historical data to show old imported records
                    $sql = "SELECT CONCAT(YEAR(reading_date), '-Q', QUARTER(reading_date)) as period,
                            SUM(total) as revenue
                            FROM billing_list
                            WHERE {$statusCondition}
                            GROUP BY period
                            ORDER BY period ASC";
                    break;
                case 'yearly':
                    // Include all historical data to show old imported records
                    $sql = "SELECT YEAR(reading_date) as period,
                            SUM(total) as revenue
                            FROM billing_list
                            WHERE {$statusCondition}
                            GROUP BY period
                            ORDER BY period ASC";
                    break;
            case 'all_years':
                $sql = "SELECT YEAR(reading_date) as period,
                        SUM(total) as revenue
                        FROM billing_list
                        WHERE {$statusCondition}
                        GROUP BY period
                        ORDER BY period ASC";
                break;
        }
        
        $result = $this->conn->query($sql);
        $data = [];
        while($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        return $data;
    }
    
    public function getPaymentStatusData() {
        // Get only the LATEST bill per customer (most recent billing period)
        $sql = "SELECT 
                bl.id,
                bl.status,
                bl.due_date,
                bl.total,
                CASE 
                    WHEN bl.status = 1 THEN 'paid'
                    WHEN bl.status = 0 AND bl.due_date < CURRENT_DATE() THEN 'overdue'
                    WHEN bl.status = 0 THEN 'pending'
                END as payment_status
                FROM billing_list bl
                INNER JOIN (
                    SELECT client_id, MAX(id) as latest_bill_id
                    FROM billing_list
                    GROUP BY client_id
                ) latest ON bl.id = latest.latest_bill_id
                ORDER BY bl.id";
        
        $result = $this->conn->query($sql);
        
        // Initialize counters
        $data = [
            'paid' => 0,
            'pending' => 0,
            'overdue' => 0,
            'total_bills' => 0
        ];
        
        // Count each status (latest bill per customer only)
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $data['total_bills']++;
                $data[$row['payment_status']]++;
            }
        }
        
        // Calculate exact percentages
        $total = $data['total_bills'];
        if ($total > 0) {
            $data['paid_percentage'] = round(($data['paid'] / $total) * 100);
            $data['pending_percentage'] = round(($data['pending'] / $total) * 100);
            $data['overdue_percentage'] = round(($data['overdue'] / $total) * 100);
        } else {
            $data['paid_percentage'] = 0;
            $data['pending_percentage'] = 0;
            $data['overdue_percentage'] = 0;
        }
        
        return $data;
    }
    
    public function getRecentTransactions($limit = 10) {
        // Get only the latest bill per customer to avoid duplicates
        // This ensures each customer appears only once in the list
        $sql = "SELECT b.*, c.firstname, c.lastname
                FROM billing_list b
                JOIN client_list c ON b.client_id = c.id
                INNER JOIN (
                    SELECT client_id, MAX(id) as latest_bill_id
                    FROM billing_list
                    GROUP BY client_id
                ) latest ON b.id = latest.latest_bill_id
                WHERE c.delete_flag = 0 AND c.status = 1
                ORDER BY b.reading_date DESC
                LIMIT ?";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $transactions = [];
        while($row = $result->fetch_assoc()) {
            $transactions[] = $row;
        }
        return $transactions;
    }

    /**
     * Total water consumption (all time) in cubic meters.
     * Uses reading - previous from billing_list.
     */
    public function getTotalConsumption() {
        $sql = "SELECT SUM(reading - previous) AS total_cubic 
                FROM billing_list 
                WHERE reading IS NOT NULL AND previous IS NOT NULL";
        $result = $this->conn->query($sql);
        if ($result && $row = $result->fetch_assoc()) {
            return (float) ($row['total_cubic'] ?? 0);
        }
        return 0;
    }

    /**
     * Total water consumption per purok (entire barangay breakdown).
     * If a 'purok' column doesn't exist, falls back to using client address.
     */
    public function getConsumptionPerPurok() {
        // Detect if 'purok' column exists in client_list
        $hasPurok = false;
        if ($colResult = $this->conn->query("SHOW COLUMNS FROM client_list LIKE 'purok'")) {
            $hasPurok = $colResult->num_rows > 0;
        }

        if ($hasPurok) {
            $sql = "SELECT 
                        COALESCE(cl.purok, 'Unspecified') AS area_label,
                        SUM(b.reading - b.previous) AS total_cubic
                    FROM billing_list b
                    JOIN client_list cl ON b.client_id = cl.id
                    WHERE b.reading IS NOT NULL 
                      AND b.previous IS NOT NULL
                      AND cl.delete_flag = 0
                    GROUP BY COALESCE(cl.purok, 'Unspecified')
                    ORDER BY area_label ASC";
        } else {
            // Fallback: group by address when purok column is not available
            $sql = "SELECT 
                        COALESCE(cl.address, 'Unspecified') AS area_label,
                        SUM(b.reading - b.previous) AS total_cubic
                    FROM billing_list b
                    JOIN client_list cl ON b.client_id = cl.id
                    WHERE b.reading IS NOT NULL 
                      AND b.previous IS NOT NULL
                      AND cl.delete_flag = 0
                    GROUP BY COALESCE(cl.address, 'Unspecified')
                    ORDER BY area_label ASC";
        }

        $result = $this->conn->query($sql);
        $data = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
        }
        return $data;
    }

    public function getPaymentPredictions() {
        // Calculate expected payments for next month based on average of last 3 months
        $sql = "SELECT AVG(monthly_total) as expected_payment
                FROM (
                    SELECT SUM(total) as monthly_total 
                    FROM billing_list 
                    WHERE reading_date >= DATE_SUB(CURRENT_DATE, INTERVAL 3 MONTH)
                    GROUP BY YEAR(reading_date), MONTH(reading_date)
                    ORDER BY reading_date DESC
                    LIMIT 3
                ) as last_3_months";
        
        $result = $this->conn->query($sql);
        $expected_payment = 0;
        if ($result && $row = $result->fetch_assoc()) {
            $expected_payment = $row['expected_payment'] ?? 0;
        }

        // Calculate predicted late payments based on current trends
        $sql = "SELECT COUNT(*) as late_count
                FROM billing_list
                WHERE status = 0 
                AND due_date < CURRENT_DATE
                AND reading_date >= DATE_SUB(CURRENT_DATE, INTERVAL 1 MONTH)";
        
        $result = $this->conn->query($sql);
        $predicted_late = 0;
        if ($result && $row = $result->fetch_assoc()) {
            $predicted_late = $row['late_count'];
        }

        // Calculate payment trend by comparing current month's paid ratio with previous month
        $sql = "SELECT 
                COUNT(CASE WHEN status = 1 THEN 1 END) as paid_count,
                COUNT(*) as total_count
                FROM billing_list
                WHERE reading_date >= DATE_SUB(CURRENT_DATE, INTERVAL 1 MONTH)";
        
        $result = $this->conn->query($sql);
        $payment_trend = 0;
        if ($result && $row = $result->fetch_assoc()) {
            $current_rate = $row['total_count'] > 0 ? 
                ($row['paid_count'] / $row['total_count']) * 100 : 0;
            
            // Get previous month's payment rate
            $sql = "SELECT 
                    COUNT(CASE WHEN status = 1 THEN 1 END) as paid_count,
                    COUNT(*) as total_count
                    FROM billing_list
                    WHERE reading_date BETWEEN 
                        DATE_SUB(CURRENT_DATE, INTERVAL 2 MONTH) AND
                        DATE_SUB(CURRENT_DATE, INTERVAL 1 MONTH)";
            
            $prev_result = $this->conn->query($sql);
            if ($prev_result && $prev_row = $prev_result->fetch_assoc()) {
                $prev_rate = $prev_row['total_count'] > 0 ? 
                    ($prev_row['paid_count'] / $prev_row['total_count']) * 100 : 0;
                
                $payment_trend = round($current_rate - $prev_rate, 1);
            }
        }

        // Calculate average delay (in days) for overdue bills
        $sql = "SELECT AVG(DATEDIFF(CURRENT_DATE, due_date)) as avg_delay
                FROM billing_list
                WHERE status = 0 
                AND due_date < CURRENT_DATE
                AND reading_date >= DATE_SUB(CURRENT_DATE, INTERVAL 1 MONTH)";
        
        $result = $this->conn->query($sql);
        $avg_delay = 0;
        if ($result && $row = $result->fetch_assoc()) {
            $avg_delay = round($row['avg_delay'] ?? 0, 1);
        }

        return [
            'expected_payment' => $expected_payment,
            'predicted_late' => $predicted_late,
            'avg_delay' => $avg_delay,
            'payment_trend' => $payment_trend
        ];
    }

    public function getReportsStatus() {
        // Get total reports
        $total_sql = "SELECT COUNT(*) as total FROM outage_reports";
        $result = $this->conn->query($total_sql);
        $total_reports = ($result && $row = $result->fetch_assoc()) ? $row['total'] : 0;

        // Get resolved reports
        $resolved_sql = "SELECT COUNT(*) as resolved FROM outage_reports WHERE status = 1";
        $result = $this->conn->query($resolved_sql);
        $resolved_reports = ($result && $row = $result->fetch_assoc()) ? $row['resolved'] : 0;

        // Get pending reports
        $pending_reports = $total_reports - $resolved_reports;

        // Calculate average resolution time for resolved reports
        $avg_time_sql = "SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, IFNULL(resolved_at, NOW()))) as avg_time 
                        FROM outage_reports 
                        WHERE status = 1";
        $result = $this->conn->query($avg_time_sql);
        $avg_resolution_time = ($result && $row = $result->fetch_assoc()) ? round($row['avg_time'] ?? 0) : 0;

        return [
            'total_reports' => $total_reports,
            'resolved_reports' => $resolved_reports,
            'pending_reports' => $pending_reports,
            'avg_resolution_time' => $avg_resolution_time
        ];
    }

    /**
     * Attempt ML forecast via external service (e.g., LightGBM)
     */
    private function callMlForecastService($historical, $forecastMonths = 6) {
        // Compose payload
        $payload = [
            'history' => $historical, // array of ['period' => 'YYYY-MM', 'revenue' => float]
            'horizon' => intval($forecastMonths)
        ];

        $ch = curl_init();
        // Default service URL; adjust if different
        $url = getenv('ML_FORECAST_URL') ?: 'http://127.0.0.1:5000/forecast';
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($response && $httpCode >= 200 && $httpCode < 300) {
            $decoded = json_decode($response, true);
            if (isset($decoded['forecast']) && is_array($decoded['forecast'])) {
                // Ensure format: [['period' => 'YYYY-MM', 'revenue' => float], ...]
                return $decoded['forecast'];
            }
        }
        return null; // signal failure
    }
    
    /**
     * Get per-customer monthly readings and payments aggregation
     */
    public function getCustomerMonthlyData($clientId, $months = 24) {
        $clientId = intval($clientId);
        $months = intval($months);
        if ($months <= 0) { $months = 24; }

        // Monthly billed and consumption from billing_list
        $billingSql = "SELECT 
                DATE_FORMAT(reading_date, '%Y-%m') AS period,
                SUM(GREATEST((reading - previous), 0)) AS consumption,
                SUM(total) AS billed,
                SUM(CASE WHEN status = 1 THEN total ELSE 0 END) AS billed_paid_marker
            FROM billing_list
            WHERE client_id = ?
              AND reading_date >= DATE_SUB(CURRENT_DATE(), INTERVAL ? MONTH)
            GROUP BY period
            ORDER BY period ASC";
        $stmt = $this->conn->prepare($billingSql);
        $stmt->bind_param("ii", $clientId, $months);
        $stmt->execute();
        $billingResult = $stmt->get_result();
        $billingData = [];
        while ($row = $billingResult->fetch_assoc()) {
            $billingData[$row['period']] = [
                'period' => $row['period'],
                'consumption' => floatval($row['consumption'] ?? 0),
                'billed' => floatval($row['billed'] ?? 0),
                'paid' => 0.0
            ];
        }

        // Monthly paid from payment_list (only successful payments)
        $paymentsSql = "SELECT 
                DATE_FORMAT(payment_date, '%Y-%m') AS period,
                SUM(amount) AS paid
            FROM payment_list
            WHERE client_id = ?
              AND status = 1
              AND payment_date >= DATE_SUB(CURRENT_DATE(), INTERVAL ? MONTH)
            GROUP BY period
            ORDER BY period ASC";
        $stmt2 = $this->conn->prepare($paymentsSql);
        $stmt2->bind_param("ii", $clientId, $months);
        $stmt2->execute();
        $paymentsResult = $stmt2->get_result();
        while ($row = $paymentsResult->fetch_assoc()) {
            if (!isset($billingData[$row['period']])) {
                $billingData[$row['period']] = [
                    'period' => $row['period'],
                    'consumption' => 0.0,
                    'billed' => 0.0,
                    'paid' => floatval($row['paid'] ?? 0)
                ];
            } else {
                $billingData[$row['period']]['paid'] = floatval($row['paid'] ?? 0);
            }
        }

        // Normalize to sorted array and compute balance
        ksort($billingData);
        $out = [];
        foreach ($billingData as $p => $d) {
            $d['balance'] = max(0, ($d['billed'] ?? 0) - ($d['paid'] ?? 0));
            $out[] = $d;
        }
        return $out;
    }
    
    /**
     * Get revenue data with forecasting
     */
    /**
     * Get pending bills revenue data
     */
        public function getPendingRevenueData($period = 'monthly') {
            $sql = "";
            switch($period) {
                case 'monthly':
                    // Include all historical data to show old imported records
                    $sql = "SELECT DATE_FORMAT(reading_date, '%Y-%m') as period,
                            SUM(total) as revenue
                            FROM billing_list
                            WHERE status = 0
                            GROUP BY period
                            ORDER BY period ASC";
                    break;
                case 'all_months':
                    $sql = "SELECT DATE_FORMAT(reading_date, '%Y-%m') as period,
                            SUM(total) as revenue
                            FROM billing_list
                            WHERE status = 0
                            GROUP BY period
                            ORDER BY period ASC";
                    break;
                case 'quarterly':
                    // Include all historical data to show old imported records
                    $sql = "SELECT CONCAT(YEAR(reading_date), '-Q', QUARTER(reading_date)) as period,
                            SUM(total) as revenue
                            FROM billing_list
                            WHERE status = 0
                            GROUP BY period
                            ORDER BY period ASC";
                    break;
                case 'yearly':
                    // Include all historical data to show old imported records
                    $sql = "SELECT YEAR(reading_date) as period,
                            SUM(total) as revenue
                            FROM billing_list
                            WHERE status = 0
                            GROUP BY period
                            ORDER BY period ASC";
                    break;
                case 'all_years':
                    $sql = "SELECT YEAR(reading_date) as period,
                            SUM(total) as revenue
                            FROM billing_list
                            WHERE status = 0
                            GROUP BY period
                            ORDER BY period ASC";
                    break;
            }
        $result = $this->conn->query($sql);
        $data = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
        }
        return $data;
    }
    
    /**
     * Get pending bills revenue with forecast
     */
    public function getPendingRevenueWithForecast($period = 'monthly', $forecastMethod = 'ensemble', $forecastMonths = 6) {
        $forecast = new RevenueForecast($this->conn);
        // Always provide actuals for pending bills
        $actualData = $this->getPendingRevenueData($period);

        // Get comprehensive forecast for pending bills
        $comprehensive = $forecast->getPendingComprehensiveForecast($forecastMonths);
        $selectedForecast = [];
        if (isset($comprehensive['forecasts'][$forecastMethod])) {
            $selectedForecast = $comprehensive['forecasts'][$forecastMethod];
        } else {
            // Default to ensemble if requested method missing
            $selectedForecast = $comprehensive['forecasts']['ensemble'] ?? [];
        }

        return [
            'actual' => $actualData,
            'forecast' => $selectedForecast
        ];
    }
    
    public function getRevenueWithForecast($period = 'monthly', $forecastMethod = 'seasonal', $forecastMonths = 6) {
        try {
            $forecast = new RevenueForecast($this->conn);
            // Always provide actuals
            $actualData = $this->getRevenueData($period);

            $normalizedForecastMethod = strtolower(trim((string) $forecastMethod));
            $methodAliases = [
                'holt' => 'exponential',
                'holt_linear' => 'exponential',
            ];
            if (isset($methodAliases[$normalizedForecastMethod])) {
                $normalizedForecastMethod = $methodAliases[$normalizedForecastMethod];
            }

            // Prefer embedded PHP ML when requested
            if ($normalizedForecastMethod === 'ml') {
                $phpMlForecast = $this->embeddedPhpMlForecast($forecastMonths);
                if (!empty($phpMlForecast)) {
                    return [ 'actual' => $actualData, 'forecast' => $phpMlForecast ];
                }
                // fallback to seasonal
                $normalizedForecastMethod = 'seasonal';
            }

            // PHP-native forecasts (fallbacks and for non-ML methods)
            $comprehensive = $forecast->getComprehensiveForecast($forecastMonths);
            $selectedForecast = [];
            
            // Log available methods for debugging
            if (isset($comprehensive['forecasts'])) {
                error_log("Forecast Debug: Available methods: " . implode(', ', array_keys($comprehensive['forecasts'])));
                error_log("Forecast Debug: Requested method: " . $normalizedForecastMethod);
            } else {
                error_log("Forecast Debug: No 'forecasts' key in comprehensive result");
            }
            
            if (isset($comprehensive['forecasts'][$normalizedForecastMethod])) {
                $selectedForecast = $comprehensive['forecasts'][$normalizedForecastMethod];
                error_log("Forecast Debug: Selected forecast method '{$normalizedForecastMethod}' returned " . count($selectedForecast) . " points");
            } else {
                // Default to seasonal if requested method missing
                error_log("Forecast Debug: Method '{$normalizedForecastMethod}' not found, falling back to seasonal");
                $selectedForecast = $comprehensive['forecasts']['seasonal'] ?? [];
                if (empty($selectedForecast)) {
                    // Try linear as last resort
                    $selectedForecast = $comprehensive['forecasts']['linear'] ?? [];
                    error_log("Forecast Debug: Seasonal also empty, trying linear: " . count($selectedForecast) . " points");
                }
            }

            return [
                'actual' => $actualData,
                'forecast' => $selectedForecast
            ];
        } catch (\Throwable $e) {
            error_log("Forecast Error in getRevenueWithForecast: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            // Return empty forecast but keep actual data
            return [
                'actual' => $this->getRevenueData($period),
                'forecast' => [],
                'error' => 'Forecast generation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Load a trained revenue forecast file produced outside the app.
     * Supports CSV or JSON with common forecast column names.
     */
    public function getTrainedRevenueForecast($sourcePath = null) {
        try {
            $candidates = [];
            if (!empty($sourcePath)) {
                $candidates[] = $sourcePath;
            }

            $candidates = array_merge($candidates, [
                __DIR__ . DIRECTORY_SEPARATOR . 'trained_revenue_forecast.csv',
                __DIR__ . DIRECTORY_SEPARATOR . 'forecast.csv',
                __DIR__ . DIRECTORY_SEPARATOR . 'colab' . DIRECTORY_SEPARATOR . 'forecast.csv',
                __DIR__ . DIRECTORY_SEPARATOR . 'trained_revenue_forecast.json'
            ]);

            $existingFile = null;
            foreach ($candidates as $candidate) {
                if (!empty($candidate) && file_exists($candidate)) {
                    $existingFile = $candidate;
                    break;
                }
            }

            if ($existingFile === null) {
                return [
                    'forecast' => [],
                    'source' => null,
                    'error' => 'No trained forecast file found. Save your Colab output as trained_revenue_forecast.csv or forecast.csv.'
                ];
            }

            $extension = strtolower(pathinfo($existingFile, PATHINFO_EXTENSION));
            $forecastPoints = [];

            if ($extension === 'json') {
                $raw = file_get_contents($existingFile);
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    throw new Exception('Invalid JSON forecast file');
                }
                foreach ($decoded as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $period = $row['period'] ?? $row['ds'] ?? $row['date'] ?? $row['month'] ?? null;
                    $value = $row['revenue'] ?? $row['yhat'] ?? $row['value'] ?? $row['forecast'] ?? $row['prediction'] ?? null;
                    if ($period === null || $value === null) {
                        continue;
                    }
                    $forecastPoints[] = [
                        'period' => (string)$period,
                        'revenue' => max(0, (float)$value)
                    ];
                }
            } else {
                $handle = fopen($existingFile, 'r');
                if ($handle === false) {
                    throw new Exception('Unable to open trained forecast file');
                }

                $headers = fgetcsv($handle);
                if ($headers === false) {
                    fclose($handle);
                    throw new Exception('Trained forecast file is empty');
                }

                $normalizedHeaders = array_map(function($header) {
                    return strtolower(trim((string)$header));
                }, $headers);

                while (($row = fgetcsv($handle)) !== false) {
                    if ($row === [null] || empty($row)) {
                        continue;
                    }

                    $mapped = [];
                    foreach ($normalizedHeaders as $index => $header) {
                        $mapped[$header] = isset($row[$index]) ? trim((string)$row[$index]) : '';
                    }

                    $period = $mapped['period'] ?? $mapped['ds'] ?? $mapped['date'] ?? $mapped['month'] ?? $mapped['datetime'] ?? null;
                    $value = $mapped['revenue'] ?? $mapped['yhat'] ?? $mapped['value'] ?? $mapped['forecast'] ?? $mapped['prediction'] ?? null;
                    if ($period === null || $period === '' || $value === null || $value === '') {
                        continue;
                    }

                    $forecastPoints[] = [
                        'period' => $period,
                        'revenue' => max(0, (float)str_replace(',', '', $value))
                    ];
                }

                fclose($handle);
            }

            usort($forecastPoints, function($left, $right) {
                return strcmp((string)$left['period'], (string)$right['period']);
            });

            return [
                'forecast' => $forecastPoints,
                'source' => basename($existingFile),
                'source_path' => $existingFile,
                'count' => count($forecastPoints)
            ];
        } catch (\Throwable $e) {
            error_log('Trained forecast load error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return [
                'forecast' => [],
                'source' => null,
                'error' => 'Failed to load trained forecast: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Embedded PHP ML forecast using Rubix ML Gradient Boosted regression.
     */
    private function embeddedPhpMlForecast($forecastMonths = 6) {
        try {
            // Check if vendor/autoload.php exists
            $autoloadPath = __DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
            if (!file_exists($autoloadPath)) {
                error_log("ML Forecast: vendor/autoload.php not found at: " . $autoloadPath);
                return [];
            }
            // Build ALL historical monthly revenue (no date limit) to include old imported records
            $historical = [];
            $res = $this->conn->query("SELECT DATE_FORMAT(reading_date, '%Y-%m') as period, SUM(total) as revenue FROM billing_list WHERE status = 1 GROUP BY period ORDER BY period ASC");
            while ($row = $res->fetch_assoc()) {
                $historical[] = ['period' => $row['period'], 'revenue' => (float)$row['revenue']];
            }
            if (count($historical) < 6) return [];

            // Feature engineering
            $monthsIndex = [];
            foreach ($historical as $h) {
                [$y, $m] = array_map('intval', explode('-', $h['period']));
                $monthsIndex[] = $y * 12 + $m;
            }
            $yVals = array_map(function($h){ return (float)$h['revenue']; }, $historical);

            $lag = function($arr, $k){ $out = array_fill(0, count($arr), null); for($i=$k; $i<count($arr); $i++){ $out[$i] = $arr[$i-$k]; } return $out; };
            $roll = function($arr, $w){ $out = array_fill(0, count($arr), null); for($i=$w-1;$i<count($arr);$i++){ $sum=0;$cnt=0; for($j=$i-$w+1;$j<=$i;$j++){ if ($arr[$j] !== null) { $sum += $arr[$j]; $cnt++; } } $out[$i] = $cnt? $sum/$cnt : null; } return $out; };

            $monthsNum = array_map(function($idx){ $m = $idx % 12; return $m === 0 ? 12 : $m; }, $monthsIndex);
            $t = range(1, count($yVals));
            $lag1 = $lag($yVals,1); $lag2=$lag($yVals,2); $lag3=$lag($yVals,3); $lag12=$lag($yVals,12);
            $rm3 = $roll($yVals,3); $rm6 = $roll($yVals,6); $rm12=$roll($yVals,12);

            $X = []; $y = [];
            for ($i=0; $i<count($yVals); $i++) {
                $row = [$monthsNum[$i], $t[$i], $lag1[$i], $lag2[$i], $lag3[$i], $lag12[$i], $rm3[$i], $rm6[$i], $rm12[$i]];
                if (in_array(null, $row, true)) continue;
                $X[] = $row; $y[] = $yVals[$i];
            }
            if (count($y) < 4) return [];

            // Instantiate Rubix ML model (Gradient Boosted Regression Trees)
            $gbClass = '\\Rubix\\ML\\Regressors\\GradientBoost';
            $rtClass = '\\Rubix\\ML\\Regressors\\RegressionTree';
            $pmClass = '\\Rubix\\ML\\PersistentModel';
            $fsClass = '\\Rubix\\ML\\Persisters\\Filesystem';
            if (!class_exists($gbClass) || !class_exists($rtClass)) {
                error_log("ML Forecast: Rubix ML classes not found. GradientBoost: " . (class_exists($gbClass) ? 'yes' : 'no') . ", RegressionTree: " . (class_exists($rtClass) ? 'yes' : 'no'));
                return [];
            }

            // Prepare persistence
            $modelsDir = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'models';
            if (!is_dir($modelsDir)) { 
                if (!@mkdir($modelsDir, 0775, true)) {
                    error_log("ML Forecast: Failed to create storage/models directory: " . $modelsDir);
                    return [];
                }
            }
            if (!is_writable($modelsDir)) {
                error_log("ML Forecast: storage/models directory is not writable: " . $modelsDir);
                return [];
            }
            $modelPath = $modelsDir . DIRECTORY_SEPARATOR . 'revenue_gbr.model';

            // Build estimator and persistent wrapper
            $estimator = new \Rubix\ML\Regressors\GradientBoost(
                new \Rubix\ML\Regressors\RegressionTree(3), // shallow trees as weak learners
                300,
                0.05
            );
            $persist = new \Rubix\ML\Persisters\Filesystem($modelPath, true);
            $model = new \Rubix\ML\PersistentModel($estimator, $persist);

            // Train or load
            if (file_exists($modelPath)) {
                $model->load();
            }
            // Always (re)train if we don't have enough training history saved or model just loaded without history alignment
            // For simplicity, retrain each call; model saving ensures deployment-friendly persistence
            $model->train($X, $y);
            $model->save();

            // Iterative forecast
            $lastIdx = end($monthsIndex);
            $yAll = $yVals;
            $forecast = [];
            for ($i=1; $i<=intval($forecastMonths); $i++) {
                $nextIdx = $lastIdx + $i;
                $m = $nextIdx % 12; $m = $m === 0 ? 12 : $m;
                $tFuture = count($yAll) + 1;
                // recompute lags/rolls from yAll
                $lag1v = $yAll[count($yAll)-1] ?? null;
                $lag2v = $yAll[count($yAll)-2] ?? null;
                $lag3v = $yAll[count($yAll)-3] ?? null;
                $lag12v = $yAll[count($yAll)-12] ?? null;
                $rm3v = array_sum(array_slice($yAll, max(0,count($yAll)-3), 3)) / min(3, count($yAll));
                $rm6v = array_sum(array_slice($yAll, max(0,count($yAll)-6), 6)) / min(6, count($yAll));
                $rm12v = array_sum(array_slice($yAll, max(0,count($yAll)-12), 12)) / min(12, count($yAll));
                $row = [$m, $tFuture, $lag1v, $lag2v, $lag3v, $lag12v, $rm3v, $rm6v, $rm12v];
                // if any nulls due to short history, backoff to last known value
                if (in_array(null, $row, true)) { $pred = end($yAll); }
                else { $pred = $model->predict([$row])[0]; }
                $pred = max(0.0, (float)$pred);
                $yAll[] = $pred;
                $year = intdiv($nextIdx,12); $month = $nextIdx % 12; if ($month==0){$year-=1;$month=12;}
                $forecast[] = ['period' => sprintf('%04d-%02d', $year, $month), 'revenue' => $pred];
            }
            return $forecast;
        } catch (\Throwable $e) {
            error_log("ML Forecast Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            return [];
        }
    }
}

// Create instance and handle AJAX requests
if(isset($_GET['action'])) {
    $dashboard = new DashboardData($conn);
    $response = [];
    
    switch($_GET['action']) {
        case 'all':
            $response = [
                'total_clients' => $dashboard->getTotalClients(),
                'current_month_revenue' => $dashboard->getCurrentMonthRevenue(),
                'pending_payments' => $dashboard->getPendingPayments(),
                'average_bill' => $dashboard->getAverageBill(),
                'payment_status' => $dashboard->getPaymentStatusData(),
                'recent_transactions' => $dashboard->getRecentTransactions()
            ];
            break;
        case 'revenue_data':
            $period = $_GET['period'] ?? 'monthly';
            $response = $dashboard->getRevenueData($period);
            break;
        case 'revenue_forecast':
            $period = $_GET['period'] ?? 'monthly';
            $forecastMethod = $_GET['forecast_method'] ?? 'seasonal';
            $forecastMonths = intval($_GET['forecast_months'] ?? 6);
            try {
                $response = $dashboard->getRevenueWithForecast($period, $forecastMethod, $forecastMonths);
                // Add debug info in development
                if (isset($_GET['debug']) && $_GET['debug'] === '1') {
                    $response['debug'] = [
                        'method' => $forecastMethod,
                        'period' => $period,
                        'forecast_months' => $forecastMonths,
                        'actual_count' => count($response['actual'] ?? []),
                        'forecast_count' => count($response['forecast'] ?? [])
                    ];
                }
            } catch (\Throwable $e) {
                error_log("Forecast API Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
                $response = [
                    'actual' => [],
                    'forecast' => [],
                    'error' => 'Forecast generation failed: ' . $e->getMessage()
                ];
            }
            break;
        case 'trained_revenue_forecast':
            $sourcePath = $_GET['source_path'] ?? null;
            $response = $dashboard->getTrainedRevenueForecast($sourcePath);
            break;
        case 'pending_revenue_forecast':
            $period = $_GET['period'] ?? 'monthly';
            $forecastMethod = $_GET['forecast_method'] ?? 'ensemble';
            $forecastMonths = intval($_GET['forecast_months'] ?? 6);
            $response = $dashboard->getPendingRevenueWithForecast($period, $forecastMethod, $forecastMonths);
            break;
        case 'customer_monthly':
            $clientId = intval($_GET['client_id'] ?? 0);
            $months = intval($_GET['months'] ?? 24);
            if ($clientId <= 0) {
                $response = ['error' => 'Invalid client_id'];
            } else {
                $response = $dashboard->getCustomerMonthlyData($clientId, $months);
            }
            break;
    }
    
    header('Content-Type: application/json');
    
    // Add error handling for JSON encoding
    $jsonResponse = json_encode($response);
    if ($jsonResponse === false) {
        error_log("JSON Encode Error: " . json_last_error_msg());
        $response = [
            'error' => 'Failed to encode response',
            'json_error' => json_last_error_msg()
        ];
        $jsonResponse = json_encode($response);
    }
    
    echo $jsonResponse;
    exit();
} 