# ✅ Checklist de Testes — Automação de Produto

**Data:** 2026-07-09  
**Objetivo:** Validar que o pipeline completo funciona de ponta-a-ponta  
**Duração Estimada:** 2-3 horas  
**Status:** Para executar após setup completo (Fase 1 + 2)

---

## 🎯 Estrutura de Testes

```
FASE 1: Setup Validated ✓
  └─ Tiny ERP campos criados ✓
  └─ Hub Olist mapeado ✓
  └─ APIs configuradas ✓

FASE 2: Workflow Testado ✓
  └─ Módulos Make funcionam ✓
  └─ Cada módulo retorna dados corretos ✓

FASE 3: Este Checklist
  └─ Teste completo fim-a-fim
  └─ Validar publicação nos 4 marketplaces
  └─ Verificar automação de preço e imagem
```

---

## 📋 PRÉ-REQUISITOS ANTES DOS TESTES

Antes de começar, certifique-se de:

- [ ] Tiny ERP online e accessível
- [ ] Hub Olist sincronizado com Tiny
- [ ] Make.com scenario criado com 5 módulos
- [ ] Google Drive pasta `/Novos_Produtos/` criada
- [ ] Contas ativas em 4 marketplaces
- [ ] Todas as APIs validadas:
  ```bash
  php scripts/validate-automation-setup.php
  ```
  Deve retornar: `✅ TODAS AS CREDENCIAIS CONFIGURADAS!`

---

## 🧪 TESTE 1: Fluxo Completo (Foto → SKU → Marketplaces)

**Objetivo:** Validar que uma foto vai de Google Drive até publicação nos 4 marketplaces

**Duração:** 20-30 minutos

### Etapa 1: Preparar Imagem de Teste

1. **Baixe uma imagem de teste** (ou use qualquer foto de produto):
   - Formato: JPEG ou PNG
   - Tamanho: 200KB-2MB
   - Conteúdo: Produto claro, bem iluminado

2. **Renomeie a imagem:**
   ```
   teste_produto_50.jpg
   (número = custo em R$ para calcular markup)
   ```

3. **Salve em Google Drive:**
   ```
   Pasta: /Novos_Produtos/
   Arquivo: teste_produto_50.jpg
   ```

### Etapa 2: Executar Make.com Scenario

1. **Acesse:** https://www.make.com/
2. **Abra o scenario:** `ShopVivaliz Auto-Product v1`
3. **Clique em:** "Run scenario" (botão ▶)
4. **Aguarde:** 3-5 minutos para conclusão

### Etapa 3: Validar Cada Etapa

**Validação em Tempo Real (Make.com):**

Dentro do Make, você verá o fluxo sendo executado:

1. **Módulo 1 — Google Drive:**
   - [ ] Detectou arquivo: `teste_produto_50.jpg`
   - [ ] Output: `filename`, `webContentLink`
   - **Print screen:** Copiar link da saída

2. **Módulo 2 — Gemini:**
   - [ ] Analisou imagem corretamente
   - [ ] Output JSON válido com: `marca`, `modelo`, `ean`, `categoria`, `caracteristicas`
   - [ ] Se EAN vazio, OK (nem todas imagens têm código visível)
   - **Print screen:** Copiar JSON de saída

3. **Módulo 3 — Claude:**
   - [ ] Gerou 4 copywritings (ML, Shopee, Amazon, TikTok)
   - [ ] Títulos têm tamanho correto:
     - ML: ~60 chars
     - Shopee: ~120 chars, começa com [ORIGINAL]
     - Amazon: ~150 chars estruturado
     - TikTok: ~150 chars viral
   - [ ] Descrições com tom apropriado
   - **Print screen:** Copiar JSON de saída

4. **Módulo 4 — DALL-E:**
   - [ ] Gerou URL de imagem
   - [ ] URL começa com: `https://`
   - [ ] Tente abrir a URL em navegador (deve mostrar imagem)
   - **Print screen:** URL da imagem

5. **Módulo 5 — Tiny API:**
   - [ ] Retornou HTTP 200 ou 201 (sucesso)
   - [ ] Response contém: `id`, `sku`
   - [ ] SKU segue padrão: `AUTO-TIMESTAMP-RANDOM`
   - **Print screen:** Response JSON com ID do produto

### Etapa 4: Validar no Tiny ERP

1. **Acesse:** https://app.tiny.com.br/
2. **Vá em:** Produtos → Listar Produtos
3. **Procure por:** `AUTO-` (filtre por SKU)
4. **Abra o produto criado** e valide:

   - [ ] Nome preenchido corretamente
   - [ ] SKU com padrão AUTO
   - [ ] Preço calculado: 50 × 1.5 = R$ 75.00
   - [ ] Estoque: 1 unidade
   - [ ] Peso/Dimensões: preenchidos (ou vazios se não extraído)

5. **Verifique CAMPOS CUSTOMIZADOS:**

   Clique em "Editar" e desça até os campos customizados:

   ```
   ✅ titulo_meli: "Produto Teste Mercado Livre"
   ✅ desc_meli: "Descrição teste ML..."
   ✅ titulo_shopee: "[ORIGINAL] Produto Teste Shopee"
   ✅ desc_shopee: "Descrição com emojis..."
   ✅ titulo_amazon: "Brand Category Model..."
   ✅ bullet_1: "Benefício 1"
   ✅ bullet_2: "Benefício 2"
   ✅ bullet_3: "Benefício 3"
   ✅ titulo_tiktok: "Trending viral title"
   ✅ desc_tiktok: "Gen Z content..."
   ✅ ean_gemini: (vazio é OK)
   ✅ peso_g: 100 (se detectado)
   ✅ url_bg_chat: "https://..."
   ✅ status_automacao: "publicado"
   ```

   **Print screen:** Tela com campos preenchidos

### Etapa 5: Validar no Hub Olist

1. **Acesse:** https://hub.olist.com.br/
2. **Procure por:** Logs/Histórico ou Sincronizações
3. **Procure pelo SKU** criado (ex: `AUTO-1720570000-XYZ`)
4. **Verifique status:**
   - [ ] Sincronização com sucesso
   - [ ] Data: agora (últimos 5 minutos)
   - [ ] Status: "Publicado" ou "Sincronizado"

**Print screen:** Log de sincronização bem-sucedida

### Etapa 6: Validar nos 4 Marketplaces (Aguardar 15-30min)

Aguarde 15-30 minutos e verifique em cada plataforma:

**Mercado Livre:**
1. Acesse: https://www.mercadolivre.com.br/
2. Vá em: Minhas Vendas → Meus Produtos
3. Procure pelo produto (pode estar como "Rascunho" ou "Ativo")
4. [ ] Título correto (tipo ML)
5. [ ] Descrição preenchida
6. [ ] EAN (se tinha)
7. [ ] Imagem fundo studio

**Print screen:** Produto no ML

---

**Shopee:**
1. Acesse: https://shopee.com.br/
2. Vá em: Minha Loja → Produtos
3. Procure pelo produto
4. [ ] Título com [ORIGINAL]
5. [ ] Descrição com emojis
6. [ ] Imagem fundo studio como capa

**Print screen:** Produto no Shopee

---

**Amazon:**
1. Acesse: https://seller-br.amazon.com/
2. Vá em: Inventário → Gerenciar Inventário
3. Procure pelo produto
4. [ ] Título estruturado (Marca Categoria Modelo)
5. [ ] 3 bullet points preenchidos
6. [ ] EAN (se tinha)

**Print screen:** Produto na Amazon

---

**TikTok Shop:**
1. Acesse: https://seller.tiktokshop.com/ (seller center)
2. Vá em: Products → My Products
3. Procure pelo produto
4. [ ] Título viral preenchido
5. [ ] Descrição com Gen Z language
6. [ ] Imagem fundo studio

**Print screen:** Produto no TikTok

---

### Resultado Final do Teste 1:

```
┌─────────────────────────────────────┐
│  TESTE 1: Fluxo Completo             │
│                                      │
│ Google Drive ✅ (detectou foto)      │
│ Gemini ✅ (analisou)                 │
│ Claude ✅ (copywriting)              │
│ DALL-E ✅ (imagem)                   │
│ Tiny API ✅ (criou SKU)              │
│ Hub Olist ✅ (sincronizou)           │
│ Mercado Livre ✅ (publicado)         │
│ Shopee ✅ (publicado)                │
│ Amazon ✅ (publicado)                │
│ TikTok ✅ (publicado)                │
│                                      │
│ ✅ TESTE PASSOU!                    │
└─────────────────────────────────────┘
```

---

## 🧪 TESTE 2: Ajuste de Preço Automático (7 dias)

**Objetivo:** Validar que produtos sem vendas têm preço reduzido em 10%

**Duração:** 7-8 dias (não é prático fazer tudo hoje)

**Setup:**

1. **Criar script:** `scripts/auto-price-optimizer.php`
2. **Agendar cron:** A cada 7 dias no 1º dia da semana

**Verificação (após 7 dias):**

- [ ] Produto criado há 7 dias SEM vendas
- [ ] Preço reduzido automaticamente em 10%
- [ ] Verificar no Tiny: preço antigo vs novo
- [ ] Verificar nos marketplaces: preço atualizado

---

## 🧪 TESTE 3: A/B de Imagem (3-7 dias)

**Objetivo:** Validar que CTR baixo dispara geração de nova imagem

**Duração:** 3-7 dias (não é prático fazer tudo hoje)

**Setup:**

1. **Criar script:** `scripts/auto-image-ab.php`
2. **Agendar cron:** A cada 3 dias

**Verificação (após 3+ dias):**

- [ ] Produto com CTR < média da categoria
- [ ] Script gera nova imagem (via DALL-E)
- [ ] Nova URL salva em `url_bg_chat`
- [ ] Imagem atualizada nos marketplaces

---

## 🧪 TESTE 4: Simulação de Pedido (Analytics)

**Objetivo:** Validar rastreamento de conversão A/B

**Duração:** 30 minutos

**Procedimento:**

1. **No Tiny ERP:**
   - [ ] Criar pedido manual com o produto criado
   - [ ] Salvar e confirmar

2. **No Hub Olist:**
   - [ ] Verificar se pedido sincronizou corretamente
   - [ ] Conferir estoque reduzido em 1

3. **Nos Marketplaces:**
   - [ ] Verificar se pedido aparece (pode não aparecer no dashboard de teste)

4. **Analytics:**
   - [ ] Registrar: qual marketplace gerou a venda
   - [ ] Registrar: qual variante de imagem foi usada
   - [ ] Comparar com outras versões

---

## 📊 RESUMO DE TESTES

### Checklist Simplificado

- [ ] **Teste 1:** Fluxo fim-a-fim (foto → 4 marketplaces)
  - Tempo: 20-30 min
  - Status: ✅ PASSOU / ❌ FALHOU
  
- [ ] **Teste 2:** Preço automático (após 7 dias)
  - Tempo: 7 dias
  - Status: ✅ PASSOU / ❌ FALHOU
  
- [ ] **Teste 3:** A/B imagem (após 3 dias)
  - Tempo: 3 dias
  - Status: ✅ PASSOU / ❌ FALHOU
  
- [ ] **Teste 4:** Simulação de pedido
  - Tempo: 30 min
  - Status: ✅ PASSOU / ❌ FALHOU

---

## 🔍 VALIDAÇÕES CRÍTICAS

### Antes de Considerar "Pronto para Produção"

Todos os itens abaixo devem estar ✅:

1. **Infra Structure:**
   - [ ] 17 campos customizados criados no Tiny
   - [ ] Hub Olist sincronizado com todas APIs
   - [ ] Google Drive pasta `/Novos_Produtos/` acessível
   - [ ] Make.com scenario com 5 módulos funcionando

2. **Integrações:**
   - [ ] Gemini reconhece produtos em imagens
   - [ ] Claude gera copywriting com 4 variações
   - [ ] DALL-E cria imagens realistas
   - [ ] Tiny API cria SKUs com campos preenchidos
   - [ ] Hub Olist publica nos 4 marketplaces

3. **Dados:**
   - [ ] Títulos respeitar limites de caracteres
   - [ ] Descrições com tom/emojis corretos por plataforma
   - [ ] Imagens aparecem nos marketplaces
   - [ ] EAN validado (se presente)

4. **Performance:**
   - [ ] Foto → SKU em < 5 minutos
   - [ ] SKU → Marketplace em < 15 minutos
   - [ ] Sem erros críticos em logs

5. **Automação:**
   - [ ] Preço reduz após 7 dias sem venda
   - [ ] Nova imagem gerada se CTR baixo
   - [ ] Sincronização funciona continuamente

---

## 📞 PRÓXIMOS PASSOS APÓS TESTES

✅ **Se TODOS os testes passarem:**

1. [ ] Documentar prints screens
2. [ ] Criar manual operacional (como adicionar novos produtos)
3. [ ] Configurar monitoramento 24/7
4. [ ] Agendar cron jobs para auto-optimizer
5. [ ] Publicar em produção

❌ **Se algum teste FALHAR:**

1. [ ] Documentar exatamente qual falhou
2. [ ] Reproduzir erro (screenshot/logs)
3. [ ] Consultar `automation-troubleshoot.md`
4. [ ] Ajustar configuração conforme necessário
5. [ ] Re-testar

---

## 📋 MODELO DE RELATÓRIO DE TESTES

Use este template para documentar os testes:

```
RELATÓRIO DE TESTES — AUTOMAÇÃO DE PRODUTO
Data: 2026-07-XX
Testador: [Seu Nome]

TESTE 1: Fluxo Completo
├─ Foto enviada: teste_produto_50.jpg ✅
├─ Gemini analisou: ✅ / ❌ (descrever se falhou)
├─ Claude escreveu: ✅ / ❌
├─ DALL-E gerou: ✅ / ❌
├─ Tiny criou SKU: ✅ / ❌ (ID: AUTO-XXXX)
├─ Hub publicou ML: ✅ / ❌ (aguardou 15 min)
├─ Hub publicou Shopee: ✅ / ❌
├─ Hub publicou Amazon: ✅ / ❌
└─ Hub publicou TikTok: ✅ / ❌

Resultado: ✅ PASSOU / ⚠️ PARCIAL / ❌ FALHOU

Observações: [descrever qualquer problema]
Prints screen: [listar arquivos capturados]
```

---

**Checklist criado por:** Claude Code  
**Data:** 2026-07-09  
**Status:** Pronto para execução
