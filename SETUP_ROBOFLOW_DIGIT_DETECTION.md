# Setup Guide: Roboflow YOLOv8 Digit Detection

## ✅ Code Changes Complete!

The system has been updated to use **Roboflow YOLOv8 digit detection** instead of Tesseract OCR. This is perfect for Verpex hosting because:
- ✅ No server-side installation needed
- ✅ All processing via API calls
- ✅ Works on shared hosting
- ✅ Can be trained in Roboflow

## 📋 What You Need to Do Next

### Step 1: Create Digit Detection Project in Roboflow

1. Go to [roboflow.com](https://roboflow.com) and log in
2. Click **"+ New Project"**
3. Set up the project:
   - **Project Name**: `watersync-digits` (or any name you prefer)
   - **Project Type**: **Object Detection**
   - **Classes**: Create 10 classes: `0`, `1`, `2`, `3`, `4`, `5`, `6`, `7`, `8`, `9`

### Step 2: Upload and Annotate Images

1. **Upload Images**: Upload your water meter images (at least 6-10 images minimum)
2. **Annotate Digits**: For each image:
   - Draw bounding boxes around **each individual digit** (0-9)
   - Label each box with the correct digit class
   - Example: If reading is "00792", draw 5 boxes and label them: `0`, `0`, `7`, `9`, `2`
3. **Important**: You need at least 2 images in each split (Train/Valid/Test), so minimum 6 images total

### Step 3: Create Dataset Version

1. Go to **"Versions"** tab
2. Click **"+ Create New Version"**
3. Configure:
   - **Train/Test Split**: Adjust slider to have at least 2 images in each split
   - **Preprocessing**: Auto-Orient, Resize (512x512 recommended)
   - **Augmentation**: Optional (can skip for now)
4. Click **"Create"**

### Step 4: Train the Model

1. On the version page, click **"Custom Train"**
2. Select **"RF-DETR"** (Recommended) or **"YOLOv11"**
3. Select **"Small"** model size (good balance)
4. Select **"Train from Objects365 Pretrained Weights"** (Recommended)
5. Click **"Start Training"**
6. Wait 5-10 minutes for training to complete

### Step 5: Deploy the Model

1. After training completes, go to **"Deployments"** tab
2. Click **"Deploy"** or **"Create Deployment"**
3. Choose **"Integrate with my app or website"** (Serverless Hosted API)
4. Copy the **endpoint URL** that Roboflow provides
   - It will look like: `https://detect.roboflow.com/watersync/watersync-digits/1?api_key=...`

### Step 6: Update Configuration

1. Open `C:\xampp\htdocs\CAPSTONE\api\roboflow_service.php`
2. Find these lines (around lines 20-22):
   ```php
   define('ROBOFLOW_DIGIT_PROJECT', 'watersync-digits'); // Change this to your digit detection project name
   define('ROBOFLOW_DIGIT_MODEL_VERSION', '1'); // Change this to your digit model version
   ```
3. Update with your actual values:
   - `ROBOFLOW_DIGIT_PROJECT`: Your project name (e.g., `watersync-digits`)
   - `ROBOFLOW_DIGIT_MODEL_VERSION`: Your model version number (e.g., `1`, `2`, `3`)
4. **OR** replace the entire `ROBOFLOW_DIGIT_INFERENCE_URL` line with the full URL from Roboflow

### Step 7: Test

1. Go to your web app → **"Pending OCR Processing"**
2. Select a reading and click **"Process Selected"**
3. The system will now:
   - Use Roboflow to detect meter region (if configured)
   - Use Roboflow to detect individual digits (0-9)
   - Combine digits left-to-right to form 5-digit reading
   - Save the reading to database

## 🔧 Current Configuration

The code is ready and will use:
- **Meter Detection**: `watersync/watersync-oekrf/3` (your existing model)
- **Digit Detection**: `watersync/watersync-digits/1` (needs to be created and configured)

## 📝 Notes

- **Minimum Images**: You need at least 6 images (2 per split) to train
- **More Images = Better Accuracy**: Aim for 20-50+ images for better results
- **Digit Annotation**: Each digit needs its own bounding box - this is more work than Tesseract but gives better accuracy
- **Confidence Threshold**: Currently set to 0.3 (can be adjusted in `roboflow_service.php`)

## 🆘 Troubleshooting

- **405 Error**: Model not deployed - deploy it in Roboflow first
- **404 Error**: Wrong project/version name - check configuration
- **No digits detected**: Model needs more training data or confidence threshold too high
- **Wrong reading order**: Digits are sorted by X position (left-to-right) automatically

## ✅ Benefits

- ✅ No Tesseract installation needed
- ✅ Works on Verpex shared hosting
- ✅ Can be trained/improved in Roboflow
- ✅ Better accuracy than Tesseract (when trained well)
- ✅ All processing via API (scalable)

