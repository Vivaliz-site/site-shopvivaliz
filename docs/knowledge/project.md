# ShopVivaliz — Visão Geral

## Visão geral

O ShopVivaliz é uma plataforma de comércio eletrônico com storefront público, área administrativa, integrações de catálogo e marketplaces, automações operacionais e recursos de inteligência artificial. A base atual combina PHP, JavaScript, MySQL, GitHub Actions e serviços externos, enquanto a arquitetura evolui de forma incremental.

## Objetivo do sistema

Centralizar catálogo, preços, estoque, imagens, anúncios, pedidos, atendimento e operação técnica em uma única base confiável. O sistema deve reduzir tarefas manuais, manter rastreabilidade das alterações e permitir atualizações cumulativas que possam ser instaladas sem depender das versões intermediárias.

## Módulos principais

### Admin

Área de gestão para produtos, preços, imagens, pedidos, integrações, configurações, diagnósticos e acompanhamento operacional.

### Olist

Integração responsável por importar e sincronizar produtos, SKUs, preços, estoque, imagens e demais dados comerciais provenientes da Olist/Tiny. Dados exibidos no storefront devem ser validados contra a origem disponível antes da publicação.

### Imagens IA

Recursos para geração, tratamento, seleção e associação de imagens aos produtos. Toda imagem publicada deve manter vínculo verificável com o SKU ou identificador correto, evitando associações por nome aproximado.

### Anúncios

Módulo destinado à criação, otimização e acompanhamento de anúncios e campanhas em canais externos. Preço, estoque e logística não devem ser inventados ou alterados sem evidência da fonte comercial.

### Squad Chat

Canal de comunicação com agentes de IA por meio do endpoint `/api/agent/squad-chat.php`, utilizado para diagnóstico, orientação e apoio operacional.

### Atualizador

Mecanismo de atualização cumulativa do sistema. Cada pacote deve conter tudo o que for necessário desde a base compatível declarada, permitindo pular versões intermediárias. SQLs, migrations e reparos de vínculo devem ser executados automaticamente durante a atualização, com registro de sucesso, falha e idempotência.

## Princípios operacionais

- Nunca assumir estado de produção sem teste ou evidência.
- Preferir alterações cumulativas e reversíveis.
- Não expor credenciais em código, documentação ou logs.
- Validar catálogo, preço, imagem, estoque e frete antes de afirmar que estão corretos.
- Manter documentação e automações alinhadas com o comportamento real do sistema.
