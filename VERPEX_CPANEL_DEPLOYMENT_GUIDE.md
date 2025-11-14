# Verpex cPanel Deployment Guide - Step by Step

## 📋 Pre-Deployment Checklist

Before starting, make sure you have:
- [ ] Verpex hosting account credentials
- [ ] cPanel access (username and password)
- [ ] Database credentials from Verpex
- [ ] Domain name configured
- [ ] Database exported from local XAMPP

---

## Step 1: Access Verpex cPanel

1. Go to your Verpex hosting login page
2. Log in with your credentials
3. Navigate to **cPanel** (usually accessible from main dashboard)

---

## Step 2: Create Database

### 2.1 Create MySQL Database
1. In cPanel, find **"MySQL Databases"** or **"Databases"** section
2. Click **"Create Database"**
3. Enter database name (e.g., `watersync_db`)
4. Click **"Create Database"**
5. **Note down the database name** (it might be prefixed with your username, e.g., `username_watersync_db`)

### 2.2 Create Database User
1. Scroll down to **"Add New User"** section
2. Enter username (e.g., `watersync_user`)
3. Enter strong password (use password generator)
4. Click **"Create User"**
5. **Note down the username and password**

### 2.3 Assign User to Database
1. Scroll to **"Add User to Database"** section
2. Select the user you created
3. Select the database you created
4. Click **"Add"**
5. Check **"ALL PRIVILEGES"**
6. Click **"Make Changes"**

---

## Step 3: Upload Files via File Manager

### 3.1 Access File Manager
1. In cPanel, find **"File Manager"**
2. Click to open it
3. Navigate to **`public_html`** folder (this is your web root)

### 3.2 Upload Project Files
**Option A: Upload via File Manager (for small projects)**
1. Click **"Upload"** button in File Manager
2. Select all files from `C:\xampp\htdocs\CAPSTONE\`
3. Upload them to `public_html/`
4. **Note**: This might take time for large projects

**Option B: Upload via FTP (Recommended for large projects)**
1. In cPanel, find **"FTP Accounts"** section
2. Note your FTP credentials (or create new FTP account)
3. Use FTP client (FileZilla, WinSCP, etc.)
4. Connect to Verpex FTP server
5. Upload all files from `C:\xampp\htdocs\CAPSTONE\` to `public_html/`

**Option C: Upload ZIP and Extract**
1. Compress your project folder to ZIP
2. Upload ZIP file via File Manager
3. Right-click ZIP file → **"Extract"**
4. Delete ZIP file after extraction

### 3.3 Verify File Structure
After upload, your `public_html/` should have:
```
public_html/
├── api/
│   ├── mobile_meter_reading.php
│   ├── mobile_client_list.php
│   ├── config.php
│   └── ... (other API files)
├── db.php
├── adminlandingpage.php
├── .htaccess
└── ... (other files)
```

---

## Step 4: Configure Database Connection

### 4.1 Update db.php
1. In File Manager, navigate to `public_html/`
2. Find `db.php` file
3. Right-click → **"Edit"** (or use Code Editor)
4. Update with your Verpex database credentials:

```php
<?php
$host = 'localhost'; // Usually 'localhost' for Verpex
$user = 'your_verpex_db_username'; // From Step 2.2
$pass = 'your_verpex_db_password'; // From Step 2.2
$dbname = 'your_verpex_db_name'; // From Step 2.1 (might be prefixed)

// Rest of the file stays the same...
```

5. **Save** the file

---

## Step 5: Import Database

### 5.1 Access phpMyAdmin
1. In cPanel, find **"phpMyAdmin"**
2. Click to open it
3. Select your database from left sidebar

### 5.2 Import Database
1. Click **"Import"** tab
2. Click **"Choose File"**
3. Select your exported SQL file from local XAMPP
4. Click **"Go"** to import
5. Wait for import to complete
6. Verify tables are created (check left sidebar)

---

## Step 6: Set File Permissions

### 6.1 Set Folder Permissions
In File Manager, set these permissions:

1. **`uploads/`** folder (if exists):
   - Right-click → **"Change Permissions"**
   - Set to **755** or **777** (writable)

2. **`qr_codes/`** folder (if exists):
   - Set to **755** or **777**

3. **`api/`** folder:
   - Set to **755** (readable and executable)

### 6.2 Set File Permissions
- **PHP files**: **644** (readable)
- **`.htaccess`**: **644**

---

## Step 7: Configure .htaccess

### 7.1 Verify .htaccess Exists
1. Check if `.htaccess` file exists in `public_html/`
2. If not, create it (see `.htaccess` file in project root)

### 7.2 Enable HTTPS (After SSL Setup)
1. Edit `.htaccess` file
2. Uncomment these lines (remove `#`):
```apache
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

---

## Step 8: Enable SSL Certificate

### 8.1 Install SSL
1. In cPanel, find **"SSL/TLS"** or **"Let's Encrypt SSL"**
2. Select your domain
3. Click **"Install"** or **"Run AutoSSL"**
4. Wait for SSL to be installed

### 8.2 Force HTTPS
1. After SSL is installed, uncomment HTTPS redirect in `.htaccess` (Step 7.2)

---

## Step 9: Test Your Deployment

### 9.1 Test Web Interface
1. Open browser
2. Go to: `https://yourdomain.com/adminlandingpage.php`
3. Try logging in
4. Check if pages load correctly

### 9.2 Test API Endpoints
Use Postman or browser to test:

**Test Authentication:**
```
POST https://yourdomain.com/api/auth.php
Headers:
  Content-Type: application/json
  Authorization: Bearer watersync_mobile_2024_new_malitbog
Body:
{
  "email": "test@example.com",
  "password": "password",
  "user_type": "customer"
}
```

**Test Client List:**
```
GET https://yourdomain.com/api/mobile_client_list.php
Headers:
  Authorization: Bearer watersync_mobile_2024_new_malitbog
```

---

## Step 10: Update Mobile App Configuration

### 10.1 Update Base URL
In your mobile app, change:
```dart
// OLD:
static const String baseUrl = 'http://localhost/CAPSTONE/api/';

// NEW:
static const String baseUrl = 'https://yourdomain.com/api/';
```

### 10.2 Test Mobile App Connection
1. Update mobile app with new base URL
2. Test login
3. Test fetching client list
4. Test submitting meter reading

---

## Step 11: Verify PHP Configuration

### 11.1 Check PHP Version
1. In cPanel, find **"Select PHP Version"** or **"PHP Version"**
2. Ensure PHP 7.4 or higher is selected
3. Click **"Set as Current"**

### 11.2 Check PHP Extensions
1. In PHP Version settings, check these extensions are enabled:
   - ✅ `mysqli` (for database)
   - ✅ `gd` (for image processing)
   - ✅ `curl` (for Roboflow API)
   - ✅ `json` (for API responses)
   - ✅ `mbstring` (for string functions)

---

## Step 12: Configure Email (Optional)

### 12.1 Email Settings
1. In cPanel, find **"Email Accounts"**
2. Create email account (e.g., `noreply@yourdomain.com`)
3. Use this for system emails

### 12.2 Update Email Configuration
1. Go to: `https://yourdomain.com/notification_settings_admin.php`
2. Configure SMTP settings:
   - **SMTP Host**: `mail.yourdomain.com` or `localhost`
   - **Port**: `587` (TLS) or `465` (SSL)
   - **Username**: Your email address
   - **Password**: Your email password

---

## Troubleshooting

### Issue: 500 Internal Server Error
**Solution:**
1. Check `.htaccess` file (might have syntax errors)
2. Check PHP error logs in cPanel
3. Verify file permissions
4. Check database connection in `db.php`

### Issue: Database Connection Failed
**Solution:**
1. Verify database credentials in `db.php`
2. Check database name includes username prefix
3. Verify user has proper permissions
4. Test connection in phpMyAdmin

### Issue: Files Not Uploading
**Solution:**
1. Check folder permissions (should be 755 or 777)
2. Check PHP `upload_max_filesize` setting
3. Verify disk space on Verpex
4. Check `.htaccess` upload limits

### Issue: API Returns 404
**Solution:**
1. Verify `api/` folder exists in `public_html/`
2. Check file names are correct (case-sensitive)
3. Verify `.htaccess` is not blocking requests
4. Check URL path is correct

### Issue: CORS Errors in Mobile App
**Solution:**
1. Verify `.htaccess` has CORS headers
2. Check `api/config.php` has CORS headers
3. Ensure HTTPS is used (not HTTP)
4. Check Verpex firewall settings

---

## Post-Deployment Checklist

### Server (Verpex)
- [ ] All files uploaded
- [ ] Database imported
- [ ] `db.php` configured
- [ ] File permissions set
- [ ] SSL certificate installed
- [ ] `.htaccess` configured
- [ ] PHP version set correctly
- [ ] PHP extensions enabled

### Testing
- [ ] Web interface loads
- [ ] Admin login works
- [ ] API endpoints respond
- [ ] Database queries work
- [ ] File uploads work
- [ ] Mobile app connects

### Mobile App
- [ ] Base URL updated
- [ ] HTTPS enabled
- [ ] Authentication works
- [ ] API calls successful

---

## Quick Reference

### File Paths on Verpex
- **Web Root**: `/public_html/` or `/home/username/public_html/`
- **Database Config**: `/public_html/db.php`
- **API Folder**: `/public_html/api/`
- **Uploads**: `/public_html/uploads/`

### Database Info Location
- **cPanel** → **MySQL Databases** → See database name and user

### API Base URL
```
https://yourdomain.com/api/
```

### Authentication Token
```
Bearer watersync_mobile_2024_new_malitbog
```

---

## Support Resources

- **Verpex Support**: Check Verpex documentation or support tickets
- **cPanel Docs**: https://docs.cpanel.net/
- **Project Docs**: See `VERPEX_MOBILE_CONNECTION_GUIDE.md`

---

**Remember**: Replace `yourdomain.com` with your actual Verpex domain name throughout!

**Good luck with your deployment! 🚀**

