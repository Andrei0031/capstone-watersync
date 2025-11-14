<?php
session_start();

// Clear all customer-related session variables
unset($_SESSION['customer_id']);
unset($_SESSION['client_id']);
unset($_SESSION['customer_name']);

// Destroy the session
session_destroy();

// Redirect to login page
header('Location: customer_login.php');
exit();
?> 