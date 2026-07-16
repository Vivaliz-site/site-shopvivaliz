<?php
/**
 * EHA Auto Fixer — corrige problemas de baixo risco automaticamente
 */

function eha_auto_fix(string $issue): ?array {
    switch (true) {
        case str_contains($issue, 'null_error'):
        case $issue === 'null_error':
            return _patch_null_safety();

        case str_contains($issue, 'missing_route'):
        case $issue === 'missing_route':
            return _ensure_fallback_route();

        case str_contains($issue, 'missing_image'):
        case $issue === 'missing_image':
            return _ensure_default_image();

        default:
            return null;
    }
}

function _patch_null_safety(): array {
    return [
        'status' => 'proposal_only',
        'issue' => 'null_error',
        'action' => 'inspect_recent_php_changes',
        'note' => 'Correcao ampla de null-safety bloqueada; gerar patch revisavel via PR.',
    ];
}

function _ensure_fallback_route(): array {
    $htaccess = dirname(__DIR__, 2) . '/.htaccess';
    if (!file_exists($htaccess)) {
        return [
            'status' => 'proposal_only',
            'issue' => 'missing_route',
            'action' => 'create_404_fallback',
            'target' => '.htaccess',
            'note' => 'Arquivo .htaccess ausente; correcao deve ser feita por PR.',
        ];
    }

    $content = file_get_contents($htaccess) ?: '';
    if (!str_contains($content, 'ErrorDocument 404')) {
        file_put_contents($htaccess, $content . "\nErrorDocument 404 /404.php\n");
        return [
            'status' => 'applied',
            'issue' => 'missing_route',
            'action' => 'create_404_fallback',
            'target' => '.htaccess',
        ];
    }

    return [
        'status' => 'no_action',
        'issue' => 'missing_route',
        'action' => 'create_404_fallback',
        'target' => '.htaccess',
    ];
}

function _ensure_default_image(): array {
    $target = dirname(__DIR__, 2) . '/assets/default.png';
    if (!file_exists($target)) {
        // cria imagem placeholder 1x1 px PNG válido
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        @mkdir(dirname($target), 0755, true);
        file_put_contents($target, $png);
        return [
            'status' => 'applied',
            'issue' => 'missing_image',
            'action' => 'create_default_image',
            'target' => 'assets/default.png',
        ];
    }

    return [
        'status' => 'no_action',
        'issue' => 'missing_image',
        'action' => 'create_default_image',
        'target' => 'assets/default.png',
    ];
}
