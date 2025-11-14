<?php
function validateSession() {
    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Check if this is an API request
    $isApiRequest = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                   strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    
    // Check if customer is logged in
    if (!isset($_SESSION['customer_id']) || !isset($_SESSION['client_id'])) {
        // Clear any partial session data
        session_unset();
        session_destroy();
        
        if ($isApiRequest) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Session expired',
                'redirect' => 'customer_login.php'
            ]);
            exit;
        } else {
            header("Location: customer_login.php");
            exit;
        }
    }
    
    // Validate session timeout
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
        // Session expired (30 minutes)
        session_unset();
        session_destroy();
        
        if ($isApiRequest) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Session timeout',
                'redirect' => 'customer_login.php?timeout=1'
            ]);
            exit;
        } else {
            header("Location: customer_login.php?timeout=1");
            exit;
        }
    }
    
    // Update last activity time
    $_SESSION['last_activity'] = time();
    
    return true;
} 