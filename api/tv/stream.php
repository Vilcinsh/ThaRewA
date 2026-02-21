<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require $_SERVER['DOCUMENT_ROOT'] . '/core/Auth.php';

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$animeId = $_GET['id'] ?? null;
$episode = (int)($_GET['ep'] ?? 1);
$lang = $_GET['lang'] ?? 'sub'; // sub | dub | raw

if (!$animeId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing anime id']);
    exit;
}

$API = 'https://thatrew.vercel.app/api/v2/hianime';
$PROXY = $_SERVER['DOCUMENT_ROOT'] . '/proxy.php?url=';

/* ========= HELPERS ========= */
function fetchJSON(string $url): array {
    $res = file_get_contents($url);
    if (!$res) throw new Exception('Fetch failed');
    return json_decode($res, true);
}

/* ========= LOAD EPISODES ========= */
$episodes = fetchJSON("$API/anime/$animeId/episodes");
$ep = $episodes['data']['episodes'][$episode - 1] ?? null;

if (!$ep) {
    http_response_code(404);
    echo json_encode(['error' => 'Episode not found']);
    exit;
}

/* ========= LOAD SERVERS ========= */
$servers = fetchJSON(
    "$API/episode/servers?animeEpisodeId=" . urlencode($ep['episodeId'])
);

$list = $servers['data'][$lang] ?? [];
if (!$list) {
    http_response_code(404);
    echo json_encode(['error' => 'No servers']);
    exit;
}

/* ========= AUTO PICK BEST SERVER ========= */
$priority = ['hd-2', 'vidstreaming', 'vidcloud', 'mycloud', 'animefox', 'kwik'];

$server = null;
foreach ($priority as $p) {
    foreach ($list as $s) {
        if (stripos($s['serverName'], $p) !== false) {
            $server = $s;
            break 2;
        }
    }
}

$server ??= $list[0];

/* ========= LOAD STREAM ========= */
$source = fetchJSON(
    "$API/episode/sources?"
    . "animeEpisodeId=" . urlencode($ep['episodeId'])
    . "&server=" . urlencode($server['serverName'])
    . "&category=" . urlencode($lang)
);

$payload = $source['data'] ?? null;
if (!$payload || empty($payload['sources'])) {
    http_response_code(500);
    echo json_encode(['error' => 'No playable source']);
    exit;
}

/* ========= PICK HLS ========= */
$hls = null;
foreach ($payload['sources'] as $s) {
    if (!empty($s['isM3U8']) || str_contains($s['url'], '.m3u8')) {
        $hls = $s['url'];
        break;
    }
}
$hls ??= $payload['sources'][0]['url'];

/* ========= RESPONSE ========= */
echo json_encode([
    'anime_id' => $animeId,
    'episode' => $episode,
    'language' => $lang,
    'server' => $server['serverName'],
    'stream' => $hls,
    'tracks' => $payload['tracks'] ?? [],
    'intro' => $payload['intro'] ?? null,
    'outro' => $payload['outro'] ?? null
]);
