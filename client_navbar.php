<?php
if (!isset($_SESSION)) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['client_id'])) {
    header("Location: login.php");
    exit();
}
?>

<nav class="navbar navbar-expand-lg navbar-dark mb-4" style="background: linear-gradient(45deg, #0D47A1, #2196F3);">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center" href="customer_dashboard.php">
            <img src="icons/Logo.png" alt="WaterSync Logo" style="height: 40px; filter: brightness(0) invert(1); margin-right: 10px;">
            WaterSync
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'customer_dashboard.php' ? 'active' : ''; ?>" 
                       href="customer_dashboard.php">
                        <i class="fas fa-home me-2"></i>Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'client_notices.php' ? 'active' : ''; ?>" 
                       href="client_notices.php">
                        <i class="fas fa-bell me-2"></i>Notices
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : ''; ?>" 
                       href="profile.php">
                        <i class="fas fa-user me-2"></i>Profile
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="customer_logout.php">
                        <i class="fas fa-sign-out-alt me-2"></i>Logout
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav> 