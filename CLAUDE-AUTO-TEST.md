# 🤖 Prompt para Claude Testar RCE Automaticamente

**Copie e cole NO CLAUDE APP DO IPHONE:**

---

## 📋 Prompt Completo

```
Você vai testar meu PC remoto via RCE Server sem eu fazer nada além de responder.

DADOS:
- URL: https://rce-shopvivaliz.trycloudflare.com/execute
- IMEI: 356935402541129
- MAC: B8:01:1F:42:B1:78

TESTE 1 - Status do Git (4G/IMEI):
Execute esse comando via cURL e mostre o resultado:

curl -X POST https://rce-shopvivaliz.trycloudflare.com/execute \
  -H "X-IMEI: 356935402541129" \
  -H "Content-Type: application/json" \
  -d '{"cmd":"git status","timeout":30}'

Se receber {"status":"success",...} → Funcionou! ✅

TESTE 2 - WiFi (MAC):
Se o Teste 1 funcionar, tente com MAC:

curl -X POST https://rce-shopvivaliz.trycloudflare.com/execute \
  -H "X-MAC: B8:01:1F:42:B1:78" \
  -H "Content-Type: application/json" \
  -d '{"cmd":"git status","timeout":30}'

TESTE 3 - Outro Comando (se os anteriores funcionarem):
curl -X POST https://rce-shopvivaliz.trycloudflare.com/execute \
  -H "X-IMEI: 356935402541129" \
  -H "Content-Type: application/json" \
  -d '{"cmd":"dir c:\\site-shopvivaliz","timeout":30}'

RESUMO:
Após cada teste, diga:
✅ Funcionou - [resultado]
❌ Falhou - [erro]

Se os 3 testes passarem, diga: "RCE PC Control está 100% pronto!"
```

---

## 🚀 **Como Usar**

1. **Abra Claude app** no iPhone
2. **Cole o prompt acima** na conversa
3. **Claude vai:**
   - ✅ Executar os 3 testes via cURL
   - ✅ Testar tanto IMEI (4G) quanto MAC (WiFi)
   - ✅ Mostrar resultados
   - ✅ Confirmar se está funcionando

4. **Você não faz nada** - Claude faz tudo!

---

## 📊 O que Claude vai fazer

```
Teste 1 (IMEI - 4G):
  curl → execute → mostra resultado

Teste 2 (MAC - WiFi):
  curl → execute → mostra resultado

Teste 3 (Comando diferente):
  curl → execute → mostra resultado

Se todos passarem:
  "RCE PC Control está 100% pronto!" ✅
```

---

## ✅ Esperado

Respostas bem-sucedidas:
```json
{
  "status": "success",
  "output": "... resultado do comando ...",
  "timestamp": "2026-07-23T..."
}
```

Erros esperados (ignorar):
```json
{
  "error": "Device ID faltando"
}
```

---

## 💡 Dica

Claude vai **automágicamente**:
- ✅ Executar cURL
- ✅ Testar conexão
- ✅ Verificar respostas
- ✅ Confirmar tudo funcionando
- ❌ Sem você fazer nada

---

**Abra Claude no iPhone e mande o prompt acima! 🚀**
