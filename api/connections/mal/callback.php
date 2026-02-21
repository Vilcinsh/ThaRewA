<?php
require __DIR__ . '/../../../config/config.php';
require BASE_PATH . '/core/Auth.php';

Auth::requireAuth();
$user = Auth::user();

if (session_status() !== PHP_SESSION_ACTIVE) {
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

function flash_set(string $type, string $msg): void {
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function go_connections(): void {
    header('Location: /profile/profile.php?tab=connections');
    exit;
}

function b64url(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function encrypt_token(string $plain): string {
    if (!defined('TOKENS_KEY')) throw new RuntimeException('TOKENS_KEY missing.');
    $key = TOKENS_KEY;
    if (!is_string($key) || strlen($key) !== 32) throw new RuntimeException('TOKENS_KEY must be 32 bytes.');
    $iv = random_bytes(16);
    $ct = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($ct === false) throw new RuntimeException('Token encryption failed.');
    return base64_encode($iv . $ct);
}

function http_post_form(string $url, array $data, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_HTTPHEADER => array_merge([
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ], $headers),
        CURLOPT_TIMEOUT => 25,
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $res ?: '', $err ?: ''];
}

function http_get_json(string $url, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
        CURLOPT_TIMEOUT => 25,
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $res ?: '', $err ?: ''];
}

try {
    $code  = (string)($_GET['code'] ?? '');
    $state = (string)($_GET['state'] ?? '');
    $err   = (string)($_GET['error'] ?? '');

    if ($err !== '') {
        mal_log('Callback error param', ['error' => $err, 'desc' => ($_GET['error_description'] ?? '')]);
        flash_set('error', 'MAL connect cancelled/failed: ' . $err);
        go_connections();
    }

    if ($code === '' || $state === '') {
        mal_log('Missing callback params', ['get' => $_GET]);
        flash_set('error', 'MAL connect failed: missing callback parameters.');
        go_connections();
    }

    $stmt = $pdo->prepare("
        SELECT id, user_id, state, code_verifier, code_challenge, redirect_uri, created_at
        FROM oauth_requests
        WHERE provider='myanimelist' AND state = ?
        LIMIT 1
    ");
    $stmt->execute([$state]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        mal_log('No oauth_requests row found for state', [
            'db' => $pdo->query('select database()')->fetchColumn(),
            'state' => $state,
            'state_len' => strlen($state),
        ]);
        flash_set('error', 'MAL connect failed: request not found. Click Connect again.');
        go_connections();
    }

    if ((int)$req['user_id'] !== (int)$user['id']) {
        mal_log('State user mismatch', ['state' => $state, 'row_user' => (int)$req['user_id'], 'auth_user' => (int)$user['id']]);
        flash_set('error', 'MAL connect failed: invalid user.');
        go_connections();
    }

    // TTL 10 minutes
    $createdAt = strtotime((string)$req['created_at']) ?: 0;
    if ($createdAt < (time() - 600)) {
        mal_log('State expired by TTL', ['state' => $state, 'created_at' => $req['created_at']]);
        flash_set('error', 'MAL connect expired. Click Connect again.');
        go_connections();
    }

    $verifier = (string)$req['code_verifier'];
    $storedChallenge = (string)$req['code_challenge'];
    $recalcChallenge = b64url(hash('sha256', $verifier, true));

    // Self-check: if THIS fails, your DB write/read is wrong or overwritten
    if (!hash_equals($storedChallenge, $recalcChallenge)) {
        mal_log('LOCAL PKCE MISMATCH (DB corruption/overwrite)', [
            'state' => $state,
            'stored_ch' => $storedChallenge,
            'recalc_ch' => $recalcChallenge,
            'verifier_len' => strlen($verifier),
        ]);
        flash_set('error', 'MAL connect failed: internal PKCE mismatch (see log).');
        go_connections();
    }

    $redirectUri = (string)$req['redirect_uri'];

    $post = [
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => $redirectUri,
        'code_verifier' => $verifier,
    ];

    $headers = [];

    // If your MAL app is confidential, use Basic auth
    if (defined('MAL_CLIENT_SECRET') && MAL_CLIENT_SECRET !== '') {
        $headers[] = 'Authorization: Basic ' . base64_encode(MAL_CLIENT_ID . ':' . MAL_CLIENT_SECRET);
    } else {
        // Public PKCE needs client_id in body
        $post['client_id'] = (string)MAL_CLIENT_ID;
    }

    [$tStatus, $tBody, $tErr] = http_post_form('https://myanimelist.net/v1/oauth2/token', $post, $headers);

    if ($tStatus < 200 || $tStatus >= 300) {
        mal_log('Token exchange failed', [
            'http' => $tStatus,
            'curl' => $tErr,
            'body' => mb_substr($tBody, 0, 900),
            'redirect_uri' => $redirectUri,
            'has_secret' => (defined('MAL_CLIENT_SECRET') && MAL_CLIENT_SECRET !== '') ? 1 : 0,
            'state_len' => strlen($state),
            'verifier_len' => strlen($verifier),
            'state_col_len' => $stateColLen,
            'verifier_col_len' => $verifierColLen,
            // prefix only, don't leak the full verifier
            'verifier_prefix' => mb_substr($verifier, 0, 12),
            'used_session_fallback' => $usedSessionFallback,
        ]);
        flash_set('error', 'MAL token exchange failed (HTTP ' . $tStatus . ').');
        go_connections();
    }

    $token = json_decode($tBody, true);
    if (!is_array($token) || empty($token['access_token'])) {
        mal_log('Token invalid JSON/shape', ['body' => mb_substr($tBody, 0, 900)]);
        flash_set('error', 'MAL token exchange failed: invalid response.');
        go_connections();
    }

    $accessToken  = (string)$token['access_token'];
    $refreshToken = !empty($token['refresh_token']) ? (string)$token['refresh_token'] : null;

    [$uStatus, $uBody, $uErr] = http_get_json(
        'https://api.myanimelist.net/v2/users/@me',
        ['Authorization: Bearer ' . $accessToken]
    );

    if ($uStatus < 200 || $uStatus >= 300) {
        mal_log('Fetch /users/@me failed', ['http' => $uStatus, 'curl' => $uErr, 'body' => mb_substr($uBody, 0, 700)]);
        flash_set('error', 'MAL connect failed: could not fetch profile (HTTP ' . $uStatus . ').');
        go_connections();
    }

    $me = json_decode($uBody, true);
    if (!is_array($me) || empty($me['id']) || empty($me['name'])) {
        mal_log('Profile invalid', ['body' => mb_substr($uBody, 0, 700)]);
        flash_set('error', 'MAL connect failed: profile response invalid.');
        go_connections();
    }

    $providerUserId   = (string)$me['id'];
    $providerUsername = (string)$me['name'];

    $expiresAt = null;
    if (!empty($token['expires_in'])) {
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->add(new DateInterval('PT' . (int)$token['expires_in'] . 'S'))
            ->format('Y-m-d H:i:s');
    }

    $sql = "
        INSERT INTO user_connections
        (user_id, provider, provider_user_id, provider_username, access_token, refresh_token, token_type, expires_at, scope)
        VALUES
        (?, 'myanimelist', ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            provider_user_id   = VALUES(provider_user_id),
            provider_username  = VALUES(provider_username),
            access_token       = VALUES(access_token),
            refresh_token      = VALUES(refresh_token),
            token_type         = VALUES(token_type),
            expires_at         = VALUES(expires_at),
            scope              = VALUES(scope),
            updated_at         = CURRENT_TIMESTAMP
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        (int)$user['id'],
        $providerUserId,
        $providerUsername,
        encrypt_token($accessToken),
        $refreshToken ? encrypt_token($refreshToken) : null,
        $token['token_type'] ?? 'Bearer',
        $expiresAt,
        $token['scope'] ?? null,
    ]);

    // Consume request row
    $pdo->prepare("DELETE FROM oauth_requests WHERE id = ?")->execute([(int)$req['id']]);

    mal_log('Connected OK', ['user_id' => (int)$user['id'], 'mal_id' => $providerUserId, 'mal_name' => $providerUsername]);
    flash_set('success', 'MyAnimeList connected as ' . $providerUsername . '.');
    go_connections();

} catch (Throwable $e) {
    mal_log('Fatal', ['msg' => $e->getMessage(), 'trace' => mb_substr($e->getTraceAsString(), 0, 1600)]);
    flash_set('error', 'MAL connect failed (server error). Check mal_oauth.log.');
    go_connections();
}

    $stateColLen = null;
    $verifierColLen = null;
    try {
        $stateColLen = $pdo->query("SELECT character_maximum_length
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'oauth_requests'
              AND column_name = 'state'")->fetchColumn();
    } catch (Throwable $e) {
        $stateColLen = null;
    }

    try {
        $verifierColLen = $pdo->query("SELECT character_maximum_length
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'oauth_requests'
              AND column_name = 'verifier'")->fetchColumn();
    } catch (Throwable $e) {
        $verifierColLen = null;
    }
