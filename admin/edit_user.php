<?php
require __DIR__ . '/../config/config.php';
require BASE_PATH . '/core/Auth.php';

Auth::requireAuth();
Auth::requireAdmin();

$id = $_GET['id'] ?? null;

if (!$id) {
    exit('User ID missing');
}

$user = db()->prepare("SELECT * FROM users WHERE id = ?");
$user->execute([$id]);
$user = $user->fetch();

if (!$user) {
    exit('User not found');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $role     = $_POST['role'] ?? $user['role'];

    if (!$username || !$email) {
        exit('Invalid input');
    }

    db()->prepare("UPDATE users SET username = ?, email = ?, role = ? WHERE id = ?")
        ->execute([$username, $email, $role, $id]);

    log_write('admin', 'USER_EDIT', 'Admin edited user', [
        'admin_id' => Auth::id(),
        'user_id' => $id
    ]);

    header('Location: /admin/index.php');
    exit;
}

?><!DOCTYPE html>
<html>
<head>
    <title>Edit User</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
<main class="admin-container">
    <h1>Edit User</h1>
    <form method="post">
        <label>Username:<input type="text" name="username" value="<?= htmlspecialchars($user['username']) ?>" required></label><br>
        <label>Email:<input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required></label><br>
        <label>Role:
            <select name="role">
                <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>User</option>
                <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
            </select>
        </label><br>
        <button type="submit">Save</button>
        <a href="/admin/index.php">Cancel</a>
    </form>
</main>
</body>
</html>
