<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';

/**
 * Abre uma conexão PDO validada com o MySQL.
 *
 * O runtime real já apresentou latência acima de 2s; o timeout de 5s está
 * alinhado ao mysqli canônico. O `SELECT 1` valida a sessão UMA vez, na
 * abertura.
 */
function sv_pdo_connect(): ?PDO
{
    if (DB_HOST === 'localhost' || DB_HOST === '127.0.0.1' || DB_HOST === '') {
        $dsn = sprintf('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=%s;charset=%s', DB_NAME, DB_CHARSET);
    } else {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
    }

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
 * Estado compartilhado da conexão (permite reset explícito).
 *
 * @return array{pdo: ?PDO, failed: bool}
 */
function &sv_pdo_state(): array
{
    static $state = ['pdo' => null, 'failed' => false];
    return $state;
}

/**
 * Retorna conexão PDO saudável.
 *
 * PERF (correção do TTFB da home): a validação `SELECT 1` roda apenas na
 * abertura da conexão. Antes ela era repetida a CADA chamada de sv_pdo() —
 * um round-trip de rede extra por consulta. Com ~10 chamadas por render da
 * home (free shipping x2, 5 redes sociais no rodapé, cupons), isso somava
 * mais de 20 round-trips por página. A morte da sessão continua sendo
 * detectada pelo erro real da query, e sv_pdo_reset() força a reconexão.
 */
function sv_pdo(): ?PDO
{
    $state = &sv_pdo_state();

    if ($state['pdo'] instanceof PDO) {
        return $state['pdo'];
    }

    // Falha nesta requisição já foi registrada: não repete o custo de 2
    // tentativas x 5s de timeout em cada uma das dezenas de chamadas.
    if ($state['failed']) {
        return null;
    }

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $pdo = sv_pdo_connect();
        if ($pdo instanceof PDO) {
            $state['pdo'] = $pdo;
            return $pdo;
        }
        if ($attempt < 2) {
            usleep(150000);
        }
    }

    $state['failed'] = true;
    error_log('sv_pdo unavailable after retry');
    return null;
}

/** Descarta a conexão cacheada e permite nova tentativa. */
function sv_pdo_reset(): void
{
    $state = &sv_pdo_state();
    $state['pdo'] = null;
    $state['failed'] = false;
}
