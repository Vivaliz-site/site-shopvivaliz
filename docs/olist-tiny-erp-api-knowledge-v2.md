# Olist/Tiny ERP API v3 - Base de conhecimento V2 para agentes ShopVivaliz

**Versao:** 2.0  
**Data:** 2026-06-27  
**Repositorio:** fredmourao-ai/site-shopvivaliz  
**Escopo:** Olist/Tiny ERP, ShopVivaliz, integracao Shopee, produtos, estoque, pedidos, imagens, webhooks, auditoria e agentes autonomos.

> Documento operacional para agentes. Nao contem credenciais reais e nunca deve receber tokens, senhas, cookies, client secrets, FTP, HARs autenticados, prints com sessao ou dumps privados.

---

## 1. Nomenclatura oficial do projeto

Neste projeto, usar a nomenclatura:

- **Olist/Tiny ERP** para o ecossistema operacional usado pelo usuario.
- **Olist ERP API v3** para a API publica documentada.
- **Tiny/Olist API v3** quando o codigo ou endpoint usar dominio `tiny.com.br`.

Observacao importante: a documentacao publica usa a marca Olist ERP API v3, enquanto endpoints tecnicos observados no projeto usam `api.tiny.com.br` e `accounts.tiny.com.br`. Os agentes devem considerar estes nomes como partes do mesmo fluxo operacional, sem trocar por fontes nao autorizadas.

## 2. Fontes oficiais e internas

### 2.1 Fontes publicas oficiais

- Documentacao Olist ERP API v3: `https://api-docs.erp.olist.com/`
- Indice LLM: `https://api-docs.erp.olist.com/llms.txt`
- Criacao de aplicativo: `https://api-docs.erp.olist.com/documentacao/comecando/criando-um-aplicativo`
- Autenticacao: `https://api-docs.erp.olist.com/documentacao/comecando/autenticacao`
- Limites de consulta: `https://api-docs.erp.olist.com/documentacao/comecando/limites-de-consulta`
- Swagger/OpenAPI: `https://erp.olist.com/public-api/v3/swagger/swagger-mintlify.json`
- OpenAPI alternativo: `https://api-docs.erp.olist.com/api-reference/openapi.json`

### 2.2 Fontes internas ShopVivaliz

- `AGENTS.md`: regras de agentes, seguranca, automacoes Olist/Tiny e releases.
- `docs/olist-tiny-erp-api-knowledge.md`: V1 desta base.
- Conversas tecnicas do projeto: OAuth validado, endpoint de produtos retornando HTTP 200, produtos com `id`, `sku`, descricao, situacao, preco e estoque.
- Fluxo administrador ShopVivaliz: importacao de imagens para tabela local `olist_product_images` e leitura local pelo catalogo publico.
- Capturas tecnicas de imagens no ERP: endpoints internos `GerenciadorImagens`, regra `excluido=S` e necessidade de sessao autenticada.

## 3. Regra absoluta de seguranca

Agentes devem validar credenciais apenas por nome. Nunca imprimir valores.

Proibido registrar:

- login ou senha;
- access token;
- refresh token;
- client secret;
- cookies de sessao;
- FTP;
- chaves Shopee;
- HAR autenticado;
- perfil Chrome autenticado;
- dumps de banco com dados reais;
- screenshots que exponham dados sensiveis.

Comando seguro para conferir variaveis sem expor valor:

```bash
printenv | grep -E 'OLIST|TINY|ERP|SHOPEE' | sed 's/=.*/=***MASKED***/'
```

## 4. Secrets esperados

Nomes aceitos/sugeridos no GitHub Secrets ou runtime seguro:

```text
OLIST_API_BASE_URL
OLIST_API_URL
OLIST_CLIENT_ID
OLIST_CLIENT_SECRET
OLIST_REDIRECT_URI
OLIST_ACCESS_TOKEN
OLIST_REFRESH_TOKEN
OLIST_TOKEN_URL
TINY_API_BASE_URL
TINY_API_URL
OLIST_CLIENT_ID
OLIST_CLIENT_SECRET
TINY_REDIRECT_URI
TINY_ACCESS_TOKEN
TINY_REFRESH_TOKEN
TINY_API_TOKEN
ERP_BASE_URL
ERP_API_TOKEN
ERP_SESSION_COOKIE
SHOPEE_PARTNER_ID
SHOPEE_PARTNER_KEY
SHOPEE_SHOP_ID
SHOPEE_ACCESS_TOKEN
SHOPEE_REFRESH_TOKEN
```

Se um secret estiver ausente, o agente deve informar somente o nome do secret ausente.

## 5. OAuth 2.0

### 5.1 Authorization URL

```text
https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth?response_type=code&client_id=CLIENT_ID&redirect_uri=REDIRECT_URI&scope=openid
```

### 5.2 Token endpoint

```text
POST https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token
Content-Type: application/x-www-form-urlencoded
```

### 5.3 Troca de code por token

```text
grant_type=authorization_code
client_id=CLIENT_ID
client_secret=CLIENT_SECRET
redirect_uri=REDIRECT_URI
code=AUTHORIZATION_CODE
```

### 5.4 Refresh token

```text
grant_type=refresh_token
client_id=CLIENT_ID
client_secret=CLIENT_SECRET
refresh_token=REFRESH_TOKEN
```

### 5.5 Header de chamada autenticada

```text
Authorization: Bearer ACCESS_TOKEN
Content-Type: application/json
```

Regras:

1. `access_token` e temporario.
2. `refresh_token` tambem pode expirar.
3. Se receber `401`, tentar refresh uma vez; se persistir, parar e reportar sem expor valores.
4. Nunca colocar a palavra `Bearer` dentro da variavel do token; montar apenas no header.

## 6. Base URLs

```text
API publica v3: https://api.tiny.com.br/public-api/v3
OAuth:          https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect
ERP Web:        https://erp.olist.com
```

## 7. Endpoint validado no projeto

Endpoint ja validado no fluxo ShopVivaliz:

```text
GET https://api.tiny.com.br/public-api/v3/produtos?limit=1&offset=0
```

Teste PowerShell seguro:

```powershell
$token = $env:OLIST_ACCESS_TOKEN
$headersApi = @{
  Authorization = "Bearer $token"
  "Content-Type" = "application/json"
}
Invoke-RestMethod -Uri "https://api.tiny.com.br/public-api/v3/produtos?limit=1&offset=0" -Method GET -Headers $headersApi
```

Campos observados no projeto: `id`, `sku`, descricao, tipo, situacao, datas, unidade, GTIN, precos, estoque e variacao.

## 8. Endpoints publicos essenciais

| Area | Endpoint/acao | Uso operacional |
|---|---|---|
| Produtos | `GET /produtos` | Listar produtos, paginar por `limit` e `offset`, mapear SKU/ID. |
| Produto | `GET /produtos/{id}` | Consultar detalhe completo do produto. |
| Produto | `PUT /produtos/{id}` | Atualizar cadastro quando permitido e aprovado. |
| Estoque | consulta/alteracao de estoque | Sincronizar disponibilidade site/Shopee. |
| Pedidos | listar/obter pedidos | Importar pedidos, itens e status. |
| Pedidos | atualizar situacao/rastreamento | Sincronizar logistica quando permitido. |
| NFe | listar/obter/autorizar/cancelar | Fiscal e conciliacao. Usar com cautela. |
| Expedicao | agrupamentos, etiquetas, formas de envio | Operacao logistica. |
| Contatos | listar/criar/atualizar | Clientes/fornecedores. |
| Categorias | listar/criar/atualizar | Normalizacao do catalogo. |
| Marcas | listar/criar/atualizar | Normalizacao do catalogo. |
| Webhooks | receber eventos | Processamento reativo e idempotente. |

## 9. Paginacao e anti-loop

Padrao recomendado:

```text
limit=100
offset=0,100,200,...
```

Regra anti-loop obrigatoria:

1. Guardar IDs ja vistos.
2. Se a pagina retornar somente IDs repetidos, interromper.
3. Se vier vazia, interromper.
4. Registrar offset, quantidade recebida e quantidade unica.
5. Aplicar delay quando necessario.
6. Nunca rodar infinito em workflow.

## 10. Imagens no ShopVivaliz

A loja publica nao deve depender de chamada ao ERP em cada visita.

Fluxo correto:

1. Importar produtos/imagens da API ou fonte autorizada para banco local.
2. Salvar URLs por produto/SKU, por exemplo em `olist_product_images`.
3. Exibir imagens pelo banco local/CDN propria.
4. Reconciliar por SKU, ID Olist e ID local.
5. Validar imagem principal, ordem, duplicidade e imagens quebradas.

Rotas internas do admin ShopVivaliz ja conhecidas:

```text
/admin/olist-api-diagnostic.php
/admin/olist-export-images.php
/admin/olist-image-reconcile.php
/admin/image-bulk-update.php
/admin/image-import-verifier.php
```

## 11. Imagens no ERP: endpoints internos capturados

Os endpoints abaixo sao internos da interface web do ERP. Exigem sessao autenticada. Usar somente em ambiente autorizado quando a API publica nao cobrir a operacao.

### 11.1 Obter dados do gerenciador de imagens

```text
POST https://erp.olist.com/services/produtos.server/2/Produto%5CGerenciadorImagens/obterDadosProdutoParaGerenciadorImagens
```

Endpoint alternativo observado/planejado:

```text
POST https://erp.olist.com/services/produtos.server/2/Produto%5CProdutoImagemInterna/obterImagensParaCadastroProduto
```

### 11.2 Salvar imagens

```text
POST https://erp.olist.com/services/produtos.server/2/Produto%5CGerenciadorImagens/salvarImagens
```

Estrutura observada de `args`:

```json
[
  ["ID_PRODUTO"],
  ["IMAGENS_INTERNAS"],
  ["IMAGENS_EXTERNAS"]
]
```

Regra validada na interface:

```text
Imagem mantida:  "excluido": "N"
Imagem removida: "excluido": "S"
```

Observacao operacional: em teste manual, ao remover uma imagem, ela permaneceu no payload marcada como `excluido=S`. Portanto, para replicar a tela com seguranca, manter imagens corretas como `N` e marcar removidas/excedentes como `S`, preservando rollback.

## 12. Regras para Agente 4 - Imagens Shopee

Objetivo: todos os anuncios Shopee devem ter capa forte, galeria completa, nitida e coerente com o SKU, sem imagem padrao errada.

Fonte oficial:

1. Cadastro do produto no ERP/Olist/Tiny.
2. Arquivos/imagens ja vinculados ao cadastro.
3. Banco local ShopVivaliz, somente como reflexo da fonte oficial.

Nao usar como fonte principal:

- planilha;
- raspagem publica;
- imagem de marketplace de terceiros;
- busca manual sem vinculo com SKU.

Auditorias obrigatorias:

- capa atual;
- ordem da galeria;
- imagens duplicadas;
- imagens quebradas;
- imagens pequenas;
- imagem fora do produto;
- URL/CDN com erro;
- imagem padrao errada;
- incompatibilidade com SKU/anuncio.

## 13. Fluxo autonomo recomendado para imagens Shopee

1. Validar secrets por nome.
2. Testar `GET /produtos?limit=1&offset=0`.
3. Listar anuncios/produtos Shopee por SKU no ambiente autorizado.
4. Consultar cadastro oficial no ERP/Olist/Tiny.
5. Comparar imagens atuais do anuncio Shopee contra imagens oficiais.
6. Classificar risco: baixo, medio ou alto.
7. Corrigir automaticamente apenas risco baixo/confianca alta.
8. Para risco medio/alto, gerar relatorio para Diretor dos Agentes Shopee.
9. Registrar antes/depois por SKU.
10. Manter rollback.

Formato de log por SKU:

```json
{
  "sku": "SKU",
  "canal": "Shopee",
  "imagem_antiga": "URL_OU_ID_ANTIGO_MASCARADO_QUANDO_NECESSARIO",
  "imagem_nova": "URL_OU_ID_NOVO",
  "fonte": "ERP/Olist/Tiny",
  "motivo": "capa quebrada | duplicada | fora do produto | ausente | pequena | errada",
  "risco": "baixo | medio | alto",
  "rollback": "descricao do rollback",
  "status": "corrigido | pendente | bloqueado"
}
```

## 14. Webhooks

Usos previstos:

- produto atualizado;
- estoque alterado;
- pedido criado/alterado;
- nota fiscal/logistica, se disponivel;
- sincronizacao reativa do ShopVivaliz.

Regras:

1. Validar assinatura/segredo quando disponivel.
2. Salvar evento bruto em log seguro sem tokens.
3. Processar de forma idempotente.
4. Consultar o recurso por ID na API antes de aplicar mudanca critica.
5. Nao confiar cegamente no payload do webhook.

## 15. Diagnostico de erros

| Sintoma | Causa provavel | Acao |
|---|---|---|
| 401 | Token expirado, vazio, errado ou sem permissao | Refresh uma vez; se falhar, reportar secret por nome. |
| 403 | Permissao insuficiente | Revisar permissoes do app e reautorizar. |
| 404 | Endpoint/ID incorreto | Confirmar rota, ID e base URL. |
| Pagina repetida | Paginacao incorreta | Usar offset e detector de repeticao. |
| Produto sem imagem | Listagem nao trouxe detalhe | Consultar produto por ID e reconciliar imagens. |
| Imagem orfa | Registro sem produto ativo | Suporte Olist/Tiny ou rotina interna autorizada. |
| CDN falha | URL expirada/quebrada | Trocar por imagem oficial valida. |
| Capa errada | Galeria desordenada | Reordenar capa a partir da fonte oficial. |

## 16. Chamado ao suporte Olist/Tiny para imagens orfas

```text
Solicito apoio tecnico para limpeza de imagens externas/orfas no Olist/Tiny ERP.
Identificamos imagens vinculadas a produtos ativos e imagens orfas sem vinculo com produtos ativos.
Como nao temos acesso direto ao banco e a API pode nao alcancar imagens orfas, pedimos avaliacao tecnica e limpeza estrutural segura.
Solicitamos validar a quantidade de registros afetados antes da execucao e confirmar backup/reversibilidade.
Objetivo: remover imagens externas indevidas/orfas, preservando imagens validas do Olist/Tiny e imagens corretas dos produtos.
```

## 17. Checklist antes de rodar em massa

- [ ] Secrets presentes por nome.
- [ ] Nenhum valor sensivel impresso.
- [ ] API de produtos retorna HTTP 200.
- [ ] Amostra de 1 SKU validada.
- [ ] Amostra de 10 SKUs validada.
- [ ] Antes/depois gerado.
- [ ] Rollback disponivel.
- [ ] Diretor dos Agentes Shopee aprovou operacao de risco.
- [ ] Escopo limitado a Shopee.

## 18. Resultado esperado do Agente 4

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

## 19. Ordem para o Diretor dos Agentes Shopee

```text
Diretor, repassar ao Agente 4 que a base oficial de API e imagens esta em docs/olist-tiny-erp-api-knowledge-v2.md.
Executar somente em ambiente autorizado, usando GitHub Secrets/ambiente seguro.
Validar secrets por nome, testar API publica Olist/Tiny e iniciar auditoria de imagens Shopee por SKU.
Nao expor credenciais.
Nao usar planilha ou raspagem publica como fonte principal.
Registrar antes/depois e rollback.
```

---

## 20. Diferencas da V2

- Inclui nomenclatura final Olist/Tiny ERP.
- Separa fontes publicas, internas e endpoints internos.
- Amplia lista de secrets aceitos.
- Define fluxo autonomo do Agente 4 para imagens Shopee.
- Inclui formato de log JSON por SKU.
- Inclui checklist de execucao em massa.
- Inclui ordem direta para o Diretor dos Agentes Shopee.
