// Apply theme immediately before DOM content loads
const savedTheme = localStorage.getItem('theme') || 'light';
document.documentElement.setAttribute('data-theme', savedTheme);

document.addEventListener('DOMContentLoaded', function() {
    // Theme Toggle
    const themeToggle = document.getElementById('theme-toggle');
    const html = document.documentElement;
    
    const savedTheme = localStorage.getItem('theme') || 'light';
    html.setAttribute('data-theme', savedTheme);
    themeToggle.checked = savedTheme === 'dark';

    themeToggle.addEventListener('change', function() {
        const theme = this.checked ? 'dark' : 'light';
        html.setAttribute('data-theme', theme);
        localStorage.setItem('theme', theme);
    });

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

    // Edit Bill Modal Functionality
    const editModal = new bootstrap.Modal(document.getElementById('editBillModal'));
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

    // Delete bill with password verification (single-step: password + delete)
    window.deleteBillWithPassword = function(billId) {
        // Show password verification modal
        const passwordModalId = 'deletePasswordModal';
        let passwordModalElement = document.getElementById(passwordModalId);
        
        if (passwordModalElement) {
            passwordModalElement.remove();
        }
        
        const passwordModalHtml = `
            <div class="modal fade" id="${passwordModalId}" tabindex="-1" aria-labelledby="${passwordModalId}Label" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header bg-danger text-white">
                            <h5 class="modal-title" id="${passwordModalId}Label">
                                <i class="fas fa-lock me-2"></i>Verify Delete Password
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Warning:</strong> This action cannot be undone. Please enter the delete password to confirm.
                            </div>
                            <div class="mb-3">
                                <label for="deletePasswordInput" class="form-label">Delete Password</label>
                                <input type="password" class="form-control" id="deletePasswordInput" placeholder="Enter delete password" autofocus>
                                <div id="passwordError" class="text-danger mt-2" style="display: none;"></div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-danger" id="confirmDeleteWithPassword" data-bill-id="${billId}">
                                <i class="fas fa-trash me-2"></i>Delete Bill
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', passwordModalHtml);
        passwordModalElement = document.getElementById(passwordModalId);
        const passwordModal = new bootstrap.Modal(passwordModalElement);
        
        // Handle password input Enter key
        document.getElementById('deletePasswordInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                document.getElementById('confirmDeleteWithPassword').click();
            }
        });
        
        // Handle confirm delete button
        document.getElementById('confirmDeleteWithPassword').addEventListener('click', function() {
            const password = document.getElementById('deletePasswordInput').value;
            const billId = this.getAttribute('data-bill-id');
            const errorDiv = document.getElementById('passwordError');
            
            if (!password) {
                errorDiv.textContent = 'Password is required';
                errorDiv.style.display = 'block';
                return;
            }

            // Hide modal and confirm deletion, password is sent to server
            passwordModal.hide();

            const confirmMessage = 'Are you sure you want to delete this bill? This will remove the bill, its payments, notices, and notifications. This action cannot be undone.';

            if (typeof showConfirm !== 'undefined') {
                showConfirm(confirmMessage, function() {
                    deleteBill(billId, password);
                });
            } else if (confirm(confirmMessage)) {
                deleteBill(billId, password);
            }
        });
        
        passwordModalElement.addEventListener('hidden.bs.modal', function() {
            passwordModalElement.remove();
        }, { once: true });
        
        passwordModal.show();
        document.getElementById('deletePasswordInput').focus();
    };

    function deleteBill(billId, password) {
        fetch('delete_bill.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ bill_id: billId, password: password })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (typeof showSuccess !== 'undefined') {
                    showSuccess(data.message);
                } else {
                    alert('Success: ' + data.message);
                }
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            } else {
                if (typeof showError !== 'undefined') {
                    showError(data.message);
                } else {
                    alert('Error: ' + data.message);
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
            if (typeof showError !== 'undefined') {
                showError('Error deleting bill');
            } else {
                alert('Error deleting bill');
            }
        });
    }
}); 