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
    margin-top: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.readings-table th,
.readings-table td {
    padding: 12px;
    border: 1px solid var(--bs-border-color);
    text-align: center;
}

.readings-table th {
    background-color: var(--bs-secondary-bg);
    font-weight: bold;
    color: white;
}

.readings-table tr:hover {
    background-color: rgba(0,0,0,0.02);
}

.readings-table input[type="number"],
.readings-table input[type="month"],
.readings-table select {
    width: 100%;
    padding: 8px;
    box-sizing: border-box;
    border: 1px solid var(--bs-border-color);
    border-radius: 4px;
    background-color: var(--bs-body-bg);
    color: var(--bs-body-color);
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
}

.btn-add-row:hover {
    background-color: #218838;
}

.btn-remove {
    background: #dc3545;
    color: white;
    padding: 6px 12px;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    font-weight: 500;
}

.btn-remove:hover {
    background: #c82333;
}

.btn-remove:disabled {
    background: #ccc;
    cursor: not-allowed;
}

.btn-submit {
    margin-top: 20px;
    background-color: #007bff;
    color: white;
    padding: 12px 30px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-weight: 600;
    font-size: 1.05rem;
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

        <form id="bulkBillingForm" method="POST" action="process_bulk_billing.php">
            <!-- Customer Selection -->
            <div class="form-group">
                <label for="customer_select"><strong>Select Customer:</strong></label>
                <select id="customer_select" name="client_id" required>
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

            <!-- Verified April Reading Box (Locked) -->
            <div id="verifiedReadingBox" style="display: none;">
                <div class="verified-reading-box">
                    <h6><i class="fas fa-lock me-2"></i>Latest Verified Reading (April Billing)</h6>
                    <p><strong>Verified Reading:</strong> <span id="verifiedReadingValue">--</span> m³</p>
                    <p><strong>Cycle:</strong> <span id="verifiedCycleName">--</span></p>
                    <p><strong>Processed Date:</strong> <span id="verifiedDate">--</span></p>
                    <p style="color: #666; font-size: 0.9rem; margin-top: 10px;"><i class="fas fa-info-circle me-1"></i>Use this as your <strong>Previous Reading</strong> for the next billing month</p>
                </div>
            </div>

            <!-- Rate Information -->
            <div id="rateInfoBox" style="display: none;">
                <div class="rate-info">
                    <h5>Current Water Rates</h5>
                    <p><strong>Base Rate (First 6 m³):</strong> ₱<span id="baseRate">0.00</span> per 6 m³</p>
                    <p><strong>Excess Rate:</strong> ₱<span id="excessRate">0.00</span> per m³ (above 6 m³)</p>
                    <p style="margin-top: 10px; color: #666; font-size: 0.9rem;">
                        <i class="fas fa-calculator me-1"></i>
                        <strong>Calculation:</strong> If consumption ≤ 6 m³: Bill = Base Rate. If consumption > 6 m³: Bill = Base Rate + (Excess m³ × Excess Rate)
                    </p>
                </div>
            </div>

            <!-- Billing Records Table -->
            <table class="readings-table">
                <thead>
                    <tr>
                        <th width="15%">Month & Year</th>
                        <th width="15%">Previous Reading (m³)</th>
                        <th width="15%">Current Reading (m³)</th>
                        <th width="12%">Usage (m³)</th>
                        <th width="13%">Bill Amount (₱)</th>
                        <th width="12%">Status</th>
                        <th width="8%">Action</th>
                    </tr>
                </thead>
                <tbody id="billingRows">
                    <!-- Rows will be added here -->
                </tbody>
            </table>

            <button type="button" class="btn-add-row" onclick="addBillingRow()">
                <i class="fas fa-plus me-2"></i>Add Billing Month
            </button>
            <button type="submit" class="btn-submit">
                <i class="fas fa-save me-2"></i>Save All Billing Records
            </button>
        </form>
    </div>
</div>

<script>
    let rowCount = 0;
    let categoryRates = {};
    let verifiedReading = null;

    // Load category rates on page load
    document.addEventListener('DOMContentLoaded', function() {
        loadCategoryRates();
        
        // Add initial 2 rows
        for (let i = 0; i < 2; i++) {
            addBillingRow();
        }
    });

    function loadCategoryRates() {
        fetch('get_category_rates.php')
            .then(res => res.json())
            .then(data => {
                categoryRates = data;
                console.log('Rates loaded:', categoryRates);
            });
    }

    function loadVerifiedReading(clientId) {
        if (!clientId) {
            document.getElementById('verifiedReadingBox').style.display = 'none';
            document.getElementById('rateInfoBox').style.display = 'none';
            verifiedReading = null;
            return;
        }

        fetch(`get_verified_april_readings.php?client_id=${clientId}`)
            .then(res => res.json())
            .then(data => {
                if (data.success && data.verified_reading) {
                    verifiedReading = data.verified_reading;
                    document.getElementById('verifiedReadingValue').textContent = data.verified_reading.toFixed(2);
                    document.getElementById('verifiedCycleName').textContent = data.cycle_name || 'April Billing';
                    document.getElementById('verifiedDate').textContent = new Date(data.processed_date).toLocaleDateString();
                    document.getElementById('verifiedReadingBox').style.display = 'block';
                } else {
                    document.getElementById('verifiedReadingBox').style.display = 'none';
                    verifiedReading = null;
                }

                // Show rate info
                const categoryId = document.getElementById('customer_select').options[document.getElementById('customer_select').selectedIndex].dataset.category;
                if (categoryId && categoryRates[categoryId]) {
                    const rates = categoryRates[categoryId];
                    document.getElementById('baseRate').textContent = rates.rate.toFixed(2);
                    document.getElementById('excessRate').textContent = rates.excess_rate.toFixed(2);
                    document.getElementById('rateInfoBox').style.display = 'block';
                }
            });
    }

    function addBillingRow() {
        rowCount++;
        const row = document.createElement('tr');
        row.id = 'row_' + rowCount;
        row.innerHTML = `
            <td>
                <input type="month" name="month[]" required>
            </td>
            <td>
                <input type="number" step="0.01" name="previous_reading[]" placeholder="0" value="0" required>
            </td>
            <td>
                <input type="number" step="0.01" name="current_reading[]" placeholder="0" value="0" oninput="calculateRow(${rowCount})" required>
            </td>
            <td>
                <input type="number" step="0.01" class="calculated" name="consumption[]" readonly>
            </td>
            <td>
                <input type="number" step="0.01" class="calculated" name="amount[]" readonly>
                <div class="calculation-info"></div>
            </td>
            <td>
                <select name="status[]">
                    <option value="pending">Pending</option>
                    <option value="paid">Paid</option>
                </select>
            </td>
            <td>
                <button type="button" class="btn-remove" onclick="removeRow(${rowCount})">Remove</button>
            </td>
        `;
        document.getElementById('billingRows').appendChild(row);
    }

    function removeRow(id) {
        const row = document.getElementById('row_' + id);
        if (row) row.remove();
    }

    function calculateRow(rowId) {
        const row = document.getElementById('row_' + rowId);
        if (!row) return;

        const prevReading = parseFloat(row.querySelector('input[name="previous_reading[]"]').value) || 0;
        const currReading = parseFloat(row.querySelector('input[name="current_reading[]"]').value) || 0;
        const consumption = currReading - prevReading;

        row.querySelector('input[name="consumption[]"]').value = consumption.toFixed(2);

        // Get category ID and calculate amount using water rate formula
        const categoryId = document.getElementById('customer_select').options[document.getElementById('customer_select').selectedIndex].dataset.category;

        if (categoryId && categoryRates[categoryId]) {
            const rates = categoryRates[categoryId];
            const baseRate = rates.rate; // Rate per 6 m³
            const excessRate = rates.excess_rate; // Rate per m³ above 6 m³

            let amount = 0;
            let info = '';

            if (consumption <= 6) {
                // Charge = (consumption / 6) * base_rate
                amount = (consumption / 6) * baseRate;
                info = `(${consumption.toFixed(2)}/6) × ${baseRate.toFixed(2)} = ₱${amount.toFixed(2)}`;
            } else {
                // Charge = base_rate + (consumption - 6) * excess_rate
                const excessUsage = consumption - 6;
                const baseCharge = baseRate;
                const excessCharge = excessUsage * excessRate;
                amount = baseCharge + excessCharge;
                info = `Base: ₱${baseCharge.toFixed(2)} + Excess: (${excessUsage.toFixed(2)} × ${excessRate.toFixed(2)}) = ₱${amount.toFixed(2)}`;
            }

            row.querySelector('input[name="amount[]"]').value = amount.toFixed(2);
            const infoDiv = row.querySelector('.calculation-info');
            infoDiv.textContent = info;
            infoDiv.classList.add('show');
        }
    }

    // Load verified reading and recalculate when customer changes
    document.getElementById('customer_select').addEventListener('change', function() {
        loadVerifiedReading(this.value);
        document.querySelectorAll('#billingRows input[name="current_reading[]"]').forEach((input, idx) => {
            calculateRow(idx + 1);
        });
    });

    // Form validation
    document.getElementById('bulkBillingForm').addEventListener('submit', function(e) {
        if (!document.getElementById('customer_select').value) {
            e.preventDefault();
            alert('Please select a customer');
            return false;
        }

        const rows = document.querySelectorAll('#billingRows tr');
        if (rows.length === 0) {
            e.preventDefault();
            alert('Please add at least one billing record');
            return false;
        }

        // Validate all rows have data
        let hasValidData = false;
        document.querySelectorAll('#billingRows tr').forEach(row => {
            const month = row.querySelector('input[name="month[]"]').value;
            const amount = row.querySelector('input[name="amount[]"]').value;
            if (month && amount && parseFloat(amount) > 0) {
                hasValidData = true;
            }
        });

        if (!hasValidData) {
            e.preventDefault();
            alert('Please fill in at least one complete billing record with readings');
            return false;
        }
    });
</script>

<?php include 'footer.php'; ?>

