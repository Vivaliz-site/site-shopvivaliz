# 📱 Autenticação por Device - Apenas seu iPhone

**Apenas SEU iPhone consegue acessar RCE Server via Cloudflare Tunnel (4G ou WiFi).**

---

## 🔐 Como Funciona

```
Requisição do iPhone
    ↓
Verifica Token Bearer ✅
    ↓
Verifica Device ID (seu iPhone) ✅
    ↓
Executa comando
    ↓
Resposta criptografada HTTPS
```

Se alguém roubar o token, **não consegue acessar sem o Device ID único do seu iPhone.**

---

## 📝 Seu Device ID

```
iphone-3cc2c19459524e3cb79d7bdfaa1b456a
```

**Guarde com segurança!** (Não compartilhe)

---

## 🚀 Usar no iPhone (Shortcuts)

### **Passo 1: Criar Shortcut**

1. Abrir app **Shortcuts** no iPhone
2. Clique em **"+"** (novo atalho)
3. Adicionar ação **Web Request**

### **Passo 2: Configurar Web Request**

```
Method: POST

URL:
https://rce-shopvivaliz.trycloudflare.com/execute

Headers:
- Authorization: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih
- X-Device-ID: iphone-3cc2c19459524e3cb79d7bdfaa1b456a

Body (texto):
{"cmd":"dir","timeout":30}
```

### **Passo 3: Mostrar Resultado**

1. Adicionar ação **Show Result**
2. Pronto! ✅

### **Passo 4: Usar**

- Toque no atalho
- Comando é executado no PC
- Resultado aparece no iPhone

---

## 🔗 Exemplos

### **Ver Status do Git**
```json
{
  "cmd": "git status",
  "timeout": 30
}
```

### **Fazer Commit**
```json
{
  "cmd": "git add . && git commit -m 'auto: sync' && git push",
  "timeout": 60
}
```

### **Executar Python**
```json
{
  "cmd": "python scripts/my-script.py",
  "timeout": 120
}
```

### **Abrir Navegador (via /open-browser)**
```json
{
  "url": "https://github.com"
}
```

### **Abrir Terminal (via /open-terminal)**
```json
{
  "type": "powershell"
}
```

---

## 🛠️ Via Curl (Terminal/Termius)

```bash
curl -X POST https://rce-shopvivaliz.trycloudflare.com/execute \
  -H "Authorization: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih" \
  -H "X-Device-ID: iphone-3cc2c19459524e3cb79d7bdfaa1b456a" \
  -H "Content-Type: application/json" \
  -d '{"cmd": "dir", "timeout": 30}'
```

---

## 📊 Monitoramento (PC)

### **Ver últimas requisições do seu iPhone**

```powershell
Get-Content logs/rce-server.log -Tail 20
```

Verá algo como:
```
[2026-07-23 20:15:30] [INFO] ✅ Autenticado: iPhone Pessoal (iphone-3cc2c19459524e3cb79d7bdfaa1b456a)
[2026-07-23 20:15:30] [EXEC] 🔴 iPhone Pessoal: dir (timeout: 30s)
[2026-07-23 20:15:31] [EXEC] ✅ iPhone Pessoal: Sucesso
```

### **Ver se servidor está respondendo**

```bash
curl -X GET http://127.0.0.1:5557/status \
  -H "Authorization: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih" \
  -H "X-Device-ID: iphone-3cc2c19459524e3cb79d7bdfaa1b456a"
```

---

## 🔑 Segurança Explicada

| Camada | Proteção | Resultado |
|--------|----------|-----------|
| **Token Bearer** | Autenticação padrão | ✅ Rejeita requisição sem token |
| **Device ID** | ID único do iPhone | ✅ Rejeita iPhone não autorizado |
| **HTTPS via Cloudflare** | Criptografia 256-bit | ✅ Impossível interceptar dados |
| **Rate Limiting** | 1 req / 0.5 segundos | ✅ Protege contra brute force |

---

## 📱 Se Trocar de iPhone

1. Gere novo Device ID no PC:
   ```powershell
   curl http://127.0.0.1:5557/generate-device-id
   ```

2. Edite `rce-command-server-device-auth.js`:
   ```javascript
   const ALLOWED_DEVICES = {
     "iPhone Pessoal": "novo-device-id-aqui",
   };
   ```

3. Reinicie servidor:
   ```powershell
   Get-Process node | Stop-Process
   node rce-command-server-device-auth.js
   ```

---

## ⚠️ Se Perder Device ID

Device ID foi salvo em:
- Este arquivo (`IPHONE-DEVICE-AUTH.md`)
- `rce-command-server-device-auth.js` (linha 23)

Se perdeu, copie de uma das fontes acima.

---

## 🎯 Checklist

- [ ] Device ID copiado: `iphone-3cc2c19459524e3cb79d7bdfaa1b456a`
- [ ] Shortcut criado no iPhone
- [ ] Web Request configurado com URL
- [ ] Headers configurados (Authorization + X-Device-ID)
- [ ] Body com comando JSON
- [ ] Teste: `{"cmd": "dir", "timeout": 30}`
- [ ] Resultado aparece no iPhone ✅

---

## 💡 Dicas

1. **Atalhos rápidos**: Crie múltiplos shortcuts para comandos diferentes
2. **Widgets**: Adicione atalho à tela inicial do iPhone
3. **Siri**: Nomeie atalho e use com voz "Ei Siri, [nome do atalho]"
4. **Automação**: Configure automação por hora/local

---

**Apenas seu iPhone consegue acessar. Seguro! 🔐**
