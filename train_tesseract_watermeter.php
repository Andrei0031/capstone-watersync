<?php
/**
 * Tesseract Training Helper Script for Water Meter OCR
 * This script helps you prepare training data and test Tesseract OCR
 */

// Configuration - Auto-detect Tesseract installation
$tesseractPath = null;
$possiblePaths = [
    'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',  // 64-bit default
    'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',  // 32-bit
    'C:\\Tesseract-OCR\\tesseract.exe',  // Custom location
    'tesseract',  // From PATH
];

// Try to find Tesseract
foreach ($possiblePaths as $path) {
    if (strpos($path, '\\') !== false && file_exists($path)) {
        $tesseractPath = $path;
        break;
    }
    // Test command execution
    $testOutput = [];
    $testReturn = 0;
    $testCmd = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') 
        ? "\"$path\" --version 2>nul" 
        : "$path --version 2>/dev/null";
    @exec($testCmd, $testOutput, $testReturn);
    if ($testReturn === 0) {
        $tesseractPath = $path;
        break;
    }
}

$trainingDataDir = __DIR__ . '/tesseract_training/';
$imagesDir = $trainingDataDir . 'images/';
$groundTruthDir = $trainingDataDir . 'ground_truth/';

// Create directories if they don't exist
if (!file_exists($trainingDataDir)) {
    mkdir($trainingDataDir, 0777, true);
}
if (!file_exists($imagesDir)) {
    mkdir($imagesDir, 0777, true);
}
if (!file_exists($groundTruthDir)) {
    mkdir($groundTruthDir, 0777, true);
}

$action = $_GET['action'] ?? 'test';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tesseract Training Helper - Water Meter OCR</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #007bff;
            padding-bottom: 10px;
        }
        h2 {
            color: #555;
            margin-top: 30px;
        }
        .nav {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        .nav a {
            padding: 10px 20px;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 4px;
        }
        .nav a:hover {
            background: #0056b3;
        }
        .nav a.active {
            background: #28a745;
        }
        .upload-area {
            border: 2px dashed #007bff;
            padding: 40px;
            text-align: center;
            border-radius: 8px;
            margin: 20px 0;
        }
        .upload-area:hover {
            background: #f8f9fa;
        }
        input[type="file"] {
            margin: 10px 0;
        }
        input[type="text"] {
            padding: 8px;
            width: 300px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        button {
            padding: 10px 20px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        button:hover {
            background: #218838;
        }
        .result {
            margin: 20px 0;
            padding: 15px;
            border-radius: 4px;
        }
        .success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        .info {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #007bff;
            color: white;
        }
        tr:hover {
            background: #f5f5f5;
        }
        .image-preview {
            max-width: 300px;
            max-height: 300px;
            margin: 10px 0;
        }
        code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
        }
        .step {
            background: #e7f3ff;
            padding: 15px;
            margin: 15px 0;
            border-left: 4px solid #007bff;
            border-radius: 4px;
        }
        .step h3 {
            margin-top: 0;
            color: #007bff;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Tesseract Training Helper - Water Meter OCR</h1>
        
        <div class="nav">
            <a href="?action=test" class="<?= $action === 'test' ? 'active' : '' ?>">Test OCR</a>
            <a href="?action=upload" class="<?= $action === 'upload' ? 'active' : '' ?>">Upload Training Images</a>
            <a href="?action=list" class="<?= $action === 'list' ? 'active' : '' ?>">View Training Data</a>
            <a href="?action=guide" class="<?= $action === 'guide' ? 'active' : '' ?>">Training Guide</a>
        </div>

        <?php if ($action === 'test'): ?>
            <h2>Test Tesseract OCR</h2>
            <?php if (!$tesseractPath): ?>
                <div class="result error">
                    <h3>❌ Tesseract OCR Not Found</h3>
                    <p><strong>Tesseract OCR is not installed on your system.</strong></p>
                    <p>Please install Tesseract OCR to use this feature.</p>
                    <div class="step">
                        <h3>Quick Installation Steps:</h3>
                        <ol>
                            <li><strong>Download:</strong> <a href="https://github.com/UB-Mannheim/tesseract/wiki" target="_blank">https://github.com/UB-Mannheim/tesseract/wiki</a></li>
                            <li><strong>Install:</strong> Run the installer (tesseract-ocr-w64-setup-5.x.x.exe)</li>
                            <li><strong>Location:</strong> Install to <code>C:\Program Files\Tesseract-OCR\</code></li>
                            <li><strong>Important:</strong> Check "Add to PATH" during installation</li>
                            <li><strong>Restart:</strong> Restart XAMPP/Apache after installation</li>
                            <li><strong>Verify:</strong> Run <code>install_tesseract.bat</code> or refresh this page</li>
                        </ol>
                        <p><strong>See detailed guide:</strong> <a href="INSTALL_TESSERACT_WINDOWS.md" target="_blank">INSTALL_TESSERACT_WINDOWS.md</a></p>
                    </div>
                </div>
            <?php else: ?>
                <p>Upload a water meter image to test OCR accuracy before training.</p>
                <p><strong>Tesseract found at:</strong> <code><?= htmlspecialchars($tesseractPath) ?></code></p>
                
                <form method="POST" enctype="multipart/form-data" action="?action=test">
                    <div class="upload-area">
                        <h3>Upload Meter Image</h3>
                        <input type="file" name="test_image" accept="image/*" required>
                        <br><br>
                        <label>Expected Reading (for comparison):</label><br>
                        <input type="text" name="expected_reading" placeholder="e.g., 00442" maxlength="5">
                        <br><br>
                        <button type="submit">Test OCR</button>
                    </div>
                </form>

                <?php
                if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['test_image'])) {
                    $imageFile = $_FILES['test_image']['tmp_name'];
                    $expectedReading = $_POST['expected_reading'] ?? '';
                    
                    // Test OCR
                    $outputFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_ocr_' . uniqid();
                    
                    // Build command - escapeshellarg already adds quotes, so don't add extra quotes
                    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                        // Windows: escapeshellarg adds quotes, use directly
                        $command = escapeshellarg($tesseractPath) . ' ' . 
                                   escapeshellarg($imageFile) . ' ' . 
                                   escapeshellarg($outputFile) . ' -l eng --psm 6 2>&1';
                    } else {
                        // Linux/Mac
                        $command = escapeshellarg($tesseractPath) . ' ' . 
                                   escapeshellarg($imageFile) . ' ' . 
                                   escapeshellarg($outputFile) . ' -l eng --psm 6 2>&1';
                    }
                    
                    exec($command, $output, $returnVar);
                    
                    $resultFile = $outputFile . '.txt';
                    $extractedText = '';
                    if (file_exists($resultFile)) {
                        $extractedText = trim(file_get_contents($resultFile));
                        unlink($resultFile);
                    }
                    
                    // Extract meter reading
                    $meterReading = extractMeterReading($extractedText);
                    
                    // Extract all numbers for debugging
                    preg_match_all('/\d+/', $extractedText, $allNumbers);
                    
                    echo '<div class="result">';
                    echo '<h3>OCR Test Results</h3>';
                    echo '<p><strong>Tesseract Path:</strong> <code>' . htmlspecialchars($tesseractPath) . '</code></p>';
                    echo '<p><strong>Extracted Text:</strong><br><code style="word-break: break-all; white-space: pre-wrap; background: #f5f5f5; padding: 10px; display: block; max-height: 200px; overflow-y: auto;">' . htmlspecialchars($extractedText) . '</code></p>';
                    
                    // Show all numbers found
                    if (!empty($allNumbers[0])) {
                        echo '<p><strong>All Numbers Found in Text:</strong> <code style="background: #e7f3ff; padding: 5px;">' . htmlspecialchars(implode(', ', $allNumbers[0])) . '</code></p>';
                    }
                    
                    // Show split digits that could be combined
                    if (!empty($combinedDigits)) {
                        echo '<p><strong>Split Digits Found (could be meter reading):</strong> <code style="background: #fff3cd; padding: 5px;">' . htmlspecialchars(implode(', ', $combinedDigits)) . '</code></p>';
                        echo '<p><strong>💡 Note:</strong> OCR is reading digits separately. Training Tesseract will fix this!</p>';
                    }
                    
                    echo '<p><strong>Detected Reading:</strong> <code style="font-size: 20px; font-weight: bold; color: ' . ($meterReading ? 'green' : 'red') . '; background: ' . ($meterReading ? '#d4edda' : '#f8d7da') . '; padding: 5px 10px; border-radius: 4px;">' . htmlspecialchars($meterReading ?: 'Not found') . '</code></p>';
                    
                    if ($expectedReading) {
                        $match = (strcmp($meterReading, $expectedReading) === 0);
                        echo '<p><strong>Expected Reading:</strong> <code style="font-size: 18px; font-weight: bold;">' . htmlspecialchars($expectedReading) . '</code></p>';
                        echo '<p><strong>Match:</strong> <span style="color: ' . ($match ? 'green' : 'red') . '; font-weight: bold; font-size: 18px;">' . ($match ? '✓ CORRECT' : '✗ INCORRECT') . '</span></p>';
                        
                        if (!$match && !empty($allNumbers[0])) {
                            echo '<p><strong>💡 Tip:</strong> If the reading is not detected correctly, try:</p>';
                            echo '<ul>';
                            echo '<li>Improve image quality (better lighting, focus)</li>';
                            echo '<li>Train Tesseract with similar images</li>';
                            echo '<li>Check if "00792" appears in the extracted text above</li>';
                            echo '</ul>';
                        }
                    }
                    
                    if ($returnVar !== 0) {
                        echo '<p><strong>Error Output:</strong> <code style="color: red;">' . htmlspecialchars(implode(' ', $output)) . '</code></p>';
                    }
                    
                    echo '<p><strong>Image Preview:</strong></p>';
                    echo '<img src="data:image/jpeg;base64,' . base64_encode(file_get_contents($imageFile)) . '" class="image-preview">';
                    
                    echo '</div>';
                }
                ?>
            <?php endif; ?>

        <?php elseif ($action === 'upload'): ?>
            <h2>Upload Training Images</h2>
            <?php if (!$tesseractPath): ?>
                <div class="result error">
                    <h3>⚠️ Tesseract OCR Not Installed</h3>
                    <p>Please install Tesseract OCR first before uploading training images.</p>
                    <p><strong>Download:</strong> <a href="https://github.com/UB-Mannheim/tesseract/wiki" target="_blank">https://github.com/UB-Mannheim/tesseract/wiki</a></p>
                    <p><strong>See:</strong> <a href="INSTALL_TESSERACT_WINDOWS.md" target="_blank">INSTALL_TESSERACT_WINDOWS.md</a> for detailed instructions</p>
                </div>
            <?php else: ?>
            <p>Upload water meter images with their correct readings to build your training dataset.</p>
            
            <form method="POST" enctype="multipart/form-data" action="?action=upload">
                <div class="upload-area">
                    <h3>Upload Training Image</h3>
                    <input type="file" name="training_image" accept="image/*" required>
                    <br><br>
                    <label>Correct Meter Reading (5 digits):</label><br>
                    <input type="text" name="correct_reading" placeholder="e.g., 00442" maxlength="5" pattern="[0-9]{5}" required>
                    <br><br>
                    <label>Additional Context (optional):</label><br>
                    <input type="text" name="context" placeholder="e.g., 00442 m³" style="width: 400px;">
                    <br><br>
                    <button type="submit">Save Training Data</button>
                </div>
            </form>

            <?php
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['training_image'])) {
                $imageFile = $_FILES['training_image']['tmp_name'];
                $correctReading = $_POST['correct_reading'] ?? '';
                $context = $_POST['context'] ?? $correctReading . ' m³';
                
                if (strlen($correctReading) === 5 && ctype_digit($correctReading)) {
                    // Generate unique filename
                    $filename = 'meter_' . date('Ymd_His') . '_' . uniqid();
                    $imagePath = $imagesDir . $filename . '.png';
                    $gtPath = $groundTruthDir . $filename . '.gt.txt';
                    
                    // Save image
                    if (move_uploaded_file($imageFile, $imagePath)) {
                        // Save ground truth
                        file_put_contents($gtPath, $context);
                        
                        echo '<div class="result success">';
                        echo '<h3>✓ Training Data Saved Successfully!</h3>';
                        echo '<p><strong>Image:</strong> ' . basename($imagePath) . '</p>';
                        echo '<p><strong>Ground Truth:</strong> ' . htmlspecialchars($context) . '</p>';
                        echo '<p><strong>Total Training Images:</strong> ' . count(glob($imagesDir . '*.png')) . '</p>';
                        echo '</div>';
                    } else {
                        echo '<div class="result error">Failed to save image.</div>';
                    }
                } else {
                    echo '<div class="result error">Invalid reading format. Must be exactly 5 digits.</div>';
                }
            }
            ?>
            <?php endif; ?>

        <?php elseif ($action === 'list'): ?>
            <h2>Training Data Collection</h2>
            <?php
            $images = glob($imagesDir . '*.png');
            $totalImages = count($images);
            
            echo '<div class="result info">';
            echo '<h3>Training Dataset Status</h3>';
            echo '<p><strong>Total Images:</strong> ' . $totalImages . '</p>';
            echo '<p><strong>Recommended:</strong> 50-100+ images for good accuracy</p>';
            echo '</div>';
            
            if ($totalImages > 0) {
                echo '<table>';
                echo '<tr><th>Image</th><th>Ground Truth</th><th>Actions</th></tr>';
                
                foreach ($images as $imagePath) {
                    $basename = basename($imagePath, '.png');
                    $gtPath = $groundTruthDir . $basename . '.gt.txt';
                    $groundTruth = file_exists($gtPath) ? file_get_contents($gtPath) : 'Not set';
                    
                    echo '<tr>';
                    echo '<td><img src="' . htmlspecialchars($imagePath) . '" style="max-width: 150px; max-height: 150px;"></td>';
                    echo '<td><code>' . htmlspecialchars($groundTruth) . '</code></td>';
                    echo '<td><a href="?action=delete&file=' . urlencode($basename) . '" onclick="return confirm(\'Delete this training data?\')">Delete</a></td>';
                    echo '</tr>';
                }
                
                echo '</table>';
                
                if ($totalImages >= 20) {
                    echo '<div class="step">';
                    echo '<h3>Ready to Train!</h3>';
                    echo '<p>You have ' . $totalImages . ' training images. You can now proceed to train Tesseract.</p>';
                    echo '<p>See the <a href="?action=guide">Training Guide</a> for instructions.</p>';
                    echo '</div>';
                }
            } else {
                echo '<div class="result info">No training images uploaded yet. Start uploading images to build your dataset.</div>';
            }
            
            // Handle delete
            if (isset($_GET['delete'])) {
                $file = $_GET['delete'];
                $imgPath = $imagesDir . $file . '.png';
                $gtPath = $groundTruthDir . $file . '.gt.txt';
                
                if (file_exists($imgPath)) unlink($imgPath);
                if (file_exists($gtPath)) unlink($gtPath);
                
                header('Location: ?action=list');
                exit;
            }
            ?>

        <?php elseif ($action === 'guide'): ?>
            <h2>Complete Tesseract Training Guide</h2>
            
            <div class="step">
                <h3>Step 1: Install Tesseract OCR</h3>
                <p><strong>Windows (XAMPP):</strong></p>
                <ol>
                    <li>Download from: <a href="https://github.com/UB-Mannheim/tesseract/wiki" target="_blank">https://github.com/UB-Mannheim/tesseract/wiki</a></li>
                    <li>Install to: <code>C:\Program Files\Tesseract-OCR\</code></li>
                    <li>Add to PATH (optional but recommended)</li>
                    <li>Verify: Open CMD and run <code>tesseract --version</code></li>
                </ol>
                <p><strong>Update the path in this script:</strong> Edit line 5 of <code>train_tesseract_watermeter.php</code></p>
            </div>

            <div class="step">
                <h3>Step 2: Collect Training Images</h3>
                <p>Use the "Upload Training Images" tab to collect 50-100+ images of water meters.</p>
                <ul>
                    <li>Include various lighting conditions (bright, dim, shadows)</li>
                    <li>Different meter models</li>
                    <li>Clean and dirty meters</li>
                    <li>Different angles</li>
                    <li>Make sure images are clear and focused</li>
                </ul>
            </div>

            <div class="step">
                <h3>Step 3: Install tesstrain (Training Tool)</h3>
                <p><strong>Option A: Using Git (Recommended)</strong></p>
                <pre style="background: #f4f4f4; padding: 15px; border-radius: 4px; overflow-x: auto;">
git clone https://github.com/tesseract-ocr/tesstrain.git
cd tesstrain
</pre>
                
                <p><strong>Option B: Download ZIP</strong></p>
                <p>Download from: <a href="https://github.com/tesseract-ocr/tesstrain" target="_blank">https://github.com/tesseract-ocr/tesstrain</a></p>
            </div>

            <div class="step">
                <h3>Step 4: Prepare Training Data</h3>
                <p>Your training images are saved in:</p>
                <ul>
                    <li><strong>Images:</strong> <code><?= htmlspecialchars($imagesDir) ?></code></li>
                    <li><strong>Ground Truth:</strong> <code><?= htmlspecialchars($groundTruthDir) ?></code></li>
                </ul>
                <p>Copy these files to the tesstrain directory:</p>
                <pre style="background: #f4f4f4; padding: 15px; border-radius: 4px; overflow-x: auto;">
tesstrain/
  data/
    watermeter-ground-truth/
      meter_001.png
      meter_001.gt.txt
      meter_002.png
      meter_002.gt.txt
      ...
</pre>
            </div>

            <div class="step">
                <h3>Step 5: Run Training</h3>
                <p><strong>Windows (PowerShell or CMD):</strong></p>
                <pre style="background: #f4f4f4; padding: 15px; border-radius: 4px; overflow-x: auto;">
cd tesstrain
make training MODEL_NAME=watermeter START_MODEL=eng
</pre>
                <p><strong>Note:</strong> You may need to install Make for Windows or use WSL (Windows Subsystem for Linux)</p>
                <p><strong>Alternative (if Make doesn't work):</strong></p>
                <p>Use the tesstrain.sh script directly or follow the manual training process from Tesseract documentation.</p>
            </div>

            <div class="step">
                <h3>Step 6: Install Trained Model</h3>
                <p>After training completes, you'll get <code>watermeter.traineddata</code></p>
                <ol>
                    <li>Copy the file to: <code>C:\Program Files\Tesseract-OCR\tessdata\</code></li>
                    <li>Update <code>upload_reading.php</code> line 204:</li>
                    <pre style="background: #f4f4f4; padding: 15px; border-radius: 4px; overflow-x: auto;">
$language = 'watermeter'; // Change from 'eng' to your trained model
// Or combine: $language = 'eng+watermeter';
</pre>
                </ol>
            </div>

            <div class="step">
                <h3>Step 7: Test Your Trained Model</h3>
                <p>Use the "Test OCR" tab to verify accuracy with new images.</p>
                <p>Compare detected readings with expected readings to measure accuracy.</p>
            </div>

            <div class="step">
                <h3>Quick Training Script (Alternative Method)</h3>
                <p>If tesstrain is too complex, you can use this simpler approach:</p>
                <ol>
                    <li>Use Tesseract's built-in training tools</li>
                    <li>Follow: <a href="https://tesseract-ocr.github.io/tessdoc/Training-Tesseract.html" target="_blank">Official Training Guide</a></li>
                    <li>Or use online training services</li>
                </ol>
            </div>

            <div class="result info">
                <h3>💡 Tips for Better Accuracy</h3>
                <ul>
                    <li><strong>More data = Better accuracy:</strong> Aim for 100+ images</li>
                    <li><strong>Quality matters:</strong> Use clear, well-lit images</li>
                    <li><strong>Variety is key:</strong> Include different meter models</li>
                    <li><strong>Test frequently:</strong> Test after every 20-30 new images</li>
                    <li><strong>Preprocess images:</strong> Consider image enhancement before OCR</li>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>

<?php
/**
 * Extract meter reading from OCR text (same logic as upload_reading.php)
 */
function extractMeterReading($text) {
    // Normalize text - remove special characters that OCR might mistake
    $normalized = $text;
    // Replace common OCR mistakes: O -> 0, I -> 1, l -> 1, S -> 5, etc.
    $normalized = preg_replace('/\b([O])(?=\d)/i', '0', $normalized);
    $normalized = preg_replace('/\b([I])(?=\d)/i', '1', $normalized);
    // Remove spaces between digits
    $normalized = preg_replace('/(\d)\s+(\d)/', '$1$2', $normalized);
    // Remove all non-digit characters except m³ indicators (fixed regex)
    $normalized = preg_replace('/[^\d\sm³3]/iu', '', $normalized);
    
    // Strategy 1: Look for 5 consecutive digits (most common pattern)
    if (preg_match('/(\d{5})/', $normalized, $matches)) {
        $reading = $matches[1];
        // Verify it's a reasonable reading (00000-99999)
        if (strlen($reading) === 5) {
            return $reading;
        }
    }
    
    // Strategy 2: 5 digits beside m³
    $pattern1 = '/(\d{5})\s*(?:m[³3]|m\s*cubed|m\s*3)/i';
    if (preg_match($pattern1, $text, $matches)) {
        return str_pad($matches[1], 5, '0', STR_PAD_LEFT);
    }
    
    // Strategy 3: m³ followed by 5 digits
    $pattern2 = '/(?:m[³3]|m\s*cubed|m\s*3)\s*(\d{5})/i';
    if (preg_match($pattern2, $text, $matches)) {
        return str_pad($matches[1], 5, '0', STR_PAD_LEFT);
    }
    
    // Strategy 4: Look for 4-6 digit numbers and normalize to 5
    if (preg_match('/(\d{4,6})/', $normalized, $matches)) {
        $reading = $matches[1];
        // Normalize to 5 digits
        if (strlen($reading) == 4) {
            $reading = '0' . $reading;
        } elseif (strlen($reading) == 6) {
            $reading = substr($reading, 0, 5);
        }
        if (strlen($reading) === 5) {
            return $reading;
        }
    }
    
    // Strategy 5: Look for patterns like "00792" with OCR errors (O instead of 0)
    if (preg_match('/([O0]{1,2}\d{3,4})/i', $text, $matches)) {
        $reading = str_replace(['O', 'o'], '0', $matches[1]);
        $reading = preg_replace('/\D/', '', $reading); // Remove non-digits
        if (strlen($reading) >= 4 && strlen($reading) <= 6) {
            $reading = str_pad($reading, 5, '0', STR_PAD_LEFT);
            if (strlen($reading) === 5) {
                return $reading;
            }
        }
    }
    
    // Strategy 6: Look in lines with m³
    $lines = explode("\n", $normalized);
    foreach ($lines as $line) {
        if (preg_match('/m[³3]|m\s*3/i', $line)) {
            if (preg_match('/(\d{4,6})/', $line, $matches)) {
                $reading = $matches[1];
                if (strlen($reading) == 4) {
                    $reading = '0' . $reading;
                } elseif (strlen($reading) == 6) {
                    $reading = substr($reading, 0, 5);
                }
                if (strlen($reading) === 5) {
                    return $reading;
                }
            }
        }
    }
    
    // Strategy 7: Look for split digits like "7 8 2" that should be "00792"
    // Check if we have digits separated by spaces that could form a 5-digit reading
    $lines = explode("\n", $text);
    foreach ($lines as $line) {
        // Look for pattern like "7 8 2" or "0 0 7 9 2"
        if (preg_match('/(\d)\s+(\d)\s+(\d)(?:\s+(\d))?(?:\s+(\d))?/', $line, $matches)) {
            $digits = array_filter(array_slice($matches, 1)); // Get captured digits
            $combined = implode('', $digits);
            if (strlen($combined) >= 3 && strlen($combined) <= 5) {
                $reading = str_pad($combined, 5, '0', STR_PAD_LEFT);
                if (strlen($reading) === 5) {
                    // If it starts with 0, it's likely correct
                    if (substr($reading, 0, 1) === '0' || strlen($combined) == 3) {
                        return $reading;
                    }
                }
            }
        }
    }
    
    // Strategy 8: Extract all numbers and find the one that looks like a meter reading
    preg_match_all('/\d+/', $text, $allNumbers);
    foreach ($allNumbers[0] as $num) {
        if (strlen($num) >= 3 && strlen($num) <= 6) {
            $reading = str_pad($num, 5, '0', STR_PAD_LEFT);
            if (strlen($reading) === 5) {
                // Prefer smaller numbers (likely meter readings) over large ones like 4064
                if ($num < 10000) {
                    return $reading;
                }
            }
        }
    }
    
    // Strategy 9: Look for "7 8 2" or "0 0 7 9 2" pattern specifically (for readings like 00792)
    // Try to find 3-5 digits separated by spaces
    if (preg_match('/(\d)\s+(\d)\s+(\d)(?:\s+(\d))?(?:\s+(\d))?/', $text, $matches)) {
        $digits = [];
        for ($i = 1; $i < count($matches); $i++) {
            if (!empty($matches[$i])) {
                $digits[] = $matches[$i];
            }
        }
        $combined = implode('', $digits);
        if (strlen($combined) >= 3 && strlen($combined) <= 5) {
            $reading = str_pad($combined, 5, '0', STR_PAD_LEFT);
            if (strlen($reading) === 5) {
                // Prefer readings that start with 0 (like 00792)
                if (substr($reading, 0, 1) === '0' || strlen($combined) == 3) {
                    return $reading;
                }
            }
        }
    }
    
    // Strategy 10: Return the first 5-digit number found (but prefer smaller ones)
    if (preg_match_all('/(\d{5})/', $text, $matches)) {
        foreach ($matches[1] as $num) {
            // Prefer numbers starting with 0 or smaller numbers
            if (substr($num, 0, 1) === '0' || intval($num) < 10000) {
                return $num;
            }
        }
        return $matches[1][0]; // Return first if no preference match
    }
    
    return null;
}
?>

