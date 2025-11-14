# How to Access SSH in Verpex cPanel and Install Composer

## Step-by-Step Guide

### Step 1: Access Verpex cPanel

1. Log in to your Verpex account
2. Go to **"My Services"** or **"Hosting"**
3. Click on your hosting plan
4. Click **"Manage"** or **"cPanel"** button

---

### Step 2: Find SSH/Terminal Access

**Option A: Direct SSH Access**
1. In cPanel, look for **"Terminal"** or **"SSH Access"** section
2. It might be under:
   - **"Advanced"** section
   - **"Security"** section
   - **"Files"** section
   - Or search for "SSH" or "Terminal" in the cPanel search bar (top right)

**Option B: If SSH is not visible**
1. Some Verpex accounts require SSH to be enabled first
2. Contact Verpex support to enable SSH access
3. Or check **"Security"** → **"SSH Access"** to enable it

**Option C: Alternative - Use File Manager Terminal**
1. Go to **"File Manager"** in cPanel
2. Some versions have a **"Terminal"** button in File Manager
3. Click it to open a terminal window

---

### Step 3: Open Terminal/SSH

Once you find Terminal/SSH:
1. Click on **"Terminal"** or **"SSH Access"**
2. A terminal window will open in your browser
3. You'll see a command prompt like: `[username@server ~]$`

---

### Step 4: Navigate to Your Project

Type these commands one by one:

```bash
# List current directory
ls

# Navigate to public_html (your website root)
cd public_html

# Verify you're in the right place
pwd
# Should show: /home/yourusername/public_html
```

---

### Step 5: Check if Composer is Installed

```bash
# Check Composer version
composer --version
```

**If Composer is installed:**
- You'll see: `Composer version X.X.X`
- Skip to Step 7

**If Composer is NOT installed:**
- You'll see: `command not found` or `composer: command not found`
- Continue to Step 6

---

### Step 6: Install Composer (If Not Installed)

```bash
# Download Composer installer
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"

# Run installer
php composer-setup.php

# Remove installer
php -r "unlink('composer-setup.php');"

# Verify installation
php composer.phar --version
```

---

### Step 7: Install Dependencies

**If Composer is globally installed:**
```bash
composer install
```

**If you used composer.phar:**
```bash
php composer.phar install
```

**Wait for installation** (this may take 2-5 minutes)

You'll see output like:
```
Loading composer repositories with package information
Installing dependencies (including require-dev) from lock file
Package operations: X installs, 0 updates, 0 removals
  - Installing rubix/ml (X.X.X)
  - Installing rubix/tensor (X.X.X)
  ...
```

---

### Step 8: Verify Installation

```bash
# Check if vendor folder was created
ls -la vendor/

# You should see folders like:
# - rubix/
# - bacon/
# - thiagoalessio/
```

---

### Step 9: Set Permissions (If Needed)

```bash
# Make sure storage folder is writable (for ML models)
chmod -R 755 storage/
mkdir -p storage/models
chmod -R 755 storage/models
```

---

## Alternative: If SSH is Not Available

### Method 1: Upload Vendor Folder from Local

1. **On your local computer:**
   ```bash
   cd C:\xampp\htdocs\CAPSTONE
   composer install
   ```

2. **Zip the vendor folder:**
   - Right-click `vendor/` folder
   - Select "Compress" or "Send to → Compressed folder"
   - Creates `vendor.zip`

3. **Upload to Verpex:**
   - Go to cPanel → File Manager
   - Navigate to `public_html/`
   - Click "Upload"
   - Upload `vendor.zip`
   - Right-click `vendor.zip` → "Extract"

4. **Delete zip file:**
   - Delete `vendor.zip` after extraction

---

### Method 2: Use cPanel Composer (If Available)

1. In cPanel, search for **"Composer"**
2. If available, click it
3. Select your project directory (`public_html`)
4. Click **"Install Dependencies"**

---

## Troubleshooting

### "Permission Denied" Error
```bash
# Fix permissions
chmod 755 public_html
chmod 644 composer.json
```

### "Memory Limit Exceeded"
- Increase PHP memory limit in cPanel → PHP Settings
- Or run: `php -d memory_limit=512M composer.phar install`

### "Composer Not Found"
- Use `php composer.phar install` instead of `composer install`
- Or install Composer globally (contact Verpex support)

### "Cannot Create Directory"
```bash
# Create storage directory manually
mkdir -p storage/models
chmod -R 755 storage/
```

---

## Verify ML Forecasting Works

After installation:

1. Go to Admin Dashboard
2. Check Revenue Forecasting chart
3. Open browser console (F12)
4. Look for any errors
5. Chart should show ML-based forecasts

---

## Need Help?

If you can't find SSH access:
1. Contact Verpex support
2. Ask them to enable SSH access
3. Or use Method 1 (upload vendor folder)

---

## Quick Reference Commands

```bash
# Navigate to project
cd public_html

# Check Composer
composer --version

# Install dependencies
composer install

# Verify installation
ls -la vendor/

# Check storage folder
ls -la storage/
```

