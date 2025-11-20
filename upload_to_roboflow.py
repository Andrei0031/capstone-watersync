"""
Roboflow Water Meter Dataset Uploader
Converts mask images to YOLO annotations and uploads to Roboflow
"""

from roboflow import Roboflow
import cv2
import numpy as np
import os
import sys
from pathlib import Path

# Fix Windows console encoding
if sys.platform == "win32":
    sys.stdout.reconfigure(encoding='utf-8')

# ============= CONFIGURATION =============
API_KEY = "plVsmWuM0KjEA8Pz6RqB"  # Get from https://app.roboflow.com → Settings → Roboflow API
WORKSPACE = "watersync"
PROJECT_NAME = "watersync-oekrf"  # Your existing water meter project

# Dataset paths from D: drive
IMAGES_FOLDER = r"D:\archive\WaterMeters\images"
MASKS_FOLDER = r"D:\archive\WaterMeters\masks"
COLLAGE_FOLDER = r"D:\archive\WaterMeters\collage"  # Pre-annotated images with boxes

# Output folder for temporary annotation files
OUTPUT_FOLDER = r"C:\xampp\htdocs\CAPSTONE\temp\roboflow_upload"

# ============= MASK TO ANNOTATION CONVERTER =============
def mask_to_yolo_bbox(mask_path, image_name):
    """
    Convert a mask image to YOLO bounding box format.
    Returns: list of bounding boxes (class, x_center, y_center, width, height) normalized to 0-1
    """
    try:
        # Read mask image
        mask = cv2.imread(mask_path, cv2.IMREAD_GRAYSCALE)
        if mask is None:
            print(f"      ⚠️  Could not read mask: {mask_path}")
            return []
        
        height, width = mask.shape
        
        # Threshold to binary (in case mask is not pure black/white)
        _, binary = cv2.threshold(mask, 127, 255, cv2.THRESH_BINARY)
        
        # Find contours (regions in the mask)
        contours, _ = cv2.findContours(binary, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
        
        bboxes = []
        for i, contour in enumerate(contours):
            # Get bounding rectangle
            x, y, w, h = cv2.boundingRect(contour)
            
            # Skip very small regions (noise)
            if w < 5 or h < 5:
                continue
            
            # Convert to YOLO format (normalized 0-1)
            x_center = (x + w/2) / width
            y_center = (y + h/2) / height
            norm_width = w / width
            norm_height = h / height
            
            # Class 0 = meter reading region (you can customize this)
            bboxes.append(f"0 {x_center:.6f} {y_center:.6f} {norm_width:.6f} {norm_height:.6f}")
        
        return bboxes
    except Exception as e:
        print(f"      ❌ Error processing mask: {str(e)}")
        return []

def create_yolo_annotations():
    """
    Create YOLO format annotation files for all images based on masks
    """
    print("\n" + "="*60)
    print("STEP 1: Converting masks to YOLO annotations...")
    print("="*60)
    
    # Create output folders
    os.makedirs(OUTPUT_FOLDER, exist_ok=True)
    labels_folder = os.path.join(OUTPUT_FOLDER, "labels")
    os.makedirs(labels_folder, exist_ok=True)
    
    # Get all image files
    image_files = list(Path(IMAGES_FOLDER).glob("*.jpg")) + list(Path(IMAGES_FOLDER).glob("*.png"))
    
    if not image_files:
        print(f"[ERROR] No images found in: {IMAGES_FOLDER}")
        return labels_folder, 0
    
    print(f"[INFO] Found {len(image_files)} images")
    
    converted = 0
    total_bboxes = 0
    
    for img_path in image_files:
        image_name = img_path.stem  # filename without extension
        
        # Try to find corresponding mask with same name
        mask_candidates = [
            os.path.join(MASKS_FOLDER, f"{image_name}.jpg"),
            os.path.join(MASKS_FOLDER, f"{image_name}.png"),
            os.path.join(MASKS_FOLDER, f"{image_name}.jpeg"),
        ]
        
        mask_path = None
        for candidate in mask_candidates:
            if os.path.exists(candidate):
                mask_path = candidate
                break
        
        if not mask_path:
            print(f"   ⚠️  No mask found for: {img_path.name}")
            continue
        
        # Convert mask to bounding boxes
        bboxes = mask_to_yolo_bbox(mask_path, image_name)
        
        if bboxes:
            # Save YOLO format annotation
            annotation_file = os.path.join(labels_folder, f"{image_name}.txt")
            with open(annotation_file, 'w') as f:
                f.write('\n'.join(bboxes))
            
            converted += 1
            total_bboxes += len(bboxes)
            print(f"   ✅ {img_path.name}: {len(bboxes)} region(s) detected")
        else:
            print(f"   ⚠️  {img_path.name}: No regions found in mask")
    
    print(f"\n✅ Converted {converted}/{len(image_files)} images")
    print(f"📦 Total bounding boxes: {total_bboxes}")
    
    return labels_folder, converted

def upload_with_annotations(labels_folder, skip_upload=False):
    """
    Upload images with their annotations to Roboflow
    """
    if skip_upload:
        print("\n⏭️  Skipping upload (API key not configured)")
        return
    
    print("\n" + "="*60)
    print("🚀 STEP 2: Uploading to Roboflow...")
    print("="*60)
    
    # Check API key
    if API_KEY == "YOUR_ROBOFLOW_API_KEY":
        print("❌ Please update API_KEY in the script first!")
        print("   Get it from: https://app.roboflow.com → Settings → Roboflow API")
        return
    
    try:
        # Initialize Roboflow
        print(f"🔌 Connecting to Roboflow...")
        rf = Roboflow(api_key=API_KEY)
        project = rf.workspace(WORKSPACE).project(PROJECT_NAME)
        print(f"✅ Connected to project: {WORKSPACE}/{PROJECT_NAME}")
    except Exception as e:
        print(f"❌ Failed to connect to Roboflow: {str(e)}")
        print("   Check your API_KEY, WORKSPACE, and PROJECT_NAME")
        return
    
    # Get all image files
    image_files = list(Path(IMAGES_FOLDER).glob("*.jpg")) + list(Path(IMAGES_FOLDER).glob("*.png"))
    
    uploaded = 0
    skipped = 0
    failed = 0
    
    for img_path in image_files:
        try:
            image_name = img_path.stem
            annotation_file = os.path.join(labels_folder, f"{image_name}.txt")
            
            # Check if annotation exists
            if os.path.exists(annotation_file):
                print(f"📤 Uploading: {img_path.name}...", end=" ")
                project.upload(
                    image_path=str(img_path),
                    annotation_path=annotation_file,
                    split="train",
                    batch_name="kaggle_water_meters"
                )
                uploaded += 1
                print(f"✅ ({uploaded}/{len(image_files)})")
            else:
                print(f"⏭️  Skipping {img_path.name} (no annotation)")
                skipped += 1
        except Exception as e:
            print(f"❌ Failed: {str(e)}")
            failed += 1
    
    print(f"\n" + "="*60)
    print(f"✅ UPLOAD COMPLETE!")
    print(f"="*60)
    print(f"   ✅ Uploaded: {uploaded}")
    print(f"   ⏭️  Skipped: {skipped}")
    print(f"   ❌ Failed: {failed}")

def preview_annotations(num_preview=5):
    """
    Preview the first few annotations to verify conversion
    """
    print("\n" + "="*60)
    print("🔍 PREVIEW: First few annotations")
    print("="*60)
    
    labels_folder = os.path.join(OUTPUT_FOLDER, "labels")
    annotation_files = list(Path(labels_folder).glob("*.txt"))[:num_preview]
    
    for ann_file in annotation_files:
        print(f"\n📄 {ann_file.name}:")
        with open(ann_file, 'r') as f:
            lines = f.readlines()
            for i, line in enumerate(lines[:3], 1):  # Show first 3 boxes
                print(f"   Box {i}: {line.strip()}")
            if len(lines) > 3:
                print(f"   ... and {len(lines) - 3} more box(es)")

# ============= MAIN EXECUTION =============
if __name__ == "__main__":
    print("="*60)
    print(">>> ROBOFLOW WATER METER DATASET UPLOADER <<<")
    print("="*60)
    
    # Validate paths
    errors = []
    if not os.path.exists(IMAGES_FOLDER):
        errors.append(f"❌ Images folder not found: {IMAGES_FOLDER}")
    else:
        img_count = len(list(Path(IMAGES_FOLDER).glob("*.jpg")) + list(Path(IMAGES_FOLDER).glob("*.png")))
        print(f"✅ Images folder: {img_count} images found")
    
    if not os.path.exists(MASKS_FOLDER):
        errors.append(f"❌ Masks folder not found: {MASKS_FOLDER}")
    else:
        mask_count = len(list(Path(MASKS_FOLDER).glob("*.jpg")) + list(Path(MASKS_FOLDER).glob("*.png")))
        print(f"✅ Masks folder: {mask_count} masks found")
    
    if os.path.exists(COLLAGE_FOLDER):
        collage_count = len(list(Path(COLLAGE_FOLDER).glob("*.jpg")) + list(Path(COLLAGE_FOLDER).glob("*.png")))
        print(f"ℹ️  Collage folder: {collage_count} images (for reference)")
    
    if errors:
        print("\n" + "\n".join(errors))
        print("\n⚠️  Please check the folder paths in the script")
        exit(1)
    
    # Step 1: Convert masks to YOLO annotations
    labels_folder, converted_count = create_yolo_annotations()
    
    if converted_count == 0:
        print("\n❌ No annotations created. Cannot proceed with upload.")
        exit(1)
    
    # Preview some annotations
    preview_annotations()
    
    # Step 2: Upload to Roboflow
    print(f"\n" + "="*60)
    print(f"📋 Configuration:")
    print(f"   Workspace: {WORKSPACE}")
    print(f"   Project: {PROJECT_NAME}")
    print(f"   API Key: {'✅ Configured' if API_KEY != 'YOUR_ROBOFLOW_API_KEY' else '❌ NOT SET'}")
    print(f"="*60)
    
    if API_KEY == "YOUR_ROBOFLOW_API_KEY":
        print("\n⚠️  API KEY NOT CONFIGURED")
        print("\n📝 To upload to Roboflow:")
        print("   1. Get API key from: https://app.roboflow.com → Settings → Roboflow API")
        print("   2. Open this file: upload_to_roboflow.py")
        print("   3. Replace 'YOUR_ROBOFLOW_API_KEY' with your actual key")
        print("   4. Run this script again")
        print(f"\n✅ Annotations saved to: {labels_folder}")
        print("   You can upload them manually from Roboflow web interface")
    else:
        # API key is set, proceed with upload
        response = input("\n🚀 Ready to upload to Roboflow? (y/n): ").strip().lower()
        if response == 'y':
            upload_with_annotations(labels_folder)
            
            print(f"\n" + "="*60)
            print(f"🎯 NEXT STEPS:")
            print(f"="*60)
            print(f"1. Go to: https://app.roboflow.com/{WORKSPACE}/{PROJECT_NAME}")
            print(f"2. Verify the annotations look correct")
            print(f"3. Assign class labels if needed (e.g., digit 0-9)")
            print(f"4. Generate dataset version")
            print(f"5. Train your model")
            print(f"6. Deploy and update your PHP code with model version")
        else:
            print("\n⏭️  Upload cancelled")
            print(f"✅ Annotations saved to: {labels_folder}")

