# 🤖 Agentes Autônomos — Memória Consolidada

> **🔴 LEIA ISTO ANTES DE COMEÇAR**
>
> Múltiplos agentes diferentes (Claude, GPT, Gemini, etc) trabalham aqui em sessões isoladas **sem memória compartilhada**. Sem um lugar único, o mesmo bug é redescoberto do zero repetidas vezes.
>
> **Este arquivo é o repositório centralizado** de tudo que qualquer agente deve saber antes de mexer no repo.

---

## 📋 Como Usar Este Arquivo

### Antes de começar uma sessão
1. **Procure por seu problema** (Ctrl+F): API, nome de arquivo, sintoma
2. **Leia a entrada relevante** se encontrar algo parecido
3. **Você economiza horas** (não redescobre o mesmo bug)

### Ao terminar uma sessão
1. **Descobriu algo não-óbvio?** Adicione uma entrada no topo da seção correspondente
2. **Formato**:
```
### AAAA-MM-DD — Título curto do que foi aprendido
**Sistema/arquivo:** onde aplica
**O que descobri:** fato direto, nada vago
**Por quê importa:** o que dá errado sem isso
**Ver também:** link pra doc dedicada, se houver
```

3. **Não remova entradas** antigas a não ser que estejam **confirmadas obsoletas**

---

## 🚫 Regras Obrigatórias para Agentes

### Diagnóstico
- ✓ Identificar o erro antes de sugerir solução
- ✓ Registrar HTTP method, URL, status, response body, etapa do fluxo
- ✓ Não tratar 404, 405, 500, CORS, DNS como o mesmo problema
- ✗ **Nunca** declarar que produção/deploy/banco/preço/imagem estão certos **sem teste verificável**

### Segurança
- ✗ **Nunca** hardcodar, registrar ou exibir senhas, tokens, chaves de API
- ✗ **Nunca** contornar CORS, autenticação, controles de acesso
- ✗ **Nunca** deletar em FTP ou banco sem backup + autorização explícita
- ✓ Usar sempre variáveis de ambiente ou GitHub Secrets

### Dados
- ✗ **Nunca** inventar preço, estoque, frete, imagem, disponibilidade
- ✗ **Nunca** alterar campos comerciais sem evidência da fonte oficial
- ✓ Vincular imagens por SKU ou ID da origem (nunca by-name)
- ✓ Distinguir falha de interface de falha de sincronização

### Atualizações
- ✓ Produza atualizações cumulativas (permitir pular versões)
- ✓ Inclua automaticamente SQLs, migrations, reparos de vínculo
- ✓ Torne migrations idempotentes (rodar 2x = rodar 1x)
- ✗ **Nunca** exija clique manual pra concluir instalação

---

## 🔴 Crítico: Problemas Não Resolvidos

### Shopee/Tiny OAuth2 — PARADO HÁ 3+ SEMANAS + workflows removidos (2026-07-27)
**Status:** Requer ação manual (regenerar client OAuth2 na Tiny) **e** recriar workflows
**Arquivos:** `.github/workflows/{fetch,optimize}-shopee-listings.yml` — **não existem mais no repo**, aparentemente removidos como colateral da consolidação "99→10 workflows" de 2026-07-26 (`CLAUDE.md`); nenhum workflow ativo restante referencia Shopee. Os agentes (`agents/v9.2.85/app/ShopeeListings*Agent.php`) continuam no repo, só falta o workflow que os chama.
**O problema:** (1) Credencial `TINY_*` do GitHub Secrets expirou/revogada — todo ciclo retornava `"Falha OAuth2: Invalid client or Invalid client credentials"`. (2) Desde ~2026-07-26, os dois workflows nem existem mais, então nem o erro acima roda: nenhum artefato novo em `listings/` desde `20260726-080756`/`20260726-060921`.
**O que precisa:** (1) Usuário vai a `accounts.tiny.com.br` → regenera client OAuth2 → atualiza `TINY_CLIENT_ID`/`TINY_CLIENT_SECRET`/`TINY_REFRESH_TOKEN` em GitHub Secrets. (2) Recriar `fetch-shopee-listings.yml`/`optimize-shopee-listings.yml` com trigger `schedule` (ver `docs/HISTORICO-AGENTES-SHOPEE.md` seção 9.10 para detalhes e formato esperado dos relatórios).
**Enquanto isso não for feito:** o pipeline de otimização Shopee não roda de forma alguma, nem para gerar erro — não há mais workflow que dispare.

---

## 📚 Memória Compartilhada por Sistema

### Tiny ERP API v3
**Última atualização:** 2026-07-19

- ✓ **Pedidos pagos** nascem com `situacao=3` (Aprovada), não `0`
- ✓ **Forma de pagamento real** vem de `order.mercadopago.payment_type_id` (Pix/boleto/cartão), NÃO do campo `payment_method` (sempre "mercado_pago")
- ✗ **Nunca enviar** `pagamento.meioPagamento` — Tiny rejeita mesmo com ID válido
- ✓ Campos `listaPreco`, `naturezaOperacao`, `intermediador` só se houver ID real configurado
- ✓ Usar Bearer token OAuth de `storage/private/tokens.json`, não `OLIST_INTEGRADOR_TOKEN` (v2 legada)
- ✓ **API v2 foi removida inteiramente** — qualquer grep de `api/v2` ou `api2/` é bug, não feature

**Ver:** `docs/TINY-ERP-API-V3.md`

### Olist API v3
**Última atualização:** 2026-07-18

- ✓ Token OAuth armazenado em `storage/private/tokens.json` com `expires_at`
- ✓ Auto-renova via refresh_token a cada 3 horas (`refresh-token.php`)
- ✓ **Status do daemon:** verificar arquivo de log ou chamar `/api/daemon-status` (health check)
- ✗ **Nunca** usar `GET /estoque/{id}?token=legacy_v2_token`

**Ver:** `docs/OLIST-API-V3.md`

### Domínio Canônico
**Última atualização:** 2026-07-19

- ✓ **Domínio principal:** `https://shopvivaliz.com.br` (apex, sem www)
- ✗ **Nunca** redirecionar `POST` (quebra webhooks)
- ✓ Legados (`www.*`, `dev.*`) podem redirecionar `GET/HEAD` → apex apenas
- ✓ Todos os callbacks, feeds, OAuth, Mercado Pago devem apontar para apex

**Ver:** `.htaccess`, `scripts/update-production-env.py`

### Produtos e Catálogo
**Última atualização:** 2026-07-26

- ✓ Filtro de exibição: `situacao` = 'A'|'ATIVO'|'ACTIVE' OU `is_published` = true
- ✗ **Nunca** mostrar pré-venda/sob-encomenda (remover do catálogo)
- ⚠️ **Cache não atualiza automaticamente** ao desmarcar produto no admin — requer manual clear ou webhook
- ✓ Fonte de verdade: `storage/products-cache-ativos.json` (Olist/Tiny) → fallback: `api/catalog/fallback-products.json`

### Deploy
**Última atualização:** 2026-07-26

- ✓ **Produção real:** VM Oracle cron a cada 30min (`git fetch` + `reset --hard origin/main`)
- ✗ **FTP/HostGator desativado** — só via `workflow_dispatch` manual
- ✓ Todos os scripts consolidados em 2 mestres: `olist-sync-master.py`, `git-auto-sync-master.py`
- ✓ GitHub Actions reduzido de 99 para 10 workflows críticos

### 2026-07-29 — ShopVivaliz Mobile Agent Bridge
**Sistema/arquivo:** VM Oracle, `/home/ubuntu/site-shopvivaliz/agent-bridge/`, `AGENTS.md`
**O que descobri:** a bridge correta para uso do GPT mobile observa `/home/ubuntu/site-shopvivaliz/agent-bridge/inbox/` e expoe apenas `create_issue`, `apply_patch_pr`, `read_file` e `run_readonly_audit`. O service associado e `shopvivaliz-agent-bridge.service`.
**Por quê importa:** isso entrega acesso controlado da VM ao GPT mobile por fila JSON, sem shell irrestrito e sem permitir commit direto em `main` ou merge automatico.
**Ver também:** `AGENTS.md`, `agent-bridge/README.md`, `deploy/systemd/shopvivaliz-agent-bridge.service`
---

## 🤖 Agentes Autônomos Ativos

| Agente | Tipo | Commits | Status |
|--------|------|---------|--------|
| Agente Autonomo ShopVivaliz | Primary | 2,542 | ✅ Ativo |
| fredmourao-ai | Developer | 1,573 | ✅ Ativo |
| Frederico Mourao | Developer | ~1,668 | ✅ Ativo |
| CI Summary Bot | Automation | 158 | ✅ Ativo |
| Claude Autonomo | AI | 212 | ✅ Ativo |
| Codex | AI | 135 | ✅ Ativo |
| CI Autônomo | CI | 142 | ✅ Ativo |

**Total histórico:** 11,600+ commits automáticos
**Frequência:** Auto-sync a cada 20-60 minutos

---

## 📄 Documentação Associada

- `docs/TINY-ERP-API-V3.md` — Schema completo do Tiny
- `docs/OLIST-API-V3.md` — Endpoints Olist v3
- `KNOWN_ISSUES.md` — Bugs conhecidos / em investigação
- `CHANGELOG.md` — Histórico de mudanças e fixes
- `CLAUDE.md` — Instruções gerais do projeto

---

**Última consolidação:** 2026-07-26
**Consolidado por:** Claude Code
**Próxima revisão:** Quando houver novo achado não-óbvio
