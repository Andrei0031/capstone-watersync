<?php
$password = "malitbogadmin";
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
echo "Password: " . $password . "<br>";
echo "Hash: " . $hash;
?>