<?php

declare(strict_types=1);

return array(
    'version' => '9.2.91',
    'version_code' => 90291,
    'channel' => 'dev',
    'codename' => 'tiny-v2-image-import-catalog',
    'release_type' => 'cumulative',
    'generated_at' => '2026-06-30T18:30:00-03:00',
    'requires_update_php_sync' => true,
    'notes' => array(
        'Centraliza numero da versao para deploy, endpoints e testes pos-deploy.',
        'Nao sobrescreve installer/update.php quando ele nao esta versionado no GitHub.',
        'Prepara o site para detectar divergencia entre versao publicada e versao mostrada pelo atualizador.',
        'Implanta proxy Tiny API V2 para listagem/detalhe e importador de imagens para o catalogo.',
    ),
);
