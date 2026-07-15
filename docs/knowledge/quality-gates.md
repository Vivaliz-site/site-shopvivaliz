# Quality Gates

## Objetivo

Impedir que alterações críticas sejam incorporadas sem validação mínima de documentação, rotas, segurança, assets, endpoints e contratos do atualizador.

## Validações automatizadas

- Knowledge base obrigatória presente e não vazia.
- Critérios de health do Squad Chat documentados.
- Assets críticos do storefront presentes.
- Rotas canônicas de carrinho, checkout, produto e pedido protegidas.
- Headers e bloqueios básicos do Apache presentes.
- Contrato do atualizador cumulativo preservado.
- Padrões comuns de segredos ausentes no repositório.
- Endpoints críticos contendo respostas e validações esperadas.

## Execução local

```bash
php scripts/quality/run-all.php
```

## Regra de merge

Qualquer falha deve bloquear o merge até que a causa seja corrigida ou documentada com evidência suficiente. Não usar `|| true` em verificações obrigatórias.
