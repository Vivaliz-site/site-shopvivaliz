<?php
/**
 * Orchestrator Queue — ShopVivaliz
 *
 * Fila de tarefas persistida em storage/orchestrator/queue.json
 * Inclui: queue_push, queue_pop, queue_list, queue_status
 */

declare(strict_types=1);

// ── Bootstrap ─────────────────────────────────────────────────────────────────
(static function () {
    $f = dirname(__DIR__, 2) . '/.env';
    if (!is_file($f)) return;
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim(trim($v), '"\'');
        if ($k !== '' && getenv($k) === false) putenv("$k=$v");
    }
})();

// ── Constantes ────────────────────────────────────────────────────────────────
define('OQ_MAX_TASKS',    100);
define('OQ_TTL_DONE',     86400); // 24h em segundos
define('OQ_PRIORITIES',   ['high', 'normal', 'low']);

function oq_root(): string { return dirname(__DIR__, 2); }

function oq_storage_dir(): string {
    $dir = oq_root() . '/storage/orchestrator';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function oq_queue_file(): string {
    return oq_storage_dir() . '/queue.json';
}

// ── Leitura / Escrita da fila ─────────────────────────────────────────────────
function oq_read(): array {
    $file = oq_queue_file();
    if (!is_file($file)) return [];
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function oq_write(array $tasks): bool {
    $file = oq_queue_file();
    return file_put_contents($file, json_encode($tasks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}

// ── Limpeza automática ─────────────────────────────────────────────────────────
function oq_prune(array $tasks): array {
    $now    = time();
    $cutoff = $now - OQ_TTL_DONE;
    return array_values(array_filter($tasks, static function (array $t) use ($cutoff): bool {
        if (!in_array($t['status'] ?? '', ['done', 'failed'], true)) return true;
        $ts = strtotime($t['done_at'] ?? '') ?: 0;
        return $ts > $cutoff; // mantém se dentro das 24h
    }));
}

// ── Ordenação por prioridade ──────────────────────────────────────────────────
function oq_sort(array $tasks): array {
    $order = array_flip(OQ_PRIORITIES);
    usort($tasks, static function (array $a, array $b) use ($order): int {
        $pa = $order[$a['priority'] ?? 'normal'] ?? 1;
        $pb = $order[$b['priority'] ?? 'normal'] ?? 1;
        if ($pa !== $pb) return $pa <=> $pb; // menor índice = maior prioridade
        return strtotime($a['created_at'] ?? '0') <=> strtotime($b['created_at'] ?? '0');
    });
    return $tasks;
}

// ── API Pública ───────────────────────────────────────────────────────────────

/**
 * Enfileira uma nova tarefa.
 * Retorna o ID gerado ou false em caso de falha.
 */
function queue_push(string $type, string $priority = 'normal', array $data = []): string|false {
    if (!in_array($priority, OQ_PRIORITIES, true)) $priority = 'normal';

    $tasks  = oq_prune(oq_read());

    // Verifica se já existe task pending/running do mesmo tipo
    foreach ($tasks as $t) {
        if ($t['type'] === $type && in_array($t['status'] ?? '', ['pending', 'running'], true)) {
            return $t['id']; // deduplica
        }
    }

    if (count($tasks) >= OQ_MAX_TASKS) return false; // fila cheia

    $id   = uniqid('task_', true);
    $task = [
        'id'         => $id,
        'type'       => $type,
        'priority'   => $priority,
        'status'     => 'pending',
        'created_at' => date('c'),
        'started_at' => null,
        'done_at'    => null,
        'data'       => $data,
        'result'     => null,
        'retries'    => 0,
    ];

    $tasks[] = $task;
    $tasks   = oq_sort($tasks);

    return oq_write($tasks) ? $id : false;
}

/**
 * Retira a próxima task pending da fila e marca como running.
 * Retorna a task ou null se a fila estiver vazia.
 */
function queue_pop(): ?array {
    $tasks = oq_prune(oq_read());
    $idx   = null;

    foreach ($tasks as $i => $t) {
        if (($t['status'] ?? '') === 'pending') {
            $idx = $i;
            break;
        }
    }

    if ($idx === null) return null;

    $tasks[$idx]['status']     = 'running';
    $tasks[$idx]['started_at'] = date('c');

    oq_write($tasks);
    return $tasks[$idx];
}

/**
 * Finaliza uma task (done ou failed) e salva o resultado.
 */
function queue_finish(string $id, bool $success, mixed $result = null, int $retries = 0): bool {
    $tasks = oq_read();

    foreach ($tasks as &$t) {
        if ($t['id'] !== $id) continue;
        $t['status']  = $success ? 'done' : 'failed';
        $t['done_at'] = date('c');
        $t['result']  = $result;
        $t['retries'] = $retries;
        break;
    }
    unset($t);

    return oq_write($tasks);
}

/**
 * Lista todas as tasks (após poda), opcionalmente filtrando por status.
 */
function queue_list(?string $status = null): array {
    $tasks = oq_prune(oq_read());
    if ($status !== null) {
        $tasks = array_values(array_filter($tasks, fn($t) => ($t['status'] ?? '') === $status));
    }
    return $tasks;
}

/**
 * Retorna um resumo do estado da fila.
 */
function queue_status(): array {
    $tasks   = oq_prune(oq_read());
    $counts  = ['pending' => 0, 'running' => 0, 'done' => 0, 'failed' => 0];
    $byType  = [];

    foreach ($tasks as $t) {
        $s = $t['status'] ?? 'unknown';
        $counts[$s] = ($counts[$s] ?? 0) + 1;

        $type = $t['type'] ?? 'unknown';
        if (!isset($byType[$type])) {
            $byType[$type] = ['last_done' => null, 'last_failed' => null, 'pending' => 0];
        }
        if ($s === 'done')    $byType[$type]['last_done']   = $t['done_at'];
        if ($s === 'failed')  $byType[$type]['last_failed']  = $t['done_at'];
        if ($s === 'pending') $byType[$type]['pending']++;
    }

    return [
        'total'   => count($tasks),
        'counts'  => $counts,
        'by_type' => $byType,
        'queue_file' => oq_queue_file(),
    ];
}

// ── Endpoint HTTP (uso direto) ────────────────────────────────────────────────
if (PHP_SAPI !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    $cronSecret = (string)(getenv('CRON_SECRET') ?: '');
    if ($cronSecret !== '' && ($_GET['secret'] ?? '') !== $cronSecret) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Acesso negado.']);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'] ?? 'status';

    $out = match ($action) {
        'status' => queue_status(),
        'list'   => ['tasks' => queue_list($_GET['filter'] ?? null)],
        'push'   => (static function () {
            $type     = $_POST['type']     ?? $_GET['type']     ?? '';
            $priority = $_POST['priority'] ?? $_GET['priority'] ?? 'normal';
            $data     = json_decode($_POST['data'] ?? '{}', true) ?: [];
            if ($type === '') return ['ok' => false, 'error' => 'type obrigatório'];
            $id = queue_push($type, $priority, $data);
            return $id !== false ? ['ok' => true, 'id' => $id] : ['ok' => false, 'error' => 'Fila cheia ou erro'];
        })(),
        default  => ['ok' => false, 'error' => "Ação desconhecida: $action"],
    };

    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}
