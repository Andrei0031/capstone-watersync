# Tesseract OCR Training Guide for Water Meter Reading

## Overview
Your app now uses **server-side Tesseract OCR** running on your PHP backend. This allows you to train Tesseract to improve accuracy for water meter displays.

## Step 1: Install Tesseract OCR on Your Server

### Windows (XAMPP):
1. Download Tesseract from: https://github.com/UB-Mannheim/tesseract/wiki
2. Install to: `C:\Program Files\Tesseract-OCR\`
3. Add to PATH (optional, but recommended):
   - Add `C:\Program Files\Tesseract-OCR\` to your system PATH
4. Verify installation:
   ```cmd
   tesseract --version
   ```

### Linux:
```bash
sudo apt-get update
sudo apt-get install tesseract-ocr tesseract-ocr-dev
```

## Step 2: Test the OCR API

1. Make sure your PHP API endpoint is working:
   - URL: `http://192.168.100.5/CAPSTONE/api/ocr_meter_reading.php`
   - Test with Postman or curl

2. The API expects:
   ```json
   {
     "image": "base64_encoded_image_data"
   }
   ```

## Step 3: Prepare Training Data

### Collect Training Images:
1. Gather **50-100+ images** of water meters with known readings
2. Include various conditions:
   - Different lighting (bright, dim, shadows)
   - Different meter models
   - Clean and dirty meters
   - Different angles

### Create Ground Truth Files:
For each image, create a `.gt.txt` file with the exact text shown:
- Image: `meter_001.png`
- Ground truth: `meter_001.gt.txt` containing: `00442 m³`

## Step 4: Train Tesseract

### Option A: Fine-tune English Model (Easier)

1. **Install tesstrain**:
   ```bash
   git clone https://github.com/tesseract-ocr/tesstrain.git
   cd tesstrain
   ```

2. **Prepare your data**:
   ```
   data/
     watermeter-ground-truth/
       meter_001.png
       meter_001.gt.txt
       meter_002.png
       meter_002.gt.txt
       ...
   ```

3. **Run training**:
   ```bash
   make training MODEL_NAME=watermeter START_MODEL=eng
   ```

4. **Output**: You'll get `watermeter.traineddata` file

### Option B: Train from Scratch (More Control)

1. Follow Tesseract training documentation:
   https://tesseract-ocr.github.io/tessdoc/Training-Tesseract.html

2. Use `tesstrain` repository for easier training

## Step 5: Install Trained Model

1. **Copy trained model** to Tesseract data directory:
   - Windows: `C:\Program Files\Tesseract-OCR\tessdata\`
   - Linux: `/usr/share/tesseract-ocr/4.00/tessdata/`

2. **Update PHP API** (`ocr_meter_reading.php`):
   ```php
   $language = 'watermeter'; // Change from 'eng' to your trained model
   ```

3. **Or use multiple languages**:
   ```php
   $language = 'eng+watermeter'; // Combines English + your trained model
   ```

## Step 6: Test Your Trained Model

1. Test with sample images before deploying
2. Monitor accuracy and adjust training data if needed
3. Consider creating multiple models for different meter types

## Training Tips

1. **More data = Better accuracy**: Aim for 100+ images minimum
2. **Quality matters**: Use clear, well-lit images
3. **Variety is key**: Include different meter models and conditions
4. **Test frequently**: Test after every 20-30 new training images

## Troubleshooting

### Tesseract not found:
- Check installation path in `ocr_meter_reading.php`
- Verify Tesseract is in system PATH
- Update `$tesseractPath` variable in PHP file

### Poor OCR accuracy:
- Add more training images
- Improve image quality (preprocessing)
- Try different PSM modes (change `--psm 6` to `--psm 7` or `--psm 8`)

### Training fails:
- Ensure ground truth files match image filenames exactly
- Check that images are readable
- Verify Tesseract version compatibility

## Resources

- **Tesseract Training**: https://github.com/tesseract-ocr/tesstrain
- **Training Tutorial**: https://tesseract-ocr.github.io/tessdoc/Training-Tesseract.html
- **Video Guide**: Search "How to Train Tesseract OCR Engine 5 on Custom Data"

## Current Configuration

- **OCR Engine**: Tesseract (server-side)
- **Language**: `eng` (English) - Change to your trained model
- **PSM Mode**: `6` (Assume uniform block of text)
- **API Endpoint**: `/api/ocr_meter_reading.php`

