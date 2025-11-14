<?php
session_start();
include 'db.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $meter_code = trim($_POST['meter_code']);
    
    // Validate input
    if (empty($email) || empty($password) || empty($confirm_password) || empty($meter_code)) {
        $error = 'All fields are required';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match';
    } else {
        // Check if meter code exists and matches a customer
        $stmt = $conn->prepare("SELECT id, firstname, lastname FROM client_list WHERE meter_code = ? AND delete_flag = 0 AND status = 1");
        $stmt->bind_param("s", $meter_code);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $error = 'Invalid meter code or customer not found';
        } else {
            $client = $result->fetch_assoc();
            
            // Check if email is already registered
            $stmt = $conn->prepare("SELECT id FROM customer_accounts WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            
            if ($stmt->get_result()->num_rows > 0) {
                $error = 'Email is already registered';
            } else {
                // Create account
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO customer_accounts (client_id, email, password) VALUES (?, ?, ?)");
                $stmt->bind_param("iss", $client['id'], $email, $hashed_password);
                
                if ($stmt->execute()) {
                    $success = 'Account created successfully! You can now login.';
                } else {
                    $error = 'Error creating account';
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Registration - Water Billing System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2196F3;
            --secondary-color: #0D47A1;
            --accent-color: #E3F2FD;
        }
        
        body {
            background: linear-gradient(135deg, var(--accent-color) 0%, #ffffff 100%);
            height: 100vh;
            margin: 0;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Open Sans', sans-serif;
            overflow: hidden;
        }
        
        .container {
            position: relative;
            width: 100%;
            max-width: 100%;
            margin: 0;
            padding: 0;
        }
        
        .register-container {
            max-width: 400px;
            margin: 0 auto;
            padding: 15px;
            position: relative;
            z-index: 1;
        }
        
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .card-header {
            background: linear-gradient(45deg, var(--secondary-color), var(--primary-color));
            padding: 20px;
            text-align: center;
            border: none;
        }
        
        .logo-container {
            text-align: center;
            margin-bottom: 0;
        }
        
        .logo-container img {
            max-width: 140px;
            height: auto;
            margin: 0 auto;
            filter: brightness(0) invert(1);
        }
        
        .card-body {
            padding: 20px;
            background: white;
        }
        
        .form-label {
            font-weight: 500;
            color: #333;
            font-size: 0.9rem;
            margin-bottom: 0.3rem;
        }
        
        .form-control {
            border-radius: 6px;
            padding: 8px 12px;
            border: 2px solid #e0e0e0;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(33, 150, 243, 0.25);
        }
        
        .input-group {
            position: relative;
        }
        
        .input-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
            z-index: 10;
            font-size: 0.9rem;
        }
        
        .icon-input {
            padding-left: 35px;
        }
        
        .btn-primary {
            background: linear-gradient(45deg, var(--secondary-color), var(--primary-color));
            border: none;
            padding: 8px 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-radius: 6px;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(33, 150, 243, 0.4);
        }
        
        .alert {
            border-radius: 6px;
            border: none;
            padding: 8px 12px;
            font-size: 0.9rem;
            margin-bottom: 15px;
        }
        
        .login-link {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }
        
        .login-link:hover {
            color: var(--secondary-color);
            text-decoration: underline;
        }
        
        .water-drop {
            position: fixed;
            width: 250px;
            height: 250px;
            background: rgba(33, 150, 243, 0.1);
            border-radius: 50%;
            z-index: 0;
        }
        
        .water-drop-1 {
            top: -125px;
            right: -125px;
        }
        
        .water-drop-2 {
            bottom: -125px;
            left: -125px;
        }

        .mb-4 {
            margin-bottom: 1rem !important;
        }

        small.text-muted {
            font-size: 0.8rem;
        }
    </style>
</head>
<body>
    <div class="water-drop water-drop-1"></div>
    <div class="water-drop water-drop-2"></div>
    
    <div class="container">
        <div class="register-container">
            <div class="card">
                <div class="card-header">
                    <div class="logo-container">
                        <img src="icons/Logo.png" alt="Water Billing System Logo" class="img-fluid">
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" action="">
                        <div class="mb-3">
                            <label class="form-label">Meter Code</label>
                            <div class="input-group">
                                <span class="input-icon">
                                    <i class="fas fa-tachometer-alt"></i>
                                </span>
                                <input type="text" class="form-control icon-input" name="meter_code" required 
                                       placeholder="Enter your meter code">
                            </div>
                            <small class="text-muted">Enter the meter code provided by the water utility</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email Address</label>
                            <div class="input-group">
                                <span class="input-icon">
                                    <i class="fas fa-envelope"></i>
                                </span>
                                <input type="email" class="form-control icon-input" name="email" required 
                                       placeholder="Enter your email">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <div class="input-group">
                                <span class="input-icon">
                                    <i class="fas fa-lock"></i>
                                </span>
                                <input type="password" class="form-control icon-input" name="password" required 
                                       placeholder="Enter your password">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm Password</label>
                            <div class="input-group">
                                <span class="input-icon">
                                    <i class="fas fa-lock"></i>
                                </span>
                                <input type="password" class="form-control icon-input" name="confirm_password" required 
                                       placeholder="Confirm your password">
                            </div>
                        </div>
                        <div class="d-grid gap-2 mb-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-user-plus me-2"></i>Register
                            </button>
                        </div>
                        <div class="text-center">
                            <small class="text-muted">
                                Already have an account? 
                                <a href="customer_login.php" class="login-link">Login here</a>
                            </small>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 