<?php

ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');

ini_set('session.gc_maxlifetime', '2592000');
ini_set('session.cookie_lifetime', '2592000');

session_set_cookie_params([
    'lifetime' => (int) env('SESSION_LIFETIME', 86400),
    'path' => '/',
    'domain' => '',
    'secure' => isset($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
