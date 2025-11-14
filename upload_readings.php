<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

include 'db.php';

header('Content-Type: application/json');

try {
    if (!isset($_FILES['meter_images'])) {
        throw new Exception('No images uploaded');
    }

    $uploaded = 0;
    $failed = 0;
    $errors = [];

    // Get list of active clients
    $clients_sql = "SELECT id FROM client_list WHERE status = 1";
    $clients_result = $conn->query($clients_sql);
    $client_ids = [];
    while ($row = $clients_result->fetch_assoc()) {
        $client_ids[] = $row['id'];
    }

    if (empty($client_ids)) {
        throw new Exception('No active clients found');
    }

    // Create uploads directory if it doesn't exist
    $upload_dir = 'uploads/meter_readings/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    foreach ($_FILES['meter_images']['tmp_name'] as $key => $tmp_name) {
        try {
            if ($_FILES['meter_images']['error'][$key] !== UPLOAD_ERR_OK) {
                throw new Exception('Upload error for file ' . $_FILES['meter_images']['name'][$key]);
            }

            // Validate file type
            $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
            if (!in_array($_FILES['meter_images']['type'][$key], $allowed_types)) {
                throw new Exception('Invalid file type for ' . $_FILES['meter_images']['name'][$key]);
            }

            // Generate unique filename
            $filename = uniqid('batch_') . '_' . basename($_FILES['meter_images']['name'][$key]);
            $filepath = $upload_dir . $filename;

            // Move uploaded file
            if (!move_uploaded_file($tmp_name, $filepath)) {
                throw new Exception('Failed to save file ' . $_FILES['meter_images']['name'][$key]);
            }

            // Randomly assign to a client (for batch testing)
            // In production, you would need a way to identify which image belongs to which client
            $client_id = $client_ids[array_rand($client_ids)];

            // Insert into pending_meter_readings
            $stmt = $conn->prepare("INSERT INTO pending_meter_readings 
                (client_id, image_path, mobile_upload_id) 
                VALUES (?, ?, ?)");
            
            $mobile_upload_id = uniqid('mob_');
            $relative_path = 'uploads/meter_readings/' . $filename;
            
            $stmt->bind_param("iss", 
                $client_id,
                $relative_path,
                $mobile_upload_id
            );

            if ($stmt->execute()) {
                $uploaded++;
            } else {
                throw new Exception('Database error for ' . $_FILES['meter_images']['name'][$key]);
            }

        } catch (Exception $e) {
            $failed++;
            $errors[] = $e->getMessage();
        }
    }

    echo json_encode([
        'success' => true,
        'message' => "Successfully uploaded {$uploaded} images" . ($failed > 0 ? ", {$failed} failed" : ""),
        'uploaded' => $uploaded,
        'failed' => $failed,
        'errors' => $errors
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} 