<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

include 'db.php';
include 'header.php';
?>

<style>
.modal {
    background-color: rgba(0, 0, 0, 0.5);
}
.modal-dialog {
    z-index: 1050;
    margin-top: 5vh;
}
.modal-content {
    background-color: var(--bs-body-bg);
    color: var(--bs-body-color);
    border: 1px solid var(--bs-border-color);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
}
.modal-header {
    border-bottom: 1px solid var(--bs-border-color);
}
.modal-footer {
    border-top: 1px solid var(--bs-border-color);
}
@media (max-width: 991.98px) {
    .sidebar {
        position: fixed;
        top: 0;
        left: 0;
        height: 100vh;
        width: 250px;
        background-color: var(--sidebar-bg, #fff);
        border-right: 1px solid var(--border-color, #dee2e6);
        transform: translateX(-250px);
        transition: transform 0.3s ease;
        z-index: 1050;
        display: block;
    }
    .sidebar.open {
        transform: translateX(0);
    }
    .sidebar-footer {
        position: absolute;
        bottom: 0;
        width: 100%;
    }
    .main-content {
        margin-left: 0 !important;
        padding: 20px 10px;
        transition: margin-left 0.3s ease;
    }
    #sidebarToggle {
        display: block;
        position: fixed;
        top: 15px;
        left: 15px;
        z-index: 1100;
        background-color: var(--sidebar-bg, #fff);
        border: none;
        padding: 8px 12px;
        border-radius: 5px;
        box-shadow: 0 0 5px rgba(0,0,0,0.2);
        cursor: pointer;
    }
}
@media (min-width: 992px) {
    .sidebar {
        position: fixed;
        top: 0;
        left: 0;
        height: 100vh;
        width: 250px;
        background-color: var(--sidebar-bg, #fff);
        border-right: 1px solid var(--border-color, #dee2e6);
        display: flex;
        flex-direction: column;
        transform: none !important;
    }
    .main-content {
        margin-left: 250px;
        padding: 30px;
    }
    #sidebarToggle {
        display: none;
    }
}
@media (max-width: 767.98px) {
    .table {
        font-size: 0.92rem;
    }
    .table th, .table td {
        padding: 6px 4px !important;
        word-break: break-word;
        vertical-align: middle;
    }
    .table th {
        white-space: nowrap;
    }
    .table td {
        max-width: 90px;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .btn-group .btn, .btn.btn-sm, .btn-outline-primary, .btn-outline-warning, .btn-outline-danger {
        padding: 3px 6px !important;
        margin-right: 2px;
        font-size: 0.95em;
    }
}
/* Sidebar logo containment to match dashboard */
.sidebar-header {
    padding: 20px;
    margin-bottom: 20px;
    text-align: center;
    background-color: var(--card-bg);
    border: 1px solid var(--border-color);
    margin: 0 20px 20px;
    border-radius: 12px;
    transition: background-color 0.3s, border-color 0.3s;
    overflow: hidden;
}
.sidebar-header img {
    max-width: 100%;
    height: auto;
    object-fit: contain;
    filter: none !important;
}

/* Prevent logo from being affected by dark mode filters */
html[data-theme="dark"] .sidebar-header img,
[data-theme="dark"] .sidebar-header img {
    filter: none !important;
    opacity: 1 !important;
    mix-blend-mode: normal !important;
}

/* Keep sidebar-header background light in dark mode for logo visibility */
html[data-theme="dark"] .sidebar-header,
[data-theme="dark"] .sidebar-header {
    background-color: #fff !important;
}

/* Action buttons improvements */
.table td .btn-sm {
    padding: 8px 12px !important;
    margin: 0 4px 0 0 !important;
    border-radius: 6px !important;
    min-width: 40px;
    height: 38px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9rem;
    transition: all 0.2s ease;
    border-width: 2px;
}

.table td .btn-sm:last-child {
    margin-right: 0 !important;
}

.table td .btn-sm i {
    font-size: 1rem;
}

.table td .btn-sm:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
}

.table td .btn-outline-primary {
    border-color: #0d6efd;
    color: #0d6efd;
}

.table td .btn-outline-primary:hover {
    background-color: #0d6efd;
    color: #fff;
}

.table td .btn-outline-warning {
    border-color: #ffc107;
    color: #ffc107;
}

.table td .btn-outline-warning:hover {
    background-color: #ffc107;
    color: #000;
}

.table td .btn-outline-danger {
    border-color: #dc3545 !important;
    color: #dc3545 !important;
}

.table td .btn-outline-danger:hover {
    background-color: #dc3545 !important;
    color: #fff !important;
}

/* Dark mode improvements for action buttons */
html[data-theme="dark"] .table td .btn-outline-primary,
[data-theme="dark"] .table td .btn-outline-primary {
    border-color: #4e9eff;
    color: #4e9eff;
}

html[data-theme="dark"] .table td .btn-outline-primary:hover,
[data-theme="dark"] .table td .btn-outline-primary:hover {
    background-color: #4e9eff;
    color: #fff;
}

html[data-theme="dark"] .table td .btn-outline-warning,
[data-theme="dark"] .table td .btn-outline-warning {
    border-color: #ffc107;
    color: #ffc107;
}

html[data-theme="dark"] .table td .btn-outline-warning:hover,
[data-theme="dark"] .table td .btn-outline-warning:hover {
    background-color: #ffc107;
    color: #000;
}
</style>

<!-- Hamburger Sidebar Toggle Button for Mobile -->
<button id="sidebarToggle" aria-label="Toggle sidebar">
    <i class="fas fa-bars"></i>
</button>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <img src="icons/Logo.png" alt="Water Billing Logo" class="img-fluid" style="max-height: 120px;" />
    </div>
    
    <div class="nav-content">
        <a href="adminlandingpage.php">
            <i class="fas fa-chart-line"></i>
            <span>Dashboard</span>
        </a>
        <a href="view_clients.php">
            <i class="fas fa-users"></i>
            <span>Customers</span>
        </a>
        <a href="billing_list.php">
            <i class="fas fa-file-invoice-dollar"></i>
            <span>Bills</span>
        </a>
        <a href="pending_readings.php">
            <i class="fas fa-camera"></i>
            <span>Meter Readings</span>
        </a>
        <a href="payments.php">
            <i class="fas fa-money-bill-wave"></i>
            <span>Payments</span>
        </a>
        <a href="customer_accounts.php" class="active">
            <i class="fas fa-user-circle"></i>
            <span>Customer Accounts</span>
        </a>
        <a href="reports.php">
            <i class="fas fa-chart-line"></i>
            <span>Reports</span>
        </a>
        <a href="client_reports.php">
            <i class="fas fa-chart-bar"></i>
            <span>Water Outage Reports</span>
        </a>
        <a href="disconnection_notices.php">
            <i class="fas fa-exclamation-triangle"></i>
            <span>Disconnection Notices</span>
        </a>
        <a href="settings_rate.php">
            <i class="fas fa-cog"></i>
            <span>Settings</span>
        </a>
    </div>

    <div class="sidebar-footer">
        <!-- Theme Switch -->
        <div class="theme-switch-wrapper">
            <i class="fas fa-sun"></i>
            <label class="theme-switch">
                <input type="checkbox" id="theme-toggle">
                <span class="slider"></span>
            </label>
            <i class="fas fa-moon"></i>
        </div>
        
        <form method="POST" action="logout.php" class="mt-3">
            <button type="submit" class="btn btn-outline-primary w-100">
                <i class="fas fa-sign-out-alt me-2"></i>
                Logout
            </button>
        </form>
    </div>
</div>

<!-- Main Content -->
<div class="main-content">
    <div class="container-fluid">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Customer Accounts</h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Customer Name</th>
                                <th>Meter Code</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Last Login</th>
                                <th>Created At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $sql = "SELECT ca.*, 
                                          cl.firstname, 
                                          cl.lastname, 
                                          cl.meter_code,
                                          CASE WHEN ca.status = 1 THEN 'Active' ELSE 'Inactive' END as status_text
                                   FROM customer_accounts ca
                                   JOIN client_list cl ON ca.client_id = cl.id
                                   ORDER BY ca.created_at DESC";
                            $result = $conn->query($sql);
                            
                            while ($row = $result->fetch_assoc()):
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['firstname'] . ' ' . $row['lastname']); ?></td>
                                <td><?php echo htmlspecialchars($row['meter_code']); ?></td>
                                <td><?php echo htmlspecialchars($row['email']); ?></td>
                                <td>
                                    <span class="badge <?php echo $row['status'] ? 'bg-success' : 'bg-danger'; ?>">
                                        <?php echo $row['status_text']; ?>
                                    </span>
                                </td>
                                <td><?php echo $row['last_login'] ? date('M d, Y H:i', strtotime($row['last_login'])) : 'Never'; ?></td>
                                <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary" onclick="toggleAccountStatus(<?php echo $row['id']; ?>, <?php echo $row['status']; ?>)" title="Toggle Status">
                                        <i class="fas <?php echo $row['status'] ? 'fa-ban' : 'fa-check'; ?>"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-warning" onclick="showChangePasswordModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['firstname'] . ' ' . $row['lastname']); ?>')" title="Change Password">
                                        <i class="fas fa-key"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger" onclick="deleteAccount(<?php echo $row['id']; ?>)" title="Delete Account">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Change Password Modal -->
<div class="modal fade" id="changePasswordModal" tabindex="-1" aria-labelledby="changePasswordModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="changePasswordModalLabel">Change Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Change password for: <span id="customerName" class="fw-bold"></span></p>
                <div class="mb-3">
                    <label for="newPassword" class="form-label">New Password</label>
                    <input type="password" class="form-control" id="newPassword" required>
                </div>
                <div class="mb-3">
                    <label for="confirmPassword" class="form-label">Confirm New Password</label>
                    <input type="password" class="form-control" id="confirmPassword" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="changePassword()">Save Changes</button>
            </div>
        </div>
    </div>
</div>

<script>
let currentAccountId = null;

function showChangePasswordModal(accountId, customerName) {
    currentAccountId = accountId;
    document.getElementById('customerName').textContent = customerName;
    document.getElementById('newPassword').value = '';
    document.getElementById('confirmPassword').value = '';
    new bootstrap.Modal(document.getElementById('changePasswordModal')).show();
}

function changePassword() {
    const newPassword = document.getElementById('newPassword').value;
    const confirmPassword = document.getElementById('confirmPassword').value;
    
    if (!newPassword || !confirmPassword) {
        showWarning('Please fill in both password fields');
        return;
    }
    
    if (newPassword !== confirmPassword) {
        showWarning('Passwords do not match');
        return;
    }
    
    fetch('change_customer_password.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            id: currentAccountId,
            password: newPassword
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showSuccess('Password changed successfully');
            bootstrap.Modal.getInstance(document.getElementById('changePasswordModal')).hide();
        } else {
            showError('Error changing password: ' + data.message);
        }
    });
}

function toggleAccountStatus(id, currentStatus) {
    if (confirm('Are you sure you want to ' + (currentStatus ? 'deactivate' : 'activate') + ' this account?')) {
        fetch('toggle_account_status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                id: id,
                status: currentStatus ? 0 : 1
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showSuccess('Account status updated successfully!');
                setTimeout(() => location.reload(), 1000);
            } else {
                showError('Error updating account status');
            }
        });
    }
}

function deleteAccount(id) {
    if (confirm('Are you sure you want to delete this account? This action cannot be undone.')) {
        fetch('delete_customer_account.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                id: id
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showSuccess('Account deleted successfully!');
                setTimeout(() => location.reload(), 1000);
            } else {
                showError('Error deleting account');
            }
        });
    }
}

// Sidebar toggle for mobile
document.addEventListener('DOMContentLoaded', function() {
    var sidebar = document.querySelector('.sidebar');
    var sidebarToggle = document.getElementById('sidebarToggle');
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('open');
        });
    }
    // Optional: close sidebar when clicking outside on mobile
    document.addEventListener('click', function(e) {
        if (window.innerWidth <= 991 && sidebar.classList.contains('open')) {
            if (!sidebar.contains(e.target) && e.target !== sidebarToggle) {
                sidebar.classList.remove('open');
            }
        }
    });
});
</script>

<?php include 'footer.php'; ?> 