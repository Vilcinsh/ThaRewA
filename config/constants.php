<?php

define('APP_NAME', env('APP_NAME', 'REW Crew Anime'));
define('APP_ENV', env('APP_ENV', 'production'));
define('APP_DEBUG', filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN));
define('APP_URL', env('APP_URL'));
