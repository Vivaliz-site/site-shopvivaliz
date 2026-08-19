# 🤖 ÍNDICE COMPLETO PARA AGENTES IA

**Sistema de Desenvolvimento Autônomo 100% - Acesso Central**

---

## ⛔ PRÉ-LEITURA OBRIGATÓRIA ANTES DE QUALQUER ALTERAÇÃO

Política: `AGENT_DOCS_PREFLIGHT_V1`.

Nenhum agente pode alterar código, configuração, fila, integração, runtime ou produção antes de executar o protocolo de `docs/AGENT-DOCS-PREFLIGHT.md` e obter um recibo atual válido para a tarefa.

Fluxo obrigatório:

```bash
python scripts/agent-docs-gate.py read --agent <agent-id> --task <task-id>
# ler todo o conteúdo emitido e os docs específicos do escopo
python scripts/agent-docs-gate.py acknowledge --agent <agent-id> --task <task-id> --digest <digest>
python scripts/agent-docs-gate.py verify --agent <agent-id> --task <task-id>
```

O `verify` precisa terminar com exit code 0 **antes da primeira mutação**. Se qualquer documento obrigatório mudar, o recibo fica automaticamente inválido e o agente deve reler. Se a ferramenta preferida estiver indisponível, o agente deve investigar e usar uma rota alternativa aprovada em vez de pular a validação.

### ⚠️ Fred-Win: regra de status obrigatória

Para qualquer tarefa que envolva Fred-Win, Windows, MCP, porta 5557, Exchange Admin ou navegador real no computador Windows, leia primeiro **[docs/FRED-WIN-PRIVATE-RELAY.md](docs/FRED-WIN-PRIVATE-RELAY.md)**.

Arquitetura canônica:

```text
GitHub Actions -> Oracle VM 137.131.156.17 via SSH -> VM 127.0.0.1:5557 -> reverse SSH -> Fred-Win 127.0.0.1:5557 -> MCP
```

- Workflow oficial: `.github/workflows/fred-win-remote-action.yml`
- Request oficial: `ops/fredwin-request.json`
- Primeira ação obrigatória: `health`
- Resposta esperada: `status=ok`, `environment=fred-win`, `mcp_version=1.0.0`
- `https://rce-shopvivaliz.trycloudflare.com` é histórico/deprecado e **não pode** ser usado para decidir o estado atual.
- Sem teste pela rota canônica, o estado é **INCONCLUSIVO**, nunca “inativo”.

---

## 📚 DOCUMENTAÇÃO PRINCIPAL

### 🚀 Começar Aqui
- **[START_HERE.md](START_HERE.md)** - Guia rápido de inicialização
- **[docs/AGENT-DOCS-PREFLIGHT.md](docs/AGENT-DOCS-PREFLIGHT.md)** - Leitura obrigatória e recibo antes de mudanças

### 📖 Documentação Completa
- **[README.md](README.md)** - Overview do projeto
- **[AUTONOMOUS_TRIO_GUIDE.md](AUTONOMOUS_TRIO_GUIDE.md)** - Guia do Trio IA
- **[AGENTS.md](AGENTS.md)** - Informações dos agentes
- **[docs/AGENT-MCP-REMOTE.md](docs/AGENT-MCP-REMOTE.md)** - Uso seguro do Remote MCP por agentes
- **[docs/FRED-WIN-PRIVATE-RELAY.md](docs/FRED-WIN-PRIVATE-RELAY.md)** - Arquitetura canônica, health e classificação ATIVO/FALHOU/INCONCLUSIVO do Fred-Win

### 🔧 Configuração & Troubleshooting
- **[MONITOR_SETUP.md](MONITOR_SETUP.md)** - Setup do monitor web
- **[DEPLOY-TROUBLESHOOTING.md](DEPLOY-TROUBLESHOOTING.md)** - Resolver erros de deploy
- **[reports/fredwin-remote-access-repair-2026-08-07.md](reports/fredwin-remote-access-repair-2026-08-07.md)** - Histórico complementar do relay Fred-Win; a norma atual é `docs/FRED-WIN-PRIVATE-RELAY.md`

### 📊 Melhorias & Status
- **[50-IMPROVEMENTS-SUMMARY.md](50-IMPROVEMENTS-SUMMARY.md)** - Todas as 50 melhorias
- **[SYSTEM-IMPROVEMENTS.md](SYSTEM-IMPROVEMENTS.md)** - 5 melhorias principais

---

## 🛠️ SCRIPTS DOS AGENTES

### Core Executors (Execução)
```
scripts/
├── autonomous-executor.py          ⭐ Executor sequencial
├── continuous-executor.py          ⭐ Executor contínuo (3 agentes)
├── parallel-executor.py            ⭐ Executor paralelo com consenso
└── heartbeat-executor.py           - Verificação periódica
```

### Automação & Geração
```
scripts/
├── auto-task-generator.py          - Gera novas tarefas autonomamente
├── auto-documentation.py           - Documenta automaticamente
└── manage-tasks-queue.py           - Gerencia fila de tarefas
```

### Monitoramento & Análise
```
scripts/
├── metrics-collector.py            - Coleta métricas dos agentes
├── observability-suite.py          - Observabilidade completa (11-15)
├── advanced-features.py            - Features avançadas (31-50)
└── deploy-diagnostic.py            - Diagnóstico de deploy
```

### Segurança & Qualidade
```
scripts/
├── quality-assurance.py            - QA automático pré-commit
├── vulnerability-scanner.py        - Scans de segurança
├── rollback-manager.py             - Rollback automático
└── learning-loop.py                - Aprendizado dos agentes
```

### Otimização & Inteligência
```
scripts/
├── smart-task-scheduler.py         - Priorização inteligente
├── version-manager.py              - Versionamento semântico
├── slack-notifier.py               - Notificações
└── generate-report.py              - Relatórios
```

### Configuração
```
scripts/
├── add-secrets.py                  - Adiciona secrets ao GitHub
└── configure-branch-protection.py  - Configura branch protection
```

---

## 🔗 APIs & Endpoints

### Monitor Dashboard
```
WEB:  https://shopvivaliz.com.br/admin/monitor/
API:  https://shopvivaliz.com.br/api/monitor/api.php
```

### Chat com Agentes
```
Endpoint: /api/monitor/chat-stream.php
Método: WebSocket/SSE
Real-time: ✅ Sim
```

### Upload de Anexos
```
Endpoint: /api/monitor/upload-attachment.php
Método: POST
Max: 10MB
```

---

## 📋 TAREFAS NA FILA

Ver arquivo: **tasks-queue.json**

```json
{
  "queue": [
    {
      "id": "task-001",
      "title": "Adicionar filtro de preço",
      "status": "completed"
    },
    {
      "id": "task-002",
      "title": "Implementar carrinho persistente",
      "status": "pending"
    },
    // ... mais 10 tarefas
  ]
}
```

**Total:** 12 tarefas
**Completas:** 1 (8%)
**Pendentes:** 11

---

## 🔄 WORKFLOWS GITHUB ACTIONS

Todos os workflows estão em: `.github/workflows/`

### Main Workflows (Críticos)
```
deploy.yml                          ⭐ Deploy para HostGator
ai-autonomous-executor.yml          ⭐ Executor contínuo 24/7
autonomous-watchdog.yml             - Watchdog e heartbeat do ciclo
parallel-trio-executor.yml          - 3 agentes em paralelo
fred-win-remote-action.yml          ⭐ Relay privado canônico para Fred-Win
```

### Support Workflows
```
executor-watchdog.yml               - Verifica se executor está rodando
auto-task-generator.yml             - Gera tarefas a cada 6h
monitor-chat-responder.yml          - Respostas via GitHub Issues
shopvivaliz-qa.yml                  - QA automático
```

---

## 🧠 ARQUITETURA DOS AGENTES

### Trio IA Composition
```
Gemini (Arquitetura)  ─┐
                       ├─→ Colaboração  ─→ Código
Claude (Implementação)─┤
                       │
ChatGPT (Validação)   ─┘
```

### Fluxo de Execução
```
0. Agente lê os docs obrigatórios e obtém receipt AGENT_DOCS_PREFLIGHT_V1
1. Executor pega tarefa (priorização inteligente)
2. Trio IA processa em paralelo
3. QA automático valida
4. Se OK → Commit + Deploy
5. Se falhar → Rollback + Retry (3x)
6. Métricas registradas
7. Próxima tarefa...
```

---

## 📊 MÉTRICAS & REPORTS

### Relatórios Gerados
```
metrics-report.md                   - Performance dos agentes
ai_collaboration_report_ecommerce.md - Relatório de colaboração
```

### Logs
```
logs/
├── monitor-commands.log
├── monitor-messages.log
├── monitor-responses.jsonl
├── rollbacks.jsonl
├── errors-and-solutions.jsonl
├── security-scan.jsonl
└── continuous-execution.log
```

---

## 🚀 COMO USAR (Para Agentes)

### 0️⃣ Fazer preflight obrigatório
```bash
python scripts/agent-docs-gate.py read --agent <agent-id> --task <task-id>
```

### 1️⃣ Verificar Status
```bash
python scripts/metrics-collector.py
```

Para Fred-Win, não use o status genérico acima como substituto do health canônico. Use `.github/workflows/fred-win-remote-action.yml` com `ops/fredwin-request.json` definido como `{"action":"health"}`.

### 2️⃣ Executar Próxima Tarefa
```bash
python scripts/continuous-executor.py
```

### 3️⃣ Diagnosticar Deploy
```bash
python scripts/deploy-diagnostic.py
```

### 4️⃣ Rodar QA
```bash
python scripts/quality-assurance.py
```

### 5️⃣ Gerar Nova Documentação
```bash
python scripts/auto-documentation.py
```

---

## ✅ CHECKLIST PARA AGENTES

- [ ] Executei `AGENT_DOCS_PREFLIGHT_V1`, li os docs e o `verify` passou
- [ ] Ler START_HERE.md
- [ ] Verificar tarefas em tasks-queue.json
- [ ] Confirmar que pode acessar GitHub Secrets
- [ ] Testar acesso ao FTP
- [ ] Verificar status do monitor
- [ ] Se a tarefa envolve Fred-Win, li `docs/FRED-WIN-PRIVATE-RELAY.md` e executei o `health` canônico
- [ ] Não usei o endpoint Cloudflare antigo para classificar Fred-Win
- [ ] Rodar primeiro teste de QA
- [ ] Executar primeira tarefa

---

## 🔐 Secrets Necessários

Agentes precisam de acesso a:
```
ANTHROPIC_API_KEY       - Claude API
OPENAI_API_KEY          - ChatGPT API
GEMINI_API_KEY          - Gemini API
FTP_SERVER              - Servidor FTP
FTP_USERNAME            - Usuário FTP
FTP_PASSWORD            - Senha FTP
FTP_PORT                - Porta FTP
FTP_REMOTE_DIR          - Diretório remoto
EMAIL_USER              - Email para notificações
EMAIL_PASSWORD          - Senha email
SQUAD_TOKEN             - Token da Squad (opcional)
```

---

## 📞 Suporte Interno

Se agente encontrar bloqueio:

1. **Verificar logs:** `logs/` directory
2. **Rodar diagnóstico:** `scripts/deploy-diagnostic.py`
3. **Consultar troubleshooting:** `DEPLOY-TROUBLESHOOTING.md`
4. Para Fred-Win, seguir a ordem de diagnóstico de `docs/FRED-WIN-PRIVATE-RELAY.md` e ler `C:\site-shopvivaliz\logs\fredwin-remote-bootstrap.log` e `C:\site-shopvivaliz\logs\fredwin-managed-tunnel.log`
5. **Notificar via email:** fredmourao@gmail.com (automático)

---

## 🎯 Resumo de Responsabilidades

### Gemini (Arquitetura)
- Analisar requisitos
- Desenhar arquitetura
- Validar design

### Claude (Implementação)
- Escrever código PHP/JS
- Implementar features
- Otimizar performance

### ChatGPT (Validação)
- Revisar código
- Rodar testes
- Validar qualidade
- Nunca inferir que Fred-Win está inativo sem testar a rota privada canônica

---

## 📈 Próximos Passos

1. ✅ Executar preflight e ler documentação completa
2. ✅ Acessar todos os scripts
3. ✅ Executar primeira tarefa
4. ✅ Monitorar progresso via dashboard
5. ✅ Reportar bloqueios por email

---

**Sistema pronto para operação 24/7 autônoma!** 🚀

*Última atualização: 2026-08-18*  
*Todas as alterações por agentes exigem `AGENT_DOCS_PREFLIGHT_V1` antes da primeira mutação.*
