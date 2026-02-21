<?php
require '../../../config/config.php';
require BASE_PATH . '/core/Auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /landing.php');
    exit;
}

$email = trim((string)($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$remember = !empty($_POST['remember']); 

if ($email === '' || $password === '') {
    header('Location: /landing.php?error=invalid');
    exit;
}

if (!Auth::login($email, $password, $remember)) { 
    header('Location: /landing.php?error=invalid');
    exit;
}

header('Location: /');
exit;
