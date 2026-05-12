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
                    <i class="fas fa-list me-2"></i>Customers with Readings
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
                            <select id="customer_select" name="client_id" required style="margin-top: 8px;">
                                <option value="">-- Choose a customer --</option>
                                <?php
                                $customers = $conn->query("SELECT id, firstname, lastname, meter_code, category_id FROM client_list WHERE delete_flag = 0 AND status = 1 ORDER BY firstname");
                                while ($cust = $customers->fetch_assoc()):
                                ?>
                                    <option value="<?php echo $cust['id']; ?>" data-category="<?php echo $cust['category_id']; ?>">
                                        <?php echo htmlspecialchars($cust['firstname'] . ' ' . $cust['lastname'] . ' (Meter: ' . $cust['meter_code'] . ')'); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                <!-- Billing Status Box -->
                <div id="billingStatusBox" style="display: none; margin-top: 15px;">
                    <div class="billing-status-box">
                        <h6 id="billingStatusTitle"><i class="fas fa-info-circle me-2"></i>Billing Status</h6>
                        <p id="billingStatusText">--</p>
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
                                <p style="color: #666; font-size: 0.9rem; margin-top: 10px;"><i class="fas fa-info-circle me-1"></i>This reading is locked and will fill the April column</p>
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
                        <p style="color: #666; font-size: 0.9rem; margin-bottom: 15px;">Enter readings for the months below. April 2026 is locked with the verified reading.</p>
                        
                        <div class="table-responsive">
                            <table class="readings-table" style="margin: 0;">
                                <thead>
                                    <tr>
                                        <th width="18%">
                                            December 2025<br>
                                            <small style="font-weight: normal;">(Editable)</small>
                                        </th>
                                        <th width="18%">
                                            January 2026<br>
                                            <small style="font-weight: normal;">(Editable)</small>
                                        </th>
                                        <th width="18%">
                                            February 2026<br>
                                            <small style="font-weight: normal;">(Editable)</small>
                                        </th>
                                        <th width="18%">
                                            March 2026<br>
                                            <small style="font-weight: normal;">(Editable)</small>
                                        </th>
                                        <th width="28%">
                                            April 2026<br>
                                            <small style="font-weight: normal; color: #dc3545;">🔒 LOCKED (Verified)</small>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr id="readingRow" style="height: 120px;">
                                        <!-- December 2025 -->
                                        <td style="vertical-align: top; padding: 10px;">
                                            <div style="margin-bottom: 8px;">
                                                <strong style="font-size: 0.85rem;">Previous (m³)</strong>
                                                <input type="number" step="0.01" name="dec_prev" id="dec_prev" placeholder="0" value="0" oninput="calculateMonthly('dec')" style="width: 100%; padding: 6px; margin-top: 3px;">
                                            </div>
                                            <div style="margin-bottom: 8px;">
                                                <strong style="font-size: 0.85rem;">Current (m³)</strong>
                                                <input type="number" step="0.01" name="dec_curr" id="dec_curr" placeholder="0" value="0" oninput="calculateMonthly('dec')" style="width: 100%; padding: 6px; margin-top: 3px;">
                                            </div>
                                            <div style="background: #f0f0f0; padding: 6px; border-radius: 3px; font-size: 0.85rem;">
                                                <strong>Bill:</strong> ₱<span id="dec_bill">0.00</span>
                                            </div>
                                        </td>
                                        
                                        <!-- January 2026 -->
                                        <td style="vertical-align: top; padding: 10px;">
                                            <div style="margin-bottom: 8px;">
                                                <strong style="font-size: 0.85rem;">Previous (m³)</strong>
                                                <input type="number" step="0.01" name="jan_prev" id="jan_prev" placeholder="0" value="0" oninput="calculateMonthly('jan')" style="width: 100%; padding: 6px; margin-top: 3px;">
                                            </div>
                                            <div style="margin-bottom: 8px;">
                                                <strong style="font-size: 0.85rem;">Current (m³)</strong>
                                                <input type="number" step="0.01" name="jan_curr" id="jan_curr" placeholder="0" value="0" oninput="calculateMonthly('jan')" style="width: 100%; padding: 6px; margin-top: 3px;">
                                            </div>
                                            <div style="background: #f0f0f0; padding: 6px; border-radius: 3px; font-size: 0.85rem;">
                                                <strong>Bill:</strong> ₱<span id="jan_bill">0.00</span>
                                            </div>
                                        </td>
                                        
                                        <!-- February 2026 -->
                                        <td style="vertical-align: top; padding: 10px;">
                                            <div style="margin-bottom: 8px;">
                                                <strong style="font-size: 0.85rem;">Previous (m³)</strong>
                                                <input type="number" step="0.01" name="feb_prev" id="feb_prev" placeholder="0" value="0" oninput="calculateMonthly('feb')" style="width: 100%; padding: 6px; margin-top: 3px;">
                                            </div>
                                            <div style="margin-bottom: 8px;">
                                                <strong style="font-size: 0.85rem;">Current (m³)</strong>
                                                <input type="number" step="0.01" name="feb_curr" id="feb_curr" placeholder="0" value="0" oninput="calculateMonthly('feb')" style="width: 100%; padding: 6px; margin-top: 3px;">
                                            </div>
                                            <div style="background: #f0f0f0; padding: 6px; border-radius: 3px; font-size: 0.85rem;">
                                                <strong>Bill:</strong> ₱<span id="feb_bill">0.00</span>
                                            </div>
                                        </td>
                                        
                                        <!-- March 2026 -->
                                        <td style="vertical-align: top; padding: 10px;">
                                            <div style="margin-bottom: 8px;">
                                                <strong style="font-size: 0.85rem;">Previous (m³)</strong>
                                                <input type="number" step="0.01" name="mar_prev" id="mar_prev" placeholder="0" value="0" oninput="calculateMonthly('mar')" style="width: 100%; padding: 6px; margin-top: 3px;">
                                            </div>
                                            <div style="margin-bottom: 8px;">
                                                <strong style="font-size: 0.85rem;">Current (m³)</strong>
                                                <input type="number" step="0.01" name="mar_curr" id="mar_curr" placeholder="0" value="0" oninput="calculateMonthly('mar')" style="width: 100%; padding: 6px; margin-top: 3px;">
                                            </div>
                                            <div style="background: #f0f0f0; padding: 6px; border-radius: 3px; font-size: 0.85rem;">
                                                <strong>Bill:</strong> ₱<span id="mar_bill">0.00</span>
                                            </div>
                                        </td>
                                        
                                        <!-- April 2026 (LOCKED) -->
                                        <td style="vertical-align: top; padding: 10px; background: rgba(255, 193, 7, 0.1); border-left: 4px solid #ffc107;">
                                            <div style="margin-bottom: 8px;">
                                                <strong style="font-size: 0.85rem; color: #d32f2f;">🔒 Previous (m³)</strong>
                                                <input type="number" step="0.01" name="apr_prev" id="apr_prev" readonly placeholder="0" value="0" style="width: 100%; padding: 6px; margin-top: 3px; background: #e0e0e0; color: #666; cursor: not-allowed;">
                                            </div>
                                            <div style="margin-bottom: 8px;">
                                                <strong style="font-size: 0.85rem; color: #d32f2f;">🔒 Current (m³)</strong>
                                                <input type="number" step="0.01" name="apr_curr" id="apr_curr" readonly placeholder="0" value="0" style="width: 100%; padding: 6px; margin-top: 3px; background: #e0e0e0; color: #666; cursor: not-allowed;">
                                            </div>
                                            <div style="background: #4CAF50; color: white; padding: 8px; border-radius: 3px; font-size: 0.85rem; font-weight: bold;">
                                                <strong>Bill:</strong> ₱<span id="apr_bill">0.00</span>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save me-2"></i>Save All Billing Records
                    </button>
                </form>
            </div>

            <!-- Customers with Readings Tab -->
            <div class="tab-pane fade" id="customers-pane" role="tabpanel">
                <div style="background: var(--bs-secondary-bg); padding: 20px; border-radius: 8px;">
                    <h5 style="margin-bottom: 15px;"><i class="fas fa-users me-2"></i>Customers with Verified Readings</h5>
                    <p style="color: #666; margin-bottom: 20px;">These customers already have verified readings in the system:</p>
                    
                    <div class="table-responsive">
                        <table class="readings-table">
                            <thead>
                                <tr>
                                    <th>Customer Name</th>
                                    <th>Meter Code</th>
                                    <th>Verified Reading</th>
                                    <th>Billing Cycle</th>
                                    <th>Reading Date</th>
                                    <th>Action</th>
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

    // Load category rates on page load
    document.addEventListener('DOMContentLoaded', function() {
        loadCategoryRates();
        loadCustomersWithReadings();
    });

    function loadCategoryRates() {
        fetch('get_category_rates.php')
            .then(res => res.json())
            .then(data => {
                categoryRates = data;
                console.log('Rates loaded:', categoryRates);
            });
    }

    function loadCustomersWithReadings() {
        fetch('get_customer_billing_status.php?all=1')
            .then(res => res.json())
            .then(data => {
                if (data && Array.isArray(data) && data.length > 0) {
                    let html = '';
                    data.forEach(customer => {
                        html += `
                            <tr>
                                <td><strong>${customer.firstname} ${customer.lastname}</strong></td>
                                <td>${customer.meter_code}</td>
                                <td><span class="badge bg-success">${customer.verified_reading}</span> m³</td>
                                <td>${customer.cycle_name || 'N/A'}</td>
                                <td>${new Date(customer.processed_date).toLocaleDateString()}</td>
                                <td>
                                    <button class="btn btn-sm btn-primary" onclick="selectCustomerFromTab(${customer.client_id})">
                                        <i class="fas fa-arrow-right"></i> Use
                                    </button>
                                </td>
                            </tr>
                        `;
                    });
                    document.getElementById('customersTableBody').innerHTML = html;
                } else {
                    document.getElementById('customersTableBody').innerHTML = '<tr><td colspan="6" class="text-center text-muted">No customers with verified readings found</td></tr>';
                }
            })
            .catch(err => {
                console.error('Error loading customers:', err);
                document.getElementById('customersTableBody').innerHTML = '<tr><td colspan="6" class="text-center text-danger">Error loading data</td></tr>';
            });
    }

    function selectCustomerFromTab(clientId) {
        document.getElementById('customer_select').value = clientId;
        document.getElementById('customer_select').dispatchEvent(new Event('change'));
        document.getElementById('entry-tab').click();
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
                    verifiedReadingValue = parseFloat(data.verified_reading);
                    
                    // Fill April column with verified reading
                    document.getElementById('apr_curr').value = verifiedReadingValue.toFixed(2);
                    document.getElementById('verifiedReadingValue').textContent = verifiedReadingValue.toFixed(2);
                    document.getElementById('verifiedDate').textContent = new Date(data.processed_date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
                    document.getElementById('verifiedReadingBox').style.display = 'block';
                    
                    // Calculate April bill
                    calculateMonthly('apr');
                    
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
        document.getElementById('exampleAprilReading').textContent = verifiedReadingValue.toFixed(2);

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

        // December small example
        const decSmallPrev = Math.max(0, dec - smallMonthlyUsage);
        const decSmallCurr = dec;
        const decSmallUsage = decSmallCurr - decSmallPrev;
        const decSmallBill = calculateBill(decSmallUsage, baseRate, excessRate);

        document.getElementById('exSmallDecPrev').textContent = decSmallPrev.toFixed(2);
        document.getElementById('exSmallDecCurr').textContent = decSmallCurr.toFixed(2);
        document.getElementById('exSmallDecUse').textContent = decSmallUsage.toFixed(2);
        document.getElementById('exSmallDecBill').textContent = decSmallBill.toFixed(2);

        // January small example
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

        // December large example
        const decLargePrev = Math.max(0, dec_lg - largeMonthlyUsage);
        const decLargeCurr = Math.max(0, dec_lg);
        const decLargeUsage = decLargeCurr - decLargePrev;
        const decLargeBill = calculateBill(decLargeUsage, baseRate, excessRate);

        document.getElementById('exLargeDecPrev').textContent = decLargePrev.toFixed(2);
        document.getElementById('exLargeDecCurr').textContent = decLargeCurr.toFixed(2);
        document.getElementById('exLargeDecUse').textContent = decLargeUsage.toFixed(2);
        document.getElementById('exLargeDecBill').textContent = decLargeBill.toFixed(2);

        // January large example
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

    function clearAprilColumn() {
        document.getElementById('apr_prev').value = '0';
        document.getElementById('apr_curr').value = '0';
        document.getElementById('apr_bill').textContent = '0.00';
    }

    function calculateMonthly(month) {
        const prevInput = document.getElementById(month + '_prev');
        const currInput = document.getElementById(month + '_curr');
        const billSpan = document.getElementById(month + '_bill');
        
        if (!prevInput || !currInput || !billSpan) return;

        const prevReading = parseFloat(prevInput.value) || 0;
        const currReading = parseFloat(currInput.value) || 0;
        const consumption = currReading - prevReading;

        const categoryId = document.getElementById('customer_select').options[document.getElementById('customer_select').selectedIndex].dataset.category;

        if (categoryId && categoryRates[categoryId]) {
            const rates = categoryRates[categoryId];
            const baseRate = rates.rate;
            const excessRate = rates.excess_rate;

            let amount = 0;

            if (consumption <= 6) {
                amount = (consumption / 6) * baseRate;
            } else {
                const excessUsage = consumption - 6;
                amount = baseRate + (excessUsage * excessRate);
            }

            billSpan.textContent = amount.toFixed(2);
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

        // Check if at least one month has data
        const months = ['dec', 'jan', 'feb', 'mar', 'apr'];
        let hasData = false;
        
        months.forEach(month => {
            const prev = parseFloat(document.getElementById(month + '_prev').value) || 0;
            const curr = parseFloat(document.getElementById(month + '_curr').value) || 0;
            if (prev !== 0 || curr !== 0) {
                hasData = true;
            }
        });

        if (!hasData) {
            e.preventDefault();
            alert('Please enter readings for at least one month');
            return false;
        }
    });
</script>

<?php include 'footer.php'; ?>

