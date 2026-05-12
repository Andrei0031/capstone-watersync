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
}); 