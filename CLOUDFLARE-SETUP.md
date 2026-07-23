# 🚀 Setup Final - Cloudflare Tunnel + RCE Server

## ✅ Parte 1: Cloudflared Instalado

```
✅ cloudflared version 2026.7.3
```

## 🔐 Parte 2: Autenticação Cloudflare (MANUAL)

### **Passo 1: Abra PowerShell**

```powershell
cd c:\site-shopvivaliz
.\cloudflared.exe login
```

### **Passo 2: Navegador Abre Automaticamente**

- Você vai ver tela de login Cloudflare
- **Faça login** com sua conta (ou crie uma gratuita)
- **Selecione domínio** ou use default `*.trycloudflare.com`
- **Clique "Authorize"**

### **Passo 3: Credenciais Salvas**

Cloudflared vai salvar token em: `C:\Users\FRED\.cloudflared\cert.pem`

---

## 🌐 Parte 3: Iniciar Tunnel

### **Um comando:**

```powershell
cd c:\site-shopvivaliz
.\cloudflared.exe tunnel --config cloudflare-tunnel.yml run site-shopvivaliz-rce
```

### **Você verá:**

```
2026-07-23 19:55:12.123Z INF Tunnel credentials written to...
2026-07-23 19:55:13.456Z INF Cloudflared is running at:
https://rce-shopvivaliz.trycloudflare.com
```

**Copie essa URL HTTPS** — é sua chave de acesso remoto! 🔑

---

## 📱 Parte 4: Usar do iPhone Agora

### **Via Curl (Termius ou Terminal)**

```bash
curl -X POST https://rce-shopvivaliz.trycloudflare.com/execute \
  -H "Authorization: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih" \
  -H "Content-Type: application/json" \
  -d '{"cmd": "git status", "timeout": 30}'
```

### **Via Shortcuts**

1. App **Shortcuts** → novo atalho
2. **Web Request**:
   ```
   POST https://rce-shopvivaliz.trycloudflare.com/execute
   
   Headers:
   Authorization: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih
   Content-Type: application/json
   
   Body: {"cmd":"dir","timeout":30}
   ```
3. **Show Result**

---

## 📋 Checklist

Após os 4 passos, você tem:

- [ ] `cloudflared.exe` instalado ✅
- [ ] Autenticado com Cloudflare (fazer login manualmente)
- [ ] Tunnel rodando e respondendo
- [ ] URL HTTPS gerada
- [ ] RCE Server em background rodando
- [ ] Testado do iPhone via 4G

---

## 🚀 Quick Start (Ordem Exata)

```powershell
# Terminal 1 - Iniciar RCE Server
cd c:\site-shopvivaliz
.\start-rce-bg.ps1

# Aguardar "✅ RCE Server respondendo"
```

```powershell
# Terminal 2 - Iniciar Cloudflare Tunnel
cd c:\site-shopvivaliz
.\cloudflared.exe tunnel --config cloudflare-tunnel.yml run site-shopvivaliz-rce

# Aguardar "https://rce-shopvivaliz.trycloudflare.com"
# Copiar URL
```

```bash
# iPhone - Testar
curl -X POST https://rce-shopvivaliz.trycloudflare.com/execute \
  -H "Authorization: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih" \
  -d '{"cmd": "dir", "timeout": 30}'
```

**Pronto!** 🎉

---

## ⚠️ Lembrete de Segurança

1. **Token está em plaintext no chat** — mude depois:
   ```powershell
   # Gerar novo token
   -join ((48..57) + (65..90) + (97..122) | Get-Random -Count 32 | ForEach-Object {[char]$_})
   ```

2. **Salve a URL em local seguro** (é sua chave mestra)

3. **Logs registram tudo** em `logs/rce-server.log`

---

## 🆘 Se der erro

### "Cloudflare login failed"
```powershell
# Tentar novamente
.\cloudflared.exe login

# Se não abrir browser
# → Abrir manualmente: https://dash.cloudflare.com/login
```

### "Tunnel not responding"
```powershell
# Reiniciar
Ctrl+C

# Verificar que RCE Server está rodando
$token = 'hBu-3gs3meFOp82AnXLzljmIvNaf-7ih'
curl -X GET http://127.0.0.1:5557/status `
  -H "Authorization: Bearer $token"
```

### "Connection refused do iPhone"
- Verificar que tunnel está rodando (Terminal 2 ativo)
- Verificar URL copiada corretamente
- Testar no PC: `curl https://URL/status`

---

## 📚 Próximos Passos (Opcional)

1. **Mude o Token** (segurança)
2. **Configure Autostart** (inicia automaticamente)
3. **Use domínio próprio** (URL permanente)
4. **Configure Rate Limiting** (proteção)

Ver `CLOUDFLARE-TUNNEL.md` para detalhes.

---

**Pronto para começar? Siga os 4 passos acima!** 🚀
