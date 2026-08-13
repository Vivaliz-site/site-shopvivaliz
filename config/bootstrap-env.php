<?php
declare(strict_types=1);

if (!function_exists('sv_bootstrap_env_assign')) {
    function sv_bootstrap_env_assign(string $key, mixed $value): void
    {
        if ($key === '') {
            return;
        }

        $stringValue = is_scalar($value) ? (string)$value : '';
        $currentValue = getenv($key);
        if (trim($stringValue) === '' || (is_string($currentValue) && trim($currentValue) !== '')) {
            return;
        }
        putenv($key . '=' . $stringValue);
        $_ENV[$key] = $stringValue;
        $_SERVER[$key] = $stringValue;
    }
}

if (!function_exists('sv_bootstrap_env')) {
    function sv_bootstrap_env(): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;

        $root = dirname(__DIR__);
        $runtimeSecretsFile = __DIR__ . '/runtime-secrets.php';
        if (is_file($runtimeSecretsFile) && is_readable($runtimeSecretsFile)) {
            $runtimeSecrets = require $runtimeSecretsFile;
            if (is_array($runtimeSecrets)) {
                foreach ($runtimeSecrets as $key => $value) {
                    if (!is_string($key)) {
                        continue;
                    }
                    sv_bootstrap_env_assign($key, $value);
                }
            }
        }

        $dotenvFile = $root . '/.env';
        if (!is_file($dotenvFile) || !is_readable($dotenvFile)) {
            return;
        }

        $lines = file($dotenvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            sv_bootstrap_env_assign(trim($key), trim(trim($value), "\"'"));
        }
    }
}

sv_bootstrap_env();

// A home possui um filtro de confianca server-side que precisa iniciar antes
// do primeiro byte de HTML para abranger tambem JSON-LD e navegadores sem JS.
// O registro e estritamente limitado a requests web da rota raiz; CLI, APIs e
// demais paginas nao recebem output buffering adicional.
if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_URI'])) {
    $svBootstrapPath = parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '';
    if ($svBootstrapPath === '/') {
        require_once dirname(__DIR__) . '/includes/home-trust-sanitizer.php';
        svhts_register('/');
    }
    unset($svBootstrapPath);
}
