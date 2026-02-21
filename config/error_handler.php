<?php
declare(strict_types=1);

/* PHP Errors */
set_error_handler(function (
    int $severity,
    string $message,
    string $file,
    int $line
) {
    log_write('error', 'PHP', $message, [
        'file' => $file,
        'line' => $line,
        'severity' => $severity
    ]);

    if (APP_DEBUG) {
        echo "<pre>PHP ERROR: $message\n$file:$line</pre>";
    }

    return true; // prevent default handler
});

/* Exceptions */
set_exception_handler(function (Throwable $e) {
    log_write('error', 'EXCEPTION', $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);

    if (APP_DEBUG) {
        echo "<pre>UNCAUGHT EXCEPTION:\n{$e->getMessage()}</pre>";
    } else {
        http_response_code(500);
        echo 'Internal Server Error';
    }
});

/* Fatal errors (shutdown) */
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR])) {
        log_write('error', 'FATAL', $error['message'], [
            'file' => $error['file'],
            'line' => $error['line']
        ]);
    }
});
