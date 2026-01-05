<?php
session_start();
include 'db.php';

// Check if user is logged in as admin
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['save_sms_settings'])) {
        $sms_enabled = isset($_POST['sms_enabled']) ? 1 : 0;
        $sms_provider = $_POST['sms_provider'];
        $sms_api_key = $_POST['sms_api_key'];
        $sms_sender_name = $_POST['sms_sender_name'];
        $sms_test_mode = isset($_POST['sms_test_mode']) ? 1 : 0;
        
        // Update SMS settings in database
        updateSetting('sms_enabled', $sms_enabled);
        updateSetting('sms_provider', $sms_provider);
        updateSetting('sms_api_key', $sms_api_key);
        updateSetting('sms_sender_name', $sms_sender_name);
        updateSetting('sms_test_mode', $sms_test_mode);
        
        $success_message = "SMS settings updated successfully!";
    }
    
    if (isset($_POST['save_email_settings'])) {
        $email_enabled = isset($_POST['email_enabled']) ? 1 : 0;
        $email_provider = $_POST['email_provider'];
        $smtp_host = $_POST['smtp_host'];
        $smtp_port = $_POST['smtp_port'];
        $smtp_username = $_POST['smtp_username'];
        $smtp_password = $_POST['smtp_password'];
        $from_email = $_POST['from_email'];
        $from_name = $_POST['from_name'];
        $email_test_mode = isset($_POST['email_test_mode']) ? 1 : 0;
        
        // Update Email settings in database
        updateSetting('email_enabled', $email_enabled);
        updateSetting('email_provider', $email_provider);
        updateSetting('smtp_host', $smtp_host);
        updateSetting('smtp_port', $smtp_port);
        updateSetting('smtp_username', $smtp_username);
        updateSetting('smtp_password', $smtp_password);
        updateSetting('from_email', $from_email);
        updateSetting('from_name', $from_name);
        updateSetting('email_test_mode', $email_test_mode);
        
        $success_message = "Email settings updated successfully!";
    }
    
    if (isset($_POST['test_notification'])) {
        $test_email = $_POST['test_email'];
        $test_phone = $_POST['test_phone'];
        $test_name = $_POST['test_name'];
        $test_sms = isset($_POST['test_sms']);
        $test_email_check = isset($_POST['test_email_check']);
        
        // Test notification
        include 'notification_manager.php';
        $notification_manager = new NotificationManager($conn);
        
        $results = [];
        $sent_count = 0;
        
        // Send SMS test (if enabled and phone provided)
        if ($test_sms && !empty($test_phone)) {
            $sms_message = "TEST: Hi $test_name! This is a test SMS from WaterSync. Your SMS system is working properly!";
            $sms_result = $notification_manager->sendSMS($test_phone, $sms_message);
            $results['sms'] = $sms_result;
            $sent_count++;
        }
        
        // Send Email test (if enabled and email provided)
        if ($test_email_check && !empty($test_email)) {
            $email_subject = "Test Email from WaterSync";
            $email_message = "Dear $test_name,\n\nThis is a test email to verify your WaterSync email system is working properly.\n\nIf you received this message, your email notifications are set up correctly!\n\nBest regards,\nWaterSync Team";
            $email_result = $notification_manager->sendEmail($test_email, $email_subject, $email_message);
            $results['email'] = $email_result;
            $sent_count++;
        }
        
        if ($sent_count > 0) {
            $test_message = "Test notifications sent! Check your phone and email for messages.";
        } else {
            $test_message = "Please provide phone number for SMS test or email address for email test.";
        }
    }
    
    // Handle quick test buttons
    if (isset($_POST['test_sms_only'])) {
        $test_email = $_POST['test_email'];
        $test_phone = $_POST['test_phone'];
        $test_name = $_POST['test_name'];
        
        include 'notification_manager.php';
        $notification_manager = new NotificationManager($conn);
        
        if (!empty($test_phone)) {
            $sms_message = "TEST: Hi $test_name! This is a test SMS from WaterSync. Your SMS system is working properly!";
            $sms_result = $notification_manager->sendSMS($test_phone, $sms_message);
            $test_message = "SMS test sent! Check your phone for the message.";
        } else {
            $test_message = "Please provide a phone number for SMS test.";
        }
    }
    
    if (isset($_POST['test_email_only'])) {
        $test_email = $_POST['test_email'];
        $test_phone = $_POST['test_phone'];
        $test_name = $_POST['test_name'];
        
        include 'notification_manager.php';
        $notification_manager = new NotificationManager($conn);
        
        if (!empty($test_email)) {
            $email_subject = "Test Email from WaterSync";
            $email_message = "Dear $test_name,\n\nThis is a test email to verify your WaterSync email system is working properly.\n\nIf you received this message, your email notifications are set up correctly!\n\nBest regards,\nWaterSync Team";
            $email_result = $notification_manager->sendEmail($test_email, $email_subject, $email_message);
            
            // Debug information
            if (isset($email_result['status'])) {
                if ($email_result['status'] === 'sent') {
                    $test_message = "✅ Email test sent successfully! Check your email inbox for the message.";
                } elseif ($email_result['status'] === 'test_mode') {
                    $test_message = "📝 Email test logged in test mode. Check notification logs.";
                } elseif ($email_result['status'] === 'disabled') {
                    $test_message = "❌ Email notifications are disabled. Enable them in email settings.";
                } elseif ($email_result['status'] === 'xampp_limitation') {
                    $test_message = "⚠️ XAMPP Limitation: " . ($email_result['message'] ?? 'Email cannot be sent from XAMPP without additional configuration.');
                } elseif ($email_result['status'] === 'development_limitation') {
                    $test_message = "🔧 Development Mode: " . ($email_result['message'] ?? 'Email will work when deployed to web hosting.');
                } else {
                    $test_message = "❌ Email test failed: " . ($email_result['error'] ?? 'Unknown error');
                }
            } else {
                $test_message = "❌ Email test failed: No response from email system.";
            }
        } else {
            $test_message = "❌ Please provide an email address for email test.";
        }
    }
}

// Function to update settings
function updateSetting($key, $value) {
    global $conn;
    
    $stmt = $conn->prepare("
        INSERT INTO notification_settings (setting_key, setting_value) 
        VALUES (?, ?) 
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->bind_param("ss", $key, $value);
    $stmt->execute();
}

// Function to get setting value
function getSetting($key, $default = '') {
    global $conn;
    
    $stmt = $conn->prepare("SELECT setting_value FROM notification_settings WHERE setting_key = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return $result->fetch_assoc()['setting_value'];
    }
    return $default;
}

// Get current settings
$sms_enabled = getSetting('sms_enabled', '1');
$sms_provider = getSetting('sms_provider', 'semaphore');
$sms_api_key = getSetting('sms_api_key', '');
$sms_sender_name = getSetting('sms_sender_name', 'WaterSync');
$sms_test_mode = getSetting('sms_test_mode', '0');

$email_enabled = getSetting('email_enabled', '1');
$email_provider = getSetting('email_provider', 'smtp');
$smtp_host = getSetting('smtp_host', 'smtp.gmail.com');
$smtp_port = getSetting('smtp_port', '587');
$smtp_username = getSetting('smtp_username', '');
$smtp_password = getSetting('smtp_password', '');
$from_email = getSetting('from_email', '');
$from_name = getSetting('from_name', 'WaterSync');
$email_test_mode = getSetting('email_test_mode', '0');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification Settings - WaterSync</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="container-fluid">
        <!-- Main content -->
        <div class="px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-cog me-2"></i>Notification Settings
                    </h1>
                </div>

                <!-- Alert Messages -->
                <?php if (isset($success_message)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($test_message)): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <i class="fas fa-info-circle me-2"></i><?php echo $test_message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- SMS Settings -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-sms me-2"></i>SMS Settings
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="sms_enabled" name="sms_enabled" <?php echo $sms_enabled ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="sms_enabled">
                                                Enable SMS Notifications
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="sms_provider" class="form-label">SMS Provider</label>
                                        <select class="form-select" id="sms_provider" name="sms_provider" onchange="updateSMSFields()">
                                            <option value="semaphore" <?php echo $sms_provider == 'semaphore' ? 'selected' : ''; ?>>Semaphore (Philippines)</option>
                                            <option value="twilio" <?php echo $sms_provider == 'twilio' ? 'selected' : ''; ?>>Twilio (Global)</option>
                                            <option value="nexmo" <?php echo $sms_provider == 'nexmo' ? 'selected' : ''; ?>>Nexmo/Vonage (Global)</option>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="sms_api_key" class="form-label">API Key</label>
                                        <input type="password" class="form-control" id="sms_api_key" name="sms_api_key" 
                                               value="<?php echo htmlspecialchars($sms_api_key); ?>" placeholder="Enter your API key">
                                        <small class="form-text text-muted">Get this from your SMS provider's dashboard</small>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="sms_sender_name" class="form-label">Sender Name</label>
                                        <input type="text" class="form-control" id="sms_sender_name" name="sms_sender_name" 
                                               value="<?php echo htmlspecialchars($sms_sender_name); ?>" placeholder="WaterSync">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="sms_test_mode" name="sms_test_mode" <?php echo $sms_test_mode ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="sms_test_mode">
                                                Test Mode (Log only, don't send real SMS)
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <button type="submit" name="save_sms_settings" class="btn btn-primary">
                                            <i class="fas fa-save me-2"></i>Save SMS Settings
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Email Settings -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-envelope me-2"></i>Email Settings
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="email_enabled" name="email_enabled" <?php echo $email_enabled ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="email_enabled">
                                                Enable Email Notifications
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="email_provider" class="form-label">Email Provider</label>
                                        <select class="form-select" id="email_provider" name="email_provider" onchange="updateEmailFields()">
                                            <option value="smtp" <?php echo $email_provider == 'smtp' ? 'selected' : ''; ?>>SMTP (Gmail, Outlook, etc.)</option>
                                            <option value="sendgrid" <?php echo $email_provider == 'sendgrid' ? 'selected' : ''; ?>>SendGrid</option>
                                            <option value="mailgun" <?php echo $email_provider == 'mailgun' ? 'selected' : ''; ?>>Mailgun</option>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="smtp_host" class="form-label">SMTP Host</label>
                                        <input type="text" class="form-control" id="smtp_host" name="smtp_host" 
                                               value="<?php echo htmlspecialchars($smtp_host); ?>" placeholder="smtp.gmail.com">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="smtp_port" class="form-label">SMTP Port</label>
                                        <input type="number" class="form-control" id="smtp_port" name="smtp_port" 
                                               value="<?php echo htmlspecialchars($smtp_port); ?>" placeholder="587">
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="smtp_username" class="form-label">Email Address</label>
                                        <input type="email" class="form-control" id="smtp_username" name="smtp_username" 
                                               value="<?php echo htmlspecialchars($smtp_username); ?>" placeholder="your-email@gmail.com">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="smtp_password" class="form-label">Password/App Password</label>
                                        <input type="password" class="form-control" id="smtp_password" name="smtp_password" 
                                               value="<?php echo htmlspecialchars($smtp_password); ?>" placeholder="Your email password or app password">
                                        <small class="form-text text-muted">For Gmail, use App Password (not regular password)</small>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="from_email" class="form-label">From Email</label>
                                        <input type="email" class="form-control" id="from_email" name="from_email" 
                                               value="<?php echo htmlspecialchars($from_email); ?>" placeholder="noreply@yourcompany.com">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="from_name" class="form-label">From Name</label>
                                        <input type="text" class="form-control" id="from_name" name="from_name" 
                                               value="<?php echo htmlspecialchars($from_name); ?>" placeholder="WaterSync">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="email_test_mode" name="email_test_mode" <?php echo $email_test_mode ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="email_test_mode">
                                                Test Mode (Log only, don't send real emails)
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <button type="submit" name="save_email_settings" class="btn btn-primary">
                                            <i class="fas fa-save me-2"></i>Save Email Settings
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Test Notifications -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-vial me-2"></i>Test Notifications
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label for="test_name" class="form-label">Test Name</label>
                                        <input type="text" class="form-control" id="test_name" name="test_name" 
                                               placeholder="John Doe" required>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label for="test_email" class="form-label">Test Email</label>
                                        <input type="email" class="form-control" id="test_email" name="test_email" 
                                               placeholder="test@example.com">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label for="test_phone" class="form-label">Test Phone</label>
                                        <input type="tel" class="form-control" id="test_phone" name="test_phone" 
                                               placeholder="+639123456789">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Notification Types</label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="test_sms" name="test_sms" checked>
                                            <label class="form-check-label" for="test_sms">
                                                <i class="fas fa-sms me-1"></i>SMS
                                            </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="test_email_check" name="test_email_check" checked>
                                            <label class="form-check-label" for="test_email_check">
                                                <i class="fas fa-envelope me-1"></i>Email
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-12">
                                    <button type="submit" name="test_notification" class="btn btn-success">
                                        <i class="fas fa-paper-plane me-2"></i>Send Test Notifications
                                    </button>
                                    
                                    <button type="submit" name="test_sms_only" class="btn btn-info ms-2">
                                        <i class="fas fa-sms me-2"></i>Test SMS Only
                                    </button>
                                    
                                    <button type="submit" name="test_email_only" class="btn btn-warning ms-2">
                                        <i class="fas fa-envelope me-2"></i>Test Email Only
                                    </button>
                                </div>
                            </div>
                        </form>
                        
                        <div class="alert alert-info mt-3">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Note:</strong> Use the checkboxes to select which notification types to test. You can test SMS only, Email only, or both.
                        </div>
                        
                        <?php if (strpos($_SERVER['DOCUMENT_ROOT'], 'xampp') !== false): ?>
                        <div class="alert alert-warning mt-3">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>XAMPP Limitation:</strong> XAMPP doesn't include a mail server by default. 
                            <a href="#" onclick="showXAMPPGuidance()" class="alert-link">Click here for solutions</a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Notification System -->
    <script src="assets/js/notifications.js"></script>
    <script>
        function updateSMSFields() {
            const provider = document.getElementById('sms_provider').value;
            const apiKeyField = document.getElementById('sms_api_key');
            
            switch(provider) {
                case 'semaphore':
                    apiKeyField.placeholder = 'Enter your Semaphore API key';
                    break;
                case 'twilio':
                    apiKeyField.placeholder = 'Enter your Twilio Account SID';
                    break;
                case 'nexmo':
                    apiKeyField.placeholder = 'Enter your Nexmo API key';
                    break;
            }
        }
        
        function updateEmailFields() {
            const provider = document.getElementById('email_provider').value;
            const hostField = document.getElementById('smtp_host');
            const portField = document.getElementById('smtp_port');
            
            switch(provider) {
                case 'smtp':
                    hostField.placeholder = 'smtp.gmail.com';
                    portField.value = '587';
                    break;
                case 'sendgrid':
                    hostField.placeholder = 'smtp.sendgrid.net';
                    portField.value = '587';
                    break;
                case 'mailgun':
                    hostField.placeholder = 'smtp.mailgun.org';
                    portField.value = '587';
                    break;
            }
        }
        
        // Initialize fields on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateSMSFields();
            updateEmailFields();
        });
        
        // XAMPP guidance function
        function showXAMPPGuidance() {
            const message = 'Email Solutions for WaterSync:\n\n' +
                  '🚀 HOSTING DEPLOYMENT (Recommended):\n' +
                  '   - Upload to web hosting (Hostinger, etc.)\n' +
                  '   - Configure hosting SMTP settings\n' +
                  '   - Full email functionality\n\n' +
                  '📧 EMAIL PROVIDER OPTIONS:\n' +
                  '   - Gmail SMTP (with app password)\n' +
                  '   - Hosting provider email\n' +
                  '   - SendGrid/Mailgun (professional)\n\n' +
                  '📱 CURRENT WORKAROUND:\n' +
                  '   - Use SMS notifications for now\n' +
                  '   - Deploy to hosting for email\n' +
                  '   - Check HOSTING_DEPLOYMENT_GUIDE.md\n\n' +
                  'Your system will work perfectly when hosted!';
            showInfo(message.replace(/\n/g, '<br>'));
        }
    </script>
</body>
</html>
