<?php

function db(): PDO {
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = sprintf(
        "mysql:host=%s;dbname=%s;charset=%s",
        env('DB_HOST'),
        env('DB_NAME'),
        env('DB_CHARSET', 'utf8mb4')
    );

    try {
        $pdo = new PDO($dsn, env('DB_USER'), env('DB_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        if (env('APP_DEBUG')) {
            die('DB ERROR: ' . $e->getMessage());
        }
        die('Database connection error.');
    }

    return $pdo;
}
