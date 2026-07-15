# Setup — Oracle VM SSH Deploy

> Como configurar deploy automático via SSH para Oracle VM

---

## ⚡ Quick Setup (5 min)

### 1. Gerar Chave SSH (ou usar existente)

Se já tem uma chave SSH pública na VM:
```bash
# Ver chave pública autorizada na VM
ssh ubuntu@137.131.156.17 "cat ~/.ssh/authorized_keys"
```

Se NÃO tem:
```bash
# Gerar nova chave (sem passphrase)
ssh-keygen -t rsa -b 4096 -f ~/.ssh/shopvivaliz-deploy -N ""
cat ~/.ssh/shopvivaliz-deploy.pub
```

Copie a chave pública e adicione na VM:
```bash
ssh ubuntu@137.131.156.17 "echo '$(cat ~/.ssh/shopvivaliz-deploy.pub)' >> ~/.ssh/authorized_keys"
```

### 2. Adicionar Secret no GitHub

1. Vá para: **GitHub → Settings → Secrets and variables → Actions → New repository secret**
2. **Name:** `ORACLE_VM_SSH_KEY`
3. **Value:** Copie o conteúdo da **chave privada**:
   ```bash
   cat ~/.ssh/shopvivaliz-deploy
   ```
4. Clique em **Add secret**

### 3. Verificar Conexão

```bash
# Teste SSH (com a chave privada)
ssh -i ~/.ssh/shopvivaliz-deploy ubuntu@137.131.156.17 "echo 'SSH works!'"
```

Se funcionar, está pronto!

---

## 📋 O Que Acontece Agora

```
git push origin main
  ↓
GitHub Actions → master-production-pipeline.yml
  ├─ Validate (lint, CSS checks) ✅
  ├─ Test Real (smoke tests) ✅
  ├─ Deploy ← **SSH para VM Oracle**
  │   └─ git-auto-sync.py executa
  │   └─ Site sincronizado
  └─ Monitor (health checks) ✅

Total: ~2 minutos do push para site atualizado
```

---

## 🔐 Security Notes

- Chave privada nunca sai de `secrets.ORACLE_VM_SSH_KEY`
- SSH usa host key checking desabilitado (config GitHub Actions)
- Apenas deploy automático, sem acesso a outros comandos
- Conexão SSH encriptada

---

## 🚨 Troubleshooting

### "SSH: Permission denied"

```bash
# Verificar se chave está autorizada na VM
ssh ubuntu@137.131.156.17 "grep shopvivaliz ~/.ssh/authorized_keys"

# Se não aparecer, adicione:
ssh ubuntu@137.131.156.17 "echo 'PUBLICKEY' >> ~/.ssh/authorized_keys"
```

### "Deploy workflow failed"

1. Verifique logs: GitHub Actions > master-production-pipeline > Deploy job
2. Comum: chave SSH expirou ou `git-auto-sync.py` falhou na VM
3. Solução: Rodar manualmente na VM:
   ```bash
   ssh ubuntu@137.131.156.17 "cd /home/ubuntu/site-shopvivaliz && python3 git-auto-sync.py"
   ```

### "Timeout waiting for sync"

- Aumentar timeout em `master-production-pipeline.yml`: `timeout-minutes: 5`
- Ou SSH para VM e debugar:
  ```bash
  ssh ubuntu@137.131.156.17
  tail -100 /var/log/git-auto-sync.log
  ```

---

## ✅ Verificar Setup

Após configurar, faça um test push:

```bash
# Edite um arquivo simples
echo "<!-- test: $(date) -->" >> index.html

# Commit e push
git add index.html
git commit -m "test: verify auto-deploy"
git push origin main

# Monitorar em GitHub
# → Actions → Master Production Pipeline
# → Deve ir validate → test → deploy → monitor
# → ~2 minutos para site estar atualizado
```

---

**Setup completo = Deploys automáticos 24/7 sem esperar cron! ✅**
