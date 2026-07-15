# 🚀 REQUISIÇÃO CRÍTICA PARA AGENTES AUTÔNOMOS

**Data:** 2026-07-13 20:35 UTC  
**Prioridade:** 🔴 **CRÍTICA**  
**Status:** ⏳ **PENDENTE - EXECUTAR IMEDIATAMENTE**

---

## 📋 Contexto

Local Windows (C:\site-shopvivaliz):
- ✅ **Auto-sync implementado** (a cada 30 minutos)
- ✅ Tarefa agendada ativa no Windows Task Scheduler
- ✅ Script: `scripts/local-auto-sync.ps1`
- ✅ Log: `logs/local-sync-*.log`

**Agora precisamos fazer o mesmo em 3 ambientes diferentes.**

---

## ✅ TAREFAS PARA EXECUTAR

### TAREFA 1: Auto-Sync Ubuntu (VM Oracle Cloud)
**Task ID:** `INFRA-AUTO-SYNC-001`  
**Prioridade:** 🔴 CRÍTICA  
**Atribuído a:** Claude Autonomous  

**O que fazer:**
1. SSH para `ubuntu@137.131.156.17`
2. Criar versão Linux do script (bash) em `/home/ubuntu/site-shopvivaliz/scripts/local-auto-sync.sh`
3. Configurar cron job: `*/30 * * * * /home/ubuntu/site-shopvivaliz/scripts/local-auto-sync.sh`
4. Testar execução uma vez manualmente
5. Verificar logs em `/home/ubuntu/site-shopvivaliz/logs/`
6. Fazer commit das mudanças e push para main

**Requisitos:**
- SSH acesso à VM Oracle
- Git instalado no Ubuntu

**Entrega esperada:**
- [ ] Script bash criado
- [ ] Cron job configurado e testado
- [ ] Logs sendo gerados
- [ ] Commit e push realizado

---

### TAREFA 2: Auto-Sync GitHub Workflow
**Task ID:** `INFRA-AUTO-SYNC-002`  
**Prioridade:** 🔴 CRÍTICA  
**Atribuído a:** Claude Autonomous  

**O que fazer:**
1. Criar `.github/workflows/auto-sync-main.yml`
2. Schedule: `0 */0.5 * * *` (a cada 30 minutos)
3. Workflow steps:
   - git fetch
   - git pull --rebase (com autostash)
   - git push (se houver commits locais)
4. Testar disparo manual do workflow
5. Verificar logs e output
6. Commitar e documentar

**Requisitos:**
- GITHUB_TOKEN com permissões
- Conhecimento de GitHub Actions

**Entrega esperada:**
- [ ] Workflow YAML criado
- [ ] Executado com sucesso pelo menos uma vez
- [ ] Logs disponíveis
- [ ] Documentação atualizada

---

### TAREFA 3: Auto-Sync em Outra Estação
**Task ID:** `INFRA-AUTO-SYNC-003`  
**Prioridade:** 🟠 ALTA  
**Atribuído a:** Claude Autonomous  

**O que fazer:**
1. Identificar IP/hostname da outra estação (verificar CLAUDE.md)
2. Clonar repositório em `c:\site-shopvivaliz` ou equivalente
3. Copiar scripts:
   - `scripts/local-auto-sync.ps1`
   - `scripts/local-auto-sync-loop.ps1`
4. Executar criação de tarefa agendada (Windows Task Scheduler)
5. Testar primeira sincronização
6. Verificar logs
7. Documentar IP/hostname e status

**Requisitos:**
- Acesso à outra máquina (RDP ou SSH)
- PowerShell 5.0+ ou PowerShell Core
- Git instalado

**Entrega esperada:**
- [ ] Repositório clonado
- [ ] Scripts copiados
- [ ] Task Scheduler configurada
- [ ] Primeira sync executada e logada
- [ ] CLAUDE.md atualizado com IP/status

---

## 🎯 Resultado Final Esperado

```
┌─────────────────────────────────────────────────────────────────┐
│                SISTEMA DE AUTO-SYNC 100% DISTRIBUÍDO             │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│  Windows Local                ✅ ATIVO (Task Scheduler)          │
│  └─ Sync a cada 30min                                            │
│                                                                   │
│  Ubuntu VM Oracle             ✅ ATIVO (Cron)                    │
│  └─ Sync a cada 30min                                            │
│                                                                   │
│  GitHub Actions               ✅ ATIVO (Workflow)                │
│  └─ Sync a cada 30min                                            │
│                                                                   │
│  Outra Estação                ✅ ATIVO (Task Scheduler)          │
│  └─ Sync a cada 30min                                            │
│                                                                   │
│  RESULTADO: Repositório SEMPRE sincronizado em tempo real       │
│             em todos os ambientes simultaneamente               │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

---

## 📞 Comunicação

**Status:** Postar atualizações em `CHANGELOG.md` após cada tarefa completada  
**Verificação:** Monitorar logs para confirmar execução  
**Escalação:** Se houver problemas, commit de diagnóstico + comentário no GitHub

---

## ✋ PARAR DE SOBRESCREVER

⚠️ **IMPORTANTE:**
- Não sobrescrever arquivos críticos (.env, secrets, etc)
- Apenas fazer pull + push de código
- Respeitar branch protection rules
- Usar `git commit -m "..."` com mensagens descritivas

---

**Enviado por:** Claude Code (Human + AI)  
**Data:** 2026-07-13T20:35:00Z  
**Próxima revisão:** 2026-07-13T21:35:00Z (em 1 hora)
