# 🧪 PADRÃO DE TESTES - ShopVivaliz

**Versão:** 1.0  
**Data:** 2026-07-14  
**Aplicável a:** TODOS os testes E2E

---

## 🎯 REGRA FUNDAMENTAL

### ❌ NÃO FAZER
```python
# Ruim: Navegar direto via URL
await page.goto(f"{BASE_URL}/checkout/")
await page.goto(f"{BASE_URL}/admin/pedidos.php")
```

### ✅ FAZER
```python
# Bom: Clicar em botões e links reais
await page.click("a:has-text('Checkout')")
await page.click("#checkout-submit")
await page.click("a[href='/admin/pedidos.php']")
```

---

## 📋 PADRÃO PARA TODOS OS TESTES

Cada teste deve seguir este ciclo:

```
1. NAVEGAR para página base
   await page.goto(f"{BASE_URL}/checkout/")

2. PREENCHER formulários
   await page.fill("#nome", "Teste")
   await page.fill("#email", "teste@example.com")

3. CLICAR em botões
   await page.click("#submit-button")

4. AGUARDAR resultado
   await page.wait_for_selector(".success-message")

5. VALIDAR resposta visual
   content = await page.content()
   assert "Sucesso" in content

6. VERIFICAR BD/Email (se aplicável)
   # Query no BD ou checar email
```

---

## 📝 TEMPLATE DE TESTE

```python
async def test_feature_name():
    """
    Teste de [Feature]
    
    REGRA: Sempre clicar em botões, nunca navegar URL direto
    """
    
    async with async_playwright() as p:
        browser = await p.chromium.launch()
        page = await browser.new_page()
        
        try:
            # 1. NAVEGAR
            await page.goto(f"{BASE_URL}/checkout/")
            
            # 2. PREENCHER
            await page.fill("#field1", "value1")
            await page.fill("#field2", "value2")
            
            # 3. CLICAR (IMPORTANTE!)
            await page.click("#botao-acao")
            
            # 4. AGUARDAR
            await page.wait_for_selector(".resultado", timeout=5000)
            
            # 5. VALIDAR
            content = await page.content()
            assert "Esperado" in content
            
            print("✅ PASSOU")
            
        except Exception as e:
            print(f"❌ FALHOU: {e}")
            
        finally:
            await browser.close()
```

---

## ✅ CHECKLIST ANTES DE FAZER QUALQUER TESTE

- [ ] Usa Playwright (não curl)
- [ ] Simula ações reais de usuário
- [ ] Clica em botões (não navega URL)
- [ ] Preenche formulários
- [ ] Aguarda elementos carregar
- [ ] Valida resultado visual
- [ ] Trata erros de timeout
- [ ] Testa em browser headless
- [ ] Testa mobile (viewport 375x812)

---

## 🔄 TESTES QUE JÁ EXISTEM

### test-playwright-e2e.py
✓ CEP preenche endereço (clica em campo)
✓ Seleciona transportadora (clica em opção)
✓ Clica em Mercado Pago
✓ Clica em menu admin
✓ Clica em painel pedidos
✓ Clica em painel produtos

### test-full-e2e.php
- Verifica arquivos e estrutura
- Testa BD direto
- Testa security

---

## 📊 MÉTRICAS DE TESTE

Cada teste deve reportar:

| Métrica | Descrição |
|---------|-----------|
| Taxa de sucesso | % de testes que passaram |
| Tempo total | Quanto levou todo o teste |
| Timeout | Se houve timeout em alguma ação |
| Browser | Qual browser foi usado |
| Viewport | Qual tamanho de tela (mobile/desktop) |

---

## 🚨 CASOS DE USO COMUM

### Testar Checkout
```python
# Navegar
await page.goto(f"{BASE_URL}/checkout/")

# Preencher CEP
await page.fill("#cep", "01310100")

# Esperar ViaCEP buscar
await page.wait_for_function(
    "document.getElementById('endereco').value !== ''",
    timeout=5000
)

# Selecionar transportadora (CLICAR, não navegar)
await page.click('input[name="shipping_option"]:nth-child(1)')

# Clicar em Mercado Pago
await page.click("#checkout-mp-btn")

# Validar
assert "Pedido criado" in await page.content()
```

### Testar Admin
```python
# Ir para menu
await page.goto(f"{BASE_URL}/admin/menu-completo.php")

# Clicar em Pedidos
await page.click("a:has-text('Pedidos')")

# Validar que carregou
await page.wait_for_url("**/admin/pedidos.php")

# Verificar tabela
assert "Pedido" in await page.content()
```

### Testar Formulário
```python
# Preencher
await page.fill("#nome", "Teste")
await page.fill("#email", "teste@example.com")

# CLICAR no botão, não dar Enter
await page.click("#submit")

# Aguardar
await page.wait_for_selector(".success", timeout=5000)
```

---

## 🔍 DEBUGGING

Se um teste falhar:

```python
# 1. Tirar screenshot
await page.screenshot(path="debug.png")

# 2. Aguardar mais tempo
await page.wait_for_selector(selector, timeout=15000)

# 3. Ver HTML
print(await page.content())

# 4. Ver erros console
await page.on("console", lambda msg: print(msg))
```

---

## 📋 PRÓXIMAS AÇÕES

- [ ] Todos os testes devem usar Playwright
- [ ] Todos os testes devem CLICAR em botões
- [ ] Todos os testes devem simular usuário real
- [ ] CI/CD deve rodar testes antes de merge
- [ ] Falha em teste = bloqueia PR

---

**LEMBRETE:** Um teste que navega direto via URL não é um teste de verdade. 
Um teste REAL simula o que um usuário faz: clica, preenche, aguarda.

