<?php

function env(string $key, $default = null) {
    static $env = null;

    if ($env === null) {
        $env = [];

        $path = BASE_PATH . '/.env';
        if (!file_exists($path)) {
            return $default;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#')) continue;

            [$k, $v] = array_map('trim', explode('=', $line, 2));
            $env[$k] = trim($v, '"');
        }
    }

    return $env[$key] ?? $default;
}


define('MAL_CLIENT_ID', '9f8dc04f9090b08a7fe892990fac0e92');
define('MAL_CLIENT_SECRET', 'a39a573e6479551d3c030ec44661e14922844ede71a747890c860ff6532fd014');
define('MAL_REDIRECT_URI', 'https://rew.vissnavslikti.lv/api/connections/mal/callback.php');
define('TOKENS_KEY', hex2bin('a39a573e6479551d3c030ec44661e14922844ede71a747890c860ff6532fd014'));
