<?php
require __DIR__ . '/../config/config.php';
require BASE_PATH . '/core/Auth.php';

Auth::requireAuth();
Auth::requireAdmin();

$users = db()->query("
    SELECT u.id, u.username, u.email, u.role, u.created_at
    FROM users u
    ORDER BY u.created_at DESC
")->fetchAll();

$headerType = 'admin';
require __DIR__ . '/../modules/header.php';
?>

<link rel="stylesheet" href="/assets/css/admin.css">
<link rel="stylesheet" href="/assets/css/style.css">

<main class="admin-container">

    <div class="admin-header">
        <h1>Admin Panel</h1>
        <a href="/admin/create_user.php" class="btn-primary">+ Create User</a>
    </div>

    <table class="admin-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Username</th>
                <th>Email</th>
                <th>Role</th>
                <th>Created</th>
                    <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= $u['id'] ?></td>
                <td><?= htmlspecialchars($u['username']) ?></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td>
                    <span class="role <?= $u['role'] ?>">
                        <?= strtoupper($u['role']) ?>
                    </span>
                </td>
                <td><?= date('Y-m-d', strtotime($u['created_at'])) ?></td>
                    <td>
                        <a href="/admin/edit_user.php?id=<?= $u['id'] ?>" class="btn-secondary">Edit</a>
                        <a href="/admin/delete_user.php?id=<?= $u['id'] ?>" class="btn-danger" onclick="return confirm('Delete user <?= htmlspecialchars($u['username']) ?>?');">Delete</a>
                    </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

</main>
