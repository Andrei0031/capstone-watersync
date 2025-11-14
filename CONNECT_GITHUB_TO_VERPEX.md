# 🚀 Connect GitHub to Verpex cPanel - Quick Guide

## Your GitHub Repository URL
```
https://github.com/Andrei0031/capstone-watersync.git
```

---

## Step-by-Step Instructions

### Step 1: Access Verpex cPanel
1. Log in to your Verpex hosting account
2. Navigate to **cPanel**
3. Look for **"Git Version Control"** section
   - If you don't see it, use the search bar at the top of cPanel to search for "Git"

### Step 2: Create Git Repository in cPanel
1. Click **"Create"** button in Git Version Control
2. Select **"Clone a Repository"** option
3. Fill in the details:
   - **Repository URL**: `https://github.com/Andrei0031/capstone-watersync.git`
   - **Repository Path**: `public_html/` (this makes it accessible at your domain root)
   - **Repository Name**: `capstone-watersync` (or any name you prefer)
4. Click **"Create"**

### Step 3: Wait for Cloning
- cPanel will automatically clone your GitHub repository
- This may take 1-2 minutes depending on file size
- You'll see a success message when done

### Step 4: Update Database Configuration
After cloning, you need to update `db.php` with Verpex database credentials:

1. In cPanel, go to **"File Manager"**
2. Navigate to `public_html/` folder
3. Find `db.php` file
4. Right-click → **"Edit"**
5. Update these lines with your Verpex database credentials:

```php
$host = 'localhost';
$user = 'your_verpex_db_username';  // Replace with actual username
$pass = 'your_verpex_db_password';  // Replace with actual password
$dbname = 'your_verpex_db_name';    // Replace with actual database name
```

6. Click **"Save Changes"**

**Note**: The `.gitignore` file ensures `db.php` won't be overwritten when you push updates from GitHub.

### Step 5: Set Folder Permissions
1. In File Manager, navigate to `public_html/`
2. Right-click on `uploads/` folder → **"Change Permissions"**
3. Set to **755** (or **777** if 755 doesn't work)
4. Repeat for `qr_codes/` folder if it exists

### Step 6: Import Database
1. In cPanel, go to **"phpMyAdmin"**
2. Select your database (the one you created earlier)
3. Click **"Import"** tab
4. Choose your SQL file (exported from local XAMPP)
5. Click **"Go"**

### Step 7: Test Your Application
Open your browser and visit:
- `https://yourdomain.com/adminlandingpage.php`
- Try logging in
- Test API: `https://yourdomain.com/api/mobile_client_list.php`

---

## Future Updates (After Initial Setup)

### To Update Your Live Site:
1. Make changes locally in `C:\xampp\htdocs\CAPSTONE\`
2. Commit and push to GitHub:
   ```bash
   git add .
   git commit -m "Description of changes"
   git push origin main
   ```
3. In Verpex cPanel Git Version Control:
   - Click **"Manage"** next to your repository
   - Click **"Pull or Deploy"** button
   - Your site will update automatically!

**Note**: Your `db.php` file will remain unchanged because it's in `.gitignore`.

---

## Troubleshooting

### Issue: Can't find Git Version Control in cPanel
**Solution**: 
- Some Verpex plans might not have Git Version Control
- Contact Verpex support to enable it
- Alternative: Use FTP to upload files manually

### Issue: Repository won't clone
**Solution**:
- Make sure your GitHub repository is **Public** (or you've added SSH keys)
- Check that the URL is correct: `https://github.com/Andrei0031/capstone-watersync.git`
- Try using SSH URL instead: `git@github.com:Andrei0031/capstone-watersync.git`

### Issue: Files not showing after clone
**Solution**:
- Check that repository path is `public_html/` (not a subfolder)
- Refresh File Manager
- Check file permissions

### Issue: Database connection error
**Solution**:
- Verify `db.php` has correct Verpex credentials
- Check database name includes prefix (e.g., `username_watersync`)
- Verify database user has ALL PRIVILEGES

---

## Quick Checklist

- [ ] Logged into Verpex cPanel
- [ ] Found Git Version Control section
- [ ] Created repository with GitHub URL
- [ ] Repository cloned successfully
- [ ] Updated `db.php` with Verpex credentials
- [ ] Set `uploads/` folder permissions to 755
- [ ] Imported database via phpMyAdmin
- [ ] Tested website: `https://yourdomain.com/adminlandingpage.php`
- [ ] Tested API: `https://yourdomain.com/api/mobile_client_list.php`

---

## Your GitHub Repository
🔗 **URL**: https://github.com/Andrei0031/capstone-watersync.git

---

**Need help?** Let me know if you encounter any issues during setup!

