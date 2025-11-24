<?php
// Set timezone to Philippine Time (Asia/Manila, UTC+8)
date_default_timezone_set('Asia/Manila');

// Enable error display for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Check if the user is already logged in
if (isset($_SESSION['admin_id'])) {
    header("Location: adminlandingpage.php");
    exit();
}

$loginStatus = $_SESSION['login_status'] ?? '';
unset($_SESSION['login_status']); // Clear session status after displaying it
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>New Malitbog WaterSync | Admin Log in</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.5.0/css/font-awesome.min.css">

  <style>
    body {
      height: 100vh;
      background-image: url('icons/Background.jpg');
      background-size: cover;
      font-family: Arial, sans-serif;
    }
    .login-box {
      max-width: 400px;
      margin: auto;
      padding-top: 50px;
    }
    .login-logo img {
      width: 70%;
      height: 100px;
    }
    .login-box-body {
      background: rgba(255, 255, 255, 0.9);
      padding: 20px;
      border-radius: 8px;
      box-shadow: 0 0 10px rgba(0,0,0,0.3);
    }
    .form-control:focus {
      box-shadow: none;
      border-color: #86b7fe;
    }
    .btn-flat {
      border-radius: 0.25rem;
    }
    .info-text {
      font-style: italic;
      color: #856404;
    }
  </style>
</head>
<body>
  <div class="login-box">
    <div class="login-logo text-center mb-3">
      <a href="#"><img src="icons/Logo.png" alt="Logo"></a>
    </div>
    <div class="login-box-body">
      <p class="login-box-msg text-center fs-5 mb-3">Sign in to start your session</p>
      <form method="POST" action="login_process.php">
        <div class="mb-3 position-relative">
          <input type="text" class="form-control ps-5" placeholder="Username" id="username" name="username" required autofocus>
          <span class="position-absolute top-50 start-0 translate-middle-y ps-3 text-muted">
            <i class="fa fa-user"></i>
          </span>
        </div>
        <div class="mb-3 position-relative">
          <input type="password" class="form-control ps-5" placeholder="Password" id="password" name="password" required>
          <span class="position-absolute top-50 start-0 translate-middle-y ps-3 text-muted">
            <i class="fa fa-lock"></i>
          </span>
        </div>
        <div class="d-grid">
          <button type="submit" class="btn btn-primary btn-flat">Sign In</button>
        </div>
      </form>
      <div class="row mt-4">
        <div class="col-12 text-end">
          <p class="info-text">@ardgarciano</p>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal -->
  <div class="modal fade" id="loginModal" tabindex="-1" aria-labelledby="loginModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header bg-<?php echo $loginStatus === 'success' ? 'success' : 'danger'; ?> text-white">
          <h5 class="modal-title" id="loginModalLabel">
            <?php echo $loginStatus === 'success' ? 'Login Successful' : 'Login Failed'; ?>
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body text-center">
          <?php
            if ($loginStatus === 'success') {
              echo 'Welcome back! Redirecting to Dashboard...';
            } elseif ($loginStatus === 'error') {
              echo 'Incorrect username or password.';
            }
          ?>
        </div>
        <?php if ($loginStatus === 'success'): ?>
          <div class="modal-footer">
            <a href="adminlandingpage.php" class="btn btn-success">Continue</a>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Bootstrap JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

  <!-- JavaScript to trigger modal -->
  <script>
    document.addEventListener("DOMContentLoaded", function () {
      const loginStatus = "<?php echo $loginStatus; ?>";
      if (loginStatus === "success" || loginStatus === "error") {
        const loginModal = new bootstrap.Modal(document.getElementById('loginModal'));
        loginModal.show();
      }
    });
  </script>
</body>
</html>
