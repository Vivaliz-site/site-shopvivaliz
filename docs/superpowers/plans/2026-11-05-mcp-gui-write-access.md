# MCP GUI write access Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Permitir cliques e digitação nas ferramentas desktop do MCP sem exigir manualmente runtime lease/fencing.

**Architecture:** O wrapper `remote-control-browser-mcp` continuará sendo o ponto de entrada das ferramentas desktop. A remoção será somente da chamada explícita ao gate de runtime no dispatcher desktop; validações de host, superfície, coordenadas, texto, autenticação do MCP e auditoria permanecem intactas.

**Tech Stack:** Python 3, unittest/pytest, MCP HTTP dispatcher.

**Spec:** `docs/superpowers/specs/2026-11-05-mcp-gui-write-access-design.md`

## Global Constraints

- Não remover autenticação, allowlist, validação de superfície, auditoria ou proteção de operações administrativas.
- Não alterar o gate de autenticação de contas nem o gate de mutações de navegador base.
- Não editar a alteração preexistente em `storage/codex-bridge/state.json`.
- Teste novo deve falhar antes do patch e passar depois.

## Review Focus

- `desktop_click` sem lease com handoff durável: deve alcançar o bridge; coberto pela regressão dispatcher.
- `desktop_type` sem lease com handoff durável: deve permanecer no mesmo caminho sem alterar o texto protegido; revisar junto ao dispatcher.
- `browser_click` sem lease: deve continuar protegido pelo gate base; coberto pela suíte base existente.
- `service_action` sem lease: deve continuar protegido; coberto pela suíte base existente.
- Host/coordenadas/texto inválidos: devem continuar falhando nas validações do wrapper; coberto pela suíte browser existente.

### Task 1: Regression for desktop actions without manual runtime lease

**Files:**
- Modify: `tests/remote-control-browser-mcp-test.py`

- [ ] **Step 1: Write the failing test**

Adicionar `test_desktop_dispatch_does_not_require_runtime_lock_when_handoff_enabled`, habilitando `SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF=1`, fazendo `base._assert_runtime_mutation` lançar `runtime_lock_required`, mockando `desktop_click`, e afirmando que `execute_tool` retorna o resultado do click.

- [ ] **Step 2: Run test to verify it fails**

Run: `python -m pytest tests/remote-control-browser-mcp-test.py -k desktop_dispatch_does_not_require_runtime_lock_when_handoff_enabled -q`

Expected: FAIL porque o dispatcher chama atualmente `base._assert_runtime_mutation`.

### Task 2: Remove only the desktop runtime-lock gate

**Files:**
- Modify: `remote-control-browser-mcp/server.py:1868-1881`
- Modify: `remote-control-browser-mcp/SPEC.md:46-50`

- [ ] **Step 1: Implement the minimal change**

Remover a chamada `base._assert_runtime_mutation(name, args)` do ramo que despacha `desktop_open`, `desktop_click` e `desktop_type`. Manter o despacho e todas as validações de cada função. Atualizar o contrato para declarar que as ações desktop não exigem lease manual, mantendo controles de autenticação, allowlist, auditoria e serialização.

- [ ] **Step 2: Run the focused regression**

Run: `python -m pytest tests/remote-control-browser-mcp-test.py -k desktop_dispatch_does_not_require_runtime_lock_when_handoff_enabled -q`

Expected: PASS.

### Task 3: Verify preserved protections and syntax

**Files:**
- No new files.

- [ ] **Step 1: Run desktop/browser focused tests**

Run: `python -m pytest tests/remote-control-browser-mcp-test.py -q` in POSIX CI/runtime; on Windows record the existing `pwd` import limitation.

- [ ] **Step 2: Run base lock protection tests**

Run: `python -m pytest tests/remote-control-mcp-test.py -k 'runtime_lock or controller_promotion or service_action' -q` in POSIX CI/runtime.

- [ ] **Step 3: Run syntax and diff checks**

Run: `python -m py_compile remote-control-browser-mcp/server.py remote-control-mcp/server.py` and `git diff --check`.

- [ ] **Step 4: Review changed files and commit**

Confirm only the intended test, wrapper, contract, spec and plan/design files changed; preserve the existing state-file modification. Commit with `fix(remote-control): allow desktop GUI input without manual runtime lock`.
