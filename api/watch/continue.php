<?php
require __DIR__ . '/../../config/config.php';
require BASE_PATH . '/core/Auth.php';

Auth::requireAuth();
header('Content-Type: application/json');

$userId = $_SESSION['user_id'];

$stmt = db()->prepare("
    SELECT 
        anime_id,
        episode,
        progress_seconds,
        duration_seconds,
        updated_at
    FROM user_watch_progress
    WHERE user_id = ?
      AND progress_seconds > 10
    ORDER BY updated_at DESC
    LIMIT 20
");


$stmt->execute([$userId]);
$rows = $stmt->fetchAll();

echo json_encode($rows);
