# Production Audit Runtime Recovery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminar falsos positivos/negativos da auditoria, restaurar o runtime crítico da produção e provar o fluxo real de catálogo, frete, checkout e integrações.

**Architecture:** Manter a VM A1 `163.176.103.253` como origem única de produção e usar os contratos existentes em `/docs/knowledge`. Corrigir primeiro os gates/testes, depois o deploy/runtime e por último revalidar providers reais sem expor secrets.

**Tech Stack:** PHP 8.x, Bash, Python 3, GitHub Actions, systemd, Apache, APIs Olist/Tiny, Mercado Pago e Melhor Envio.

**Spec:** `docs/knowledge/agent-rules.md`

## Global Constraints

- HTTP 200 não prova funcionamento.
- Não imprimir tokens, chaves ou senhas.
- Squad Chat só é saudável com `ok=true`, `endpoint=squad-chat` e `providers` presente.
- Qualquer integração crítica falhando mantém `PRODUCTION_FUNCTIONAL_AUDIT=FAIL`.
- Produção real: A1 `163.176.103.253`; IP E2 `137.131.156.17` é legado.

---

### Task 1: Corrigir contratos da auditoria funcional

**Files:** `scripts/production-functional-audit.sh`, `tests/test_production_functional_audit_contract.py`, `tests/test_catalog_available_only_contract.py`

- [ ] Criar/ajustar testes para a chave real `olist_tiny` e filtro real `available=1`.
- [ ] Executar os testes e confirmar falha pelo contrato incorreto atual.
- [ ] Corrigir apenas os parâmetros/chaves incorretos no script.
- [ ] Reexecutar testes e `bash -n`.
### Task 2: Corrigir gate e deploy da A1

**Files:** `.github/workflows/quality-gate.yml`, `.github/workflows/master-production-pipeline.yml`, testes de contrato de runtime.

- [ ] Adicionar teste que rejeite a contradição do Quality Gate e o IP E2 em caminhos ativos.
- [ ] Executar RED.
- [ ] Corrigir o Quality Gate para exigir A1 e rejeitar somente o IP legado.
- [ ] Garantir que o deploy reinstale/reconcilie os serviços systemd de token após trocar `current`.
- [ ] Executar contratos de deploy e sintaxe YAML/commands relevantes.

### Task 3: Restaurar configuração crítica sem expor credenciais

**Files:** workflows/configuradores existentes de runtime e OAuth.

- [ ] Mapear quais secrets de produção já são consumidos pelos workflows.
- [ ] Usar apenas GitHub Secrets/arquivos privados canônicos; nunca copiar valores para logs ou repo.
- [ ] Restaurar Olist/Tiny via fluxo OAuth canônico se os secrets existirem.
- [ ] Restaurar Mercado Pago no runtime se o secret existir.
- [ ] Diagnosticar Melhor Envio comparando o probe de health com a chamada funcional de cotação antes de trocar credenciais.

### Task 4: Auditoria final independente

- [ ] Confirmar release SHA servido.
- [ ] Confirmar serviços críticos instalados e ativos.
- [ ] Executar Squad Chat conforme contrato de três campos.
- [ ] Executar catálogo disponível e garantir estoque > 0 em todos os itens retornados.
- [ ] Executar cotação real de frete com produto disponível.
- [ ] Executar `production-functional-audit.sh` autenticado.
- [ ] Só declarar PASS se o script terminar com `PRODUCTION_FUNCTIONAL_AUDIT=PASS`.
