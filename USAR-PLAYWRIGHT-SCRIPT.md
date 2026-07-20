# 🎬 USAR SCRIPT PLAYWRIGHT - PASSO A PASSO

**Status:** ✅ Script pronto para executar

---

## 📦 INSTALAÇÃO (Só 1 vez)

### Passo 1: Abrir PowerShell

```powershell
# Abra PowerShell como administrador
# Navegue para a pasta do projeto
cd C:\site-shopvivaliz
```

### Passo 2: Instalar Playwright

```powershell
# Instalar o pacote Python
pip install playwright

# Instalar navegadores (Chromium, Firefox, WebKit)
playwright install
```

⏱️ Isso leva ~2-3 minutos na primeira vez (baixa ~200MB)

---

## 🚀 EXECUTAR O SCRIPT

### Comando Simples

```powershell
cd C:\site-shopvivaliz
python automate-purchase.py
```

Pronto! O script vai:
1. ✅ Abrir navegador (você verá tudo)
2. ✅ Ir para o site
3. ✅ Adicionar produto ao carrinho
4. ✅ Preencher seus dados (Frederico de Castro Mourao)
5. ✅ Selecionar BOLETO
6. ✅ Gerar boleto
7. ✅ Tirar screenshots de cada passo

---

## 📊 O QUE ESPERAR

### Durante a execução:

```
Terminal vai mostrar:
  [2026-07-13 14:35:00] [INFO] 🛒 INICIANDO AUTOMAÇÃO DE COMPRA
  [2026-07-13 14:35:00] [INFO] Cliente: Frederico de Castro Mourao
  [2026-07-13 14:35:02] [INFO] 📱 Abrindo navegador...
  [2026-07-13 14:35:05] [INFO] ✅ Produtos detectados
  [2026-07-13 14:35:10] [INFO] ✅ Produto selecionado
  [2026-07-13 14:35:15] [INFO] ✅ Clicou no carrinho
  [2026-07-13 14:35:20] [INFO] 📋 Capturando dados do pedido...
  [2026-07-13 14:35:25] [INFO] ✅ COMPRA REALIZADA COM SUCESSO!
  ...
```

### Navegador vai aparecer:

- Abrirá automaticamente
- Você **VERÁ TUDO** acontecendo em tempo real
- Se precisar intervir, ele vai fazer pause
- Ao final, o navegador fica aberto para você verificar

---

## 📂 ARQUIVOS GERADOS

Após executar, você terá:

```
/logs/purchase-automation.log
  └─ Log detalhado de cada passo

/logs/purchase-result.json
  └─ Resultado final em JSON (número do pedido, etc)

/screenshots-purchase/
  ├─ 01-homepage.png
  ├─ 02-product-page.png
  ├─ 03-added-to-cart.png
  ├─ 04-cart-page.png
  ├─ 05-form-filled.png
  ├─ 06-payment-page.png
  └─ 07-boleto-gerado.png
```

---

## ✅ CHECKLIST ANTES DE EXECUTAR

- [x] Python 3.7+ instalado? (`python --version`)
- [x] Playwright instalado? (`pip install playwright`)
- [x] Navegadores instalados? (`playwright install`)
- [x] Você está em C:\site-shopvivaliz?
- [x] Internet funcionando?
- [x] Dados corretos no script? (Frederico, CPF, email)

Se tudo OK → execute:

```powershell
python automate-purchase.py
```

---

## 🆘 SE DER ERRO

### Erro: "playwright not found"

```powershell
pip install playwright
playwright install
python automate-purchase.py
```

### Erro: "Python not found"

```powershell
# Verificar se Python está instalado
python --version

# Se não estiver:
# Baixe em: https://www.python.org/downloads/
# Instale (marque "Add Python to PATH")
```

### Erro: "Site não carrega"

```
Possível causa: site offline ou DNS lento
Solução: Verificar em navegador manualmente
         Testar: curl https://shopvivaliz.com.br/
```

### Erro: "Não encontra elementos"

```
Possível causa: HTML do site é diferente do esperado
Solução: Script vai fazer pause para você intervir manualmente
         Completa a compra na mão
```

---

## 📋 APÓS A COMPRA (O IMPORTANTE)

Após o script terminar, você DEVE:

### 1️⃣ Verificar Email (5 MIN)

```
1. Abra: https://mail.google.com/
2. Login: fredmourao@gmail.com
3. Procure por email de ShopVivaliz
4. Verifique:
   ✅ Email chegou?
   ✅ Tempo de chegada?
   ✅ Número pedido está lá?
   ✅ Link boleto incluído?
```

Se email não chegou → **CRÍTICO**, vou debugar SMTP

### 2️⃣ Verificar Olist/ERP (5 MIN)

```
1. Abra: https://www.olist.com.br/pedidos/
2. Faça login
3. Procure novo pedido
4. Verifique:
   ✅ Pedido apareceu?
   ✅ Nome está "Frederico"?
   ✅ Status correto?
   ✅ Valores batem?
```

Se pedido não apareceu → **CRÍTICO**, vou debugar Olist sync

### 3️⃣ Reportar Resultado

```
Mande mensagem com:
  ✅ Email chegou? SIM/NÃO (tempo em segundos)
  ✅ Pedido no ERP? SIM/NÃO
  ✅ Número do pedido (se conseguir)
  ✅ Qualquer erro que viu
```

---

## 🎯 FLUXO COMPLETO

```
14:35 - Você executa: python automate-purchase.py
14:35 - Navegador abre automaticamente
14:37 - Script preenche dados (Frederico, CPF, etc)
14:38 - Script seleciona BOLETO
14:39 - Script gera boleto
14:39 - Navegador fica aberto (você verifica)
14:40 - Você verifica email (deve ter chegado)
14:45 - Você verifica Olist/ERP (procura pedido)
14:50 - Você me reporta resultado

TOTAL: ~15 minutos
```

---

## 📊 RESULTADO ESPERADO

### ✅ SE TUDO FUNCIONAR:

```
Terminal:
  ✅ COMPRA REALIZADA COM SUCESSO!
  
Email:
  ✅ Confirmação chegou
  
Olist:
  ✅ Pedido apareceu

Conclusão:
  🚀 PRODUÇÃO LIBERADA!
```

### 🔴 SE ALGO FALHAR:

```
Terminal vai mostrar qual passo falhou

Se foi Email:
  → Vou verificar logs SMTP
  → Diagnosticar credenciais Gmail
  → Testar conexão
  
Se foi Olist:
  → Vou verificar token
  → Renovar se expirado
  → Testar sync manual
```

---

## 💡 DICAS ÚTEIS

### Script Pausou? 

```
Se você vir "página pausada", significa que o script não conseguiu fazer algo.
Você pode:
1. Intervir manualmente (preencher campo, clicar botão)
2. Pressionar Enter para continuar
3. Ou Ctrl+C para cancelar
```

### Quer Ver Logs em Tempo Real?

```powershell
# Em outra terminal (PowerShell):
Get-Content -Path "logs/purchase-automation.log" -Wait -Tail 50
```

### Quer Debugar Elemento?

```
Se não conseguiu preencher um campo:
1. Script faz pause
2. Pressione F12 (developer tools)
3. Use inspector para encontrar o nome do campo
4. Me avise e eu ajusto o script
```

---

## 🚀 COMEÇAR AGORA

### Copiar e colar no PowerShell:

```powershell
cd C:\site-shopvivaliz
pip install playwright
playwright install
python automate-purchase.py
```

**3 comandos, é isso!**

---

## 📞 PRÓXIMAS AÇÕES

1. **Execute o script** (comandos acima)
2. **Verifique email** (fredmourao@gmail.com)
3. **Verifique Olist** (procure novo pedido)
4. **Me reporte resultado:**
   - Email chegou? SIM/NÃO
   - Pedido no ERP? SIM/NÃO
   - Número do pedido (se tiver)

---

**VAMO! Começar agora?** 🚀

```powershell
cd C:\site-shopvivaliz && python automate-purchase.py
```
