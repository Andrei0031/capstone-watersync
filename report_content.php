<?php
// Dashboard Overview
if ($report_type === 'dashboard') {
?>
    <!-- Key Metrics Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card metric-card text-center p-3">
                <h3>₱<?php echo number_format($report_data['collections']['verified_collected'] ?? 0, 2); ?></h3>
                <p class="mb-0">Total Collections</p>
                <small><?php echo $report_data['collections']['total_payments'] ?? 0; ?> payments</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card metric-card text-center p-3" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                <h3><?php echo $report_data['clients']['active_clients'] ?? 0; ?></h3>
                <p class="mb-0">Active Clients</p>
                <small><?php echo $report_data['clients']['total_clients'] ?? 0; ?> total clients</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card metric-card text-center p-3" style="background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%);">
                <h3><?php echo $report_data['clients']['inactive_clients'] ?? 0; ?></h3>
                <p class="mb-0">Inactive Clients</p>
                <small><?php echo $report_data['clients']['total_clients'] ?? 0; ?> total clients</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card metric-card text-center p-3" style="background: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%); color: #333;">
                <h3><?php echo $report_data['overdue']['overdue_clients'] ?? 0; ?></h3>
                <p class="mb-0">Overdue Clients</p>
                <small>₱<?php echo number_format($report_data['overdue']['overdue_amount'] ?? 0, 2); ?> overdue</small>
            </div>
        </div>
    </div>
    
    <!-- Customer Status Breakdown -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card report-card">
                <div class="card-header">
                    <h5><i class="fas fa-users me-2"></i>Customer Status Breakdown</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Customer Type</th>
                                            <th>Count</th>
                                            <th>Percentage</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>
                                                <i class="fas fa-check-circle text-success me-2"></i>
                                                <strong>Active Customers</strong>
                                            </td>
                                            <td><?php echo $report_data['clients']['active_clients'] ?? 0; ?></td>
                                            <td>
                                                <?php 
                                                $total = $report_data['clients']['total_clients'] ?? 0;
                                                $active = $report_data['clients']['active_clients'] ?? 0;
                                                echo $total > 0 ? number_format(($active / $total) * 100, 1) : 0;
                                                ?>%
                                            </td>
                                        </tr>
                                        <tr>
                                            <td>
                                                <i class="fas fa-times-circle text-danger me-2"></i>
                                                <strong>Inactive Customers</strong>
                                            </td>
                                            <td><?php echo $report_data['clients']['inactive_clients'] ?? 0; ?></td>
                                            <td>
                                                <?php 
                                                $inactive = $report_data['clients']['inactive_clients'] ?? 0;
                                                echo $total > 0 ? number_format(($inactive / $total) * 100, 1) : 0;
                                                ?>%
                                            </td>
                                        </tr>
                                        <tr class="table-info">
                                            <td>
                                                <i class="fas fa-users me-2"></i>
                                                <strong>Total Customers</strong>
                                            </td>
                                            <td><strong><?php echo $total; ?></strong></td>
                                            <td><strong>100%</strong></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="chart-container">
                                <canvas id="customerStatusChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row">
        <div class="col-md-8">
            <div class="card report-card p-3">
                <h5><i class="fas fa-chart-line me-2"></i>Performance Overview</h5>
                <div class="chart-container">
                    <canvas id="performanceChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card report-card p-3">
                <h5><i class="fas fa-chart-pie me-2"></i>Account Status</h5>
                <div class="chart-container">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Performance Chart
        const performanceCtx = document.getElementById('performanceChart').getContext('2d');
        new Chart(performanceCtx, {
            type: 'bar',
            data: {
                labels: ['Collections', 'Bills Generated', 'Active Clients'],
                datasets: [{
                    label: 'This Period',
                    data: [
                        <?php echo $report_data['collections']['verified_collected'] ?? 0; ?>,
                        <?php echo $report_data['billing']['bills_generated'] ?? 0; ?>,
                        <?php echo $report_data['clients']['active_clients'] ?? 0; ?>
                    ],
                    backgroundColor: ['#667eea', '#38ef7d', '#ff9a9e']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });

        // Status Chart
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Active', 'Inactive', 'Overdue'],
                datasets: [{
                    data: [
                        <?php echo $report_data['clients']['active_clients'] ?? 0; ?>,
                        <?php echo $report_data['clients']['inactive_clients'] ?? 0; ?>,
                        <?php echo $report_data['overdue']['overdue_clients'] ?? 0; ?>
                    ],
                    backgroundColor: ['#38ef7d', '#fecfef', '#ff9a9e']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
        
        // Customer Status Chart
        const customerStatusCtx = document.getElementById('customerStatusChart').getContext('2d');
        new Chart(customerStatusCtx, {
            type: 'bar',
            data: {
                labels: ['Active Customers', 'Inactive Customers'],
                datasets: [{
                    label: 'Number of Customers',
                    data: [
                        <?php echo $report_data['clients']['active_clients'] ?? 0; ?>,
                        <?php echo $report_data['clients']['inactive_clients'] ?? 0; ?>
                    ],
                    backgroundColor: ['#38ef7d', '#ff6b6b']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const total = <?php echo $report_data['clients']['total_clients'] ?? 0; ?>;
                                const value = context.raw;
                                const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                return context.label + ': ' + value + ' (' + percentage + '%)';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    </script>

<?php
}

// Collections Report
elseif ($report_type === 'collections') {
?>
    <!-- Collections Summary -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card report-card p-3 text-center">
                <h4 class="text-success">₱<?php echo number_format(array_sum(array_column($report_data['daily_collections'], 'verified_total')), 2); ?></h4>
                <p class="mb-0">Total Verified Collections</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card report-card p-3 text-center">
                <h4 class="text-primary"><?php echo array_sum(array_column($report_data['daily_collections'], 'payment_count')); ?></h4>
                <p class="mb-0">Total Payments</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card report-card p-3 text-center">
                <h4 class="text-info">₱<?php echo number_format(array_sum(array_column($report_data['daily_collections'], 'verified_total')) / max(1, array_sum(array_column($report_data['daily_collections'], 'payment_count'))), 2); ?></h4>
                <p class="mb-0">Average Payment</p>
            </div>
        </div>
    </div>

    <!-- Payment Methods Chart -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card report-card p-3">
                <h5><i class="fas fa-credit-card me-2"></i>Payment Methods</h5>
                <div class="chart-container">
                    <canvas id="paymentMethodChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card report-card p-3">
                <h5><i class="fas fa-chart-area me-2"></i>Daily Collections</h5>
                <div class="chart-container">
                    <canvas id="dailyChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Top Clients Table -->
    <div class="card report-card">
        <div class="card-header">
            <h5><i class="fas fa-trophy me-2"></i>Top Paying Clients</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Meter Code</th>
                        <th>Payments</th>
                        <th>Total Paid</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report_data['top_clients'] as $client): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($client['firstname'] . ' ' . $client['lastname']); ?></td>
                        <td><?php echo htmlspecialchars($client['meter_code']); ?></td>
                        <td><?php echo $client['payment_count']; ?></td>
                        <td class="text-success fw-bold">₱<?php echo number_format($client['total_paid'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        // Payment Methods Chart
        const methodCtx = document.getElementById('paymentMethodChart').getContext('2d');
        new Chart(methodCtx, {
            type: 'pie',
            data: {
                labels: [<?php echo "'" . implode("','", array_column($report_data['method_breakdown'], 'payment_method')) . "'"; ?>],
                datasets: [{
                    data: [<?php echo implode(',', array_column($report_data['method_breakdown'], 'total')); ?>],
                    backgroundColor: ['#667eea', '#38ef7d', '#ff9a9e', '#fecfef']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });

        // Daily Collections Chart
        const dailyCtx = document.getElementById('dailyChart').getContext('2d');
        const dailyData = <?php echo json_encode(array_reverse($report_data['daily_collections'])); ?>;
        const groupedDaily = {};
        dailyData.forEach(item => {
            if (!groupedDaily[item.payment_date]) {
                groupedDaily[item.payment_date] = 0;
            }
            groupedDaily[item.payment_date] += parseFloat(item.verified_total);
        });

        new Chart(dailyCtx, {
            type: 'line',
            data: {
                labels: Object.keys(groupedDaily),
                datasets: [{
                    label: 'Daily Collections',
                    data: Object.values(groupedDaily),
                    borderColor: '#667eea',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    </script>

<?php
}

// Clients Report
elseif ($report_type === 'clients') {
    // Get active and inactive customer breakdown
    $client_status_sql = "SELECT 
        COUNT(*) as total_clients,
        SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as active_clients,
        SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) as inactive_clients
        FROM client_list WHERE delete_flag = 0";
    $client_status_data = $conn->query($client_status_sql)->fetch_assoc();
?>
    <!-- Customer Status Overview -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card report-card p-3 text-center" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); color: white;">
                <h3><?php echo $client_status_data['active_clients'] ?? 0; ?></h3>
                <p class="mb-0"><i class="fas fa-check-circle me-2"></i>Active Customers</p>
                <small>
                    <?php 
                    $total = $client_status_data['total_clients'] ?? 0;
                    $active = $client_status_data['active_clients'] ?? 0;
                    echo $total > 0 ? number_format(($active / $total) * 100, 1) : 0;
                    ?>% of total
                </small>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card report-card p-3 text-center" style="background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%); color: white;">
                <h3><?php echo $client_status_data['inactive_clients'] ?? 0; ?></h3>
                <p class="mb-0"><i class="fas fa-times-circle me-2"></i>Inactive Customers</p>
                <small>
                    <?php 
                    $inactive = $client_status_data['inactive_clients'] ?? 0;
                    echo $total > 0 ? number_format(($inactive / $total) * 100, 1) : 0;
                    ?>% of total
                </small>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card report-card p-3 text-center" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                <h3><?php echo $client_status_data['total_clients'] ?? 0; ?></h3>
                <p class="mb-0"><i class="fas fa-users me-2"></i>Total Customers</p>
                <small>All registered customers</small>
            </div>
        </div>
    </div>
    
    <!-- Customer Status Breakdown Table -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card report-card">
                <div class="card-header">
                    <h5><i class="fas fa-list me-2"></i>Customer Status Breakdown</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Customer Type</th>
                                    <th>Count</th>
                                    <th>Percentage</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>
                                        <i class="fas fa-check-circle text-success me-2"></i>
                                        <strong>Active Customers</strong>
                                    </td>
                                    <td><strong><?php echo $client_status_data['active_clients'] ?? 0; ?></strong></td>
                                    <td>
                                        <span class="badge bg-success">
                                            <?php 
                                            echo $total > 0 ? number_format(($active / $total) * 100, 1) : 0;
                                            ?>%
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-success">Active</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <i class="fas fa-times-circle text-danger me-2"></i>
                                        <strong>Inactive Customers</strong>
                                    </td>
                                    <td><strong><?php echo $client_status_data['inactive_clients'] ?? 0; ?></strong></td>
                                    <td>
                                        <span class="badge bg-danger">
                                            <?php 
                                            echo $total > 0 ? number_format(($inactive / $total) * 100, 1) : 0;
                                            ?>%
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-danger">Inactive</span>
                                    </td>
                                </tr>
                                <tr class="table-info">
                                    <td>
                                        <i class="fas fa-users me-2"></i>
                                        <strong>Total Customers</strong>
                                    </td>
                                    <td><strong><?php echo $total; ?></strong></td>
                                    <td><strong><span class="badge bg-primary">100%</span></strong></td>
                                    <td><strong>All Types</strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Client Categories -->
    <div class="row mb-4">
        <?php foreach ($report_data['categories'] as $category): ?>
        <div class="col-md-3">
            <div class="card report-card p-3 text-center">
                <h4><?php echo $category['client_count']; ?></h4>
                <p class="mb-0">Category <?php echo $category['category_id']; ?> Clients</p>
                <small>Rate: ₱<?php echo number_format($category['rate'] ?? 0, 2); ?></small>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Recent Clients -->
    <div class="row">
        <div class="col-md-8">
            <div class="card report-card">
                <div class="card-header">
                    <h5><i class="fas fa-users me-2"></i>Recent Client Registrations</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Contact</th>
                                <th>Meter Code</th>
                                <th>Date Registered</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report_data['recent_clients'] as $client): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($client['firstname'] . ' ' . $client['lastname']); ?></td>
                                <td><?php echo htmlspecialchars($client['contact']); ?></td>
                                <td><?php echo htmlspecialchars($client['meter_code']); ?></td>
                                <td><?php echo date('M d, Y', strtotime($client['date_created'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card report-card p-3">
                <h5><i class="fas fa-chart-bar me-2"></i>Client Categories</h5>
                <div class="chart-container">
                    <canvas id="categoriesChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Categories Chart
        const categoriesCtx = document.getElementById('categoriesChart').getContext('2d');
        new Chart(categoriesCtx, {
            type: 'bar',
            data: {
                labels: [<?php echo "'" . implode("','", array_map(function($c) { return 'Category ' . $c['category_id']; }, $report_data['categories'])) . "'"; ?>],
                datasets: [{
                    label: 'Client Count',
                    data: [<?php echo implode(',', array_column($report_data['categories'], 'client_count')); ?>],
                    backgroundColor: '#667eea'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    </script>

<?php
}

// Collectibles (Unpaid Bills) Report
elseif ($report_type === 'collectibles') {
    $collectible_bills = $report_data['collectible_bills'] ?? [];
    $collectibles_monthly = $report_data['collectibles_monthly'] ?? [];
?>
    <!-- Collectibles Summary -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card report-card p-3 text-center bg-warning">
                <h4>₱<?php echo number_format(array_sum(array_column($collectible_bills, 'balance_due')), 2); ?></h4>
                <p class="mb-0">Total Collectible Amount</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card report-card p-3 text-center bg-danger text-white">
                <h4><?php echo count($collectible_bills); ?></h4>
                <p class="mb-0">Unpaid Bills (This Period)</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card report-card p-3 text-center bg-primary text-white">
                <h4><?php echo count(array_unique(array_map(function($b) { return $b['meter_code'] ?? ''; }, $collectible_bills))); ?></h4>
                <p class="mb-0">Affected Clients</p>
            </div>
        </div>
    </div>

    <!-- Monthly Collectibles Log -->
    <div class="card report-card mb-4">
        <div class="card-header">
            <h5><i class="fas fa-calendar-alt me-2"></i>Monthly Collectibles Log</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Unpaid Bills</th>
                        <th>Total Collectible</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($collectibles_monthly as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['month_name'] . ' ' . $row['year']); ?></td>
                        <td><?php echo $row['bills_unpaid']; ?></td>
                        <td>₱<?php echo number_format($row['total_collectible'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($collectibles_monthly)): ?>
                    <tr>
                        <td colspan="3" class="text-center text-muted">No collectible history found for the selected period.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Collectible Bills Detail -->
    <div class="card report-card">
        <div class="card-header">
            <h5><i class="fas fa-hand-holding-usd me-2"></i>Unpaid Bills Detail (Clients Not Yet Paid)</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Meter Code</th>
                        <th>Contact</th>
                        <th>Reading Date</th>
                        <th>Due Date</th>
                        <th>Total Bill</th>
                        <th>Amount Paid</th>
                        <th>Balance (Collectible)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($collectible_bills as $bill): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($bill['firstname'] . ' ' . $bill['lastname']); ?></td>
                        <td><?php echo htmlspecialchars($bill['meter_code']); ?></td>
                        <td><?php echo htmlspecialchars($bill['contact']); ?></td>
                        <td><?php echo date('M d, Y', strtotime($bill['reading_date'])); ?></td>
                        <td><?php echo date('M d, Y', strtotime($bill['due_date'])); ?></td>
                        <td>₱<?php echo number_format($bill['total'], 2); ?></td>
                        <td class="text-success">₱<?php echo number_format($bill['amount_paid'], 2); ?></td>
                        <td class="text-danger fw-bold">₱<?php echo number_format($bill['balance_due'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($collectible_bills)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted">No unpaid bills found for the selected period.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php
}

// Overdue Accounts Report
elseif ($report_type === 'overdue') {
?>
    <!-- Overdue Summary -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card report-card p-3 text-center bg-danger text-white">
                <h4><?php echo count($report_data['overdue_bills']); ?></h4>
                <p class="mb-0">Overdue Bills</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card report-card p-3 text-center bg-warning">
                <h4>₱<?php echo number_format(array_sum(array_column($report_data['overdue_bills'], 'balance_due')), 2); ?></h4>
                <p class="mb-0">Total Overdue Amount</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card report-card p-3 text-center bg-info text-white">
                <h4><?php echo count(array_unique(array_column($report_data['overdue_bills'], 'firstname'))); ?></h4>
                <p class="mb-0">Affected Clients</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card report-card p-3 text-center bg-dark text-white">
                <h4><?php echo !empty($report_data['overdue_bills']) ? max(array_column($report_data['overdue_bills'], 'days_overdue')) : 0; ?></h4>
                <p class="mb-0">Max Days Overdue</p>
            </div>
        </div>
    </div>

    <!-- Aging Chart -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card report-card p-3">
                <h5><i class="fas fa-clock me-2"></i>Overdue Aging</h5>
                <div class="chart-container">
                    <canvas id="agingChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card report-card p-3">
                <h5><i class="fas fa-info-circle me-2"></i>Aging Summary</h5>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Age Group</th>
                                <th>Bills</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report_data['overdue_aging'] as $group => $data): ?>
                            <tr>
                                <td><?php echo $group; ?></td>
                                <td><?php echo $data['bill_count']; ?></td>
                                <td>₱<?php echo number_format($data['total_overdue'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Overdue Bills Table -->
    <div class="card report-card">
        <div class="card-header">
            <h5><i class="fas fa-exclamation-triangle me-2"></i>Overdue Bills Details</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Contact</th>
                        <th>Due Date</th>
                        <th>Days Overdue</th>
                        <th>Balance Due</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($report_data['overdue_bills'], 0, 20) as $bill): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($bill['firstname'] . ' ' . $bill['lastname']); ?></td>
                        <td><?php echo htmlspecialchars($bill['contact']); ?></td>
                        <td><?php echo date('M d, Y', strtotime($bill['due_date'])); ?></td>
                        <td>
                            <span class="badge bg-danger"><?php echo $bill['days_overdue']; ?> days</span>
                        </td>
                        <td class="text-danger fw-bold">₱<?php echo number_format($bill['balance_due'], 2); ?></td>
                        <td>
                            <button class="btn btn-sm btn-warning" onclick="showInfo('Contact client at <?php echo $bill['contact']; ?>')">
                                <i class="fas fa-phone"></i> Contact
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        // Aging Chart
        const agingCtx = document.getElementById('agingChart').getContext('2d');
        new Chart(agingCtx, {
            type: 'doughnut',
            data: {
                labels: [<?php echo "'" . implode("','", array_keys($report_data['overdue_aging'])) . "'"; ?>],
                datasets: [{
                    data: [<?php echo implode(',', array_column($report_data['overdue_aging'], 'total_overdue')); ?>],
                    backgroundColor: ['#ff9a9e', '#fecfef', '#667eea', '#38ef7d']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    </script>

<?php
}

// Billing Summary Report
elseif ($report_type === 'billing') {
?>
    <!-- Billing Summary -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card report-card">
                <div class="card-header">
                    <h5><i class="fas fa-chart-line me-2"></i>Monthly Billing Trends</h5>
                </div>
                <div class="chart-container p-3">
                    <canvas id="billingTrendsChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Consumption Patterns -->
    <div class="row">
        <div class="col-md-8">
            <div class="card report-card">
                <div class="card-header">
                    <h5><i class="fas fa-tint me-2"></i>Consumption Patterns</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Consumption Range</th>
                                <th>Number of Bills</th>
                                <th>Average Bill Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report_data['consumption_patterns'] as $pattern): ?>
                            <tr>
                                <td><?php echo $pattern['consumption_range']; ?></td>
                                <td><?php echo $pattern['bill_count']; ?></td>
                                <td>₱<?php echo number_format($pattern['average_bill'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card report-card p-3">
                <h5><i class="fas fa-chart-pie me-2"></i>Consumption Distribution</h5>
                <div class="chart-container">
                    <canvas id="consumptionChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Billing Trends Chart
        const trendsCtx = document.getElementById('billingTrendsChart').getContext('2d');
        new Chart(trendsCtx, {
            type: 'line',
            data: {
                labels: [<?php echo "'" . implode("','", array_map(function($b) { return $b['month_name'] . ' ' . $b['year']; }, $report_data['monthly_billing'])) . "'"; ?>],
                datasets: [{
                    label: 'Total Billed',
                    data: [<?php echo implode(',', array_column($report_data['monthly_billing'], 'total_amount')); ?>],
                    borderColor: '#667eea',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    fill: true
                }, {
                    label: 'Bills Generated',
                    data: [<?php echo implode(',', array_column($report_data['monthly_billing'], 'bills_generated')); ?>],
                    borderColor: '#38ef7d',
                    backgroundColor: 'rgba(56, 239, 125, 0.1)',
                    fill: true,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        grid: {
                            drawOnChartArea: false,
                        },
                    },
                }
            }
        });

        // Consumption Chart
        const consumptionCtx = document.getElementById('consumptionChart').getContext('2d');
        new Chart(consumptionCtx, {
            type: 'doughnut',
            data: {
                labels: [<?php echo "'" . implode("','", array_column($report_data['consumption_patterns'], 'consumption_range')) . "'"; ?>],
                datasets: [{
                    data: [<?php echo implode(',', array_column($report_data['consumption_patterns'], 'bill_count')); ?>],
                    backgroundColor: ['#667eea', '#38ef7d', '#ff9a9e', '#fecfef']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    </script>

<?php
}

// Additional Fees Report
elseif ($report_type === 'fees') {
?>
    <!-- Fees Summary -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card report-card p-3 text-center bg-success text-white">
                <h4>₱<?php echo number_format(array_sum(array_column($report_data['fees_breakdown'], 'total_collected')), 2); ?></h4>
                <p class="mb-0">Total Fees Collected</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card report-card p-3 text-center bg-primary text-white">
                <h4><?php echo array_sum(array_column($report_data['fees_breakdown'], 'times_applied')); ?></h4>
                <p class="mb-0">Total Applications</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card report-card p-3 text-center bg-info text-white">
                <h4><?php echo count($report_data['fees_breakdown']); ?></h4>
                <p class="mb-0">Active Fee Types</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card report-card p-3 text-center bg-warning">
                <h4>₱<?php 
                    if (count($report_data['fees_breakdown']) > 0) {
                        $total_collected = array_sum(array_column($report_data['fees_breakdown'], 'total_collected'));
                        $times_applied = array_sum(array_column($report_data['fees_breakdown'], 'times_applied'));
                        echo $times_applied > 0 ? number_format($total_collected / $times_applied, 2) : '0.00';
                    } else {
                        echo '0.00';
                    }
                ?></h4>
                <p class="mb-0">Average Fee Amount</p>
            </div>
        </div>
    </div>

    <!-- Fee Breakdown -->
    <div class="row">
        <div class="col-md-8">
            <div class="card report-card">
                <div class="card-header">
                    <h5><i class="fas fa-tags me-2"></i>Fee Breakdown</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Fee Name</th>
                                <th>Type</th>
                                <th>Rate</th>
                                <th>Applications</th>
                                <th>Total Collected</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report_data['fees_breakdown'] as $fee): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($fee['fee_name']); ?></td>
                                <td>
                                    <span class="badge <?php echo $fee['fee_type'] === 'fixed' ? 'bg-info' : 'bg-warning'; ?>">
                                        <?php echo ucfirst($fee['fee_type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($fee['fee_type'] === 'fixed'): ?>
                                        ₱<?php echo number_format($fee['fee_amount'], 2); ?>
                                    <?php else: ?>
                                        <?php echo number_format($fee['fee_amount'], 2); ?>%
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $fee['times_applied']; ?></td>
                                <td class="text-success fw-bold">₱<?php echo number_format($fee['total_collected'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card report-card p-3">
                <h5><i class="fas fa-chart-pie me-2"></i>Fee Distribution</h5>
                <div class="chart-container">
                    <canvas id="feesChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Fees Chart
        const feesCtx = document.getElementById('feesChart').getContext('2d');
        new Chart(feesCtx, {
            type: 'pie',
            data: {
                labels: [<?php echo "'" . implode("','", array_column($report_data['fees_breakdown'], 'fee_name')) . "'"; ?>],
                datasets: [{
                    data: [<?php echo implode(',', array_column($report_data['fees_breakdown'], 'total_collected')); ?>],
                    backgroundColor: ['#667eea', '#38ef7d', '#ff9a9e', '#fecfef', '#a8edea']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    </script>

<?php
}

// Disconnection Tracking Report
elseif ($report_type === 'disconnections') {
    $stats = $report_data['disco_stats'] ?? [];
    $scheduled = $report_data['scheduled_notices'] ?? [];
    $logs = $report_data['logs'] ?? [];
?>
    <!-- Disconnection Summary -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card report-card p-3 text-center bg-danger text-white">
                <h4><?php echo $stats['pending_notices'] ?? 0; ?></h4>
                <p class="mb-0">Pending Notices</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card report-card p-3 text-center bg-warning">
                <h4><?php echo $stats['sent_notices'] ?? 0; ?></h4>
                <p class="mb-0">Sent Notices</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card report-card p-3 text-center bg-success text-white">
                <h4><?php echo $stats['resolved_notices'] ?? 0; ?></h4>
                <p class="mb-0">Resolved Notices</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card report-card p-3 text-center bg-primary text-white">
                <h4>₱<?php echo number_format($stats['total_amount_flagged'] ?? 0, 2); ?></h4>
                <p class="mb-0">Total Amount Flagged</p>
            </div>
        </div>
    </div>

    <!-- Clients Scheduled for Disconnection -->
    <div class="card report-card mb-4">
        <div class="card-header">
            <h5><i class="fas fa-plug-circle-bolt me-2"></i>Clients Scheduled for Disconnection</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Meter Code</th>
                        <th>Contact</th>
                        <th>Address</th>
                        <th>Notice Type</th>
                        <th>Status</th>
                        <th>Amount Due</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($scheduled as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['firstname'] . ' ' . $row['lastname']); ?></td>
                        <td><?php echo htmlspecialchars($row['meter_code']); ?></td>
                        <td><?php echo htmlspecialchars($row['contact']); ?></td>
                        <td><?php echo htmlspecialchars($row['address']); ?></td>
                        <td>
                            <span class="badge bg-secondary">
                                <?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($row['notice_type']))); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($row['status'] === 'pending'): ?>
                                <span class="badge bg-danger">Pending</span>
                            <?php elseif ($row['status'] === 'sent'): ?>
                                <span class="badge bg-warning text-dark">Sent</span>
                            <?php else: ?>
                                <span class="badge bg-success">Resolved</span>
                            <?php endif; ?>
                        </td>
                        <td>₱<?php echo number_format($row['amount_due'], 2); ?></td>
                        <td><?php echo date('M d, Y H:i', strtotime($row['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($scheduled)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted">No clients are currently scheduled for disconnection.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Historical Logs of Disconnection Notices -->
    <div class="card report-card">
        <div class="card-header">
            <h5><i class="fas fa-history me-2"></i>Disconnection Notices History (Selected Period)</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Created At</th>
                        <th>Client</th>
                        <th>Meter Code</th>
                        <th>Notice Type</th>
                        <th>Status</th>
                        <th>Amount Due</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?php echo date('M d, Y H:i', strtotime($log['created_at'])); ?></td>
                        <td><?php echo htmlspecialchars($log['firstname'] . ' ' . $log['lastname']); ?></td>
                        <td><?php echo htmlspecialchars($log['meter_code']); ?></td>
                        <td><?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($log['notice_type']))); ?></td>
                        <td>
                            <?php if ($log['status'] === 'pending'): ?>
                                <span class="badge bg-danger">Pending</span>
                            <?php elseif ($log['status'] === 'sent'): ?>
                                <span class="badge bg-warning text-dark">Sent</span>
                            <?php else: ?>
                                <span class="badge bg-success">Resolved</span>
                            <?php endif; ?>
                        </td>
                        <td>₱<?php echo number_format($log['amount_due'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted">No disconnection notices found for the selected period.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php
}
?>