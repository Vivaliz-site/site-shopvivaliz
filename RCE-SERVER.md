# 🔴 RCE Server - Controle Remoto Completo do PC

**⚠️ AVISO: Este servidor executa QUALQUER comando sem restrições. Use apenas em rede local privada.**

---

## 🚀 Iniciar

### No PC (PowerShell)

```powershell
cd c:\site-shopvivaliz
.\start-rce-server.ps1
```

### Com IP customizado (para iPhone)

```powershell
# 1. Descobrir seu IP local:
ipconfig | findstr IPv4

# 2. Iniciar no IP encontrado (exemplo 192.168.1.100):
.\start-rce-server.ps1 -Ip "192.168.1.100" -Port 5557
```

### Sem script (direto com Node):

```bash
node rce-command-server.js
```

---

## 📱 Usar do iPhone

### Via Shortcuts (mais fácil)

1. Abrir app **Shortcuts** no iPhone
2. Criar novo atalho
3. Adicionar ação **Ask for Text** → salvar em variável `command`
4. Adicionar ação **Web Request**:
   ```
   Method: POST
   URL: http://192.168.1.100:5557/execute
   
   Headers:
   - Authorization: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih
   - Content-Type: application/json
   
   Body (Text):
   {"cmd":"[seu_comando_aqui]","timeout":30}
   ```
5. Substituir `[seu_comando_aqui]` pela variável capturada
6. Adicionar ação **Show Result** para ver output

### Via Curl (Terminal ou Termius)

```bash
# Comando simples
curl -X POST http://192.168.1.100:5557/execute \
  -H "Authorization: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih" \
  -H "Content-Type: application/json" \
  -d '{"cmd": "dir", "timeout": 30}'

# Com timeout maior
curl -X POST http://192.168.1.100:5557/execute \
  -H "Authorization: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih" \
  -d '{"cmd": "git status", "timeout": 60}'

# Executar PowerShell
curl -X POST http://192.168.1.100:5557/execute \
  -H "Authorization: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih" \
  -d '{"cmd": "Get-Process", "timeout": 30, "shell": "powershell"}'
```

---

## 🎯 Endpoints Disponíveis

### 1. POST /execute — Executar Comando

```json
{
  "cmd": "dir",
  "timeout": 30,
  "shell": "cmd"
}
```

**Respostas:**
```json
{
  "status": "success",
  "output": "...",
  "timestamp": "2026-07-23T..."
}
```

**Exemplos:**
```bash
# Git
{"cmd": "git status"}
{"cmd": "git log --oneline -5"}
{"cmd": "git commit -m 'test'"}

# NPM
{"cmd": "npm install"}
{"cmd": "npm run dev"}

# Python
{"cmd": "python script.py"}

# System
{"cmd": "tasklist"}
{"cmd": "systeminfo"}

# PowerShell
{"cmd": "Get-Process", "shell": "powershell"}
```

---

### 2. POST /open-terminal — Abrir Terminal

```json
{
  "type": "cmd"
}
```

**Tipos:**
- `"cmd"` — Command Prompt
- `"powershell"` — PowerShell

**Exemplo:**
```bash
curl -X POST http://192.168.1.100:5557/open-terminal \
  -H "Authorization: Bearer TOKEN" \
  -d '{"type": "powershell"}'
```

---

### 3. POST /open-browser — Abrir Navegador

```json
{
  "url": "https://www.google.com"
}
```

**Exemplo:**
```bash
curl -X POST http://192.168.1.100:5557/open-browser \
  -H "Authorization: Bearer TOKEN" \
  -d '{"url": "https://github.com"}'
```

---

### 4. POST /open-app — Abrir Aplicativo

```json
{
  "app": "C:\\Program Files\\VSCode\\Code.exe"
}
```

**Exemplos comuns:**
```json
{"app": "C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe"}
{"app": "C:\\Windows\\System32\\notepad.exe"}
{"app": "C:\\Users\\FRED\\AppData\\Local\\Microsoft\\VSCode\\bin\\code.cmd"}
```

---

### 5. GET /status — Status do Servidor

```bash
curl -X GET http://192.168.1.100:5557/status \
  -H "Authorization: Bearer TOKEN"
```

Retorna lista de endpoints e status RCE.

---

## 📊 Exemplos Práticos

### Automação de GitHub

```bash
# Ver status do repo
curl -X POST http://IP:5557/execute \
  -H "Authorization: Bearer TOKEN" \
  -d '{"cmd": "git status", "timeout": 30}'

# Fazer commit automático
curl -X POST http://IP:5557/execute \
  -H "Authorization: Bearer TOKEN" \
  -d '{"cmd": "git add . && git commit -m auto:sync && git push", "timeout": 60}'
```

### Monitoramento do Sistema

```bash
# Processos rodando
curl -X POST http://IP:5557/execute \
  -H "Authorization: Bearer TOKEN" \
  -d '{"cmd": "tasklist /v", "timeout": 30}'

# Informações do sistema
curl -X POST http://IP:5557/execute \
  -H "Authorization: Bearer TOKEN" \
  -d '{"cmd": "systeminfo", "timeout": 30}'

# Espaço em disco
curl -X POST http://IP:5557/execute \
  -H "Authorization: Bearer TOKEN" \
  -d '{"cmd": "dir C:\\", "timeout": 30}'
```

### Deploy Automatizado

```bash
# Deploy com npm
curl -X POST http://IP:5557/execute \
  -H "Authorization: Bearer TOKEN" \
  -d '{"cmd": "cd c:\\site-shopvivaliz && npm run deploy", "timeout": 120}'

# PHP lint
curl -X POST http://IP:5557/execute \
  -H "Authorization: Bearer TOKEN" \
  -d '{"cmd": "php -l includes/header.php", "timeout": 30}'
```

---

## 🔐 Segurança

| Medida | Status | Notas |
|--------|--------|-------|
| Autenticação | ✅ | Token Bearer obrigatório |
| HTTPS | ❌ | HTTP apenas (risco) |
| Validação cmd | ❌ | Nenhuma — RCE total |
| Rate limiting | ⚠️ | 0.5s entre reqs (mínimo) |
| Timeout | ✅ | Max 5 minutos por comando |
| Payload size | ✅ | Max 50KB |
| Output size | ✅ | Max 10MB |
| Logging | ✅ | Tudo em `logs/rce-server.log` |

---

## 🚨 Riscos Críticos

### 1. Se o token vazar
```
Qualquer pessoa com acesso à rede:
❌ Pode deletar todos os seus arquivos
❌ Pode instalar malware
❌ Pode roubar dados
❌ Pode desabilitar antivírus
❌ Pode usar seu PC como botnet
```

### 2. Se a porta ficar pública
```
Qualquer pessoa na internet:
❌ Acesso RCE total ao PC
❌ Seu PC vira servidor de ataques
❌ Pode ser processado por lei
```

### 3. Rede WiFi compartilhada
```
Hóspedes / vizinhos:
❌ Veem tráfego HTTP em plaintext
❌ Podem interceptar token
❌ Podem executar comandos
```

---

## ⚠️ Recomendações de Segurança

1. **Mudar token regularmente**
   ```powershell
   $env:COMMAND_SERVER_TOKEN = "novo-token-seguro-aleatorio"
   ```

2. **Usar VPN para acesso remoto**
   - Não acesse do iPhone fora da rede WiFi
   - Use VPN para tráfego criptografado

3. **Usar HTTPS em produção**
   - Gerar certificado SSL
   - Usar porta 443

4. **Desabilitar quando não usar**
   - Ctrl+C para parar servidor
   - Remoça arquivo `rce-command-server.js` se não precisar

5. **Monitorar logs**
   ```bash
   tail -f logs/rce-server.log
   ```

6. **Firewall do Windows**
   - Apenas rede local no firewall
   - Bloquear porta 5557 da internet

---

## 🐛 Troubleshooting

### "Connection refused"
- Servidor não está rodando
- IP incorreto (use `ipconfig`)
- Firewall bloqueando

### "Unauthorized"
- Token faltando ou errado
- Verificar Header `Authorization: Bearer TOKEN`

### "Too many requests"
- Aguardar 0.5 segundos entre requisições

### Comando não executa
- Verificar sintaxe do comando
- Testar direto no CMD primeiro
- Ver logs: `logs/rce-server.log`

---

## 📋 Mais Exemplos

### Controlar Spotify do iPhone
```bash
curl -X POST http://IP:5557/execute \
  -H "Authorization: Bearer TOKEN" \
  -d '{"cmd": "nircmd sendkey space", "timeout": 5}'
```

### Tirar screenshot
```bash
curl -X POST http://IP:5557/execute \
  -H "Authorization: Bearer TOKEN" \
  -d '{"cmd": "powershell -Command \"[System.Windows.Forms.SendKeys]::SendWait(\"%{PRTSC}\")\"", "timeout": 5}'
```

### Gerenciar serviços Windows
```bash
curl -X POST http://IP:5557/execute \
  -H "Authorization: Bearer TOKEN" \
  -d '{"cmd": "net start Apache2.4", "timeout": 30}'
```

---

## 📞 Suporte

Se algo quebrar:
1. Verificar `logs/rce-server.log`
2. Testar comando no CMD localmente
3. Confirmar que token está correto
4. Reiniciar servidor

**Responsabilidade:** Qualquer dano causado é responsabilidade do usuário que ativou RCE sem restrições.
