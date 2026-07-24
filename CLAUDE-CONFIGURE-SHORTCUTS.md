# 🤖 Claude/ChatGPT Configurando Shortcuts no iPhone

**Pergunte ao Claude ou ChatGPT para configurar o Shortcut passo a passo.**

---

## 📱 O que Falar para Claude/ChatGPT

Copie e mande para o app Claude ou ChatGPT no iPhone:

```
Configure um Shortcut no meu iPhone para executar comandos no RCE Server.

Detalhes:
- Nome: RCE Execute
- Método: POST
- URL: https://rce-shopvivaliz.trycloudflare.com/execute
- Headers:
  * Authorization: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih
  * X-Device-ID: iphone-3cc2c19459524e3cb79d7bdfaa1b456a
  * Content-Type: application/json
- Body (JSON): {"cmd":"git status","timeout":30}

Inclua ações:
1. Web Request (POST)
2. Show Result

Guie-me passo a passo no app Shortcuts.
```

---

## 🎯 O que Claude/ChatGPT Vai Fazer

Claude/ChatGPT vai:

1. **Pedir para abrir** Shortcuts app
2. **Guiar** cada passo:
   - "Toque no +"
   - "Procure por 'Web Request'"
   - "Selecione Method: POST"
   - Etc.

3. **Mostrar exatamente** aonde colar:
   - URL exata
   - Headers (um por um)
   - Body JSON

4. **Verificar** cada passo

---

## ✅ Fluxo Completo

### **Você no iPhone:**
```
"Claude, configure um shortcut para executar comandos no meu PC"
```

### **Claude responde:**
```
Claro! Vamos configurar passo a passo.

Passo 1:
Abra o app Shortcuts (ícone de atalho azul)

Passo 2:
Toque no botão "+" (canto superior direito)

Passo 3:
Procure por "Web Request" na busca

[Continua guiando cada passo...]
```

---

## 🔧 Shortcuts Prontos para Claude Descrever

### **Shortcut 1: Ver Git Status**
```
Claude pode dizer:

"Configure assim:
- Method: POST
- URL: https://rce-shopvivaliz.trycloudflare.com/execute
- Header 1: Authorization = Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih
- Header 2: X-Device-ID = iphone-3cc2c19459524e3cb79d7bdfaa1b456a
- Header 3: Content-Type = application/json
- Body: {"cmd":"git status","timeout":30}
- Add 'Show Result' action"
```

### **Shortcut 2: Fazer Commit**
```
Claude dirá:

"Mesmo setup anterior, mas mude o Body para:
{"cmd":"git add . && git commit -m 'auto' && git push","timeout":60}"
```

### **Shortcut 3: Abrir App**
```
Claude dirá:

"Mesmo setup, mas mude:
- URL para: https://rce-shopvivaliz.trycloudflare.com/open-app
- Body para: {"app":"C:\\Program Files\\VSCode\\Code.exe"}"
```

---

## 📋 Script Pronto para Falar ao Claude

**Copie e mande direto:**

```
Preciso de um shortcut no iPhone que execute comandos no meu PC remoto.

Configure passo a passo no app Shortcuts:

Shortcut Name: RCE Command

Adicione ação: Web Request
- Method: POST
- URL: https://rce-shopvivaliz.trycloudflare.com/execute
- Add Header "Authorization" = "Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih"
- Add Header "X-Device-ID" = "iphone-3cc2c19459524e3cb79d7bdfaa1b456a"
- Add Header "Content-Type" = "application/json"
- Request Body: {"cmd":"git status","timeout":30}

Adicione ação: Show Result

Guie-me pelo processo.
```

---

## 🎬 Exemplo Completo de Conversa

### **Você:**
```
Claude, help me create a shortcut to execute commands on my PC
```

### **Claude responde:**
```
I'll guide you step by step through the Shortcuts app.

Step 1: Open the Shortcuts app
Look for the blue icon with circles on your home screen.

Step 2: Create a new shortcut
Tap the "+" button in the top right corner.

Step 3: Add Web Request action
- Tap "Add Action"
- Search for "Web Request"
- Tap it to add

Step 4: Configure Web Request
- Method: Change to POST
- URL: https://rce-shopvivaliz.trycloudflare.com/execute
- Add Headers (tap "Add"):
  Header 1: Authorization = Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih
  Header 2: X-Device-ID = iphone-3cc2c19459524e3cb79d7bdfaa1b456a
  Header 3: Content-Type = application/json

Step 5: Add request body
- Request Body (JSON): {"cmd":"git status","timeout":30}

Step 6: Show result
- Add Action > Show Result

Done! Your shortcut is ready.
```

---

## 💡 Dicas

### **Claude/ChatGPT Podem:**
✅ Guiar passo a passo  
✅ Dizer exatamente aonde clicar  
✅ Explicar cada campo  
✅ Ajudar se errar  

### **Claude/ChatGPT NÃO Podem:**
❌ Criar automaticamente  
❌ Acessar Shortcuts app  
❌ Clicar por você  

---

## 🚀 Depois de Configurado

Você pode:
- **Chamar Claude:** "Execute git status no meu PC"
- **Claude executa:** Requisição via Shortcut
- **Resultado:** Aparece no iPhone

---

## 📞 Conversa Rápida

**Você:**
```
Configure um shortcut chamado "Git Status" que executa 
git status no meu PC via RCE Server.

Token: hBu-3gs3meFOp82AnXLzljmIvNaf-7ih
Device: iphone-3cc2c19459524e3cb79d7bdfaa1b456a
URL: https://rce-shopvivaliz.trycloudflare.com/execute
```

**Claude vai guiar** cada passo no Shortcuts app! 📱

---

**Pronto para começar? Abra Claude/ChatGPT no iPhone e peça para configurar!** 🚀
