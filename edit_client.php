<?php
include 'db.php'; // Include your database connection file

// Check if client_id is provided via GET request
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['id'])) {
    $client_id = $_GET['id'];

    // Fetch client information based on the client_id
    $stmt = $conn->prepare("SELECT * FROM client_list WHERE id = ?");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();

        // Display client details in an edit form
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <link rel="icon" href="logo.png">
            <title>Edit Client</title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    margin: 0;
                    padding: 0;
                    background-color: #f2f2f2;
                }
                .container {
                    width: 50%;
                    margin: 50px auto;
                    padding: 20px;
                    background-color: #ffffff94;
                    box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
                    border-radius: 10px;
                    position: relative;
                }
                .container img {
                    position: absolute;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    object-fit: cover;
                    z-index: -1;
                    border-radius: 10px;
                }
                h1 {
                    text-align: center;
                    color: #333;
                }
                form {
                    display: flex;
                    flex-direction: column;
                    gap: 15px;
                }
                label {
                    font-weight: bold;
                    color: #555;
                }
                input[type="text"], input[type="submit"] {
                    padding: 10px;
                    border: 1px solid #ddd;
                    border-radius: 5px;
                }
                input[type="submit"] {
                    background-color: #007770;
                    color: white;
                    cursor: pointer;
                    transition: background-color 0.3s;
                }
                input[type="submit"]:hover {
                    background-color: #005f5f;
                }
                .back-link {
                    display: block;
                    text-align: center;
                    margin-top: 20px;
                    padding: 10px 20px;
                    text-decoration: none;
                    color: #fff;
                    background-color: #007770;
                    border-radius: 5px;
                    transition: background-color 0.3s;
                }
                .back-link:hover {
                    background-color: #005f5f;
                }
            </style>
        </head>
        <body>
            <div class="container">
                
                <h1>Edit Client</h1>
                <form action="update_client.php" method="POST">
                    <input type="hidden" name="client_id" value="<?php echo $row['id']; ?>">
                    <label for="code">Code:</label>
                    <input type="text" id="code" name="code" value="<?php echo htmlspecialchars($row['code']); ?>">
                    
                    <label for="category_id">Category ID:</label>
                    <input type="text" id="category_id" name="category_id" value="<?php echo htmlspecialchars($row['category_id']); ?>">
                    
                    <label for="firstname">Firstname:</label>
                    <input type="text" id="firstname" name="firstname" value="<?php echo htmlspecialchars($row['firstname']); ?>">
                    
                    <label for="middlename">Middlename:</label>
                    <input type="text" id="middlename" name="middlename" value="<?php echo htmlspecialchars($row['middlename']); ?>">
                    
                    <label for="lastname">Lastname:</label>
                    <input type="text" id="lastname" name="lastname" value="<?php echo htmlspecialchars($row['lastname']); ?>">
                    
                    <label for="contact">Contact:</label>
                    <input type="text" id="contact" name="contact" value="<?php echo htmlspecialchars($row['contact']); ?>">
                    
                    <label for="address">Address:</label>
                    <input type="text" id="address" name="address" value="<?php echo htmlspecialchars($row['address']); ?>">
                    
                    <label for="meter_code">Meter Code:</label>
                    <input type="text" id="meter_code" name="meter_code" value="<?php echo htmlspecialchars($row['meter_code']); ?>">
                    
                    <input type="submit" value="Update">
                </form>
                <a href="view_clients.php" class="back-link">Back to Client List</a>
            </div>
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
