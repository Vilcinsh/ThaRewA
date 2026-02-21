<?php
// Set JSON headers FIRST
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Custom error handler for this API - always return JSON
set_exception_handler(function($e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
});

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

ini_set('session.use_cookies', '0');

// Buffer output to catch any stray HTML
ob_start();

require __DIR__ . '/../../config/config.php';

// Discard any output from config
ob_end_clean();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$deviceId = trim($input['device_id'] ?? '');

if (empty($deviceId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'device_id is required']);
    exit;
}

// Generate a unique 8-character code (format: XXXX-XXXX)
function generateDeviceCode(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // No I, O, 0, 1 to avoid confusion
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return substr($code, 0, 4) . '-' . substr($code, 4, 4);
}

// Delete any existing pending requests for this device
db()->prepare("DELETE FROM device_auth_requests WHERE device_id = ? AND approved_at IS NULL")->execute([$deviceId]);

// Generate unique code (retry if collision)
$maxAttempts = 10;
$deviceCode = null;

for ($i = 0; $i < $maxAttempts; $i++) {
    $code = generateDeviceCode();
    
    // Check if code already exists and is not expired
    $stmt = db()->prepare("SELECT id FROM device_auth_requests WHERE device_code = ? AND expires_at > NOW()");
    $stmt->execute([$code]);
    
    if (!$stmt->fetch()) {
        $deviceCode = $code;
        break;
    }
}

if (!$deviceCode) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not generate unique code']);
    exit;
}

$expiresIn = 600; // 10 minutes
$expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);

// Insert the new request
$stmt = db()->prepare("
    INSERT INTO device_auth_requests (device_id, device_code, expires_at, created_at)
    VALUES (?, ?, ?, NOW())
");
$stmt->execute([$deviceId, $deviceCode, $expiresAt]);

echo json_encode([
    'success' => true,
    'device_code' => $deviceCode,
    'expires_in' => $expiresIn,
    'poll_interval' => 5,
    'activation_url' => 'https://rew.vissnavslikti.lv/activate'
]);