# Fred ChatGPT Continuity Stability Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reduzir os travamentos do perfil ChatGPT Fred/CDP 9555 ao nível do perfil Atendimento/CDP 9556 sem desabilitar a continuidade automática e sem afetar sessões, cookies ou logins.

**Architecture:** Usar o perfil Atendimento/9556 como controle A/B na mesma VM. Instrumentar e corrigir apenas o caminho específico de continuidade do Fred/9555, preservando o perfil do navegador e o mecanismo checkpoint-driven. Toda correção deve ser test-first e validada com evidência runtime real.

**Tech Stack:** Node.js, Chromium CDP, systemd, Python/shell de operação, Remote Control MCP, GitHub.

**Spec:** `docs/knowledge/task-continuity.md`

## Global Constraints

- Não fazer logout nem trocar contas entre perfis.
- Fred permanece em `shopvivaliz-chromium` / CDP 9555.
- Atendimento permanece em `shopvivaliz-atendimento-chromium` / CDP 9556.
- Não desabilitar, pausar ou contornar a retomada automática checkpoint-driven.
- Não editar `current/` nem release ativa diretamente.
- Não expor secrets, cookies, tokens, senha ou OTP.
- Conclusão exige evidência fresca no runtime, não apenas testes locais.

## Review Focus

- Um renderer preso em CPU alta não pode provocar loop de probes/recovery.
- `stopped_thinking` não pode consumir repetidamente o orçamento de envio sem progresso.
- Múltiplas abas/targets não podem causar seleção ambígua silenciosa.
- O monitor não pode marcar browser saudável apenas por CDP acessível enquanto o fluxo está travado.
- A correção do Fred não pode alterar comportamento do perfil Atendimento/9556.

---

### Task 1: Baseline e reprodução A/B

**Files:**
- Create: `docs/operations/chatgpt-fred-vs-atendimento-baseline-20261004.md`

**Interfaces:**
- Consumes: CDP 9555 e 9556, status do controller e métricas de processos.
- Produces: hipótese única de causa raiz e critérios mensuráveis de regressão.

- [ ] Capturar CPU/RSS/processos/targets dos dois perfis em pelo menos dois snapshots.
- [ ] Registrar estado de continuidade e falhas atuais.
- [ ] Correlacionar o renderer de maior consumo com targets/conversa sem registrar conteúdo privado.
- [ ] Definir hipótese única para Task 2.

### Task 2: Teste de regressão do loop de recovery

**Files:**
- Test: `tests/chatgpt-retry-scope-test.mjs`
- Test: novo teste focado somente se o teste existente não cobrir a causa observada.
- Modify: `scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs` somente após RED.

**Interfaces:**
- Consumes: hipótese da Task 1.
- Produces: teste RED que reproduz o loop/escopo incorreto e comportamento GREEN esperado.

- [ ] Escrever/ajustar teste que falha com o comportamento atual.
- [ ] Rodar e confirmar RED pela causa esperada.
- [ ] Implementar a menor correção possível.
- [ ] Rodar teste focal e suíte relacionada até GREEN.

### Task 3: Health e controle de pressão no Fred/9555

**Files:**
- Modify somente os arquivos diretamente ligados ao health/recovery identificados pela Task 2.
- Test: contratos de health/monitor correspondentes.

**Interfaces:**
- Consumes: recovery corrigido da Task 2.
- Produces: health que distingue CDP acessível de progresso real e evita recuperação agressiva sem evidência.

- [ ] Criar teste RED para o estado degradado observado.
- [ ] Implementar gate/cooldown/escopo mínimo conforme causa confirmada.
- [ ] Confirmar que continuidade permanece habilitada.
- [ ] Rodar suíte de continuidade completa.

### Task 4: Deploy controlado e validação runtime

**Files:**
- No direct edits em release ativa.
- Deploy via fluxo canônico do controller.

**Interfaces:**
- Consumes: branch validada.
- Produces: runtime atualizado e comparação pós-correção Fred vs Atendimento.

- [ ] Commit/push/PR/checks/review/merge.
- [ ] Promover SHA mergeado pelo controlador canônico.
- [ ] Confirmar `active_sha == origin_main_sha`.
- [ ] Repetir métricas A/B e health.
- [ ] Executar probe real de continuidade correlacionado a uma conversa Fred autorizada.

### Task 5: Prevenção e encerramento

**Files:**
- Modify: documentação/testes apenas se a causa revelar lacuna não coberta.

**Interfaces:**
- Consumes: evidência pós-deploy.
- Produces: prevenção contra regressão e relatório final.

- [ ] Confirmar Fred sem loop de `stopped_thinking/resume_failed/send_budget_exhausted`.
- [ ] Confirmar Atendimento sem regressão.
- [ ] Registrar causa raiz, correção, métricas antes/depois e limites restantes.
- [ ] Só marcar CONCLUIDO com E2E real e evidência fresca.
