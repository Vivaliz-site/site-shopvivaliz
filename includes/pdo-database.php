<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';

/**
 * Abre uma conexão PDO validada com o MySQL.
 *
 * O runtime real já apresentou latência acima de 2s. O timeout anterior de
 * 2s provocava falsos "banco desconectado" no Admin. Usamos 5s, alinhado ao
 * mysqli canônico, e validamos a sessão com SELECT 1 antes de devolvê-la.
 */
function sv_pdo_connect(): ?PDO
{
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $pdo->query('SELECT 1')->fetchColumn();
        return $pdo;
    } catch (Throwable $e) {
        error_log('sv_pdo connection attempt failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Retorna conexão PDO saudável.
 *
 * - tenta até 2 vezes em caso de falha transitória;
 * - não congela permanentemente um NULL após a primeira tentativa;
 * - se uma conexão cacheada morrer durante uma requisição longa, detecta e
 *   reconecta automaticamente.
 */
function sv_pdo(): ?PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        try {
            $pdo->query('SELECT 1')->fetchColumn();
            return $pdo;
        } catch (Throwable $e) {
            error_log('sv_pdo cached connection lost; reconnecting: ' . $e->getMessage());
            $pdo = null;
        }
    }

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $pdo = sv_pdo_connect();
        if ($pdo instanceof PDO) {
            return $pdo;
        }
        if ($attempt < 2) {
            usleep(150000);
        }
    }

    error_log('sv_pdo unavailable after retry');
    return null;
}
