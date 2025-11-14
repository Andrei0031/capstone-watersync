# 🚀 Real SMS & Email Notifications Setup Guide

## 📋 **Overview**

This guide will help you convert your WaterSync system from dummy mode to real SMS and email notifications for:

- **Billing Notifications** - When bills are approved
- **Payment Reminders** - For overdue bills
- **Water Interruptions** - Service disruption alerts
- **Service Restoration** - When water service is restored

## 🔧 **Step 1: Database Setup**

Run the SQL script to create necessary tables:

```sql
-- Run this in your MySQL database
source create_notification_tables.sql;
```

This creates tables for:
- `water_interruptions` - Track water service issues
- `notification_logs` - Log all sent notifications
- `interruption_notifications` - Track interruption alerts
- `notification_settings` - Store configuration

## 📱 **Step 2: SMS Provider Setup**

### **Option A: Semaphore (Recommended for Philippines)**

1. **Sign up**: Go to [semaphore.co](https://semaphore.co)
2. **Get API Key**: Copy your API key from dashboard
3. **Update config**: Edit `notification_config.php`:

```php
'sms' => [
    'enabled' => true,
    'provider' => 'semaphore',
    'api_key' => 'YOUR_API_KEY_HERE', // Add your API key
    'sender_name' => 'WaterSync',
    'test_mode' => false
],
```

### **Option B: Twilio**

1. **Sign up**: Go to [twilio.com](https://twilio.com)
2. **Get credentials**: Account SID, Auth Token, Phone Number
3. **Update config**:

```php
'sms' => [
    'enabled' => true,
    'provider' => 'twilio',
    'account_sid' => 'YOUR_ACCOUNT_SID',
    'auth_token' => 'YOUR_AUTH_TOKEN',
    'from_number' => '+1234567890', // Your Twilio number
    'test_mode' => false
],
```

## 📧 **Step 3: Email Provider Setup**

### **Option A: Gmail SMTP (Easiest)**

1. **Enable 2FA**: On your Gmail account
2. **Generate App Password**: 
   - Go to Google Account Settings
   - Security → 2-Step Verification → App passwords
   - Generate password for "Mail"
3. **Update config**:

```php
'email' => [
    'enabled' => true,
    'provider' => 'smtp',
    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => 587,
    'smtp_username' => 'your-email@gmail.com',
    'smtp_password' => 'YOUR_APP_PASSWORD', // Not your regular password
    'from_email' => 'noreply@yourcompany.com',
    'from_name' => 'WaterSync',
    'test_mode' => false
],
```

### **Option B: SendGrid**

1. **Sign up**: Go to [sendgrid.com](https://sendgrid.com)
2. **Get API Key**: Create API key in dashboard
3. **Update config**:

```php
'email' => [
    'enabled' => true,
    'provider' => 'sendgrid',
    'sendgrid_api_key' => 'YOUR_API_KEY_HERE',
    'from_email' => 'noreply@yourcompany.com',
    'from_name' => 'WaterSync',
    'test_mode' => false
],
```

## 🏗️ **Step 4: Install Required Libraries**

### **For SMTP Email (if using Gmail SMTP):**

```bash
# Install PHPMailer via Composer
composer require phpmailer/phpmailer
```

Or download PHPMailer manually and place in your project folder.

## 🔄 **Step 5: Update Your System**

### **Replace the dummy notification system:**

1. **Backup current system**:
   ```bash
   cp simple_notifications.php simple_notifications_backup.php
   ```

2. **Update billing_list.php** to use new system:
   ```php
   // Replace the include
   include 'notification_manager.php';
   
   // Replace notification calls
   $notification_manager = new NotificationManager($conn);
   $result = $notification_manager->sendBillingNotification($client_id, $bill_id);
   ```

3. **Add water interruption management**:
   ```php
   // Add to your admin menu
   <a href="water_interruption_admin.php">Water Interruptions</a>
   ```

## 🧪 **Step 6: Testing**

### **Test SMS:**
1. Go to Billing Management
2. Select a customer with phone number
3. Click "Send Test"
4. Check your phone for SMS

### **Test Email:**
1. Go to Billing Management  
2. Select a customer with email
3. Click "Send Test"
4. Check email inbox

### **Test Water Interruptions:**
1. Go to Water Interruption Management
2. Create a test interruption
3. Check affected customers receive notifications

## 📊 **Step 7: Monitor & Logs**

### **View Notification Logs:**
- All notifications are logged in `notification_logs` table
- Check delivery status and responses
- Monitor success/failure rates

### **Database Queries:**
```sql
-- View all notifications
SELECT * FROM notification_logs ORDER BY sent_at DESC;

-- Check SMS delivery
SELECT * FROM notification_logs WHERE type = 'sms' AND status = 'sent';

-- View interruption history
SELECT * FROM water_interruptions ORDER BY created_at DESC;
```

## 🎯 **Step 8: Production Deployment**

### **Before going live:**

1. **Test thoroughly** with real phone numbers and emails
2. **Set up monitoring** for failed deliveries
3. **Configure backup providers** if needed
4. **Set up rate limiting** to avoid spam
5. **Monitor costs** for SMS and email services

### **Security considerations:**

- Store API keys securely (not in code)
- Use environment variables for sensitive data
- Implement rate limiting
- Log all notification attempts
- Set up alerts for failed deliveries

## 📞 **Step 9: Customer Communication**

### **Inform customers about notifications:**

1. **Add phone/email collection** to customer registration
2. **Send welcome message** when customers are added
3. **Provide opt-out options** if needed
4. **Update privacy policy** to include notification usage

## 🔧 **Troubleshooting**

### **Common Issues:**

**SMS not sending:**
- Check API key is correct
- Verify phone number format (+63 for Philippines)
- Check account balance/credits
- Review provider logs

**Email not sending:**
- Check SMTP credentials
- Verify email addresses
- Check spam folders
- Review SMTP logs

**Notifications not triggering:**
- Check if notification system is enabled
- Verify customer has phone/email
- Check notification logs
- Review error logs

## 📈 **Advanced Features**

### **Scheduled Notifications:**
- Set up cron jobs for payment reminders
- Automated overdue notices
- Scheduled maintenance notifications

### **Template Customization:**
- Customize message templates
- Add company branding
- Multi-language support

### **Analytics:**
- Track notification effectiveness
- Monitor delivery rates
- Customer engagement metrics

## 🆘 **Support**

If you need help:

1. **Check logs** in `notification_logs` table
2. **Test with dummy mode** first
3. **Verify API credentials**
4. **Check provider documentation**
5. **Contact support** with specific error messages

## 🎉 **You're Ready!**

Once configured, your WaterSync system will:

✅ **Send real SMS** for billing notifications  
✅ **Send real emails** with detailed bill information  
✅ **Alert customers** about water interruptions  
✅ **Notify service restoration** automatically  
✅ **Track all notifications** in logs  
✅ **Manage interruptions** through admin panel  

**Your water management system is now fully automated!** 🚰📱📧
