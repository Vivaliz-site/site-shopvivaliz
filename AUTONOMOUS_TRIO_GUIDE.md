# 🤖 Guia - Trio IA Autônomo ShopVivaliz

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
# Listar todas as tarefas
python scripts/manage-tasks-queue.py list

# Listar só pendentes
python scripts/manage-tasks-queue.py list --status pending

# Adicionar nova tarefa
python scripts/manage-tasks-queue.py add \
  "Integrar Stripe" \
  "Adicionar gateway de pagamento Stripe com webhooks" \
  --priority high

# Remover tarefa
python scripts/manage-tasks-queue.py remove task-001

# Marcar como completa
python scripts/manage-tasks-queue.py mark task-002 --status completed

# Alterar prioridade
python scripts/manage-tasks-queue.py priority task-003 high

# Ver estatísticas
python scripts/manage-tasks-queue.py stats
```

### Via GitHub (Editar JSON Diretamente)

1. Vá a: https://github.com/fredmourao-ai/site-shopvivaliz/blob/main/tasks-queue.json
2. Clique no ✏️ (Edit)
3. Adicione/remova tarefas no JSON
4. Clique em "Commit changes"

---

## 📊 Monitorar Execução

### Acompanhar no GitHub Actions
**URL:** https://github.com/fredmourao-ai/site-shopvivaliz/actions/workflows/ai-autonomous-executor.yml

Status de cada execução:
- ✅ **Success** = Tarefa completada e deployada
- ⏳ **Skipped** = Nenhuma tarefa pendente (fila vazia)
- ❌ **Failed** = Erro (revise logs)

### Relatórios de Execução
Cada execução gera um relatório disponível em **Artifacts**:
```
relatorio-<run_id>/trio-report.txt
```

---

## 🔄 Agendar Execuções

### Automático (Padrão)
Roda a cada **1 hora** (cron: `0 * * * *`)

### Manual (Via GitHub)
1. Vá a **Actions** → **Trio IA - Executor Autônomo**
2. Clique **Run workflow** → **Run workflow**
3. Executa imediatamente

### Customizar Intervalo
Edite `.github/workflows/ai-autonomous-executor.yml`:
```yaml
schedule:
  - cron: '0 */6 * * *'  # A cada 6 horas
  - cron: '0 9 * * MON'  # Às 9h de segunda
```

---

## 📝 Estrutura da Tarefa

```json
{
  "id": "task-001",
  "title": "Adicionar filtro de preço",
  "description": "Implementar filtro de preço com Ajax...",
  "priority": "high",
  "status": "pending",
  "created_at": "2026-06-27T12:00:00Z"
}
```

**Status:**
- `pending` → Aguardando execução
- `completed` → Já foi feita

**Prioridade:** `low`, `medium`, `high`

---

## 🛑 Pausar o Executor

Se precisar pausar temporariamente:

1. Desative o workflow no GitHub:
   - Actions → Trio IA - Executor Autônomo
   - ... → Disable workflow

2. Ou edite a cron em `.github/workflows/ai-autonomous-executor.yml`:
   ```yaml
   on:
     # schedule:
     #   - cron: '0 * * * *'  # COMENTADO = DESATIVADO
     workflow_dispatch:
   ```

---

## 🔍 Debugar Problemas

### Ver logs completos
1. **Actions** → workflow executado
2. Clique em **Configurar main branch** (ou tarefa específica)
3. Expanda **Executar próxima tarefa**

### Tarefa travou?
1. Verifique se `ai_collaboration.py` está funcional:
   ```bash
   python ai_collaboration.py --modo diagnostico
   ```

2. Verifique os secrets no GitHub:
   - Settings → Secrets and variables → Actions
   - `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `GEMINI_API_KEY`

### Fila vazia?
Adicione tarefas:
```bash
python scripts/manage-tasks-queue.py add "Tarefa teste" "Descrição" --priority high
```

---

## 📧 Notificações (Futuro)

Para receber emails em `fredmourao@gmail.com` a cada execução, adicione um step no workflow que envia email via SMTP ou SendGrid.

---

## 🚀 Resumo de Comandos

| Ação | Comando |
|------|---------|
| Listar tarefas | `python scripts/manage-tasks-queue.py list` |
| Adicionar | `python scripts/manage-tasks-queue.py add "Título" "Descrição"` |
| Executar agora | GitHub Actions → **Run workflow** |
| Ver status | GitHub Actions → Workflow runs |
| Pausar | GitHub Actions → Disable workflow |

---

## ✅ Status Atual

- ✅ Sistema autônomo operacional
- ✅ Branch protection configurada
- ✅ Todos os workflows com permissões completas
- ✅ Fila de tarefas pronta
- ⏳ Próxima execução em: **~1 hora**

**Seu ecommerce está no piloto automático! 🛸**
