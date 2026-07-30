# 🌐 ROTEIRO DE TESTES MANUAL - VALIDAÇÃO PELO BROWSER

**Data:** 2026-07-13 23:33 UTC  
**Objetivo:** Validar fluxo completo via browser real  
**Duração:** ~30 minutos  
**Equipamento:** Sua máquina (Windows)  

---

## 🚀 COMEÇAR AGORA

### PASSO 1: Abrir Site e Validar Home (5 MIN)

```
1. Abrir browser (Chrome/Firefox)
2. Navegar para: https://shopvivaliz.com.br/
3. Verificar:
   ✅ Página carrega rapidamente (< 3 segundos)
   ✅ Logo ShopVivaliz visível
   ✅ Menu navegação funcionando
   ✅ Produtos aparecem em grid/lista
   ✅ Busca funciona (digitar "teste")
   ✅ Carrinho vazio (ícone mostra 0)
```

**Se falhar:** 
```
- F12 → Console → verificar erros vermelhos
- Network tab → verificar requests 404
- Refresh page (Ctrl+F5 = cache limpo)
```

---

### PASSO 2: Testar Catálogo (5 MIN)

```
1. Clicar em "Produtos" ou categoria qualquer
2. Verificar:
   ✅ Produtos têm preço
   ✅ Produtos têm foto
   ✅ Produto tem "adicionar ao carrinho"
   ✅ Filtros funcionam (preço, categoria)
3. Clicar em 1 produto aleatório
4. Verificar:
   ✅ Página do produto abre
   ✅ Preço visível
   ✅ Estoque mostrado
   ✅ Descrição carrega
   ✅ Botão "Comprar" ou "Adicionar ao Carrinho" visível
```

**Se falhar:**
- Produtos não têm preço? Problema com Olist sync
- Fotos não carregam? Problema com URLs de imagem
- Filtros não funcionam? Problema de JS

---

### PASSO 3: Testar Carrinho (5 MIN)

```
1. Clicar "Adicionar ao Carrinho" em 1 produto
2. Verificar:
   ✅ Produto adicionado (carrinho mostra 1)
   ✅ Notificação sucesso ("Adicionado ao carrinho")
3. Adicionar MAIS 1 produto
4. Verificar:
   ✅ Carrinho mostra 2 produtos
   ✅ Totais calculados corretamente
5. Clicar no ícone carrinho
6. Verificar página carrinho:
   ✅ Produtos listados
   ✅ Preços individuais mostrados
   ✅ Subtotal calculado
   ✅ Botão "Finalizar Compra" visível
```

**Se falhar:**
- Produto não adiciona? Problema JavaScript
- Total incorreto? Problema cálculo preço

---

### PASSO 4: Testar Checkout com Boleto (10 MIN)

```
1. Na página carrinho, clicar "Finalizar Compra"
2. Preencher dados FICTÍCIOS:
   Nome: Test User
   Email: seu-email@gmail.com (REAL para receber confirmação)
   Telefone: 11999999999
   CPF: 12345678901
   CEP: 01234567
   Endereço: Rua Teste, 123
   Cidade: São Paulo
   Estado: SP
3. Clicar "Próximo" ou "Continuar"
4. Selecionar método pagamento:
   ✅ Boleto apareça como opção
5. Se houver frete:
   ✅ Frete calcular automaticamente
   ✅ Opções de frete listadas
6. Clicar "Gerar Boleto" ou "Confirmar Pedido"
7. Verificar:
   ✅ Número do pedido gerado
   ✅ Número do boleto mostrado
   ✅ Link para boleto disponível
   ✅ Data vencimento mostrada
```

**Se falhar:**
- CEP não reconhece? Problema frete/validação
- Boleto não gera? Problema integração Pagar.me/Tiny
- Erro de validação? Campo obrigatório faltando

---

### PASSO 5: VALIDAÇÃO CRÍTICA - EMAIL CHEGOU? (5 MIN)

```
1. Verificar seu EMAIL (@gmail.com)
2. Procurar por:
   ✅ Email de "ShopVivaliz"
   ✅ Assunto contém "Confirmação de Pedido"
   ✅ Email chegou em < 60 segundos após pedido
3. Dentro do email, verificar:
   ✅ Número do pedido
   ✅ Produtos listados
   ✅ Valor total correto
   ✅ Link para boleto
   ✅ Contato de suporte

SE NÃO CHEGOU:
   ⚠️ CRÍTICO! Verificar:
   - Pasta Spam/Promotions
   - Abrir logs: tail -f logs/email-*.log
   - Testar: curl -X POST https://shopvivaliz.com.br/api/mail/test.php -d "to=seu-email@gmail.com"
```

---

### PASSO 6: VALIDAÇÃO CRÍTICA - PEDIDO NO OLIST/ERP? (5 MIN)

```
1. Fazer login no dashboard Olist/Tiny:
   https://www.olist.com.br/pedidos/
   (ou seu ERP específico)

2. Procurar pelo pedido criado:
   ✅ Número do pedido aparece na listagem
   ✅ Status: "Novo" ou "Pendente Pagamento"
   ✅ Cliente correto
   ✅ Produtos listados corretamente
   ✅ Total correto

SE NÃO APARECER:
   🔴 CRÍTICO! Problema sincronização
   - Verificar: logs/olist-sync.log
   - Token pode estar expirado
   - Executar: php api/olist/refresh-token.php
   - Testar sync manual: curl -X POST https://shopvivaliz.com.br/api/olist/sync-catalog.php
```

---

### PASSO 7: VALIDAÇÃO EXTRA - Shopee (OPCIONAL)

```
Se integração Shopee ativa:
1. Fazer login: https://seller.shopee.com.br/
2. Verificar:
   ✅ Produtos sincronizados lá também
   ✅ Preços iguais ao site
   ✅ Estoque sincronizado
```

---

## 📋 CHECKLIST FINAL

Marque cada item conforme completar:

```
SITE & NAVEGAÇÃO:
  [ ] Homepage carrega rápido
  [ ] Menu funciona
  [ ] Produtos visíveis
  
CATÁLOGO:
  [ ] Produtos têm preço
  [ ] Produtos têm foto
  [ ] Filtros funcionam
  [ ] Página produto carrega
  
CARRINHO:
  [ ] Produto adiciona
  [ ] Carrinho conta atualiza
  [ ] Total calcula correto
  
CHECKOUT:
  [ ] Formulário valida
  [ ] Frete calcula
  [ ] Boleto gera
  [ ] Número pedido criado
  
EMAIL ✅ CRÍTICO:
  [ ] Email chegou em < 60s
  [ ] Email tem número pedido
  [ ] Email tem link boleto
  
OLIST/ERP ✅ CRÍTICO:
  [ ] Pedido aparece no ERP
  [ ] Status correto
  [ ] Totais batem
  
RESULTADO FINAL:
  [ ] TUDO PASSOU = ✅ PRODUÇÃO OK
  [ ] ALGO FALHOU = 🔴 VERIFICAR LOGS
```

---

## 🆘 TROUBLESHOOTING RÁPIDO

### Site não carrega (erro 404/500)
```
→ Verificar: https://shopvivaliz.com.br/admin/health-check.php
→ Se vermelho: problema servidor
→ Logs: /var/log/apache2/error.log na VM Oracle
```

### Produtos sem foto/preço
```
→ Problema: Olist não sincronizou
→ Ação: php scripts/run-autonomy-phases.py (sync manual)
→ Logs: logs/olist-sync.log
```

### Email não chegou
```
→ Verificar SPAM/Promotions
→ Logs: tail -20 logs/email-*.log
→ Testar SMTP: echo "HELO" | nc smtp.gmail.com 587
→ Ação: Verificar credentials Gmail em .env
```

### Pedido não aparece no ERP
```
→ Token Olist pode estar expirado
→ Ação: php api/olist/refresh-token.php
→ Testar: curl -X POST .../api/olist/sync-catalog.php
→ Logs: logs/olist-sync.log
```

---

## ✅ RESULTADO ESPERADO

**Se TODOS os 7 passos passarem:**

```
🎉 PRODUÇÃO VALIDADA E OPERACIONAL
├─ Site carrega rápido
├─ Checkout funciona
├─ Email enviado
├─ Pedido no ERP
└─ Tudo sincronizado ✅
```

**Go-Live Liberado:** 🚀 **SISTEMA PRONTO PARA TRÁFEGO REAL**

---

## 📞 PRÓXIMOS PASSOS

Após completar os testes:

1. **Tudo passou?**
   - ✅ Sistema pronto para produção
   - ✅ Notificar stakeholders
   - ✅ Monitorar próximas 24h

2. **Algo falhou?**
   - 🔴 Abra os logs específicos
   - 🔴 Execute ação troubleshooting
   - 🔴 Reporte problema

---

**Começar testes AGORA:**
1. Abrir https://shopvivaliz.com.br/
2. Seguir PASSO 1-7 acima
3. Completar checklist
4. Reportar resultados

**Tempo estimado:** 30 minutos ⏱️
