<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';

function am_json_file(string $relativePath): array
{
    $path = dirname(__DIR__) . '/' . ltrim($relativePath, '/');
    if (!is_file($path)) {
        return [];
    }
    $raw = trim((string)@file_get_contents($path));
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

$guardian = am_json_file('/logs/autonomous-hourly-guardian.json');
$cycle = am_json_file('/storage/agent-evidence/latest-agent-cycle.json');
$guardianOk = ($guardian['ok'] ?? false) === true
    && strtoupper((string)($guardian['status'] ?? '')) === 'HEALTHY'
    && ($guardian['queue_modified'] ?? null) === false
    && ($guardian['service_restarted'] ?? null) === false;
$cycleAccepted = ($cycle['execution_accepted'] ?? false) === true
    && ($cycle['execution_completed'] ?? false) === true
    && (string)($cycle['execution_status'] ?? '') === 'completed'
    && (int)($cycle['active_agent_count'] ?? 0) === 5
    && (int)($cycle['work_evidence_count'] ?? 0) >= 12;
$healthy = $guardianOk && $cycleAccepted;
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Agents Monitor</title>
    <style>
        body { font-family: Arial, sans-serif; background:#0f172a; color:#e2e8f0; padding:20px; }
        .card { background:#1e293b; padding:20px; margin-bottom:20px; border-radius:10px; }
        .ok { color:#22c55e; }
        .fail { color:#ef4444; }
        pre { background:#020617; padding:10px; overflow:auto; white-space:pre-wrap; }
    </style>
    <link rel="stylesheet" href="/css/admin-zoom-responsive.css?v=20260719-1">
</head>
<body>
<h1>ShopVivaliz — Agents Monitor</h1>
<div class="card">
    <h2>Estado validado</h2>
    <p>Status: <strong class="<?= $healthy ? 'ok' : 'fail' ?>"><?= $healthy ? 'COMPROVADO' : 'FALHOU / INCONCLUSIVO' ?></strong></p>
    <p>Guardian: <?= htmlspecialchars((string)($guardian['status'] ?? 'sem evidência'), ENT_QUOTES, 'UTF-8') ?></p>
    <p>Release: <?= htmlspecialchars((string)($guardian['release_sha'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></p>
    <p>Ciclo: <?= htmlspecialchars((string)($cycle['cycle_id'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></p>
    <p>Agentes ativos: <?= (int)($cycle['active_agent_count'] ?? 0) ?></p>
    <p>Evidências: <?= (int)($cycle['work_evidence_count'] ?? 0) ?></p>
    <p>Idade da evidência: <?= isset($guardian['evidence_age_seconds']) ? (int)$guardian['evidence_age_seconds'] . ' s' : '-' ?></p>
</div>
<div class="card">
    <h2>Erros</h2>
    <?php if (!empty($guardian['errors']) && is_array($guardian['errors'])): ?>
        <ul><?php foreach ($guardian['errors'] as $error): ?><li><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul>
    <?php else: ?>
        <p>Nenhum erro registrado no snapshot validado.</p>
    <?php endif; ?>
</div>
<div class="card">
    <h2>Evidência bruta</h2>
    <pre><?= htmlspecialchars((string)json_encode(['guardian' => $guardian, 'cycle' => $cycle], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?></pre>
</div>
</body>
</html>
