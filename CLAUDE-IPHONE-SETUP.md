# 🤖 Claude AI no iPhone - Controlando seu PC via 4G

**Use o Claude (app de IA) no iPhone para executar comandos no seu PC remoto.**

---

## 📱 Como Usar

### **Opção 1: Claude App (Recomendado)**

1. Abra **Claude app** no iPhone
2. Copie o prompt abaixo e cole na conversa:

```
Você é um assistente que controla um PC remoto via RCE Server.
Você tem acesso total ao PC do usuário (executar qualquer comando, abrir apps, etc).

INSTRUÇÕES:

1. PARA EXECUTAR COMANDOS NO PC:
   - Use curl via seu Terminal/Browser capabilities
   - Endpoint: https://rce-shopvivaliz.trycloudflare.com/execute
   - Header: Authorization: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih
   - Header: X-Device-ID: iphone-3cc2c19459524e3cb79d7bdfaa1b456a
   - Body JSON: {"cmd": "SEU_COMANDO", "timeout": 30}

2. EXEMPLOS DE COMANDOS:

   Git:
   - {"cmd": "git status"}
   - {"cmd": "git log --oneline -5"}
   - {"cmd": "git add . && git commit -m 'msg' && git push"}

   Sistema:
   - {"cmd": "dir"}
   - {"cmd": "tasklist"}
   - {"cmd": "systeminfo"}

   Desenvolvimento:
   - {"cmd": "npm run dev"}
   - {"cmd": "python script.py"}
   - {"cmd": "php -l arquivo.php"}

   VSCode:
   - {"cmd": "code ."}

   Arquivos:
   - {"cmd": "type c:\\site-shopvivaliz\\arquivo.txt"}
   - {"cmd": "dir c:\\site-shopvivaliz"}

3. ENDPOINTS ESPECIAIS:

   Abrir Terminal (PowerShell):
   POST /open-terminal
   {"type": "powershell"}

   Abrir Navegador:
   POST /open-browser
   {"url": "https://github.com"}

   Abrir App:
   POST /open-app
   {"app": "C:\\Program Files\\VSCode\\Code.exe"}

4. SEMPRE FAZER:
   - Incluir o comando exato em mensagens
   - Explicar o que o comando faz
   - Mostrar resultado ao usuário
   - Perguntar se quer fazer mais algo

5. NÃO FAZER:
   - Comandos destrutivos sem confirmação (rm, del, format)
   - Executar código sem contexto
   - Ignorar erros de comando

PROTOCOLO DE REQUISIÇÃO CURL:

curl -X POST https://rce-shopvivaliz.trycloudflare.com/execute \
  -H "Authorization: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih" \
  -H "X-Device-ID: iphone-3cc2c19459524e3cb79d7bdfaa1b456a" \
  -H "Content-Type: application/json" \
  -d '{"cmd": "COMANDO_AQUI", "timeout": 30}'
```

3. Depois pergunte qualquer coisa:
   - "Ver status do git"
   - "Abrir VSCode"
   - "Listar arquivos"
   - "Fazer commit automático"

---

### **Opção 2: ChatGPT/Gemini (Similar)**

Mesmo processo, mas com ChatGPT ou Google Gemini.

---

## 🎯 Exemplos de Uso

### **Claude, faça commit automático**
```
Claude vai executar:
curl -X POST https://rce-shopvivaliz.trycloudflare.com/execute \
  -H "Authorization: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih" \
  -H "X-Device-ID: iphone-3cc2c19459524e3cb79d7bdfaa1b456a" \
  -d '{"cmd": "git add . && git commit -m auto:sync && git push", "timeout": 60}'
```

### **Claude, abra VSCode**
```
Claude vai executar:
curl -X POST https://rce-shopvivaliz.trycloudflare.com/open-app \
  -H "Authorization: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih" \
  -H "X-Device-ID: iphone-3cc2c19459524e3cb79d7bdfaa1b456a" \
  -d '{"app": "C:\\Program Files\\VSCode\\Code.exe"}'
```

### **Claude, qual é a status do git?**
```
Claude vai executar:
curl -X POST https://rce-shopvivaliz.trycloudflare.com/execute \
  -H "Authorization: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih" \
  -H "X-Device-ID: iphone-3cc2c19459524e3cb79d7bdfaa1b456a" \
  -d '{"cmd": "git status"}'

E vai mostrar o resultado para você.
```

---

## ⚙️ Autostart (PC)

Seu PC agora inicia automaticamente ao ligar:

✅ **RCE Server** (Device Auth)  
✅ **Cloudflare Tunnel** (URL HTTPS pública)

**Timeline:**
- 0s: PC ligado
- 2s: RCE Server rodando
- 5s: Cloudflare Tunnel rodando
- 10s: URL pronta

---

## 🔐 Segurança

```
Token:      hBu-3gs3meFOp82AnXLzljmIvNaf-7ih
Device ID:  iphone-3cc2c19459524e3cb79d7bdfaa1b456a
URL:        https://rce-shopvivaliz.trycloudflare.com
```

**Proteções:**
✅ HTTPS (256-bit TLS)  
✅ Token Bearer obrigatório  
✅ Device ID obrigatório  
✅ Apenas seu iPhone  
✅ Rate limiting (1 req/0.5s)  

---

## 📋 Comandos Úteis

### **Verificar Status**
```
Claude: "Verifique se o servidor está respondendo"
→ Executa status check
```

### **Git Operations**
```
Claude: "Faça pull das últimas mudanças"
→ git pull origin main

Claude: "Veja o histórico de commits"
→ git log --oneline -10
```

### **Sistema**
```
Claude: "Qual é o espaço livre em disco?"
→ dir C:\

Claude: "Liste os processos rodando"
→ tasklist
```

### **Desenvolvimento**
```
Claude: "Inicie o servidor de desenvolvimento"
→ npm run dev

Claude: "Rode os testes"
→ npm test
```

---

## 🆘 Troubleshooting

### "Unauthorized" no Claude
- Verificar que token está correto
- Verificar que Device ID está correto
- Tentar novamente

### "Cloudflare Tunnel não respondendo"
- URL muda cada vez que reinicia
- Copie a nova URL de `logs/rce-server.log`
- Atualize o prompt do Claude

### "Command timeout"
- Comando demorou mais de 300 segundos
- Tente comando mais simples
- Aumentar `timeout` no JSON

---

## 💡 Dicas Avançadas

### **Claude com Internet**
```
Se o Claude tiver acesso a internet (Claude Pro):
- Ele pode verificar seu repositório GitHub
- Comparar com estado local do PC
- Sugerir mudanças mais inteligentes
```

### **Automação Completa**
```
Você pode criar workflows:
1. Claude verifica GitHub
2. Claude puxa mudanças
3. Claude roda testes
4. Claude faz commit
5. Claude notifica resultado

Tudo via 4G do iPhone!
```

### **Integração com Outros Serviços**
```
Claude pode:
- Deployar via GitHub Actions
- Enviar notificações
- Monitorar logs
- Alertar sobre erros
```

---

## 🚀 Status Atual

```
✅ RCE Server rodando (Device Auth)
✅ Cloudflare Tunnel pronto
✅ Autostart configurado
✅ Pronto para usar Claude
```

---

## 📞 Quick Commands

Copie e cole no Claude:

**Status Check**
```
Verifique o status: curl -X GET https://rce-shopvivaliz.trycloudflare.com/status \
  -H "Authorization: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih" \
  -H "X-Device-ID: iphone-3cc2c19459524e3cb79d7bdfaa1b456a"
```

**Git Pull**
```
Faça git pull: {"cmd": "git pull origin main"}
```

**Abrir VSCode**
```
Abra VSCode: {"app": "C:\\Program Files\\VSCode\\Code.exe"}
```

---

**Pronto! Controle seu PC via Claude no iPhone com 4G! 🚀**
