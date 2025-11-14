<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: adminlogin.php");
    exit();
}

include 'db.php'; // Include your database connection file

if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['id'])) {
    $client_id = $_GET['id'];

    // Fetch client information based on the client_id
    $stmt = $conn->prepare("SELECT * FROM client_list WHERE id = ?");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8" />
            <meta name="viewport" content="width=device-width, initial-scale=1" />
            <title>Client Details - Water Billing System</title>
            
            <!-- Bootstrap 5 CSS -->
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />

            <!-- Google Fonts & Icons -->
            <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700" rel="stylesheet" />
            <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" />

            <style>
                body {
                    font-family: 'Open Sans', sans-serif;
                    background-color: #f8f9fa;
                }
                .sidebar {
                    height: 100vh;
                    background-color: #fff;
                    border-right: 1px solid #dee2e6;
                    padding-top: 20px;
                    position: fixed;
                    width: 250px;
                }
                .sidebar a {
                    padding: 10px 20px;
                    display: block;
                    color: #333;
                    font-weight: 600;
                    text-decoration: none;
                    border-radius: 12px;
                    margin-bottom: 8px;
                }
                .sidebar a:hover,
                .sidebar a.active {
                    background-color: #f0f2f5;
                    color: #007bff;
                }
                .main-content {
                    margin-left: 250px;
                    padding: 30px;
                }
                .card-soft {
                    border-radius: 20px;
                    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
                    border: none;
                }
                .back-link {
                    margin-top: 20px;
                }
            </style>
        </head>
        <body>

        <!-- Sidebar -->
        <div class="sidebar">
            <div class="text-center mb-4">
                <img src="icons/Logo.png" alt="Water Billing Logo" class="img-fluid" style="max-height: 90px;" />
            </div>
            <a href="adminlandingpage.php"><i class="fas fa-home me-2"></i>Dashboard</a>
            <a href="view_clients.php" class="active"><i class="fas fa-users me-2"></i>Customers</a>
            <a href="billing_list.php"><i class="fas fa-file-invoice-dollar me-2"></i>Bills</a>
            <a href="payments.php"><i class="fas fa-money-bill-wave me-2"></i>Payments</a>
            <a href="customer_accounts.php"><i class="fas fa-user-circle me-2"></i>Customer Accounts</a>
            <a href="client_reports.php"><i class="fas fa-chart-line me-2"></i>Reports</a>
            <form method="POST" action="logout.php" class="mt-4 text-center">
                <button type="submit" class="btn btn-outline-primary">Logout</button>
            </form>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <h2 class="text-primary mb-4">Client Details</h2>

            <div class="card card-soft p-4">
                <div class="row mb-3">
                    <div class="col-md-3 fw-bold">ID:</div>
                    <div class="col-md-9"><?php echo htmlspecialchars($row['id']); ?></div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-3 fw-bold">Code:</div>
                    <div class="col-md-9"><?php echo htmlspecialchars($row['code']); ?></div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-3 fw-bold">Category ID:</div>
                    <div class="col-md-9"><?php echo htmlspecialchars($row['category_id']); ?></div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-3 fw-bold">Firstname:</div>
                    <div class="col-md-9"><?php echo htmlspecialchars($row['firstname']); ?></div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-3 fw-bold">Lastname:</div>
                    <div class="col-md-9"><?php echo htmlspecialchars($row['lastname']); ?></div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-3 fw-bold">Contact:</div>
                    <div class="col-md-9"><?php echo htmlspecialchars($row['contact']); ?></div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-3 fw-bold">Address:</div>
                    <div class="col-md-9"><?php echo htmlspecialchars($row['address']); ?></div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-3 fw-bold">Meter Code:</div>
                    <div class="col-md-9"><?php echo htmlspecialchars($row['meter_code']); ?></div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-3 fw-bold">Status:</div>
                    <div class="col-md-9"><?php echo $row['status'] == 1 ? 'Active' : 'Inactive'; ?></div>
                </div>

                <a href="view_clients.php" class="btn btn-primary back-link">Back to Client List</a>
            </div>
        </div>

        <!-- Bootstrap 5 JS -->
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        </body>
        </html>
        <?php
    } else {
        echo "Client not found.";
    }

    $stmt->close();
    $conn->close();
} else {
    echo "Invalid request.";
}
?>
