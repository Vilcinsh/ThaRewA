<?php
require __DIR__ . '/../../../config/config.php';
require BASE_PATH . '/core/Auth.php';

Auth::requireAuth();
$user = Auth::user();

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_httponly', '1');
    session_start();
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

function mal_log(string $msg, array $ctx = []): void {
    $line = date('c') . ' ' . $msg;
    if ($ctx) $line .= ' ' . json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    @file_put_contents(__DIR__ . '/mal_oauth.log', $line . PHP_EOL, FILE_APPEND);
}

function b64url(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

try {
    if (!defined('MAL_CLIENT_ID') || MAL_CLIENT_ID === '') {
        throw new RuntimeException('MAL_CLIENT_ID missing');
    }
    if (!defined('MAL_REDIRECT_URI') || MAL_REDIRECT_URI === '') {
        throw new RuntimeException('MAL_REDIRECT_URI missing');
    }

    // PKCE: use HEX verifier (super safe charset)
    // 64 bytes -> 128 hex chars (PKCE allows 43..128)
    $verifier = bin2hex(random_bytes(64)); // exactly 128 chars
    $challenge = b64url(hash('sha256', $verifier, true));
    $state = b64url(random_bytes(32)); // ~43 chars

    // Store NEW request row (never overwrite)
    $stmt = $pdo->prepare("
        INSERT INTO oauth_requests (user_id, provider, state, code_verifier, code_challenge, redirect_uri, created_at)
        VALUES (?, 'myanimelist', ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        (int)$user['id'],
        $state,
        $verifier,
        $challenge,
        (string)MAL_REDIRECT_URI,
    ]);

    mal_log('CONNECT HIT + INSERT OK', [
        'db' => $pdo->query('select database()')->fetchColumn(),
        'user_id' => (int)$user['id'],
        'state' => $state,
        'state_len' => strlen($state),
        'verifier_len' => strlen($verifier),
        'challenge_len' => strlen($challenge),
        'redirect_uri' => (string)MAL_REDIRECT_URI,
        'has_secret' => (defined('MAL_CLIENT_SECRET') && MAL_CLIENT_SECRET !== '') ? 1 : 0,
    ]);

    $params = [
        'response_type' => 'code',
        'client_id' => (string)MAL_CLIENT_ID,
        'redirect_uri' => (string)MAL_REDIRECT_URI,
        'state' => $state,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ];

    $authUrl = 'https://myanimelist.net/v1/oauth2/authorize?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    header('Location: ' . $authUrl);
    exit;

} catch (Throwable $e) {
    mal_log('CONNECT FAILED', ['msg' => $e->getMessage()]);
    header('Location: /profile/profile.php?tab=connections');
    exit;
}
