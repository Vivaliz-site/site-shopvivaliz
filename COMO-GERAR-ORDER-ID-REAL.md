# 📦 Como Gerar um Order ID Real para Validar no Mercado Pago

**Problema:** O Order ID deve ser de um pagamento real feito nos últimos 7 dias

**Solução:** Fazer um checkout real no site de produção

---

## ✅ Passo a Passo

### 1. Acesse o Site de Produção
```
https://dev.shopvivaliz.com.br/checkout
```

### 2. Preencha os Dados (Checkout)
```
Nome: Cliente Teste
Email: cliente@shopvivaliz.com.br
Telefone: (37) 99999-1234
CPF: 123.456.789-01

Endereço:
  CEP: 37000-000
  Rua: Rua Teste
  Número: 123
  Complemento: Apto 001
  Cidade: Divinópolis
```

### 3. Complete o Pedido
- Clique em **"Finalizar Pedido"** ou **"Pagar com Mercado Pago"**
- O Payment Brick será renderizado

### 4. Escolha o Método de Pagamento
- **PIX** (mais rápido para teste)
- **Cartão de Crédito** (usa cartão de teste)
- **Boleto**

### 5. Use Dados de Teste (Sandbox)

**Se escolher Cartão:**
```
Número:    4011 7810 0000 0011
CVV:       123
Validade:  12/30
Nome:      APRO (força aprovação em sandbox)
CPF:       123.456.789-01
```

**Se escolher PIX:**
- Sistema gerará um QR Code
- Em sandbox, é simulado automaticamente

### 6. Completar Pagamento
- Clique em **"Pagar"** ou **"Confirmar"**
- Aguarde processamento (alguns segundos)

### 7. Obter o Order ID

**Após o pagamento ser processado:**

Opção A - No site:
```
1. Acesse: https://dev.shopvivaliz.com.br/admin/orders
2. Procure pelo pedido mais recente
3. O ID estará em: "Payment ID" ou "Mercado Pago ID"
```

Opção B - No Mercado Pago:
```
1. Acesse: https://www.mercadopago.com.br/account/payments
2. Procure o pagamento mais recente
3. O ID numérico longo é o Order ID
```

---

## 💳 Dados de Teste Válidos (Sandbox)

| Método | Dados | Resultado |
|--------|-------|-----------|
| **Cartão Visa** | 4011 7810 0000 0011 | ✅ Aprovado |
| **Cartão Mastercard** | 5031 7510 0000 0011 | ✅ Aprovado |
| **Cartão Elo** | 6363 6810 0000 0011 | ✅ Aprovado |
| **PIX** | Automático | ✅ Instantâneo |

**CVV:** 123 (qualquer 3 dígitos)  
**Validade:** Qualquer data futura  
**CPF:** 123.456.789-01 (qualquer CPF)

---

## 🎯 O Que Esperar

1. ✅ Pagamento processado
2. ✅ Webhook disparado para `/api/webhook-mercadopago.php`
3. ✅ Banco de dados atualizado
4. ✅ Email de confirmação enviado
5. ✅ Order ID válido gerado automaticamente

---

## 🔍 Validar no Mercado Pago

Após o pagamento:

```
1. Acesse: https://www.mercadopago.com.br/developers/
2. Vá em: Suas integrações
3. Selecione: ShopVivaliz
4. Clique: "Testar a integração"
5. Cole o Order ID obtido acima
6. Clique: "Avaliar qualidade"
```

---

## ⚠️ Importante

- O Order ID deve ser gerado nos **últimos 7 dias**
- Deve ser um pagamento **real realizado via API**
- Credenciais usadas devem ser de **TESTE** (sandbox)
- Seu site já está configurado com credenciais válidas

---

**Pronto! Assim você terá um Order ID real e válido! 🚀**
