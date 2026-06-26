<?php

declare(strict_types=1);

return array(
    'version' => '9.2.90',
    'version_code' => 90290,
    'channel' => 'dev',
    'codename' => 'robust-version-sync-agents-admin-audit',
    'release_type' => 'cumulative',
    'generated_at' => '2026-06-26T00:00:00+00:00',
    'requires_update_php_sync' => true,
    'notes' => array(
        'Centraliza numero da versao para deploy, endpoints e testes pos-deploy.',
        'Nao sobrescreve installer/update.php quando ele nao esta versionado no GitHub.',
        'Prepara o site para detectar divergencia entre versao publicada e versao mostrada pelo atualizador.',
    ),
);
