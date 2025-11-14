# Step-by-Step Guide: Train and Deploy Your Roboflow Model

## Current Status
✅ You have version v1 created with 3 images  
❌ No model trained yet  
❌ Model not deployed  

## What You Need to Do (3 Simple Steps)

### STEP 1: Train Your Model (5-10 minutes)
1. On the page you're seeing, click the purple **"Custom Train"** button
2. Wait for training to complete (you'll see progress)
3. Once done, you'll see a trained model appear

### STEP 2: Deploy Your Model (2 minutes)
1. After training completes, click **"Deployments"** in the left sidebar (under DEPLOY section)
2. Click **"Deploy"** or **"Create Deployment"**
3. Choose **"Integrate with my app or website"** (the recommended option)
4. Copy the **endpoint URL** that Roboflow shows you
   - It will look like: `https://detect.roboflow.com/watersync/watersync-oekrf/1?api_key=...`

### STEP 3: Update Your Code (1 minute)
1. Open `C:\xampp\htdocs\CAPSTONE\api\roboflow_service.php`
2. Find line 17 (the `ROBOFLOW_INFERENCE_URL` line)
3. Replace it with the URL from Roboflow
4. Also update `ROBOFLOW_MODEL_VERSION` to match (probably `'1'` instead of `'3'`)

## That's It!
After these 3 steps, your system will:
1. ✅ Use Roboflow to detect the meter region
2. ✅ Crop the image to focus on the meter
3. ✅ Run OCR on the cropped image
4. ✅ Extract the 5-digit reading

## If You Get Stuck
- **Training takes too long?** It's normal, wait 5-10 minutes
- **Can't find Deploy button?** Look in the left sidebar under "DEPLOY" → "Deployments"
- **Still getting errors?** Make sure you copied the FULL URL from Roboflow (including `?api_key=...`)

