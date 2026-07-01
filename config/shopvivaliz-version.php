<?php

declare(strict_types=1);

return array(
    'version' => '9.2.95',
    'version_code' => 90295,
    'channel' => 'dev',
    'codename' => 'gateway-diagnostics-hardening',
    'release_type' => 'cumulative',
    'generated_at' => '2026-07-01T00:00:00-03:00',
    'requires_update_php_sync' => true,
    'notes' => array(
        'Centraliza numero da versao para deploy, endpoints e testes pos-deploy.',
        'Nao sobrescreve installer/update.php quando ele nao esta versionado no GitHub.',
        'Prepara o site para detectar divergencia entre versao publicada e versao mostrada pelo atualizador.',
        'Implanta proxy Tiny API V2 para listagem/detalhe e importador de imagens para o catalogo.',
        'Liga home, catalogo e admin a uma API publica de catalogo com fallback por relatorio validado.',
        'Adiciona AutoDev evolutivo com coleta de eventos, metricas, diretores, agentes, A/B test e propostas seguras de PR.',
        'Adiciona EHA continuo com health check, checkout E2E, classificador de risco e bloqueio de entrega insegura.',
        'Reforca guarda de segredos locais, cookies, HARs, perfis Chrome e storage privado no Git.',
        'Versiona update-applied-check e auto-routines no repositorio para diagnostico consistente apos deploy.',
        'Moderniza o sync Olist/Tiny para ler secrets persistidos, expor status operacional e manter OAuth com offline_access.',
        'Adiciona diagnostico versionado de Melhor Envio e Pagar.me para diferenciar endpoint ativo de gateway realmente autenticado.',
    ),
);
