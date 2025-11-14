# Verpex Deployment Checklist

## ✅ Pre-Deployment

- [ ] Export database from local XAMPP (phpMyAdmin → Export)
- [ ] Note Verpex database credentials
- [ ] Note Verpex FTP/cPanel credentials
- [ ] Have domain name ready

## ✅ Step 1: Database Setup

- [ ] Create MySQL database in Verpex cPanel
- [ ] Create database user
- [ ] Assign user to database with ALL PRIVILEGES
- [ ] Note down: database name, username, password

## ✅ Step 2: File Upload

- [ ] Access Verpex cPanel File Manager
- [ ] Navigate to `public_html/`
- [ ] Upload all project files
- [ ] Verify `api/` folder exists
- [ ] Verify `db.php` exists

## ✅ Step 3: Database Configuration

- [ ] Edit `db.php` in File Manager
- [ ] Update database host (usually `localhost`)
- [ ] Update database username
- [ ] Update database password
- [ ] Update database name (include prefix if any)
- [ ] Save file

## ✅ Step 4: Import Database

- [ ] Access phpMyAdmin in cPanel
- [ ] Select your database
- [ ] Click Import tab
- [ ] Upload exported SQL file
- [ ] Verify tables are created

## ✅ Step 5: File Permissions

- [ ] Set `uploads/` folder to 755 or 777
- [ ] Set `qr_codes/` folder to 755 or 777 (if exists)
- [ ] Set PHP files to 644
- [ ] Set `.htaccess` to 644

## ✅ Step 6: SSL Configuration

- [ ] Install SSL certificate (Let's Encrypt)
- [ ] Enable HTTPS redirect in `.htaccess`
- [ ] Test HTTPS access

## ✅ Step 7: PHP Configuration

- [ ] Set PHP version to 7.4 or higher
- [ ] Enable required extensions:
  - [ ] mysqli
  - [ ] gd
  - [ ] curl
  - [ ] json
  - [ ] mbstring

## ✅ Step 8: Testing

- [ ] Test web interface: `https://yourdomain.com/adminlandingpage.php`
- [ ] Test admin login
- [ ] Test API endpoint: `https://yourdomain.com/api/mobile_client_list.php`
- [ ] Test authentication: `https://yourdomain.com/api/auth.php`
- [ ] Test file uploads
- [ ] Test database queries

## ✅ Step 9: Mobile App Update

- [ ] Update base URL in mobile app
- [ ] Change to HTTPS
- [ ] Test mobile app connection
- [ ] Test login from mobile app
- [ ] Test meter reading submission

## ✅ Step 10: Final Verification

- [ ] All pages load correctly
- [ ] Database queries work
- [ ] API endpoints respond
- [ ] Mobile app connects successfully
- [ ] File uploads work
- [ ] OCR processing works (if applicable)
- [ ] Email notifications work (if configured)

---

## 📝 Notes

- Database name might be prefixed (e.g., `username_watersync_db`)
- Use `localhost` for database host (usually correct for Verpex)
- Enable SSL before testing mobile app
- Keep local backup of files and database

---

## 🆘 If Something Goes Wrong

1. Check cPanel error logs
2. Check PHP error logs
3. Verify database credentials
4. Check file permissions
5. Verify `.htaccess` syntax
6. Test API endpoints with Postman

---

**Deployment Date**: _______________
**Domain**: _______________
**Database Name**: _______________
**Status**: _______________

