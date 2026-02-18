// Apply theme immediately before DOM content loads
const savedTheme = localStorage.getItem('theme') || 'light';
document.documentElement.setAttribute('data-theme', savedTheme);

// --- Admin "secret delete" for bills (define early so inline onclick always works) ---
// Uses prompt/confirm so it works even if Bootstrap modals are not available.
window.deleteBillWithPassword = async function(billId) {
    console.log('=== DELETE BILL FUNCTION CALLED ===', billId);
    alert('Delete function called for bill ID: ' + billId); // Temporary debug
    try {
        const idNum = parseInt(billId, 10);
        if (!idNum) {
            console.error('Invalid bill ID:', billId);
            throw new Error('Invalid bill ID');
        }

        console.log('Prompting for password...');
        const password = prompt('Enter the admin delete password to delete this bill:');
        if (password === null) return; // user cancelled
        if (!password) throw new Error('Delete password is required.');

        const ok = confirm(
            'Are you sure you want to delete this bill?\n\n' +
            'This will permanently remove the bill and related payments/notices.\n' +
            'This action cannot be undone.'
        );
        if (!ok) return;

        const response = await fetch('delete_bill.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ bill_id: idNum, password })
        });

        const text = await response.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('delete_bill.php returned non-JSON:', text);
            throw new Error('Delete failed (server returned invalid response).');
        }

        if (!data.success) {
            throw new Error(data.message || 'Delete failed.');
        }

        if (typeof showSuccess !== 'undefined') {
            showSuccess(data.message || 'Bill deleted successfully');
        } else {
            alert(data.message || 'Bill deleted successfully');
        }

        setTimeout(() => window.location.reload(), 800);
    } catch (err) {
        console.error('Delete bill error:', err);
        const msg = err?.message || 'Delete failed.';
        if (typeof showError !== 'undefined') {
            showError(msg);
        } else {
            alert(msg);
        }
    }
};

document.addEventListener('DOMContentLoaded', function() {
    // Theme Toggle
    const themeToggle = document.getElementById('theme-toggle');
    const html = document.documentElement;
    
    const savedTheme = localStorage.getItem('theme') || 'light';
    html.setAttribute('data-theme', savedTheme);
    if (themeToggle) {
        themeToggle.checked = savedTheme === 'dark';
        themeToggle.addEventListener('change', function() {
            const theme = this.checked ? 'dark' : 'light';
            html.setAttribute('data-theme', theme);
            localStorage.setItem('theme', theme);
        });
    }

    // Listen for theme changes from other pages
    window.addEventListener('storage', function(e) {
        if (e.key === 'theme_updated') {
            const currentTheme = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', currentTheme);
            if (themeToggle) {
                themeToggle.checked = currentTheme === 'dark';
            }
        }
    });

    // View Bill Modal Functionality
    const viewModal = new bootstrap.Modal(document.getElementById('viewBillModal'));
    document.querySelectorAll('.view-btn').forEach(button => {
        button.addEventListener('click', function() {
            const billId = this.getAttribute('data-id');
            const row = this.closest('tr');
            
            // Get customer info from the first cell's divs
            const customerCell = row.querySelector('td:nth-child(2)');
            const customerName = customerCell.querySelector('.customer-name').textContent.trim();
            const meterCode = customerCell.querySelector('.customer-code').textContent.trim();
            const initials = customerCell.querySelector('.avatar-sm').textContent.trim();
            
            // Get other data from the row
            const reading = row.querySelector('td:nth-child(3)').textContent.trim();
            const consumption = row.querySelector('td:nth-child(4)').textContent.trim();
            const amount = row.querySelector('td:nth-child(5)').textContent.trim();
            const dueDate = row.querySelector('td:nth-child(6)').textContent.trim();
            const status = row.querySelector('.status-badge').textContent.trim();

            // Update modal content
            document.getElementById('viewCustomerInitials').textContent = initials;
            document.getElementById('viewCustomerName').textContent = customerName;
            document.getElementById('viewMeterCode').textContent = meterCode;
            document.getElementById('viewBillNumber').textContent = billId;
            document.getElementById('viewCurrentReading').textContent = reading;
            document.getElementById('viewConsumption').textContent = consumption;
            document.getElementById('viewTotalAmount').textContent = amount;
            document.getElementById('viewDueDate').textContent = dueDate;

            // Update status badge
            const statusElem = document.getElementById('viewStatus');
            statusElem.textContent = status;
            statusElem.className = 'status-badge ' + (
                status === 'Paid' ? 'status-paid' :
                status === 'Overdue' ? 'status-overdue' : 'status-pending'
            );

            // Show/hide "Mark as Paid" button based on status
            const markAsPaidBtn = document.getElementById('markAsPaidBtn');
            if (markAsPaidBtn) {
                markAsPaidBtn.style.display = status === 'Paid' ? 'none' : 'block';
            }

            viewModal.show();
        });
    });

    // Edit Bill Modal Functionality (only if modal exists on this page)
    const editBillModalElement = document.getElementById('editBillModal');
    if (editBillModalElement) {
        const editModal = new bootstrap.Modal(editBillModalElement);
    document.querySelectorAll('.edit-btn').forEach(button => {
        button.addEventListener('click', function() {
            const billId = this.getAttribute('data-id');
            
            // Show loading state
            this.setAttribute('disabled', 'disabled');
            
            console.log('Fetching bill details for ID:', billId); // Debug log
            
            fetch(`get_bill_details.php?id=${billId}`)
                .then(async response => {
                    const text = await response.text();
                    console.log('Raw response:', text); // Debug log
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('JSON parse error:', e); // Debug log
                        throw new Error('Invalid JSON response: ' + text);
                    }
                })
                .then(data => {
                    // Remove loading state
                    this.removeAttribute('disabled');
                    console.log('Parsed data:', data); // Debug log
                    
                    if (!data.success) {
                        throw new Error(data.message || 'Failed to fetch bill details');
                    }
                    
                    const bill = data.data;
                    console.log('Bill data:', bill); // Debug log
                    
                    // Populate the edit form
                    document.getElementById('editBillId').value = bill.id;
                    document.getElementById('editReadingDate').value = bill.reading_date_formatted;
                    document.getElementById('editDueDate').value = bill.due_date_formatted;
                    document.getElementById('editStatus').value = bill.status || '0';
                    document.getElementById('editPreviousReading').value = bill.previous || '0';
                    document.getElementById('editCurrentReading').value = bill.reading || '0';
                    document.getElementById('editRate').value = bill.rate || '0';
                    
                    // Calculate totals
                    calculateTotals();
                    
                    // Show the modal
                    editModal.show();
                })
                .catch(error => {
                    // Remove loading state
                    this.removeAttribute('disabled');
                    console.error('Detailed error:', error); // Debug log
                    showError('Error fetching billing details: ' + error.message);
                });
        });
    });

    // Delete Bill Functionality - REMOVED
    // Bills should not be deleted manually
    // document.querySelectorAll('.delete-btn').forEach(button => {
    //     button.addEventListener('click', function() {
    //         // Delete functionality removed
    //     });
    // });

    // Calculate totals function
    async function calculateTotals() {
        try {
            const previousReading = parseFloat(document.getElementById('editPreviousReading').value) || 0;
            const currentReading = parseFloat(document.getElementById('editCurrentReading').value) || 0;
            const billId = document.getElementById('editBillId').value;
            
            if (!billId) {
                throw new Error('Bill ID is missing');
            }

            // Calculate consumption
            const consumption = Math.max(0, currentReading - previousReading);
            
            // Get rates for this bill
            const response = await fetch(`get_bill_rates.php?bill_id=${billId}`);
            const data = await response.json();
            
            if (!data.success) {
                throw new Error(data.message || 'Failed to get rate information');
            }
            
            const baseRate = parseFloat(data.rate);
            const excessRate = parseFloat(data.excess_rate);
            
            if (isNaN(baseRate) || isNaN(excessRate)) {
                throw new Error('Invalid rate values received');
            }
            
            let total;
            let baseCharge = baseRate;
            let excessCharge = 0;
            
            if (consumption > 6) {
                const excessConsumption = consumption - 6;
                excessCharge = excessConsumption * excessRate;
                total = baseRate + excessCharge;
            } else {
                total = baseRate;
            }

            // Update the display
            document.getElementById('editConsumption').textContent = `${consumption.toFixed(2)}`; // Consumption keeps decimals
            document.getElementById('editBaseCharge').textContent = `₱${baseCharge.toFixed(2)}`;
            document.getElementById('editExcessCharge').textContent = `₱${excessCharge.toFixed(2)}`;
            document.getElementById('editTotalAmount').textContent = `₱${total.toFixed(2)}`;
            document.getElementById('editTotalInput').value = total.toFixed(2);
        } catch (error) {
            console.error('Error calculating totals:', error);
            showError('Error calculating bill totals: ' + error.message);
        }
    }

    // Add calculation listeners
    ['editPreviousReading', 'editCurrentReading'].forEach(id => {
        const element = document.getElementById(id);
        if (element) {
            element.addEventListener('input', calculateTotals);
        }
    });

    // Edit form submission
    const editForm = document.getElementById('editBillForm');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...';
            
            const formData = new FormData(this);
            
            fetch('update_bill.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success message
                    const toast = document.createElement('div');
                    toast.className = 'alert alert-success position-fixed top-0 end-0 m-3';
                    toast.style.zIndex = '9999';
                    toast.innerHTML = `
                        <i class="fas fa-check-circle me-2"></i>
                        ${data.message}
                    `;
                    document.body.appendChild(toast);
                    
                    // Hide toast after 3 seconds
                    setTimeout(() => {
                        toast.remove();
                    }, 3000);
                    
                    // Close modal and refresh
                    editModal.hide();
                    location.reload();
                } else {
                    throw new Error(data.message || 'Failed to update bill');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('Error updating bill: ' + error.message);
            })
            .finally(() => {
                // Restore button state
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        });
    }
}); 