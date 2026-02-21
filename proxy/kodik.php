<?php
$url = "https://proxy.rewcrew.lv/proxy/stream.php?url=http://kodik.info/serial/72976/83328972691f8836c2e998c9b3922ff5/720p";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
]);

$html = curl_exec($ch);
curl_close($ch);

// IMPORTANT: rewrite relative URLs if needed
header("Content-Type: text/html; charset=utf-8");
echo $html;
