# Google APIs + OAuth 2.0 — Guia Operacional ShopVivaliz

> **Leitura obrigatória para agentes que mexam em Search Console, Merchant Center, GTM, GA4 ou Indexing API.**
>
> Atualizado em 2026-08-16. Este documento descreve o padrão autorizado do projeto. Nunca copie valores reais de credenciais para código, documentação, logs, issues ou PRs.

## 1. Credenciais e fonte de verdade

As rotinas Google usam somente estas variáveis de ambiente:

```dotenv
GOOGLE_OAUTH_CLIENT_ID=
GOOGLE_OAUTH_CLIENT_SECRET=
GOOGLE_OAUTH_REFRESH_TOKEN=
```

Produção carrega segredos pelo mecanismo já existente em `config/constants.php`, incluindo o `.env` da release e o `shared/.env` do deploy. GitHub Actions usa os GitHub Secrets com os mesmos nomes.

Variáveis operacionais opcionais:

```dotenv
# URL pública canônica da loja
GOOGLE_SITE_BASE_URL=https://shopvivaliz.com.br

# Se vazio, o auditor tenta descobrir a propriedade acessível do host de produção.
# Para propriedade de prefixo, use exatamente o formato do Search Console, incluindo / final.
# Para propriedade de domínio, use sc-domain:shopvivaliz.com.br
GOOGLE_SEARCH_CONSOLE_SITE_URL=

GOOGLE_SEARCH_CONSOLE_SITEMAP_URL=https://shopvivaliz.com.br/sitemap.xml
GOOGLE_SEARCH_CONSOLE_AUDIT_MAX_URLS=100

# Preencher somente quando uma automação específica exigir estes IDs.
GOOGLE_GA4_PROPERTY_ID=
GOOGLE_GTM_ACCOUNT_ID=
GOOGLE_GTM_CONTAINER_ID=
GOOGLE_MERCHANT_ACCOUNT_ID=
```

### Nunca fazer

- Nunca versionar client secret, refresh token ou access token.
- Nunca imprimir `Authorization`, refresh token ou resposta bruta do endpoint OAuth.
- Nunca salvar access token em arquivo para "reusar depois". Ele é temporário.
- Nunca substituir o refresh token por um access token em `.env` ou GitHub Secrets.
- Nunca colocar segredos em query string.
- Nunca tentar contornar quotas criando contas/projetos adicionais.

## 2. Padrão obrigatório de renovação automática

Toda rotina PHP ou Python deve obter o access token em tempo de execução via:

```text
POST https://oauth2.googleapis.com/token
Content-Type: application/x-www-form-urlencoded

grant_type=refresh_token
client_id=$GOOGLE_OAUTH_CLIENT_ID
client_secret=$GOOGLE_OAUTH_CLIENT_SECRET
refresh_token=$GOOGLE_OAUTH_REFRESH_TOKEN
```

O repositório fornece duas implementações compartilhadas:

- PHP: `ShopVivaliz\Google\OAuthTokenProvider` em `src/Google/OAuthTokenProvider.php`.
- Python: `GoogleOAuthTokenProvider` em `scripts/lib/google_oauth.py`.

O cliente PHP `ShopVivaliz\Google\GoogleApiClient` injeta `Authorization: Bearer <access_token>` e, em HTTP 401, força **uma única** renovação de token e repete a chamada. Não criar loops infinitos de refresh/retry.

### PHP

```php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../vendor/autoload.php';

use ShopVivaliz\Google\GoogleApiClient;
use ShopVivaliz\Google\OAuthTokenProvider;

$api = new GoogleApiClient(OAuthTokenProvider::fromEnvironment());
$response = $api->request('GET', 'https://www.googleapis.com/webmasters/v3/sites');
```

Se `vendor/autoload.php` não estiver disponível em um utilitário isolado, carregue explicitamente os dois arquivos de `src/Google/`, como faz `scripts/google-search-console-audit.php`.

### Python

```python
from scripts.lib.google_oauth import GoogleOAuthTokenProvider

tokens = GoogleOAuthTokenProvider.from_environment()
access_token = tokens.get_access_token()
```

Ao receber 401 em um cliente Python, faça no máximo uma nova tentativa com:

```python
access_token = tokens.get_access_token(force_refresh=True)
```

## 3. Escopos autorizados

| Serviço | Escopo OAuth | Uso no ShopVivaliz |
|---|---|---|
| Search Console | `https://www.googleapis.com/auth/webmasters` | inspeção de URLs, sitemaps e métricas de busca |
| GA4 Data API | `https://www.googleapis.com/auth/analytics.readonly` | leitura de tráfego, eventos e conversões |
| GTM | `https://www.googleapis.com/auth/tagmanager.edit.containers` + `https://www.googleapis.com/auth/tagmanager.publish` | editar e publicar contêineres quando solicitado |
| Merchant | `https://www.googleapis.com/auth/content` | autenticação da integração Merchant |
| Indexing API | `https://www.googleapis.com/auth/indexing` | **somente** URLs elegíveis descritas na seção 7 |

Princípio operacional: leitura/auditoria por padrão; alteração/publicação somente quando a tarefa exigir mutação e houver evidência do recurso-alvo correto.

## 4. Search Console — rotina principal de diagnóstico

O auditor oficial do projeto é:

```bash
php scripts/google-search-console-audit.php --max-urls=100
```

Ele:

1. carrega as credenciais do ambiente;
2. renova o access token dinamicamente;
3. lista as propriedades acessíveis do Search Console;
4. usa `GOOGLE_SEARCH_CONSOLE_SITE_URL` quando definido ou tenta resolver a propriedade do host de produção;
5. lê o sitemap público;
6. chama a URL Inspection API para cada URL selecionada;
7. registra `verdict`, `coverageState`, `indexingState`, `pageFetchState`, `robotsTxtState`, canonical declarada e canonical escolhida pelo Google;
8. gera um relatório JSON sem segredos.

Exemplos:

```bash
# Primeiras 200 URLs
php scripts/google-search-console-audit.php \
  --max-urls=200 \
  --output=reports/google-search-console-audit.json

# Próximo lote
php scripts/google-search-console-audit.php \
  --max-urls=200 \
  --offset=200 \
  --output=reports/google-search-console-audit-200.json

# Forçar a propriedade correta quando houver mais de uma
php scripts/google-search-console-audit.php \
  --site-url='sc-domain:shopvivaliz.com.br' \
  --max-urls=100
```

A URL Inspection API informa o estado da versão conhecida pelo índice do Google; ela não substitui um teste ao vivo da página. O endpoint usado é:

```text
POST https://searchconsole.googleapis.com/v1/urlInspection/index:inspect
```

Referência oficial: https://developers.google.com/webmaster-tools/v1/urlInspection.index/inspect

### Quotas

A documentação atual do Search Console informa para URL Inspection 2.000 consultas/dia e 600 consultas/minuto por propriedade. O auditor limita `--max-urls` a 1.900 e introduz atraso entre chamadas para não encostar no limite por minuto.

Referência oficial: https://developers.google.com/webmaster-tools/limits

### Interpretação dos principais problemas

- `CANONICAL_MISMATCH`: comparar `userCanonical` e `googleCanonical`. Verificar redirects, slash final, parâmetros, conteúdo duplicado e links internos antes de alterar canonical.
- `INDEXING_NOT_ALLOWED`: revisar `meta robots`, `X-Robots-Tag`, autenticação e outras regras de indexação.
- `ROBOTS_*`: revisar `robots.txt`; não "corrigir" bloqueio proposital de áreas internas.
- `PAGE_FETCH_*`: testar HTTP real, DNS/TLS, 5xx, loops de redirect, timeout e conteúdo entregue ao Googlebot.
- verdict diferente de `PASS`: usar `coverageState` como evidência complementar; não presumir a causa somente pelo nome genérico do erro.
- URL antiga com redirect 301 correto: normalmente é migração, não uma URL que deve voltar a ser indexável.

### Estado SEO observado no código

- `/sitemap.xml` é reescrito para `sitemap.php` em `.htaccess`.
- `robots.txt` aponta para `https://shopvivaliz.com.br/sitemap.xml`.
- páginas de produto declaram canonical para `/produto/<slug>` e páginas não encontradas retornam HTTP 404 com `noindex,follow`.
- o host legado `www.shopvivaliz.com.br` é consolidado por 301 no `.htaccess`; URLs históricas desse host podem continuar aparecendo no Google/Search Console durante a migração.

**Regra para agentes:** antes de modificar redirect/canonical em massa, rode o auditor e agrupe os erros por `coverageState`, canonical do Google e padrão de URL. Não trate URLs antigas redirecionadas como se fossem páginas novas a indexar.

## 5. Automação diária no GitHub

Workflow:

```text
.github/workflows/google-search-console-audit.yml
```

Ele roda diariamente e também aceita execução manual com `max_urls` e `offset`. O relatório é enviado como artifact `google-search-console-audit-<run_id>` por 30 dias.

Secrets necessários:

```text
GOOGLE_OAUTH_CLIENT_ID
GOOGLE_OAUTH_CLIENT_SECRET
GOOGLE_OAUTH_REFRESH_TOKEN
```

Repository Variables opcionais:

```text
GOOGLE_SEARCH_CONSOLE_SITE_URL
GOOGLE_SEARCH_CONSOLE_SITEMAP_URL
```

O job falha quando encontra URLs com problemas, mas o artifact é enviado com `if: always()` para preservar o diagnóstico.

## 6. Merchant Center — usar Merchant API, não criar integração nova na Content API

**Atenção de migração:** a Content API for Shopping está descontinuada e tem desligamento programado para **18 de agosto de 2026**. Em 2026-08-16 faltam dois dias para esse desligamento. Toda integração nova/corrigida deve usar a Merchant API atual (`v1`).

Base atual:

```text
https://merchantapi.googleapis.com/{SUB_API}/v1/...
```

A Merchant API v1 exige o registro de desenvolvedor/GCP descrito pela documentação do Google antes de usar os métodos v1. Não suponha que apenas possuir o escopo OAuth conclui essa etapa de conta.

Referências oficiais:

- https://developers.google.com/merchant/api/guides/compatibility/overview
- https://developers.google.com/merchant/api/guides/compatibility/migrate-v1beta-v1

### Regra para agentes Merchant

1. procurar e remover dependências novas de `shoppingcontent.googleapis.com`/Content API;
2. usar `merchantapi.googleapis.com` + `v1`;
3. confirmar `GOOGLE_MERCHANT_ACCOUNT_ID` antes de mutações;
4. validar developer registration da conta/projeto;
5. testar leitura antes de inserir/atualizar produtos;
6. não alterar preço, estoque ou disponibilidade sem comparar com a fonte canônica do catálogo/Tiny/Olist.

## 7. Indexing API — NÃO usar para produtos e artigos comuns

A Indexing API **não é uma API genérica para acelerar indexação de e-commerce**. A documentação atual do Google restringe seu uso a páginas que contenham:

- `JobPosting`; ou
- `BroadcastEvent` incorporado em `VideoObject` para transmissão ao vivo.

Portanto, agentes **não devem** enviar `/produto/...`, `/catalogo...` ou artigos comuns do blog para:

```text
https://indexing.googleapis.com/v3/urlNotifications:publish
```

Para produtos e blog, use sitemap, links internos, canonicals corretas e Search Console/URL Inspection para diagnóstico.

Referência oficial: https://developers.google.com/search/apis/indexing-api/v3/using-api

Se futuramente o ShopVivaliz publicar uma página realmente elegível, documente a evidência de `JobPosting`/`BroadcastEvent` antes de ativar notificações.

## 8. Google Analytics 4

Escopo atual é somente leitura. Agentes podem consultar relatórios da GA4 Data API quando `GOOGLE_GA4_PROPERTY_ID` estiver definido.

Regras:

- não confundir `GA4_ID`/Measurement ID (`G-...`) com Property ID numérico da Data API;
- não usar `GA4_SECRET` do Measurement Protocol como credencial da Data API;
- usar o OAuth compartilhado deste documento para relatórios;
- comparar timezone/datas do relatório antes de diagnosticar queda de tráfego/conversão;
- não criar eventos duplicados para "corrigir" relatório sem primeiro conferir GTM + tracking server-side existente.

## 9. Google Tag Manager

O refresh OAuth compartilhado atende os escopos de edição/publicação já autorizados.

Fluxo obrigatório para agentes:

1. identificar account/container/workspace corretos;
2. ler configuração atual;
3. criar/editar em workspace;
4. validar tags, triggers e variáveis;
5. revisar diff/version;
6. publicar somente quando a tarefa exigir publicação;
7. registrar container ID, version ID e motivo da mudança — nunca credenciais.

Não criar uma segunda tag de GA4/Ads antes de verificar `includes/head-analytics.php`, configuração existente do GTM e Measurement Protocol; duplicação produz pageviews/conversões duplicadas.

## 10. Troubleshooting OAuth/API

### `invalid_grant`

Possíveis causas: refresh token revogado/expirado, consentimento removido ou credencial incompatível. Não tente gerar tokens em loop. Pare a automação, valide o consentimento e substitua o segredo apenas no secret manager/`.env` protegido.

### HTTP 401

O `GoogleApiClient` PHP já força um refresh e repete uma vez. Se o segundo request continuar 401, trate como falha real de autenticação e não continue em loop.

### HTTP 403

Verifique primeiro:

- API habilitada no projeto;
- escopo presente no refresh token;
- usuário OAuth com permissão no recurso (propriedade Search Console, conta GTM, GA4, Merchant);
- IDs/account/property corretos;
- quotas e políticas do serviço.

### HTTP 429 / quota

Reduza lote/frequência. Nunca distribua chamadas entre contas para contornar limites.

## 11. Checklist obrigatório para outros agentes

Antes de operar qualquer Google API:

1. Ler este documento.
2. Confirmar serviço, recurso e se a ação é leitura ou mutação.
3. Usar `OAuthTokenProvider` compartilhado; não implementar credencial hardcoded.
4. Confirmar IDs/propriedades antes de mutar.
5. Em Search Console, executar auditor por lote e guardar JSON de evidência.
6. Em Merchant, usar Merchant API v1.
7. Em Indexing API, rejeitar produtos/blog comuns.
8. Em GTM, revisar antes de publicar.
9. Em GA4, manter consultas read-only com o escopo atual.
10. Nunca incluir segredos em PR, commit, artifact, issue ou log.

## 12. Fontes oficiais

- OAuth 2.0 Web Server Applications: https://developers.google.com/identity/protocols/oauth2/web-server
- Search Console API: https://developers.google.com/webmaster-tools/v1/api_reference_index
- URL Inspection: https://developers.google.com/webmaster-tools/v1/urlInspection.index/inspect
- Search Console quotas: https://developers.google.com/webmaster-tools/limits
- Merchant API migration: https://developers.google.com/merchant/api/guides/compatibility/overview
- Merchant API v1 migration: https://developers.google.com/merchant/api/guides/compatibility/migrate-v1beta-v1
- Indexing API: https://developers.google.com/search/apis/indexing-api/v3/using-api
