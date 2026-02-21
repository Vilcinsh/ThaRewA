<?php
require '../../../config/config.php';
require '../../../core/Guard.php';

header('Content-Type: application/json');

if (!Auth::check()) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
  exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$userId = (int)($_SESSION['user_id'] ?? 0);
$animeId = isset($input['anime_id']) ? trim((string)$input['anime_id']) : null;

$provider = strtolower(trim((string)($input['provider'] ?? 'hianime')));
$category = strtolower(trim((string)($input['category'] ?? 'sub')));
$server   = trim((string)($input['server'] ?? ''));
$kodikIdx = isset($input['kodik_index']) ? (int)$input['kodik_index'] : null;

$allowedProviders = ['hianime', 'kodik'];
$allowedCategories = ['sub', 'dub', 'voice', 'rus_sub'];

if (!in_array($provider, $allowedProviders, true)) $provider = 'hianime';
if (!in_array($category, $allowedCategories, true)) $category = 'sub';

if ($server === '') $server = null;
if ($kodikIdx !== null && $kodikIdx < 0) $kodikIdx = null;

$stmt = db()->prepare("
  INSERT INTO user_watch_preferences
    (user_id, anime_id, preferred_provider, preferred_category, preferred_server, kodik_index)
  VALUES
    (:user_id, :anime_id, :provider, :category, :server, :kodik_index)
  ON DUPLICATE KEY UPDATE
    preferred_provider = VALUES(preferred_provider),
    preferred_category = VALUES(preferred_category),
    preferred_server   = VALUES(preferred_server),
    kodik_index        = VALUES(kodik_index),
    updated_at         = CURRENT_TIMESTAMP
");
$stmt->execute([
  ':user_id'     => $userId,
  ':anime_id'    => $animeId,
  ':provider'    => $provider,
  ':category'    => $category,
  ':server'      => $server,
  ':kodik_index' => $kodikIdx,
]);

echo json_encode(['ok' => true]);
