<?php
require __DIR__ . '/../config/config.php';
require BASE_PATH . '/core/Auth.php';

Auth::requireAuth();
Auth::requireAdmin();

$username = trim($_POST['username'] ?? '');
$email    = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$role     = $_POST['role'] ?? 'user';

if (!$username || !$email || !$password) {
    exit('Invalid input');
}

$hash = password_hash($password, PASSWORD_DEFAULT);

db()->beginTransaction();

try {
    // users
    $stmt = db()->prepare("
        INSERT INTO users (username, email, password_hash, role, status)
        VALUES (?, ?, ?, ?, 'active')
    ");
    $stmt->execute([$username, $email, $hash, $role]);

    $userId = db()->lastInsertId();

    // profile
    db()->prepare("
        INSERT INTO user_profiles (user_id, display_name)
        VALUES (?, ?)
    ")->execute([$userId, $username]);

    // stats
    db()->prepare("
        INSERT INTO user_stats (user_id)
        VALUES (?)
    ")->execute([$userId]);

    // settings
    db()->prepare("
        INSERT INTO user_settings (user_id)
        VALUES (?)
    ")->execute([$userId]);

    db()->commit();

    log_write('admin', 'USER_CREATE', 'Admin created user', [
        'admin_id' => Auth::id(),
        'user_id' => $userId
    ]);

    header('Location: /admin/index.php');
    exit;

} catch (Throwable $e) {
    db()->rollBack();
    throw $e;
}
