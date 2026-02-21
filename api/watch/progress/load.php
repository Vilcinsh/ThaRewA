<?php
require __DIR__ . '/../../../config/config.php';
require BASE_PATH . '/core/Auth.php';

Auth::requireAuth();

header('Content-Type: application/json');

$userId  = $_SESSION['user_id'] ?? null;
$animeId = $_GET['anime_id'] ?? '';
$episode = isset($_GET['episode']) ? (int)$_GET['episode'] : 0;

if (!$userId || !$animeId || $episode <= 0) {
    http_response_code(400);
    echo json_encode(null);
    exit;
}

$stmt = db()->prepare("
    SELECT 
        progress_seconds,
        duration_seconds,
        completed
    FROM user_watch_progress
    WHERE user_id = ?
      AND anime_id = ?
      AND episode = ?
    LIMIT 1
");

$stmt->execute([$userId, $animeId, $episode]);
$row = $stmt->fetch();

if (!$row) {
    echo json_encode(null);
    exit;
}

/*
 Optional safety:
 - If progress is > duration → clamp
 - Prevent negative values
*/
$row['progress_seconds'] = max(0, (int)$row['progress_seconds']);
$row['duration_seconds'] = max(0, (int)$row['duration_seconds']);
$row['completed']        = (int)$row['completed'];

echo json_encode($row);
