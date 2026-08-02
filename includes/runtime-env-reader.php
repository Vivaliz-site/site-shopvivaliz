<?php

declare(strict_types=1);

/**
 * Le configuracao runtime sem depender exclusivamente de getenv()/putenv().
 * Alguns SAPIs podem nao refletir valores carregados dinamicamente em
 * getenv(), embora os mesmos arquivos sejam legiveis pelo processo web.
 */

/** @return list<string> */
function svre_env_files(?string $projectRoot = null): array
{
    $root = rtrim($projectRoot ?? dirname(__DIR__), DIRECTORY_SEPARATOR);

    return array_values(array_unique([
        $root . DIRECTORY_SEPARATOR . '.env',
        dirname($root) . DIRECTORY_SEPARATOR . 'shared' . DIRECTORY_SEPARATOR . '.env',
        '/home/ubuntu/shopvivaliz-deploy/shared/.env',
    ]));
}

/** @return array<string,string> */
function svre_parse_env_file(string $path): array
{
    static $cache = [];

    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $mtime = (int)(filemtime($path) ?: 0);
    $cacheKey = $path . '|' . $mtime;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if (preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key) !== 1) {
            continue;
        }

        $value = trim($value);
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        $values[$key] = $value;
    }

    $cache[$cacheKey] = $values;
    return $values;
}

/** @return array<string,string> */
function svre_runtime_secrets(?string $projectRoot = null): array
{
    static $cache = [];
    $root = rtrim($projectRoot ?? dirname(__DIR__), DIRECTORY_SEPARATOR);
    $path = $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'runtime-secrets.php';

    if (array_key_exists($path, $cache)) {
        return $cache[$path];
    }
    if (!is_file($path) || !is_readable($path)) {
        return $cache[$path] = [];
    }

    $raw = require $path;
    if (!is_array($raw)) {
        return $cache[$path] = [];
    }

    $values = [];
    foreach ($raw as $key => $value) {
        if (!is_string($key) || $key === '' || !is_scalar($value)) {
            continue;
        }
        $values[$key] = (string)$value;
    }

    return $cache[$path] = $values;
}

/**
 * @param string|list<string> $keys
 */
function svre_value(string|array $keys, ?string $projectRoot = null): string
{
    $keys = is_array($keys) ? $keys : [$keys];

    foreach ($keys as $key) {
        if (!is_string($key) || $key === '') {
            continue;
        }

        $environmentValue = getenv($key);
        if (is_string($environmentValue) && trim($environmentValue) !== '') {
            return trim($environmentValue);
        }
        foreach ([$_ENV, $_SERVER] as $scope) {
            if (isset($scope[$key]) && is_scalar($scope[$key]) && trim((string)$scope[$key]) !== '') {
                return trim((string)$scope[$key]);
            }
        }
    }

    $secrets = svre_runtime_secrets($projectRoot);
    foreach ($keys as $key) {
        if (isset($secrets[$key]) && trim($secrets[$key]) !== '') {
            return trim($secrets[$key]);
        }
    }

    foreach (svre_env_files($projectRoot) as $path) {
        $values = svre_parse_env_file($path);
        foreach ($keys as $key) {
            if (isset($values[$key]) && trim($values[$key]) !== '') {
                return trim($values[$key]);
            }
        }
    }

    return '';
}

/** @return array<string,string> */
function svre_process_environment(array $keys): array
{
    $values = [];
    foreach ($keys as $key) {
        $value = getenv($key);
        if (is_string($value) && trim($value) !== '') {
            $values[$key] = trim($value);
            continue;
        }
        foreach ([$_ENV, $_SERVER] as $scope) {
            if (isset($scope[$key]) && is_scalar($scope[$key]) && trim((string)$scope[$key]) !== '') {
                $values[$key] = trim((string)$scope[$key]);
                break;
            }
        }
    }
    return $values;
}

/**
 * Resolve credenciais de banco como um conjunto coerente, sem misturar usuario
 * de uma fonte com senha ou banco de outra. Arquivos/runtime-secrets precedem o
 * ambiente do processo porque servicos CLI podem herdar DB_USER=root do sistema.
 * Quando existem varios conjuntos completos, um usuario nao-root e preferido;
 * root continua permitido como fallback para desenvolvimento local.
 *
 * @return array{host:string,port:string,name:string,user:string,pass:string,alias_set:string}
 */
function svre_database_config(?string $projectRoot = null): array
{
    $root = rtrim($projectRoot ?? dirname(__DIR__), DIRECTORY_SEPARATOR);
    $databaseKeys = [
        'DB_HOST', 'DB_PORT',
        'DB_NAME', 'DB_USER', 'DB_PASS',
        'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
    ];

    $sources = [];
    $runtimeSecrets = svre_runtime_secrets($root);
    if ($runtimeSecrets !== []) {
        $sources[] = $runtimeSecrets;
    }
    foreach (svre_env_files($root) as $path) {
        $values = svre_parse_env_file($path);
        if ($values !== []) {
            $sources[] = $values;
        }
    }
    $processValues = svre_process_environment($databaseKeys);
    if ($processValues !== []) {
        $sources[] = $processValues;
    }

    $aliasSets = [
        'short' => ['DB_NAME', 'DB_USER', 'DB_PASS'],
        'long' => ['DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'],
    ];
    $candidates = [];

    foreach ($sources as $source) {
        foreach ($aliasSets as $aliasSet => [$nameKey, $userKey, $passKey]) {
            $name = trim((string)($source[$nameKey] ?? ''));
            $user = trim((string)($source[$userKey] ?? ''));
            if ($name === '' || $user === '') {
                continue;
            }
            $candidates[] = [
                'host' => trim((string)($source['DB_HOST'] ?? '')),
                'port' => trim((string)($source['DB_PORT'] ?? '')),
                'name' => $name,
                'user' => $user,
                'pass' => (string)($source[$passKey] ?? ''),
                'alias_set' => $aliasSet,
            ];
        }
    }

    $selected = null;
    foreach ($candidates as $candidate) {
        if (strtolower($candidate['user']) !== 'root') {
            $selected = $candidate;
            break;
        }
    }
    if ($selected === null && $candidates !== []) {
        $selected = $candidates[0];
    }

    $fallback = static function (string $key) use ($sources): string {
        foreach ($sources as $source) {
            $value = trim((string)($source[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    };

    if ($selected === null) {
        return [
            'host' => $fallback('DB_HOST') ?: 'localhost',
            'port' => $fallback('DB_PORT') ?: '3306',
            'name' => '',
            'user' => '',
            'pass' => '',
            'alias_set' => '',
        ];
    }

    if ($selected['host'] === '') {
        $selected['host'] = $fallback('DB_HOST') ?: 'localhost';
    }
    if ($selected['port'] === '' || !ctype_digit($selected['port'])) {
        $selected['port'] = $fallback('DB_PORT');
    }
    if ($selected['port'] === '' || !ctype_digit($selected['port'])) {
        $selected['port'] = '3306';
    }

    return $selected;
}
