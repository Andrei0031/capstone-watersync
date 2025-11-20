# Upload Water Meter Dataset to Roboflow

## 🎯 Quick Start

This script automatically converts your mask images to YOLO annotations and uploads everything to Roboflow.

### Step 1: Install Requirements

```bash
pip install roboflow opencv-python numpy
```

### Step 2: Get Your Roboflow API Key

1. Go to: https://app.roboflow.com
2. Click **Settings** → **Roboflow API**
3. Copy your **API Key**

### Step 3: Configure the Script

1. Open `upload_to_roboflow.py`
2. Find line 12 and replace with your API key:
   ```python
   API_KEY = "your_actual_api_key_here"
   ```
3. (Optional) Update project name if different:
   ```python
   PROJECT_NAME = "watersync-digits"  # or your project name
   ```

### Step 4: Run the Script

```bash
python upload_to_roboflow.py
```

## 📁 Your Dataset Configuration

The script is already configured with your paths:
- **Images**: `D:\archive\WaterMeters\images`
- **Masks**: `D:\archive\WaterMeters\masks`
- **Collage**: `D:\archive\WaterMeters\collage` (for reference)

## 🔄 What This Script Does

1. ✅ Reads all images from your D: drive
2. ✅ Finds matching mask files
3. ✅ Converts white regions in masks to bounding boxes
4. ✅ Creates YOLO format annotations (.txt files)
5. ✅ Uploads images + annotations to Roboflow
6. ✅ Shows preview of annotations before upload

## 📊 Expected Output

```
🚀 ROBOFLOW WATER METER DATASET UPLOADER
============================================================
✅ Images folder: 150 images found
✅ Masks folder: 150 masks found
ℹ️  Collage folder: 150 images (for reference)

🔄 STEP 1: Converting masks to YOLO annotations...
============================================================
   ✅ id_1_value_13_116.jpg: 1 region(s) detected
   ✅ id_2_value_495_341.jpg: 1 region(s) detected
   ...

✅ Converted 150/150 images
📦 Total bounding boxes: 150

🔍 PREVIEW: First few annotations
============================================================
📄 id_1_value_13_116.txt:
   Box 1: 0 0.512345 0.498765 0.234567 0.123456

🚀 Ready to upload to Roboflow? (y/n): 
```

## 🎯 After Upload

Once uploaded to Roboflow, you'll need to:

1. **Verify annotations**: Check that bounding boxes are correct
2. **Label classes**: If detecting individual digits, assign digit labels (0-9)
3. **Generate dataset**: Create a dataset version
4. **Train model**: Click "Train" → Select YOLOv8 or RF-DETR
5. **Deploy**: Get your model endpoint URL

## 🔧 Customization

### Change Upload Split

In `upload_to_roboflow.py`, line 148:
```python
split="train"  # Can be: "train", "valid", or "test"
```

### Filter by Confidence

Adjust minimum region size in line 49:
```python
if w < 5 or h < 5:  # Increase to filter out smaller regions
    continue
```

### Change Class ID

In line 62, change the class number:
```python
# Class 0 = meter reading region
bboxes.append(f"0 {x_center:.6f} ...")  # Change 0 to different class
```

## ⚠️ Troubleshooting

### "No images found"
- Check that D: drive is accessible
- Verify folder paths are correct
- Make sure images are .jpg or .png format

### "No mask found for image"
- Ensure mask filenames match image filenames
- Check that masks are in the correct folder

### "Failed to connect to Roboflow"
- Verify your API key is correct
- Check your internet connection
- Make sure WORKSPACE and PROJECT_NAME are correct

### "No regions found in mask"
- Mask might be completely black
- Try adjusting threshold in line 45:
  ```python
  _, binary = cv2.threshold(mask, 127, 255, cv2.THRESH_BINARY)
  # Try changing 127 to 100 or 150
  ```

## 📝 Manual Upload Alternative

If automatic upload fails, you can manually upload:

1. Run the script - it will create annotations in:
   ```
   C:\xampp\htdocs\CAPSTONE\temp\roboflow_upload\labels\
   ```

2. In Roboflow web interface:
   - Click "Upload"
   - Select "Upload with Annotations"
   - Choose "YOLO v5/v8 PyTorch"
   - Upload both images and labels folders

## 🎓 Understanding the Output

### YOLO Format Explained
Each line in annotation file represents one bounding box:
```
class_id x_center y_center width height
```
All values (except class_id) are normalized between 0 and 1.

Example:
```
0 0.5 0.5 0.3 0.2
```
- Class 0
- Center at 50% x, 50% y
- Width 30% of image, height 20% of image

## 📧 Need Help?

Check these files for more information:
- `SETUP_ROBOFLOW_DIGIT_DETECTION.md` - Full Roboflow setup guide
- `STEP_BY_STEP_ROBOFLOW.md` - Training workflow
- `QUICK_ROBOFLOW_SETUP.md` - Integration guide

