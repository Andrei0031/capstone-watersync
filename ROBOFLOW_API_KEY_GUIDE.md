# Roboflow API Key Guide - Which Key to Use?

## ✅ Answer: Use **PRIVATE API KEY**

For server-side Roboflow API calls, you need the **Private API Key**, not the Publishable API Key.

---

## 🔑 Difference Between Keys:

### **Private API Key** (Use This ✅)
- **Purpose:** Server-side API calls
- **Security:** Keep it secret, never expose to client-side
- **Usage:** Backend/server applications (PHP, Python, Node.js, etc.)
- **Access:** Full API access
- **Format:** Starts with `rf_...` (longer string)

### **Publishable API Key** (Don't Use ❌)
- **Purpose:** Client-side/browser applications
- **Security:** Can be exposed in frontend code
- **Usage:** JavaScript in browsers, mobile apps (if needed)
- **Access:** Limited API access
- **Format:** Different format, shorter

---

## 📍 Where to Find Your Private API Key:

### Option 1: Account Settings
1. Go to: https://app.roboflow.com
2. Click your **profile icon** (top right)
3. Select **"Account Settings"**
4. Go to **"API"** tab
5. Look for **"Private API Key"** or **"API Key"**
6. Copy the key (starts with `rf_...`)

### Option 2: Project Settings
1. Go to: https://app.roboflow.com/watersync/watersync-oekrf
2. Click **"Settings"** (gear icon)
3. Scroll to **"API"** section
4. Find **"Private API Key"**
5. Copy the key

---

## 🔧 How to Configure:

**File:** `C:\xampp\htdocs\CAPSTONE\api\roboflow_service.php`

**Line 7:** Replace with your **Private API Key**:
```php
define('ROBOFLOW_API_KEY', 'rf_your_private_api_key_here');
```

**Example:**
```php
define('ROBOFLOW_API_KEY', 'rf_abc123def456ghi789jkl012mno345pqr678stu901vwx234yz');
```

---

## 🔒 Security Notes:

✅ **DO:**
- Keep Private API Key on server only
- Never commit it to public repositories
- Use environment variables if possible
- Restrict access to server files

❌ **DON'T:**
- Expose Private API Key in client-side code
- Share it publicly
- Commit it to version control
- Use Publishable Key for server-side

---

## ✅ Verification:

After configuring, test by:
1. Upload an image from mobile app
2. Click "Process Selected" on web interface
3. Check server logs for Roboflow API calls
4. If you see "Roboflow API error: 401", the key is wrong
5. If you see "Roboflow cropped image...", it's working!

---

## 📝 Summary:

**Use:** Private API Key (starts with `rf_...`)  
**Location:** Account Settings → API → Private API Key  
**File:** `api/roboflow_service.php` → Line 7

**That's it!** 🚀

