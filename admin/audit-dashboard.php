<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-guard.php';
require_once dirname(__DIR__) . '/config/bootstrap-env.php';

$root = dirname(__DIR__);
$readJson = static function (string $relative) use ($root): array {
    $path = $root . '/' . ltrim($relative, '/');
    if (!is_file($path)) {
        return [];
    }
    $raw = trim((string)@file_get_contents($path));
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
};

$guardian = $readJson('/logs/autonomous-hourly-guardian.json');
$cycle = $readJson('/storage/agent-evidence/latest-agent-cycle.json');
$integration = $readJson('/storage/private/integration-health.json');

$guardianHealthy = ($guardian['ok'] ?? false) === true
    && strtoupper((string)($guardian['status'] ?? '')) === 'HEALTHY'
    && ($guardian['queue_modified'] ?? null) === false
    && ($guardian['service_restarted'] ?? null) === false;
$cycleHealthy = ($cycle['execution_accepted'] ?? false) === true
    && ($cycle['execution_completed'] ?? false) === true
    && (string)($cycle['execution_status'] ?? '') === 'completed'
    && (int)($cycle['active_agent_count'] ?? 0) === 5
    && (int)($cycle['work_evidence_count'] ?? 0) >= 12;
$integrationHealthy = ($integration['ok'] ?? false) === true;
$overallHealthy = $guardianHealthy && $cycleHealthy && $integrationHealthy;
$errors = [];
foreach ((array)($guardian['errors'] ?? []) as $error) {
    $errors[] = (string)$error;
}
if (!$cycleHealthy) {
    $errors[] = 'Ciclo de agentes ausente, incompleto, não aceito ou sem evidência mínima.';
}
if (!$integrationHealthy) {
    $errors[] = 'Integrações não possuem snapshot saudável e verificável.';
}
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Painel de Auditoria — ShopVivaliz</title>
    <style>
        * { box-sizing:border-box; }
        body { margin:0; font-family:system-ui,-apple-system,sans-serif; background:#0f172a; color:#e2e8f0; }
        .container { max-width:1200px; margin:0 auto; padding:24px; }
        .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:16px; }
        .card { background:#1e293b; border:1px solid #334155; border-radius:12px; padding:18px; margin-bottom:16px; }
        .value { font-size:28px; font-weight:700; }
        .ok { color:#22c55e; }
        .fail { color:#ef4444; }
        .muted { color:#94a3b8; }
        pre { background:#020617; border-radius:8px; padding:12px; overflow:auto; white-space:pre-wrap; }
        ul { margin-bottom:0; }
    </style>
    <link rel="stylesheet" href="/css/admin-zoom-responsive.css?v=20260719-1">
</head>
<body>
<div class="container">
    <h1>Painel de Auditoria</h1>
    <p class="muted">Nenhum estado <code>idle</code>, arquivo existente ou processo vivo é tratado como prova de saúde.</p>
    <div class="grid">
        <div class="card"><div class="muted">Estado geral</div><div class="value <?= $overallHealthy ? 'ok' : 'fail' ?>"><?= $overallHealthy ? 'COMPROVADO' : 'FALHOU / INCONCLUSIVO' ?></div></div>
        <div class="card"><div class="muted">Release</div><div class="value" style="font-size:16px"><?= htmlspecialchars((string)($guardian['release_sha'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div></div>
        <div class="card"><div class="muted">Agentes ativos</div><div class="value"><?= (int)($cycle['active_agent_count'] ?? 0) ?></div></div>
        <div class="card"><div class="muted">Evidências do ciclo</div><div class="value"><?= (int)($cycle['work_evidence_count'] ?? 0) ?></div></div>
        <div class="card"><div class="muted">Idade da evidência</div><div class="value"><?= isset($guardian['evidence_age_seconds']) ? (int)$guardian['evidence_age_seconds'] . 's' : '-' ?></div></div>
        <div class="card"><div class="muted">Integrações</div><div class="value <?= $integrationHealthy ? 'ok' : 'fail' ?>"><?= $integrationHealthy ? 'OK' : 'ATENÇÃO' ?></div></div>
    </div>
    <div class="card">
        <h2>Ações necessárias</h2>
        <?php if ($errors === []): ?>
            <p class="ok">Nenhuma falha registrada nos snapshots independentes.</p>
        <?php else: ?>
            <ul><?php foreach (array_values(array_unique($errors)) as $error): ?><li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
    </div>
    <div class="card">
        <h2>Evidência bruta</h2>
        <pre><?= htmlspecialchars((string)json_encode(['guardian'=>$guardian,'cycle'=>$cycle,'integration'=>$integration], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?></pre>
    </div>
</div>
</body>
</html>
