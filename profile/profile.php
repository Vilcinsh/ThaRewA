<?php
require __DIR__ . '/../config/config.php';
require BASE_PATH . '/core/Auth.php';

Auth::requireAuth();
$user = Auth::user();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$pdo = db();

/** ---------- Helpers ---------- */
function e(?string $v): string { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrf_validate(): bool {
    return isset($_POST['csrf_token'], $_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token']);
}
function flash_set(string $type, string $msg): void {
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}
function flash_get(): array {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/** Column exists (robust for evolving schema) */
function column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function load_user(PDO $pdo, int $userId): ?array {
    $cols = ['id', 'username', 'email', 'avatar', 'role', 'status'];
    if (column_exists($pdo, 'users', 'created_at')) $cols[] = 'created_at';

    $sql = "SELECT " . implode(',', $cols) . " FROM users WHERE id = ? LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function load_stats(PDO $pdo, int $userId): array {
    $stats = ['watched' => 0, 'completed' => 0, 'minutes' => 0];

    $stmt = $pdo->prepare("
        SELECT watched_anime, completed_anime, total_watch_minutes
        FROM user_stats
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $stats['watched'] = (int)($row['watched_anime'] ?? 0);
        $stats['completed'] = (int)($row['completed_anime'] ?? 0);
        $stats['minutes'] = (int)($row['total_watch_minutes'] ?? 0);
    }
    return $stats;
}

function load_settings(PDO $pdo, int $userId): array {
    $defaults = [
        'theme' => 'dark',
        'autoplay' => 0,
        'skip_intro' => 0,
        'skip_outro' => 0,
        'preferred_language' => 'en',
        'show_spoilers' => 0,
    ];

    $stmt = $pdo->prepare("SELECT theme, autoplay, skip_intro, skip_outro, preferred_language, show_spoilers FROM user_settings WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) return $defaults;

    return array_merge($defaults, [
        'theme' => (string)($row['theme'] ?? $defaults['theme']),
        'autoplay' => (int)($row['autoplay'] ?? $defaults['autoplay']),
        'skip_intro' => (int)($row['skip_intro'] ?? $defaults['skip_intro']),
        'skip_outro' => (int)($row['skip_outro'] ?? $defaults['skip_outro']),
        'preferred_language' => (string)($row['preferred_language'] ?? $defaults['preferred_language']),
        'show_spoilers' => (int)($row['show_spoilers'] ?? $defaults['show_spoilers']),
    ]);
}

function upsert_settings(PDO $pdo, int $userId, array $s): void {
    $sql = "
        INSERT INTO user_settings (user_id, theme, autoplay, skip_intro, skip_outro, preferred_language, show_spoilers)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            theme = VALUES(theme),
            autoplay = VALUES(autoplay),
            skip_intro = VALUES(skip_intro),
            skip_outro = VALUES(skip_outro),
            preferred_language = VALUES(preferred_language),
            show_spoilers = VALUES(show_spoilers)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $userId,
        $s['theme'],
        (int)$s['autoplay'],
        (int)$s['skip_intro'],
        (int)$s['skip_outro'],
        $s['preferred_language'],
        (int)$s['show_spoilers'],
    ]);
}

function load_connection(PDO $pdo, int $userId, string $provider): ?array {
    try {
        $stmt = $pdo->prepare("SELECT * FROM user_connections WHERE user_id = ? AND provider = ? LIMIT 1");
        $stmt->execute([$userId, $provider]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** ---------- Tabs ---------- */
$allowedTabs = ['overview', 'settings', 'connections'];
$tab = $_GET['tab'] ?? 'overview';
if (!in_array($tab, $allowedTabs, true)) $tab = 'overview';

$userId = (int)$user['id'];

/** ---------- Handle POST actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        flash_set('error', 'Security check failed (CSRF). Please refresh and try again.');
        header('Location: /profile/profile.php?tab=' . urlencode($tab));
        exit;
    }

    $action = $_POST['action'] ?? '';

    $avatarDir = BASE_PATH . '/public/uploads/avatars';
    if (!is_dir($avatarDir)) {
        @mkdir($avatarDir, 0755, true);
    }

    if ($action === 'update_avatar') {
        $newAvatarWebPath = null;

        if (!empty($_POST['remove_avatar'])) {
            if (!empty($user['avatar']) && str_starts_with($user['avatar'], '/uploads/avatars/')) {
                $oldPath = BASE_PATH . '/public' . $user['avatar'];
                if (is_file($oldPath)) @unlink($oldPath);
            }
            $newAvatarWebPath = '';
        }

        if (!empty($_FILES['avatar']['name'])) {
            $file = $_FILES['avatar'];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                flash_set('error', 'Avatar upload failed.');
            } else {
                $maxBytes = 2 * 1024 * 1024;
                if ($file['size'] > $maxBytes) {
                    flash_set('error', 'Avatar is too large. Max 2MB.');
                } else {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->file($file['tmp_name']) ?: '';

                    $allowed = [
                        'image/jpeg' => 'jpg',
                        'image/png'  => 'png',
                        'image/webp' => 'webp',
                    ];

                    if (!isset($allowed[$mime])) {
                        flash_set('error', 'Unsupported avatar format. Use JPG, PNG, or WEBP.');
                    } else {
                        $ext = $allowed[$mime];
                        $filename = 'u' . $userId . '_' . time() . '.' . $ext;
                        $destAbs = $avatarDir . '/' . $filename;

                        if (!move_uploaded_file($file['tmp_name'], $destAbs)) {
                            flash_set('error', 'Could not save uploaded avatar.');
                        } else {
                            if (!empty($user['avatar']) && str_starts_with($user['avatar'], '/uploads/avatars/')) {
                                $oldPath = BASE_PATH . '/public' . $user['avatar'];
                                if (is_file($oldPath)) @unlink($oldPath);
                            }
                            $newAvatarWebPath = '/uploads/avatars/' . $filename;
                        }
                    }
                }
            }
        }

        $hasError = false;
        foreach (($_SESSION['flash'] ?? []) as $f) {
            if (($f['type'] ?? '') === 'error') { $hasError = true; break; }
        }

        if (!$hasError && $newAvatarWebPath !== null) {
            $stmt = $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?");
            $stmt->execute([$newAvatarWebPath, $userId]);
            flash_set('success', 'Avatar updated.');
        } elseif (!$hasError && $newAvatarWebPath === '') {
            $stmt = $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?");
            $stmt->execute(['', $userId]);
            flash_set('success', 'Avatar removed.');
        }

        header('Location: /profile/profile.php?tab=settings');
        exit;
    }

    if ($action === 'save_settings') {
        $theme = in_array($_POST['theme'] ?? 'dark', ['dark', 'light'], true) ? (string)$_POST['theme'] : 'dark';

        $lang = (string)($_POST['preferred_language'] ?? 'en');
        $allowedLang = ['en', 'lv', 'ru'];
        if (!in_array($lang, $allowedLang, true)) $lang = 'en';

        $settings = [
            'theme' => $theme,
            'autoplay' => !empty($_POST['autoplay']) ? 1 : 0,
            'skip_intro' => !empty($_POST['skip_intro']) ? 1 : 0,
            'skip_outro' => !empty($_POST['skip_outro']) ? 1 : 0,
            'preferred_language' => $lang,
            'show_spoilers' => !empty($_POST['show_spoilers']) ? 1 : 0,
        ];

        try {
            upsert_settings($pdo, $userId, $settings);
            flash_set('success', 'Settings saved.');
        } catch (Throwable $e) {
            flash_set('error', 'Could not save settings.');
        }

        header('Location: /profile/profile.php?tab=settings');
        exit;
    }

    if ($action === 'change_password') {
        if (!column_exists($pdo, 'users', 'password')) {
            flash_set('error', 'Password change is not enabled (users.password column not found).');
            header('Location: /profile/profile.php?tab=settings');
            exit;
        }

        $current = (string)($_POST['current_password'] ?? '');
        $new1 = (string)($_POST['new_password'] ?? '');
        $new2 = (string)($_POST['confirm_password'] ?? '');

        if ($new1 !== $new2) {
            flash_set('error', 'New passwords do not match.');
            header('Location: /profile/profile.php?tab=settings');
            exit;
        }
        if (strlen($new1) < 8) {
            flash_set('error', 'New password must be at least 8 characters.');
            header('Location: /profile/profile.php?tab=settings');
            exit;
        }

        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || empty($row['password']) || !password_verify($current, $row['password'])) {
            flash_set('error', 'Current password is incorrect.');
            header('Location: /profile/profile.php?tab=settings');
            exit;
        }

        $hash = password_hash($new1, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hash, $userId]);

        flash_set('success', 'Password updated.');
        header('Location: /profile/profile.php?tab=settings');
        exit;
    }
}

/** ---------- Load data ---------- */
$fresh = load_user($pdo, $userId);
if ($fresh) $user = array_merge($user, $fresh);

$stats = load_stats($pdo, $userId);
$settings = load_settings($pdo, $userId);
$flashes = flash_get();

// Connections
$malConn = load_connection($pdo, $userId, 'myanimelist');

$headerType = 'profile';
require __DIR__ . '/../modules/header.php';
?>
<link rel="stylesheet" href="/assets/css/profile.css">
<link rel="stylesheet" href="/assets/css/style.css">

<main class="profile-container">

    <?php if (!empty($flashes)): ?>
        <section class="profile-flash">
            <?php foreach ($flashes as $f): ?>
                <div class="flash <?= e($f['type'] ?? '') ?>">
                    <?= e($f['msg'] ?? '') ?>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <!-- PROFILE HEADER -->
    <section class="profile-header">
        <img
            src="<?= e(!empty($user['avatar']) ? $user['avatar'] : '/assets/img/avatar-default.png') ?>"
            class="profile-avatar"
            alt="Avatar"
        >

        <div class="profile-info">
            <h1>
                <?= e($user['username'] ?? 'User') ?>
                <?php if (($user['role'] ?? '') === 'admin'): ?>
                    <span class="role-badge">ADMIN</span>
                <?php endif; ?>
            </h1>

            <p class="profile-meta">
                <?= e($user['email'] ?? '') ?>
                <?php if (!empty($user['status'])): ?>
                    • Status: <?= e($user['status']) ?>
                <?php endif; ?>
                <?php if (!empty($user['created_at'])): ?>
                    • Member since <?= e(date('F Y', strtotime((string)$user['created_at']))) ?>
                <?php endif; ?>
            </p>
        </div>
    </section>

    <!-- STATS -->
    <section class="profile-stats">
        <div class="stat-card">
            <span class="stat-value"><?= (int)$stats['watched'] ?></span>
            <span class="stat-label">Watched</span>
        </div>

        <div class="stat-card">
            <span class="stat-value"><?= (int)$stats['completed'] ?></span>
            <span class="stat-label">Completed</span>
        </div>

        <div class="stat-card">
            <span class="stat-value"><?= (int)$stats['minutes'] ?></span>
            <span class="stat-label">Minutes</span>
        </div>
    </section>

    <!-- TABS -->
    <section class="profile-tabs" role="tablist">
        <a class="tab-btn <?= $tab === 'overview' ? 'active' : '' ?>" href="/profile/profile.php?tab=overview">Overview</a>
        <a class="tab-btn <?= $tab === 'settings' ? 'active' : '' ?>" href="/profile/profile.php?tab=settings">Settings</a>
        <a class="tab-btn <?= $tab === 'connections' ? 'active' : '' ?>" href="/profile/profile.php?tab=connections">Connections</a>
    </section>

    <!-- CONTENT -->
    <section class="profile-content">

        <?php if ($tab === 'overview'): ?>
            <div class="profile-card">
                <h2>Account</h2>
                <p><strong>Username:</strong> <?= e($user['username'] ?? '') ?></p>
                <p><strong>Email:</strong> <?= e($user['email'] ?? '') ?></p>
                <p><strong>Role:</strong> <?= e($user['role'] ?? '') ?></p>
                <p><strong>Status:</strong> <?= e($user['status'] ?? '') ?></p>
            </div>

        <?php elseif ($tab === 'settings'): ?>
            <div class="profile-card">
                <h2>Avatar</h2>

                <form method="post" enctype="multipart/form-data" class="profile-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_avatar">

                    <div class="form-row">
                        <label>Upload new avatar</label>
                        <input type="file" name="avatar" accept="image/png,image/jpeg,image/webp">
                        <small>JPG/PNG/WEBP up to 2MB.</small>
                    </div>

                    <div class="form-row">
                        <label style="display:flex;gap:10px;align-items:center;">
                            <input type="checkbox" name="remove_avatar" value="1">
                            Remove current avatar
                        </label>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-primary">Save avatar</button>
                    </div>
                </form>
            </div>

            <div class="profile-card">
                <h2>Player Settings</h2>

                <form method="post" class="profile-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_settings">

                    <div class="form-row">
                        <label>Theme</label>
                        <select name="theme">
                            <option value="dark" <?= ($settings['theme'] === 'dark') ? 'selected' : '' ?>>Dark</option>
                            <option value="light" <?= ($settings['theme'] === 'light') ? 'selected' : '' ?>>Light</option>
                        </select>
                    </div>

                    <div class="form-row">
                        <label>Preferred language</label>
                        <select name="preferred_language">
                            <option value="en" <?= ($settings['preferred_language'] === 'en') ? 'selected' : '' ?>>English</option>
                            <option value="lv" <?= ($settings['preferred_language'] === 'lv') ? 'selected' : '' ?>>Latviešu</option>
                            <option value="ru" <?= ($settings['preferred_language'] === 'ru') ? 'selected' : '' ?>>Русский</option>
                        </select>
                    </div>

                    <div class="form-row">
                        <label style="display:flex;gap:10px;align-items:center;">
                            <input type="checkbox" name="autoplay" value="1" <?= ((int)$settings['autoplay'] === 1) ? 'checked' : '' ?>>
                            Autoplay next episode
                        </label>
                    </div>

                    <div class="form-row">
                        <label style="display:flex;gap:10px;align-items:center;">
                            <input type="checkbox" name="skip_intro" value="1" <?= ((int)$settings['skip_intro'] === 1) ? 'checked' : '' ?>>
                            Skip intro
                        </label>
                    </div>

                    <div class="form-row">
                        <label style="display:flex;gap:10px;align-items:center;">
                            <input type="checkbox" name="skip_outro" value="1" <?= ((int)$settings['skip_outro'] === 1) ? 'checked' : '' ?>>
                            Skip outro
                        </label>
                    </div>

                    <div class="form-row">
                        <label style="display:flex;gap:10px;align-items:center;">
                            <input type="checkbox" name="show_spoilers" value="1" <?= ((int)$settings['show_spoilers'] === 1) ? 'checked' : '' ?>>
                            Show spoilers
                        </label>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-primary">Save settings</button>
                    </div>
                </form>
            </div>

            <?php if (column_exists($pdo, 'users', 'password')): ?>
                <div class="profile-card">
                    <h2>Change Password</h2>

                    <form method="post" class="profile-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="change_password">

                        <div class="form-row">
                            <label>Current password</label>
                            <input type="password" name="current_password" required>
                        </div>

                        <div class="form-row">
                            <label>New password</label>
                            <input type="password" name="new_password" required minlength="8">
                        </div>

                        <div class="form-row">
                            <label>Confirm new password</label>
                            <input type="password" name="confirm_password" required minlength="8">
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn-primary">Update password</button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

        <?php elseif ($tab === 'connections'): ?>
            <div class="profile-card">
                <h2>Connections</h2>

                <div style="display:flex;flex-direction:column;gap:14px;">

                    <!-- MyAnimeList -->
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:16px;padding:14px;border:1px solid rgba(255,255,255,.08);border-radius:12px;">
                        <div>
                            <div style="font-weight:800;font-size:15px;">MyAnimeList</div>
                            <div style="color:rgba(255,255,255,.7);font-size:13px;margin-top:4px;">
                                <?php if ($malConn): ?>
                                    Connected as <strong><?= e($malConn['provider_username'] ?? 'Unknown') ?></strong>
                                    <?php if (!empty($malConn['expires_at'])): ?>
                                        • token expires: <?= e($malConn['expires_at']) ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    Not connected
                                <?php endif; ?>
                            </div>
                        </div>

                        <div style="display:flex;gap:10px;align-items:center;">
                            <?php if (!$malConn): ?>
                                <a class="btn-primary" href="/api/connections/mal/connect.php" style="text-decoration:none;display:inline-flex;align-items:center;gap:8px;">
                                    <span>Connect</span>
                                </a>
                            <?php else: ?>
                                <form method="post" action="/api/connections/mal/disconnect.php" style="margin:0;">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <button type="submit" class="btn-primary" style="background:#b33;">
                                        Disconnect
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div style="color:rgba(255,255,255,.65);font-size:13px;line-height:1.5;">
                        After connecting, we can pull your MAL stats and list statuses to show them inside the watch page (e.g., “Watching/Completed”, score, progress).
                    </div>

                </div>
            </div>
        <?php endif; ?>

    </section>
</main>
