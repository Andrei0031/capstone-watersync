<?php
// API Configuration
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include database connection
// Use __DIR__ to get absolute path regardless of where this file is included from
require_once __DIR__ . '/../db.php';

// API Response Helper
function sendResponse($success, $message, $data = null, $http_code = 200) {
    http_response_code($http_code);
    $response = [
        'success' => $success,
        'message' => $message
    ];
    if ($data !== null) {
        $response['data'] = $data;
    }
    echo json_encode($response);
    exit();
}

/**
 * Helper to check if a table exists. Prevents SQL errors if upgrades
 * have not been applied yet.
 */
function apiTableExists($table_name) {
    global $conn;
    static $cache = [];

    if (isset($cache[$table_name])) {
        return $cache[$table_name];
    }

    $result = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table_name) . "'");
    $exists = $result && $result->num_rows > 0;
    $cache[$table_name] = $exists;
    return $exists;
}

/**
 * Extracts bearer token from headers (Authorization or X-API-Key)
 */
function getAuthorizationToken() {
    $headers = function_exists('getallheaders') ? getallheaders() : [];

    $candidates = [
        'Authorization',
        'authorization',
        'AUTHORIZATION',
        'X-API-Key',
        'x-api-key'
    ];

    $raw = '';
    foreach ($candidates as $candidate) {
        if (!empty($headers[$candidate])) {
            $raw = trim($headers[$candidate]);
            break;
        }
    }

    if (empty($raw) && isset($_GET['api_token'])) {
        $raw = trim($_GET['api_token']);
    }

    if (stripos($raw, 'Bearer ') === 0) {
        return trim(substr($raw, 7));
    }

    return $raw;
}

/**
 * Validates API token against legacy static key or mobile user tokens.
 * Returns metadata about the authenticated principal on success.
 */
function validateApiKey() {
    global $conn;

    $token = getAuthorizationToken();
    if (empty($token)) {
        sendResponse(false, 'Invalid or missing authorization token', null, 401);
    }

    $legacyToken = 'watersync_mobile_2024_new_malitbog';
    if ($token === $legacyToken) {
        $_SERVER['MOBILE_API_USER'] = [
            'token_type' => 'legacy',
            'user_identifier' => 'legacy-mobile-integration'
        ];
        return $_SERVER['MOBILE_API_USER'];
    }

    if (!apiTableExists('mobile_users')) {
        sendResponse(false, 'Mobile accounts not initialized', null, 401);
    }

    $stmt = $conn->prepare("SELECT id, username, full_name, status FROM mobile_users WHERE api_token = ? LIMIT 1");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        sendResponse(false, 'Invalid authorization token', null, 401);
    }

    $user = $result->fetch_assoc();
    if ($user['status'] !== 'active') {
        sendResponse(false, 'Mobile account is inactive', null, 403);
    }

    $_SERVER['MOBILE_API_USER'] = [
        'token_type' => 'mobile_user',
        'user_id' => (int)$user['id'],
        'username' => $user['username'],
        'full_name' => $user['full_name']
    ];

    return $_SERVER['MOBILE_API_USER'];
}

// Get input data
function getInputData() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendResponse(false, 'Invalid JSON data', null, 400);
    }
    return $input;
}

// Validate required fields
function validateRequiredFields($data, $required_fields) {
    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            sendResponse(false, "Missing required field: {$field}", null, 400);
        }
    }
}
?> 