<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/admin-guard.php';
require_once __DIR__ . '/../includes/production-agent-registry.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['agent_cycle_csrf'])) {
    $_SESSION['agent_cycle_csrf'] = bin2hex(random_bytes(24));
}

$root = dirname(__DIR__);
$evidencePath = $root . '/storage/agent-evidence/latest-agent-cycle.json';

function svadmin_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function svadmin_read_cycle(string $path): array
{
    if (!is_file($path) || !is_readable($path)) return [];
    $payload = json_decode((string)file_get_contents($path), true);
    return is_array($payload) ? $payload : [];
}

function svadmin_run_cycle(string $root): array
{
    $php = PHP_BINARY !== '' ? PHP_BINARY : '/usr/bin/php';
    $script = $root . '/api/agent/real-work-orchestrator.php';
    if (!is_file($script)) return ['ok' => false, 'error' => 'orchestrator_missing'];

    $command = 'cd ' . escapeshellarg($root)
        . ' && timeout 1500s flock -w 120 /tmp/shopvivaliz-real-work.lock '
        . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' 2>&1';
    $lines = [];
    $exitCode = 1;
    exec($command, $lines, $exitCode);
    $raw = trim(implode("\n", $lines));
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'invalid_orchestrator_output', 'exit_code' => $exitCode];
    }
    if ($exitCode !== 0 || ($payload['execution_accepted'] ?? false) !== true) {
        return ['ok' => false, 'error' => 'agent_cycle_failed', 'exit_code' => $exitCode, 'result' => $payload];
    }
    return ['ok' => true, 'result' => $payload];
}

$notice = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = trim((string)($_POST['csrf'] ?? ''));
    if ($csrf === '' || !hash_equals((string)$_SESSION['agent_cycle_csrf'], $csrf)) {
        $notice = ['ok' => false, 'message' => 'Sessão inválida. Recarregue a página.'];
    } elseif (($_POST['action'] ?? '') !== 'run_cycle') {
        $notice = ['ok' => false, 'message' => 'Ação não permitida.'];
    } else {
        $execution = svadmin_run_cycle($root);
        $notice = ($execution['ok'] ?? false) === true
            ? ['ok' => true, 'message' => 'Ciclo real concluído e aceito com evidências.']
            : ['ok' => false, 'message' => 'O ciclo falhou e não foi marcado como concluído.', 'detail' => $execution];
    }
}

$cycle = svadmin_read_cycle($evidencePath);
$registry = svpa_registry();
$components = is_array($cycle['components'] ?? null) ? $cycle['components'] : [];
$finishedAt = strtotime((string)($cycle['finished_at'] ?? ''));
$ageSeconds = $finishedAt !== false ? max(0, time() - $finishedAt) : null;
$cycleFresh = $ageSeconds !== null && $ageSeconds <= 1800;
$cycleAccepted = ($cycle['execution_accepted'] ?? false) === true && $cycleFresh;
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Agentes de produção 24/7</title>
    <style>
        :root { color-scheme: light; --bg:#f4f6f8; --card:#fff; --text:#17212b; --muted:#65717d; --ok:#16794b; --bad:#b42318; --line:#dde3e8; --navy:#1f3a70; }
        * { box-sizing:border-box; }
        body { margin:0; font-family:system-ui,-apple-system,Segoe UI,sans-serif; background:var(--bg); color:var(--text); }
        header { padding:24px; background:var(--navy); color:#fff; }
        header h1 { margin:0 0 6px; font-size:1.55rem; }
        header p { margin:0; opacity:.85; }
        main { max-width:1180px; margin:0 auto; padding:24px; }
        .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:14px; margin-bottom:18px; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:18px; box-shadow:0 2px 8px rgba(0,0,0,.04); }
        .label { color:var(--muted); font-size:.78rem; text-transform:uppercase; letter-spacing:.05em; }
        .value { margin-top:6px; font-size:1.35rem; font-weight:700; overflow-wrap:anywhere; }
        .ok { color:var(--ok); }
        .bad { color:var(--bad); }
        table { width:100%; border-collapse:collapse; margin-top:10px; }
        th,td { text-align:left; padding:11px 9px; border-bottom:1px solid var(--line); vertical-align:top; }
        th { color:var(--muted); font-size:.78rem; text-transform:uppercase; }
        .notice { margin-bottom:18px; padding:14px 16px; border-radius:9px; background:#eef8f2; border:1px solid #b7dfc8; }
        .notice.bad { background:#fff1f0; border-color:#f5c2be; }
        button { border:0; border-radius:9px; padding:12px 17px; font-weight:700; background:var(--navy); color:#fff; cursor:pointer; }
        button:hover { filter:brightness(1.08); }
        .actions { display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; }
        code { font-size:.82rem; overflow-wrap:anywhere; }
        @media (max-width:700px) { main { padding:14px; } th:nth-child(3), td:nth-child(3) { display:none; } }
    </style>
</head>
<body>
<header>
    <h1>Agentes de produção 24/7</h1>
    <p>Somente execuções reais da release ativa, com validação fail-closed.</p>
</header>
<main>
    <?php if ($notice !== null): ?>
        <div class="notice <?= ($notice['ok'] ?? false) ? '' : 'bad' ?>"><?= svadmin_h((string)$notice['message']) ?></div>
    <?php endif; ?>

    <section class="grid" aria-label="Resumo do ciclo">
        <div class="card"><div class="label">Estado</div><div class="value <?= $cycleAccepted ? 'ok' : 'bad' ?>"><?= $cycleAccepted ? 'Ativo e verificado' : 'Sem evidência recente' ?></div></div>
        <div class="card"><div class="label">Agentes registrados</div><div class="value"><?= count((array)($registry['agents'] ?? [])) ?></div></div>
        <div class="card"><div class="label">Evidências de trabalho</div><div class="value"><?= (int)($cycle['work_evidence_count'] ?? 0) ?></div></div>
        <div class="card"><div class="label">Itens alterados</div><div class="value"><?= (int)($cycle['changed_items'] ?? 0) ?></div></div>
        <div class="card"><div class="label">Idade da evidência</div><div class="value"><?= $ageSeconds === null ? 'indisponível' : svadmin_h((string)$ageSeconds . ' s') ?></div></div>
        <div class="card"><div class="label">Ciclo</div><div class="value"><code><?= svadmin_h((string)($cycle['cycle_id'] ?? 'não executado')) ?></code></div></div>
    </section>

    <section class="card">
        <div class="actions">
            <div>
                <strong>Execução agendada: a cada 15 minutos</strong><br>
                <span class="label">O botão executa o mesmo ciclo real do workflow, com trava contra concorrência.</span>
            </div>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= svadmin_h((string)$_SESSION['agent_cycle_csrf']) ?>">
                <input type="hidden" name="action" value="run_cycle">
                <button type="submit">Executar ciclo real agora</button>
            </form>
        </div>
    </section>

    <section class="card" style="margin-top:18px; overflow-x:auto">
        <h2 style="margin-top:0">Componentes registrados</h2>
        <table>
            <thead><tr><th>Agente</th><th>Estado</th><th>Evidências</th><th>Alterações</th><th>Finalizado</th></tr></thead>
            <tbody>
            <?php foreach ((array)($registry['agents'] ?? []) as $id => $definition):
                $component = is_array($components[$id] ?? null) ? $components[$id] : [];
                $accepted = ($component['ok'] ?? false) === true
                    && ($component['execution_completed'] ?? false) === true
                    && ($component['execution_status'] ?? '') === 'completed';
            ?>
                <tr>
                    <td><strong><?= svadmin_h((string)$id) ?></strong><br><code><?= svadmin_h((string)($definition['class'] ?? '')) ?></code></td>
                    <td class="<?= $accepted ? 'ok' : 'bad' ?>"><?= $accepted ? 'concluído' : 'sem execução aceita' ?></td>
                    <td><?= count((array)($component['actions'] ?? [])) ?></td>
                    <td><?= (int)($component['changed_items'] ?? 0) ?></td>
                    <td><?= svadmin_h((string)($component['finished_at'] ?? '—')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</main>
</body>
</html>
