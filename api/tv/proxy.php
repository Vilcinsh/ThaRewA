<?php

if (!isset($_GET['url'])) {
    http_response_code(400);
    exit('Missing url');
}

$url = $_GET['url'];
if (!preg_match('#^https?://#', $url)) {
    http_response_code(400);
    exit('Invalid url');
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT => 'Mozilla/5.0',
    CURLOPT_HTTPHEADER => [
        'Accept: */*',
        'Referer: https://rew.vissnavslikti.lv/',
        'Origin: https://rew.vissnavslikti.lv'
    ],
]);

$response = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($code >= 400 || $response === false) {
    http_response_code(502);
    exit('Upstream error');
}

/* ===== M3U8 REWRITE ===== */
if (str_contains($type, 'application/vnd.apple.mpegurl') || str_contains($url, '.m3u8')) {

    $base = dirname($url);

    $response = preg_replace_callback(
        '/^(?!#)(.+)$/m',
        function ($m) use ($base) {
            $line = trim($m[1]);
            if ($line === '') return $line;

            if (str_starts_with($line, 'http')) {
                return '/api/tv/proxy.php?url=' . urlencode($line);
            }

            return '/api/tv/proxy.php?url=' . urlencode($base . '/' . $line);
        },
        $response
    );

    header('Content-Type: application/vnd.apple.mpegurl');
} else {
    header('Content-Type: ' . ($type ?: 'application/octet-stream'));
}

header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

echo $response;
