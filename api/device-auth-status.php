<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

$code = $_GET['code'] ?? null;

if (!$code) {
    echo json_encode(['authenticated' => false]);
    exit;
}

$stmt = db()->prepare("
    SELECT user_id
    FROM device_auth_requests
    WHERE device_code = ?
      AND approved_at IS NOT NULL
      AND expires_at > NOW()
    LIMIT 1
");

$stmt->execute([$code]);
$row = $stmt->fetch();

if ($row) {
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$row['user_id'];

    // Cleanup (one-time use)
    db()->prepare("DELETE FROM device_auth_requests WHERE device_code = ?")
        ->execute([$code]);

    echo json_encode(['authenticated' => true]);
    exit;
}

echo json_encode(['authenticated' => false]);
