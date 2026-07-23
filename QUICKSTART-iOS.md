# ⚡ Quick Start - iOS Commands em 3 Minutos

## 1️⃣ Iniciar Listener (nesta PC agora)

Abra PowerShell **neste VS Code** e execute:

```powershell
cd c:\site-shopvivaliz
node scripts/ios-command-listener.js
```

✅ Você verá aparecer:
```
🎯 iOS Command Listener rodando na porta 9999
📱 Acesse do iPhone: http://seu-pc-ip:9999/execute
🔐 Token: hBu-3gs3meFOp82AnXLzljmIvNaf-7ih
```

---

## 2️⃣ Descobrir IP da sua PC

**Mesmo PowerShell, execute:**

```powershell
ipconfig | findstr "IPv4"
```

**Resultado (exemplo):**
```
IPv4 Address. . . . . . . . : 192.168.1.100
```

**Anote este número!** É seu `SEU-PC-IP`

---

## 3️⃣ Testar do iPhone

**Via Safari no iPhone, acesse:**

```
http://192.168.1.100:9999/execute

Headers:
X-Token: hBu-3gs3meFOp82AnXLzljmIvNaf-7ih
Content-Type: application/json

Body:
{"cmd":"git status"}
```

**Resultado aparece na tela.**

---

## 📱 Usar Siri Shortcuts (Recomendado)

Ver arquivo completo: `iOS-SETUP-GUIA.md`

Resumo:
1. App Atalhos → Nova automação
2. POST request para `http://SEU-PC-IP:9999/execute`
3. Header: `X-Token: hBu-3gs3meFOp82AnXLzljmIvNaf-7ih`
4. Body: `{"cmd":"seu-comando"}`
5. Mostrar resultado

---

## 🚀 Pronto!

Agora qualquer comando que você mandar do iPhone vai executar aqui. Exemplos:

- `git status`
- `git add . && git commit -m "msg"`
- `npm run build`
- `ls -la`
- `git log --oneline -5`

---

**Mais dúvidas?** Consulte `iOS-SETUP-GUIA.md` ou `scripts/ios-test-commands.txt`
