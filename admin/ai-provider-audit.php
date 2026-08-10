<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin-guard.php';

header('Content-Type: text/html; charset=UTF-8');

$version = is_file(__DIR__ . '/../config/shopvivaliz-version.php')
    ? require __DIR__ . '/../config/shopvivaliz-version.php'
    : [];
$appVersion = (string)($version['version'] ?? '0.0.0');
$codename = (string)($version['codename'] ?? '');

function ai_audit_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ai_audit_health_snapshot(): array
{
    $path = dirname(__DIR__) . '/storage/ai-provider-health.json';
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function ai_audit_events_tail(int $limit = 120): array
{
    $path = dirname(__DIR__) . '/storage/ai-provider-audit.jsonl';
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines) || $lines === []) {
        return [];
    }

    $events = [];
    foreach (array_slice($lines, max(0, count($lines) - $limit)) as $line) {
        $decoded = json_decode((string)$line, true);
        if (!is_array($decoded)) {
            continue;
        }
        $events[] = $decoded;
    }

    return array_reverse($events);
}

function ai_audit_provider_label(string $provider): string
{
    return match ($provider) {
        'openai' => 'GPT / OpenAI',
        'gemini' => 'Gemini',
        'claude' => 'Claude',
        'openrouter' => 'OpenRouter',
        'groq', 'qrope', 'qroq' => 'Groq / Qrope',
        default => ucfirst($provider),
    };
}

function ai_audit_event_color(string $status): string
{
    return match ($status) {
        'ok' => '#166534',
        'skip' => '#92400e',
        'retry' => '#1d4ed8',
        'fail' => '#991b1b',
        'analysis' => '#7c3aed',
        default => '#475569',
    };
}

function ai_audit_event_background(string $status): string
{
    return match ($status) {
        'ok' => '#dcfce7',
        'skip' => '#fef3c7',
        'retry' => '#dbeafe',
        'fail' => '#fee2e2',
        'analysis' => '#ede9fe',
        default => '#e2e8f0',
    };
}

function ai_audit_provider_summary(array $health, string $provider): array
{
    $entry = $health[$provider] ?? [];
    $status = (string)($entry['status'] ?? 'unknown');
    $cooldownUntil = (string)($entry['cooldown_until'] ?? '');
    $lastError = (string)($entry['last_error'] ?? '');
    $lastSeen = (string)($entry['last_seen'] ?? '');

    return [
        'status' => $status,
        'cooldown_until' => $cooldownUntil,
        'last_error' => $lastError,
        'last_seen' => $lastSeen,
    ];
}

$providerHealth = ai_audit_health_snapshot();
$events = ai_audit_events_tail(160);
$providers = ['openai', 'gemini', 'claude', 'openrouter', 'groq'];
$channels = ['image', 'catalog', 'prompt'];
$grouped = [];
foreach ($events as $event) {
    $provider = strtolower(trim((string)($event['provider'] ?? 'unknown')));
    $channel = strtolower(trim((string)($event['channel'] ?? 'unknown')));
    $grouped[$provider][$channel][] = $event;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auditoria de IA - ShopVivaliz</title>
    <link rel="canonical" href="/admin/ai-provider-audit.php">
    <link rel="stylesheet" href="/css/style.css">
    <?php require_once __DIR__ . '/../includes/load-custom-css.php'; ?>
    <style>
        .audit-shell{padding:24px 0 56px}
        .audit-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px}
        .audit-card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:18px;box-shadow:0 10px 30px rgba(15,23,42,.06)}
        .audit-card h2,.audit-card h3{margin:0 0 8px}
        .audit-badge{display:inline-flex;align-items:center;gap:8px;border-radius:999px;padding:6px 12px;font-weight:700;font-size:12px}
        .audit-muted{color:#64748b;font-size:14px;line-height:1.55}
        .audit-list{margin:12px 0 0;padding:0;list-style:none;display:grid;gap:10px}
        .audit-list li{border:1px solid #e5e7eb;border-radius:14px;padding:12px;background:#f8fafc}
        .audit-matrix{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px}
        .audit-table{width:100%;border-collapse:collapse}
        .audit-table th,.audit-table td{padding:10px 8px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top;font-size:13px}
        .audit-table th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#64748b}
        .audit-panel{max-height:520px;overflow:auto}
        .audit-topbar{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:20px}
        .audit-topbar .version{font-size:12px;font-weight:800;letter-spacing:.04em;color:#0f172a;background:#e2e8f0;padding:8px 12px;border-radius:999px}
        .audit-links{display:flex;flex-wrap:wrap;gap:10px;margin-top:12px}
        .audit-links a{display:inline-flex;padding:10px 14px;border-radius:12px;background:#0f172a;color:#fff;text-decoration:none;font-weight:700;font-size:14px}
        .audit-links a.secondary{background:#2563eb}
    </style>
</head>
<body class="admin-surface">
<main class="audit-shell container">
    <div class="audit-topbar">
        <div>
            <p class="eyebrow">Auditoria inteligente</p>
            <h1>Saúde consolidada dos motores de IA</h1>
            <p class="audit-muted">Visão única para geração de imagem, otimização de catálogo e fallback entre provedores. Os eventos são lidos dos logs compartilhados de saúde e auditoria.</p>
        </div>
        <span class="version">Release <?= ai_audit_h($appVersion) ?><?= $codename !== '' ? ' · ' . ai_audit_h($codename) : '' ?></span>
    </div>

    <section class="audit-grid">
        <?php foreach ($providers as $provider): ?>
            <?php $summary = ai_audit_provider_summary($providerHealth, $provider); ?>
            <article class="audit-card">
                <span class="audit-badge" style="background:<?= ai_audit_event_background((string)$summary['status']) ?>;color:<?= ai_audit_event_color((string)$summary['status']) ?>">
                    <?= ai_audit_h(ai_audit_provider_label($provider)) ?>
                </span>
                <h2 style="margin-top:12px">Estado: <?= ai_audit_h((string)$summary['status']) ?></h2>
                <p class="audit-muted">Última leitura: <?= ai_audit_h($summary['last_seen'] !== '' ? $summary['last_seen'] : 'sem registro') ?></p>
                <p class="audit-muted">Cooldown até: <?= ai_audit_h($summary['cooldown_until'] !== '' ? $summary['cooldown_until'] : 'livre') ?></p>
                <p class="audit-muted">Último erro: <?= ai_audit_h($summary['last_error'] !== '' ? $summary['last_error'] : 'nenhum') ?></p>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="audit-matrix" style="margin-top:20px">
        <?php foreach ($providers as $provider): ?>
            <article class="audit-card audit-panel">
                <h3><?= ai_audit_h(ai_audit_provider_label($provider)) ?></h3>
                <?php foreach ($channels as $channel): ?>
                    <div style="margin-top:16px">
                        <strong style="display:block;margin-bottom:8px;text-transform:uppercase;font-size:12px;letter-spacing:.04em;color:#64748b">Canal <?= ai_audit_h($channel) ?></strong>
                        <?php $rows = $grouped[$provider][$channel] ?? []; ?>
                        <?php if ($rows === []): ?>
                            <p class="audit-muted">Sem eventos recentes.</p>
                        <?php else: ?>
                            <table class="audit-table">
                                <thead>
                                    <tr>
                                        <th>Hora</th>
                                        <th>Status</th>
                                        <th>Mensagem</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach (array_slice($rows, 0, 8) as $event): ?>
                                    <?php
                                        $status = strtolower(trim((string)($event['status'] ?? 'unknown')));
                                        $message = (string)($event['message'] ?? '');
                                        $timestamp = (string)($event['ts'] ?? $event['timestamp'] ?? '');
                                    ?>
                                    <tr>
                                        <td><?= ai_audit_h($timestamp !== '' ? $timestamp : '—') ?></td>
                                        <td><span class="audit-badge" style="background:<?= ai_audit_event_background($status) ?>;color:<?= ai_audit_event_color($status) ?>"><?= ai_audit_h($status) ?></span></td>
                                        <td><?= ai_audit_h($message !== '' ? $message : '—') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="audit-card" style="margin-top:20px">
        <h3>Últimos eventos consolidados</h3>
        <?php if ($events === []): ?>
            <p class="audit-muted">Ainda não há eventos registrados nos logs compartilhados.</p>
        <?php else: ?>
            <table class="audit-table">
                <thead>
                    <tr>
                        <th>Hora</th>
                        <th>Provedor</th>
                        <th>Canal</th>
                        <th>Status</th>
                        <th>Mensagem</th>
                        <th>SKU / Produto</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach (array_slice($events, 0, 40) as $event): ?>
                    <?php
                        $provider = strtolower(trim((string)($event['provider'] ?? 'unknown')));
                        $channel = strtolower(trim((string)($event['channel'] ?? 'unknown')));
                        $status = strtolower(trim((string)($event['status'] ?? 'unknown')));
                        $message = (string)($event['message'] ?? '');
                        $ts = (string)($event['ts'] ?? $event['timestamp'] ?? '');
                        $sku = trim((string)($event['sku'] ?? ''));
                        $productId = (string)($event['product_id'] ?? '');
                    ?>
                    <tr>
                        <td><?= ai_audit_h($ts !== '' ? $ts : '—') ?></td>
                        <td><?= ai_audit_h(ai_audit_provider_label($provider)) ?></td>
                        <td><?= ai_audit_h($channel) ?></td>
                        <td><span class="audit-badge" style="background:<?= ai_audit_event_background($status) ?>;color:<?= ai_audit_event_color($status) ?>"><?= ai_audit_h($status) ?></span></td>
                        <td><?= ai_audit_h($message !== '' ? $message : '—') ?></td>
                        <td><?= ai_audit_h($sku !== '' ? $sku : ($productId !== '' ? 'ID ' . $productId : '—')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <div class="audit-links">
            <a href="/admin/ai-image-studio/admin_dashboard.php">Ir para AI Image Studio</a>
            <a class="secondary" href="/admin/ai-image-studio/admin_validate.php">Ir para validação</a>
            <a class="secondary" href="/admin/catalog-optimization/admin_catalog.php">Ir para catálogo</a>
        </div>
    </section>
</main>
</body>
</html>
