<?php
declare(strict_types=1);

require_once BASE_PATH . '/core/Auth.php';

class Guard
{
    public static function auth(): void
    {
        Auth::initSession();
        if (!Auth::check()) {
            header('Location: /landing.php');
            exit;
        }
    }
}