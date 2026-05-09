<?php
// Enable error display for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session FIRST before any output
session_start();

// Include database connection
include 'db.php';

// Check if database connection exists
if (!isset($conn) || !$conn) {
    $_SESSION['login_status'] = 'error';
    header("Location: adminlogin.php?error=db_connection");
    exit();
}

if (isset($_SERVER["REQUEST_METHOD"]) && $_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $_SESSION['login_status'] = 'error';
        header("Location: adminlogin.php");
        exit();
    }

    try {
        // Prepare SQL query to fetch the admin based on the username
        $sql = "SELECT * FROM admin WHERE username = ?";
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $admin = $result->fetch_assoc();

            // Check if the password is correct using password_verify for bcrypt hashes
            if (password_verify($password, $admin['password'])) {
                // Successful login, set session variables
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                $_SESSION['login_status'] = 'success';  // Set login status for modal
                header("Location: adminlogin.php");  // Redirect to login page to trigger modal
                exit();
            } else {
                // Incorrect password
                $_SESSION['login_status'] = 'error';  // Set error status for modal
                header("Location: adminlogin.php");  // Redirect back to login page
                exit();
            }
        } else {
            // User not found
            $_SESSION['login_status'] = 'error';  // Set error status for modal
            header("Location: adminlogin.php");  // Redirect back to login page
            exit();
        }
    } catch (Exception $e) {
        // Log error and redirect
        error_log("Login error: " . $e->getMessage());
        $_SESSION['login_status'] = 'error';
        header("Location: adminlogin.php?error=login_failed");
        exit();
    }
} else {
    // Not a POST request, redirect to login
    header("Location: adminlogin.php");
    exit();
}
?>
