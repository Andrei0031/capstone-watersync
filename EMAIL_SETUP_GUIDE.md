# 📧 **Email Setup Guide - Fix SMTP Issues**

## 🚨 **Current Issue**
The error `Failed to connect to mailserver at "localhost" port 25` means your system is trying to use a local mail server that doesn't exist. Let's fix this!

## 🔧 **Solution Options**

### **Option 1: Use Gmail SMTP (Recommended)**

#### **Step 1: Get Gmail App Password**
1. Go to [myaccount.google.com](https://myaccount.google.com)
2. **Security** → **2-Step Verification** (enable if not already)
3. **App passwords** → **Generate**
4. Select **Mail** → **Other** → Type "WaterSync"
5. **Copy the 16-character password** (like: `abcd efgh ijkl mnop`)

#### **Step 2: Configure in Admin Interface**
1. Go to: `http://localhost/CAPSTONE/notification_settings_admin.php`
2. **Email Settings**:
   - ✅ **Enable Email** notifications
   - **Provider**: SMTP
   - **SMTP Host**: `smtp.gmail.com`
   - **SMTP Port**: `587`
   - **Email Address**: `your-email@gmail.com`
   - **Password**: `your-16-character-app-password`
   - **From Email**: `your-email@gmail.com`
   - **From Name**: `WaterSync`

#### **Step 3: Test**
- Use **Test Mode** first (safe testing)
- Click **"Test Email Only"** button
- Check your email inbox

### **Option 2: Use Your Web Hosting Email**

If you have web hosting, use your hosting provider's SMTP:

#### **Common Hosting SMTP Settings:**
- **Host**: `mail.yourdomain.com`
- **Port**: `587` or `465`
- **Username**: `your-email@yourdomain.com`
- **Password**: Your email password

### **Option 3: Use Professional Email Services**

#### **SendGrid (Free Tier Available)**
1. Sign up at [sendgrid.com](https://sendgrid.com)
2. Get API key from dashboard
3. Configure in admin interface

#### **Mailgun (Free Tier Available)**
1. Sign up at [mailgun.com](https://mailgun.com)
2. Get API key and domain
3. Configure in admin interface

## 🎛️ **Using the Toggle System**

### **SMS/Email Selection**
The admin interface now has toggle options:

#### **Test Notifications Section:**
- ✅ **SMS Checkbox**: Enable/disable SMS testing
- ✅ **Email Checkbox**: Enable/disable email testing
- 🎯 **Quick Buttons**:
  - **"Test SMS Only"** - Tests only SMS
  - **"Test Email Only"** - Tests only email
  - **"Send Test Notifications"** - Tests both (if both checked)

#### **Individual Provider Control:**
- **SMS Settings**: Enable/disable SMS notifications globally
- **Email Settings**: Enable/disable email notifications globally
- **Test Mode**: Safe testing without sending real messages

## 🧪 **Testing Strategy**

### **Step 1: Test Mode First**
1. **Enable Test Mode** for both SMS and Email
2. **Configure** your settings
3. **Test notifications** - they'll be logged but not sent
4. **Check logs** to verify configuration

### **Step 2: Individual Testing**
1. **Test SMS Only**:
   - Disable email notifications
   - Enable SMS notifications
   - Test with your phone number

2. **Test Email Only**:
   - Disable SMS notifications  
   - Enable email notifications
   - Test with your email address

### **Step 3: Full Testing**
1. **Disable Test Mode**
2. **Enable both** SMS and Email
3. **Test both** notification types
4. **Verify** you receive messages

## 🔍 **Troubleshooting**

### **Gmail Issues:**
- **App Password**: Must use 16-character app password, not regular password
- **2FA Required**: Must have 2-factor authentication enabled
- **Account Type**: Personal Gmail works best (not Google Workspace)

### **SMTP Issues:**
- **Port 25 Blocked**: Most hosting providers block port 25
- **Use Port 587**: For TLS/STARTTLS
- **Use Port 465**: For SSL
- **Authentication**: Username/password required

### **Local Development:**
- **XAMPP**: Doesn't have built-in mail server
- **Use Gmail SMTP**: Best option for local development
- **Use Hosting SMTP**: If you have web hosting

## 💡 **Quick Fix for Current Issue**

### **Immediate Solution:**
1. **Go to**: `http://localhost/CAPSTONE/notification_settings_admin.php`
2. **Email Settings**:
   - **Enable Email** notifications
   - **Provider**: SMTP
   - **SMTP Host**: `smtp.gmail.com`
   - **SMTP Port**: `587`
   - **Username**: Your Gmail address
   - **Password**: Your Gmail app password
3. **Test**: Use "Test Email Only" button

### **Alternative Quick Fix:**
1. **Disable Email** notifications temporarily
2. **Use SMS only** for now
3. **Set up email** later when you have proper SMTP

## 🎯 **Recommended Setup**

### **For Development:**
- **SMS**: Use Semaphore (Philippines) or Twilio
- **Email**: Use Gmail SMTP with app password
- **Test Mode**: Enable for safe testing

### **For Production:**
- **SMS**: Use your preferred provider
- **Email**: Use professional service (SendGrid, Mailgun)
- **Monitoring**: Check logs regularly

## 🚀 **Next Steps**

1. **Configure Gmail SMTP** using the steps above
2. **Test email notifications** using the toggle system
3. **Add customer phone/email** information
4. **Test full notification system**
5. **Go live** with real notifications

Your WaterSync system will then have full SMS and email capabilities with proper toggle controls! 📧📱✨
