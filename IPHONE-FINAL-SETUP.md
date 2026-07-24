# 📱 Setup Final - iPhone com Device ID Único

**Seu iPhone tem acesso completo ao PC. Apenas você consegue acessar.**

---

## 🔐 Seu Device ID (Memorize ou Salve)

```
iphone-3cc2c19459524e3cb79d7bdfaa1b456a
```

**Este é o ÚNICO identificador que você precisa.**

---

## 🚀 Setup em 3 Passos

### **Passo 1: Criar Shortcut no iPhone**

Abra **Shortcuts app** → **Novo (+)**

Adicione ação **Web Request:**

```
Method: POST
URL: https://rce-shopvivaliz.trycloudflare.com/execute

Headers:
1. X-Device-ID = iphone-3cc2c19459524e3cb79d7bdfaa1b456a
2. Content-Type = application/json

Body: {"cmd":"git status","timeout":30}

Ação final: Show Result
```

**Done!**

---

### **Passo 2: Testar**

Toque no Shortcut no iPhone.

**Esperado:**
```json
{
  "status": "success",
  "output": "... git output aqui ...",
  "timestamp": "2026-07-23T..."
}
```

---

### **Passo 3: Automação (Opcional)**

Para **executar sozinho** a cada hora:

1. **Automation** (aba inferior)
2. **"+"** → **Personal Automation**
3. **Time of Day** → **Custom** → **Every 1 hour**
4. **Selecione seu Shortcut**
5. **Toggle OFF:** "Ask Before Running"
6. **Save**

Pronto! Executa automaticamente ✅

---

## 💡 Exemplos de Comandos

### **Git**
```json
{"cmd":"git status","timeout":30}
{"cmd":"git log --oneline -5","timeout":30}
{"cmd":"git add . && git commit -m 'auto' && git push","timeout":60}
```

### **Sistema**
```json
{"cmd":"dir","timeout":30}
{"cmd":"tasklist","timeout":30}
{"cmd":"systeminfo","timeout":30}
```

### **Dev**
```json
{"cmd":"npm run dev","timeout":60}
{"cmd":"python script.py","timeout":60}
{"cmd":"php -l arquivo.php","timeout":30}
```

### **Abrir Apps**

**Usar endpoint diferente:**

```
POST /open-browser
{"url":"https://github.com"}

POST /open-terminal
{"type":"powershell"}

POST /open-app
{"app":"C:\\Program Files\\VSCode\\Code.exe"}
```

---

## 🔒 Segurança

✅ **Apenas seu iPhone** pode executar  
✅ **Device ID único** como proteção  
✅ **HTTPS via Cloudflare** (criptografado)  
✅ **Rate limited** (1 req/0.5s)  
✅ **Logs de tudo** em `logs/rce-server.log`

---

## 📝 Usar com Claude

**Fale ao Claude no iPhone:**

```
Execute esse comando no meu PC:
git status

Device ID: iphone-3cc2c19459524e3cb79d7bdfaa1b456a
```

Claude vai:
- Montar requisição
- Executar
- Mostrar resultado

---

## 🆘 Troubleshooting

### "Unauthorized"
- Verificar Device ID
- Completar cópia (sem espaços)
- Header deve ser exatamente: `X-Device-ID`

### "Command not found"
- Testar comando no PC primeiro
- PowerShell vs CMD podem ter sintaxes diferentes

### "Timeout"
- Comando demorou mais de 300s
- Tente comando mais rápido

---

## ✅ Checklist

- [ ] Device ID copiado: `iphone-3cc2c19459524e3cb79d7bdfaa1b456a`
- [ ] Shortcut criado no iPhone
- [ ] Headers: X-Device-ID + Content-Type
- [ ] Body JSON: `{"cmd":"...","timeout":30}`
- [ ] Show Result adicionado
- [ ] Teste: Toque no shortcut
- [ ] Resultado aparece ✅
- [ ] Automação configurada (opcional)
- [ ] Executa sozinho ✅

---

## 📊 Status

```
✅ RCE Server rodando (Device ID only)
✅ Cloudflare Tunnel pronto
✅ Autostart configurado
✅ Apenas seu iPhone autorizado
✅ Acesso completo sem restrições
```

---

**Pronto para controlar seu PC do iPhone! 🚀**
