# Quick Guide: Install Composer Dependencies on Verpex

## The Problem
The `vendor/` folder cannot be properly included in Git because Composer packages are Git repositories themselves. Git treats them as submodules, which don't include the actual files.

## Solution: Install Composer on Verpex

### Method 1: SSH (Fastest)

1. **Access SSH in Verpex cPanel:**
   - Log in to Verpex cPanel
   - Find **"Terminal"** or **"SSH Access"** (search for "SSH" in cPanel)
   - Click to open terminal

2. **Navigate and Install:**
   ```bash
   cd public_html
   composer install
   ```
   
   If `composer` command not found:
   ```bash
   php composer.phar install
   ```

3. **Wait 2-5 minutes** for installation

4. **Verify:**
   ```bash
   ls -la vendor/
   ```

---

### Method 2: Upload from Local (If SSH Not Available)

1. **On your local computer:**
   ```powershell
   cd C:\xampp\htdocs\CAPSTONE
   composer install
   ```

2. **Zip the vendor folder:**
   - Right-click `vendor/` folder
   - Select "Send to → Compressed (zipped) folder"
   - Creates `vendor.zip`

3. **Upload to Verpex:**
   - cPanel → File Manager → `public_html/`
   - Click "Upload"
   - Upload `vendor.zip`
   - Right-click `vendor.zip` → "Extract"
   - Delete `vendor.zip` after extraction

---

### Method 3: cPanel Composer (If Available)

1. In cPanel, search for **"Composer"**
2. If available, click it
3. Select `public_html` directory
4. Click **"Install Dependencies"**

---

## After Installation

1. Refresh your admin dashboard
2. ML forecasting should now work
3. Check browser console (F12) for any errors

---

## Why Vendor Can't Be in Git

- Composer packages are Git repositories
- Git treats them as submodules (references, not files)
- When cloned, submodules are empty
- Solution: Install via `composer install` on the server

---

## Need Help?

If you can't access SSH:
- Contact Verpex support to enable SSH
- Or use Method 2 (upload vendor.zip)

