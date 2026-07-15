# 🔄 ATIVAR GIT AUTO-SYNC DAEMON NA VM ORACLE

**Data:** 2026-07-15  
**Responsável:** Você (via SSH na VM)

---

## ⚠️ PROBLEMA

O sync daemon NÃO está rodando na VM Oracle.

**Sintoma:** Commits no GitHub não aparecem em produção automaticamente

---

## ✅ SOLUÇÃO (5 minutos)

### 1️⃣ Conectar à VM Oracle via SSH

```bash
ssh -i ~/.ssh/ubuntu_key ubuntu@137.131.156.17
```

Ou se não tiver chave SSH:

```bash
ssh ubuntu@137.131.156.17
```

---

### 2️⃣ Instalar Git Auto-Sync

Na VM, execute:

```bash
cd /home/ubuntu/site-shopvivaliz
bash scripts/install-git-sync-cron.sh
```

**O script fará:**
- ✅ Verificar se Python3 está instalado
- ✅ Testar o script git-auto-sync.py
- ✅ Criar diretório /var/log/shopvivaliz
- ✅ Instalar cron job (*/30 * * * *)

---

### 3️⃣ Verificar Instalação

Na VM, verifique se o cron job foi instalado:

```bash
crontab -l | grep git-auto-sync
```

**Esperado:**
```
*/30 * * * * /usr/bin/python3 /home/ubuntu/site-shopvivaliz/scripts/git-auto-sync.py >> /var/log/shopvivaliz/cron.log 2>&1
```

---

### 4️⃣ Testar Manual

Para testar o sync agora (sem esperar 30 minutos):

```bash
python3 /home/ubuntu/site-shopvivaliz/scripts/git-auto-sync.py
```

**Esperado:**
```
[2026-07-15 15:50:00] [INFO] ========== Git Auto-Sync ==========
[2026-07-15 15:50:00] [INFO] Iniciando sincronização
[2026-07-15 15:50:02] [INFO] git fetch OK
[2026-07-15 15:50:03] [INFO] git reset OK
[2026-07-15 15:50:03] [INFO] Sincronização concluída com sucesso
[2026-07-15 15:50:03] [INFO] ========== Fim ==========
```

---

### 5️⃣ Ver Logs

Para verificar os logs:

```bash
# Logs diários
tail -f /var/log/shopvivaliz/git-auto-sync-*.log

# Logs do cron
tail -f /var/log/shopvivaliz/cron.log

# Ver tudo
ls -lah /var/log/shopvivaliz/
```

---

## 🔄 O QUE O SYNC DAEMON FAZ

**A cada 30 minutos:**

```
1. Verificar se lock está disponível (evita múltiplas execuções)
2. Fazer git fetch origin (puxar mudanças)
3. git reset --hard origin/main (atualizar código)
4. Registrar commit sincronizado
5. Liberar lock
```

**Resultado:** Código no GitHub aparece em produção a cada 30 minutos

---

## 🎯 FLUXO AGORA FUNCIONANDO

```
Você faz push no GitHub
        ↓ (imediato)
Deploy workflow executa
        ↓ (2-5 min)
Deploy conclui
        ↓ (até 30 min)
Cron job roda (git fetch + reset)
        ↓
VM Oracle atualiza código
        ↓
Site em produção sincronizado ✅
```

---

## 🛠️ COMANDOS ÚTEIS

### Instalar (primeira vez)
```bash
bash /home/ubuntu/site-shopvivaliz/scripts/install-git-sync-cron.sh
```

### Testar manual
```bash
python3 /home/ubuntu/site-shopvivaliz/scripts/git-auto-sync.py
```

### Ver próximas execuções
```bash
crontab -l | grep git-auto-sync
```

### Ver logs
```bash
tail -f /var/log/shopvivaliz/git-auto-sync-*.log
```

### Remover cron job
```bash
crontab -e
# Deletar a linha com git-auto-sync
```

### Sincronizar agora (manual)
```bash
cd /home/ubuntu/site-shopvivaliz
git fetch origin
git reset --hard origin/main
```

---

## 🔐 SEGURANÇA

- ✅ Lock file previne múltiplas execuções
- ✅ Stale lock detection (remove locks com >10min)
- ✅ Logs registram tudo (auditoria)
- ✅ SSH key required para SSH
- ✅ Cron executa como user `ubuntu`

---

## 📊 VERIFICAÇÃO

Para confirmar que está funcionando:

```bash
# 1. Fazer um commit teste no GitHub
git commit --allow-empty -m "test: sync daemon check"
git push origin main

# 2. Esperar até 30 minutos (ou executar manual)
python3 /home/ubuntu/site-shopvivaliz/scripts/git-auto-sync.py

# 3. Verificar se commit apareceu na VM
git log --oneline -1

# Esperado: O commit teste deve aparecer
```

---

## ⏰ AGENDAMENTO

**Cron job instalado:**
```
*/30 * * * * python3 git-auto-sync.py
```

**Horários de execução:**
```
00:00, 00:30, 01:00, 01:30, ... 23:30
(A cada 30 minutos, 24/7)
```

---

## 📞 SUPORTE

Se algo der errado:

1. **Cron job não instalado:**
   ```bash
   crontab -e
   # Adicionar manualmente:
   */30 * * * * /usr/bin/python3 /home/ubuntu/site-shopvivaliz/scripts/git-auto-sync.py >> /var/log/shopvivaliz/cron.log 2>&1
   ```

2. **Script falha:**
   ```bash
   python3 /home/ubuntu/site-shopvivaliz/scripts/git-auto-sync.py
   # Ver erro e corrigir
   ```

3. **Logs não aparecem:**
   ```bash
   mkdir -p /var/log/shopvivaliz
   chmod 777 /var/log/shopvivaliz
   ```

---

## 🎯 RESULTADO FINAL

**Antes:**
- ❌ Código no GitHub não sincroniza automaticamente
- ❌ Precisa fazer deploy manual

**Depois:**
- ✅ A cada 30 min: git fetch + reset
- ✅ Site sempre sincronizado
- ✅ Deploy automático funciona
- ✅ Logs registram tudo

---

**Status:** 🟡 AGUARDANDO EXECUÇÃO NA VM  
**Tempo:** 5 minutos  
**Próximo:** SSH para VM e instalar cron job

