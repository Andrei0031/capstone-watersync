# jTessBoxEditor vs VietOCR - Clarification

## ✅ Yes, You're Looking at the Right Tool!

**VietOCR** is the project name, but **jTessBoxEditor** is the specific tool you need.

## What's the Difference?

### VietOCR
- **What it is:** An OCR application for Vietnamese text
- **Project:** https://sourceforge.net/projects/vietocr/
- **Not what we need** ❌

### jTessBoxEditor
- **What it is:** A visual training tool for Tesseract OCR
- **Part of:** VietOCR project
- **Download page:** https://sourceforge.net/projects/vietocr/files/jTessBoxEditor/
- **This is what we need!** ✅

## Where to Download

### Correct Download Link:
**jTessBoxEditor (the training tool):**
- https://sourceforge.net/projects/vietocr/files/jTessBoxEditor/
- Look for: `jTessBoxEditor.jar` file
- File size: ~2-3 MB

### What You'll See:
```
VietOCR Project
├── jTessBoxEditor/          ← THIS IS WHAT YOU NEED!
│   └── jTessBoxEditor.jar
└── Other files...
```

## How to Identify the Right File

✅ **Correct file:** `jTessBoxEditor.jar` (or `jTessBoxEditor-2.3.1.jar`)
✅ **File type:** Java Archive (.jar)
✅ **Size:** ~2-3 MB
✅ **Location:** Inside `jTessBoxEditor/` folder

❌ **Wrong file:** `VietOCR.jar` or other files

## Direct Download Steps

1. **Go to:** https://sourceforge.net/projects/vietocr/files/jTessBoxEditor/

2. **Click on:** `jTessBoxEditor-2.3.1/` (or latest version)

3. **Download:** `jTessBoxEditor.jar`

4. **Save to:** `C:\TesseractTools\` (or any folder)

5. **Run:** `java -Xmx1024m -jar jTessBoxEditor.jar`

## Verification

After downloading, you should have:
- ✅ File name: `jTessBoxEditor.jar`
- ✅ File type: Java Archive
- ✅ Can run with: `java -jar jTessBoxEditor.jar`

## Alternative Download Sources

If SourceForge is slow, you can also try:
- GitHub: Search "jTessBoxEditor"
- Official Tesseract wiki: https://github.com/tesseract-ocr/tesseract/wiki/Compiling#jTessBoxEditor

## Summary

**Yes, VietOCR project page is correct!** Just make sure you download **jTessBoxEditor.jar** (not VietOCR.jar or other files). The jTessBoxEditor tool is what you need for training Tesseract to recognize your water meter readings.

