<?php
header('Content-Type: text/html; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$url = $_GET['url'] ?? '';

if (empty($url) || !str_contains($url, 'kodik')) {
    die('Invalid Kodik URL');
}

// Use corsproxy.io to bypass Kodik's IP blocking
$proxyUrl = 'https://corsproxy.io/?' . urlencode($url);

$ch = curl_init($proxyUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER => [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'Accept: text/html',
    ],
]);

$html = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$html) {
    die('Failed to fetch via proxy - HTTP ' . $httpCode);
}

// Add DOCTYPE
if (!str_contains($html, '<!DOCTYPE')) {
    $html = '<!DOCTYPE html>' . "\n" . $html;
}

// Inject auto-skip script
$autoSkipScript = <<<'SCRIPT'
<script>
(function() {
    console.log('🎯 Kodik Ad Skipper Active');
    
    let attempts = 0;
    const interval = setInterval(() => {
        attempts++;
        
        const skipBtn = document.querySelector('.skip_adv');
        const closeBtn = document.querySelector('.adv_close');
        
        if (skipBtn && skipBtn.offsetParent) {
            skipBtn.click();
            console.log('✅ Skip clicked!');
        }
        
        if (closeBtn && closeBtn.offsetParent && closeBtn.style.display !== 'none') {
            closeBtn.click();
            console.log('✅ Close clicked!');
        }
        
        const player = document.querySelector('.creative-player');
        if (player && player.classList.contains('adv-complete')) {
            clearInterval(interval);
            console.log('🎉 Ads done!');
        }
        
        if (attempts > 120) clearInterval(interval);
    }, 500);
    
    // Auto-click after 5 seconds
    setTimeout(() => {
        const skip = document.querySelector('.skip_adv');
        if (skip) skip.click();
    }, 5000);
})();
</script>
SCRIPT;

$html = str_replace('</body>', $autoSkipScript . '</body>', $html);

// Fix relative URLs
$html = preg_replace('/(src|href)=(["\'])\/([^\/])/i', '$1=$2https://kodik.info/$3', $html);

echo $html;