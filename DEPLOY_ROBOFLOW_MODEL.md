# How to Deploy Your Roboflow Model

## The Problem
You're getting a **405 "Method Not Allowed"** error because your Roboflow model needs to be **deployed** before it can be accessed via API.

## Solution: Deploy Your Model

### Step 1: Go to Your Roboflow Project
1. Open your browser and go to [roboflow.com](https://roboflow.com)
2. Log in to your account
3. Navigate to your project: **watersync** → **watersync-oekrf**

### Step 2: Deploy the Model
1. Click on the **"Deploy"** button (or tab) in your project
2. You'll see a modal with deployment options
3. Click on **"Integrate with my app or website"** (the first option with "Recommended" tag)
4. This will deploy your model as a **Serverless Hosted API**

### Step 3: Get the Endpoint URL
After deployment, Roboflow will show you:
- **API Endpoint URL** (something like: `https://detect.roboflow.com/watersync/watersync-oekrf/3`)
- **API Key** (you already have this: `plVsmWuM0KjEA8Pz6RqB`)

### Step 4: Update the Code
1. Copy the **full endpoint URL** from Roboflow (it should include the API key as a query parameter)
2. Open `C:\xampp\htdocs\CAPSTONE\api\roboflow_service.php`
3. Find this line (around line 17):
   ```php
   define('ROBOFLOW_INFERENCE_URL', 'https://detect.roboflow.com/...');
   ```
4. Replace it with the URL from Roboflow (or just add `?api_key=plVsmWuM0KjEA8Pz6RqB` to the end if it doesn't have it)

### Step 5: Test
1. Go to your web app → "Pending OCR Processing"
2. Select a reading and click "Process Selected"
3. Check the error logs - you should see a successful Roboflow API call (HTTP 200)

## Alternative: If You Can't Deploy
If deployment is not available or you're on a free plan, you can:
1. **Skip Roboflow** - The system will process the full image with Tesseract OCR (it's already doing this as a fallback)
2. **Use Roboflow Batch Processing** - Process images in batches through the Roboflow web interface

## Current Status
- ✅ Model is trained (3 annotated images)
- ❌ Model is NOT deployed (needs deployment)
- ✅ Code is ready (will work after deployment)

## Need Help?
- Check Roboflow documentation: https://docs.roboflow.com/deploy/serverless-hosted-api-v2
- Roboflow support: support@roboflow.com

