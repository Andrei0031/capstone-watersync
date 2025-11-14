<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Only POST method allowed', null, 405);
}

$input = getInputData();
validateRequiredFields($input, ['password']);

$user_type = $input['user_type'] ?? 'customer';

try {
    switch ($user_type) {
        case 'admin':
            if (empty($input['username'])) {
                sendResponse(false, 'Username is required for admin login', null, 400);
            }

            $stmt = $conn->prepare("SELECT id, username, password FROM admin WHERE username = ?");
            $stmt->bind_param("s", $input['username']);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                sendResponse(false, 'Invalid credentials', null, 401);
            }

            $admin = $result->fetch_assoc();

            // Check password - support both plain text and hashed passwords
            $passwordMatch = false;
            
            // First try plain text comparison (for legacy accounts)
            if ($admin['password'] === $input['password']) {
                $passwordMatch = true;
            }
            // Then try password_verify for hashed passwords (if password_hash was used)
            else if (password_verify($input['password'], $admin['password'])) {
                $passwordMatch = true;
            }
            // Also check if password is MD5 hashed (legacy systems)
            else if (md5($input['password']) === $admin['password']) {
                $passwordMatch = true;
            }
            
            if (!$passwordMatch) {
                sendResponse(false, 'Invalid credentials', null, 401);
            }

            sendResponse(true, 'Login successful', [
                'user_id' => $admin['id'],
                'username' => $admin['username'],
                'user_type' => 'admin',
                'token' => 'Bearer watersync_mobile_2024_new_malitbog'
            ]);
            break;

        case 'meter_reader':
        case 'mobile':
        case 'mobile_reader':
            if (empty($input['username'])) {
                sendResponse(false, 'Username is required for mobile login', null, 400);
            }

            if (!apiTableExists('mobile_users')) {
                sendResponse(false, 'Mobile accounts not initialized', null, 401);
            }

            $stmt = $conn->prepare("SELECT id, username, full_name, password_hash, api_token, status FROM mobile_users WHERE username = ? LIMIT 1");
            $stmt->bind_param("s", $input['username']);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                sendResponse(false, 'Invalid credentials', null, 401);
            }

            $mobile_user = $result->fetch_assoc();

            if ($mobile_user['status'] !== 'active') {
                sendResponse(false, 'Mobile account is inactive', null, 403);
            }

            if (!password_verify($input['password'], $mobile_user['password_hash'])) {
                sendResponse(false, 'Invalid credentials', null, 401);
            }

            // Update last login timestamp
            $update_stmt = $conn->prepare("UPDATE mobile_users SET last_login_at = NOW() WHERE id = ?");
            $update_stmt->bind_param("i", $mobile_user['id']);
            $update_stmt->execute();

            sendResponse(true, 'Login successful', [
                'user_id' => $mobile_user['id'],
                'username' => $mobile_user['username'],
                'name' => $mobile_user['full_name'],
                'user_type' => 'mobile_reader',
                'token' => 'Bearer ' . $mobile_user['api_token']
            ]);
            break;

        default:
            if (empty($input['email'])) {
                sendResponse(false, 'Email is required for customer login', null, 400);
            }

            $stmt = $conn->prepare("SELECT id, firstname, lastname, email, password, status FROM customer_accounts WHERE email = ?");
            $stmt->bind_param("s", $input['email']);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                sendResponse(false, 'Invalid credentials', null, 401);
            }

            $customer = $result->fetch_assoc();

            if ($customer['status'] !== 'active') {
                sendResponse(false, 'Account is inactive', null, 401);
            }

            if (!password_verify($input['password'], $customer['password'])) {
                sendResponse(false, 'Invalid credentials', null, 401);
            }

            sendResponse(true, 'Login successful', [
                'user_id' => $customer['id'],
                'name' => $customer['firstname'] . ' ' . $customer['lastname'],
                'email' => $customer['email'],
                'user_type' => 'customer',
                'token' => 'Bearer watersync_mobile_2024_new_malitbog'
            ]);
            break;
    }

} catch (Exception $e) {
    sendResponse(false, 'Authentication failed: ' . $e->getMessage(), null, 500);
}
?> 