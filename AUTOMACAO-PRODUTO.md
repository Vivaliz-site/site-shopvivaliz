# 🤖 Automação de Produto com Make.com + IA

**Status:** 🚀 Iniciando  
**Data:** 2026-07-09  
**Objetivo:** Pipeline completo de captura, enriquecimento e publicação automática de produtos nos 4 marketplaces

---

## 📋 Visão Geral

```
FLUXO:
  Foto + Preço (celular)
    ↓ (salvar em /Novos_Produtos)
  Google Drive (trigger)
    ↓
  Make.com (cenário)
    ├─ Gemini: Extrai dados (EAN, dimensions)
    ├─ Claude: Copywriting (títulos/descrições)
    ├─ ChatGPT: Gera fundo studio (DALL-E)
    └─ Tiny API: Cria SKU com campos customizados
    ↓
  Hub Olist (webhook automático)
    ├─ Mapeamento de campos
    ├─ Publicação em massa
    └─ 4 Marketplaces simultâneos
    ↓
  Monitoramento (cron 7 dias)
    ├─ Sem vendas? Reduz preço
    ├─ CTR baixo? Muda imagem
    └─ Sucesso? Escala produção
```

---

## 🎯 ETAPA 1: Preparação da Infraestrutura

### 1.1 Configurar Tiny ERP

**Objetivo:** Adicionar campos customizados para armazenar conteúdo gerado por IA

**Passos:**

1. **Acessar Tiny**
   ```
   URL: https://app.tiny.com.br/
   Login: seu usuário
   ```

2. **Criar Campos Customizados**
   ```
   Ir para: ⚙️ Configurações > Suprimentos > Campos Customizados
   ```

3. **Adicionar os campos abaixo** (tipo Texto)

   | Campo | Tipo | Tamanho | Descrição |
   |-------|------|--------|-----------|
   | `titulo_meli` | Texto | 60 | Título otimizado para Mercado Livre |
   | `desc_meli` | Texto | 1000 | Descrição otimizada ML |
   | `titulo_shopee` | Texto | 120 | Título otimizado Shopee |
   | `desc_shopee` | Texto | 1000 | Descrição Shopee + emojis |
   | `titulo_amazon` | Texto | 150 | Título estruturado Amazon |
   | `bullet_1` | Texto | 500 | Bullet point 1 |
   | `bullet_2` | Texto | 500 | Bullet point 2 |
   | `bullet_3` | Texto | 500 | Bullet point 3 |
   | `titulo_tiktok` | Texto | 150 | Título viral TikTok |
   | `desc_tiktok` | Texto | 1000 | Descrição TikTok |
   | `ean_gemini` | Texto | 13 | EAN extraído por Gemini |
   | `peso_g` | Número | 5 | Peso em gramas |
   | `altura_cm` | Número | 5 | Altura em cm |
   | `largura_cm` | Número | 5 | Largura em cm |
   | `comprimento_cm` | Número | 5 | Comprimento em cm |
   | `url_bg_chat` | Texto | 500 | URL fundo studio gerado ChatGPT |
   | `status_automacao` | Texto | 50 | pending/processando/publicado/erro |

   **Screenshot esperado:** Tela com lista de campos customizados

4. **Gerar Chave de API**
   ```
   Ir para: ⚙️ Configurações > E-commerce > Integrações
   Encontrar: Sua integração Hub Olist
   Copiar: Chave de API (Token)
   
   Salvar em arquivo seguro:
   API_KEY_TINY=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
   ```

### 1.2 Configurar Hub Olist

**Objetivo:** Mapear campos do Tiny para cada marketplace

**Passos:**

1. **Acessar Hub Olist**
   ```
   URL: https://hub.olist.com.br/
   Login: seu usuário
   ```

2. **Para CADA marketplace** (Mercado Livre, Shopee, Amazon, TikTok):
   
   a. Clique em "Canais Integrados"
   
   b. Selecione o marketplace (ex: Mercado Livre)
   
   c. Vá em "Mapeamento de Campos"
   
   d. **Desmarque** "Espelhar dados globais do ERP"
   
   e. Mapeie assim:
   
   **Mercado Livre:**
   ```
   Título → titulo_meli (campo customizado Tiny)
   Descrição → desc_meli
   EAN → ean_gemini
   Dimensões → altura_cm, largura_cm, comprimento_cm
   Peso → peso_g
   ```
   
   **Shopee:**
   ```
   Título → titulo_shopee
   Descrição → desc_shopee
   Imagem de capa → url_bg_chat
   ```
   
   **Amazon:**
   ```
   Título → titulo_amazon
   Bullet 1 → bullet_1
   Bullet 2 → bullet_2
   Bullet 3 → bullet_3
   EAN → ean_gemini
   ```
   
   **TikTok Shop:**
   ```
   Título → titulo_tiktok
   Descrição → desc_tiktok
   Imagem → url_bg_chat
   ```

3. **Ativar Webhooks**
   ```
   Para cada marketplace:
   ✅ Ativar "Publicar quando SKU criado no Tiny"
   ✅ Ativar "Atualizar quando SKU modificado"
   ```

### 1.3 Criar Pasta no Google Drive

**Objetivo:** Local onde você coloca fotos de produtos para processar

**Passos:**

1. **Criar pasta**
   ```
   Nome: Novos_Produtos
   Localização: /My Drive
   Permissão: Editor para make@make.com (bot do Make)
   ```

2. **Regra operacional**
   ```
   Arquivo = foto_nome_CUSTO.jpg
   
   Exemplo:
   - garrafa_neon_45.jpg (custo R$ 45)
   - luminaria_rgb_120.jpg (custo R$ 120)
   - fone_wireless_25.50.jpg (custo R$ 25,50)
   
   Sistema vai:
   1. Extrair nome + custo
   2. Gerar markdown = custo * 1.5 (markup 50%)
   3. Publicar com preço calculado
   ```

---

## 🤖 ETAPA 2: Configurar Make.com

### 2.1 Criar Conta e Workspace

1. Acessar https://www.make.com
2. Sign up ou login
3. Criar novo "Scenario" (cenário)
4. Nome: `ShopVivaliz Auto-Product`

### 2.2 Montar Fluxo de Módulos

**MÓDULO 1: Google Drive — Watch New Files**

```
Trigger: Quando novo arquivo é criado em /Novos_Produtos/

Configuração:
  Folder ID: [copiar ID da pasta Novos_Produtos]
  Type: Only images
  Scheduled: Sim (a cada 5 minutos)
  
Output:
  - filename (string): "garrafa_neon_45.jpg"
  - id (string): "FILE_ID_XYZ"
  - mimeType (string): "image/jpeg"
  - size (number): 102400
  - createdTime (datetime)
  - webContentLink (string): URL direta
```

---

**MÓDULO 2: Gemini — Generate Content (Multimodal)**

```
Trigger: Recebe output do Módulo 1

Configuração:
  API Key: [sua GEMINI_API_KEY]
  Input File: webContentLink do Módulo 1
  Prompt:
    ---
    Analise esta imagem de produto e extraia RIGOROSAMENTE:
    1. Marca (ou "Genérica" se não identificável)
    2. Modelo exato
    3. Código de barras (EAN-13) se visível, senão deixe vazio
    4. Categoria do produto
    5. Características principais (max 5 bullet points)
    6. Cor/Variações visíveis
    
    RETORNE UM JSON VÁLIDO (nada mais):
    {
      "marca": "string",
      "modelo": "string",
      "ean": "string ou null",
      "categoria": "string",
      "caracteristicas": ["string", "string"],
      "cor": "string",
      "observacoes": "string"
    }
    ---

Output (JSON):
  - marca, modelo, ean, categoria, caracteristicas[], cor, observacoes
```

---

**MÓDULO 3: Claude — Create a Message**

```
Trigger: Recebe output do Módulo 2

Configuração:
  API Key: [sua ANTHROPIC_API_KEY]
  Model: claude-3-5-sonnet
  Temperature: 0.7
  Prompt:
    ---
    Você é copywriter chefe de e-commerce brasileiro.
    
    Com base nesta ficha técnica, gere títulos e descrições otimizados
    para CADA MARKETPLACE (específico, sem genérico).
    
    FICHA:
    - Marca: {{marca}}
    - Modelo: {{modelo}}
    - Categoria: {{categoria}}
    - Características: {{caracteristicas}}
    - Cor: {{cor}}
    
    REQUISITOS:
    
    MERCADO LIVRE:
      Título: máx 60 chars, sem pontuação, keywords SEO
      Descrição: informativo, destaque diferenciais
    
    SHOPEE:
      Título: máx 120 chars, inicie com [ORIGINAL]
      Descrição: use emojis, hashtags, call-to-action
    
    AMAZON:
      Título: estruturado "Marca + Categoria + Modelo", máx 150 chars
      Bullets: 3 frases impactantes (max 500 chars cada)
      Tom: técnico, benefícios do produto
    
    TIKTOK SHOP:
      Título: máx 150 chars, foco em tendência/viral
      Descrição: call-to-action, urgência, Gen Z language
    
    RETORNE APENAS JSON (válido, sem markdown):
    {
      "mercado_livre": {
        "titulo": "string",
        "descricao": "string"
      },
      "shopee": {
        "titulo": "string",
        "descricao": "string"
      },
      "amazon": {
        "titulo": "string",
        "bullets": ["string", "string", "string"]
      },
      "tiktok": {
        "titulo": "string",
        "descricao": "string"
      }
    }
    ---

Output: JSON com títulos/descrições por marketplace
```

---

**MÓDULO 4: ChatGPT — Generate Image (DALL-E 3)**

```
Trigger: Recebe output do Módulo 2

Configuração:
  API Key: [sua OPENAI_API_KEY]
  Model: dall-e-3
  Size: 1024x1024
  Quality: hd
  N: 1
  Prompt:
    ---
    Create a PHOTOREALISTIC, 8K quality, studio product photography
    of a {{categoria}} {{modelo}} in {{cor}}.
    
    REQUIREMENTS:
    - Professional studio lighting (soft key light, fill light)
    - Clean white or marble background
    - Sharp product focus, slight bokeh background
    - Product CENTERED and COMPLETELY EMPTY SPACE around it
    - NO text, NO logos, NO watermarks
    - Natural shadows, professional composition
    - Ready for e-commerce marketplace use
    
    Style: High-end product photography, minimal, clean
    ---

Output:
  - url (string): URL da imagem gerada
  - b64_json (string): Base64 da imagem
  - revised_prompt (string): Prompt que foi usado
```

---

**MÓDULO 5: HTTP Request → Tiny API**

```
Trigger: Recebe outputs dos Módulos 2, 3, 4

Configuração:
  URL: https://tiny.com.br/api/v3/produtos/
  Method: POST
  Auth: Bearer {{API_KEY_TINY}}
  Headers:
    Content-Type: application/json
  
  Body (JSON):
    {
      "nome": "{{marca}} {{modelo}} {{cor}}",
      "descricao_complementar": "Importado automaticamente por IA",
      "sku": "AUTO-{{timestamp}}-{{random}}",
      "estoque": {
        "quantidade": 1
      },
      "preco": {{custo * 1.5}},  # Markup 50%
      "peso_bruto_grama": {{peso_g}},
      "altura": {{altura_cm}},
      "largura": {{largura_cm}},
      "profundidade": {{comprimento_cm}},
      "categoria_pai": "Importados",
      
      "campos_customizados": {
        "titulo_meli": "{{mercado_livre.titulo}}",
        "desc_meli": "{{mercado_livre.descricao}}",
        "titulo_shopee": "{{shopee.titulo}}",
        "desc_shopee": "{{shopee.descricao}}",
        "titulo_amazon": "{{amazon.titulo}}",
        "bullet_1": "{{amazon.bullets[0]}}",
        "bullet_2": "{{amazon.bullets[1]}}",
        "bullet_3": "{{amazon.bullets[2]}}",
        "titulo_tiktok": "{{tiktok.titulo}}",
        "desc_tiktok": "{{tiktok.descricao}}",
        "ean_gemini": "{{ean}}",
        "peso_g": {{peso_g}},
        "altura_cm": {{altura_cm}},
        "largura_cm": {{largura_cm}},
        "comprimento_cm": {{comprimento_cm}},
        "url_bg_chat": "{{dall_e_url}}",
        "status_automacao": "publicado"
      }
    }

Output (sucesso):
  - id (number): Tiny product ID
  - sku (string): SKU gerado
  - status (string): "ativo"
```

---

## 📊 ETAPA 3: Automação Hub Olist

**Objetivo:** Quando Tiny cria SKU, Hub publica automático em 4 marketplaces

**Configuração:**

1. **Webhook Tiny → Hub Olist**
   ```
   Ir em: Tiny > ⚙️ Configurações > Webhooks
   
   Adicionar:
   Event: produto.criado
   URL: https://hub.olist.com.br/api/webhook
   
   Headers:
     Authorization: Bearer {{API_TOKEN_HUB}}
     Content-Type: application/json
   ```

2. **Hub Olist recebe e publica**
   ```
   Evento: novo_produto_criado
   Ação automática:
     ✅ Validar EAN
     ✅ Mapear campos customizados para cada marketplace
     ✅ Publicar em Mercado Livre (titulo_meli + desc_meli)
     ✅ Publicar em Shopee (titulo_shopee + desc_shopee)
     ✅ Publicar em Amazon (titulo_amazon + bullets)
     ✅ Publicar em TikTok Shop (titulo_tiktok + desc_tiktok)
   
   Tempo: <60 segundos do SKU criado até exposto nos marketplaces
   ```

---

## 📈 ETAPA 4: Monitoramento e Otimização

### 4.1 Script de Ajuste de Preço

**Arquivo:** `scripts/auto-price-optimizer.php`

```php
<?php
// Executa a cada 7 dias (cron)
// Verifica SKUs criados pela automação

$query = "
  SELECT 
    id, sku, preco, 
    (SELECT COUNT(*) FROM pedidos WHERE sku = produtos.sku) as vendas
  FROM produtos 
  WHERE status_automacao = 'publicado'
  AND DATE_ADD(created_at, INTERVAL 7 DAY) >= NOW()
";

foreach ($produtos as $produto) {
    if ($produto['vendas'] == 0) {
        // Sem vendas em 7 dias → reduz 10%
        $novo_preco = $produto['preco'] * 0.90;
        atualizarPrecoTiny($produto['id'], $novo_preco);
    }
}
?>
```

### 4.2 Script de A/B de Imagem

**Arquivo:** `scripts/auto-image-ab.php`

```php
<?php
// Se CTR < média da categoria por 3 dias
// Gera nova imagem e alterna

$query = "
  SELECT id, sku, url_bg_chat, ctr, categoria
  FROM produtos 
  WHERE status_automacao = 'publicado'
  AND ctr < (
    SELECT AVG(ctr) FROM produtos WHERE categoria = {categoria}
  )
  AND DATE_ADD(created_at, INTERVAL 3 DAY) >= NOW()
";

foreach ($produtos as $produto) {
    // Chamar DALL-E para gerar nova imagem
    $novaUrl = gerarNovaImagemDalle($produto);
    
    // Atualizar em Tiny + Hub
    atualizarImagemTiny($produto['id'], $novaUrl);
}
?>
```

---

## 🔄 Fluxo Completo (Timeline)

| Tempo | Ação | Sistema |
|-------|------|---------|
| T+0 | Foto salva em Google Drive | Usuário |
| T+5min | Make detecta novo arquivo | Google Drive trigger |
| T+5m30s | Gemini analisa imagem | Gemini API |
| T+6min | Claude gera copywriting | Claude API |
| T+6m30s | ChatGPT gera fundo studio | DALL-E API |
| T+7min | Tiny cria SKU com campos | Tiny API |
| T+8min | Hub Olist detecta novo SKU | Webhook |
| T+9min | Publicado em 4 marketplaces | ML, Shopee, Amazon, TikTok |
| T+30s | Produto está VIVO para vender | Todos os canais |
| **T+7 dias** | Monitoramento: ajusta preço | Auto-optimizer |
| **T+7 dias** | Se CTR baixo: nova imagem | Auto-image-ab |

---

## ✅ Próximos Passos

1. **Hoje:**
   - [ ] Acessar Tiny e criar 14 campos customizados
   - [ ] Copiar API key do Tiny
   - [ ] Acessar Hub Olist e mapear campos para cada marketplace
   
2. **Amanhã:**
   - [ ] Criar conta Make.com (gratuita ou paga)
   - [ ] Criar pasta "Novos_Produtos" no Google Drive
   - [ ] Montar os 5 módulos do cenário
   
3. **Depois de amanhã:**
   - [ ] Testar fluxo: salvar 1 foto fake e rodar cenário
   - [ ] Verificar SKU criado no Tiny
   - [ ] Verificar publicação nos marketplaces
   
4. **Semana 1:**
   - [ ] Ajustar prompts de IA baseado em testes
   - [ ] Configurar scripts de otimização
   - [ ] Documentar resultados

---

## 📞 Suporte

**Erro ao criar campo customizado Tiny:**
- Verif se usuário tem permissão de admin

**Make.com não conecta API Tiny:**
- Copiar API key exatamente (sem espaços)
- Testar em Postman antes

**Hub Olist não publica:**
- Confirmar campos mapeados corretamente
- Verificar se EAN é válido (Gemini pode falhar)

---

## 🚀 Status

**Fase:** INICIANDO  
**Próximo:** Configurar Tiny ERP com usuário

Você quer que eu:
- **A)** Criar scripts Python para automação dos passos 1-3?
- **B)** Criar documentação visual (screenshots esperados)?
- **C)** Ambos?

---

*Desenvolvido por: Claude Code*  
*Última atualização: 2026-07-09*  
*Sistema: ShopVivaliz Auto-Product v1.0*
