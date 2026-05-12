<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: adminlogin.php');
    exit();
}

include 'db.php';
include 'header.php';
?>

<style>
.bulk-billing-container {
    background: var(--bs-body-bg);
    color: var(--bs-body-color);
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.readings-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 0;
    box-shadow: none;
    background: var(--bs-body-bg);
}

.readings-table th,
.readings-table td {
    padding: 12px;
    border: 1px solid var(--bs-border-color);
    text-align: center;
    font-size: 0.95rem;
}

.readings-table th {
    background-color: #007bff;
    font-weight: 600;
    color: white;
    white-space: nowrap;
}

.readings-table tbody tr:hover {
    background-color: rgba(0, 123, 255, 0.05);
}

.table-responsive {
    display: block;
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    background: var(--bs-body-bg);
}

.readings-table input[type="number"],
.readings-table input[type="month"],
.readings-table select {
    width: 100%;
    min-width: 80px;
    padding: 8px 6px;
    box-sizing: border-box;
    border: 1px solid var(--bs-border-color);
    border-radius: 3px;
    background-color: var(--bs-body-bg);
    color: var(--bs-body-color);
    font-size: 0.9rem;
}

.readings-table input:read-only,
.readings-table input:disabled {
    background-color: #f0f0f0;
    color: #666;
    cursor: not-allowed;
}

.readings-table .calculated {
    background-color: rgba(0, 150, 255, 0.1);
    font-weight: bold;
    color: #0055cc;
}

.readings-table .locked-row {
    background-color: rgba(255, 193, 7, 0.1);
    border-left: 4px solid #ffc107;
}

.readings-table .locked-row td {
    padding: 15px 12px;
}

.calculation-info {
    font-size: 0.85rem;
    color: #666;
    margin-top: 3px;
    display: none;
}

.calculation-info.show {
    display: block;
}

.locked-badge {
    display: inline-block;
    background-color: #ffc107;
    color: #000;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 0.75rem;
    font-weight: bold;
    margin-right: 5px;
}

.btn-add-row {
    margin-top: 10px;
    background-color: #28a745;
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-weight: 500;
    font-size: 0.95rem;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.btn-add-row:hover {
    background-color: #218838;
}

.btn-remove {
    background: #dc3545;
    color: white;
    padding: 6px 10px;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    font-weight: 500;
    font-size: 0.85rem;
    white-space: nowrap;
}

.btn-remove:hover {
    background: #c82333;
}

.btn-remove:disabled {
    background: #ccc;
    cursor: not-allowed;
}

.btn-submit {
    margin-top: 30px;
    background-color: #007bff;
    color: white;
    padding: 12px 30px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-weight: 600;
    font-size: 1rem;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-submit:hover {
    background-color: #0056b3;
}

.alert {
    padding: 12px;
    margin-bottom: 20px;
    border-radius: 4px;
    border-left: 4px solid;
}

.alert-success {
    background-color: #d4edda;
    color: #155724;
    border-color: #28a745;
}

.alert-danger {
    background-color: #f8d7da;
    color: #721c24;
    border-color: #dc3545;
}

.alert-info {
    background-color: #d1ecf1;
    color: #0c5460;
    border-color: #17a2b8;
}

.rate-info {
    background-color: var(--bs-secondary-bg);
    padding: 15px;
    border-radius: 6px;
    margin-bottom: 20px;
    border-left: 4px solid #007bff;
}

.rate-info h5 {
    margin: 0 0 10px 0;
    color: #007bff;
}

.rate-info p {
    margin: 5px 0;
    font-size: 0.95rem;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 8px;
}

.form-group select {
    width: 100%;
    padding: 10px;
    border: 1px solid var(--bs-border-color);
    border-radius: 4px;
    background-color: var(--bs-body-bg);
    color: var(--bs-body-color);
}

.verified-reading-box {
    background-color: #f8f9fa;
    padding: 15px;
    border-radius: 6px;
    margin-bottom: 20px;
    border: 2px solid #28a745;
}

.verified-reading-box h6 {
    color: #28a745;
    margin-bottom: 10px;
    font-weight: 600;
}

.verified-reading-box p {
    margin: 5px 0;
    font-size: 0.95rem;
}

.billing-status-box {
    background-color: #e3f2fd;
    padding: 15px;
    border-radius: 6px;
    margin-bottom: 20px;
    border-left: 4px solid #2196F3;
}

.billing-status-box h6 {
    color: #1976D2;
    margin-bottom: 10px;
    font-weight: 600;
}

.billing-status-box p {
    margin: 5px 0;
    font-size: 0.95rem;
    color: #333;
}

.billing-status-new {
    background-color: #e8f5e9;
    border-left-color: #4CAF50;
}

.billing-status-new h6 {
    color: #388E3C;
}

.checkbox-group {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 10px;
}

.checkbox-group input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
}

.checkbox-group label {
    margin: 0;
    font-size: 0.95rem;
    cursor: pointer;
}
</style>

<div class="main-content">
    <div class="bulk-billing-container">
        <h2><i class="fas fa-file-invoice-dollar me-2"></i>Bulk Billing Entry</h2>
        <p>Add multiple billing records for a customer by adding current and previous readings</p>

        <?php if (isset($_SESSION['bulk_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['bulk_status']; ?>">
                <?php echo $_SESSION['bulk_message']; ?>
            </div>
            <?php unset($_SESSION['bulk_message']); unset($_SESSION['bulk_status']); ?>
        <?php endif; ?>

        <!-- Tabs for navigation -->
        <ul class="nav nav-tabs" role="tablist" style="margin-bottom: 20px; background: var(--bs-secondary-bg); padding: 10px; border-radius: 8px; border-bottom: none;">
            <li class="nav-item">
                <button class="nav-link active" id="entry-tab" data-bs-toggle="tab" data-bs-target="#entry-pane" type="button" role="tab">
                    <i class="fas fa-edit me-2"></i>Add Billing Entry
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" id="customers-tab" data-bs-toggle="tab" data-bs-target="#customers-pane" type="button" role="tab">
                    <i class="fas fa-list me-2"></i>Completed bulk (Dec–Mar)
                </button>
            </li>
        </ul>

        <!-- Tab Panes -->
        <div class="tab-content">
            <!-- Entry Tab -->
            <div class="tab-pane fade show active" id="entry-pane" role="tabpanel">
                <form id="bulkBillingForm" method="POST" action="process_bulk_billing.php">
                    <!-- Customer Selection Card -->
                    <div style="background: var(--bs-secondary-bg); padding: 20px; border-radius: 8px; margin-bottom: 30px; border: 2px solid var(--bs-border-color);">
                        <h5 style="margin-bottom: 20px;"><i class="fas fa-user me-2"></i>Select Customer</h5>
                        
                        <!-- Customer Dropdown -->
                        <div class="form-group" style="margin-bottom: 15px;">
                            <label for="customer_select"><strong>Customer Name & Meter Code:</strong></label>
                            <p class="text-muted small mb-1">Customers who already have December 2025 plus January–March 2026 bulk bills are not listed here.</p>
                            <select id="customer_select" name="client_id" required style="margin-top: 8px;">
                                <option value="">-- Choose a customer --</option>
                                <?php
                                // Exclude customers with full Dec 2025 + Jan–Mar 2026 bulk set (April not from bulk).
                                $cust_sql = "SELECT c.id, c.firstname, c.lastname, c.meter_code, c.category_id
                                    FROM client_list c
                                    WHERE c.delete_flag = 0 AND c.status = 1
                                      AND c.id NOT IN (
                                          SELECT bl.client_id
                                          FROM billing_list bl
                                          WHERE (YEAR(bl.reading_date) = 2025 AND MONTH(bl.reading_date) = 12)
                                             OR (YEAR(bl.reading_date) = 2026 AND MONTH(bl.reading_date) IN (1, 2, 3))
                                          GROUP BY bl.client_id
                                          HAVING COUNT(DISTINCT (YEAR(bl.reading_date) * 100 + MONTH(bl.reading_date))) >= 4
                                      )
                                    ORDER BY c.firstname";
                                $customers = $conn->query($cust_sql);
                                if ($customers) {
                                    while ($cust = $customers->fetch_assoc()):
                                ?>
                                    <option value="<?php echo $cust['id']; ?>" data-category="<?php echo $cust['category_id']; ?>">
                                        <?php echo htmlspecialchars($cust['firstname'] . ' ' . $cust['lastname'] . ' (Meter: ' . $cust['meter_code'] . ')'); ?>
                                    </option>
                                <?php
                                    endwhile;
                                }
                                ?>
                            </select>
                        </div>

                        <!-- Billing Status Box -->
                        <div id="billingStatusBox" style="display: none; margin-top: 15px;">
                            <div class="billing-status-box">
                                <h6 id="billingStatusTitle"><i class="fas fa-info-circle me-2"></i>Billing Status</h6>
                                <p id="billingStatusText">--</p>
                                <div class="checkbox-group" id="billingCheckboxGroup" style="display: none;">
                                    <input type="checkbox" id="hasExistingBilling" name="has_existing_billing">
                                    <label for="hasExistingBilling">I'm adding to existing billing records</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Verified Reading & Rate Info Row -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px;">
                        <!-- Verified April Reading Box -->
                        <div id="verifiedReadingBox" style="display: none;">
                            <div class="verified-reading-box">
                                <h6><i class="fas fa-lock me-2"></i>April 2026 Verified Reading</h6>
                                <p><strong>Reading:</strong> <span id="verifiedReadingValue">--</span> m³</p>
                                <p><strong>Date:</strong> <span id="verifiedDate">--</span></p>
                                <p style="color: #666; font-size: 0.9rem; margin-top: 10px;"><i class="fas fa-info-circle me-1"></i>This value comes from the <strong>Pending</strong> readings workflow (verified OCR). Bulk save on this page <strong>does not</strong> create an April bill—use <strong>Add / regular billing</strong> to bill April when you are ready.</p>
                            </div>
                        </div>

                        <!-- Rate Information -->
                        <div id="rateInfoBox" style="display: none;">
                            <div class="rate-info">
                                <!-- Billing Examples Progression -->
                                <div>
                                    <h6 style="color: #007bff; margin-bottom: 10px;"><i class="fas fa-lightbulb me-2"></i>Reading Progression Examples</h6>
                                    <p style="font-size: 0.85rem; color: #666; margin-bottom: 10px;">Based on your April verified reading of <strong id="exampleAprilReading">--</strong> m³. Here's how readings might progress from December to January:</p>
                                    
                                    <!-- Small Consumption Example -->
                                    <div style="background: rgba(76, 175, 80, 0.1); padding: 12px; border-radius: 4px; margin-bottom: 10px; border-left: 3px solid #4CAF50;">
                                        <strong style="color: #2E7D32; font-size: 0.9rem;"><i class="fas fa-chart-line me-1"></i>Example 1: Low Consumption Pattern</strong>
                                        <div style="font-size: 0.85rem; margin-top: 6px; line-height: 1.6;">
                                            <strong>December:</strong> Previous <strong id="exSmallDecPrev">--</strong> m³ → Current <strong id="exSmallDecCurr">--</strong> m³ (Usage: <strong id="exSmallDecUse">--</strong> m³) = ₱<strong style="color: #2E7D32;" id="exSmallDecBill">0.00</strong><br>
                                            <strong>January:</strong> Previous <strong id="exSmallJanPrev">--</strong> m³ → Current <strong id="exSmallJanCurr">--</strong> m³ (Usage: <strong id="exSmallJanUse">--</strong> m³) = ₱<strong style="color: #2E7D32;" id="exSmallJanBill">0.00</strong><br>
                                            <span style="color: #666; font-size: 0.8rem;">Progressing toward April ↑</span>
                                        </div>
                                    </div>
                                    
                                    <!-- Large Consumption Example -->
                                    <div style="background: rgba(33, 150, 243, 0.1); padding: 12px; border-radius: 4px; border-left: 3px solid #2196F3;">
                                        <strong style="color: #1565C0; font-size: 0.9rem;"><i class="fas fa-chart-line me-1"></i>Example 2: High Consumption Pattern</strong>
                                        <div style="font-size: 0.85rem; margin-top: 6px; line-height: 1.6;">
                                            <strong>December:</strong> Previous <strong id="exLargeDecPrev">--</strong> m³ → Current <strong id="exLargeDecCurr">--</strong> m³ (Usage: <strong id="exLargeDecUse">--</strong> m³) = ₱<strong style="color: #1565C0;" id="exLargeDecBill">0.00</strong><br>
                                            <strong>January:</strong> Previous <strong id="exLargeJanPrev">--</strong> m³ → Current <strong id="exLargeJanCurr">--</strong> m³ (Usage: <strong id="exLargeJanUse">--</strong> m³) = ₱<strong style="color: #1565C0;" id="exLargeJanBill">0.00</strong><br>
                                            <span style="color: #666; font-size: 0.8rem;">Progressing toward April ↑</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Billing Records Table - 5 Fixed Months (Dec 2025 - Apr 2026) -->
                    <div style="background: var(--bs-secondary-bg); padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                        <h5 style="margin-bottom: 20px;"><i class="fas fa-table me-2"></i>Billing Records (5 Months)</h5>
                        <p style="color: #666; font-size: 0.9rem; margin-bottom: 8px;"><strong>November</strong> is only the <em>previous reading</em> for December—it does <strong>not</strong> create its own bill. Bills are only created for <strong>December–March</strong>, and <strong>only when that column shows ✓ Paid</strong> (typing readings alone does nothing until you mark Paid). <strong>April</strong> here is verified reading for reference; bill April elsewhere.</p>
                        <p style="color: #0c5460; font-size: 0.9rem; margin-bottom: 10px; background: #d1ecf1; padding: 10px; border-radius: 6px; border-left: 4px solid #17a2b8;">
                            <strong>Per-month Paid:</strong> Click <strong>✓ Paid</strong> on each of <strong>December–March</strong> you want to save this time. Months left on <strong>⏳ Pending</strong> are skipped—so if January or March stay Pending, they will not appear in Billing History.
                        </p>
                        <p class="mb-3">
                            <button type="button" class="btn btn-sm btn-success" onclick="markBulkMonthsPaid()"><i class="fas fa-check-double me-1"></i>Mark December–March as ✓ Paid</button>
                            <span class="text-muted small ms-2">Use after entering readings to save all four months in one submit.</span>
                        </p>
                        <div class="table-responsive">
                            <table class="readings-table" style="margin: 0;">
                                <thead>
                                    <tr>
                                        <th width="16%">
                                            December 2025<br>
                                            <small style="font-weight: normal;">(Nov → Dec usage)</small>
                                        </th>
                                        <th width="16%">
                                            January 2026<br>
                                            <small style="font-weight: normal;">(Editable)</small>
                                        </th>
                                        <th width="16%">
                                            February 2026<br>
                                            <small style="font-weight: normal;">(Editable)</small>
                                        </th>
                                        <th width="16%">
                                            March 2026<br>
                                            <small style="font-weight: normal;">(Editable)</small>
                                        </th>
                                        <th width="20%">
                                            April 2026<br>
                                            <small style="font-weight: normal; color: #dc3545;">🔒 Verified reading (reference only—not saved here)</small>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr id="readingRow" style="height: 140px;">
                                        <!-- December 2025: previous = November reading -->
                                        <td style="vertical-align: top; padding: 10px;">
                                            <div style="margin-bottom: 8px;">
                                                <strong style="font-size: 0.8rem;">November 2025 (m³)</strong>
                                                <input type="number" step="1" min="0" name="nov_reading" id="nov_reading" placeholder="0" value="0" oninput="calculateAllBills()" style="width: 100%; padding: 6px; margin-top: 3px;">
                                            </div>
                                            <div style="margin-bottom: 8px;">
                                                <strong style="font-size: 0.85rem;">December reading (m³)</strong>
                                                <input type="number" step="1" min="0" name="dec_reading" id="dec_reading" placeholder="0" value="0" oninput="calculateAllBills()" style="width: 100%; padding: 6px; margin-top: 3px;">
                                            </div>
                                            <div style="margin-bottom: 8px;">
                                                <strong style="font-size: 0.85rem;">Consumption (m³)</strong>
                                                <div style="background: #e8f5e9; padding: 6px; border-radius: 3px; font-size: 0.85rem; font-weight: bold; color: #2E7D32;">
                                                    <span id="dec_consumption">0.00</span>
                                                </div>
                                            </div>
                                            <div style="background: #c8e6c9; padding: 6px; border-radius: 3px; font-size: 0.85rem; font-weight: bold; margin-bottom: 8px;">
                                                <strong>Bill:</strong> ₱<span id="dec_bill">0.00</span>
                                            </div>
                                            <div style="display: flex; gap: 4px;">
                                                <button type="button" class="status-btn" data-month="dec" data-status="pending" onclick="setMonthStatus('dec', 'pending')" style="flex: 1; padding: 4px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 3px; font-size: 0.75rem; cursor: pointer; font-weight: 600; color: #ff9800;">⏳ Pending</button>
                                                <button type="button" class="status-btn" data-month="dec" data-status="paid" onclick="setMonthStatus('dec', 'paid')" style="flex: 1; padding: 4px; background: #e8f5e9; border: 1px solid #ddd; border-radius: 3px; font-size: 0.75rem; cursor: pointer; font-weight: 600; color: #999;">✓ Paid</button>
                                                <input type="hidden" name="dec_status" id="dec_status" value="pending">
                                            </div>
                                        </td>
                                        
                                        <!-- January 2026 -->
                                        <td style="vertical-align: top; padding: 10px;">
                                            <div style="margin-bottom: 8px;">
                                                <strong style="font-size: 0.85rem;">Meter Reading (m³)</strong>
                                                <input type="number" step="1" min="0" name="jan_reading" id="jan_reading" placeholder="0" value="0" oninput="calculateAllBills()" style="width: 100%; padding: 6px; margin-top: 3px;">
                                            </div>
                                            <div style="margin-bottom: 8px;">
                                                <strong style="font-size: 0.85rem;">Consumption (m³)</strong>
                                                <div style="background: #e8f5e9; padding: 6px; border-radius: 3px; font-size: 0.85rem; font-weight: bold; color: #2E7D32;">
                                                    <span id="jan_consumption">0.00</span>
                                                </div>
                                            </div>
                                            <div style="background: #c8e6c9; padding: 6px; border-radius: 3px; font-size: 0.85rem; font-weight: bold; margin-bottom: 8px;">
                                                <strong>Bill:</strong> ₱<span id="jan_bill">0.00</span>
                                            </div>
                                            <div style="display: flex; gap: 4px;">
                                                <button type="button" class="status-btn" data-month="jan" data-status="pending" onclick="setMonthStatus('jan', 'pending')" style="flex: 1; padding: 4px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 3px; font-size: 0.75rem; cursor: pointer; font-weight: 600; color: #ff9800;">⏳ Pending</button>
                                                <button type="button" class="status-btn" data-month="jan" data-status="paid" onclick="setMonthStatus('jan', 'paid')" style="flex: 1; padding: 4px; background: #e8f5e9; border: 1px solid #ddd; border-radius: 3px; font-size: 0.75rem; cursor: pointer; font-weight: 600; color: #999;">✓ Paid</button>
                                                <input type="hidden" name="jan_status" id="jan_status" value="pending">
                                            </div>
                                        </td>
                                        
                                        <!-- February 2026 -->
                                        <td style="vertical-align: top; padding: 10px;">
                                            <div style="margin-bottom: 8px;">
                                                <strong style="font-size: 0.85rem;">Meter Reading (m³)</strong>
                                                <input type="number" step="1" min="0" name="feb_reading" id="feb_reading" placeholder="0" value="0" oninput="calculateAllBills()" style="width: 100%; padding: 6px; margin-top: 3px;">
                                            </div>
                                            <div style="margin-bottom: 8px;">
                                                <strong style="font-size: 0.85rem;">Consumption (m³)</strong>
                                                <div style="background: #e8f5e9; padding: 6px; border-radius: 3px; font-size: 0.85rem; font-weight: bold; color: #2E7D32;">
                                                    <span id="feb_consumption">0.00</span>
                                                </div>
                                            </div>
                                            <div style="background: #c8e6c9; padding: 6px; border-radius: 3px; font-size: 0.85rem; font-weight: bold; margin-bottom: 8px;">
                                                <strong>Bill:</strong> ₱<span id="feb_bill">0.00</span>
                                            </div>
                                            <div style="display: flex; gap: 4px;">
                                                <button type="button" class="status-btn" data-month="feb" data-status="pending" onclick="setMonthStatus('feb', 'pending')" style="flex: 1; padding: 4px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 3px; font-size: 0.75rem; cursor: pointer; font-weight: 600; color: #ff9800;">⏳ Pending</button>
                                                <button type="button" class="status-btn" data-month="feb" data-status="paid" onclick="setMonthStatus('feb', 'paid')" style="flex: 1; padding: 4px; background: #e8f5e9; border: 1px solid #ddd; border-radius: 3px; font-size: 0.75rem; cursor: pointer; font-weight: 600; color: #999;">✓ Paid</button>
                                                <input type="hidden" name="feb_status" id="feb_status" value="pending">
                                            </div>
                                        </td>
                                        
                                        <!-- March 2026 -->
                                        <td style="vertical-align: top; padding: 10px;">
                                            <div style="margin-bottom: 8px;">
                                                <strong style="font-size: 0.85rem;">Meter Reading (m³)</strong>
                                                <input type="number" step="1" min="0" name="mar_reading" id="mar_reading" placeholder="0" value="0" oninput="calculateAllBills()" style="width: 100%; padding: 6px; margin-top: 3px;">
                                            </div>
                                            <div style="margin-bottom: 8px;">
                                                <strong style="font-size: 0.85rem;">Consumption (m³)</strong>
                                                <div style="background: #e8f5e9; padding: 6px; border-radius: 3px; font-size: 0.85rem; font-weight: bold; color: #2E7D32;">
                                                    <span id="mar_consumption">0.00</span>
                                                </div>
                                            </div>
                                            <div style="background: #c8e6c9; padding: 6px; border-radius: 3px; font-size: 0.85rem; font-weight: bold; margin-bottom: 8px;">
                                                <strong>Bill:</strong> ₱<span id="mar_bill">0.00</span>
                                            </div>
                                            <div style="display: flex; gap: 4px;">
                                                <button type="button" class="status-btn" data-month="mar" data-status="pending" onclick="setMonthStatus('mar', 'pending')" style="flex: 1; padding: 4px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 3px; font-size: 0.75rem; cursor: pointer; font-weight: 600; color: #ff9800;">⏳ Pending</button>
                                                <button type="button" class="status-btn" data-month="mar" data-status="paid" onclick="setMonthStatus('mar', 'paid')" style="flex: 1; padding: 4px; background: #e8f5e9; border: 1px solid #ddd; border-radius: 3px; font-size: 0.75rem; cursor: pointer; font-weight: 600; color: #999;">✓ Paid</button>
                                                <input type="hidden" name="mar_status" id="mar_status" value="pending">
                                            </div>
                                        </td>
                                        
                                        <!-- April 2026 (LOCKED) -->
                                        <td style="vertical-align: top; padding: 10px; background: rgba(76, 175, 80, 0.15); border-left: 4px solid #4CAF50;">
                                            <div style="margin-bottom: 8px;">
                                                <strong style="font-size: 0.85rem; color: #2E7D32;">🔒 Meter Reading (m³)</strong>
                                                <input type="number" step="1" min="0" name="apr_reading" id="apr_reading" readonly placeholder="0" value="0" style="width: 100%; padding: 6px; margin-top: 3px; background: #e0e0e0; color: #666; cursor: not-allowed;">
                                            </div>
                                            <div style="margin-bottom: 8px;">
                                                <strong style="font-size: 0.85rem; color: #2E7D32;">Consumption (m³)</strong>
                                                <div style="background: #e8f5e9; padding: 6px; border-radius: 3px; font-size: 0.85rem; font-weight: bold; color: #2E7D32;">
                                                    <span id="apr_consumption">0.00</span>
                                                </div>
                                            </div>
                                            <div style="background: #4CAF50; color: white; padding: 8px; border-radius: 3px; font-size: 0.85rem; font-weight: bold; margin-bottom: 8px;">
                                                <strong>Bill:</strong> ₱<span id="apr_bill">0.00</span>
                                            </div>
                                            <div style="padding: 8px 6px; background: #e3f2fd; border: 1px solid #90caf9; border-radius: 4px; font-size: 0.72rem; color: #1565c0; line-height: 1.35;">
                                                <strong>Reference only.</strong> April is not written to billing from bulk—bill April separately when ready.
                                            </div>
                                            <input type="hidden" name="apr_status" id="apr_status" value="pending">
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save me-2"></i>Save paid December–March only
                    </button>
                </form>
            </div>

            <!-- Customers with Readings Tab -->
            <div class="tab-pane fade" id="customers-pane" role="tabpanel">
                <div style="background: var(--bs-secondary-bg); padding: 20px; border-radius: 8px;">
                    <h5 style="margin-bottom: 15px;"><i class="fas fa-users me-2"></i>Customers finished (Dec–Mar bulk)</h5>
                    <p style="color: #666; margin-bottom: 20px;">Listed when they have billing records for <strong>December 2025</strong> and <strong>January through March 2026</strong> (four months). April is not part of bulk save. These accounts are <strong>excluded</strong> from the dropdown so they are not run through bulk again by mistake.</p>
                    
                    <div class="table-responsive">
                        <table class="readings-table">
                            <thead>
                                <tr>
                                    <th>Customer Name</th>
                                    <th>Meter Code</th>
                                    <th>Verified April ref. (m³)</th>
                                    <th>Status</th>
                                    <th>Last bill date</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="customersTableBody">
                                <tr><td colspan="6" class="text-center text-muted" style="padding: 40px;">Loading customers...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

<script>
    let categoryRates = {};
    let verifiedReading = null;
    let verifiedReadingValue = 0;

    function readMeterInput(id) {
        return Math.round(parseFloat(document.getElementById(id).value) || 0);
    }

    // Load category rates on page load
    document.addEventListener('DOMContentLoaded', function() {
        loadCategoryRates();
        loadCustomersWithReadings();
        const customersTab = document.getElementById('customers-tab');
        if (customersTab) {
            customersTab.addEventListener('shown.bs.tab', function() {
                loadCustomersWithReadings();
            });
        }
    });

    function loadCategoryRates() {
        return fetch('get_category_rates.php')
            .then(res => res.json())
            .then(data => {
                categoryRates = data;
                console.log('Rates loaded:', categoryRates);
                calculateAllBills();
            });
    }

    function loadCustomersWithReadings() {
        fetch('get_customer_billing_status.php?all=1')
            .then(res => res.json())
            .then(data => {
                if (data && Array.isArray(data) && data.length > 0) {
                    let html = '';
                    data.forEach(customer => {
                        const ref = customer.verified_reading;
                        const refCell = (ref != null && !isNaN(ref) && ref > 0)
                            ? `<span class="badge bg-secondary">${Math.round(Number(ref))}</span> m³`
                            : '<span class="text-muted">—</span>';
                        html += `
                            <tr>
                                <td><strong>${customer.firstname} ${customer.lastname}</strong></td>
                                <td>${customer.meter_code}</td>
                                <td>${refCell}</td>
                                <td><span class="badge bg-success">Dec–Mar complete</span></td>
                                <td>${customer.processed_date ? (() => { const d = new Date(customer.processed_date); return isNaN(d.getTime()) ? 'N/A' : d.toLocaleDateString(); })() : 'N/A'}</td>
                                <td></td>
                            </tr>
                        `;
                    });
                    document.getElementById('customersTableBody').innerHTML = html;
                } else {
                    document.getElementById('customersTableBody').innerHTML = '<tr><td colspan="6" class="text-center text-muted">No customers with a full Dec 2025 – Mar 2026 bulk billing set yet.</td></tr>';
                }
            })
            .catch(err => {
                console.error('Error loading customers:', err);
                document.getElementById('customersTableBody').innerHTML = '<tr><td colspan="6" class="text-center text-danger">Error loading data</td></tr>';
            });
    }

    function loadBillingStatus(clientId) {
        if (!clientId) {
            document.getElementById('billingStatusBox').style.display = 'none';
            return;
        }

        fetch(`get_customer_billing_status.php?client_id=${clientId}`)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const statusBox = document.getElementById('billingStatusBox');
                    const statusTitle = document.getElementById('billingStatusTitle');
                    const statusText = document.getElementById('billingStatusText');
                    const checkboxGroup = document.getElementById('billingCheckboxGroup');

                    if (data.has_billing) {
                        statusBox.classList.add('billing-status-new');
                        statusTitle.innerHTML = '<i class="fas fa-history me-2"></i>Customer has Existing Billings';
                        statusText.innerHTML = `<strong>${data.billing_count} billing record(s) found</strong><br>Last billing: ${data.last_billing_date || 'N/A'}`;
                        checkboxGroup.style.display = 'flex';
                    } else {
                        statusBox.classList.add('billing-status-new');
                        statusTitle.innerHTML = '<i class="fas fa-star me-2"></i>New Customer';
                        statusText.innerHTML = '<strong>No billing records found</strong><br>This customer is new to the billing system.';
                        checkboxGroup.style.display = 'none';
                    }

                    statusBox.style.display = 'block';
                }
            })
            .catch(err => console.error('Error loading billing status:', err));
    }

    function loadVerifiedReading(clientId) {
        if (!clientId) {
            document.getElementById('verifiedReadingBox').style.display = 'none';
            document.getElementById('rateInfoBox').style.display = 'none';
            verifiedReading = null;
            verifiedReadingValue = 0;
            clearAprilColumn();
            return;
        }

        fetch(`get_verified_april_readings.php?client_id=${clientId}`)
            .then(res => res.json())
            .then(data => {
                console.log('Verified reading response:', data);
                
                if (data.success && data.verified_reading !== null && data.verified_reading !== undefined) {
                    verifiedReading = data.verified_reading;
                    verifiedReadingValue = Math.round(Number(data.verified_reading));
                    
                    // Fill April column with verified reading (whole m³)
                    document.getElementById('apr_reading').value = String(verifiedReadingValue);
                    document.getElementById('verifiedReadingValue').textContent = String(verifiedReadingValue);
                    const pd = data.processed_date ? new Date(data.processed_date) : null;
                    document.getElementById('verifiedDate').textContent = (pd && !isNaN(pd.getTime()))
                        ? pd.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
                        : '—';
                    document.getElementById('verifiedReadingBox').style.display = 'block';
                    
                    // Calculate all bills with new reading
                    calculateAllBills();

                    // Show billing examples progression based on April reading
                    showBillingExamples();
                    
                    console.log('Verified reading set:', verifiedReadingValue);
                } else {
                    console.log('No verified reading found');
                    document.getElementById('verifiedReadingBox').style.display = 'none';
                    clearAprilColumn();
                }

                // Show reading progression examples
                document.getElementById('rateInfoBox').style.display = 'block';
            })
            .catch(err => {
                console.error('Error loading verified reading:', err);
                document.getElementById('verifiedReadingBox').style.display = 'none';
                document.getElementById('rateInfoBox').style.display = 'none';
                clearAprilColumn();
            });
    }

    function showBillingExamples() {
        if (verifiedReadingValue <= 0) {
            return;
        }

        // Display April reading as reference
        document.getElementById('exampleAprilReading').textContent = String(Math.round(verifiedReadingValue));

        const categoryId = document.getElementById('customer_select').options[document.getElementById('customer_select').selectedIndex].dataset.category;
        
        if (!categoryId || !categoryRates[categoryId]) {
            return;
        }

        const rates = categoryRates[categoryId];
        const baseRate = rates.rate;
        const excessRate = rates.excess_rate;

        // SMALL CONSUMPTION EXAMPLE: ~40 m³/month progression
        const smallMonthlyUsage = 40;
        
        // Working backwards from April verified reading
        const apr = verifiedReadingValue;
        const mar = apr - smallMonthlyUsage;
        const feb = mar - smallMonthlyUsage;
        const jan = feb - smallMonthlyUsage;
        const dec = jan - smallMonthlyUsage;
        const novSmall = dec - smallMonthlyUsage;

        const decSmallPrev = novSmall;
        const decSmallCurr = dec;
        const decSmallUsage = decSmallCurr - decSmallPrev;
        const decSmallBill = calculateBill(decSmallUsage, baseRate, excessRate);

        document.getElementById('exSmallDecPrev').textContent = decSmallPrev.toFixed(2);
        document.getElementById('exSmallDecCurr').textContent = decSmallCurr.toFixed(2);
        document.getElementById('exSmallDecUse').textContent = decSmallUsage.toFixed(2);
        document.getElementById('exSmallDecBill').textContent = decSmallBill.toFixed(2);

        // January small example - uses December current as previous
        const janSmallPrev = dec;
        const janSmallCurr = jan;
        const janSmallUsage = janSmallCurr - janSmallPrev;
        const janSmallBill = calculateBill(janSmallUsage, baseRate, excessRate);

        document.getElementById('exSmallJanPrev').textContent = janSmallPrev.toFixed(2);
        document.getElementById('exSmallJanCurr').textContent = janSmallCurr.toFixed(2);
        document.getElementById('exSmallJanUse').textContent = janSmallUsage.toFixed(2);
        document.getElementById('exSmallJanBill').textContent = janSmallBill.toFixed(2);

        // LARGE CONSUMPTION EXAMPLE: ~150 m³/month progression
        const largeMonthlyUsage = 150;
        
        // Working backwards from April verified reading
        const apr_lg = verifiedReadingValue;
        const mar_lg = apr_lg - largeMonthlyUsage;
        const feb_lg = mar_lg - largeMonthlyUsage;
        const jan_lg = feb_lg - largeMonthlyUsage;
        const dec_lg = jan_lg - largeMonthlyUsage;
        const novLarge = Math.max(0, dec_lg - largeMonthlyUsage);

        const decLargePrev = novLarge;
        const decLargeCurr = Math.max(0, dec_lg);
        const decLargeUsage = decLargeCurr - decLargePrev;
        const decLargeBill = calculateBill(decLargeUsage, baseRate, excessRate);

        document.getElementById('exLargeDecPrev').textContent = decLargePrev.toFixed(2);
        document.getElementById('exLargeDecCurr').textContent = decLargeCurr.toFixed(2);
        document.getElementById('exLargeDecUse').textContent = decLargeUsage.toFixed(2);
        document.getElementById('exLargeDecBill').textContent = decLargeBill.toFixed(2);

        // January large example - uses December current as previous
        const janLargePrev = Math.max(0, dec_lg);
        const janLargeCurr = jan_lg;
        const janLargeUsage = janLargeCurr - janLargePrev;
        const janLargeBill = calculateBill(janLargeUsage, baseRate, excessRate);

        document.getElementById('exLargeJanPrev').textContent = janLargePrev.toFixed(2);
        document.getElementById('exLargeJanCurr').textContent = janLargeCurr.toFixed(2);
        document.getElementById('exLargeJanUse').textContent = janLargeUsage.toFixed(2);
        document.getElementById('exLargeJanBill').textContent = janLargeBill.toFixed(2);
    }

    function calculateBill(consumption, baseRate, excessRate) {
        if (consumption <= 6) {
            return (consumption / 6) * baseRate;
        } else {
            const excessUsage = consumption - 6;
            return baseRate + (excessUsage * excessRate);
        }
    }

    function markBulkMonthsPaid() {
        ['dec', 'jan', 'feb', 'mar'].forEach(function(m) {
            setMonthStatus(m, 'paid');
        });
    }

    function setMonthStatus(month, status) {
        if (month === 'apr') {
            return;
        }
        // Update hidden field
        const hiddenInput = document.getElementById(month + '_status');
        if (hiddenInput) {
            hiddenInput.value = status;
        }

        // Update button styles
        const buttons = document.querySelectorAll(`.status-btn[data-month="${month}"]`);
        buttons.forEach(btn => {
            const btnStatus = btn.dataset.status;
            if (btnStatus === status) {
                // Active button (selected status)
                if (status === 'pending') {
                    btn.style.background = '#ffc107';
                    btn.style.color = 'white';
                    btn.style.fontWeight = 'bold';
                } else {
                    btn.style.background = '#4CAF50';
                    btn.style.color = 'white';
                    btn.style.fontWeight = 'bold';
                }
            } else {
                // Inactive button
                if (btnStatus === 'pending') {
                    btn.style.background = '#fff3cd';
                    btn.style.border = '1px solid #ffc107';
                    btn.style.color = '#ff9800';
                    btn.style.fontWeight = '600';
                } else {
                    btn.style.background = '#e8f5e9';
                    btn.style.border = '1px solid #ddd';
                    btn.style.color = '#999';
                    btn.style.fontWeight = '600';
                }
            }
        });
    }

    function clearAprilColumn() {
        document.getElementById('apr_reading').value = '0';
        document.getElementById('apr_consumption').textContent = '0.00';
        document.getElementById('apr_bill').textContent = '0.00';
    }

    function calculateAllBills() {
        const sel = document.getElementById('customer_select');
        const opt = sel && sel.options[sel.selectedIndex];
        const categoryId = opt ? opt.dataset.category : '';
        const hasRates = !!(categoryId && categoryRates[categoryId]);
        const baseRate = hasRates ? categoryRates[categoryId].rate : 0;
        const excessRate = hasRates ? categoryRates[categoryId].excess_rate : 0;

        const readings = {
            nov: readMeterInput('nov_reading'),
            dec: readMeterInput('dec_reading'),
            jan: readMeterInput('jan_reading'),
            feb: readMeterInput('feb_reading'),
            mar: readMeterInput('mar_reading'),
            apr: readMeterInput('apr_reading')
        };

        // December (November → December)
        if (readings.nov > 0 && readings.dec > 0) {
            const decConsumption = readings.dec - readings.nov;
            document.getElementById('dec_consumption').textContent = decConsumption.toFixed(2);
            if (decConsumption < 0) {
                document.getElementById('dec_bill').textContent = '—';
            } else if (hasRates) {
                document.getElementById('dec_bill').textContent = calculateBill(decConsumption, baseRate, excessRate).toFixed(2);
            } else {
                document.getElementById('dec_bill').textContent = '—';
            }
        } else {
            document.getElementById('dec_consumption').textContent = '0.00';
            document.getElementById('dec_bill').textContent = '0.00';
        }

        // January (Dec → Jan)
        if (readings.dec > 0 && readings.jan > 0) {
            const janConsumption = readings.jan - readings.dec;
            document.getElementById('jan_consumption').textContent = janConsumption.toFixed(2);
            if (janConsumption < 0) {
                document.getElementById('jan_bill').textContent = '—';
            } else if (hasRates) {
                document.getElementById('jan_bill').textContent = calculateBill(janConsumption, baseRate, excessRate).toFixed(2);
            } else {
                document.getElementById('jan_bill').textContent = '—';
            }
        } else {
            document.getElementById('jan_consumption').textContent = '0.00';
            document.getElementById('jan_bill').textContent = '0.00';
        }

        // February (Jan → Feb)
        if (readings.jan > 0 && readings.feb > 0) {
            const febConsumption = readings.feb - readings.jan;
            document.getElementById('feb_consumption').textContent = febConsumption.toFixed(2);
            if (febConsumption < 0) {
                document.getElementById('feb_bill').textContent = '—';
            } else if (hasRates) {
                document.getElementById('feb_bill').textContent = calculateBill(febConsumption, baseRate, excessRate).toFixed(2);
            } else {
                document.getElementById('feb_bill').textContent = '—';
            }
        } else {
            document.getElementById('feb_consumption').textContent = '0.00';
            document.getElementById('feb_bill').textContent = '0.00';
        }

        // March (Feb → Mar)
        if (readings.feb > 0 && readings.mar > 0) {
            const marConsumption = readings.mar - readings.feb;
            document.getElementById('mar_consumption').textContent = marConsumption.toFixed(2);
            if (marConsumption < 0) {
                document.getElementById('mar_bill').textContent = '—';
            } else if (hasRates) {
                document.getElementById('mar_bill').textContent = calculateBill(marConsumption, baseRate, excessRate).toFixed(2);
            } else {
                document.getElementById('mar_bill').textContent = '—';
            }
        } else {
            document.getElementById('mar_consumption').textContent = '0.00';
            document.getElementById('mar_bill').textContent = '0.00';
        }

        // April (Mar → Apr) — reference only
        if (readings.mar > 0 && readings.apr > 0) {
            const aprConsumption = readings.apr - readings.mar;
            document.getElementById('apr_consumption').textContent = aprConsumption.toFixed(2);
            if (aprConsumption < 0) {
                document.getElementById('apr_bill').textContent = '—';
            } else if (hasRates) {
                document.getElementById('apr_bill').textContent = calculateBill(aprConsumption, baseRate, excessRate).toFixed(2);
            } else {
                document.getElementById('apr_bill').textContent = '—';
            }
        } else {
            document.getElementById('apr_consumption').textContent = '0.00';
            document.getElementById('apr_bill').textContent = '0.00';
        }
    }

    // Load verified reading and billing status when customer changes
    document.getElementById('customer_select').addEventListener('change', function() {
        loadVerifiedReading(this.value);
        loadBillingStatus(this.value);
    });

    // Form validation and submission
    document.getElementById('bulkBillingForm').addEventListener('submit', function(e) {
        if (!document.getElementById('customer_select').value) {
            e.preventDefault();
            alert('Please select a customer');
            return false;
        }

        const paidToggleMonths = ['dec', 'jan', 'feb', 'mar'];
        const anyPaid = paidToggleMonths.some(m => {
            const el = document.getElementById(m + '_status');
            return el && el.value === 'paid';
        });
        if (!anyPaid) {
            e.preventDefault();
            alert('Mark at least one of December–March as ✓ Paid. April is reference-only here and is not saved from bulk.');
            return false;
        }

        // Check if at least one month has reading data
        const readings = {
            nov: readMeterInput('nov_reading'),
            dec: readMeterInput('dec_reading'),
            jan: readMeterInput('jan_reading'),
            feb: readMeterInput('feb_reading'),
            mar: readMeterInput('mar_reading'),
            apr: readMeterInput('apr_reading')
        };

        let hasData = Object.values(readings).some(val => val > 0);

        if (!hasData) {
            e.preventDefault();
            alert('Please enter meter readings for at least one month');
            return false;
        }

        // Validate that readings are in ascending order
        const readingArray = [readings.nov, readings.dec, readings.jan, readings.feb, readings.mar, readings.apr];
        for (let i = 1; i < readingArray.length; i++) {
            if (readingArray[i] > 0 && readingArray[i - 1] > 0 && readingArray[i] < readingArray[i - 1]) {
                e.preventDefault();
                alert('Meter readings must be in ascending order (cannot decrease)');
                return false;
            }
        }
    });
</script>

<?php include 'footer.php'; ?>

