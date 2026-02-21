<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$API_BASE  = 'https://thatrew.vercel.app/api/v2/hianime';
$PROXY_URL = 'https://corsproxy.io/?';

$cacheDir  = __DIR__ . '/../../storage/cache';
$cacheFile = $cacheDir . '/frontpage.json';
$ttl       = 3600; // 1 hour

@mkdir($cacheDir, 0775, true);

function fetchJson(string $url): ?array {
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 20,
            'header'  => "User-Agent: FrontpageCache/1.0\r\nAccept: application/json\r\n"
        ]
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;
    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}

$now = time();
$exists = is_file($cacheFile);
$isFresh = $exists && (filemtime($cacheFile) !== false) && (($now - filemtime($cacheFile)) < $ttl);

// If cache is missing or stale, refresh it (best-effort)
if (!$isFresh) {
    $live = fetchJson($PROXY_URL . rawurlencode($API_BASE . '/home'));

    if (is_array($live) && ($live['status'] ?? null) === 200) {
        $payload = [
            'status'    => 200,
            'generated' => date('c'),
            'data'      => $live['data'] ?? [],
        ];

        // Atomic write
        $tmp = $cacheFile . '.tmp';
        @file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @rename($tmp, $cacheFile);
        $exists = true;
    }
}

// Serve cache if available; otherwise fallback to live
if ($exists) {
    $raw = file_get_contents($cacheFile);
    if ($raw !== false && trim($raw) !== '') {
        echo $raw;
        exit;
    }
}

http_response_code(503);
echo json_encode(['status' => 503, 'error' => 'No cache available']);
