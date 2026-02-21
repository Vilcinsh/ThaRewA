<?php
declare(strict_types=1);

// Disable error display but log errors
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS, POST');
header('Access-Control-Allow-Headers: Range, Accept, Accept-Encoding, Accept-Language, Origin, Referer, User-Agent, X-Requested-With, Content-Type');
header('Access-Control-Expose-Headers: Content-Length, Content-Range, Content-Type, Accept-Ranges');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Send JSON response
 */
function sendJson(int $code, $data): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send error response
 */
function sendError(int $code, string $message, array $details = []): void {
    sendJson($code, [
        'error' => $message,
        'code' => $code,
        'timestamp' => time(),
        ...$details
    ]);
}

// Get and validate URL parameter
$url = $_GET['url'] ?? $_POST['url'] ?? '';

// Remove any leading '?url=' if it exists (handles double encoding)
$url = preg_replace('/^\?url=/', '', $url);
$url = trim($url);

if (empty($url)) {
    sendError(400, 'Missing URL parameter', ['received' => $_GET]);
}

// Decode if it's URL encoded
if (str_contains($url, '%')) {
    $url = urldecode($url);
}

// Validate URL format
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    sendError(400, 'Invalid URL format', ['url' => $url]);
}

$parsed = parse_url($url);
if (!isset($parsed['scheme']) || !in_array($parsed['scheme'], ['http', 'https'], true)) {
    sendError(400, 'Invalid URL scheme (only http/https allowed)', ['url' => $url]);
}

$host = strtolower($parsed['host'] ?? '');
if (empty($host)) {
    sendError(400, 'Invalid hostname', ['url' => $url]);
}

// Prevent proxy loops
$selfHost = strtolower($_SERVER['HTTP_HOST'] ?? '');
if (!empty($selfHost) && $host === $selfHost) {
    sendError(400, 'Proxy loop detected - cannot proxy to self');
}

// Detect file types
$path = $parsed['path'] ?? '/';
$isM3U8 = preg_match('/\.m3u8(\?.*)?$/i', $path);
$isSegment = preg_match('/\.(ts|m4s|mp4|m4a|aac|key)(\?.*)?$/i', $path);
$isJson = str_contains($path, '/api/') || str_contains($url, 'thatrew.vercel.app');

/**
 * Resolve relative URLs against base URL
 */
function resolveUrl(string $base, string $reference): string {
    // Already absolute
    if (preg_match('~^https?://~i', $reference)) {
        return $reference;
    }

    $baseParts = parse_url($base);
    $scheme = $baseParts['scheme'] ?? 'https';
    $host = $baseParts['host'] ?? '';
    $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
    
    // Absolute path reference
    if (str_starts_with($reference, '//')) {
        return $scheme . ':' . $reference;
    }
    
    if (str_starts_with($reference, '/')) {
        return $scheme . '://' . $host . $port . $reference;
    }
    
    // Relative reference
    $basePath = $baseParts['path'] ?? '/';
    $baseDir = preg_replace('~/[^/]*$~', '/', $basePath);
    $combined = $baseDir . $reference;
    
    // Normalize path (remove .. and .)
    $segments = explode('/', $combined);
    $normalized = [];
    
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($normalized);
            continue;
        }
        $normalized[] = $segment;
    }
    
    $finalPath = '/' . implode('/', $normalized);
    
    // Preserve query string if present in reference
    if (str_contains($reference, '?')) {
        $refQuery = parse_url($reference, PHP_URL_QUERY);
        if ($refQuery) {
            $finalPath .= '?' . $refQuery;
        }
    }
    
    return $scheme . '://' . $host . $port . $finalPath;
}

/**
 * Get proxy self URL
 */
function getProxyUrl(): string {
    $script = $_SERVER['SCRIPT_NAME'] ?? '/proxy.php';
    // Remove any query parameters from script name
    return explode('?', $script)[0];
}

/**
 * Build request headers for upstream
 */
function buildRequestHeaders(bool $isJson = false, string $referer = ''): array {
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept-Language: en-US,en;q=0.9',
        'Accept-Encoding: gzip, deflate, br',
        'Connection: keep-alive',
        'DNT: 1',
    ];
    
    if ($isJson) {
        $headers[] = 'Accept: application/json, text/plain, */*';
    } else {
        $headers[] = 'Accept: */*';
    }
    
    // Add referer for video sources
    if ($referer) {
        $headers[] = 'Referer: ' . $referer;
    }
    
    // Forward Range header for partial content requests
    if (!empty($_SERVER['HTTP_RANGE'])) {
        $headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
    }
    
    return $headers;
}

/**
 * Perform the upstream request and return response
 */
function fetchUpstream(string $url, array $headers): array {
    $ch = curl_init($url);
    
    $responseHeaders = [];
    $responseStatus = 200;
    
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_NOSIGNAL => 1,
        CURLOPT_ENCODING => '', // Auto-handle compression
        CURLOPT_HEADER => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_BUFFERSIZE => 16384,
    ]);
    
    // Capture response headers
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$responseHeaders, &$responseStatus) {
        $len = strlen($header);
        $header = trim($header);
        
        if (empty($header)) {
            return $len;
        }
        
        // Parse status line
        if (preg_match('/^HTTP\/\d\.\d\s+(\d+)/', $header, $matches)) {
            $responseStatus = (int)$matches[1];
            return $len;
        }
        
        // Parse header
        $parts = explode(':', $header, 2);
        if (count($parts) === 2) {
            $name = strtolower(trim($parts[0]));
            $value = trim($parts[1]);
            $responseHeaders[$name] = $value;
        }
        
        return $len;
    });
    
    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    
    curl_close($ch);
    
    if ($body === false) {
        sendError(502, 'Upstream request failed', [
            'curl_error' => $curlError,
            'curl_errno' => $curlErrno,
            'url' => $url
        ]);
    }
    
    return [
        'status' => $responseStatus,
        'headers' => $responseHeaders,
        'body' => $body
    ];
}

/**
 * Stream content directly to client
 */
function streamUpstream(string $url, array $headers): void {
    $ch = curl_init($url);
    
    $responseHeaders = [];
    $responseStatus = 200;
    $headersSent = false;
    
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 0, // No timeout for streaming
        CURLOPT_NOSIGNAL => 1,
        CURLOPT_ENCODING => '',
        CURLOPT_HEADER => false,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_BUFFERSIZE => 8192,
    ]);
    
    // Capture and forward headers
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$responseHeaders, &$responseStatus, &$headersSent) {
        $len = strlen($header);
        $header = trim($header);
        
        if (empty($header)) {
            return $len;
        }
        
        if (preg_match('/^HTTP\/\d\.\d\s+(\d+)/', $header, $matches)) {
            $responseStatus = (int)$matches[1];
            return $len;
        }
        
        $parts = explode(':', $header, 2);
        if (count($parts) === 2) {
            $name = strtolower(trim($parts[0]));
            $value = trim($parts[1]);
            $responseHeaders[$name] = $value;
        }
        
        return $len;
    });
    
    // Stream body chunks
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) use (&$headersSent, &$responseStatus, &$responseHeaders) {
        if (!$headersSent) {
            http_response_code($responseStatus);
            
            // Forward essential headers
            if (!empty($responseHeaders['content-type'])) {
                header('Content-Type: ' . $responseHeaders['content-type']);
            } else {
                header('Content-Type: application/octet-stream');
            }
            
            if (!empty($responseHeaders['content-length'])) {
                header('Content-Length: ' . $responseHeaders['content-length']);
            }
            
            if (!empty($responseHeaders['content-range'])) {
                header('Content-Range: ' . $responseHeaders['content-range']);
            }
            
            header('Accept-Ranges: bytes');
            header('Cache-Control: public, max-age=3600');
            
            $headersSent = true;
        }
        
        echo $data;
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
        
        return strlen($data);
    });
    
    $result = curl_exec($ch);
    
    if ($result === false && !$headersSent) {
        sendError(502, 'Stream failed', [
            'error' => curl_error($ch),
            'errno' => curl_errno($ch)
        ]);
    }
    
    curl_close($ch);
}

// Determine referer based on URL
$referer = '';
if (str_contains($url, 'sunshinerays') || str_contains($url, 'sunburst')) {
    $referer = 'https://megacloud.blog/';
}

// Handle JSON API responses
if ($isJson) {
    $response = fetchUpstream($url, buildRequestHeaders(true));
    
    if ($response['status'] !== 200) {
        sendError($response['status'], 'Upstream returned error status', [
            'upstream_status' => $response['status'],
            'url' => $url
        ]);
    }
    
    // Validate and forward JSON
    $jsonData = json_decode($response['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendError(502, 'Invalid JSON from upstream', [
            'json_error' => json_last_error_msg(),
            'preview' => substr($response['body'], 0, 500)
        ]);
    }
    
    sendJson(200, $jsonData);
}

// Handle M3U8 playlists
if ($isM3U8) {
    $response = fetchUpstream($url, buildRequestHeaders(false, $referer));
    
    if ($response['status'] !== 200) {
        sendError($response['status'], 'Upstream returned error status', [
            'upstream_status' => $response['status'],
            'url' => $url,
            'preview' => substr($response['body'], 0, 500)
        ]);
    }
    
    $body = $response['body'];
    
    // More lenient HLS validation - check for common HLS markers
    $hasExtM3U = str_contains($body, '#EXTM3U');
    $hasExtInf = str_contains($body, '#EXTINF');
    $hasStreamInf = str_contains($body, '#EXT-X-STREAM-INF');
    
    if (!$hasExtM3U && !$hasExtInf && !$hasStreamInf) {
        sendError(502, 'Invalid HLS playlist - no valid markers found', [
            'content_preview' => substr($body, 0, 500),
            'content_length' => strlen($body),
            'upstream_status' => $response['status'],
            'content_type' => $response['headers']['content-type'] ?? 'unknown'
        ]);
    }
    
    // Send response headers
    http_response_code(200);
    header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Accept-Ranges: bytes');
    
    // Rewrite playlist URLs
    $proxyUrl = getProxyUrl();
    $lines = preg_split('/\r\n|\r|\n/', $body);
    $output = [];
    
    foreach ($lines as $line) {
        $trimmed = trim($line);
        
        // Keep comments and empty lines
        if (empty($trimmed) || str_starts_with($trimmed, '#')) {
            $output[] = $line;
            continue;
        }
        
        // Rewrite resource URLs
        $absoluteUrl = resolveUrl($url, $trimmed);
        $proxiedUrl = $proxyUrl . '?url=' . urlencode($absoluteUrl);
        $output[] = $proxiedUrl;
    }
    
    echo implode("\n", $output);
    exit;
}

// Stream video segments and other binary content
streamUpstream($url, buildRequestHeaders(false, $referer));