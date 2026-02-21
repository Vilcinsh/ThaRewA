<?php
require __DIR__ . '/config/config.php';
require BASE_PATH . '/core/Auth.php';

Auth::requireAuth();

$userId = Auth::id();

$data = [
    'theme' => $_POST['theme'] ?? 'dark',
    'autoplay' => isset($_POST['autoplay']) ? 1 : 0,
    'preferred_language' => $_POST['preferred_language'] ?? 'sub',
    'show_spoilers' => isset($_POST['show_spoilers']) ? 1 : 0,
];

$stmt = db()->prepare("
    UPDATE user_settings
    SET theme = ?, autoplay = ?, preferred_language = ?, show_spoilers = ?
    WHERE user_id = ?
");

$stmt->execute([
    $data['theme'],
    $data['autoplay'],
    $data['preferred_language'],
    $data['show_spoilers'],
    $userId
]);

log_write('app', 'SETTINGS', 'User updated settings', ['user_id' => $userId]);

header('Location: /settings.php?saved=1');
exit;
