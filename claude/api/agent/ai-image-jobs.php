<?php
declare(strict_types=1);

/**
 * ShopVivaliz - AI Image Jobs API
 * Endpoint REST para gerenciar jobs de geração de imagens e sessões A/B.
 *
 * GET  ?health=1                    → health check
 * GET  ?action=list                 → lista jobs de imagem
 * GET  ?action=get_product&sku=X    → busca URL original de um produto
 * POST ?action=register_job         → registra novo job de geração
 * POST ?action=ab_register          → registra sessão A/B
 * GET  ?action=ab_list              → lista sessões A/B
 * GET  ?action=ab_metrics&session_id=X → métricas de uma sessão
 * POST ?action=ab_winner            → declara vencedor A/B
 * POST ?action=track_click          → registra clique em variante (sem auth)
 */

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// ─── Helpers básicos ──────────────────────────────────────────────────────────

function aij_root(): string
{
    return dirname(__DIR__, 2);
}

function aij_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function aij_env_load(string $path): void
{
    if (!is_file($path) || !is_readable($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim(trim($value), "\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

function aij_auth(): void
{
    aij_env_load(aij_root() . '/.env');
    $expected = getenv('SQUAD_TOKEN') ?: '';
    if ($expected === '') {
        aij_json(503, ['ok' => false, 'error' => 'SQUAD_TOKEN não configurado']);
    }
    $received = $_SERVER['HTTP_X_SQUAD_TOKEN'] ?? '';
    if ($received === '' || !hash_equals($expected, $received)) {
        aij_json(401, ['ok' => false, 'error' => 'Não autorizado']);
    }
}

function aij_db(): mysqli
{
    aij_env_load(aij_root() . '/.env');
    foreach ([
        aij_root() . '/config/constants.php',
        aij_root() . '/config/database.php',
        aij_root() . '/config.php',
    ] as $file) {
        if (is_file($file)) {
            try { require_once $file; } catch (Throwable $ignored) {}
        }
    }

    if (class_exists('Database')) {
        $db = Database::getInstance()->getConnection();
        if ($db instanceof mysqli) return $db;
    }

    $host = defined('DB_HOST') ? DB_HOST : (getenv('DB_HOST') ?: 'localhost');
    $port = (int)(defined('DB_PORT') ? DB_PORT : (getenv('DB_PORT') ?: 3306));
    $name = defined('DB_NAME') ? DB_NAME : (getenv('DB_NAME') ?: '');
    $user = defined('DB_USER') ? DB_USER : (getenv('DB_USER') ?: '');
    $pass = defined('DB_PASS') ? DB_PASS : (getenv('DB_PASS') ?: '');

    if ($name === '' || $user === '') {
        aij_json(500, ['ok' => false, 'error' => 'database_config_unavailable']);
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli((string)$host, (string)$user, (string)$pass, (string)$name, $port);
    $db->set_charset('utf8mb4');
    return $db;
}

// ─── Schema ───────────────────────────────────────────────────────────────────

function aij_ensure_schema(mysqli $db): void
{
    $db->query("CREATE TABLE IF NOT EXISTS ai_image_jobs (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        sku           VARCHAR(191)  NULL,
        olist_id      VARCHAR(64)   NULL,
        nome_produto  VARCHAR(255)  NULL,
        original_url  VARCHAR(1000) NULL,
        status        VARCHAR(40)   NOT NULL DEFAULT 'pending',
        vision_model  VARCHAR(80)   NULL,
        image_model   VARCHAR(80)   NULL,
        generated_at  DATETIME      NULL,
        error_message TEXT          NULL,
        created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at    DATETIME      NULL,
        PRIMARY KEY (id),
        KEY idx_aij_sku (sku),
        KEY idx_aij_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS ai_image_job_items (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        job_id      BIGINT UNSIGNED NOT NULL,
        image_type  VARCHAR(40)   NOT NULL,
        prompt      TEXT          NULL,
        site_url    VARCHAR(1000) NULL,
        local_file  VARCHAR(1000) NULL,
        status      VARCHAR(40)   NOT NULL DEFAULT 'pending',
        error       VARCHAR(500)  NULL,
        created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_aiji_job (job_id),
        KEY idx_aiji_type (image_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS ab_test_sessions (
        id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id      VARCHAR(64)   NOT NULL,
        sku             VARCHAR(191)  NULL,
        olist_id        VARCHAR(64)   NULL,
        variant_a_type  VARCHAR(40)   NOT NULL,
        variant_a_url   VARCHAR(1000) NOT NULL,
        variant_b_type  VARCHAR(40)   NOT NULL,
        variant_b_url   VARCHAR(1000) NOT NULL,
        clicks_a        INT           NOT NULL DEFAULT 0,
        clicks_b        INT           NOT NULL DEFAULT 0,
        sales_a         INT           NOT NULL DEFAULT 0,
        sales_b         INT           NOT NULL DEFAULT 0,
        impressions     INT           NOT NULL DEFAULT 0,
        winner_type     VARCHAR(40)   NULL,
        winner_url      VARCHAR(1000) NULL,
        status          VARCHAR(40)   NOT NULL DEFAULT 'running',
        started_at      DATETIME      NULL,
        decided_at      DATETIME      NULL,
        created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME      NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_abs_session (session_id),
        KEY idx_abs_sku (sku),
        KEY idx_abs_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ─── Ações ────────────────────────────────────────────────────────────────────

function aij_list_jobs(mysqli $db): never
{
    $status = isset($_GET['status']) ? (string)$_GET['status'] : '';
    $limit  = min((int)($_GET['limit'] ?? 50), 200);
    $sql    = "SELECT j.*, COUNT(i.id) AS items_total,
               SUM(i.status = 'uploaded') AS items_uploaded,
               SUM(i.status = 'error') AS items_error
               FROM ai_image_jobs j
               LEFT JOIN ai_image_job_items i ON i.job_id = j.id";
    if ($status !== '') {
        $sql    .= " WHERE j.status = ?";
    }
    $sql .= " GROUP BY j.id ORDER BY j.created_at DESC LIMIT $limit";
    $rows = [];

    if ($status !== '') {
        $stmt = $db->prepare($sql);
        $stmt->bind_param('s', $status);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $db->query($sql);
    }

    while ($row = $result->fetch_assoc()) $rows[] = $row;
    aij_json(200, ['ok' => true, 'total' => count($rows), 'jobs' => $rows]);
}

function aij_get_product(mysqli $db): never
{
    $sku      = trim((string)($_GET['sku']      ?? ''));
    $olist_id = trim((string)($_GET['olist_id'] ?? ''));

    $row = null;
    if ($sku !== '') {
        $stmt = $db->prepare('SELECT sku, olist_product_id AS olist_id, name AS nome, primary_image_url FROM olist_products WHERE UPPER(sku) = UPPER(?) LIMIT 1');
        $stmt->bind_param('s', $sku);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
    }
    if (!$row && $olist_id !== '') {
        $stmt = $db->prepare('SELECT sku, olist_product_id AS olist_id, name AS nome, primary_image_url FROM olist_products WHERE olist_product_id = ? OR olist_id = ? LIMIT 1');
        $stmt->bind_param('ss', $olist_id, $olist_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
    }
    if (!$row) {
        aij_json(404, ['ok' => false, 'error' => 'produto não encontrado']);
    }
    aij_json(200, array_merge(['ok' => true], $row ?? []));
}

function aij_register_job(mysqli $db, array $body): never
{
    $sku          = trim((string)($body['sku']          ?? ''));
    $olist_id     = trim((string)($body['olist_id']     ?? ''));
    $nome         = trim((string)($body['nome_produto'] ?? ''));
    $original_url = trim((string)($body['original_url'] ?? ''));
    $items        = $body['images'] ?? [];

    if (!$sku && !$olist_id) {
        aij_json(400, ['ok' => false, 'error' => 'sku ou olist_id obrigatório']);
    }

    $db->begin_transaction();
    try {
        $stmt = $db->prepare('INSERT INTO ai_image_jobs (sku, olist_id, nome_produto, original_url, status, vision_model, image_model, generated_at, updated_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())');
        $status      = 'pending';
        $vision_model = (string)($body['vision_model'] ?? '');
        $image_model  = (string)($body['image_model']  ?? '');
        $stmt->bind_param('ssssss', $sku, $olist_id, $nome, $original_url, $vision_model, $image_model);
        $stmt->execute();
        $job_id = (int)$db->insert_id;

        if (is_array($items)) {
            $istmt = $db->prepare('INSERT INTO ai_image_job_items (job_id, image_type, prompt, site_url, local_file, status, error) VALUES (?,?,?,?,?,?,?)');
            foreach ($items as $item) {
                $type       = (string)($item['image_type'] ?? '');
                $prompt     = (string)($item['prompt']     ?? '');
                $site_url   = (string)($item['site_url']   ?? '');
                $local_file = (string)($item['local_file'] ?? '');
                $istatus    = (string)($item['status']     ?? 'pending');
                $ierror     = (string)($item['error']      ?? '');
                $istmt->bind_param('issssss', $job_id, $type, $prompt, $site_url, $local_file, $istatus, $ierror);
                $istmt->execute();
            }

            $uploaded = count(array_filter($items, fn($i) => ($i['status'] ?? '') === 'uploaded'));
            $errors   = count(array_filter($items, fn($i) => ($i['status'] ?? '') === 'error'));
            $status   = $errors === count($items) ? 'failed' : ($uploaded > 0 ? 'partial' : 'pending');

            $ustmt = $db->prepare('UPDATE ai_image_jobs SET status = ?, generated_at = NOW(), updated_at = NOW() WHERE id = ?');
            $ustmt->bind_param('si', $status, $job_id);
            $ustmt->execute();
        }

        $db->commit();
        aij_json(201, ['ok' => true, 'job_id' => $job_id, 'status' => $status]);
    } catch (Throwable $e) {
        $db->rollback();
        aij_json(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

function aij_ab_register(mysqli $db, array $body): never
{
    $session_id    = bin2hex(random_bytes(16));
    $sku           = trim((string)($body['sku']           ?? ''));
    $olist_id      = trim((string)($body['olist_id']      ?? ''));
    $variant_a_type= trim((string)($body['variant_a_type']?? ''));
    $variant_a_url = trim((string)($body['variant_a_url'] ?? ''));
    $variant_b_type= trim((string)($body['variant_b_type']?? ''));
    $variant_b_url = trim((string)($body['variant_b_url'] ?? ''));
    $started_at    = trim((string)($body['started_at']    ?? date('c')));

    if (!$variant_a_url || !$variant_b_url) {
        aij_json(400, ['ok' => false, 'error' => 'variant_a_url e variant_b_url obrigatórios']);
    }

    $stmt = $db->prepare('INSERT INTO ab_test_sessions (session_id, sku, olist_id, variant_a_type, variant_a_url, variant_b_type, variant_b_url, started_at, status) VALUES (?,?,?,?,?,?,?,?,\'running\')');
    $stmt->bind_param('ssssssss', $session_id, $sku, $olist_id, $variant_a_type, $variant_a_url, $variant_b_type, $variant_b_url, $started_at);
    try {
        $stmt->execute();
        aij_json(201, ['ok' => true, 'session_id' => $session_id]);
    } catch (Throwable $e) {
        aij_json(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

function aij_ab_list(mysqli $db): never
{
    $status          = trim((string)($_GET['status']          ?? ''));
    $min_impressions = (int)($_GET['min_impressions'] ?? 0);
    $limit           = min((int)($_GET['limit'] ?? 100), 500);

    $sql = "SELECT * FROM ab_test_sessions WHERE 1=1";
    $params = [];
    $types = '';

    if ($status !== '') {
        $sql .= " AND status = ?";
        $params[] = $status;
        $types .= 's';
    }
    if ($min_impressions > 0) {
        $sql .= " AND impressions >= ?";
        $params[] = $min_impressions;
        $types .= 'i';
    }
    $sql .= " ORDER BY created_at DESC LIMIT $limit";

    $rows = [];
    if ($params) {
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $db->query($sql);
    }

    while ($row = $result->fetch_assoc()) $rows[] = $row;
    aij_json(200, ['ok' => true, 'total' => count($rows), 'sessions' => $rows]);
}

function aij_ab_metrics(mysqli $db): never
{
    $session_id = trim((string)($_GET['session_id'] ?? ''));
    if ($session_id === '') aij_json(400, ['ok' => false, 'error' => 'session_id obrigatório']);

    $stmt = $db->prepare('SELECT * FROM ab_test_sessions WHERE session_id = ? LIMIT 1');
    $stmt->bind_param('s', $session_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) aij_json(404, ['ok' => false, 'error' => 'sessão não encontrada']);
    aij_json(200, array_merge(['ok' => true], $row ?? []));
}

function aij_ab_winner(mysqli $db, array $body): never
{
    $session_id  = trim((string)($body['session_id']  ?? ''));
    $winner_type = trim((string)($body['winner_type'] ?? ''));
    $winner_url  = trim((string)($body['winner_url']  ?? ''));
    $decided_at  = trim((string)($body['decided_at']  ?? date('c')));

    if ($session_id === '') aij_json(400, ['ok' => false, 'error' => 'session_id obrigatório']);

    $stmt = $db->prepare("UPDATE ab_test_sessions SET winner_type=?, winner_url=?, decided_at=?, status='decided', updated_at=NOW() WHERE session_id=?");
    $stmt->bind_param('ssss', $winner_type, $winner_url, $decided_at, $session_id);
    try {
        $stmt->execute();
        aij_json(200, ['ok' => true, 'updated' => $db->affected_rows]);
    } catch (Throwable $e) {
        aij_json(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

function aij_track_click(mysqli $db, array $body): never
{
    $session_id = trim((string)($body['session_id'] ?? ''));
    $variant    = trim((string)($body['variant']    ?? '')); // 'a' ou 'b'
    $event      = trim((string)($body['event']      ?? 'click')); // 'click' ou 'sale'

    if ($session_id === '' || !in_array($variant, ['a', 'b'], true)) {
        aij_json(400, ['ok' => false, 'error' => 'session_id e variant (a|b) obrigatórios']);
    }

    $col = $event === 'sale'
        ? ($variant === 'a' ? 'sales_a' : 'sales_b')
        : ($variant === 'a' ? 'clicks_a' : 'clicks_b');

    $stmt = $db->prepare("UPDATE ab_test_sessions SET `$col` = `$col` + 1, impressions = impressions + 1, updated_at = NOW() WHERE session_id = ?");
    $stmt->bind_param('s', $session_id);
    try {
        $stmt->execute();
        aij_json(200, ['ok' => true]);
    } catch (Throwable $e) {
        aij_json(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

// ─── Router ───────────────────────────────────────────────────────────────────

if (($_GET['health'] ?? '') === '1') {
    aij_json(200, ['ok' => true, 'endpoint' => 'ai-image-jobs', 'version' => '1.0']);
}

$action = trim((string)($_GET['action'] ?? ''));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// track_click: público (sem auth) para ser chamado do frontend
if ($action === 'track_click' && $method === 'POST') {
    $raw  = file_get_contents('php://input') ?: '';
    $body = json_decode($raw, true) ?: [];
    $db   = aij_db();
    aij_ensure_schema($db);
    aij_track_click($db, $body);
}

aij_auth();

try {
    $db = aij_db();
    aij_ensure_schema($db);

    if ($method === 'GET') {
        match ($action) {
            'list'         => aij_list_jobs($db),
            'get_product'  => aij_get_product($db),
            'ab_list'      => aij_ab_list($db),
            'ab_metrics'   => aij_ab_metrics($db),
            default        => aij_json(400, ['ok' => false, 'error' => "ação desconhecida: $action"]),
        };
    }

    if ($method === 'POST') {
        $raw = file_get_contents('php://input') ?: '';
        if (strlen($raw) > 2_000_000) aij_json(413, ['ok' => false, 'error' => 'payload muito grande']);
        $body = json_decode($raw, true);
        if (!is_array($body)) aij_json(400, ['ok' => false, 'error' => 'JSON inválido']);

        match ($action) {
            'register_job' => aij_register_job($db, $body),
            'ab_register'  => aij_ab_register($db, $body),
            'ab_winner'    => aij_ab_winner($db, $body),
            default        => aij_json(400, ['ok' => false, 'error' => "ação desconhecida: $action"]),
        };
    }

    aij_json(405, ['ok' => false, 'error' => 'método não permitido']);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof mysqli) {
        try { $db->rollback(); } catch (Throwable $ignored) {}
    }
    aij_json(500, ['ok' => false, 'error' => 'internal_error', 'message' => $e->getMessage()]);
}
