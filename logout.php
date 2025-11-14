<?php
session_start();
session_unset(); // clear session variables
session_destroy(); // destroy session
header("Location: adminlogin.php"); // redirect to login page
exit();
