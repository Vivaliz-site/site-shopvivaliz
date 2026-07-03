# Histórico de Agentes Shopee — ShopVivaliz

**Repositório:** `fredmourao-ai/site-shopvivaliz`  
**Última atualização:** 2026-06-27  
**Branch de origem:** `claude/guth-portfolio-access-81jjq2`

> Documento de consulta para agentes. Descreve o que foi implementado, como usar, quais secrets são necessários e quais limitações existem.

---

## 1. Agentes implementados (v9.2.85)

### 1.1 ShopeeListingsExtractorAgent

| Campo | Valor |
|---|---|
| Arquivo | `agents/v9.2.85/app/ShopeeListingsExtractorAgent.php` |
| Script CLI | `agents/v9.2.85/scripts/fetch-shopee-listings.php` |
| Workflow | `.github/workflows/fetch-shopee-listings.yml` |
| Saída | `listings/shopee-listings-YYYYMMDD-HHmmss.json` |

**Função:** Busca todos os produtos do Tiny/Olist ERP via API v3 e salva em JSON.

**Campos extraídos por produto:**
- `id`, `sku`, `nome`, `situacao`, `preco`, `preco_promocional`
- `estoque`, `unidade`, `gtin`, `categoria`, `marca`, `imagens[]`, `qtd_variacoes`

**Como executar:**
```bash
# Via GitHub Actions (recomendado)
# Actions → Fetch Shopee Listings via Tiny API → Run workflow

# Local (com PHP 8.3+)
TINY_ACCESS_TOKEN=xxx php agents/v9.2.85/scripts/fetch-shopee-listings.php
OUTPUT_FILE=listings/meu-arquivo.json TINY_ACCESS_TOKEN=xxx php agents/v9.2.85/scripts/fetch-shopee-listings.php
```

---

### 1.2 ShopeeListingsOptimizationAgent

| Campo | Valor |
|---|---|
| Arquivo | `agents/v9.2.85/app/ShopeeListingsOptimizationAgent.php` |
| Script CLI | `agents/v9.2.85/scripts/optimize-shopee-listings.php` |
| Workflow | `.github/workflows/optimize-shopee-listings.yml` |
| Saída | `listings/optimization-report-YYYYMMDD-HHmmss.json` |

**Função:** Otimiza título, descrição, atributos e SEO de cada produto no Tiny via PUT. Nunca altera preços.

**Campos otimizados:** `nome`, `descricao`, `atributos`, `palavras_chave`  
**Campos protegidos (nunca tocados):** `preco`, `preco_promocional`, `preco_custo`

**Como executar:**
```bash
# Via GitHub Actions (recomendado)
# Actions → Optimize Shopee Listings via Tiny API → Run workflow

# Local
TINY_ACCESS_TOKEN=xxx ANTHROPIC_API_KEY=yyy php agents/v9.2.85/scripts/optimize-shopee-listings.php
```

---

## 2. Secrets necessários

### 2.1 Autenticação Tiny/Olist (ao menos um obrigatório)

| Secret | Descrição |
|---|---|
| `TINY_ACCESS_TOKEN` | Bearer token direto (mais simples) |
| `TINY_API_TOKEN` | Alternativa ao ACCESS_TOKEN |
| `ERP_API_TOKEN` | Alternativa genérica |
| `OLIST_ACCESS_TOKEN` | Token Olist equivalente |
| `TINY_CLIENT_ID` + `TINY_CLIENT_SECRET` + `TINY_REFRESH_TOKEN` | OAuth2 com refresh automático |
| `OLIST_CLIENT_ID` + `OLIST_CLIENT_SECRET` + `OLIST_REFRESH_TOKEN` | Alternativa OAuth2 Olist |

Os agentes testam cada opção na ordem acima e usam o primeiro disponível. Se nenhum estiver configurado, retornam `status=error` com lista dos nomes ausentes (sem expor valores).

### 2.2 IA para otimização (opcional)

| Secret | Provedor | Modelo usado |
|---|---|---|
| `ANTHROPIC_API_KEY` | Anthropic (primário) | `claude-haiku-4-5-20251001` |
| `OPENAI_API_KEY` | OpenAI (fallback) | `gpt-4o-mini` |

Se nenhuma chave de IA estiver disponível, o agente de otimização opera em **modo rule-based** (sem custo, sem chamada externa), aplicando regras estruturadas de título e descrição.

---

## 3. Endpoints Tiny utilizados

| Método | Endpoint | Uso |
|---|---|---|
| `GET` | `/public-api/v3/produtos?limit=100&offset=N` | Listagem paginada |
| `GET` | `/public-api/v3/produtos/{id}` | Detalhe completo |
| `PUT` | `/public-api/v3/produtos/{id}` | Aplicar otimização |
| `POST` | `https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token` | OAuth2 refresh |

Base URL da API: `https://api.tiny.com.br/public-api/v3`

---

## 4. Formato dos arquivos de saída

### listings/shopee-listings-*.json (extração)
```json
{
  "agent": "shopee_listings_extractor",
  "version": "9.2.85",
  "generated_at": "2026-06-27T03:00:00+00:00",
  "secrets_check": { "token_source": "TINY_ACCESS_TOKEN", "token_available": true },
  "status": "success",
  "total_products": 250,
  "products": [
    {
      "id": 123,
      "sku": "PROD-001",
      "nome": "Nome do Produto",
      "situacao": "A",
      "preco": 49.90,
      "preco_promocional": null,
      "estoque": 10,
      "unidade": "UN",
      "gtin": "7891234567890",
      "categoria": "Categoria",
      "marca": "Marca",
      "imagens": ["https://..."],
      "qtd_variacoes": 0
    }
  ],
  "errors": []
}
```

### listings/optimization-report-*.json (otimização)
```json
{
  "agent": "shopee_listings_optimization",
  "version": "9.2.85",
  "generated_at": "2026-06-27T03:00:00+00:00",
  "ai_provider": "anthropic",
  "status": "success",
  "total_products": 250,
  "optimized": 248,
  "skipped": 2,
  "errors": [],
  "log": [
    {
      "sku": "PROD-001",
      "id": 123,
      "titulo_antes": "Título antigo",
      "titulo_novo": "Marca Produto Modelo Atributo Principal Benefício",
      "descricao_antes": "desc antiga (80 chars)...",
      "descricao_nova": "desc nova (80 chars)...",
      "imagens_antes": 2,
      "imagens_depois": 2,
      "status": "optimized",
      "motivo": null
    }
  ]
}
```

---

## 5. Limitações e pontos de atenção

| Limitação | Detalhe |
|---|---|
| Imagens | O agente audita e alerta quantidade < 3, mas não adiciona novas imagens (requer URLs de origem). |
| Preços | Protegidos por design — nunca incluídos no payload de atualização. |
| Rate limit Tiny | 250ms entre GETs, 300ms entre PUTs. Máximo 50 páginas por execução. |
| OAuth2 | Se `TINY_REFRESH_TOKEN` estiver expirado, a extração falha com `status=error`. Renovar manualmente no ERP. |
| IA sem key | Cai automaticamente em rule-based. Títulos e descrições melhoram, mas sem criatividade/contexto de IA. |
| Issue #29 | `ANTHROPIC_API_KEY`, `OPENAI_API_KEY` e `GOOGLE_API_KEY` ainda podem estar ausentes nos secrets. |

---

## 6. Regras de segurança obrigatórias para agentes que estendam este trabalho

1. Nunca imprimir valores de secrets em logs, commits, issues ou saída padrão.
2. Validar secrets apenas por nome: `printenv | grep -E 'TINY|OLIST' | sed 's/=.*/=***MASKED***/'`
3. Nunca incluir `preco`, `preco_promocional` ou `preco_custo` em payloads de update.
4. Sempre implementar anti-loop na paginação (checar IDs repetidos e página vazia).
5. Sempre implementar delay entre chamadas à API Tiny (mínimo 200ms GET, 300ms PUT).
6. Manter relatório before/after para permitir rollback manual por SKU.
7. Consultar `docs/olist-tiny-erp-api-knowledge-v2.md` como fonte principal de regras de API.

---

## 7. Histórico de sessões

| Data | Branch | O que foi feito |
|---|---|---|
| 2026-06-27 | `claude/guth-portfolio-access-81jjq2` | Criação de `ShopeeListingsExtractorAgent`, `ShopeeListingsOptimizationAgent`, workflows `fetch-shopee-listings.yml` e `optimize-shopee-listings.yml`, release-notes `9.2.85-shopee-listings-extractor-optimizer.json` e este documento. |
| 2026-07-02 (~13h UTC) | `main` (rotina agendada, sem branch dedicada) | Ciclo de otimização inteligente (CTR/conversão/título/A-B) executado como rotina autônoma. Diagnóstico: nenhuma otimização foi aplicada — ver seção 9. |
| 2026-07-02 (~19h UTC) | `main` (rotina agendada, sem branch dedicada) | Novo ciclo (6h depois): mesmo bloqueador confirmado, sem mudanças no ambiente. `fetch-shopee-listings.yml` run #12 (18:17:31Z) segue retornando `total_products: 0` / 401; `optimize-shopee-listings.yml` run #5 (11:55:02Z) terminou em `failure`. Nenhum arquivo `optimization-report-*.json` novo desde 2026-06-30. Nenhuma alteração de título/descrição/imagem/preço aplicada — mesma decisão da seção 9. Nenhum dado de venda, CTR ou conversão foi inventado. |
| 2026-07-03 (~04h UTC) | `main` (rotina agendada, sem branch dedicada) | 3º ciclo consecutivo (agora no dia seguinte): bloqueador ainda presente, ~33h após a última extração real. `optimize-shopee-listings.yml` gerou `listings/optimization-report-20260703-041044.json` com `status: error`, `"Autenticação Tiny falhou (401)."`, `total_products: 0`. Nenhuma otimização aplicada. Notificação enviada ao usuário (push) pedindo renovação manual do token, já que os 2 ciclos anteriores não resolveram o bloqueador. |
| 2026-07-03 (~14h UTC) | `main` (rotina agendada, sem branch dedicada) | 4º ciclo consecutivo: mesmo bloqueador (token Tiny), sem renovação desde a notificação do ciclo anterior. `fetch-shopee-listings.yml` run (10:16:18Z) e `optimize-shopee-listings.yml` run (11:53:23Z) terminaram em `failure` sem gerar novo relatório — causa raiz distinta: corrida de commit concorrente entre workflows autônomos no `main` (mesma classe de bug corrigida em `a3690a2` para o CI EHA), não um novo problema de dados. Nenhuma otimização aplicada; nenhum push duplicado enviado ao usuário por não haver fato novo além do já reportado no ciclo das 04h. |

---

## 8. Próximas ações sugeridas

- [x] Configurar `TINY_ACCESS_TOKEN` ou `TINY_CLIENT_ID`+`SECRET`+`REFRESH_TOKEN` nos GitHub Secrets (feito — mas token está **expirado/inválido** desde ~2026-06-30, ver seção 9).
- [x] Configurar `ANTHROPIC_API_KEY` nos GitHub Secrets para ativar otimização com IA (issue #29) — presente nos secrets.
- [x] Executar `fetch-shopee-listings.yml` para validar conectividade com a API Tiny — falhando com 401 desde 2026-07-01.
- [ ] **Renovar `TINY_ACCESS_TOKEN`/`TINY_REFRESH_TOKEN` no ERP e nos GitHub Secrets** — bloqueador atual, ver seção 9.
- [ ] Executar `optimize-shopee-listings.yml` em modo manual para revisar o primeiro relatório real (o único disponível hoje tem `total_products: 0`).
- [ ] Criar agente de reposição de imagens (após ter URLs das imagens oficiais do ERP).
- [ ] Revisar o commit `b925f9d` (converteu falha 401 em `::warning::`) e considerar um alerta ativo (issue automática, notificação) em vez de silenciar — CI verde não deve significar "sincronizado".

---

## 9. Bloqueador atual: token Tiny expirado (desde ~2026-06-30)

A rotina de otimização inteligente (análise de CTR/conversão, reescrita de título/descrição,
reordenação de imagens, testes A/B) depende de dados reais de produtos e desempenho vindos
da API Tiny/ERP. Diagnóstico do ciclo de 2026-07-02:

- Última extração com dados reais: `listings/shopee-listings-20260630-113006.json`
  (1360 produtos, `status: success`, gerado em `2026-06-30T11:30:06Z`).
- Todas as execuções seguintes (`fetch-shopee-listings.yml` run #11, `optimize-shopee-listings.yml`
  run #5, ambas em 2026-07-02) retornam `total_products: 0` com erro
  `"Autenticação Tiny falhou (401). Token inválido ou expirado."`.
- O commit `b925f9d` (2026-07-02) mudou `optimize-shopee-listings.yml` para tratar esse 401
  como `::warning::` (exit 0) em vez de falhar o job — o pipeline volta a aparecer "verde" no
  CI mesmo sem sincronizar nenhum produto real, o que reduz a visibilidade do problema.

**Por regra do agente ("análise deve ser baseada em dados, não suposições"), nenhuma alteração
de título, descrição, imagem, atributo ou preço foi aplicada neste ciclo.** Gerar otimizações
sobre dados de 2+ dias sem sincronização (ou inventados) seria uma suposição, não uma decisão
orientada a dados.

**Ação necessária (fora do escopo de um agente autônomo):** renovar `TINY_ACCESS_TOKEN` /
`TINY_REFRESH_TOKEN` em Settings → Secrets do repositório, e depois rodar
`fetch-shopee-listings.yml` manualmente para confirmar `status: success` com `total_products > 0`
antes de retomar os ciclos de otimização.

### 9.1 Atualização — ciclo de 2026-07-02 ~19h UTC

Bloqueador confirmado, sem mudanças desde a seção 9 acima (escrita ~6h antes):

- `listings/shopee-listings-20260702-181749.json`: `status: partial`, `total_products: 0`,
  erro `"Autenticação falhou (401). Token inválido ou expirado."`.
- `fetch-shopee-listings.yml` run #12 (2026-07-02T18:17:31Z): job termina com exit 0 (histórico
  de `b925f9d` mascarando 401 como sucesso de workflow), mas o payload confirma 0 produtos reais.
- `optimize-shopee-listings.yml` run #5 (2026-07-02T11:55:02Z): `conclusion: failure`.
- Nenhum `listings/optimization-report-*.json` novo desde `20260630-115948`.

Este agente não tem acesso para renovar o token Tiny (requer login no ERP + GitHub Secrets), então
o ciclo permanece bloqueado. Nenhuma otimização de título/descrição/imagem/atributo/preço foi
aplicada, e nenhum dado de CTR/conversão/vendas foi assumido ou inventado para contornar a falta
de dados reais.

### 9.2 Atualização — ciclo de 2026-07-03 ~04h UTC

Terceiro ciclo consecutivo com o mesmo bloqueador, agora ~33h sem extração real:

- `listings/optimization-report-20260703-041044.json`: `status: error`,
  `"Autenticação Tiny falhou (401)."`, `total_products: 0`, `optimized: 0`.
- Nenhum `listings/shopee-listings-*.json` novo desde `20260702-181749` (também 401).
- Como os dois ciclos anteriores (seções 9 e 9.1) não resultaram em renovação do token,
  este ciclo enviou uma notificação push ao usuário pedindo a ação manual: renovar
  `TINY_ACCESS_TOKEN`/`TINY_REFRESH_TOKEN` no ERP Tiny e atualizar o secret no GitHub,
  depois rodar `fetch-shopee-listings.yml` manualmente para confirmar `status: success`
  com `total_products > 0` antes do próximo ciclo autônomo.

### 9.3 Atualização — ciclo de 2026-07-03 ~14h UTC

Quarto ciclo consecutivo. O bloqueador de dados (token Tiny expirado) segue sem renovação:

- `fetch-shopee-listings.yml` (run 2026-07-03T10:16:18Z): `conclusion: failure`.
- `optimize-shopee-listings.yml` (run 2026-07-03T11:53:23Z): `conclusion: failure`.
- Nenhum `listings/shopee-listings-*.json` ou `listings/optimization-report-*.json` novo
  foi commitado a partir dessas duas execuções — diferente dos ciclos anteriores, que ao
  menos conseguiam commitar um relatório com `status: error/partial`.
- Causa provável dessas duas falhas sem relatório: corrida de commit concorrente no `main`
  entre múltiplos workflows autônomos rodando na mesma janela (mesmo padrão de falha
  diagnosticado e corrigido em `a3690a2` — "corrigir falha em cascata no step de commit do
  CI EHA" — para o workflow `ci-autonomo-continuo.yml`). Os workflows Shopee usam um padrão
  de commit/push semelhante e provavelmente sofrem do mesmo problema; ainda não corrigido
  especificamente para `fetch-shopee-listings.yml`/`optimize-shopee-listings.yml`.
- Nenhuma otimização de título/descrição/imagem/atributo/preço foi aplicada — sem dados reais
  de produto (token Tiny) não há base para decisão orientada a dados.
- Nenhuma notificação push adicional foi enviada ao usuário neste ciclo: o fato acionável
  (renovar `TINY_ACCESS_TOKEN`) é o mesmo já comunicado no ciclo das ~04h; alertar de novo
  sem informação nova seria ruído.

**Sugestão para o próximo ciclo com acesso de escrita a workflows:** aplicar em
`fetch-shopee-listings.yml` e `optimize-shopee-listings.yml` o mesmo fix de `a3690a2`
(`continue-on-error` + `if/fi` em vez de `&& break` + `git rebase --abort` antes de retry)
para que corridas de commit concorrente parem de mascarar o diagnóstico do bloqueador real.
