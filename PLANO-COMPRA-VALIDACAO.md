# 🎯 PLANO DE AÇÃO - VALIDAÇÃO COM COMPRA REAL

**Status:** 🔴 PRONTO PARA EXECUTAR (VOCÊ FAZ, EU MONITORO)

---

## 📋 DIVISÃO DE RESPONSABILIDADES

### VOCÊ (Usuário):
```
1. Abrir navegador
2. Ir para: https://shopvivaliz.com.br/
3. Fazer compra com seus dados (Frederico de Castro Mourao, CPF, email, etc)
4. Gerar boleto
5. Anotar número do pedido
6. Verificar inbox de email
7. Logar em Olist/Tiny ERP
8. Procurar pelo pedido
9. Reportar resultado
```

**Tempo esperado:** 20-30 minutos

### EU (Claude Code):
```
1. Monitorar logs em tempo real
2. Testar API de email
3. Validar Olist sync
4. Se algo falhar: diagnosticar
5. Suporte instantâneo se precisar
```

---

## 🚀 COMEÇAR AGORA - PASSO A PASSO

### PASSO 1: Eu preparo o monitor (AGORA)

```bash
# Ele vai rodar em paralelo monitora:
# - Site online/offline
# - Email chegando
# - Olist sincronizando
# - Erros aparecerem
bash MONITOR-TEMPO-REAL.sh
# Deixar rodando na terminal
```

Eu vou executar este comando para você em uma janela de terminal. Ficará monitorando tudo em tempo real.

### PASSO 2: VOCÊ faz a compra no browser

```
⏱️ CRONOGRAMA (seguir GUIA-COMPRA-TESTE-REAL.md):

14:00 - Abrir site
14:02 - Adicionar produto
14:03 - Checkout
14:08 - Preencher dados (use Frederico de Castro Mourao)
14:10 - Calcular frete
14:12 - Selecionar boleto
14:13 - Gerar boleto ← ANOTAR NÚMERO DO PEDIDO
14:14 - Verificar email (deve chegar em < 60s)
14:18 - Logar Olist (procurar pedido)
14:20 - Reportar resultado
```

### PASSO 3: Eu verifico TUDO enquanto você faz

Enquanto você está fazendo a compra, eu vou estar:

```
✅ Monitorando logs de email
✅ Verificando sync Olist
✅ Testando APIs
✅ Procurando erros
✅ De prontidão para debug
```

### PASSO 4: Resultado

**Se tudo funcionar:**
```
Email chegou? ✅
Pedido no ERP? ✅
→ PRODUÇÃO LIBERADA 🚀
```

**Se algo falhar:**
```
Email não chegou? 
→ Diagnóstico: SMTP Gmail issue
→ Ação: Debug credenciais

Pedido não apareceu?
→ Diagnóstico: Token Olist issue
→ Ação: Renovar token
```

---

## 📍 LOCALIZAÇÃO DOS DADOS

**Você vai usar:**
- 📄 GUIA-COMPRA-TESTE-REAL.md (tem seus dados pré-preenchidos)

**Eu vou consultar:**
- 📊 logs/email-*.log (monitorar email)
- 📊 logs/olist-sync.log (monitorar ERP)
- 📊 logs/orchestrator.log (monitorar geral)
- 🔗 https://shopvivaliz.com.br/ (testar site)

---

## ⚡ COMO EU FAREI O MONITORAMENTO

### Método 1: Monitor em Tempo Real (Terminal)
```bash
bash MONITOR-TEMPO-REAL.sh
```
Este script ficará exibindo:
- Status do site (online/offline)
- Últimos emails detectados
- Últimos syncs Olist
- Erros que aparecerem
- Atualizações a cada 5 segundos

### Método 2: Logs Detalhados (Conforme você relatar)
```bash
# Se email não chega:
tail -20 logs/email-*.log

# Se pedido não aparece:
tail -50 logs/olist-sync.log

# Se site está com erro:
curl -v https://shopvivaliz.com.br/
```

### Método 3: Testes de API (Sob Demanda)
```bash
# Testar email direto
curl -X POST https://shopvivaliz.com.br/api/mail/test.php

# Testar Olist sync
curl -X POST https://shopvivaliz.com.br/api/olist/sync-catalog.php

# Health check
curl https://shopvivaliz.com.br/admin/health-check.php
```

---

## 🔔 SINAIS DE SUCESSO (O que procurar)

### Email deve chegar em < 60 segundos
```
✅ SUCESSO:
- Inbox tem email de "ShopVivaliz"
- Assunto: "Confirmação de Pedido" ou similar
- Número do pedido bate
- Link/código boleto incluído

🔴 FALHA:
- Sem email após 2 minutos
- Verificar SPAM/Promotions
```

### Pedido deve aparecer no ERP
```
✅ SUCESSO:
- Olist/Tiny mostra novo pedido
- Nome: "Frederico de Castro Mourao"
- Status: "Novo" ou "Pagamento Pendente"
- Produtos e totais batem

🔴 FALHA:
- Pedido não aparece após 5 minutos
- Token Olist pode estar expirado
```

---

## 🎯 CHECKLIST FINAL

Marque conforme progride:

```
PREPARAÇÃO:
  [ ] Leu GUIA-COMPRA-TESTE-REAL.md
  [ ] Tem seus dados à mão (nome, CPF, email, etc)
  [ ] Sabe acessar https://shopvivaliz.com.br/

EXECUÇÃO (Você):
  [ ] Abrir site
  [ ] Adicionar produto ao carrinho
  [ ] Fazer checkout
  [ ] Preencher dados
  [ ] Gerar boleto
  [ ] Anotar número pedido

VALIDAÇÃO (Simultânea - Eu monitoro):
  [ ] Monitor rodando (bash MONITOR-TEMPO-REAL.sh)
  [ ] Email chegou? ___
  [ ] Tempo: ___ segundos
  [ ] Pedido apareceu? ___
  [ ] Status no ERP: ___

RESULTADO:
  [ ] ✅ SUCESSO = Produção liberada
  [ ] 🔴 FALHA = Diagnosticar
```

---

## 📞 COMO PROCEDER

### AGORA:
1. Abra uma terminal PowerShell
2. Navegue para: `cd C:\site-shopvivaliz`
3. Execute: `bash MONITOR-TEMPO-REAL.sh`
4. Deixe rodando
5. Abra OUTRA aba/janela do navegador

### NA OUTRA ABA:
1. Abra: https://shopvivaliz.com.br/
2. Siga GUIA-COMPRA-TESTE-REAL.md
3. Faça a compra
4. Relate o resultado

### APÓS A COMPRA:
1. Verifique email inbox
2. Login Olist/Tiny ERP
3. Me reporte o resultado

---

## 🆘 SE ALGO DER ERRADO

**Email não chegou?**
```
→ Eu vou verificar: tail -50 logs/email-*.log
→ Diagnosticar credenciais Gmail
→ Testar SMTP direto
→ Ação corretiva
```

**Pedido não apareceu no ERP?**
```
→ Eu vou verificar: tail -50 logs/olist-sync.log
→ Diagnosticar token Olist
→ Renovar token se expirado
→ Testar sync manual
→ Ação corretiva
```

**Site respondendo erro?**
```
→ Verificar: curl -v https://shopvivaliz.com.br/
→ Logs Apache: /var/log/apache2/error.log (VM)
→ Reiniciar serviços se necessário
```

---

## 🎊 RESULTADO ESPERADO

**Melhor caso:**
```
✅ Site carrega
✅ Compra realizada
✅ Email chega em 10-30 segundos
✅ Pedido aparece no ERP em 1-2 minutos
✅ Tudo sincronizado

→ PRODUÇÃO LIBERADA! 🚀
```

**Pior caso:**
```
❌ Email não chega (SMTP issue)
   OU
❌ Pedido não aparece (Token Olist issue)

→ Diagnosticar
→ Implementar fix
→ Retry
```

---

## 🕐 CRONOGRAMA TOTAL

```
00:00 - Você lê GUIA-COMPRA-TESTE-REAL.md (2 min)
00:02 - Você abre site e começa compra (5 min)
00:07 - Você preenche dados e gera boleto (5 min)
00:12 - Você verifica email inbox (2 min) ← EU MONITORO
00:14 - Você faz login Olist (1 min)
00:15 - Você procura pedido (2 min) ← EU MONITORO
00:17 - RESULTADO FINAL
```

**Total: ~20 minutos máximo**

---

## ✅ PRÓXIMA AÇÃO

**VOCÊ:**
1. Abra terminal
2. Execute: `bash MONITOR-TEMPO-REAL.sh`
3. Abra outra aba browser
4. Siga GUIA-COMPRA-TESTE-REAL.md
5. Faça a compra

**EU:**
1. Vou monitorar os logs
2. Vou testar APIs
3. Vou estar pronto para debug

---

**VAMO LÁ! Começar agora? 🚀**

Use este documento como referência. O GUIA-COMPRA-TESTE-REAL.md tem o passo-a-passo detalhado com seus dados já preenchidos.
