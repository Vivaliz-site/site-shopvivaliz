# 🌐 Cloudflare Tunnel - RCE Server Seguro via 4G

**Melhor solução: Acesso remoto total via HTTPS criptografado, sem expor seu IP.**

---

## 🎯 Como Funciona

```
iPhone 4G (qualquer lugar)
    ↓ HTTPS criptografado
Cloudflare Network (protegido)
    ↓ Túnel criptografado
Seu PC (Windows)
    ↓
RCE Server (acesso total)
```

---

## ⚡ Vantagens

✅ **Criptografado** — HTTPS 256-bit  
✅ **Gratuito** — Cloudflare free tier  
✅ **Rápido** — Rede global do Cloudflare  
✅ **Seguro** — IP do PC nunca exposto  
✅ **DDoS Protection** — Cloudflare protege  
✅ **Funciona em qualquer 4G** — WiFi, 4G LTE, 5G  
✅ **Acesso Total** — RCE completo  

---

## 🚀 Setup Rápido (5 minutos)

### **1. Baixar Cloudflared**

```powershell
cd c:\site-shopvivaliz
curl -L --output cloudflared.exe https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe
```

### **2. Autenticar com Cloudflare**

```powershell
.\cloudflared.exe login
```

Vai abrir browser → **Selecione domínio** → **Authorize**

### **3. Iniciar Tunnel**

```powershell
.\start-cloudflare-tunnel.ps1
```

**Você verá:**
```
[2026-07-23 19:55:12] Tunnel credentials written to...
[2026-07-23 19:55:13] Cloudflared is running at: https://rce-shopvivaliz.trycloudflare.com
✅ Acesso remoto ativo!
```

### **4. Copiar URL Pública**

```
https://rce-shopvivaliz.trycloudflare.com
```

**Essa é sua URL de acesso remoto!** 🎉

---

## 📱 Usar do iPhone

### **Via Shortcuts (mais fácil)**

1. Abrir **Shortcuts**
2. Criar novo atalho
3. Adicionar **Web Request**:
   ```
   Method: POST
   URL: https://rce-shopvivaliz.trycloudflare.com/execute
   
   Headers:
   - Authorization: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih
   - Content-Type: application/json
   
   Body (Text):
   {"cmd":"[SEU_COMANDO]","timeout":30}
   ```
4. Adicionar ação **Show Result**

### **Via Curl (Terminal/Termius)**

```bash
curl -X POST https://rce-shopvivaliz.trycloudflare.com/execute \
  -H "Authorization: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih" \
  -H "Content-Type: application/json" \
  -d '{"cmd": "dir", "timeout": 30}'
```

### **Via Python**

```python
import requests

TOKEN = "hBu-3gs3meFOp82AnXLzljmIvNaf-7ih"
URL = "https://rce-shopvivaliz.trycloudflare.com"

response = requests.post(
    f"{URL}/execute",
    headers={"Authorization": f"Bearer {TOKEN}"},
    json={"cmd": "git status", "timeout": 30}
)

print(response.json())
```

---

## 🎯 Exemplos Prácticos

### **Ver Status do Git**
```bash
curl -X POST https://rce.cloudflare-tunnel.com/execute \
  -H "Authorization: Bearer TOKEN" \
  -d '{"cmd": "git log --oneline -5", "timeout": 30}'
```

### **Fazer Commit Automático**
```bash
curl -X POST https://rce.cloudflare-tunnel.com/execute \
  -H "Authorization: Bearer TOKEN" \
  -d '{"cmd": "git add . && git commit -m \"auto: sync\" && git push", "timeout": 60}'
```

### **Abrir Navegador**
```bash
curl -X POST https://rce.cloudflare-tunnel.com/open-browser \
  -H "Authorization: Bearer TOKEN" \
  -d '{"url": "https://github.com"}'
```

### **Executar Python Script**
```bash
curl -X POST https://rce.cloudflare-tunnel.com/execute \
  -H "Authorization: Bearer TOKEN" \
  -d '{"cmd": "python scripts/my-script.py", "timeout": 60}'
```

---

## 🔐 Segurança

| Aspecto | Como Funciona |
|---------|--------------|
| **Criptografia** | TLS 1.3 (256-bit) |
| **IP Exposto** | ❌ Não (Cloudflare como intermediário) |
| **Autenticação** | ✅ Token Bearer obrigatório |
| **Man-in-the-middle** | ❌ Impossível (HTTPS) |
| **DDoS** | ✅ Cloudflare protege |
| **Comandos** | ✅ Qualquer comando (RCE total) |

---

## 🛡️ Proteções Extras

### **Mude o Token**

O token `hBu-3gs3meFOp82AnXLzljmIvNaf-7ih` está comprometido (no chat).

**Gerar novo token seguro:**
```powershell
$newToken = -join ((48..57) + (65..90) + (97..122) | Get-Random -Count 32 | ForEach-Object {[char]$_})
Write-Host "🔑 Novo token: $newToken"
```

**Atualizar em:**
1. `rce-command-server.js` (linha `const TOKEN = ...`)
2. Todas as requisições do iPhone

### **Firewall do Windows**

Cloudflare Tunnel não expõe porta, mas proteja por segurança:

```powershell
# Bloquear qualquer acesso direto à porta 5557
netsh advfirewall firewall add rule name="Block RCE" dir=in action=block protocol=tcp localport=5557
```

### **Logs Monitorados**

```powershell
# Ver logs em tempo real
Get-Content logs/rce-server.log -Tail 30 -Wait
```

---

## 🚀 Autostart (Inicia Automaticamente)

### **Option 1: Adicionar Tunnel ao Windows Startup**

```powershell
# Criar atalho no Startup
$source = "C:\site-shopvivaliz\start-cloudflare-tunnel.ps1"
$target = "C:\Users\$env:USERNAME\AppData\Roaming\Microsoft\Windows\Start Menu\Programs\Startup\RCE-Tunnel.lnk"

$shell = New-Object -ComObject WScript.Shell
$link = $shell.CreateShortcut($target)
$link.TargetPath = "powershell.exe"
$link.Arguments = "-NoProfile -ExecutionPolicy Bypass -File `"$source`""
$link.Save()

Write-Host "✅ Tunnel adicionado ao Startup"
```

### **Option 2: Task Scheduler**

```powershell
$action = New-ScheduledTaskAction `
    -Execute "powershell.exe" `
    -Argument "-NoProfile -ExecutionPolicy Bypass -File c:\site-shopvivaliz\start-cloudflare-tunnel.ps1"

$trigger = New-ScheduledTaskTrigger -AtLogOn

Register-ScheduledTask `
    -TaskName "Start-Cloudflare-Tunnel" `
    -Action $action `
    -Trigger $trigger `
    -RunLevel Highest -Force

Write-Host "✅ Cloudflare Tunnel adicionado ao Task Scheduler"
```

---

## 🔗 Endpoints Disponíveis

| Endpoint | Exemplo |
|----------|---------|
| `POST /execute` | `{"cmd": "dir", "timeout": 30}` |
| `POST /open-terminal` | `{"type": "powershell"}` |
| `POST /open-browser` | `{"url": "https://google.com"}` |
| `POST /open-app` | `{"app": "C:\\Program Files\\VSCode\\Code.exe"}` |
| `GET /status` | Ver status do server |

---

## 🚨 Troubleshooting

### "Cloudflared not found"
```powershell
curl -L --output cloudflared.exe https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe
```

### "Unauthorized" no iPhone
- Verificar token
- Certificar que é `Bearer TOKEN`, não só `TOKEN`

### "Tunnel not connecting"
```powershell
# Reiniciar
Get-Process cloudflared | Stop-Process
Start-Sleep 2
.\start-cloudflare-tunnel.ps1
```

### "Command timeout"
- Aumentar `timeout` no request (max 300s)

---

## 📊 Monitoramento

### **Ver Conexões Ativas**
```powershell
Get-Process cloudflared
Get-Process node | Where-Object CommandLine -like "*rce*"
```

### **Ver Últimos Comandos Executados**
```powershell
Get-Content logs/rce-server.log -Tail 50
```

### **Status do Tunnel**
```bash
curl -X GET https://rce-shopvivaliz.trycloudflare.com/status \
  -H "Authorization: Bearer TOKEN"
```

---

## 🎯 Checklist de Setup

- [ ] Cloudflared baixado
- [ ] Autenticado com Cloudflare
- [ ] Tunnel iniciado e respondendo
- [ ] URL HTTPS copiada
- [ ] RCE Server rodando
- [ ] Testado do iPhone via 4G
- [ ] Token mudado (opcional mas recomendado)
- [ ] Logs sendo monitorados
- [ ] Autostart configurado (opcional)

---

## 💡 Dicas

1. **URL Permanente**: Se quiser URL fixa, use domínio próprio no Cloudflare
2. **Múltiplas Máquinas**: Pode ter múltiplos tunnels para diferentes PCs
3. **Histórico**: Logs em `logs/rce-server.log` registram tudo
4. **Offline**: Se Cloudflare cair, tunnel encerra (normal, raramente)

---

**Pronto! Você tem acesso remoto seguro, criptografado e total via 4G de qualquer lugar do mundo.** 🌍🔒
