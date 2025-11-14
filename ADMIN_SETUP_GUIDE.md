# 🎛️ **Admin Settings Interface Setup Guide**

## 📋 **Overview**

You now have a professional admin interface to configure all your notification settings without editing files manually! This makes it much easier to manage your SMS and email APIs.

## 🚀 **How to Access the Settings**

### **Step 1: Access the Admin Interface**
1. **Login** to your WaterSync admin panel
2. **Navigate** to the sidebar menu
3. **Click** "Notification Settings" 
4. **URL**: `http://localhost/CAPSTONE/notification_settings_admin.php`

## 📱 **SMS Configuration**

### **Step 1: Choose Your SMS Provider**
- **Semaphore** (Recommended for Philippines)
- **Twilio** (Global)
- **Nexmo/Vonage** (Global)

### **Step 2: Enter Your API Details**
1. **Enable SMS**: Check the "Enable SMS Notifications" box
2. **Select Provider**: Choose from dropdown
3. **API Key**: Enter your provider's API key
4. **Sender Name**: Enter "WaterSync" or your company name
5. **Test Mode**: Check this to test without sending real SMS
6. **Save**: Click "Save SMS Settings"

### **Step 3: Get Your API Keys**

#### **For Semaphore (Philippines):**
1. Go to [semaphore.co](https://semaphore.co)
2. Sign up for free account
3. Go to API section
4. Copy your API key
5. Paste in the "API Key" field

#### **For Twilio (Global):**
1. Go to [twilio.com](https://twilio.com)
2. Sign up for free account
3. Get Account SID and Auth Token
4. Buy a phone number
5. Use Account SID as API key

## 📧 **Email Configuration**

### **Step 1: Choose Your Email Provider**
- **SMTP** (Gmail, Outlook, etc.)
- **SendGrid** (Professional)
- **Mailgun** (Advanced)

### **Step 2: Gmail Setup (Easiest)**
1. **Enable Email**: Check the "Enable Email Notifications" box
2. **Select Provider**: Choose "SMTP"
3. **SMTP Host**: `smtp.gmail.com`
4. **SMTP Port**: `587`
5. **Email Address**: Your Gmail address
6. **Password**: Your Gmail App Password (not regular password)
7. **From Email**: Same as your Gmail
8. **From Name**: "WaterSync"
9. **Test Mode**: Check to test without sending real emails
10. **Save**: Click "Save Email Settings"

### **Step 3: Gmail App Password Setup**
1. Go to [myaccount.google.com](https://myaccount.google.com)
2. Security → 2-Step Verification
3. App passwords → Generate
4. Select "Mail" → "Other"
5. Type "WaterSync"
6. Copy the 16-character password
7. Paste in "Password/App Password" field

## 🧪 **Testing Your Setup**

### **Step 1: Use Test Mode First**
1. **Enable Test Mode** for both SMS and Email
2. **Save settings**
3. **Test notifications** - they'll be logged but not sent
4. **Check logs** to verify configuration

### **Step 2: Send Real Test**
1. **Disable Test Mode**
2. **Go to Test Notifications section**
3. **Enter your phone and email**
4. **Click "Send Test Notifications"**
5. **Check your phone and email**

### **Step 3: Verify Everything Works**
- ✅ **SMS received** on your phone
- ✅ **Email received** in your inbox
- ✅ **No error messages** in the interface

## 🎯 **Using the System**

### **Automatic Notifications**
Once configured, your system will automatically send:
- **Bill Approvals**: When you approve bills
- **Payment Reminders**: For overdue accounts
- **Water Interruptions**: When you report service issues
- **Service Restoration**: When water is back

### **Manual Testing**
- Use the **Test Notifications** section anytime
- **Check logs** to see delivery status
- **Enable/disable** notifications as needed

## 🔧 **Troubleshooting**

### **SMS Not Working?**
- Check API key is correct
- Verify phone number format (+63 for Philippines)
- Check account balance/credits
- Try test mode first

### **Email Not Working?**
- Check Gmail app password (not regular password)
- Verify 2FA is enabled
- Check spam folder
- Try test mode first

### **Settings Not Saving?**
- Check database connection
- Verify notification_settings table exists
- Check file permissions

## 📊 **Monitoring**

### **View Notification Logs**
- All notifications are logged in database
- Check delivery status
- Monitor success/failure rates
- Track costs and usage

### **Database Queries**
```sql
-- View all notifications
SELECT * FROM notification_logs ORDER BY sent_at DESC;

-- Check SMS delivery
SELECT * FROM notification_logs WHERE type = 'sms' AND status = 'sent';

-- View settings
SELECT * FROM notification_settings;
```

## 🎉 **You're All Set!**

Your WaterSync system now has:
- ✅ **Professional admin interface** for settings
- ✅ **Real SMS notifications** via your chosen provider
- ✅ **Real email notifications** via Gmail or other providers
- ✅ **Test mode** for safe testing
- ✅ **Automatic notifications** for all billing events
- ✅ **Water interruption alerts** for service issues
- ✅ **Easy configuration** without editing files

## 🆘 **Need Help?**

If you encounter issues:
1. **Check the test mode** first
2. **Verify API credentials** are correct
3. **Check notification logs** for error messages
4. **Try different providers** if one doesn't work
5. **Contact support** with specific error messages

**Your water management system is now fully automated with professional notifications!** 🚰📱📧
