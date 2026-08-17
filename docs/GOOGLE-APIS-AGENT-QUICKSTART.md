# Google APIs — Quickstart para Agentes

Leia primeiro: [`GOOGLE-APIS-OAUTH-INTEGRATION.md`](./GOOGLE-APIS-OAUTH-INTEGRATION.md).

## Regras de 30 segundos

1. Nunca copie secrets/tokens para código, prompt de log, issue, PR ou artifact.
2. Use o refresh OAuth compartilhado; nunca reutilize access token salvo manualmente.
3. Rode leitura/health check antes de mutações.
4. Search Console: diagnostique por lote e preserve o JSON de evidência.
5. Merchant: use Merchant API `v1`, não Content API for Shopping.
6. Indexing API: não enviar produto, catálogo ou artigo comum.
7. GTM: revisar workspace/version antes de publicar.
8. GA4: `GA4_ID` (`G-...`) não é `GOOGLE_GA4_PROPERTY_ID` numérico.

## 1. Validar OAuth e serviços

```bash
php scripts/google-api-health.php
```

Esse comando é read-only. Ele valida:

- troca do refresh token por access token;
- propriedades acessíveis no Search Console;
- contas acessíveis no GTM;
- contas acessíveis na Merchant API v1;
- GA4 Data API, se `GOOGLE_GA4_PROPERTY_ID` estiver definido.

A Indexing API não recebe URL de teste propositalmente.

## 2. Auditar erros do Search Console

```bash
php scripts/google-search-console-audit.php \
  --max-urls=100 \
  --output=reports/google-search-console-audit.json
```

Próximo lote:

```bash
php scripts/google-search-console-audit.php \
  --max-urls=100 \
  --offset=100 \
  --output=reports/google-search-console-audit-100.json
```

O auditor prefere `sitemap.xml`. Se o sitemap estiver saudável para navegador/Google, mas um runner externo receber 403/5xx, o script não deve alterar WAF nem simular Googlebot. Ele registra `sitemapFetchError` e usa automaticamente páginas reais da Search Analytics dos últimos 90 dias como fallback. O campo `urlSource` do JSON indica `sitemap` ou `search-analytics-fallback`.

Se houver múltiplas propriedades para o mesmo host, configure explicitamente:

```dotenv
GOOGLE_SEARCH_CONSOLE_SITE_URL=sc-domain:shopvivaliz.com.br
```

ou o URL-prefix exato cadastrado no Search Console, incluindo a `/` final.

### Limpeza do sitemap legado

O Search Console deve manter apenas o sitemap canônico `https://shopvivaliz.com.br/sitemap.xml`. O antigo `https://www.shopvivaliz.com.br/sitemap.xml` pertence ao host legado e hoje não é uma fonte válida de URLs.

```bash
php scripts/google-search-console-sitemap-cleanup.php
```

Esse comando é mutável, mas possui allow-list fixa e é idempotente: ele só pode remover exatamente o sitemap `www`, exige que o sitemap canônico esteja registrado antes de qualquer exclusão e verifica o estado final após o DELETE. Nunca transforme esse utilitário em um "delete sitemap" genérico sem revisão explícita.

## 3. Prioridade de correção do relatório

Ordem sugerida:

1. `PAGE_FETCH_*` / 5xx / falha de rastreamento;
2. `ROBOTS_*` e bloqueios de indexação não intencionais;
3. `INDEXING_NOT_ALLOWED`;
4. `CANONICAL_MISMATCH`;
5. demais verdicts/coverage states;
6. redirects 301 históricos já intencionais ficam por último.

Não altere canonical/redirect em massa apenas pelo nome do erro. Compare `url`, `userCanonical`, `googleCanonical`, `coverageState` e o HTTP real.

## 4. Execução automática

O workflow `.github/workflows/google-search-console-audit.yml` executa o auditor diariamente e permite execução manual com `max_urls` e `offset`. Antes da auditoria ele executa a limpeza allow-listed do sitemap legado `www`. O relatório JSON fica disponível como artifact por 30 dias.

## 5. Arquivos compartilhados

- PHP OAuth: `src/Google/OAuthTokenProvider.php`
- PHP HTTP Google: `src/Google/GoogleApiClient.php`
- Python OAuth: `scripts/lib/google_oauth.py`
- Health check: `scripts/google-api-health.php`
- Auditor Search Console: `scripts/google-search-console-audit.php`
- Limpeza allow-listed de sitemap: `scripts/google-search-console-sitemap-cleanup.php`
- Guia completo: `docs/GOOGLE-APIS-OAUTH-INTEGRATION.md`
