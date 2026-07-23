# 🤖 PROMPT PRONTO - Cole no Claude iPhone

**Copie TODO o conteúdo abaixo e cole no Claude app do iPhone**

---

## 📋 COLA ISSO NO CLAUDE:

```
Você é um assistente que controla um PC remoto via RCE Server com Device Authentication.

CONFIG DO IPHONE (Copie para seu Shortcut):
=========================================
Endpoint: https://rce-shopvivaliz.trycloudflare.com
Token: hBu-3gs3meFOp82AnXLzljmIvNaf-7ih
Device ID: iphone-3cc2c19459524e3cb79d7bdfaa1b456a

Headers Obrigatórios:
- Authorization: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih
- X-Device-ID: iphone-3cc2c19459524e3cb79d7bdfaa1b456a
- Content-Type: application/json

COMO FAZER REQUISIÇÃO:
curl -X POST https://rce-shopvivaliz.trycloudflare.com/execute \
  -H "Authorization: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih" \
  -H "X-Device-ID: iphone-3cc2c19459524e3cb79d7bdfaa1b456a" \
  -H "Content-Type: application/json" \
  -d '{"cmd": "COMANDO_AQUI", "timeout": 30}'

COMANDOS DISPONÍVEIS:
====================

GIT:
- {"cmd": "git status"}
- {"cmd": "git log --oneline -10"}
- {"cmd": "git add . && git commit -m 'msg' && git push"}
- {"cmd": "git pull origin main"}

SISTEMA:
- {"cmd": "dir"}
- {"cmd": "tasklist"}
- {"cmd": "systeminfo"}
- {"cmd": "ipconfig"}

DEV:
- {"cmd": "npm run dev"}
- {"cmd": "npm test"}
- {"cmd": "python script.py"}
- {"cmd": "php -l arquivo.php"}

FILES:
- {"cmd": "type c:\\arquivo.txt"}
- {"cmd": "dir c:\\site-shopvivaliz"}

TERMINAL:
POST /open-terminal
{"type": "powershell"}

NAVEGADOR:
POST /open-browser
{"url": "https://github.com"}

APP:
POST /open-app
{"app": "C:\\Program Files\\VSCode\\Code.exe"}

REGRAS:
=======
1. Sempre execute comandos com curl
2. Mostrar comando exato antes de executar
3. Explicar o que está fazendo
4. Mostrar resultado ao usuário
5. Nunca deletar sem confirmar
6. Máximo 300 segundos de timeout
```

---

## 🔧 CONFIGURAR SHORTCUTS NO IPHONE

### **Método 1: Usando Web Request (Mais Fácil)**

1. **Abra** Shortcuts app
2. **Crie** novo atalho (+)
3. **Adicione** ação: **Web Request**

```
Configuração:
Method: POST
URL: https://rce-shopvivaliz.trycloudflare.com/execute

Headers (importante: adicionar um por um):
1. Authorization
   Valor: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih

2. X-Device-ID
   Valor: iphone-3cc2c19459524e3cb79d7bdfaa1b456a

3. Content-Type
   Valor: application/json

Body (escolha uma opção):

OPÇÃO A - Comando fixo:
{"cmd":"git status","timeout":30}

OPÇÃO B - Pedir comando do usuário:
Adicione antes: Ask for [Text]
Salve em: userCommand
Body: {"cmd":"[userCommand]","timeout":30}
```

4. **Adicione** ação: **Show Result**
5. **Pronto!** Use o shortcut

---

## 🎯 TESTE AGORA

**No Claude iPhone, escreva:**

```
Verifique o git status do meu PC
```

Claude vai:
1. Montar a requisição curl
2. Executar
3. Mostrar o resultado

---

## 📱 SHORTCUTS PRONTAS (Copie estas configs)

### **Shortcut 1: Ver Git Status**
```
POST https://rce-shopvivaliz.trycloudflare.com/execute
Headers:
  Authorization: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih
  X-Device-ID: iphone-3cc2c19459524e3cb79d7bdfaa1b456a

Body: {"cmd":"git status","timeout":30}
```

### **Shortcut 2: Fazer Commit**
```
POST https://rce-shopvivaliz.trycloudflare.com/execute
Headers:
  Authorization: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih
  X-Device-ID: iphone-3cc2c19459524e3cb79d7bdfaa1b456a

Body: {"cmd":"git add . && git commit -m 'auto' && git push","timeout":60}
```

### **Shortcut 3: Abrir VSCode**
```
POST https://rce-shopvivaliz.trycloudflare.com/open-app
Headers:
  Authorization: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih
  X-Device-ID: iphone-3cc2c19459524e3cb79d7bdfaa1b456a

Body: {"app":"C:\\Program Files\\VSCode\\Code.exe"}
```

### **Shortcut 4: Listar Arquivos**
```
POST https://rce-shopvivaliz.trycloudflare.com/execute
Headers:
  Authorization: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih
  X-Device-ID: iphone-3cc2c19459524e3cb79d7bdfaa1b456a

Body: {"cmd":"dir c:\\site-shopvivaliz","timeout":30}
```

---

## ✅ CHECKLIST

- [ ] RCE Server rodando no PC (autostart)
- [ ] Cloudflare Tunnel ativo (autostart)
- [ ] Claude app instalado no iPhone
- [ ] Prompt colado no Claude
- [ ] Shortcuts configurados no iPhone
- [ ] Headers corretamente adicionados:
  - `Authorization: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih`
  - `X-Device-ID: iphone-3cc2c19459524e3cb79d7bdfaa1b456a`
- [ ] Teste: Pergunte algo ao Claude
- [ ] Claude executa comando com sucesso ✅

---

## 🚀 COMANDS RAPIDOS

**No Claude, copie e mande:**

```
Execute esse comando no meu PC:
git status
```

Claude vai fazer tudo! Curl + headers + resposta.

---

## 🔐 SEGURANÇA

✅ HTTPS criptografado  
✅ Token + Device ID (2 camadas)  
✅ Apenas seu iPhone  
✅ Rate limited  
✅ Logs registrados  

---

## 💾 AUTOSTART CONFIGURADO

Seu PC agora:
- Inicia RCE Server ao ligar
- Inicia Cloudflare Tunnel 3s depois
- Está pronto para receber comandos

**Pronto para usar! 🎉**
