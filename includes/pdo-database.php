<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';

function sv_pdo(): ?PDO
{
    static $pdo = null;
    static $bootstrapped = false;
    if ($bootstrapped) {
        return $pdo;
    }

    $bootstrapped = true;

    try {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (Throwable $e) {
        error_log('sv_pdo unavailable: ' . $e->getMessage());
        $pdo = null;
    }

    return $pdo;
}
