<?php
require __DIR__ . '/../../config/config.php';
require BASE_PATH . '/core/Guard.php';

header('Content-Type: application/json');

if (!Auth::check()) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
  exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$animeId = isset($_GET['anime_id']) ? trim((string)$_GET['anime_id']) : null;

$stmt = db()->prepare("
  SELECT preferred_provider, preferred_category, preferred_server, kodik_index
  FROM user_watch_preferences
  WHERE user_id = ? AND anime_id <=> ?
  LIMIT 1
");
$stmt->execute([$userId, $animeId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  echo json_encode(['ok' => true, 'data' => null]);
  exit;
}

echo json_encode(['ok' => true, 'data' => $row]);
