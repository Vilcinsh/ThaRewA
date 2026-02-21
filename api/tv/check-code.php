<?php
/**
 * TV Device Code Status Check API
 * 
 * POST /api/tv/check-code.php
 * Body: { "device_id": "unique-device-identifier", "device_code": "RW7K-9Q2M" }
 * 
 * Returns: 
 *   - Pending:  { "status": "pending" }
 *   - Approved: { "status": "approved", "auth_token": "...", "user": {...} }
 *   - Expired:  { "status": "expired" }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require __DIR__ . '/../../config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$deviceId = trim($input['device_id'] ?? '');
$deviceCode = strtoupper(trim($input['device_code'] ?? ''));

if (empty($deviceId) || empty($deviceCode)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'device_id and device_code are required']);
    exit;
}

// Look up the request
$stmt = db()->prepare("
    SELECT dar.*, u.email, p.display_name
    FROM device_auth_requests dar
    LEFT JOIN users u ON u.id = dar.user_id
    LEFT JOIN user_profiles p ON p.user_id = dar.user_id
    WHERE dar.device_id = ? AND dar.device_code = ?
    LIMIT 1
");
$stmt->execute([$deviceId, $deviceCode]);
$request = $stmt->fetch();

if (!$request) {
    echo json_encode(['status' => 'invalid']);
    exit;
}

// Check if expired
if (strtotime($request['expires_at']) < time() && empty($request['approved_at'])) {
    echo json_encode(['status' => 'expired']);
    exit;
}

// Check if approved
if (!empty($request['approved_at']) && !empty($request['user_id'])) {
    // Generate a long-lived auth token for the TV device
    $authToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $authToken);
    
    // Store in device_auth_tokens table
    $stmt = db()->prepare("
        INSERT INTO device_auth_tokens (user_id, device_id, token_hash, created_at, last_used_at)
        VALUES (?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), last_used_at = NOW()
    ");
    $stmt->execute([$request['user_id'], $deviceId, $tokenHash]);
    
    // Clean up the auth request
    db()->prepare("DELETE FROM device_auth_requests WHERE id = ?")->execute([$request['id']]);
    
    echo json_encode([
        'status' => 'approved',
        'auth_token' => $authToken,
        'user' => [
            'id' => (int)$request['user_id'],
            'email' => $request['email'],
            'display_name' => $request['display_name'] ?? explode('@', $request['email'])[0]
        ]
    ]);
    exit;
}

// Still pending
echo json_encode(['status' => 'pending']);
