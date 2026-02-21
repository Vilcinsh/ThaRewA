<?php
require BASE_PATH . '/core/Auth.php';
Auth::requireAuth();

if (!isset($_FILES['avatar'])) {
    exit('No file');
}

$file = $_FILES['avatar'];
$allowed = ['image/jpeg','image/png','image/webp'];

if (!in_array($file['type'], $allowed, true)) {
    exit('Invalid file type');
}

$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = 'avatar_' . Auth::id() . '.' . $ext;

$path = BASE_PATH . '/public/uploads/avatars/';
if (!is_dir($path)) mkdir($path, 0755, true);

move_uploaded_file($file['tmp_name'], $path . $filename);

db()->prepare("UPDATE users SET avatar = ? WHERE id = ?")
    ->execute(['/uploads/avatars/' . $filename, Auth::id()]);

log_write('app', 'AVATAR', 'Avatar updated', ['user_id' => Auth::id()]);

header('Location: /profile.php');
