# 🔐 Sincronizar Secrets em Todos os Ambientes

## 📋 Resumo

Os secrets estão armazenados **SEGURAMENTE no GitHub Actions** e sincronizados automaticamente para cada ambiente.

**Nunca commita `.env.local`** - ele está em `.gitignore` por segurança.

---

## 🪟 Windows (PC Atual)

### Opção 1: Python (Recomendado)
```powershell
python3 scripts/sincronizar_secrets_github.py
python3 scripts/validar_secrets.py
```

### Opção 2: Batch Script
```powershell
.\scripts\ATIVAR-AUTO-SYNC.bat
```

### Resultado:
- ✅ `.env.local` criado
- ✅ Auto-Sync ativo
- ✅ Secrets sincronizados

---

## 🐧 Ubuntu / Linux

### 1. Clone o repositório
```bash
git clone https://github.com/Vivaliz-site/site-shopvivaliz.git
cd site-shopvivaliz
```

### 2. Autentique no GitHub (primeira vez)
```bash
gh auth login
# Selecione: GitHub.com
# Selecione: HTTPS
# Autentique com seu token/senha
```

### 3. Sincronize secrets
```bash
bash scripts/sincronizar_secrets_github.sh
python3 scripts/validar_secrets.py
```

### 4. Ative auto-sync (opcional)
```bash
bash scripts/auto_sync_git.ps1    # ou PowerShell se disponível
# Ou use cron:
crontab -e
# Adicione: */5 * * * * cd /caminho/site-shopvivaliz && bash scripts/auto_sync_git.ps1
```

---

## ☁️ Cloud (AWS, Azure, Google Cloud, Oracle, etc)

### 1. SSH na máquina
```bash
ssh user@seu-cloud-host.com
cd /caminho/site-shopvivaliz
```

### 2. Sincronize secrets
```bash
bash scripts/sincronizar_secrets_github.sh
python3 scripts/validar_secrets.py
```

### 3. Configure auto-sync via Cron
```bash
crontab -e

# Adicionar linha:
*/5 * * * * cd /caminho/site-shopvivaliz && /usr/bin/python3 scripts/auto_sync_git.ps1 >> /var/log/shopvivaliz-sync.log 2>&1
```

### Ou via Systemd Timer
```bash
sudo vim /etc/systemd/system/shopvivaliz-sync.service

[Unit]
Description=ShopVivaliz Auto Sync
After=network.target

[Service]
Type=simple
User=shopvivaliz
WorkingDirectory=/caminho/site-shopvivaliz
ExecStart=/usr/bin/python3 scripts/auto_sync_git.ps1 -Interval 5
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target

# Depois:
sudo systemctl enable shopvivaliz-sync.service
sudo systemctl start shopvivaliz-sync.service
sudo systemctl status shopvivaliz-sync.service
```

---

## ✅ Checklist de Sincronização

Para cada ambiente, execute:

```bash
# 1. Sincronizar secrets
python3 scripts/sincronizar_secrets_github.py  # Windows
bash scripts/sincronizar_secrets_github.sh     # Linux/Mac

# 2. Validar
python3 scripts/validar_secrets.py

# Esperado:
# [SUCCESS] SUCESSO - Todos os secrets obrigatórios estão configurados!
```

---

## 🔍 Verificar Status

### Ver se sincronização está ativa

**Windows:**
```powershell
Get-ScheduledTask | Where-Object { $_.TaskName -like "*ShopVivaliz*" }
```

**Linux/Mac:**
```bash
# Cron:
crontab -l | grep shopvivaliz

# Systemd:
systemctl status shopvivaliz-sync.service
```

**Cloud:**
```bash
# Ver últimas sincronizações:
tail -f /var/log/shopvivaliz-sync.log
```

---

## 🆘 Troubleshooting

### Erro: "GitHub CLI (gh) não está instalado"

**Windows:**
```powershell
choco install gh
```

**Linux/Ubuntu:**
```bash
curl -fsSLo github-cli.deb https://github.com/cli/cli/releases/download/v2.95.0/gh_2.95.0_linux_amd64.deb
sudo dpkg -i github-cli.deb
```

**macOS:**
```bash
brew install gh
```

**Cloud:**
```bash
sudo apt-get update
sudo apt-get install gh
```

### Erro: "Acesso negado ao repositório"

```bash
# Autentique novamente:
gh auth login
gh auth refresh
```

### Erro: "Secrets vazios"

Verifique se os secrets existem no GitHub:
```bash
gh secret list
```

Se faltarem, configure em:
`https://github.com/Vivaliz-site/site-shopvivaliz/settings/secrets/actions`

---

## 📊 Resumo da Arquitetura

```
GitHub Actions (Secrets)
        ↓
    ┌───┴───┬─────────┬──────────┐
    ↓       ↓         ↓          ↓
Windows  Ubuntu    Cloud    macOS
(PC)     (Dev)     (Prod)   (Local)
    ↓       ↓         ↓          ↓
.env.local gerado automaticamente
    ↓       ↓         ↓          ↓
Auto-Sync rodando a cada 5 minutos
    ↓       ↓         ↓          ↓
Git pull/push sincronizado
```

---

## 🔐 Segurança

✅ **O que é seguro:**
- Secrets no GitHub Actions (encrypted)
- `.env.local` gerado localmente (nunca commitado)
- Auto-sync via HTTPS com autenticação

❌ **O que NÃO fazer:**
- ❌ Commitar `.env.local`
- ❌ Expor secrets em logs
- ❌ Compartilhar `.env.local` via email/chat
- ❌ Harcodear secrets no código

---

## 📞 Próximos Passos

1. **Windows:** Execute agora
   ```powershell
   python3 scripts/sincronizar_secrets_github.py
   python3 scripts/validar_secrets.py
   ```

2. **Ubuntu/Cloud:** Execute quando puder
   ```bash
   bash scripts/sincronizar_secrets_github.sh
   python3 scripts/validar_secrets.py
   ```

3. **Verificar:** Todos os ambientes devem mostrar
   ```
   ✅ SUCESSO - Todos os secrets obrigatórios estão configurados!
   ```

---

**Status: ✅ Todos os ambientes prontos para sincronização automática!**
