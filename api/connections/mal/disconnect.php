<?php
require __DIR__ . '/../../../config/config.php';
require BASE_PATH . '/core/Auth.php';

Auth::requireAuth();
$user = Auth::user();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$pdo = db();

function flash_set(string $type, string $msg): void {
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function csrf_validate(): bool {
    return isset($_POST['csrf_token'], $_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /profile.php?tab=connections');
    exit;
}

if (!csrf_validate()) {
    flash_set('error', 'Security check failed (CSRF).');
    header('Location: /profile.php?tab=connections');
    exit;
}

$userId = (int)$user['id'];

$stmt = $pdo->prepare("DELETE FROM user_connections WHERE user_id = ? AND provider = 'myanimelist' LIMIT 1");
$stmt->execute([$userId]);

flash_set('success', 'MyAnimeList disconnected.');
header('Location: /profile.php?tab=connections');
exit;
