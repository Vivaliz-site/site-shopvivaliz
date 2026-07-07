# ShopVivaliz - Sistema Integrado de Automação

> Última atualização: 2026-07-03  
> Responsável: fredmourao-ai + Claude Code Autonomous  
> Status: ✅ Produção - Pipeline de Deploy Automático Ativo

---

## 📊 Visão Geral do Sistema

ShopVivaliz é um **e-commerce de alto rendimento** com automação completa de:
- **Deploy:** FTP automático em push para `main`
- **Validação:** QA lint a cada push + auto-fix a cada 30 min
- **Execução de Tarefas:** Fila autônoma executada a cada 30 min
- **Agentes IA:** Trio (Gemini → Claude → ChatGPT) para análise e implementação

### 🏗️ Arquitetura de Duas Camadas

```
┌─────────────────────────────────────────────────────────────┐
│                  GitHub (Fonte de Verdade)                  │
│  ✓ Secrets configurados (FTP, APIs)                        │
│  ✓ Workflows habilitados (4 críticos)                       │
│  ✓ Branch protection em main (merge only)                   │
└────────────────────┬────────────────────────────────────────┘
                     │
         ┌───────────┴───────────┐
         │                       │
    ┌────▼─────────┐    ┌───────▼────────┐
    │   Ambiente A │    │  Ambiente B    │
    │  C:\FRED\    │    │  c:/user/      │
    │ site-*       │    │ site-*         │
    │ (Local Dev)  │    │ (CI Helper)    │
    └────┬─────────┘    └────┬───────────┘
         │                    │
         └────────┬───────────┘
                  │
           ┌──────▼──────┐
           │  FTP Deploy │
           │ HostGator   │
           └─────────────┘
```

---

## 🔄 Fluxo de Trabalho Dia-a-dia

### Ciclo Padrão de Desenvolvimento

```
1. Modificação Local (seu editor)
   ├─ Editar arquivo em C:\Users\FRED\site-shopvivaliz\
   └─ Testes locais se possível

2. Commit e Push
   └─ git add .
   └─ git commit -m "feat: descrição clara"
   └─ git push origin main

3. GitHub Dispara Pipeline Automática
   ├─ [1] QA Lint (5 min) - Valida PHP/JS
   │   └─ Se falhar: notifica, não deploy
   ├─ [2] Auto-validation (cron 30 min)
   │   └─ Se encontra issues: auto-fix + commit
   ├─ [3] Deploy (push) → FTP HostGator
   │   └─ Sincroniza código em produção
   └─ [4] Health Check (pós-deploy)
       └─ Testa endpoints críticos

4. Execução de Tarefas (cron 30 min)
   ├─ Lê fila (tasks-queue.json)
   ├─ Gemini analisa
   ├─ Claude implementa
   ├─ GPT revisa
   └─ Auto-commit resultado

5. Monitoramento Contínuo
   ├─ Logs: /logs/
   ├─ Status: /admin/monitor/
   └─ Alertas: GitHub Actions
```

### Exemplos Prácticos

**Adicionar feature simples:**
```bash
cd C:\Users\FRED\site-shopvivaliz
# editar arquivo
git add .
git commit -m "feat: nova feature X"
git push origin main
# Deploy automático em 5-10 minutos ✓
```

**Resolver issue encontrada por validação:**
- Auto-validator detecta problema
- Cria PR automático com fix
- Você revisa e merge
- Deploy automático ocorre

**Adicionar tarefa para agentes:**
```json
// tasks-queue.json
{
  "task_id": "SEO-001",
  "action": "optimize_listing",
  "target": "produto_id_123",
  "priority": "high",
  "assigned_to": ["gemini", "claude"],
  "status": "pending"
}
```
Executor autônomo pega a cada 30 minutos.

---

## 🔐 Configuração de Secrets (GitHub)

### Secrets Obrigatórios para Deploy

Todos configurados em `Settings > Secrets and variables > Actions`:

| Secret | Descrição | Status |
|--------|-----------|--------|
| `FTP_SERVER` | Host HostGator | ✅ Configurado |
| `FTP_USERNAME` | Usuário FTP | ✅ Configurado |
| `FTP_PASSWORD` | Senha FTP | ✅ Configurado |
| `FTP_PORT` | Porta FTP (21 ou 2121) | ✅ Configurado |
| `FTP_REMOTE_DIR` | Path remoto (/public_html) | ✅ Configurado |

### Secrets Opcionais (para agentes IA)

| Secret | Uso | Status |
|--------|-----|--------|
| `ANTHROPIC_API_KEY` | Claude API | ✅ Configurado |
| `OPENAI_API_KEY` | ChatGPT API | ✅ Configurado |
| `GEMINI_API_KEY` | Google Gemini | ✅ Configurado |

---

## ⚙️ Workflows Críticos (Ordem de Execução)

### 1️⃣ `shopvivaliz-qa.yml` - Validação na Admissão
- **Triggers:** Push para main, pull_request
- **Tempo:** 5 minutos
- **Ação:** Lint PHP, validar sintaxe
- **Falha:** Bloqueia merge automático
- **Status:** ✅ **ATIVO**

### 2️⃣ `auto-validation-and-fix.yml` - Auto-Fix de Issues
- **Triggers:** Schedule a cada 30 min, push para main
- **Tempo:** 15 minutos
- **Ação:** 
  - Analisa código
  - Detecta issues
  - Auto-commit de fixes
- **Status:** ✅ **ATIVO**

### 3️⃣ `deploy.yml` - Deploy em Produção
- **Triggers:** Push para main (após QA passar)
- **Tempo:** 10 minutos
- **Ação:**
  - Conecta FTP
  - Sincroniza arquivos com HostGator
  - Verifica health check
- **Status:** ✅ **ATIVO**

### 4️⃣ `ai-autonomous-executor.yml` - Executor de Tarefas
- **Triggers:** Schedule a cada 30 min
- **Tempo:** 20 minutos
- **Ação:**
  - Lê fila de tarefas
  - Chama APIs de IA
  - Implementa mudanças
  - Auto-commit resultado
- **Status:** ✅ **ATIVO**

---

## 📁 Estrutura de Arquivos Críticos

```
site-shopvivaliz/
├── .github/
│   ├── workflows/
│   │   ├── shopvivaliz-qa.yml              ← QA Lint
│   │   ├── auto-validation-and-fix.yml     ← Auto-fix
│   │   ├── deploy.yml                      ← Deploy FTP
│   │   └── ai-autonomous-executor.yml      ← Executor
│   └── scripts/
│       ├── autonomous-validator.py         ← Validação
│       └── autonomous-executor.py          ← Executor
├── scripts/
│   ├── resolve_git_agent_conflict.ps1      ← Resolver conflitos
│   ├── install-git-auto-sync.ps1           ← Auto-sync setup
│   └── git_autonomous_agent.py             ← Agent de merge
├── tasks-queue.json                        ← Fila de tarefas
├── CLAUDE.md                               ← Este arquivo
├── CLAUDE-AUTONOMO.md                      ← Operações autônomas
└── logs/
    ├── validation-*.log                    ← Log de validação
    ├── deployment-*.log                    ← Log de deploy
    └── executor-*.log                      ← Log de tarefas
```

---

## 🚨 Troubleshooting

### Problema: Workflow não executa após push

**Causa possível:** Workflows desabilitados em Settings  
**Solução:**
```
1. GitHub > Settings > Actions > General
2. Selecionar: "All actions and reusable workflows"
3. Save
```

### Problema: Deploy falha com erro de autenticação FTP

**Causa possível:** Secrets FTP_* incorretos ou expirados  
**Solução:**
```bash
# Testar conexão FTP local
ftp -n <FTP_SERVER> <<EOF
user <FTP_USERNAME> <FTP_PASSWORD>
quit
EOF

# Se conectar, secret está OK
# Se falhar, atualizar secret no GitHub Settings
```

### Problema: Auto-validation cria conflitos recursivos

**Causa possível:** Multiple commits simultâneos  
**Solução:**
- Lock implementado em `auto-validation-and-fix.yml`
- Se persistir, delay de 30 seg entre commits
- Log em `/logs/validation-*.log`

### Problema: Tarefas autônomas não executam

**Causa possível:** `tasks-queue.json` com status inválido  
**Solução:**
```json
// Verificar que toda tarefa tem:
{
  "task_id": "ABC-001",
  "status": "pending",        // ← Deve ser 'pending'
  "assigned_to": ["gemini"],  // ← Agente válido
  "action": "valid_action"    // ← Ação registrada
}
```

---

## 🔗 Referências Rápidas

| Necessidade | Arquivo | Ação |
|------------|---------|------|
| Ver logs de validação | `/logs/validation-*.log` | `tail -f` |
| Ver logs de deploy | `/logs/deployment-*.log` | GitHub Actions UI |
| Adicionar tarefa IA | `tasks-queue.json` | Editar + push |
| Modificar pipeline | `.github/workflows/*.yml` | Editar + push |
| Resolver conflito merge | `scripts/resolve_git_agent_conflict.ps1` | Executar |
| Sincronizar ambientes | `git fetch && git pull origin main` | Bash/PowerShell |

---

## 📝 Checklist de Manutenção Semanal

- [ ] Verificar logs em `/logs/` por errors
- [ ] Testar fluxo: commit → push → deploy (5 min)
- [ ] Validar endpoints críticos em produção
- [ ] Revisar `tasks-queue.json` (concluídas vs pendentes)
- [ ] Atualizar secrets se algum API expirou
- [ ] Fazer backup de dados críticos

---

## 🎯 Próximos Passos

1. **Você (C:\Users\FRED):**
   - Resolver merge conflict conforme instruções acima
   - Fazer push para main
   - Notificar conclusão

2. **Sistema (Automático):**
   - QA Lint validará código
   - Deploy sincronizará com HostGator
   - Auto-validation rodará a cada 30 min

3. **Monitoramento:**
   - Abra: https://github.com/fredmourao-ai/site-shopvivaliz/actions
   - Monitore execução dos 4 workflows
   - Verificar health check pós-deploy

---

## 📞 Suporte

Se algo não funcionar:
1. Checar logs relevantes em `/logs/` ou GitHub Actions
2. Verificar erro específico conforme Troubleshooting acima
3. Se necessário, rodar: `git fetch && git pull origin main`

**Repositório:** https://github.com/fredmourao-ai/site-shopvivaliz  
**Live Site:** https://dev.shopvivaliz.com.br/  
**Admin Monitor:** https://dev.shopvivaliz.com.br/admin/monitor/

---

**Sistema integrado e funcionando. Pronto para produção. 🚀**
