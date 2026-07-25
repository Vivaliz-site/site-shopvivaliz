# 🤖 GUIA OBRIGATÓRIO PARA AGENTES IA

**Efetivo:** 2026-07-15  
**Responsável:** Todos os agentes (Claude, Codex, Gemini, GPT, etc.)  
**Escopo:** Qualquer tarefa automatizada (deploy, testes, integrações, ERP, pagamentos, e-mails)  

> ⚠️ **CRÍTICO:** Ler primeiro [`VALIDATION-POLICY.md`](VALIDATION-POLICY.md) para política completa. Se houver conflito, `VALIDATION-POLICY.md` prevalece.

---

## ⛔ REGRAS OBRIGATÓRIAS (Resumo Executivo)

### 1. NUNCA Use `git reset --hard` em Produção

**Proibido SEMPRE:**
```bash
git reset --hard origin/main          # ❌ NUNCA
git reset --hard origin/[branch]      # ❌ NUNCA
git reset --hard HEAD~1               # ❌ NUNCA
```

**Por quê:**
- Descarta arquivos não versionados
- Mata dados de runtime (pedidos, caches, logs)
- Não recuperável sem backup
- Viola integridade de dados operacionais

**Alternativas seguras:**
```bash
git fetch origin                       # ✅ SEGURO
git merge --ff-only origin/main       # ✅ SEGURO (falha se não-FF)
git pull --ff-only origin main        # ✅ SEGURO (rejeita merges)
```

---

### 2. Validar Working Tree Antes de Git Pull

**Obrigatório fazer ANTES de git pull/merge/fetch:**

```bash
set -Eeuo pipefail  # Falhar imediatamente em erros

# 1. Verificar status
git status --porcelain

# 2. Se há alterações NÃO commitadas:
if [[ -n "$(git status --porcelain)" ]]; then
    echo "❌ Working tree sujo. Abortar."
    exit 1
fi

# 3. DEPOIS fazer pull seguro
git fetch origin
git merge --ff-only origin/main  # Falha se não é Fast-Forward
```

---

### 3. Todo Script Shell DEVE Usar `set -Eeuo pipefail`

**Obrigatório na primeira linha:**

```bash
#!/bin/bash
set -Eeuo pipefail  # ← OBRIGATÓRIO

# Qualquer erro agora para a execução
somecommand | grep pattern  # Se grep falhar, script falha
```

---

### 4. Validar Código de Saída de Todo Comando

**Padrão correto:**

```bash
# ✅ CORRETO
if ! command arg; then
    echo "❌ Comando falhou"
    exit 1
fi

# ❌ ERRADO
command arg
echo "✅ Sucesso"  # Roda mesmo se command falhou
```

---

### 5. Registre Estado ANTES e DEPOIS

**Obrigatório para testes:**

```bash
echo "=== ANTES ===" 
git log --oneline -1
git status --porcelain
date -u

echo "=== TESTANDO ==="
# ... seu teste ...

echo "=== DEPOIS ===" 
git log --oneline -1
```

---

### 6. NUNCA Declare "Sucesso" Sem Evidência

**Proibido:**

| ❌ NÃO FAÇA | ✅ FAÇA |
|-----------|--------|
| "100% operacional" | "COMPROVADO: SHA bate" |
| "funcionando" | "FALHOU: erro no log" |
| "daemon rodando" | "INCONCLUSIVO: sem evidência" |

---

### 7. Testes Reais, Nunca Simulação

- Se você executa `git pull/reset/fetch` DEPOIS do push, invalidou o teste
- Teste real = push + aguardar daemon + SEM intervir

---

### 8. Separar Claramente: Preparação, Disparo, Espera, Observação

```bash
# FASE 1: PREPARAÇÃO
echo "=== PREPARAÇÃO ===" 
git checkout main
git log --oneline -1

# FASE 2: DISPARO
echo "=== DISPARO ===" 
git commit --allow-empty -m "test: sync"
EXPECTED_SHA=$(git rev-parse HEAD)
git push origin main

# FASE 3: ESPERA (SEM INTERVIR!)
echo "Aguardando 4 minutos..."
sleep 240

# FASE 4: OBSERVAÇÃO
echo "=== OBSERVAÇÃO ===" 
ACTUAL_SHA=$(ssh ubuntu@vm "git -C /home/ubuntu/site-shopvivaliz rev-parse HEAD")
[[ "$ACTUAL_SHA" == "$EXPECTED_SHA" ]] && echo "✅ OK" || echo "❌ FALHOU"
```

---

### 9. Proteja Dados Operacionais

**NUNCA comitte:**

```
storage/orders/
storage/codex-bridge/state.json
storage/orchestrator/queue.json
.agent-heartbeats/
.git-sync.lock
```

---

### 10. Em Caso de Erro: PARAR Imediatamente

```bash
set -Eeuo pipefail  # Faz script falhar automaticamente em erros
git fetch origin    # Se isso falha, próximas linhas NÃO rodam
git merge --ff-only origin/main
```

---

## 📋 CHECKLIST

- [ ] `set -Eeuo pipefail` em scripts
- [ ] Validei código de saída
- [ ] Registrei ANTES e DEPOIS
- [ ] Teste é REAL
- [ ] NÃO usei `git reset --hard`
- [ ] Nenhuma falsa afirmação
- [ ] Status é COMPROVADO/FALHOU/INCONCLUSIVO

---

## 🏗️ NOVA ARQUITETURA: RELEASES IMUTÁVEIS (Ativo desde 2026-07-25)

**Produção migrou de `git merge` vivo para releases imutáveis.**

Estrutura pós-migração:
```
/home/ubuntu/shopvivaliz-deploy/
├── repo/            (clone limpo, somente leitura)
├── releases/        (releases imutáveis)
└── current → releases/20260725-143500-2489d8d9/

/var/lock/shopvivaliz-deploy.lock (flock, impede concorrência)
```

### O Que Mudou Para Agentes

**ANTES (2026-07-24 e antes):**
```bash
git merge --ff-only origin/main  # ❌ Na árvore viva
                                  # ❌ Bloqueia por .env/.cache modificados
                                  # ❌ Sem rollback rápido
```

**DEPOIS (2026-07-25 e depois):**
```bash
/home/ubuntu/shopvivaliz-deploy/repo/scripts/deploy-production.sh
# ✅ Cria nova release limpa
# ✅ Separado de dados runtime
# ✅ Rollback atômico
# ✅ idempotente (roda 2x, mesmo resultado)
```

### Regras para Deploy (Novo)

1. **NUNCA editar dentro:**
   ```
   /home/ubuntu/shopvivaliz-deploy/releases/<ativa>/
   /home/ubuntu/shopvivaliz-deploy/current/
   ```

2. **Dados runtime vão em `/shared/`:**
   ```
   .env, uploads/, logs/, cache/, sessions/
   ```

3. **Deploy é pull-based:**
   - VM faz `git fetch` a cada 2 min (cron)
   - Detecta novo SHA
   - Cria nova release
   - Troca symlink atomicamente
   - **Você não pusheia release**, você pusheia commits

4. **Rollback é manual:**
   ```bash
   sudo /home/ubuntu/shopvivaliz-deploy/repo/scripts/rollback-production.sh
   ```

### Se Tiver Que SSH à VM

```bash
ssh -i "C:\Users\FRED\Downloads\ssh-key-2026-07-04.key" ubuntu@137.131.156.17

# Monitorar deploy (a cada 2 min, cron roda)
tail -f /var/log/shopvivaliz-deploy.log

# Ver release ativa
readlink -f /home/ubuntu/shopvivaliz-deploy/current

# Ver releases disponíveis
ls -la /home/ubuntu/shopvivaliz-deploy/releases/

# Rollback manual
sudo /home/ubuntu/shopvivaliz-deploy/repo/scripts/rollback-production.sh

# Nunca faça:
cd /home/ubuntu/shopvivaliz-deploy/current
vi index.php  # ❌ NUNCA edite release ativa
```

---

**Versão:** 1.1  
**Data Última Atualização:** 2026-07-25  
**Efetivo para:** Todos os agentes IA  
**Status:** ✅ Produção — Arquitetura Imutável Ativa
