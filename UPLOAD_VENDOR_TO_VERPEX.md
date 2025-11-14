# Upload Vendor Folder to Verpex (No SSH Required)

## Step-by-Step Guide

### Step 1: Install Composer Locally (If Not Done)

On your local computer, open PowerShell:

```powershell
cd C:\xampp\htdocs\CAPSTONE
composer install
```

Wait for installation to complete (2-5 minutes).

---

### Step 2: Zip the Vendor Folder

1. Navigate to: `C:\xampp\htdocs\CAPSTONE\`
2. Right-click on the `vendor` folder
3. Select **"Send to"** → **"Compressed (zipped) folder"**
4. This creates `vendor.zip` in the same directory

**Note:** The zip file might be 50-100MB, so uploading may take a few minutes.

---

### Step 3: Upload to Verpex via File Manager

1. **Log in to Verpex cPanel**
2. Go to **"File Manager"**
3. Navigate to **`public_html/`** folder
4. Click **"Upload"** button (top menu)
5. Click **"Select File"** or drag and drop `vendor.zip`
6. Wait for upload to complete (may take 5-10 minutes for large files)

---

### Step 4: Extract the Zip File

1. In File Manager, find `vendor.zip` in `public_html/`
2. Right-click on `vendor.zip`
3. Select **"Extract"** or **"Extract All"**
4. Choose extraction location: `public_html/`
5. Click **"Extract"**
6. Wait for extraction (may take 2-3 minutes)

---

### Step 5: Verify Installation

1. In File Manager, check if `vendor/` folder exists in `public_html/`
2. Open `vendor/` folder
3. You should see folders like:
   - `rubix/`
   - `bacon/`
   - `composer/`
   - `amphp/`
   - etc.

---

### Step 6: Set Permissions (If Needed)

1. Right-click `vendor/` folder
2. Select **"Change Permissions"**
3. Set to **755** (or **777** if 755 doesn't work)
4. Click **"Change Permissions"**

---

### Step 7: Create Storage Folder (For ML Models)

1. In File Manager, go to `public_html/`
2. Click **"+ Folder"** button
3. Name it: `storage`
4. Click **"Create"**
5. Open `storage/` folder
6. Create another folder inside: `models`
7. Set permissions to **755** for both folders

---

### Step 8: Test ML Forecasting

1. Go to your admin dashboard
2. Check Revenue Forecasting chart
3. Open browser console (F12) to check for errors
4. ML forecasting should now work!

---

## Troubleshooting

### "File Too Large" Error
- **Solution:** Contact Verpex support to increase upload limit
- Or split vendor into multiple zip files

### "Permission Denied" Error
- **Solution:** Set vendor folder permissions to 755 or 777

### "Storage Folder Not Writable"
- **Solution:** Create `storage/models/` folder manually
- Set permissions to 755 or 777

### Chart Still Not Working
- Check browser console (F12) for errors
- Verify `vendor/autoload.php` exists
- Check if `storage/models/` folder exists and is writable

---

## Quick Checklist

- [ ] `composer install` run locally
- [ ] `vendor.zip` created
- [ ] `vendor.zip` uploaded to Verpex
- [ ] `vendor.zip` extracted in `public_html/`
- [ ] `vendor/` folder verified
- [ ] `storage/models/` folder created
- [ ] Permissions set correctly
- [ ] ML forecasting tested

---

## Alternative: Use cPanel Terminal (If Available)

If you find Terminal in cPanel later:

1. Open Terminal
2. Run:
   ```bash
   cd public_html
   composer install
   ```

This is faster than uploading, but requires SSH/Terminal access.

