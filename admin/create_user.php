<?php
require __DIR__ . '/../config/config.php';
require BASE_PATH . '/core/Auth.php';

Auth::requireAuth();
Auth::requireAdmin();

$headerType = 'admin';
require __DIR__ . '/../modules/header.php';
?>

<link rel="stylesheet" href="/assets/css/admin.css">
<link rel="stylesheet" href="/assets/css/style.css">


<main class="admin-container">

    <h1>Create User</h1>

    <form method="POST" action="/admin/store_user.php" class="admin-form">

        <label>Username</label>
        <input type="text" name="username" required>

        <label>Email</label>
        <input type="email" name="email" required>

        <label>Password</label>
        <input type="password" name="password" required>

        <label>Role</label>
        <select name="role">
            <option value="user">User</option>
            <option value="admin">Admin</option>
        </select>

        <button class="btn-primary">Create User</button>
    </form>

</main>
