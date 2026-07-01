<?php
/**
 * EHA Auto Fixer — corrige problemas de baixo risco automaticamente
 */

function eha_auto_fix(string $issue): ?string {
    switch (true) {
        case str_contains($issue, 'null_error'):
        case $issue === 'null_error':
            // adiciona null-safety em arquivos PHP recentemente modificados
            _patch_null_safety();
            return '// fixed null safety';

        case str_contains($issue, 'missing_route'):
        case $issue === 'missing_route':
            _ensure_fallback_route();
            return '// route auto-generated';

        case str_contains($issue, 'missing_image'):
        case $issue === 'missing_image':
            _ensure_default_image();
            return '/assets/default.png';

        default:
            return null;
    }
}

function _patch_null_safety(): void {
    // varre PHP modificado nas últimas 2h e adiciona operador null-safe onde falta
    $base = dirname(__DIR__, 2);
    $files = [];
    $iter  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));
    foreach ($iter as $f) {
        if ($f->getExtension() !== 'php') continue;
        if (str_contains($f->getPathname(), 'vendor')) continue;
        if (filemtime($f->getPathname()) > time() - 7200) {
            $files[] = $f->getPathname();
        }
    }
    foreach (array_slice($files, 0, 20) as $path) {
        $src = file_get_contents($path);
        // substitui ->método() sem verificação null por ?->método() quando precedido de variável simples
        $patched = preg_replace('/(\$\w+)->(\w+\s*\()/', '$1?->$2', $src ?? '');
        if ($patched && $patched !== $src) {
            file_put_contents($path, $patched);
        }
    }
}

function _ensure_fallback_route(): void {
    $htaccess = dirname(__DIR__, 2) . '/.htaccess';
    if (!file_exists($htaccess)) return;
    $content = file_get_contents($htaccess) ?: '';
    if (!str_contains($content, 'ErrorDocument 404')) {
        file_put_contents($htaccess, $content . "\nErrorDocument 404 /404.php\n");
    }
}

function _ensure_default_image(): void {
    $target = dirname(__DIR__, 2) . '/assets/default.png';
    if (!file_exists($target)) {
        // cria imagem placeholder 1x1 px PNG válido
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        @mkdir(dirname($target), 0755, true);
        file_put_contents($target, $png);
    }
}
