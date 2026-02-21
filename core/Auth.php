<?php
declare(strict_types=1);

class Auth
{
    // CHANGE COOKIE NAME to avoid any other script overriding "remember"
    private const REMEMBER_COOKIE = 'remember_v2';

    // If you want cookie valid across subdomains, set to ".yourdomain.com"
    // If you are not sure, keep null (host-only cookie).
    private const COOKIE_DOMAIN = null; // e.g. '.vissnavslikti.lv'

    private const REMEMBER_YEARS = 10;
    
    // Session lifetime in seconds (30 days)
    private const SESSION_LIFETIME = 86400 * 30;

    /**
     * Initialize session with long lifetime.
     * Call this before session_start() in your init/bootstrap file.
     */
    public static function initSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return; // Already started
        }

        // Set session cookie to last 30 days (not just browser session)
        $secure = self::isHttps();
        session_set_cookie_params([
            'lifetime' => self::SESSION_LIFETIME,
            'path'     => '/',
            'domain'   => self::COOKIE_DOMAIN ?? '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        // Keep session data on server for 30 days
        ini_set('session.gc_maxlifetime', (string)self::SESSION_LIFETIME);

        session_start();

        // Refresh session cookie on every request to extend lifetime
        if (isset($_COOKIE[session_name()])) {
            setcookie(
                session_name(),
                session_id(),
                [
                    'expires'  => time() + self::SESSION_LIFETIME,
                    'path'     => '/',
                    'domain'   => self::COOKIE_DOMAIN ?? '',
                    'secure'   => $secure,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]
            );
        }

        // Auto-restore from remember cookie if session lost
        self::ensure();
    }

    public static function check(): bool
    {
        if (isset($_SESSION['user_id'])) {
            return true;
        }
        self::ensure();
        return isset($_SESSION['user_id']);
    }

    public static function ensure(): void
    {
        if (!isset($_SESSION['user_id']) && !empty($_COOKIE[self::REMEMBER_COOKIE])) {
            self::loginFromRememberToken();
        }
    }

    public static function id(): ?int
    {
        self::ensure();
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }

    public static function user(): ?array
    {
        self::ensure();
        if (!self::check()) return null;

        static $cachedUser = null;
        if ($cachedUser !== null) return $cachedUser;

        $stmt = db()->prepare("
            SELECT u.*, p.display_name, p.bio
            FROM users u
            LEFT JOIN user_profiles p ON p.user_id = u.id
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->execute([self::id()]);
        $cachedUser = $stmt->fetch() ?: null;
        return $cachedUser;
    }

    public static function login(string $email, string $password, bool $remember = true): bool
    {
        $stmt = db()->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);

        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['authenticated'] = true;

        // Always set remember-me to keep users logged in permanently
        self::setRememberMe((int)$user['id']);

        db()->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);

        return true;
    }

    public static function logout(): void
    {
        // Delete token row by selector if cookie exists
        $cookieValue = (string)($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        if ($cookieValue !== '') {
            $parsed = self::parseRememberCookie($cookieValue);
            if ($parsed) {
                [$selector] = $parsed;
                db()->prepare("DELETE FROM user_remember_tokens WHERE selector = ?")->execute([$selector]);
            }
            self::clearRememberCookie();
        }

        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function requireAuth(): void
    {
        if (!self::check()) {
            header('Location: /landing.php');
            exit;
        }
    }

    public static function requireAdmin(): void
    {
        if (!self::isAdmin()) {
            http_response_code(403);
            exit('Access denied');
        }
    }

    public static function isAdmin(): bool
    {
        $user = self::user();
        return $user && ($user['role'] ?? null) === 'admin';
    }

    /**
     * ========== Remember me ==========
     * Cookie format: v2:selector:token
     * DB stores selector + sha256(token)
     */

    private static function loginFromRememberToken(): bool
    {
        $cookieValue = (string)($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        $parsed = self::parseRememberCookie($cookieValue);
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

        $tokenHash = hash('sha256', $token);

        if (!$row || !hash_equals((string)$row['token_hash'], $tokenHash)) {
            // invalid => delete and clear
            db()->prepare("DELETE FROM user_remember_tokens WHERE selector = ?")->execute([$selector]);
            self::clearRememberCookie();
            return false;
        }

        // valid => restore session
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$row['user_id'];
        $_SESSION['authenticated'] = true;

        // rotate token
        $newToken = bin2hex(random_bytes(32));
        $newHash  = hash('sha256', $newToken);

        db()->prepare("
            UPDATE user_remember_tokens
            SET token_hash = ?, last_used_at = NOW()
            WHERE id = ?
        ")->execute([$newHash, $row['id']]);

        // re-issue cookie for 10 years
        self::setRememberCookie($selector, $newToken);

        return true;
    }

    private static function setRememberMe(int $userId): void
    {
        // Keep only one token per user to avoid junk
        db()->prepare("DELETE FROM user_remember_tokens WHERE user_id = ?")->execute([$userId]);

        $selector = bin2hex(random_bytes(12));
        $token    = bin2hex(random_bytes(32));
        $hash     = hash('sha256', $token);

        db()->prepare("
            INSERT INTO user_remember_tokens (user_id, selector, token_hash, created_at, last_used_at)
            VALUES (?, ?, ?, NOW(), NOW())
        ")->execute([$userId, $selector, $hash]);

        self::setRememberCookie($selector, $token);
    }

    /**
     * IMPORTANT:
     * - Uses BOTH Expires and Max-Age
     * - Uses unique cookie name (remember_v2) to avoid being overwritten
     */
    private static function setRememberCookie(string $selector, string $token): void
    {
        $secure = self::isHttps();
        $ttl    = 86400 * 365 * self::REMEMBER_YEARS; // seconds
        $value  = 'v2:' . $selector . ':' . $token;

        // PHP setcookie() array format should already handle Expires,
        // but we also set Max-Age by providing it explicitly.
        setcookie(
            self::REMEMBER_COOKIE,
            $value,
            [
                'expires'  => time() + $ttl,
                'path'     => '/',
                'domain'   => self::COOKIE_DOMAIN, // null = host-only
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );

        // Some environments ignore the 'expires' array option unexpectedly.
        // As a hard fallback, also send a raw header with Max-Age.
        // (Safe: same cookie name overwrites itself with correct TTL)
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
        $secure = self::isHttps();

        setcookie(
            self::REMEMBER_COOKIE,
            '',
            [
                'expires'  => time() - 9999600,
                'path'     => '/',
                'domain'   => self::COOKIE_DOMAIN,
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );

        $domainPart = self::COOKIE_DOMAIN ? '; Domain=' . self::COOKIE_DOMAIN : '';
        $securePart = $secure ? '; Secure' : '';
        header(
            'Set-Cookie: ' . self::REMEMBER_COOKIE . '=; Max-Age=0; Path=/' . $domainPart . $securePart . '; HttpOnly; SameSite=Lax',
            false
        );

        unset($_COOKIE[self::REMEMBER_COOKIE]);
    }

    private static function parseRememberCookie(string $cookieValue): ?array
    {
        $cookieValue = trim($cookieValue);
        if ($cookieValue === '') return null;

        $parts = explode(':', $cookieValue);

        // Expected: v2:selector:token
        if (count($parts) === 3 && $parts[0] === 'v2') {
            $selector = trim($parts[1]);
            $token    = trim($parts[2]);
            if ($selector !== '' && $token !== '') {
                return [$selector, $token];
            }
            return null;
        }

        // Backward compatibility: selector:token
        if (count($parts) === 2) {
            $selector = trim($parts[0]);
            $token    = trim($parts[1]);
            if ($selector !== '' && $token !== '') {
                return [$selector, $token];
            }
        }

        return null;
    }

    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') return true;

        if (!empty($_SERVER['HTTP_CF_VISITOR'])) {
            $v = json_decode((string)$_SERVER['HTTP_CF_VISITOR'], true);
            if (is_array($v) && ($v['scheme'] ?? '') === 'https') return true;
        }

        return false;
    }
}
