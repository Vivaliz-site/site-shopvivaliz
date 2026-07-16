# 🚀 INSTRUÇÕES PARA AGENTES - TESTES AUTÔNOMOS PRÉ-PRODUÇÃO

**Autorização**: Vocês possuem FULL AUTONOMY para tomar decisões, corrigir bugs, fazer commits

**Objetivo**: ShopVivaliz pronto para produção com ZERO bugs conhecidos

**Timeline**: 24-48 horas para testes + correções completas

---

## 📋 CHECKLIST DE TESTES (Faça TODOS)

### 1️⃣ FRETE / SHIPPING (CRÍTICO ⚠️)
**Status**: Não estava funcionando - INVESTIGAR + CORRIGIR

```
[ ] Verificar cálculo de frete
[ ] Testar integração Melhor Envio
[ ] Testar fallback (se API cair)
[ ] Testar free shipping
[ ] Testar múltiplas regiões
[ ] Testar peso/dimensões
[ ] Verificar atualização de frete em tempo real
```

**Responsável**: Claude/Gemini  
**Ação**: Encontre o bug, corrija, teste, commite

---

### 2️⃣ FLUXO DE PEDIDO (CRÍTICO ⚠️)
**Testar ponta a ponta**: Produto → Carrinho → Checkout → Pagamento → Confirmação

```
[ ] Produto existe e exibe
[ ] Adicionar ao carrinho
[ ] Remover do carrinho
[ ] Atualizar quantidade
[ ] Carrinho persiste (refresh)
[ ] Checkout carrega
[ ] Validação de form
[ ] Endereço/CEP válido
[ ] Frete carrega
[ ] Pagamento aparece
[ ] Pedido criado no DB
[ ] Email confirmação enviado
[ ] Página de obrigado funciona
```

**Responsável**: Claude/GPT  
**Ação**: Teste cada etapa, reporte bugs, corrija

---

### 3️⃣ ESTOQUE / INVENTORY (CRÍTICO ⚠️)
**Status**: Deve atualizar após compra - VERIFICAR

```
[ ] Estoque exibe na página de produto
[ ] Produto out-of-stock desabilita
[ ] Estoque atualiza após compra
[ ] Não permite overselling
[ ] Sync Olist → Local funciona
[ ] Sync Shopee → Local funciona
[ ] Stock history logs mudanças
[ ] Alerta de estoque baixo
```

**Responsável**: Gemini  
**Ação**: Verifique cada ponto, corrija banco de dados se necessário

---

### 4️⃣ PAGAMENTOS (CRÍTICO ⚠️)
**Testar todas as formas**: PIX, CC, WhatsApp, Transferência

```
[ ] PIX - QR Code gera corretamente
[ ] PIX - PIX key está correto (.env)
[ ] Credit Card - Pagar.me conectado
[ ] Credit Card - Simulação de compra
[ ] WhatsApp - Link funciona
[ ] Bank Transfer - Dados corretos
[ ] Payment Webhook - Recebido
[ ] Order Status - Atualiza após pagamento
[ ] Refund - Implementado
[ ] Failed Payment - Erro handling
```

**Responsável**: GPT  
**Ação**: Teste cada método, simule compras, verifique status

---

### 5️⃣ DADOS E BANCO DE DADOS
```
[ ] Banco conecta sem erro
[ ] Tabelas existem (orders, products, users)
[ ] Relacionamentos corretos
[ ] Foreign keys funcionam
[ ] Índices estão otimizados
[ ] Backup está funcionando
[ ] Logs registram tudo
```

**Responsável**: Claude  
**Ação**: Verifique schema, rodar migrations se necessário

---

### 6️⃣ PERFORMANCE E SEGURANÇA
```
[ ] Página home carrega em < 2s
[ ] Produto page carrega em < 2s
[ ] Checkout carrega em < 1s
[ ] SQL Injection - não há vulnerabilidade
[ ] XSS - inputs sanitizados
[ ] CSRF tokens presentes
[ ] Senhas hasheadas (bcrypt)
[ ] APIs autenticadas
[ ] Rate limiting implementado
```

**Responsável**: Claude  
**Ação**: Teste performance, audit segurança

---

## 🔧 COMO AGIR

### Se encontrar um BUG:
```
1. Documente o bug (reprodução exata)
2. Encontre a causa no código
3. Corrija o código
4. Teste a correção
5. Commite com mensagem clara
6. Notifique os outros agentes
```

### Se encontrar uma FEATURE faltando:
```
1. Analise se é crítico para produção
2. Se SIM → Implemente
3. Se NÃO → Documente para depois
4. Commite ou documente
```

### Se tiver DÚVIDA:
```
1. Pesquise no código existente
2. Teste comportamento esperado
3. Se ainda incerto → Implemente "forma segura"
4. Documente a decisão
```

---

## 📊 REPORT TEMPLATE

Quando achar issue, crie um arquivo:
`ISSUE-[NUMERO]-[TITULO].md`

```markdown
# Issue #001 - Frete não carrega

## Reprodução
1. Ir para checkout
2. Verificar campo de frete
3. Erro: frete não aparece

## Causa
Arquivo: `/api/shipping.php` linha 45
Problema: API key vazia

## Solução
Adicionar MELHOR_ENVIO_API_KEY ao .env

## Status
[ ] Fixed
[ ] Tested
[ ] Committed
```

---

## ✅ DEFINIÇÃO DE PRONTO

Pode ir para produção quando:
- ✅ Frete funciona em 100% dos casos
- ✅ Pedido completo (início ao fim) funciona
- ✅ Estoque atualiza corretamente
- ✅ Pagamentos processam e confirmam
- ✅ Zero SQL errors
- ✅ Zero XSS/CSRF vulnerabilities
- ✅ Performance < 2s em todas as páginas

---

## 🚨 PRIORIDADES

### HOJE (0-8h):
1. Frete - DEVE FUNCIONAR
2. Checkout ponta a ponta
3. Estoque updates

### AMANHÃ (8-24h):
1. Pagamentos completos
2. Performance tunning
3. Segurança audit

### DEPOIS (24-48h):
1. Nice-to-haves
2. Otimizações
3. Documentação

---

## 👥 DIVISÃO DE TRABALHO SUGERIDA

| Agente | Responsabilidade | Prioridade |
|--------|-----------------|-----------|
| **Claude** | Frete + DB + Performance | 🔴 CRÍTICO |
| **Gemini** | Estoque + Inventory | 🔴 CRÍTICO |
| **GPT** | Pagamentos + Confirmação | 🔴 CRÍTICO |
| **Outros** | Validações + Polish | 🟡 ALTA |

---

## 📞 COMUNICAÇÃO

- 🔴 BUG CRÍTICO → Commita e notifique IMEDIATAMENTE
- 🟡 BUG ALTO → Corrija e documente
- 🟢 BUG BAIXO → Documente e continue testando

---

## 🎯 OBJETIVO

**Não é perfeição** - é ter CONFIANÇA que funciona sem quebrar.

Se algo que você não entende pode quebrar = INVESTIGAR E CORRIGIR.

Se algo funciona mas poderia ser melhor = Documente para depois.

---

**Status**: 🟢 GO FOR TESTING  
**Começar**: AGORA  
**Terminar**: 48h máximo  
**Produção**: Depois que tudo passar  

🚀 **LET'S GO!** 🚀

