<?php
/**
 * Revenue Forecasting System
 * Provides various forecasting methods for revenue prediction
 */

class RevenueForecast {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }
    
    /**
     * Get historical revenue data for forecasting
     */
    public function getHistoricalData($months = null, $includePending = false) {
        $statusCondition = $includePending ? "status IN (0, 1)" : "status = 1";
        
        // If months is null or 0, get ALL historical data
        if ($months === null || $months <= 0) {
            $sql = "SELECT 
                    DATE_FORMAT(reading_date, '%Y-%m') as period,
                    SUM(total) as revenue,
                    COUNT(*) as bill_count,
                    AVG(total) as avg_bill
                    FROM billing_list 
                    WHERE {$statusCondition}
                    GROUP BY period 
                    ORDER BY period ASC";
            
            $result = $this->conn->query($sql);
            return $result->fetch_all(MYSQLI_ASSOC);
        }
        
        // Otherwise, limit to specified months
        $sql = "SELECT 
                DATE_FORMAT(reading_date, '%Y-%m') as period,
                SUM(total) as revenue,
                COUNT(*) as bill_count,
                AVG(total) as avg_bill
                FROM billing_list 
                WHERE {$statusCondition}
                AND reading_date >= DATE_SUB(CURRENT_DATE(), INTERVAL ? MONTH)
                GROUP BY period 
                ORDER BY period ASC";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $months);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    
    /**
     * Get historical pending bills data for forecasting
     */
    public function getPendingHistoricalData($months = null) {
        // If months is null or 0, get ALL historical pending data
        if ($months === null || $months <= 0) {
            $sql = "SELECT 
                    DATE_FORMAT(reading_date, '%Y-%m') as period,
                    SUM(total) as revenue,
                    COUNT(*) as bill_count,
                    AVG(total) as avg_bill
                    FROM billing_list 
                    WHERE status = 0 
                    GROUP BY period 
                    ORDER BY period ASC";
            
            $result = $this->conn->query($sql);
            return $result->fetch_all(MYSQLI_ASSOC);
        }
        
        // Otherwise, limit to specified months
        $sql = "SELECT 
                DATE_FORMAT(reading_date, '%Y-%m') as period,
                SUM(total) as revenue,
                COUNT(*) as bill_count,
                AVG(total) as avg_bill
                FROM billing_list 
                WHERE status = 0 
                AND reading_date >= DATE_SUB(CURRENT_DATE(), INTERVAL ? MONTH)
                GROUP BY period 
                ORDER BY period ASC";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $months);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    
    /**
     * Linear Regression Forecasting
     * Predicts future revenue based on linear trend
     */
    public function linearForecast($historicalData, $forecastMonths = 6) {
        if (count($historicalData) < 1) {
            return [];
        }
        
        // If only one data point, use it as the base for forecast
        if (count($historicalData) === 1) {
            $lastPeriod = $historicalData[0]['period'];
            $lastRevenue = floatval($historicalData[0]['revenue']);
            $forecasts = [];
            for ($i = 1; $i <= $forecastMonths; $i++) {
                $nextPeriod = date('Y-m', strtotime($lastPeriod . '-01 +' . $i . ' month'));
                $forecasts[] = [
                    'period' => $nextPeriod,
                    'revenue' => max(0, $lastRevenue), // Use same value for forecast
                    'type' => 'linear_forecast'
                ];
            }
            return $forecasts;
        }
        
        $n = count($historicalData);
        $sumX = 0; $sumY = 0; $sumXY = 0; $sumXX = 0;
        
        // Calculate linear regression coefficients
        for ($i = 0; $i < $n; $i++) {
            $x = $i + 1; // month number
            $y = floatval($historicalData[$i]['revenue']);
            
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumXX += $x * $x;
        }
        
        $slope = ($n * $sumXY - $sumX * $sumY) / ($n * $sumXX - $sumX * $sumX);
        $intercept = ($sumY - $slope * $sumX) / $n;
        
        // Generate forecasts
        $forecasts = [];
        $lastPeriod = end($historicalData)['period'];
        
        for ($i = 1; $i <= $forecastMonths; $i++) {
            $nextPeriod = date('Y-m', strtotime($lastPeriod . '-01 +' . $i . ' month'));
            $forecastValue = $slope * ($n + $i) + $intercept;
            
            $forecasts[] = [
                'period' => $nextPeriod,
                'revenue' => max(0, $forecastValue), // Ensure non-negative
                'type' => 'linear_forecast'
            ];
        }
        
        return $forecasts;
    }
    
    /**
     * Moving Average Forecasting
     * Predicts based on average of recent periods
     */
    public function movingAverageForecast($historicalData, $forecastMonths = 6, $windowSize = 3) {
        if (count($historicalData) < 1) {
            return [];
        }
        
        // If less than windowSize, use available data
        $actualWindowSize = min(count($historicalData), $windowSize);
        
        // Calculate moving average for the last window
        $recentData = array_slice($historicalData, -$actualWindowSize);
        $avgRevenue = array_sum(array_column($recentData, 'revenue')) / $actualWindowSize;
        
        // Generate forecasts
        $forecasts = [];
        $lastPeriod = end($historicalData)['period'];
        
        for ($i = 1; $i <= $forecastMonths; $i++) {
            $nextPeriod = date('Y-m', strtotime($lastPeriod . '-01 +' . $i . ' month'));
            
            $forecasts[] = [
                'period' => $nextPeriod,
                'revenue' => $avgRevenue,
                'type' => 'moving_average_forecast'
            ];
        }
        
        return $forecasts;
    }
    
    /**
     * Exponential Smoothing (Holt's Linear Method)
     * Time-series model that smooths level and trend with exponential weights.
     *
     * @param array $historicalData Array of ['period' => 'YYYY-MM', 'revenue' => number]
     * @param int   $forecastMonths Number of future months to forecast
     * @param float $alpha          Smoothing factor for level (0 < α ≤ 1)
     * @param float $beta           Smoothing factor for trend (0 < β ≤ 1)
     */
    public function exponentialSmoothingForecast($historicalData, $forecastMonths = 6, $alpha = 0.3, $beta = 0.1) {
        if (count($historicalData) < 1) {
            return [];
        }

        // Sort by period just in case
        usort($historicalData, function($a, $b) {
            return strcmp($a['period'], $b['period']);
        });

        // Initialize level (L) and trend (T)
        $y0 = floatval($historicalData[0]['revenue']);
        $L = $y0;
        $T = 0.0;
        if (count($historicalData) >= 2) {
            $y1 = floatval($historicalData[1]['revenue']);
            $T = $y1 - $y0; // initial trend estimate
        }

        // Update level and trend for all historical points
        $lastPeriod = $historicalData[0]['period'];
        foreach ($historicalData as $index => $data) {
            $Yt = floatval($data['revenue']);
            if ($index === 0) {
                $lastPeriod = $data['period'];
                continue; // already used as initialization
            }

            $Lt_prev = $L;
            $L = $alpha * $Yt + (1 - $alpha) * ($L + $T);
            $T = $beta * ($L - $Lt_prev) + (1 - $beta) * $T;
            $lastPeriod = $data['period'];
        }

        // Generate forecasts m steps ahead: F_{t+m} = L_t + m*T_t
        $forecasts = [];
        for ($m = 1; $m <= $forecastMonths; $m++) {
            $nextPeriod = date('Y-m', strtotime($lastPeriod . '-01 +' . $m . ' month'));
            $forecastValue = $L + $m * $T;

            $forecasts[] = [
                'period' => $nextPeriod,
                'revenue' => max(0, $forecastValue),
                'type' => 'exponential_smoothing_forecast'
            ];
        }

        return $forecasts;
    }
    
    /**
     * Seasonal Forecasting
     * Considers seasonal patterns and growth trends
     */
    public function seasonalForecast($historicalData, $forecastMonths = 6) {
        if (count($historicalData) < 12) {
            // If less than a year of data, fall back to linear forecast
            return $this->linearForecast($historicalData, $forecastMonths);
        }
        
        // Calculate seasonal indices
        $monthlyTotals = [];
        $monthlyCount = [];
        $grandTotal = 0;
        
        foreach ($historicalData as $data) {
            $month = date('n', strtotime($data['period'] . '-01')); // 1-12
            $revenue = floatval($data['revenue']);
            
            if (!isset($monthlyTotals[$month])) {
                $monthlyTotals[$month] = 0;
                $monthlyCount[$month] = 0;
            }
            
            $monthlyTotals[$month] += $revenue;
            $monthlyCount[$month]++;
            $grandTotal += $revenue;
        }
        
        $overallAvg = $grandTotal / count($historicalData);
        $seasonalIndices = [];
        
        for ($month = 1; $month <= 12; $month++) {
            if (isset($monthlyTotals[$month]) && $monthlyCount[$month] > 0) {
                $monthlyAvg = $monthlyTotals[$month] / $monthlyCount[$month];
                $seasonalIndices[$month] = $monthlyAvg / $overallAvg;
            } else {
                $seasonalIndices[$month] = 1.0; // No seasonal effect
            }
        }
        
        // Calculate growth trend
        $recentData = array_slice($historicalData, -6); // Last 6 months
        $earlierData = array_slice($historicalData, -12, 6); // 6 months before that
        
        $recentAvg = array_sum(array_column($recentData, 'revenue')) / count($recentData);
        $earlierAvg = array_sum(array_column($earlierData, 'revenue')) / count($earlierData);
        
        $growthRate = ($recentAvg - $earlierAvg) / $earlierAvg;
        $monthlyGrowthRate = $growthRate / 6; // Monthly growth rate
        
        // Generate seasonal forecasts
        $forecasts = [];
        $lastPeriod = end($historicalData)['period'];
        $baseRevenue = end($historicalData)['revenue'];
        
        for ($i = 1; $i <= $forecastMonths; $i++) {
            $nextPeriod = date('Y-m', strtotime($lastPeriod . '-01 +' . $i . ' month'));
            $month = date('n', strtotime($nextPeriod . '-01'));
            
            // Apply growth and seasonal adjustment
            $forecastValue = $baseRevenue * (1 + $monthlyGrowthRate * $i) * $seasonalIndices[$month];
            
            $forecasts[] = [
                'period' => $nextPeriod,
                'revenue' => max(0, $forecastValue),
                'type' => 'seasonal_forecast'
            ];
        }
        
        return $forecasts;
    }
    
    /**
     * Get comprehensive forecast with multiple methods
     */
    public function getComprehensiveForecast($forecastMonths = 6, $includePending = false) {
        // Get ALL historical data (null = no date limit) to include old imported records
        $historicalData = $this->getHistoricalData(null, $includePending);
        
        if (empty($historicalData)) {
            return [
                'historical' => [],
                'forecasts' => [],
                'methods' => []
            ];
        }
        
        // Get forecasts from different methods
        $linearForecast = $this->linearForecast($historicalData, $forecastMonths);
        $movingAvgForecast = $this->movingAverageForecast($historicalData, $forecastMonths);
        $expForecast = $this->exponentialSmoothingForecast($historicalData, $forecastMonths);
        $seasonalForecast = $this->seasonalForecast($historicalData, $forecastMonths);
        
        // Calculate ensemble forecast (average of available methods), guard for missing indexes
        $ensembleForecast = [];
        for ($i = 0; $i < $forecastMonths; $i++) {
            $values = [];
            $period = null;
            if (isset($linearForecast[$i])) {
                $values[] = (float)$linearForecast[$i]['revenue'];
                $period = $period ?? $linearForecast[$i]['period'];
            }
            if (isset($movingAvgForecast[$i])) {
                $values[] = (float)$movingAvgForecast[$i]['revenue'];
                $period = $period ?? $movingAvgForecast[$i]['period'];
            }
            if (isset($expForecast[$i])) {
                $values[] = (float)$expForecast[$i]['revenue'];
                $period = $period ?? $expForecast[$i]['period'];
            }
            if (isset($seasonalForecast[$i])) {
                $values[] = (float)$seasonalForecast[$i]['revenue'];
                $period = $period ?? $seasonalForecast[$i]['period'];
            }
            // If no method produced this step, synthesize period from last historical
            if ($period === null) {
                $lastPeriod = end($historicalData)['period'];
                $period = date('Y-m', strtotime($lastPeriod . '-01 +' . ($i + 1) . ' month'));
            }
            $avgRevenue = !empty($values) ? array_sum($values) / count($values) : 0.0;
            $ensembleForecast[] = [
                'period' => $period,
                'revenue' => $avgRevenue,
                'type' => 'ensemble_forecast'
            ];
        }
        
        return [
            'historical' => $historicalData,
            'forecasts' => [
                'linear' => $linearForecast,
                'moving_average' => $movingAvgForecast,
                'exponential' => $expForecast,
                'seasonal' => $seasonalForecast,
                'ensemble' => $ensembleForecast
            ],
            'methods' => [
                'linear' => 'Linear Trend',
                'moving_average' => 'Moving Average',
                'exponential' => 'Exponential Smoothing',
                'seasonal' => 'Seasonal Analysis',
                'ensemble' => 'Combined Forecast'
            ]
        ];
    }
    
    /**
     * Get comprehensive forecast for pending bills with multiple methods
     */
    public function getPendingComprehensiveForecast($forecastMonths = 6) {
        // Get ALL historical pending data (null = no date limit) to include old imported records
        $historicalData = $this->getPendingHistoricalData(null);
        
        if (empty($historicalData)) {
            return [
                'historical' => [],
                'forecasts' => [],
                'methods' => []
            ];
        }
        
        // Get forecasts from different methods
        $linearForecast = $this->linearForecast($historicalData, $forecastMonths);
        $movingAvgForecast = $this->movingAverageForecast($historicalData, $forecastMonths);
        $expForecast = $this->exponentialSmoothingForecast($historicalData, $forecastMonths);
        $seasonalForecast = $this->seasonalForecast($historicalData, $forecastMonths);
        
        // Calculate ensemble forecast (average of available methods), guard for missing indexes
        $ensembleForecast = [];
        for ($i = 0; $i < $forecastMonths; $i++) {
            $values = [];
            $period = null;
            if (isset($linearForecast[$i])) {
                $values[] = (float)$linearForecast[$i]['revenue'];
                $period = $period ?? $linearForecast[$i]['period'];
            }
            if (isset($movingAvgForecast[$i])) {
                $values[] = (float)$movingAvgForecast[$i]['revenue'];
                $period = $period ?? $movingAvgForecast[$i]['period'];
            }
            if (isset($expForecast[$i])) {
                $values[] = (float)$expForecast[$i]['revenue'];
                $period = $period ?? $expForecast[$i]['period'];
            }
            if (isset($seasonalForecast[$i])) {
                $values[] = (float)$seasonalForecast[$i]['revenue'];
                $period = $period ?? $seasonalForecast[$i]['period'];
            }
            // If no method produced this step, synthesize period from last historical
            if ($period === null) {
                $lastPeriod = end($historicalData)['period'];
                $period = date('Y-m', strtotime($lastPeriod . '-01 +' . ($i + 1) . ' month'));
            }
            $avgRevenue = !empty($values) ? array_sum($values) / count($values) : 0.0;
            $ensembleForecast[] = [
                'period' => $period,
                'revenue' => $avgRevenue,
                'type' => 'ensemble_forecast'
            ];
        }
        
        return [
            'historical' => $historicalData,
            'forecasts' => [
                'linear' => $linearForecast,
                'moving_average' => $movingAvgForecast,
                'exponential' => $expForecast,
                'seasonal' => $seasonalForecast,
                'ensemble' => $ensembleForecast
            ],
            'methods' => [
                'linear' => 'Linear Trend',
                'moving_average' => 'Moving Average',
                'exponential' => 'Exponential Smoothing',
                'seasonal' => 'Seasonal Analysis',
                'ensemble' => 'Combined Forecast'
            ]
        ];
    }
    
    /**
     * Calculate forecast accuracy metrics
     */
    public function calculateAccuracy($actualData, $forecastData) {
        if (count($actualData) !== count($forecastData)) {
            return null;
        }
        
        $n = count($actualData);
        $totalError = 0;
        $totalPercentError = 0;
        
        for ($i = 0; $i < $n; $i++) {
            $actual = floatval($actualData[$i]['revenue']);
            $forecast = floatval($forecastData[$i]['revenue']);
            
            $error = abs($actual - $forecast);
            $percentError = ($actual > 0) ? ($error / $actual) * 100 : 0;
            
            $totalError += $error;
            $totalPercentError += $percentError;
        }
        
        return [
            'mae' => $totalError / $n, // Mean Absolute Error
            'mape' => $totalPercentError / $n // Mean Absolute Percentage Error
        ];
    }
    
    /**
     * Get forecast confidence intervals
     */
    public function getForecastConfidence($historicalData, $forecasts) {
        if (count($historicalData) < 3) {
            return $forecasts; // Not enough data for confidence intervals
        }
        
        // Calculate historical variance
        $revenues = array_column($historicalData, 'revenue');
        $mean = array_sum($revenues) / count($revenues);
        $variance = 0;
        
        foreach ($revenues as $revenue) {
            $variance += pow($revenue - $mean, 2);
        }
        $variance = $variance / (count($revenues) - 1);
        $stdDev = sqrt($variance);
        
        // Add confidence intervals to forecasts
        $confidenceForecasts = [];
        foreach ($forecasts as $i => $forecast) {
            $forecastPeriod = $i + 1;
            $adjustedStdDev = $stdDev * sqrt(1 + 1/count($historicalData) + pow($forecastPeriod, 2));
            
            $confidenceForecasts[] = array_merge($forecast, [
                'confidence_lower' => max(0, $forecast['revenue'] - 1.96 * $adjustedStdDev),
                'confidence_upper' => $forecast['revenue'] + 1.96 * $adjustedStdDev
            ]);
        }
        
        return $confidenceForecasts;
    }
}
?> 