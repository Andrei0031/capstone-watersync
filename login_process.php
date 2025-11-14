<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include 'db.php';

if (isset($_SERVER["REQUEST_METHOD"]) && $_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // Prepare SQL query to fetch the admin based on the username
    $sql = "SELECT * FROM admin WHERE username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $admin = $result->fetch_assoc();

        // Check if the password is correct (plain text comparison)
        if ($admin['password'] === $password) {
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
}
?>
