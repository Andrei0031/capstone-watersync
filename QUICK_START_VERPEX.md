# 🚀 Quick Start - Deploy to Verpex cPanel

## Step-by-Step (15 minutes)

### 1️⃣ Prepare Files
- [ ] Export database from phpMyAdmin (local XAMPP)
- [ ] Compress project folder to ZIP (optional, for faster upload)

### 2️⃣ Create Database in Verpex
1. Login to Verpex cPanel
2. Go to **"MySQL Databases"**
3. Create database → Note the name
4. Create user → Note username & password
5. Add user to database → Give ALL PRIVILEGES

### 3️⃣ Upload Files
**Option A: File Manager**
1. cPanel → **File Manager** → `public_html/`
2. Click **Upload** → Select all files
3. Wait for upload to complete

**Option B: FTP (Faster)**
1. Use FileZilla/WinSCP
2. Connect to Verpex FTP
3. Upload to `public_html/`

**Option C: ZIP Upload**
1. Upload ZIP file
2. Right-click → **Extract**
3. Delete ZIP after extraction

### 4️⃣ Configure Database
1. File Manager → Edit `db.php`
2. Update these 3 lines:
```php
$user = 'your_verpex_db_username';
$pass = 'your_verpex_db_password';
$dbname = 'your_verpex_db_name';
```
3. Save

### 5️⃣ Import Database
1. cPanel → **phpMyAdmin**
2. Select your database
3. **Import** → Choose SQL file → **Go**

### 6️⃣ Set Permissions
1. File Manager → Right-click `uploads/` folder
2. **Change Permissions** → Set to **755**
3. Repeat for `qr_codes/` if exists

### 7️⃣ Enable SSL
1. cPanel → **SSL/TLS** or **Let's Encrypt**
2. Select domain → **Install**
3. Wait for installation

### 8️⃣ Test
Open browser:
- `https://yourdomain.com/adminlandingpage.php`
- Try logging in

Test API:
- `https://yourdomain.com/api/mobile_client_list.php`
- Should return JSON

### 9️⃣ Update Mobile App
Change base URL:
```dart
static const String baseUrl = 'https://yourdomain.com/api/';
```

### ✅ Done!
Your system is now live on Verpex!

---

## 📋 Files Created for You

1. **`.htaccess`** - Server configuration (already created)
2. **`db.php.verpex.template`** - Database config template
3. **`VERPEX_CPANEL_DEPLOYMENT_GUIDE.md`** - Detailed guide
4. **`DEPLOYMENT_CHECKLIST.md`** - Step-by-step checklist
5. **`VERPEX_MOBILE_CONNECTION_GUIDE.md`** - Mobile app guide

---

## 🆘 Quick Troubleshooting

**500 Error?**
→ Check `.htaccess` syntax
→ Check PHP error logs in cPanel

**Database Error?**
→ Verify credentials in `db.php`
→ Check database name includes prefix

**404 Error?**
→ Verify `api/` folder exists
→ Check file names (case-sensitive)

**CORS Error?**
→ Check `.htaccess` has CORS headers
→ Use HTTPS, not HTTP

---

## 📞 Need Help?

See detailed guides:
- **Full Guide**: `VERPEX_CPANEL_DEPLOYMENT_GUIDE.md`
- **Checklist**: `DEPLOYMENT_CHECKLIST.md`
- **Mobile App**: `VERPEX_MOBILE_CONNECTION_GUIDE.md`

---

**Ready to deploy? Follow the steps above! 🎯**

