# ShopVivaliz - E-commerce Autônomo com Trio IA

Código-fonte e automações do e-commerce ShopVivaliz operado por **Gemini + Claude + ChatGPT** trabalhando autonomamente.

## Direção de plataforma

O projeto está evoluindo para uma base pronta com **MedusaJS** como backend principal, mantendo o stack PHP atual durante a transição.

### Arquitetura alvo

- **Backend:** MedusaJS
- **Frontend:** Next.js Commerce
- **Banco:** PostgreSQL
- **Cache/fila:** Redis
- **Integrações:** Olist, Tiny e marketplaces

### Documento de migração

Veja o plano em [`docs/medusa-migracao-roadmap.md`](docs/medusa-migracao-roadmap.md).

## 🤖 Trio IA Autônomo

Seu ecommerce agora funciona com inteligência artificial 24/7:

- **Gemini** → Analisa arquitetura e infraestrutura
- **Claude** → Implementa código PHP production-ready
- **ChatGPT** → Revisa, encontra bugs, gera checklists

Tudo roda **automaticamente a cada hora** sem intervenção manual.

### Começar Rápido

```bash
# Ver fila de tarefas
python scripts/manage-tasks-queue.py list

# Adicionar nova feature
python scripts/manage-tasks-queue.py add \
  "Adicionar carrinho de compras" \
  "Implementar carrinho persistente com sessão PHP" \
  --priority high

# Ver estatísticas
python scripts/manage-tasks-queue.py stats
```

**Próxima execução:** A cada 1 hora via GitHub Actions (ou manual)

### Documentação Completa

📖 **Leia:** [`AUTONOMOUS_TRIO_GUIDE.md`](AUTONOMOUS_TRIO_GUIDE.md)

Inclui:
- Como gerenciar a fila
- Monitorar execuções
- Customizar agendamento
- Debugar problemas
- Status atual do sistema

### Arquitetura

```
tasks-queue.json → Fila de tarefas
    ↓
ai-autonomous-executor.yml → Workflow executado a cada hora
    ↓
autonomous-executor.py → Pega tarefa pendente
    ↓
ai_collaboration.py → Roda Trio IA (Gemini → Claude → ChatGPT)
    ↓
Git commit + push + deploy automático
    ↓
Próxima tarefa ⏭️
```

### Workflows Disponíveis

| Workflow | Trigger | Descrição |
|----------|---------|-----------|
| **Trio IA Autônomo** | A cada 1h | Executa tarefas automaticamente |
| **Trio IA Ecommerce** | Manual | Roda uma tarefa específica sob demanda |
| **Deploy** | Push/Manual | Sincroniza código com HostGator |
| **QA** | Push | Valida PHP e testes |
| **Setup Branch Protection** | Manual | Configura proteção de branch |

---

## 📂 Estrutura do Projeto

```
├── ai_collaboration.py          # Script principal do Trio IA
├── tasks-queue.json             # Fila de tarefas (gerenciável)
├── AUTONOMOUS_TRIO_GUIDE.md     # Documentação completa
├── .github/workflows/
│   ├── ai-autonomous-executor.yml    # Executor autônomo (1h)
│   ├── ai-trio-ecommerce.yml        # Manual Trio IA
│   ├── deploy.yml               # Deploy automático
│   └── [outros workflows...]
├── scripts/
│   ├── autonomous-executor.py   # Lógica do executor
│   ├── manage-tasks-queue.py    # CLI para gerenciar fila
│   └── generate-report.py       # Relatórios
├── api/                         # APIs e agentes
├── agents/                      # Agentes customizados
└── [código do ecommerce...]
```

---

## 🚀 Operação

### Status Atual
✅ Sistema 100% operacional e autônomo

### Próximos Passos
1. Adicione tarefas à fila (`tasks-queue.json`)
2. Monitore em GitHub Actions
3. Analise relatórios a cada execução
4. Intervenha apenas quando necessário reprioritizar

### Pausar Sistema
```yaml
# Em .github/workflows/ai-autonomous-executor.yml
# Comente a seção schedule:
# schedule:
#   - cron: '0 * * * *'
```

---

## 📊 Monitorar

**GitHub Actions Dashboard:**
https://github.com/fredmourao-ai/site-shopvivaliz/actions/workflows/ai-autonomous-executor.yml

---

## 🔧 Stack

- **Backend:** PHP 8.3, MySQL 5.7
- **IA:** Anthropic Claude, OpenAI GPT-4o, Google Gemini
- **Deployment:** HostGator via FTP
- **Automation:** GitHub Actions, CI/CD

---

## 🛒 Backend Medusa (em desenvolvimento)

Há um backend headless MedusaJS + storefront Next.js em `claude/medusa/apps/`,
integrado ao site PHP legado via webhook (`claude/api/medusa-webhook.php`) e
à sincronização Olist/Tiny ERP. Ainda não está em produção.

📖 **Leia:** [`claude/medusa/README.md`](claude/medusa/README.md) (visão geral)
e [`claude/medusa/DEPLOY-CHECKLIST.md`](claude/medusa/DEPLOY-CHECKLIST.md)
(como rodar localmente, o que falta para produção).

---

## 🧠 Knowledge Base

A documentação completa do sistema está em:
`/docs/knowledge/`

Utilizada por agentes IA e desenvolvedores para diagnóstico e operação.

Consulte primeiro [`docs/knowledge/README.md`](docs/knowledge/README.md) para acessar a visão geral, Squad Chat, troubleshooting, deploy, regras de agentes, atualizador cumulativo, integridade de dados e testes.

---

## 📝 Notas

- Sistema opera 24/7 sem intervenção
- Commits e deploys automáticos
- Relatórios salvos a cada execução
- Você controla a fila, não o sistema
