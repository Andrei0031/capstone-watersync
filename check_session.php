<?php
require_once 'session_validation.php';

header('Content-Type: application/json');

echo json_encode(['valid' => isSessionValid()]);
?> 