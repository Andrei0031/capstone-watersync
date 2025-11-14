# Verpex Hosting - Mobile App Connection Guide

## Overview
This guide explains how to connect your mobile water reader app to the WaterSync system hosted on Verpex.

## Prerequisites
- ✅ Verpex hosting account with domain name (e.g., `yourdomain.com`)
- ✅ Database credentials from Verpex
- ✅ Mobile app source code access
- ✅ FTP/File Manager access to Verpex

---

## Step 1: Upload Files to Verpex

### 1.1 Upload All Project Files
1. Connect to Verpex via FTP or use File Manager
2. Upload all files from `C:\xampp\htdocs\CAPSTONE\` to your Verpex hosting
3. **Important**: Maintain the same folder structure
   - Upload to: `public_html/` or `htdocs/` (depends on Verpex structure)
   - Keep the `api/` folder structure intact

### 1.2 Verify File Structure
Your Verpex structure should look like:
```
public_html/
├── api/
│   ├── mobile_meter_reading.php
│   ├── mobile_client_list.php
│   ├── ocr_meter_reading.php
│   ├── auth.php
│   ├── config.php
│   └── ... (other API files)
├── db.php
├── adminlandingpage.php
└── ... (other files)
```

---

## Step 2: Configure Database Connection

### 2.1 Update `db.php`
1. Open `db.php` in Verpex File Manager
2. Update database credentials:

```php
<?php
$servername = "localhost"; // Usually "localhost" for Verpex
$username = "your_verpex_db_user"; // From Verpex database settings
$password = "your_verpex_db_password"; // From Verpex database settings
$dbname = "your_verpex_db_name"; // From Verpex database settings

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
```

### 2.2 Import Database
1. Export your local database (from phpMyAdmin)
2. Import to Verpex database (via phpMyAdmin or Verpex database tool)

---

## Step 3: Update Mobile App Configuration

### 3.1 Update Base URL in Mobile App

**For Flutter Apps:**
Update your API service file (e.g., `lib/services/api_service.dart`):

```dart
class ApiService {
  // OLD (Local Development):
  // static const String baseUrl = 'http://localhost/CAPSTONE/api/';
  // static const String baseUrl = 'http://192.168.1.100/CAPSTONE/api/';
  
  // NEW (Verpex Production):
  static const String baseUrl = 'https://yourdomain.com/api/';
  // OR if using subdomain:
  // static const String baseUrl = 'https://api.yourdomain.com/';
  
  static const String authToken = 'Bearer watersync_mobile_2024_new_malitbog';
  
  static Map<String, String> get headers => {
    'Content-Type': 'application/json',
    'Authorization': authToken,
  };
}
```

**For React Native Apps:**
Update your API configuration:

```javascript
// config/api.js
const API_CONFIG = {
  // OLD (Local Development):
  // BASE_URL: 'http://localhost/CAPSTONE/api/',
  
  // NEW (Verpex Production):
  BASE_URL: 'https://yourdomain.com/api/',
  
  AUTH_TOKEN: 'Bearer watersync_mobile_2024_new_malitbog',
};

export default API_CONFIG;
```

### 3.2 Update API Endpoints

The mobile app should use these endpoints:

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/auth.php` | POST | User authentication |
| `/api/mobile_client_list.php` | GET | Get list of clients |
| `/api/mobile_meter_reading.php` | POST | Submit meter reading |
| `/api/ocr_meter_reading.php` | POST | Process OCR from image |

**Full URLs will be:**
- `https://yourdomain.com/api/auth.php`
- `https://yourdomain.com/api/mobile_client_list.php`
- `https://yourdomain.com/api/mobile_meter_reading.php`
- `https://yourdomain.com/api/ocr_meter_reading.php`

---

## Step 4: Configure SSL/HTTPS

### 4.1 Enable HTTPS on Verpex
1. Log in to Verpex control panel
2. Enable SSL certificate (Let's Encrypt is free)
3. Force HTTPS redirect (optional but recommended)

### 4.2 Update Mobile App for HTTPS
- Ensure your mobile app uses `https://` instead of `http://`
- Update base URL to use HTTPS

---

## Step 5: Test API Connection

### 5.1 Test Authentication Endpoint
Use Postman or curl to test:

```bash
curl -X POST https://yourdomain.com/api/auth.php \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer watersync_mobile_2024_new_malitbog" \
  -d '{
    "email": "test@example.com",
    "password": "password123",
    "user_type": "customer"
  }'
```

### 5.2 Test Client List Endpoint
```bash
curl -X GET https://yourdomain.com/api/mobile_client_list.php \
  -H "Authorization: Bearer watersync_mobile_2024_new_malitbog"
```

### 5.3 Test Meter Reading Submission
```bash
curl -X POST https://yourdomain.com/api/mobile_meter_reading.php \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer watersync_mobile_2024_new_malitbog" \
  -d '{
    "client_id": 1,
    "meter_reading": 1234.56,
    "image_data": "base64_encoded_image_here",
    "device_info": "Android 12",
    "app_version": "1.0.0"
  }'
```

---

## Step 6: Mobile App Updates Checklist

### 6.1 Configuration Changes
- [ ] Update base URL to Verpex domain
- [ ] Change `http://` to `https://`
- [ ] Remove localhost/local IP addresses
- [ ] Update API endpoint URLs

### 6.2 Code Changes Needed
1. **API Service File**: Update base URL constant
2. **Network Configuration**: Ensure HTTPS is enabled
3. **Error Handling**: Update error messages for production URLs
4. **Logging**: Update log messages to reflect production environment

### 6.3 Testing Checklist
- [ ] Test login functionality
- [ ] Test fetching client list
- [ ] Test submitting meter reading
- [ ] Test OCR image upload
- [ ] Test error handling (no internet, server errors)
- [ ] Test on both WiFi and mobile data

---

## Step 7: Verpex-Specific Configuration

### 7.1 File Permissions
Ensure these folders have write permissions:
- `uploads/` (if exists)
- `qr_codes/` (if exists)
- Any folder that stores uploaded images

### 7.2 PHP Configuration
Check Verpex PHP settings:
- PHP version: 7.4 or higher recommended
- Extensions needed:
  - `mysqli` (for database)
  - `gd` (for image processing)
  - `curl` (for Roboflow API calls)
  - `json` (for API responses)

### 7.3 .htaccess Configuration
Create/update `.htaccess` in root directory:

```apache
# Enable CORS for mobile app
<IfModule mod_headers.c>
    Header set Access-Control-Allow-Origin "*"
    Header set Access-Control-Allow-Methods "GET, POST, PUT, DELETE, OPTIONS"
    Header set Access-Control-Allow-Headers "Content-Type, Authorization, X-API-Key"
</IfModule>

# Force HTTPS (optional)
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# Increase upload size (if needed)
php_value upload_max_filesize 10M
php_value post_max_size 10M
```

---

## Step 8: Security Considerations

### 8.1 API Authentication
- ✅ Current token: `watersync_mobile_2024_new_malitbog`
- ⚠️ Consider implementing user-specific tokens for better security
- ⚠️ Consider rate limiting for API endpoints

### 8.2 CORS Configuration
- Current: Allows all origins (`*`)
- ⚠️ For production, consider restricting to your mobile app's domain
- Update in `api/config.php` if needed

### 8.3 SSL Certificate
- ✅ Ensure SSL certificate is valid
- ✅ Use HTTPS for all API calls
- ✅ Mobile app should validate SSL certificates

---

## Step 9: Troubleshooting

### Common Issues

#### Issue 1: CORS Errors
**Symptoms**: Mobile app can't connect, CORS errors in console

**Solution**:
1. Check `.htaccess` has CORS headers
2. Verify `api/config.php` has CORS headers
3. Check Verpex firewall settings

#### Issue 2: 404 Not Found
**Symptoms**: API endpoints return 404

**Solution**:
1. Verify file structure on Verpex
2. Check if `api/` folder exists
3. Verify file permissions
4. Check if `.htaccess` is blocking requests

#### Issue 3: Database Connection Failed
**Symptoms**: API returns database errors

**Solution**:
1. Verify database credentials in `db.php`
2. Check if database exists on Verpex
3. Verify database user has proper permissions
4. Check if database server is `localhost` (usually correct for Verpex)

#### Issue 4: Image Upload Fails
**Symptoms**: Meter reading images not uploading

**Solution**:
1. Check folder permissions (uploads folder)
2. Verify PHP `upload_max_filesize` setting
3. Check disk space on Verpex
4. Verify image processing functions work

#### Issue 5: OCR Not Working
**Symptoms**: OCR processing fails

**Solution**:
1. Verify Roboflow API key is correct
2. Check if Roboflow API calls are allowed (firewall)
3. Verify image format is supported
4. Check error logs on Verpex

---

## Step 10: Final Checklist

### Server-Side (Verpex)
- [ ] All files uploaded to Verpex
- [ ] Database imported and connected
- [ ] SSL certificate enabled
- [ ] File permissions set correctly
- [ ] `.htaccess` configured
- [ ] PHP extensions enabled
- [ ] API endpoints tested

### Mobile App
- [ ] Base URL updated to Verpex domain
- [ ] HTTPS enabled
- [ ] API endpoints updated
- [ ] Authentication token configured
- [ ] Error handling updated
- [ ] App tested on production API

### Testing
- [ ] Login works
- [ ] Client list loads
- [ ] Meter reading submission works
- [ ] OCR processing works
- [ ] Image upload works
- [ ] Error handling works

---

## Quick Reference

### API Base URL Format
```
Production: https://yourdomain.com/api/
Development: http://localhost/CAPSTONE/api/
```

### Authentication Header
```
Authorization: Bearer watersync_mobile_2024_new_malitbog
```

### Main API Endpoints
- **Auth**: `POST /api/auth.php`
- **Client List**: `GET /api/mobile_client_list.php`
- **Submit Reading**: `POST /api/mobile_meter_reading.php`
- **OCR Processing**: `POST /api/ocr_meter_reading.php`

---

## Support

If you encounter issues:
1. Check Verpex error logs
2. Check PHP error logs
3. Test API endpoints with Postman/curl
4. Verify database connection
5. Check file permissions

---

## Next Steps After Deployment

1. **Monitor**: Check API logs regularly
2. **Update**: Keep mobile app updated with production URL
3. **Backup**: Regular database backups
4. **Security**: Consider implementing API rate limiting
5. **Performance**: Monitor API response times

---

**Last Updated**: 2024
**Version**: 1.0

