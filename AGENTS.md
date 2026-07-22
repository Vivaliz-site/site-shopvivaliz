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
- Para site/layout/checkout/chatbot, teste real exige base de teste local ou staging ANTES de produção
- Teste de página web exige navegação real no navegador com DOM renderizado; `curl`, inspeção de código ou screenshot antigo não bastam

---

### 8. Base de Teste Antes de Produção

**Obrigatório para qualquer mudança que afete páginas públicas, carrinho, checkout, catálogo, chatbot, integrações ou deploy:**

1. Subir a alteração primeiro em base local/staging separada da produção
2. Registrar URL/porta e estado ANTES/DEPOIS
3. Navegar de verdade no navegador pelas telas afetadas
4. Validar home, catálogo, produto, carrinho e checkout quando a loja for impactada
5. Validar zoom negativo e positivo quando houver qualquer impacto visual/responsivo
6. Só promover para produção depois de evidência real
7. Após produção, repetir smoke test no domínio público

**Proibido:** deploy direto em produção sem teste em base de teste.

---

### 9. Separar Claramente: Preparação, Disparo, Espera, Observação

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

### 10. Proteja Dados Operacionais

**NUNCA comitte:**

```
storage/orders/
storage/codex-bridge/state.json
storage/orchestrator/queue.json
.agent-heartbeats/
.git-sync.lock
```

---

### 11. Em Caso de Erro: PARAR Imediatamente

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
- [ ] Testei primeiro em base local/staging
- [ ] Naveguei de verdade no navegador
- [ ] NÃO usei `git reset --hard`
- [ ] Nenhuma falsa afirmação
- [ ] Status é COMPROVADO/FALHOU/INCONCLUSIVO

---

**Versão:** 1.0  
**Data:** 2026-07-15  
**Efetivo para:** Todos os agentes IA
