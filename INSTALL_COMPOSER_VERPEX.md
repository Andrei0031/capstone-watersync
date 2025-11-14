# Install Composer Dependencies on Verpex for ML Forecasting

## Overview
The revenue forecasting uses Rubix ML which requires Composer dependencies. The `vendor/` folder is excluded from Git, so you need to install it on Verpex.

---

## Method 1: Using SSH (Recommended)

### Step 1: Access SSH Terminal
1. Log in to Verpex cPanel
2. Find **"Terminal"** or **"SSH Access"** section
3. Click to open SSH terminal

### Step 2: Navigate to Your Project
```bash
cd public_html
```

### Step 3: Check if Composer is Available
```bash
composer --version
```

**If Composer is NOT installed:**
```bash
# Download Composer
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php
php -r "unlink('composer-setup.php');"

# Use local composer.phar
php composer.phar install
```

**If Composer IS installed:**
```bash
composer install
```

### Step 4: Verify Installation
```bash
ls -la vendor/
```

You should see folders like:
- `rubix/`
- `bacon/`
- `thiagoalessio/`

---

## Method 2: Using cPanel File Manager + Composer

### Step 1: Download Composer
1. In cPanel, go to **File Manager**
2. Navigate to `public_html/`
3. Download `composer.json` to your local computer

### Step 2: Install Locally
On your local computer:
```bash
cd C:\xampp\htdocs\CAPSTONE
composer install
```

### Step 3: Upload Vendor Folder
1. In cPanel File Manager, go to `public_html/`
2. Click **"Upload"**
3. Upload the entire `vendor/` folder (zip it first if needed)
4. Extract if uploaded as zip

---

## Method 3: Using cPanel Composer (If Available)

Some Verpex accounts have Composer in cPanel:

1. Log in to Verpex cPanel
2. Look for **"Composer"** or **"PHP Composer"** section
3. Click it
4. Select your project directory (`public_html`)
5. Click **"Install Dependencies"** or run `composer install`

---

## Verify ML Forecasting Works

After installing dependencies:

1. Go to Admin Dashboard
2. Check Revenue Forecasting chart
3. Open browser console (F12) and check for errors
4. The chart should now show ML-based forecasts

---

## Troubleshooting

### Error: "Class 'Rubix\ML\Regressors\GradientBoost' not found"
- **Solution**: Vendor folder is missing or incomplete. Re-run `composer install`

### Error: "Composer not found"
- **Solution**: Use Method 1 to install Composer via SSH

### Error: "Memory limit exceeded"
- **Solution**: Increase PHP memory limit in cPanel PHP settings

### Chart Still Not Showing
- Check browser console (F12) for JavaScript errors
- Verify `dashboard_data.php` can access `vendor/autoload.php`
- Check if `storage/models/` folder exists and is writable

---

## Required Dependencies

From `composer.json`:
- `rubix/ml` - Machine Learning library
- `rubix/tensor` - Tensor operations
- `bacon/bacon-qr-code` - QR code generation
- `thiagoalessio/tesseract_ocr` - OCR functionality

---

## Notes

- The `vendor/` folder is large (~50-100MB), so uploading via File Manager might take time
- SSH method is faster and more reliable
- After installation, the ML forecasting will work automatically

