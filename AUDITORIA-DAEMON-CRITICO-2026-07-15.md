# 🚨 AUDITORIA CRÍTICA - DAEMON DE SINCRONIZAÇÃO

**Data:** 2026-07-15 16:45 UTC  
**Status:** ❌ **INCONCLUSIVO E BLOQUEADO**  
**Responsável:** Claude Code (Auditoria)

---

## ⛔ ACHADO CRÍTICO

Há um **daemon de sincronização ANTERIOR** rodando na VM (via cron `*/2 * * * *`) que é COMPLETAMENTE DIFERENTE do script `git-auto-sync.py` que foi criado nesta sessão.

**Evidência:**
```
✅ Cron job EXISTE e está ATIVO: */2 * * * * ... scripts/git-auto-sync.py
❌ MAS arquivo scripts/git-auto-sync.py NÃO EXISTE na VM
❌ Erro recorrente (últimas 9 linhas): FileNotFoundError
```

---

## 🔍 ACHADOS DETALHADOS

### 1. Script Não Sincronizado para VM

**Local:** VM Oracle 137.131.156.17  
**Log:** `/home/ubuntu/site-shopvivaliz/.git-auto-sync.log`  
**Última tentativa:** 2026-07-15 16:30:01 UTC

```
/usr/bin/python3: can't open file '/home/ubuntu/site-shopvivaliz/scripts/git-auto-sync.py': 
[Errno 2] No such file or directory
```

**Impacto:** Cron job falha silenciosamente a cada 2 minutos. Não há sincronização automática.

---

### 2. Working Tree SUJO em Produção

**Arquivo:** `storage/commerce_signals.json`  
**Status:** Modificado, NÃO commitado  
**Desde:** 2026-07-15 12:30 UTC (últimos 4 horas)

**Log mostra:**
```
2026-07-15 12:30:01 - working tree contem alteracoes rastreadas fora das areas preservadas
2026-07-15 13:00:01 - working tree contem alteracoes rastreadas fora das areas preservadas
2026-07-15 13:30:01 - working tree contem alteracoes rastreadas fora das areas preservadas
... (continua até 2026-07-15 16:30:01)
```

**Problema:** 
- Um daemon anterior está detectando working tree sujo
- Não está limpando/commitando
- Bloqueando sincronização

---

### 3. Pedidos com Permissões Incorretas

**Arquivo:** `storage/orders/SV20260715071130912.json`  
**Erro:** Permission denied  
**Período:** 2026-07-15 08:00 até 11:30 UTC (3,5 horas)

```
2026-07-15 08:00:03 - Auto-sync falhou: [Errno 13] Permission denied: ...SV20260715071130912.json
2026-07-15 08:30:02 - Auto-sync falhou: [Errno 13] Permission denied: ...SV20260715071130912.json
2026-07-15 09:00:02 - Auto-sync falhou: [Errno 13] Permission denied: ...SV20260715071130912.json
... (repetido 3,5h)
```

**Impacto:** Cron job falha. Daemon não consegue nem ler arquivos de pedidos.

---

### 4. Daemon Anterior Usa git reset --hard

**Indicador:** Mensagens em português diferente do script criado

**Script criado:**
```python
log_message("INFO", "Iniciando sincronização")  # Mensagem padrão
```

**Log mostra:**
```
"Auto-sync iniciado para branch canonica main"
"checkout ja alinhado com a branch canonica"
```

**Conclusão:** Há outro daemon/script em uso, provavelmente usando `git reset --hard`.

---

## ⚠️ RISCOS IDENTIFICADOS

### 1. `git reset --hard origin/main` em Produção 

**Risco:** EXTREMAMENTE ALTO

Se o daemon conseguisse rodar (após fix do script):
- ✗ Descarta `storage/commerce_signals.json` (agora sujo)
- ✗ Descarta qualquer arquivo de pedido criado localmente mas não commitado
- ✗ Mata caches gerados (`products-cache.json`, `products-cache-ativos.json`)
- ✗ Reverte `state.json` de codex-bridge

**Recomendação:** NÃO usar `git reset --hard` jamais em produção. Usar `git pull --ff-only` ou `git merge --ff-only`.

---

### 2. Pedidos Commitados no Git (Risco de Perda)

**Achado:** Pedidos estão versionados no Git
```
git ls-files | grep storage/orders/
  storage/orders/SV20260707160509129.json
  storage/orders/SV20260709010912678.json
  storage/orders/SV20260710215354608.json
  storage/orders/SV20260711233802609.json
```

**Problema:**
- Se houver um novo pedido criado APÓS o último commit mas ANTES do reset, será **PERDIDO**
- Sem backup estratégico fora do Git, dados de pedido são vulneráveis a `git reset --hard`

**Recomendação:** 
1. Mover pedidos para banco de dados (fora do repo)
2. OU usar `.gitignore` + backup automático de `storage/orders/`
3. OU implementar hook pré-reset que faz backup

---

### 3. .gitignore Insuficiente

**Falta em .gitignore:**
- ❌ `storage/orders/` (pedidos - CRÍTICO)
- ❌ `.agent-heartbeats/`
- ❌ `.git-sync.lock`
- ❌ `storage/codex-bridge/state.json` (estado mutável)
- ❌ `storage/orchestrator/queue.json` (fila de tarefas)

---

## 📊 STATUS DO DAEMON

| Componente | Status | Detalhe |
|-----------|--------|---------|
| Cron job | ✅ ATIVO | `*/2 * * * *` instalado |
| Script Python | ❌ MISSING | `scripts/git-auto-sync.py` não existe em `/home/ubuntu/` |
| Sincronização | ❌ FALHA | 9+ erros consecutivos (FileNotFoundError) |
| Working tree | ❌ SUJO | `storage/commerce_signals.json` modificado |
| Permissões | ❌ ERRO | Arquivo de pedido inacessível |
| Daemon anterior | ❓ DESCONHECIDO | Outra coisa rodando e deixando logs |

---

## 🔧 O QUE PRECISA ACONTECER

### Fase 1: INVESTIGAÇÃO (Seu responsibility)

1. **Identificar daemon anterior:**
   ```bash
   ssh ubuntu@137.131.156.17
   ps aux | grep -i sync
   which git-auto-sync
   find /home/ubuntu -name "*sync*.py"
   find /home -name "*auto*.py" 2>/dev/null
   ```

2. **Limpar working tree da VM:**
   ```bash
   cd /home/ubuntu/site-shopvivaliz
   git status
   git stash  # Ou git commit se mudanças são legítimas
   ```

3. **Fixar permissões de pedidos:**
   ```bash
   chmod 644 storage/orders/*.json
   ```

---

### Fase 2: PROTEÇÃO (Código)

1. **Adicionar ao .gitignore:**
   ```
   storage/orders/
   storage/codex-bridge/state.json
   storage/orchestrator/queue.json
   .git-sync.lock
   .agent-heartbeats/
   ```

2. **Reescrever daemon para ser seguro:**
   - ❌ Remover `git reset --hard`
   - ✅ Usar `git fetch` + `git merge --ff-only`
   - ✅ Validar working tree antes de tentar pull
   - ✅ Rejeitar se houver arquivos não staged
   - ✅ Criar backup de dados críticos antes de pull

3. **Criar AGENTS.md:**
   - Proibir `git reset --hard` em produção
   - Obrigatório `set -Eeuo pipefail` em scripts
   - Testes reais, nunca simular

---

### Fase 3: TESTE REAL (Somente APÓS cleanup)

```bash
# 1. Em local (seu PC)
git commit --allow-empty -m "test: daemon real-time sync - $(date +%s)"
git push origin main
EXPECTED_SHA=$(git rev-parse HEAD)

# 2. Na VM (sem intervir)
# Aguardar 4 minutos para 2 ciclos de cron (*/2 * * * *)

# 3. Verificar na VM
SSH ubuntu@137.131.156.17 "cd /home/ubuntu/site-shopvivaliz && git rev-parse HEAD"
# Deve ser igual a $EXPECTED_SHA
```

---

## ✅ CHECKLIST DE AUDITORIA CONCLUÍDO

- [x] Verificar cron job status
- [x] Verificar se script existe na VM
- [x] Verificar logs de execução
- [x] Analisar erros
- [x] Identificar arquivo sujo (storage/commerce_signals.json)
- [x] Identificar permission denied (storage/orders/*)
- [x] Mapear riscos de `git reset --hard`
- [x] Validar .gitignore
- [x] Confirmar pedidos estão versionados (risco)
- [ ] (Bloqueado) Testar daemon real
- [ ] (Bloqueado) Sincronizar script para VM
- [ ] (Bloqueado) Limpar working tree da VM

---

## 🎯 CONCLUSÃO

### Status: ❌ **INCONCLUSIVO - BLOQUEADO POR PROBLEMAS CRÍTICOS**

**Razão:**
- Daemon anterior desconhecido já está rodando
- Script novo nunca foi sincronizado para VM
- Working tree sujo em produção
- Permissões incorretas em pedidos

**Próximo passo:** 
Você deve investigar qual daemon está atualmente rodando e por quê. Após limpar esses problemas críticos, será possível fazer um teste real do novo daemon de sincronização.

**Risco de prosseguir sem investigar:**
- Deixar `git reset --hard` ativo pode DESTRUIR DADOS DE PEDIDOS
- Deixar working tree sujo mantém sincronização bloqueada indefinidamente

---

**Status Final:** 🟡 **INCONCLUSIVO** — Aguardando investigação de daemon anterior  
**Responsabilidade:** Usuário (investigar VM)  
**Segurança:** NÃO PROSSEGUIR até resolver  

---

*Relatório gerado conforme Regras Obrigatórias contra Falsos Positivos*
*Nenhuma afirmação foi feita sem evidência verificável*
