<?php
declare(strict_types=1);

class Auth
{
    private const REMEMBER_COOKIE   = 'remember_v2';
    private const COOKIE_DOMAIN     = null;
    private const REMEMBER_YEARS    = 10;
    private const SESSION_LIFETIME  = 86400 * 30; // 30 days

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    public static function initSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = self::isHttps();

        session_set_cookie_params([
            'lifetime' => self::SESSION_LIFETIME,
            'path'     => '/',
            'domain'   => self::COOKIE_DOMAIN ?? '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        ini_set('session.gc_maxlifetime', (string)self::SESSION_LIFETIME);

        session_start();

        // Slide the session cookie expiry forward on every request
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), session_id(), [
                'expires'  => time() + self::SESSION_LIFETIME,
                'path'     => '/',
                'domain'   => self::COOKIE_DOMAIN ?? '',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        // If no active PHP session, try to restore via remember-me cookie
        if (!isset($_SESSION['user_id']) && !empty($_COOKIE[self::REMEMBER_COOKIE])) {
            self::loginFromRememberToken();
        }
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    public static function check(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public static function id(): ?int
    {
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }

    /** Cached user row (joins users + user_profiles). */
    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }

        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $stmt = db()->prepare("
            SELECT u.*, p.display_name, p.bio
            FROM users u
            LEFT JOIN user_profiles p ON p.user_id = u.id
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->execute([self::id()]);
        $cache = $stmt->fetch() ?: null;

        return $cache;
    }

    /**
     * Authenticate a user by email + password.
     *
     * @param bool $remember  Set a long-lived remember-me cookie (default true)
     */
    public static function login(string $email, string $password, bool $remember = true): bool
    {
        $stmt = db()->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        // Rotate session ID to prevent session-fixation
        session_regenerate_id(true);

        $_SESSION['user_id']       = (int)$user['id'];
        $_SESSION['authenticated'] = true;

        if ($remember) {
            self::setRememberMe((int)$user['id']);
        }

        db()->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")
            ->execute([$user['id']]);

        return true;
    }

    public static function logout(): void
    {
        // Revoke the remember-me token from DB and clear the cookie
        $cookieValue = (string)($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        if ($cookieValue !== '') {
            $parsed = self::parseRememberCookie($cookieValue);
            if ($parsed) {
                db()->prepare("DELETE FROM user_remember_tokens WHERE selector = ?")
                    ->execute([$parsed[0]]);
            }
            self::clearRememberCookie();
        }

        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function isAdmin(): bool
    {
        $user = self::user();
        return $user !== null && ($user['role'] ?? null) === 'admin';
    }

    /** Redirect to landing page if not authenticated. */
    public static function requireAuth(): void
    {
        if (!self::check()) {
            header('Location: /landing.php');
            exit;
        }
    }

    /** Return 403 if not an admin. */
    public static function requireAdmin(): void
    {
        if (!self::isAdmin()) {
            http_response_code(403);
            exit('Access denied');
        }
    }

    // -------------------------------------------------------------------------
    // Remember-me internals
    // -------------------------------------------------------------------------

    /**
     * Attempt to log the user in from a remember-me cookie.
     * On success: session is restored and the token is rotated.
     * On failure: token is deleted and cookie is cleared.
     */
    private static function loginFromRememberToken(): bool
    {
        $cookieValue = (string)($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        $parsed      = self::parseRememberCookie($cookieValue);

        if (!$parsed) {
            return false;
        }

        [$selector, $token] = $parsed;

        $stmt = db()->prepare("
            SELECT id, user_id, token_hash
            FROM user_remember_tokens
            WHERE selector = ?
            LIMIT 1
        ");
        $stmt->execute([$selector]);
        $row = $stmt->fetch();

        if (!$row || !hash_equals((string)$row['token_hash'], hash('sha256', $token))) {
            // Token mismatch — possible theft; invalidate everything
            db()->prepare("DELETE FROM user_remember_tokens WHERE selector = ?")
                ->execute([$selector]);
            self::clearRememberCookie();
            return false;
        }

        // Valid — restore the session
        session_regenerate_id(true);
        $_SESSION['user_id']       = (int)$row['user_id'];
        $_SESSION['authenticated'] = true;

        // Rotate: issue a new token to detect cookie theft
        $newToken = bin2hex(random_bytes(32));
        db()->prepare("
            UPDATE user_remember_tokens
            SET token_hash = ?, last_used_at = NOW()
            WHERE id = ?
        ")->execute([hash('sha256', $newToken), $row['id']]);

        self::setRememberCookie($selector, $newToken);

        return true;
    }

    /** Create a new remember-me token pair in the DB and set the cookie. */
    private static function setRememberMe(int $userId): void
    {
        // One active token per user — old ones are removed
        db()->prepare("DELETE FROM user_remember_tokens WHERE user_id = ?")
            ->execute([$userId]);

        $selector = bin2hex(random_bytes(12));
        $token    = bin2hex(random_bytes(32));

        db()->prepare("
            INSERT INTO user_remember_tokens (user_id, selector, token_hash, created_at, last_used_at)
            VALUES (?, ?, ?, NOW(), NOW())
        ")->execute([$userId, $selector, hash('sha256', $token)]);

        self::setRememberCookie($selector, $token);
    }

    /**
     * Write the remember-me cookie.
     * Sends both the PHP setcookie() call AND a raw Set-Cookie header with
     * Max-Age so the cookie survives in every environment / reverse proxy.
     */
    private static function setRememberCookie(string $selector, string $token): void
    {
        $secure = self::isHttps();
        $ttl    = 86400 * 365 * self::REMEMBER_YEARS;
        $value  = 'v2:' . $selector . ':' . $token;

        setcookie(self::REMEMBER_COOKIE, $value, [
            'expires'  => time() + $ttl,
            'path'     => '/',
            'domain'   => self::COOKIE_DOMAIN,
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        // Hard fallback: explicit Max-Age header (some proxies drop `expires`)
        $domainPart = self::COOKIE_DOMAIN ? '; Domain=' . self::COOKIE_DOMAIN : '';
        $securePart = $secure ? '; Secure' : '';
        header(
            'Set-Cookie: ' . self::REMEMBER_COOKIE . '=' . rawurlencode($value)
            . '; Max-Age=' . $ttl
            . '; Path=/'
            . $domainPart
            . $securePart
            . '; HttpOnly; SameSite=Lax',
            false
        );
    }

    private static function clearRememberCookie(): void
    {
        $secure     = self::isHttps();
        $domainPart = self::COOKIE_DOMAIN ? '; Domain=' . self::COOKIE_DOMAIN : '';
        $securePart = $secure ? '; Secure' : '';

        setcookie(self::REMEMBER_COOKIE, '', [
            'expires'  => time() - 9_999_600,
            'path'     => '/',
            'domain'   => self::COOKIE_DOMAIN,
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        header(
            'Set-Cookie: ' . self::REMEMBER_COOKIE . '=; Max-Age=0; Path=/'
            . $domainPart . $securePart . '; HttpOnly; SameSite=Lax',
            false
        );

        unset($_COOKIE[self::REMEMBER_COOKIE]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Parse a remember-me cookie value.
     * Supports both "v2:selector:token" and legacy "selector:token".
     *
     * @return array{0: string, 1: string}|null  [selector, token] or null
     */
    private static function parseRememberCookie(string $value): ?array
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $parts = explode(':', $value, 3);

        // New format: v2:selector:token
        if (count($parts) === 3 && $parts[0] === 'v2') {
            $selector = trim($parts[1]);
            $token    = trim($parts[2]);
            return ($selector !== '' && $token !== '') ? [$selector, $token] : null;
        }

        // Legacy format: selector:token (exactly 2 parts)
        $parts = explode(':', $value, 2);
        if (count($parts) === 2) {
            $selector = trim($parts[0]);
            $token    = trim($parts[1]);
            return ($selector !== '' && $token !== '') ? [$selector, $token] : null;
        }

        return null;
    }

    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        if (!empty($_SERVER['HTTP_CF_VISITOR'])) {
            $v = json_decode((string)$_SERVER['HTTP_CF_VISITOR'], true);
            if (is_array($v) && ($v['scheme'] ?? '') === 'https') {
                return true;
            }
        }
        return false;
    }
}