<?php
include 'db.php'; // Include your database connection file

$message = '';
$messageClass = '';
$client_id = null;
$deletionDone = false;

if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['id'])) {
    $client_id = $_GET['id'];
    // Perform deletion
    $stmt = $conn->prepare("DELETE FROM client_list WHERE id = ?");
    $stmt->bind_param("i", $client_id);

    if ($stmt->execute()) {
        $message = "Client deleted successfully.";
        $messageClass = "alert alert-success";
        $deletionDone = true;
    } else {
        $message = "Error deleting client: " . $stmt->error;
        $messageClass = "alert alert-danger";
    }

    $stmt->close();
    $conn->close();
} else {
    $message = "Invalid request.";
    $messageClass = "alert alert-danger";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="icon" href="logo.png" />
    <title>Delete Client Record</title>
    <!-- Bootstrap CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
</head>
<body>
    <!-- Deletion success message and redirect -->
    <div class="container py-5">
    <div class="<?php echo $messageClass; ?>" role="alert">
        <?php echo htmlspecialchars($message); ?>
    </div>
        <a href="view_clients.php" class="btn btn-primary">Back to Client List</a>
    </div>
    <script>
        // Redirect to view_clients.php after 3 seconds (flash message)
        setTimeout(function() {
            window.location.href = 'view_clients.php';
        }, 3000);
    </script>
    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
