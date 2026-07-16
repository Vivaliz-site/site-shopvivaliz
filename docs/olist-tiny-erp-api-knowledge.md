# Olist/Tiny ERP API v3 - Documento tecnico para agentes ShopVivaliz

**Data:** 2026-06-27  
**Repositorio:** fredmourao-ai/site-shopvivaliz  
**Escopo:** ShopVivaliz, Olist/Tiny ERP, integracao Shopee, produtos, estoque, pedidos, imagens, webhooks e agentes autonomos.

> **Regra absoluta:** este documento nao contem e nao deve receber credenciais reais. Nunca registrar login, senha, cookie, access token, refresh token, client secret, FTP, bearer token, payload de sessao ou arquivo de perfil autenticado. Agentes devem usar GitHub Secrets/ambiente seguro e imprimir apenas nomes de secrets encontrados/ausentes.

---

## 1. Nomenclatura correta

Usar preferencialmente **Olist/Tiny ERP** ou **Olist ERP API v3**. A documentacao publica se apresenta como **Olist ERP API v3** e informa que e uma API publica para integracao com o ERP da Olist. O fluxo de autenticacao usa dominios `accounts.tiny.com.br`, e a API operacional observada usa `api.tiny.com.br/public-api/v3`, portanto os agentes devem tratar Olist ERP e Tiny como o mesmo ecossistema operacional nesta integracao.

## 2. Fontes oficiais e arquivos de referencia

### 2.1 Fontes oficiais publicas

- Documentacao Olist ERP API v3: `https://api-docs.erp.olist.com/`
- Indice para LLMs: `https://api-docs.erp.olist.com/llms.txt`
- Criacao de aplicativo: `https://api-docs.erp.olist.com/documentacao/comecando/criando-um-aplicativo`
- Autenticacao OAuth: `https://api-docs.erp.olist.com/documentacao/comecando/autenticacao`
- Limites de consulta: `https://api-docs.erp.olist.com/documentacao/comecando/limites-de-consulta`
- OpenAPI/Swagger: `https://erp.olist.com/public-api/v3/swagger/swagger-mintlify.json`
- OpenAPI alternativo: `https://api-docs.erp.olist.com/api-reference/openapi.json`

### 2.2 Fontes internas ShopVivaliz usadas na consolidacao

- `AGENTS.md` do repositorio: regras de agentes, seguranca e automacoes Olist/Tiny.
- Registro tecnico ShopVivaliz: OAuth Olist/Tiny concluido, tokens salvos em local privado, endpoint de produtos testado com HTTP 200, produtos retornando `id`, `sku`, descricao, situacao, preco, estoque e outros campos.
- Tutorial administrador ShopVivaliz: fluxo correto e importar URLs da API ou XLS para banco local, e catalogo ler de `olist_product_images`.
- Conversas tecnicas sobre imagens: endpoint interno de gerenciamento de imagens, regra `excluido=S`, necessidade de cookie de sessao para endpoints internos e restricao de nao expor cookies.

## 3. Criacao do aplicativo API v3

Para integrar, deve ser criado um aplicativo na conta do ERP. O administrador autoriza o aplicativo e confirma as permissoes.

Regras operacionais:

1. Solicitar apenas permissoes necessarias.
2. Registrar a URL de redirecionamento usada pelo ShopVivaliz.
3. Guardar `client_id` e `client_secret` somente em secrets/ambiente seguro.
4. Se permissoes forem alteradas, o usuario precisa autorizar novamente.
5. As chamadas respeitam as permissoes do usuario autorizado no ERP.

## 4. Autenticacao OAuth 2.0

### 4.1 Authorization URL

Modelo:

```text
https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth?client_id=CLIENT_ID&redirect_uri=REDIRECT_URI&scope=openid&response_type=code
```

Variacao usada em testes:

```text
https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth?response_type=code&client_id=CLIENT_ID&redirect_uri=REDIRECT_URI&scope=openid
```

### 4.2 Token endpoint

```text
POST https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token
Content-Type: application/x-www-form-urlencoded
```

### 4.3 Trocar authorization code por tokens

```text
grant_type=authorization_code
client_id=CLIENT_ID
client_secret=CLIENT_SECRET
redirect_uri=REDIRECT_URI
code=AUTHORIZATION_CODE
```

### 4.4 Renovar access token

```text
grant_type=refresh_token
client_id=CLIENT_ID
client_secret=CLIENT_SECRET
refresh_token=REFRESH_TOKEN
```

### 4.5 Uso do access token

```text
Authorization: Bearer ACCESS_TOKEN
Content-Type: application/json
```

Notas conhecidas:

- O `access_token` expira e deve ser renovado.
- O `refresh_token` tambem expira; se expirado, repetir autorizacao.
- Em scripts, a variavel `$token` deve conter apenas o token, sem a palavra `Bearer`.
- `401 Unauthorized` geralmente indica token vazio, expirado, colado errado, sem permissao ou endpoint incompativel.

## 5. Secrets esperados no ambiente seguro

Nomes sugeridos para GitHub Secrets/ambiente:

```text
OLIST_API_BASE_URL
OLIST_CLIENT_ID
OLIST_CLIENT_SECRET
OLIST_REDIRECT_URI
OLIST_ACCESS_TOKEN
OLIST_REFRESH_TOKEN
OLIST_TOKEN_URL
TINY_API_BASE_URL
TINY_CLIENT_ID
TINY_CLIENT_SECRET
TINY_REDIRECT_URI
TINY_ACCESS_TOKEN
TINY_REFRESH_TOKEN
ERP_BASE_URL
ERP_SESSION_COOKIE
SHOPEE_PARTNER_ID
SHOPEE_PARTNER_KEY
SHOPEE_SHOP_ID
SHOPEE_ACCESS_TOKEN
SHOPEE_REFRESH_TOKEN
```

Agentes devem informar somente nomes ausentes/encontrados, nunca valores.

## 6. Base URLs

### 6.1 API publica v3

```text
https://api.tiny.com.br/public-api/v3
```

### 6.2 ERP Web

```text
https://erp.olist.com
```

### 6.3 Token/OAuth

```text
https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect
```

## 7. Endpoints publicos essenciais para ShopVivaliz/Shopee

| Area | Endpoint base/acao | Uso no ShopVivaliz |
|---|---|---|
| Produtos | `GET /produtos` | Listar produtos, obter IDs e SKUs para reconciliacao. |
| Produto | `GET /produtos/{id}` | Buscar detalhe do produto, campos completos e possiveis anexos/imagens. |
| Produto | `PUT /produtos/{id}` | Atualizar cadastro quando permitido pela API. Usar com cuidado. |
| Estoque | obter estoque de produto | Sincronizar disponibilidade com Shopee/site. |
| Estoque | atualizar estoque de produto | Ajustar estoque no ERP quando o fluxo autorizar. |
| Pedidos | listar/obter pedidos | Importar pedidos, auditar situacao e itens. |
| Pedidos | atualizar itens/rastreamento/situacao | Atualizar informacoes de pedido/logistica quando permitido. |
| Notas fiscais | listar/obter/autorizar/cancelar | Consultas fiscais e conciliacao, sem automatismo perigoso. |
| Expedicao/logistica | agrupamentos, etiquetas, formas de envio | Operacao logistica e rastreamento. |
| Contatos | listar/obter/criar/atualizar | Clientes/fornecedores. |
| Categorias/marcas | listar/obter/criar/atualizar | Normalizacao de catalogo. |
| Webhooks | configurar/receber eventos | Atualizacoes reativas do ERP para ShopVivaliz. |

## 8. Endpoint de produtos validado no projeto

Endpoint testado e registrado como funcional:

```text
GET https://api.tiny.com.br/public-api/v3/produtos?limit=1&offset=0
```

Formato de teste PowerShell:

```powershell
$token = "COLE_SEU_ACCESS_TOKEN_AQUI"
$headersApi = @{
  Authorization = "Bearer $token"
  "Content-Type" = "application/json"
}

Invoke-RestMethod `
  -Uri "https://api.tiny.com.br/public-api/v3/produtos?limit=1&offset=0" `
  -Method GET `
  -Headers $headersApi
```

Resultado esperado: HTTP 200 e lista de produtos. Campos observados no projeto: `id`, `sku`, descricao, tipo, situacao, datas, unidade, GTIN, precos, estoque e variacao.

## 9. Paginacao

Parametros usados/observados:

```text
limit=100
offset=0
```

Houve registro anterior de tentativa com `pagina=2&limit=50`, com risco de repeticao de assinatura/IDs. Para os agentes, o padrao recomendado e usar `limit` e `offset`, mantendo detector de IDs repetidos para evitar loop.

Regra anti-loop:

1. Guardar IDs ja vistos.
2. Se uma pagina retornar todos IDs repetidos, interromper.
3. Se `itens` vier vazio, interromper.
4. Respeitar delay entre chamadas.
5. Registrar pagina, offset, total recebido e total unico.

## 10. Imagens: fluxo publico ShopVivaliz

A loja publica nao deve buscar Olist/Tiny a cada visita. Fluxo correto:

1. Importar produtos e imagens para banco local.
2. Salvar URLs em tabela local, por exemplo `olist_product_images`.
3. Catalogo publico le somente banco local/CDN propria.
4. Reconciliar por SKU, ID Olist e ID local.
5. Rodar verificador para garantir que produtos com imagem exibivel aumentem.

Rotas internas ShopVivaliz ja documentadas:

```text
/admin/olist-api-diagnostic.php
/admin/olist-export-images.php
/admin/olist-image-reconcile.php
/admin/image-bulk-update.php
/admin/image-import-verifier.php
```

## 11. Imagens: endpoints internos do ERP observados

Alem da API publica v3, foram capturados endpoints internos usados pela interface do ERP. Estes endpoints exigem sessao autenticada/cookie e nao devem ser usados com credenciais expostas.

### 11.1 Obter dados/imagens

```text
POST https://erp.olist.com/services/produtos.server/2/Produto%5CGerenciadorImagens/obterDadosProdutoParaGerenciadorImagens
```

Tambem foi usado/planejado:

```text
POST https://erp.olist.com/services/produtos.server/2/Produto%5CProdutoImagemInterna/obterImagensParaCadastroProduto
```

### 11.2 Salvar imagens

```text
POST https://erp.olist.com/services/produtos.server/2/Produto%5CGerenciadorImagens/salvarImagens
```

Estrutura de `args` observada:

```json
[
  ["ID_PRODUTO"],
  ["IMAGENS_INTERNAS"],
  ["IMAGENS_EXTERNAS"]
]
```

Regra validada via interface:

```text
Imagem mantida:  "excluido": "N"
Imagem removida: "excluido": "S"
```

Observacao critica: em teste manual, produto tinha 12 imagens, foi removida 1, e a imagem removida permaneceu no payload marcada com `excluido=S`. Para limpeza equivalente a tela, manter imagens desejadas com `N`, marcar excedentes com `S` e tratar externas no terceiro array.

## 12. Limite e regras operacionais de imagens

Para Shopee/ShopVivaliz:

1. Fonte oficial: cadastro do produto no ERP/Olist/Tiny e arquivos ja vinculados ao cadastro.
2. Nao usar planilha como fonte principal.
3. Nao usar raspagem publica como fonte principal.
4. Validar capa, ordem, duplicadas, quebradas, pequenas, fora do SKU, URL/CDN com erro e imagens padrao erradas.
5. Registrar antes/depois por SKU.
6. Manter rollback por SKU.
7. Para importacao local, aceitar no maximo 10 imagens por item, salvo regra especifica.
8. Para limpeza interna capturada, usar teste em lote pequeno antes de qualquer execucao massiva.

## 13. Fluxo recomendado para Agente 4 - Imagens Shopee

1. Validar secrets por nome, sem imprimir valores.
2. Testar API publica Olist/Tiny com `GET /produtos?limit=1&offset=0`.
3. Listar produtos Shopee vinculados por SKU.
4. Ler cadastro oficial no ERP/Olist/Tiny.
5. Comparar imagens atuais do anuncio Shopee com imagens oficiais do cadastro.
6. Auditar capa e galeria.
7. Corrigir somente quando houver confianca alta.
8. Registrar: SKU, imagem antiga, imagem nova, fonte, motivo, risco, rollback.
9. Liberar relatorio ao Diretor antes de sincronizacao final quando houver risco.

## 14. Webhooks

A documentacao oficial lista grupo de webhooks. Para ShopVivaliz, os webhooks devem ser usados para:

- Atualizacao de produto.
- Alteracao de estoque.
- Alteracao de pedido.
- Alteracao fiscal/logistica, se disponivel.

Regras:

1. Validar assinatura/segredo, se oferecido pela plataforma.
2. Registrar evento bruto em log seguro sem tokens.
3. Fazer processamento idempotente.
4. Nao confiar apenas no webhook: quando necessario, consultar o recurso por ID na API.

## 15. Erros comuns e diagnostico

| Erro | Possivel causa | Acao |
|---|---|---|
| HTTP 401 | Token expirado, vazio, errado, sem permissao | Renovar token; validar secrets; nao imprimir valor. |
| HTTP 403 | Permissao insuficiente | Revisar permissoes do aplicativo e reautorizar. |
| HTTP 404 | Endpoint incorreto ou recurso inexistente | Confirmar rota e ID. |
| Loop de paginacao | Parametro de pagina ignorado ou resposta repetida | Usar offset e detector de IDs repetidos. |
| Catalogo sem imagem | Listagem nao trouxe imagens | Buscar detalhe do produto/anexos ou reconciliar via exportacao. |
| API v3 nao remove imagens antigas | Campo de imagens/anexos nao substitui como esperado | Usar endpoint interno apenas em ambiente autorizado, ou chamado ao suporte. |
| Imagens orfas | Sem vinculo a produto ativo | Abrir chamado no suporte Olist/Tiny; API pode nao alcancar. |

## 16. Chamado ao suporte para imagens orfas

Quando existirem imagens orfas, sem vinculo com produto ativo, a API publica pode nao alcancar esses registros. O caminho seguro e abrir chamado com suporte Olist/Tiny solicitando limpeza estrutural com validacao previa de quantidade afetada, backup e reversibilidade.

Texto-base:

```text
Solicito apoio tecnico para limpeza de imagens externas/orfas no Olist/Tiny ERP.
Identificamos imagens vinculadas a produtos ativos e imagens orfas sem vinculo com produtos ativos.
Como nao temos acesso direto ao banco e a API pode nao alcancar imagens orfas, pedimos avaliacao tecnica e limpeza estrutural segura.
Solicitamos validar a quantidade de registros afetados antes da execucao e confirmar backup/reversibilidade.
Objetivo: remover imagens externas indevidas/orfas, preservando imagens validas do Olist/Tiny e imagens corretas dos produtos.
```

## 17. Politica de seguranca para agentes

- Nunca commitar `login_config.json`, cookies, HARs com cookies, perfis Chrome, dumps de sessao, logs com tokens ou screenshots sensiveis.
- Nunca registrar valores de secrets.
- Redigir logs com mascaramento.
- Preferir API publica.
- Usar endpoints internos somente em ambiente autorizado e quando API publica nao cobrir a acao.
- Antes de escrita em massa, rodar dry-run e amostra pequena.
- Toda escrita precisa ter rollback ou plano de reversao.

## 18. Comandos seguros para validar ambiente

Validar apenas nomes de variaveis:

```bash
printenv | grep -E 'OLIST|TINY|ERP|SHOPEE' | sed 's/=.*/=***MASKED***/'
```

Teste API produtos com PowerShell:

```powershell
$token = $env:OLIST_ACCESS_TOKEN
$headers = @{ Authorization = "Bearer $token"; "Content-Type" = "application/json" }
Invoke-RestMethod -Uri "https://api.tiny.com.br/public-api/v3/produtos?limit=1&offset=0" -Method GET -Headers $headers
```

## 19. Regras para gravar conhecimento no repositorio

Arquivo recomendado:

```text
docs/olist-tiny-erp-api-knowledge.md
```

Tambem atualizar `AGENTS.md` com referencia curta:

```text
Para integracao Olist/Tiny ERP API v3, consultar docs/olist-tiny-erp-api-knowledge.md. Nunca expor secrets, tokens, cookies ou sessoes. Usar API publica primeiro; endpoints internos somente em ambiente autorizado.
```

## 20. Prioridades para os agentes Shopee

1. Escopo somente Shopee.
2. Fonte oficial: ERP/Olist/Tiny.
3. API primeiro; painel so quando necessario.
4. Imagens: capa forte, galeria coerente, sem imagem padrao errada.
5. Logs antes/depois e rollback.
6. Diretor dos Agentes Shopee decide liberacao final quando houver risco.

---

## Anexo A - Checklist minimo antes de rodar em massa

- [ ] Secrets presentes por nome.
- [ ] API produtos retorna HTTP 200.
- [ ] Amostra de 1 produto validada.
- [ ] Amostra de 10 produtos validada.
- [ ] Relatorio antes/depois gerado.
- [ ] Rollback disponivel.
- [ ] Nenhum token/cookie impresso em log.
- [ ] Diretor aprovou operacao de risco.

## Anexo B - Resultado esperado do Agente 4

```text
secrets encontrados: nomes apenas
secrets ausentes: nomes apenas
total de anuncios Shopee lidos
SKUs com imagens OK
SKUs com imagem ausente
SKUs com capa incorreta
SKUs corrigidos
erros pendentes
log antes/depois
rollback disponivel por SKU
```
