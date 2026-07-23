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
| 2026-07-03 (~19h UTC) | `main` (rotina agendada, sem branch dedicada) | 5º ciclo consecutivo: bloqueador do token Tiny inalterado, agora ~89h desde a última extração real. Novo run de `fetch-shopee-listings.yml` (2026-07-03T17:03:16Z) também terminou em `failure` sem commitar relatório (mesmo padrão dos dois runs do ciclo das 14h). A teoria de "corrida de commit concorrente" do ciclo anterior não pôde ser confirmada nem descartada: os logs desses runs já expiraram no GitHub Actions (download retorna 404) e o domínio de blob storage dos logs está fora da allowlist de rede deste ambiente. Comparação de `run_duration_ms` entre runs (falhas: ~4s; sucessos/erros com relatório: ~19-23s) é consistente com falha rápida antes de qualquer tentativa de commit, mas não prova a causa exata. Nenhuma otimização aplicada — sem dados reais de produto não há base para decisão orientada a dados. Nenhuma notificação push enviada: nenhum fato novo que mude a ação recomendada (renovar `TINY_ACCESS_TOKEN`/`TINY_REFRESH_TOKEN`), já comunicada nos ciclos anteriores. |
| 2026-07-04 (~01h UTC) | `main` (rotina agendada, sem branch dedicada) | 6º ciclo consecutivo: mudança de contexto relevante desde o ciclo anterior. Em 2026-07-03T20:06:19Z (commit `71bb308`, autor `fredmourao-ai`), o próprio usuário desabilitou 48 workflows para recuperar quota do GitHub Actions — decisão deliberada, não uma falha —, incluindo `fetch-shopee-listings.yml` e `optimize-shopee-listings.yml`, agora `on: workflow_dispatch` apenas, com o job original substituído por um `echo` de pausa. Isso significa que, mesmo após renovar o `TINY_ACCESS_TOKEN`, os dois workflows do pipeline Shopee não voltam a rodar sozinhos (perderam o trigger `schedule` e a lógica real) — é preciso reativá-los manualmente além de renovar o token. Nenhum `listings/shopee-listings-*.json` ou `optimization-report-*.json` novo desde `20260703-041044`; nenhuma credencial Tiny/Olist disponível neste ambiente de sessão para tentar extração direta fora do workflow. Nenhuma otimização aplicada. Notificação push enviada neste ciclo por haver fato novo e acionável: além do bloqueador de token (agora ~4 dias sem renovação), o pipeline em si foi pausado, e a rotina completa 6 ciclos (~30h) sem produzir nenhum valor real — recomenda-se ao usuário decidir entre reativar o pipeline (token + workflows) ou pausar esta rotina de otimização até lá. |
| 2026-07-05 (~04h UTC) | `main` (rotina agendada, sem branch dedicada) | 7º ciclo consecutivo, ~28h após o ciclo anterior (maior intervalo que os 6h nominais, sem run intermediário registrado). Ambos os bloqueadores seguem idênticos ao ciclo 6: token Tiny sem renovação (`shopee-listings-20260702-181749.json`, o mais recente com conteúdo real, ainda mostra `401` e `total_products: 0`; nenhum arquivo novo desde `optimization-report-20260703-041044.json`) e os workflows `fetch-shopee-listings.yml`/`optimize-shopee-listings.yml` seguem pausados (`on: workflow_dispatch`) desde `71bb308`. Nenhum secret `TINY_*`/`OLIST_*` neste ambiente de sessão. Nenhuma otimização aplicada — sem dados reais não há base para decisão orientada a dados. Nenhuma notificação push enviada: nenhum fato novo além do já comunicado no ciclo 6 (mesma recomendação: renovar o token e reativar os dois workflows, ou pausar esta rotina até lá). |
| 2026-07-07 (~19h UTC) | `main` (rotina agendada, sem branch dedicada) | 8º ciclo consecutivo, ~63h após o ciclo anterior (maior gap ainda que os 6h nominais — nenhum run intermediário registrado). Estado idêntico ao ciclo 7: `fetch-shopee-listings.yml`/`optimize-shopee-listings.yml` seguem `on: workflow_dispatch` apenas (commit `6e32ce0` de 2026-07-05 tocou o modo/permissões de dezenas de arquivos, incluindo `sync-shopee-6h.yml`, mas não reverteu a pausa nem reativou o `schedule`); nenhum `listings/shopee-listings-*.json` ou `optimization-report-*.json` novo desde `20260703-041044`; nenhum secret `TINY_*`/`OLIST_*`/`SHOPEE_*` neste ambiente de sessão. Nenhuma otimização de título/descrição/imagem/atributo/preço aplicada — sem dados reais não há base para decisão orientada a dados. Nenhuma notificação push enviada: nenhum fato novo além do já comunicado nos ciclos 6 e 7 (mesma recomendação: renovar o token Tiny e reativar os dois workflows, ou pausar esta rotina agendada até que o bloqueador seja resolvido). |
| 2026-07-08 (~19h UTC) | `main` (rotina agendada, sem branch dedicada) | 9º ciclo consecutivo. Fato novo desde o ciclo 8: em `2026-07-08T09:54:33-03:00` (commit `e714686`, PR #153, autor `fredmourao-ai`) o usuário reativou `fetch-shopee-listings.yml`/`optimize-shopee-listings.yml` com o trigger `schedule` restaurado (resolve o bloqueador secundário descrito nos ciclos 6-8). O primeiro run automático após a reativação (`fetch-shopee-listings.yml`, 2026-07-08T17:12:37Z, commit `dd4d439`) já confirma que o pipeline volta a executar sozinho, mas gerou `listings/shopee-listings-20260708-171237.json` com `status: partial`, `total_products: 0` e o mesmo erro `"Autenticação falhou (401). Token inválido ou expirado."` — ou seja, o bloqueador primário (token Tiny) permanece sem renovação, agora ~8 dias desde a última extração real (`20260630-113006.json`). Nenhum `optimization-report-*.json` novo desde `20260703-041044`. Nenhuma credencial `TINY_*`/`OLIST_*` neste ambiente de sessão para tentar renovação direta. Nenhuma otimização de título/descrição/imagem/atributo/preço aplicada — sem dados reais não há base para decisão orientada a dados. Notificação push enviada neste ciclo: há fato novo e acionável (pipeline reativado com sucesso, mas ainda bloqueado só pelo token — a ação restante do usuário é unicamente renovar `TINY_ACCESS_TOKEN`/`TINY_REFRESH_TOKEN`). |

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

### 9.4 Atualização — ciclo de 2026-07-03 ~19h UTC

Quinto ciclo consecutivo. Bloqueador do token Tiny ainda sem renovação, agora ~89h
sem extração real (última: `shopee-listings-20260630-113006.json`, 2026-06-30T11:30:06Z):

- Novo run de `fetch-shopee-listings.yml` (2026-07-03T17:03:16Z, run #15): `conclusion: failure`,
  sem novo `listings/shopee-listings-*.json` commitado — mesmo padrão dos dois runs sem
  relatório do ciclo das 14h (seção 9.3).
- `optimize-shopee-listings.yml` não teve run novo desde 2026-07-03T11:53:23Z (já coberto
  na seção 9.3).
- Tentativa de confirmar a causa raiz exata (corrida de commit vs. outra falha) não teve
  sucesso: os logs desses runs de curta duração (~4s) já expiraram no GitHub Actions
  (`get_job_logs` retorna 404) e a URL de download do zip completo aponta para um domínio de
  blob storage (`*.blob.core.windows.net`) fora da allowlist de rede deste ambiente
  (`CONNECT tunnel failed, response 403`). `get_workflow_run_usage` não ajuda a diferenciar
  causas — retorna `total_ms: 0` tanto em runs de sucesso quanto de falha neste ambiente,
  então não é sinal confiável de "bloqueado por cota/billing". O único dado observável é
  `run_duration_ms`: falhas ~4s vs. sucessos/erros-com-relatório ~19-23s, consistente com uma
  falha rápida antes de qualquer tentativa de commit, mas isso não confirma nem descarta a
  hipótese de corrida de commit da seção 9.3.
- Nenhuma otimização de título/descrição/imagem/atributo/preço foi aplicada — sem dados reais
  de produto não há base para decisão orientada a dados.
- Nenhuma notificação push enviada neste ciclo: a ação recomendada ao usuário continua a
  mesma já comunicada (renovar `TINY_ACCESS_TOKEN`/`TINY_REFRESH_TOKEN` no ERP Tiny e no
  GitHub Secrets); não há fato novo que mude essa recomendação, apenas mais uma confirmação
  do mesmo bloqueador.

### 9.5 Atualização — ciclo de 2026-07-04 ~01h UTC

Sexto ciclo consecutivo. Novidade real desde o ciclo anterior: em `2026-07-03T20:06:19Z`
(commit `71bb308fix: desabilita 48 workflows redundantes para recuperar quota GitHub Actions`,
autor `fredmourao-ai`, ou seja o próprio usuário, não um agente autônomo), 48 workflows foram
convertidos para `on: workflow_dispatch` apenas — entre eles `fetch-shopee-listings.yml` e
`optimize-shopee-listings.yml`, cujo job foi substituído por um único `echo "Workflow pausado
para economizar quota Actions."`. Mantidos ativos (fora do escopo deste agente): apenas
`ci-autonomo-continuo.yml`, `deploy.yml` e `shopvivaliz-qa.yml`.

Efeito prático para este agente de otimização:

- O bloqueador de dados (token Tiny expirado desde ~2026-06-30, ~96h sem extração real) segue
  sem renovação — nenhum secret novo, nenhum arquivo `listings/shopee-listings-*.json` ou
  `optimization-report-*.json` desde `20260703-041044`.
- Mesmo que o token fosse renovado agora, os dois workflows do pipeline (`fetch-shopee-listings.yml`,
  `optimize-shopee-listings.yml`) não voltariam a rodar automaticamente: perderam o trigger
  `schedule` e a lógica real foi substituída pelo `echo` de pausa. Reativação exige duas ações
  manuais distintas: (1) renovar `TINY_ACCESS_TOKEN`/`TINY_REFRESH_TOKEN`; (2) reverter os dois
  workflows ao conteúdo anterior a `71bb308` (ou recriá-los) e restaurar o trigger `schedule`.
- Este ambiente de sessão (Claude Code agendado) não tem nenhuma credencial `TINY_*`/`OLIST_*`
  configurada, então não há como tentar uma extração direta fora do workflow para contornar a
  pausa.
- Nenhuma otimização de título/descrição/imagem/atributo/preço foi aplicada — sem dados reais
  de produto não há base para decisão orientada a dados, e não é escopo deste agente reverter
  uma decisão de quota tomada pelo próprio usuário.

**Notificação push enviada neste ciclo:** diferente dos ciclos 4 e 5 (sem fato novo), este
ciclo tem duas informações acionáveis novas: (a) o pipeline foi pausado deliberadamente junto
com outros 47 workflows, então o usuário precisa saber que reativá-lo requer mais do que só
renovar o token; (b) a rotina de otimização já soma 6 ciclos (~30h de tentativas a cada 6h)
sem produzir nenhuma otimização real, o que sugere considerar pausar esta rotina agendada
específica até que o bloqueador seja resolvido, evitando ciclos vazios repetidos.

### 9.6 Atualização — ciclo de 2026-07-08 (~19h UTC), 9º ciclo

Bloqueador secundário (pipeline pausado, seções 9.5 e ciclos 6-8) **resolvido**: o commit
`e714686` (PR #153, 2026-07-08T09:54:33-03:00, autor `fredmourao-ai`) restaurou o trigger
`schedule` em `fetch-shopee-listings.yml` (`0 */6 * * *`) e `optimize-shopee-listings.yml`
(`0 3 * * *`), revertendo a pausa aplicada em `71bb308`.

Confirmação prática: o primeiro run agendado após a reativação já ocorreu
(`fetch-shopee-listings.yml`, 2026-07-08T17:12:37Z, commit `dd4d439`) e o pipeline
volta a commitar sozinho em `listings/`. Porém o resultado desse run mostra que o
**bloqueador primário permanece**:

- `listings/shopee-listings-20260708-171237.json`: `secrets_check.token_available: true`
  (o secret `TINY_ACCESS_TOKEN` existe), mas `status: partial`, `total_products: 0`,
  erro `"Autenticação falhou (401). Token inválido ou expirado."` — idêntico ao erro
  observado desde `20260701-114213.json` (ciclo de 2026-07-01/02).
- Nenhum `listings/optimization-report-*.json` novo desde `20260703-041044` (`optimize-shopee-listings.yml`
  ainda não teve run novo desde a reativação no momento deste ciclo).
- Não há lógica de refresh automático de token nos workflows nem no
  `ShopeeListingsExtractorAgent.php` — ambos apenas repassam `TINY_ACCESS_TOKEN`/`TINY_REFRESH_TOKEN`
  como env vars; um 401 não dispara troca automática do refresh token.
- Nenhuma credencial `TINY_*`/`OLIST_*` neste ambiente de sessão para tentar renovação direta
  ou testar o refresh token fora do workflow.
- Nenhuma otimização de título/descrição/imagem/atributo/preço aplicada — sem dados reais de
  produto (~8 dias sem sincronização real, desde `20260630-113006.json`) não há base para
  decisão orientada a dados.

**Notificação push enviada neste ciclo:** fato novo e acionável — o pipeline voltou a rodar
sozinho (a reativação dos workflows funcionou), mas a extração automática confirma que o
único bloqueador restante é a renovação manual de `TINY_ACCESS_TOKEN`/`TINY_REFRESH_TOKEN`
no ERP Tiny + GitHub Secrets. Diferente dos ciclos 7/8 (sem fato novo, sem push), aqui há uma
mudança de estado real que o usuário provavelmente quer saber: o esforço de reativar os
workflows não foi em vão, mas não basta sozinho.

### 9.7 Atualização — ciclo de 2026-07-09 (~13h UTC), 10º ciclo

Bloqueador primário (token Tiny expirado, seções 9-9.6) **resolvido**: fora do ciclo agendado,
um commit de fix (`fix(shopee): priorizar refresh OAuth2 sobre token estático`) mudou a
resolução de credencial no extractor para priorizar `TINY_REFRESH_TOKEN`/OAuth2 em vez do
`TINY_ACCESS_TOKEN` estático. Dois runs de `fetch-shopee-listings.yml` via `workflow_dispatch`
em 2026-07-09T01:15:08Z e 01:16:38Z confirmam a correção na prática:
`listings/shopee-listings-20260709-011652.json` tem `status: success`,
`secrets_check: {"token_source": "oauth2_refresh", "token_refreshed": true}` e
`total_products: 1058` — a primeira extração real de catálogo desde `20260630-113006.json`
(~9 dias parado).

Dois bloqueadores **novos** surgiram, ambos confirmados via logs reais do GitHub Actions
(`mcp__github__get_job_logs`), não suposição:

- **Corrida de commit no `fetch-shopee-listings.yml`**: o run agendado seguinte
  (run `29014701374`, 2026-07-09T11:23:41Z) extraiu os dados com sucesso (18000 inserções
  preparadas para commit), mas o `git push` final foi rejeitado —
  `! [rejected] main -> main (fetch first)` — porque o bot de heartbeat deste repositório
  (`scripts/heartbeat.txt`, commits `auto: sincronizar HH:MM:SS` a cada ~5 min) avançou o
  `main` remoto entre o checkout do job e o push. Isso confirma a teoria de "corrida de commit
  concorrente" já levantada (sem prova) no ciclo de 2026-07-03 na seção 9.3 — agora com log
  de erro explícito. O resultado extraído nesse run específico foi perdido (não commitado).
- **`optimize-shopee-listings.yml` cancelado, não com falha de auth**: o run agendado de
  2026-07-09T12:41:10Z (job `optimize`) não terminou com erro de token — a conclusão do job
  foi `cancelled` após ~15min20s de execução, sem logs de falha específicos. Não há
  `timeout-minutes` nem `concurrency` configurados no workflow; a causa mais provável é
  cancelamento por limite de cota/orçamento de GitHub Actions do repositório (já visto em
  commit anterior `b02fe8a fix: evitar falhas de actions com budget bloqueado`) combinado com
  o tempo real necessário para processar 1058 produtos sequencialmente (cada um com uma
  chamada de IA + delays de 500ms/300ms entre produtos — cerca de 15-35min só de delays fixos
  para o catálogo completo). Nenhum `optimization-report-*.json` novo foi commitado neste
  ciclo; o mais recente continua sendo `20260703-041044` (que já era `status: error`,
  `total_products: 0`).

**Achado estrutural, não um blocker temporário:** o pipeline real (`ShopeeListingsExtractorAgent`
+ `ShopeeListingsOptimizationAgent`) só lê/escreve dados de catálogo do ERP Tiny (nome, preço,
estoque, categoria, imagens) e reescreve título/descrição/atributos/ordem de imagem via IA
genérica — o próprio agente de otimização documenta `NÃO altera preços`. Não existe, em
nenhum workflow ou script deste repositório, integração com a API de performance/analytics do
Shopee Open Platform (CTR, taxa de conversão, dados de vendas por SKU) — os secrets que essa
integração exigiria (`SHOPEE_PARTNER_ID`, `SHOPEE_PARTNER_KEY`, `SHOPEE_SHOP_ID`,
`SHOPEE_ACCESS_TOKEN`, conforme `scripts/shopee-readiness-report.py`) não aparecem em nenhum
workflow deste repo. Ou seja: mesmo com o bloqueador de token resolvido, as instruções desta
rotina agendada (calcular CTR real, testar preço, comparar concorrentes, reordenar imagens por
engagement) permanecem tecnicamente inexequíveis com o pipeline atual — o que existe é reescrita
de título/descrição por IA a partir de dados de catálogo, sem qualquer métrica de performance
real de anúncio. Nenhuma otimização foi aplicada e nenhum dado de CTR/conversão/venda foi
inventado neste ciclo.

**Notificação push enviada neste ciclo:** três fatos novos e acionáveis — (1) o bloqueador de
9 ciclos (token Tiny) está resolvido e a extração de catálogo volta a funcionar; (2) mas o
commit automático do próprio pipeline está perdendo runs por causa de conflito de push com o
bot de heartbeat de 5 em 5 minutos deste repositório — vale considerar dar um `retry`/`pull
--rebase` antes do push nesses workflows, ou reduzir a frequência do heartbeat; (3) o job de
otimização está sendo cancelado (provavelmente cota do Actions ou tempo de execução), então
mesmo com catálogo disponível a IA não chega a rodar; e (4), estrutural — a rotina descrita
para este agente (CTR, conversão, teste de preço) não é implementável com a integração atual,
que é limitada a título/descrição via IA sobre dados do Tiny, sem qualquer fonte real de
métricas de performance do Shopee.

### 9.8 Atualização — ciclo de 2026-07-15 (~13h UTC), 11º ciclo

**Achado estrutural (seção 9.7) confirmado, sem mudança:** nenhum secret ou workflow novo de
performance/analytics do Shopee (`SHOPEE_PARTNER_ID`, `SHOPEE_PARTNER_KEY`, `SHOPEE_SHOP_ID`,
`SHOPEE_ACCESS_TOKEN`) foi adicionado desde o ciclo 10. As instruções desta rotina (CTR real,
teste A/B de preço, reordenar imagens por engagement, comparar concorrentes) continuam
tecnicamente inexequíveis — não há de onde ler esses dados. Nenhuma otimização foi aplicada e
nenhum dado de CTR/conversão/venda foi inventado neste ciclo, conforme a regra de segurança
da seção 6.

**Regressão no bloqueador de autenticação Tiny (o que existe do pipeline, título/descrição via
catálogo, também parou de funcionar):** analisando `listings/optimization-report-*.json` e
`listings/shopee-listings-*.json` entre 2026-07-10 e hoje:

- 2026-07-10 a 2026-07-13: erro voltou a ser `Falha OAuth2 refresh: Token is not active` — o
  fix da seção 9.7 (priorizar `TINY_REFRESH_TOKEN`) funcionou uma vez em 2026-07-09 mas o
  refresh token expirou/foi invalidado de novo em menos de 24h e não se renovou sozinho nos
  ciclos seguintes.
- 2026-07-14 e 2026-07-15 (`optimization-report-20260714-093004.json`,
  `optimization-report-20260715-101720.json`, `shopee-listings-20260714-164350.json`): o erro
  mudou para `Falha OAuth2 refresh: Invalid client or Invalid client credentials` — isto é
  diferente de token expirado. Sugere que `TINY_CLIENT_ID`/`TINY_CLIENT_SECRET` (não apenas o
  refresh token) estão incorretos ou o app Tiny foi revogado/reconfigurado do lado do Tiny.
  `git log` não mostra nenhum commit alterando esses secrets entre 07-09 e agora — a mudança
  de sintoma veio do lado do provedor (Tiny), não de uma alteração no repo.

Resultado prático: **0 produtos extraídos e 0 otimizações aplicadas em todos os 6 ciclos desde
07-09** (`total_products: 0` em todos os relatórios do período). O catálogo mais recente
disponível continua sendo o de 2026-07-09 (1058 produtos).

**Notificação push enviada neste ciclo:** o bloqueador de credencial Tiny piorou de "token
expirado" (recuperável por refresh automático) para "client credentials inválidas" (exige
reconfiguração manual do app no painel Tiny + atualização de `TINY_CLIENT_ID`/
`TINY_CLIENT_SECRET` nos GitHub Secrets) — sem essa correção manual, nenhum ciclo futuro deste
pipeline vai funcionar, mesmo os que não exigem dado de performance do Shopee. Reforçada
também a conclusão estrutural: a rotina de CTR/conversão/preço não é implementável sem
integração real com a API de analytics do Shopee Open Platform.

### 9.9 Atualização — ciclo de 2026-07-23 (~UTC), 12º ciclo

**Achado estrutural (seções 9.1–9.8) confirmado, sem mudança:** nenhum secret ou workflow de
performance/analytics do Shopee (`SHOPEE_PARTNER_ID`, `SHOPEE_PARTNER_KEY`, `SHOPEE_SHOP_ID`,
`SHOPEE_ACCESS_TOKEN`) existe neste repo. `printenv | grep -E 'TINY|SHOPEE'` neste sandbox não
retornou nenhuma variável — nem mesmo as credenciais Tiny já documentadas como quebradas. As
instruções desta rotina (CTR real, teste A/B de preço, comparar concorrentes, reordenar imagens
por engagement) seguem tecnicamente inexequíveis. Nenhuma otimização foi aplicada e nenhum dado
de CTR/conversão/venda foi inventado neste ciclo, conforme a regra da seção 6.

**Regressão nova e mais severa que o token Tiny (achada em 2026-07-22, confirmada hoje persistindo
pelo segundo dia):** `.github/workflows/` inteiro sumiu de `main` — não só os 3 workflows Shopee
(`fetch-shopee-listings.yml`, `optimize-shopee-listings.yml`, `sync-shopee-6h.yml`), mas também o
`shopvivaliz-qa.yml` (o gate de lint/smoke-test descrito no `CLAUDE.md` como crítico e ativo).
Hoje `.github/workflows/` contém apenas 2 arquivos, nenhum relacionado a Shopee ou QA:
`agents-runtime-ci.yml` e `deploy-production-ftp.yml`. `git log --diff-filter=D --all` para os
arquivos sumidos não mostra nenhum commit de deleção alcançável — consistente com a teoria
registrada em `abe3622` (2026-07-22) de que um bot de heartbeat/auto-sync está fazendo force-push
que reescreve o histórico de `main` e derruba commits que já tinham sido aceitos (ex:
`400dcb2` de 07-20 e `8dc2969` de 07-21, existentes via SHA mas inalcançáveis a partir do HEAD
atual). `git fetch origin main` neste ciclo também reportou `(forced update)` na ref de
tracking local, o que é consistente com (mas não prova isolada de) esse padrão continuando.
Também confirmado: `listings/` não existe mais em `HEAD` (só via `git log --all`), então mesmo o
catálogo de 2026-07-09 citado na seção 9.8 não está mais acessível no working tree atual.

**Notificação push enviada neste ciclo:** o achado mais urgente não é mais o token Tiny (já
documentado e conhecido do usuário desde 07-22) — é que o pipeline inteiro de CI/CD (incluindo o
QA lint que bloqueia regressões de produção) está ausente de `main` há pelo menos 2 dias, sem
commit de deleção rastreável, o que exige investigação manual do force-push/heartbeat antes de
qualquer outro workflow autônomo (Shopee ou não) voltar a funcionar de forma confiável.
