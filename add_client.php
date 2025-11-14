<?php
include 'db.php'; // Include your database connection file

$message = ''; // Initialize message variable

// Fetch all active categories from the database
$category_sql = "SELECT id, name FROM categories";
$category_result = $conn->query($category_sql);

// Generate unique code with format YEAR/NNN, e.g. 2024/001
$year_prefix = date('Y') . '/';
$code = $year_prefix . '001'; // default code

$last_number = 0; // Initialize last_number to avoid undefined variable warning
// Query existing codes starting with current year prefix
$code_sql = "SELECT code FROM client_list WHERE code LIKE '{$year_prefix}%' ORDER BY code DESC LIMIT 1";
$code_result = $conn->query($code_sql);
if ($code_result && $row = $code_result->fetch_assoc()) {
    $last_code = $row['code'];
    // Extract the numeric part after the slash
if (!empty($last_code)) {
    $last_number = (int)substr($last_code, strlen($year_prefix));
}
    $next_number = $last_number + 1;
    if ($next_number > 999) {
        $message = "Maximum client code reached for the year {$year_prefix}. Please contact admin.";
        $messageClass = "alert alert-danger";
    } else {
        $code = $year_prefix . str_pad($next_number, 3, '0', STR_PAD_LEFT);
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $category_id = $_POST['category_id'];
    $firstname = $_POST['firstname'];
    $middlename = $_POST['middlename'];
    $lastname = $_POST['lastname'];
    $contact = $_POST['contact'];
    $address = $_POST['address'];
    $meter_code = $_POST['meter_code'];
    $status = 1;
    $delete_flag = 0;

if (isset($_POST['code'])) {
    // Use the generated code instead of user input
    $code = $_POST['code'];
} else {
    $code = $year_prefix . '001';
}

    // Prepare and execute SQL statement to insert new client
    $stmt = $conn->prepare("INSERT INTO client_list (code, category_id, firstname, middlename, lastname, contact, address, meter_code, status, delete_flag) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sisssssdii", $code, $category_id, $firstname, $middlename, $lastname, $contact, $address, $meter_code, $status, $delete_flag);

    if ($stmt->execute()) {
        $message = "New client added successfully";
        $messageClass = "alert alert-success";
        // Regenerate next code for next client
        $last_number++;
        if ($last_number <= 999) {
            $code = $year_prefix . str_pad($last_number, 3, '0', STR_PAD_LEFT);
        }
    } else {
        $message = "Error: " . $stmt->error;
        $messageClass = "alert alert-danger";
    }

    $stmt->close();
}

// Close database connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="icon" href="logo.png" />
    <title>Add Client</title>
    <!-- Bootstrap CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="card shadow-sm mx-auto" style="max-width: 600px;">
            <div class="card-body">
                <h2 class="card-title text-center text-primary mb-4">Add New Client</h2>
                <?php if (!empty($message)): ?>
                    <div class="<?php echo $messageClass; ?>" role="alert">
                        <?php echo $message; ?>
                    </div>
                <?php endif; ?>
                <form method="post" action="">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="category_id" class="form-label">Category</label>
                            <select id="category_id" name="category_id" class="form-select" required>
                                <?php
                                if ($category_result->num_rows > 0) {
                                    while ($row = $category_result->fetch_assoc()) {
                                        echo "<option value='{$row['id']}'>{$row['name']}</option>";
                                    }
                                } else {
                                    echo "<option value=''>No categories available</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="code" class="form-label">Code</label>
                            <input type="text" id="code" name="code" class="form-control" value="<?php echo htmlspecialchars($code); ?>" readonly />
                        </div>
                        <div class="col-md-4">
                            <label for="firstname" class="form-label">Firstname</label>
                            <input type="text" id="firstname" name="firstname" class="form-control" required />
                        </div>
                        <div class="col-md-4">
                            <label for="middlename" class="form-label">Middlename</label>
                            <input type="text" id="middlename" name="middlename" class="form-control" />
                        </div>
                        <div class="col-md-4">
                            <label for="lastname" class="form-label">Lastname</label>
                            <input type="text" id="lastname" name="lastname" class="form-control" required />
                        </div>
                        <div class="col-md-6">
                            <label for="contact" class="form-label">Contact</label>
                            <input type="text" id="contact" name="contact" class="form-control" required />
                        </div>
                        <div class="col-md-6">
                            <label for="address" class="form-label">Address</label>
                            <input type="text" id="address" name="address" class="form-control" required />
                        </div>
                        <div class="col-md-12">
                            <label for="meter_code" class="form-label">Meter Code</label>
                            <input type="text" id="meter_code" name="meter_code" class="form-control" required />
                        </div>
                    </div>
                    <div class="d-grid mt-4">
                        <button type="submit" class="btn btn-primary btn-lg">Add Client</button>
                    </div>
                </form>
                <a href="view_clients.php" class="btn btn-outline-primary mt-3 w-100">Back to Client List</a>
            </div>
        </div>
    </div>
    <!-- Bootstrap JS Bundle with Popper (optional for some components) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
