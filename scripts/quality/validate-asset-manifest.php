<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$manifestPath = $root . '/public/dist/asset-manifest.json';

if (!is_file($manifestPath) || !is_readable($manifestPath)) {
    fwrite(STDERR, "FALHOU: manifest ausente ou ilegivel em public/dist/asset-manifest.json\n");
    exit(1);
}

$decoded = json_decode((string)file_get_contents($manifestPath), true);
if (!is_array($decoded) || !is_array($decoded['assets'] ?? null)) {
    fwrite(STDERR, "FALHOU: manifest JSON invalido ou sem chave assets\n");
    exit(1);
}

$errors = [];
$checked = 0;
foreach ($decoded['assets'] as $source => $entry) {
    $checked++;
    if (!is_string($source) || $source === '' || $source[0] !== '/') {
        $errors[] = "origem invalida: " . (string)$source;
        continue;
    }

    if (!is_array($entry) || !is_string($entry['file'] ?? null) || $entry['file'] === '') {
        $errors[] = "destino ausente para {$source}";
        continue;
    }

    $target = $entry['file'];
    if ($target[0] !== '/') {
        $errors[] = "destino relativo invalido para {$source}: {$target}";
        continue;
    }

    $absoluteTarget = $root . str_replace('/', DIRECTORY_SEPARATOR, $target);
    if (!is_file($absoluteTarget)) {
        $errors[] = "arquivo gerado nao encontrado para {$source}: {$target}";
        continue;
    }

    $actualBytes = filesize($absoluteTarget);
    if ($actualBytes === false || $actualBytes <= 0) {
        $errors[] = "arquivo gerado vazio para {$source}: {$target}";
        continue;
    }

    if (isset($entry['bytes']) && (int)$entry['bytes'] !== $actualBytes) {
        $errors[] = "bytes divergentes para {$source}: manifest={$entry['bytes']} real={$actualBytes}";
    }
}

if ($errors !== []) {
    fwrite(STDERR, "FALHOU: manifest de assets inconsistente\n");
    foreach ($errors as $error) {
        fwrite(STDERR, "- {$error}\n");
    }
    exit(1);
}

echo "COMPROVADO: manifest de assets valido ({$checked} entradas)\n";
