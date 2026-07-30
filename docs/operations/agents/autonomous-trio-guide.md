# 🤖 Guia - Trio IA Autônomo ShopVivaliz

> Migrado de `AUTONOMOUS_TRIO_GUIDE.md` durante organização estrutural do repositório.

Seu ecommerce agora opera com **Trio IA 100% autônomo**. Gemini, Claude e ChatGPT trabalham juntos sem intervenção manual.

---

## 📋 Como Funciona

### Fluxo Automático
1. **Fila de tarefas** (`tasks-queue.json`) contém features a implementar
2. **Executor Autônomo** roda a cada **1 hora** (ou manualmente)
3. Pega a primeira tarefa pendente
4. **Gemini** → Analisa arquitetura
5. **Claude** → Implementa código PHP
6. **ChatGPT** → Revisa e gera relatório
7. ✅ Código é commitado e deployado automaticamente
8. ⏭️ Passa para a próxima tarefa

### Sem Intervenção Manual
- Nenhuma aprovação necessária
- Deploy automático em `main`
- Relatórios salvos a cada execução
- Você só intervém quando precisa reprioritizar ou adicionar tarefas

---

## 🎯 Gerenciar a Fila de Tarefas

### Via Linha de Comando (Local)

```bash
python scripts/manage-tasks-queue.py list
python scripts/manage-tasks-queue.py list --status pending
python scripts/manage-tasks-queue.py add "Integrar Stripe" "Adicionar gateway de pagamento Stripe com webhooks" --priority high
python scripts/manage-tasks-queue.py remove task-001
python scripts/manage-tasks-queue.py mark task-002 --status completed
python scripts/manage-tasks-queue.py priority task-003 high
python scripts/manage-tasks-queue.py stats
```

### Via GitHub

Editar `tasks-queue.json` diretamente no repositório e commitar a alteração.

---

## 📊 Monitorar Execução

### GitHub Actions

Workflow principal registrado em `docs/knowledge/routines-registry.md`.

Status de execução:

- ✅ Success = tarefa completada e deployada
- ⏳ Skipped = nenhuma tarefa pendente
- ❌ Failed = erro; revisar logs

### Relatórios de Execução

Cada execução gera relatório em artifacts quando configurado:

```text
relatorio-<run_id>/trio-report.txt
```

---

## 🔄 Agendar Execuções

### Automático

A cadência ativa deve ser confirmada no workflow atual e no registro de rotinas.

### Manual

Actions → workflow do Trio IA → Run workflow.

---

## 🛑 Pausar o Executor

- Desativar o workflow no GitHub Actions; ou
- Comentar o bloco `schedule` e manter apenas `workflow_dispatch`.

---

## 🔍 Debug

```bash
python ai_collaboration.py --modo diagnostico
python scripts/manage-tasks-queue.py list
```

Verificar secrets de IA no GitHub Actions sem expor valores.

---

## 🚀 Resumo de Comandos

| Ação | Comando |
|---|---|
| Listar tarefas | `python scripts/manage-tasks-queue.py list` |
| Adicionar tarefa | `python scripts/manage-tasks-queue.py add "Título" "Descrição"` |
| Executar agora | GitHub Actions → Run workflow |
| Pausar | GitHub Actions → Disable workflow |

---

## Status de migração

- Caminho antigo: `AUTONOMOUS_TRIO_GUIDE.md`
- Caminho canônico: `docs/operations/agents/autonomous-trio-guide.md`
- Compatibilidade: arquivo antigo mantido como ponte.
