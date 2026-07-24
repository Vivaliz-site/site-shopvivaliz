# ⏰ Automação - Shortcut Executa Sozinho no iPhone

**Configure para rodar automaticamente sem tocar.**

---

## 🎯 Opções de Automação

### **Opção 1: Executar a Cada Hora**

1. **Shortcuts app** → **Automation** (aba inferior)
2. **"+"** → **Create Personal Automation**
3. **Time of Day** → **Custom**
   - Hora: Cada 1 hora
4. **Add Action** → **Web Request**
   - Mesmo setup anterior
5. **Toggle ON**: "Run immediately without asking"
6. **Done**

**Resultado:** Executa sozinho a cada hora ✅

---

### **Opção 2: Executar ao Conectar WiFi**

1. **Automation** → **"+"** → **Personal Automation**
2. **WiFi** → **Selecione sua WiFi**
3. **Add Action** → **Web Request**
4. **Toggle ON**: "Run immediately without asking"
5. **Done**

**Resultado:** Executa automaticamente ao conectar WiFi ✅

---

### **Opção 3: Executar ao Abrir App**

1. **Automation** → **"+"** → **Personal Automation**
2. **App** → **Selecione app** (ex: Chrome)
3. **Add Action** → **Web Request**
4. **Toggle ON**: "Run immediately without asking"
5. **Done**

**Resultado:** Executa ao abrir app específico ✅

---

### **Opção 4: Executar ao Receber Notificação**

1. **Automation** → **"+"** → **Personal Automation**
2. **Notification** → **Selecione app**
3. **Add Action** → **Web Request**
4. **Toggle ON**: "Run immediately without asking"
5. **Done**

**Resultado:** Executa ao receber notificação ✅

---

## 📋 Web Request (Same for all)

Para TODAS as automações, configure:

```
Method: POST
URL: https://rce-shopvivaliz.trycloudflare.com/execute

Headers:
- Authorization: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih
- X-Device-ID: iphone-3cc2c19459524e3cb79d7bdfaa1b456a
- Content-Type: application/json

Body: {"cmd":"git status","timeout":30}
```

---

## 🎬 Exemplo: Executar a Cada 10 Minutos

1. **Automation** → **"+"**
2. **Time of Day**
3. **Custom** → **Every 10 minutes**
4. **Add Action** → **Web Request** (config acima)
5. **Toggle ON**: "Run immediately without asking"
6. **Save**

**Pronto!** Executa sozinho a cada 10 minutos 🚀

---

## ⚠️ Dica Importante

Para rodar **"sem pedir confirmação"**:
- Procure toggle: **"Ask Before Running"** ou **"Show When Run"**
- **DESABILITAR** (deixar OFF)
- Agora roda silenciosamente ✅

---

## 📊 Opções Recomendadas

| Quando | Setup | Frequência |
|--------|-------|-----------|
| **Cada hora** | Time of Day | 1h |
| **Ao acordar** | Time → 8:00 AM | 1x/dia |
| **Ao ligar WiFi** | WiFi event | Quando conectar |
| **A cada 10 min** | Time → Custom | 10 min |

---

## 💡 Usar com Claude

Depois de configurar, fale ao Claude:

```
"Verifique git status" → 
Claude vê que você tem automação rodando 
Claude pode usar resultados anteriores
Claude recomenda ações
```

---

**Qual automação você quer: a cada hora? ao conectar WiFi? Outro trigger?**
