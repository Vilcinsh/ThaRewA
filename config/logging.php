<?php
declare(strict_types=1);

define('LOG_PATH', BASE_PATH . '/storage/logs');

function log_write(string $file, string $level, string $message, array $context = []): void
{
    if (!is_dir(LOG_PATH)) {
        mkdir(LOG_PATH, 0755, true);
    }

    $date = date('Y-m-d H:i:s');

    $contextStr = $context
        ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : '';

    $line = "[$date] [$level] $message";
    if ($contextStr) {
        $line .= " | $contextStr";
    }
    $line .= PHP_EOL;

    file_put_contents(
        LOG_PATH . "/$file.log",
        $line,
        FILE_APPEND | LOCK_EX
    );
}
