// Apply theme immediately before DOM content loads
const savedTheme = localStorage.getItem('theme') || 'light';
document.documentElement.setAttribute('data-theme', savedTheme);

document.addEventListener('DOMContentLoaded', function() {
    // Theme Toggle
    const themeToggle = document.getElementById('theme-toggle');
    if (themeToggle) {
        themeToggle.checked = savedTheme === 'dark';
        themeToggle.addEventListener('change', function() {
            const theme = this.checked ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', theme);
            localStorage.setItem('theme', theme);
            
            // Broadcast theme change to other pages
            try {
                localStorage.setItem('theme_updated', Date.now().toString());
            } catch (e) {
                console.error('Could not broadcast theme change:', e);
            }
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
            
            // Get other data from the row
            const reading = row.querySelector('td:nth-child(3)').textContent.trim();
            const consumption = row.querySelector('td:nth-child(4)').textContent.trim();
            const amount = row.querySelector('td:nth-child(5)').textContent.trim();
            const dueDate = row.querySelector('td:nth-child(6)').textContent.trim();
            const status = row.querySelector('.status-badge').textContent.trim();

            // Calculate initials from customer name
            const initials = customerName.split(' ')
                .map(name => name.charAt(0))
                .join('')
                .toUpperCase();

            // Update modal content
            document.getElementById('viewCustomerName').textContent = customerName;
            document.getElementById('viewMeterCode').textContent = meterCode;
            document.getElementById('viewBillNumber').textContent = billId;
            document.getElementById('viewCurrentReading').textContent = reading;
            document.getElementById('viewConsumption').textContent = consumption;
            document.getElementById('viewTotalAmount').textContent = amount;
            document.getElementById('viewDueDate').textContent = dueDate;
            document.getElementById('viewCustomerInitials').textContent = initials;

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

    // Edit Bill Modal Functionality
    const editModal = new bootstrap.Modal(document.getElementById('editBillModal'));
    document.querySelectorAll('.edit-btn').forEach(button => {
        button.addEventListener('click', function() {
            const billId = this.getAttribute('data-id');
            
            // Show loading state
            this.setAttribute('disabled', 'disabled');
            
            fetch(`get_bill_details.php?id=${billId}`)
                .then(response => response.json())
                .then(data => {
                    // Remove loading state
                    this.removeAttribute('disabled');
                    
                    if (data.error) {
                        showError(data.error);
                        return;
                    }
                    
                    // Populate the edit form
                    document.getElementById('editBillId').value = data.id;
                    document.getElementById('editReadingDate').value = data.reading_date;
                    document.getElementById('editDueDate').value = data.due_date;
                    document.getElementById('editStatus').value = data.status || '0';
                    document.getElementById('editPreviousReading').value = data.previous || '0';
                    document.getElementById('editCurrentReading').value = data.reading || '0';
                    document.getElementById('editRate').value = data.rate || '0';
                    
                    // Calculate totals
                    calculateTotals();
                    
                    // Show the modal
                    editModal.show();
                })
                .catch(error => {
                    // Remove loading state
                    this.removeAttribute('disabled');
                    console.error('Error:', error);
                    showError('Error fetching billing details. Please try again.');
                });
        });
    });

    // Calculate totals function
    function calculateTotals() {
        const previousReading = parseFloat(document.getElementById('editPreviousReading').value) || 0;
        const currentReading = parseFloat(document.getElementById('editCurrentReading').value) || 0;
        
        const consumption = Math.max(0, currentReading - previousReading);
        const baseRate = 100; // 100 pesos base rate for first 6 cubic meters
        const excessRate = 20; // 20 pesos per cubic meter for excess
        
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

        document.getElementById('editConsumption').textContent = `${consumption.toFixed(2)}`;
        document.getElementById('editBaseCharge').textContent = `₱${baseCharge.toFixed(2)}`;
        document.getElementById('editExcessCharge').textContent = `₱${excessCharge.toFixed(2)}`;
        document.getElementById('editTotalAmount').textContent = `₱${total.toFixed(2)}`;
        document.getElementById('editTotalInput').value = total.toFixed(2);
    }

    // Add calculation listeners
    ['editPreviousReading', 'editCurrentReading', 'editRate'].forEach(id => {
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
                    // Show error message
                    const errorDiv = document.createElement('div');
                    errorDiv.className = 'alert alert-danger mt-3';
                    errorDiv.innerHTML = `
                        <i class="fas fa-exclamation-circle me-2"></i>
                        ${data.message}
                    `;
                    
                    // Remove any existing error messages
                    const existingError = editForm.querySelector('.alert-danger');
                    if (existingError) {
                        existingError.remove();
                    }
                    
                    // Add new error message
                    editForm.appendChild(errorDiv);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('Network error occurred. Please try again.');
            })
            .finally(() => {
                // Restore button state
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        });
    }
}); 