# 🔄 SYNC DAEMON RUNBOOK

**Última atualização:** 2026-07-15  
**Status:** ⚠️ AUDITORIA CRÍTICA EM PROGRESSO  
**Segurança:** OBRIGATÓRIO ler AGENTS.md antes de modificar

---

## ⚠️ STATUS CRÍTICO

**INCONCLUSIVO:**
- Daemon anterior DESCONHECIDO já está rodando na VM
- Script novo `git-auto-sync.py` foi REESCRITO com segurança
- Arquivo novo NÃO FOI SINCRONIZADO para a VM ainda
- Working tree SUJO na VM (`storage/commerce_signals.json`)
- Pedidos com permissões incorretas

**AÇÃO NECESSÁRIA:** Investigar daemon anterior antes de prosseguir

---

## 🏗️ Arquitetura

```
┌──────────────────────────────────────────────────────────────┐
│ GitHub (source)                                              │
│ main branch                                                  │
└─────────────────────────────────────────────────────────────┘
                              ↑
                    git fetch (a cada 2 min)
                              │
┌─────────────────────────────────────────────────────────────┐
│ VM Oracle (137.131.156.17)                                   │
│ Cron: */2 * * * *                                            │
│ Script: scripts/git-auto-sync.py                             │
└─────────────────────────────────────────────────────────────┘
                              │
                   git merge --ff-only
                              ↓
                    Production Code Ready
```

---

## 📋 Componentes

### 1. Daemon Script (NOVO - SEGURO)

**Arquivo:** `scripts/git-auto-sync.py`  
**Tipo:** Python 3  
**Execução:** Cron `*/2 * * * *` (a cada 2 minutos)

**Mudanças de segurança:**
- ❌ Removeu `git reset --hard` (perigoso)
- ✅ Usa `git fetch` + `git merge --ff-only` (seguro)
- ✅ Valida working tree antes de merge
- ✅ Rejeita se há arquivos modificados
- ✅ Registra SHA completo para auditoria
- ✅ Falha rápido com mensagens claras

**Logs:**
```
/var/log/shopvivaliz/git-auto-sync-YYYYMMDD.log
/var/log/shopvivaliz/cron.log
```

### 2. Instalador

**Arquivo:** `scripts/install-git-sync-cron.sh`  
**Status:** Precisa de `set -Eeuo pipefail` (TODO)

```bash
bash scripts/install-git-sync-cron.sh
```

### 3. Verificador (NOVO)

**Arquivo:** `scripts/verify-sync-daemon.sh`  
**Tipo:** Bash com `set -Eeuo pipefail`  
**Uso:** Teste independente do daemon

```bash
bash scripts/verify-sync-daemon.sh
```

---

## 🔐 Proteções de Dados

**Arquivos em `.gitignore` (não sincronizados):**

| Arquivo | Razão |
|---------|-------|
| `storage/orders/` | Pedidos em produção (risco de perda) |
| `storage/codex-bridge/state.json` | Estado mutável |
| `storage/orchestrator/queue.json` | Fila de tarefas |
| `.agent-heartbeats/` | Heartbeats de agentes |
| `.git-sync.lock` | Lock do daemon |
| `.git-auto-sync.log` | Logs locais |

**Observação:** Arquivos em `storage/orders/` AINDA estão sendo commitados (risco!). TODO: Migrar para banco de dados ou implementar backup automático.

---

## ⚙️ Configuração

### Na Máquina Local

```bash
# 1. Commitar mudanças
git add .
git commit -m "fix: sync daemon com segurança e validações"
git push origin main
```

### Na VM

```bash
# 1. SSH
ssh -i ~/.ssh/ssh-key-2026-07-04.key ubuntu@137.131.156.17

# 2. Instalar novo daemon (quando pronto)
cd /home/ubuntu/site-shopvivaliz
bash scripts/install-git-sync-cron.sh

# 3. Verificar cron
crontab -l | grep git-auto-sync

# 4. Ver logs
tail -f /var/log/shopvivaliz/git-auto-sync-*.log
```

---

## 🧪 Teste Independente

**Script:** `scripts/verify-sync-daemon.sh`

**O que faz:**
1. ✅ Registra SHA antes
2. ✅ Cria commit de teste
3. ✅ Push para origin/main
4. ✅ Aguarda 4 min (SEM intervir)
5. ✅ Verifica SHA na VM via SSH
6. ✅ Valida correspondência

**Uso:**

```bash
cd /c/site-shopvivaliz
bash scripts/verify-sync-daemon.sh
```

**Resultado esperado:**
```
=== VALIDAÇÃO FINAL ===
✅ COMPROVADO: SHA bate!

Resumo:
  Commit: abc123def456...
  Tempo: 2026-07-15T...
  Status: SINCRONIZADO
```

---

## 🚨 Problemas Conhecidos

### Problema 1: Script não existe na VM

**Sintoma:** Cron falha com `FileNotFoundError`  
**Causa:** Script nunca foi copiado para VM  
**Solução:** Fazer push das mudanças e rodar install-git-sync-cron.sh

### Problema 2: Working tree sujo

**Sintoma:** Daemon rejeita merge  
**Causa:** Arquivo modificado não commitado  
**Solução:**
```bash
ssh ubuntu@137.131.156.17
cd /home/ubuntu/site-shopvivaliz
git status  # Ver qual arquivo
git stash   # Ou git commit se mudanças são legítimas
```

### Problema 3: Permissões incorretas

**Sintoma:** Permission denied em arquivos  
**Causa:** Arquivo criado com permissões restritas  
**Solução:**
```bash
chmod 644 storage/orders/*.json
chmod 644 storage/codex-bridge/state.json
```

---

## 📊 Monitoramento

### Verificar se daemon está rodando

```bash
ssh ubuntu@137.131.156.17 "crontab -l | grep git-auto-sync"
```

### Ver últimas execuções

```bash
ssh ubuntu@137.131.156.17 "tail -20 /var/log/shopvivaliz/git-auto-sync-*.log"
```

### Verificar SHAs

```bash
# Local
git log --oneline -1

# VM
ssh ubuntu@137.131.156.17 "git -C /home/ubuntu/site-shopvivaliz log --oneline -1"
```

---

## 🔧 Troubleshooting

### Daemon não sincroniza

1. Verificar se cron job existe
2. Verificar se script existe em `/home/ubuntu/site-shopvivaliz/scripts/git-auto-sync.py`
3. Verificar logs
4. Validar working tree

### Teste verify-sync-daemon.sh falha

1. Verificar SSH key
2. Verificar que estamos em branch `main`
3. Verificar que working tree está limpa
4. Verificar logs da VM

---

## 📝 Regras Obrigatórias

Ver `AGENTS.md`:

- [ ] ⛔ NUNCA use `git reset --hard` em produção
- [ ] ✅ Use `git merge --ff-only` (seguro)
- [ ] ✅ Valide working tree antes de git pull
- [ ] ✅ Todo script shell use `set -Eeuo pipefail`
- [ ] ✅ Registre SHA ANTES e DEPOIS
- [ ] ✅ Nenhuma declaração de sucesso sem evidência
- [ ] ✅ Testes REAIS, nunca simulação
- [ ] ✅ Proteja dados operacionais em `.gitignore`

---

## 📞 Referências

| Item | Link |
|------|------|
| AGENTS.md | Regras obrigatórias para agentes |
| AUDITORIA-DAEMON-CRITICO | Achados da auditoria |
| scripts/git-auto-sync.py | Daemon (seguro) |
| scripts/verify-sync-daemon.sh | Teste independente |
| .gitignore | Proteção de dados |

---

**Status:** 🟡 INCONCLUSIVO - Aguardando investigação  
**Segurança:** CRÍTICA - Leia AGENTS.md  
**Próximo:** Limpar problemas críticos e retentar instalação
