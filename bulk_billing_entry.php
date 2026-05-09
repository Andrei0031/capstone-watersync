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
}

.readings-table th,
.readings-table td {
    padding: 10px;
    border: 1px solid var(--bs-border-color);
    text-align: center;
}

.readings-table th {
    background-color: var(--bs-secondary-bg);
    font-weight: bold;
}

.readings-table input {
    width: 100%;
    padding: 5px;
    box-sizing: border-box;
}

.readings-table .calculated {
    background-color: rgba(0, 255, 0, 0.1);
    font-weight: bold;
}

.btn-add-row {
    margin-top: 10px;
    background-color: #28a745;
    color: white;
    padding: 8px 15px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}

.btn-add-row:hover {
    background-color: #218838;
}

.btn-submit {
    margin-top: 20px;
    background-color: #007bff;
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}

.btn-submit:hover {
    background-color: #0056b3;
}

.alert {
    padding: 12px;
    margin-bottom: 20px;
    border-radius: 4px;
}

.alert-success {
    background-color: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.alert-danger {
    background-color: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}
</style>

<div class="main-content">
    <div class="bulk-billing-container">
        <h2>Bulk Billing Entry</h2>
        <p>Add multiple billing records for a customer in one go</p>

        <?php if (isset($_SESSION['bulk_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['bulk_status']; ?>">
                <?php echo $_SESSION['bulk_message']; ?>
            </div>
            <?php unset($_SESSION['bulk_message']); unset($_SESSION['bulk_status']); ?>
        <?php endif; ?>

        <form id="bulkBillingForm" method="POST" action="process_bulk_billing.php">
            <!-- Customer Selection -->
            <div style="margin-bottom: 20px;">
                <label for="customer_select"><strong>Select Customer:</strong></label>
                <select id="customer_select" name="client_id" required style="width: 100%; padding: 8px; margin-top: 5px;">
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

            <!-- Billing Records Table -->
            <table class="readings-table">
                <thead>
                    <tr>
                        <th>Month & Year</th>
                        <th>Previous Reading</th>
                        <th>Current Reading</th>
                        <th>Consumption</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="billingRows">
                    <!-- Rows will be added here -->
                </tbody>
            </table>

            <button type="button" class="btn-add-row" onclick="addBillingRow()">+ Add Month</button>
            <button type="submit" class="btn-submit">Save All Billing Records</button>
        </form>
    </div>
</div>

<script>
    let rowCount = 0;
    let categoryRates = {};

    // Load category rates on page load
    document.addEventListener('DOMContentLoaded', function() {
        loadCategoryRates();
        
        // Add initial 3 rows
        for (let i = 0; i < 3; i++) {
            addBillingRow();
        }
    });

    function loadCategoryRates() {
        fetch('get_category_rates.php')
            .then(res => res.json())
            .then(data => {
                categoryRates = data;
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
            </td>
            <td>
                <select name="status[]">
                    <option value="pending">Pending</option>
                    <option value="paid">Paid</option>
                </select>
            </td>
            <td>
                <button type="button" onclick="removeRow(${rowCount})" style="background: #dc3545; color: white; padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer;">Remove</button>
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

        // Get category ID and calculate amount
        const clientId = document.getElementById('customer_select').value;
        const categoryId = document.getElementById('customer_select').options[document.getElementById('customer_select').selectedIndex].dataset.category;

        if (categoryId && categoryRates[categoryId]) {
            const rate = categoryRates[categoryId];
            let amount = consumption * rate;
            row.querySelector('input[name="amount[]"]').value = amount.toFixed(2);
        }
    }

    // Recalculate all rows when customer changes
    document.getElementById('customer_select').addEventListener('change', function() {
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
    });
</script>

<?php include 'footer.php'; ?>
