# 🤖 Guia Completo: Montar Workflow no Make.com (5 Módulos)

**Data:** 2026-07-09  
**Objetivo:** Criar cenário completo que transforma foto → dados → conteúdo IA → SKU publicado  
**Duração Esperada:** 3-4 horas  
**Custo Estimado:** $1-5 (principalmente DALL-E)

---

## 📋 Pré-requisitos

Antes de começar:

- ✅ Conta Make.com ativa: https://www.make.com/
- ✅ TODOS os 6 secrets configurados em `.env`:
  - `TINY_ERP_API_KEY`
  - `GEMINI_API_KEY`
  - `ANTHROPIC_API_KEY`
  - `OPENAI_API_KEY`
  - `GOOGLE_DRIVE_FOLDER_ID`
  - `HUB_OLIST_API_KEY` (opcional, mas recomendado)

Valide com:
```bash
php scripts/validate-automation-setup.php
```

---

## 🎯 Visão Geral do Workflow

```
┌─────────────────────────────────────────────────────────────────┐
│                    WORKFLOW MAKE.COM                             │
│                 ShopVivaliz Auto-Product v1                      │
└─────────────────────────────────────────────────────────────────┘

[MÓDULO 1] Google Drive
    ↓ (Trigger: novo arquivo em /Novos_Produtos/)
[MÓDULO 2] Gemini (Análise imagem)
    ↓ (Marca, modelo, EAN, características)
[MÓDULO 3] Claude (Copywriting)
    ↓ (Títulos + descrições para 4 marketplaces)
[MÓDULO 4] DALL-E (Geração de imagem)
    ↓ (URL de fundo studio)
[MÓDULO 5] Tiny API (Criar SKU)
    ↓ (Todos os campos customizados preenchidos)
Hub Olist recebe webhook automaticamente
    ↓
Publica em 4 marketplaces! 🎉
```

---

## 🚀 PASSO-A-PASSO: MONTAR OS 5 MÓDULOS

### PREPARAÇÃO: Criar Novo Scenario no Make.com

1. **Acesse:** https://www.make.com/
2. **Clique em:** "Create a new scenario"
3. **Nome:** `ShopVivaliz Auto-Product v1`
4. **Descrição:** "Captura foto → IA análise → publica em 4 marketplaces"
5. **Clique:** "Create"

Você verá uma tela em branco com um símbolo de "+" no centro.

---

## 📌 MÓDULO 1: Google Drive — Watch New Files (TRIGGER)

**Objetivo:** Detectar quando novo arquivo (foto) é salvo na pasta `/Novos_Produtos/`

### Configuração:

1. **Clique no "+" do centro**

2. **Busque:** "Google Drive"
   - Se não encontrar, clique em "Search for apps" e procure "Google Drive"

3. **Selecione:** "Watch Files in Folder" (não "Watch Files", mas sim a versão com "in Folder")

4. **Configure:**
   ```
   Connection: Clique em "Add" para conectar sua conta Google Drive
   (Será redirecionado para login - faça login e autorize)
   
   Folder ID: Copie o ID da pasta Novos_Produtos
   (Se não sabe o ID, vá em Google Drive, abra a pasta,
    copie de URL: https://drive.google.com/drive/folders/[ID_AQUI])
   
   Type: Only Images (é importante filtrar)
   
   Scheduled Check: ✅ SIM (check every 5 minutes)
   ```

### Output esperado do Módulo 1:

```json
{
  "id": "FILE_ID_123456",
  "name": "garrafa_neon_45.jpg",
  "mimeType": "image/jpeg",
  "size": 102400,
  "createdTime": "2026-07-09T10:30:00Z",
  "webContentLink": "https://drive.google.com/uc?id=FILE_ID&export=download"
}
```

**Salve e continue.**

---

## 🔍 MÓDULO 2: Gemini — Generate Content (Multimodal)

**Objetivo:** Analisar a imagem e extrair dados (marca, modelo, EAN, características)

### Configuração:

1. **Clique no "+"** (após Módulo 1)

2. **Busque:** "Google Gemini"

3. **Selecione:** "Generate Content"

4. **Configure:**
   ```
   Connection: Clique em "Add" para conectar Gemini
   (Será redirecionado para Google Cloud)
   
   Model: gemini-pro-vision (ou gemini-1.5-pro para melhor qualidade)
   
   Input Image: Clique no ícone variável e selecione:
     webContentLink (do Módulo 1)
   
   Prompt: (COPIE EXATAMENTE):
   ```

### Prompt para Gemini:

```
Analise esta imagem de produto e extraia RIGOROSAMENTE os dados solicitados:

1. Marca (ou "Genérica" se não identificável)
2. Modelo exato (nome/código do produto)
3. Código de barras EAN-13 (se visível, senão deixe vazio)
4. Categoria do produto (ex: Eletrônicos, Moda, Casa, etc)
5. Características principais (máximo 5 bullet points)
6. Cor/Variações visíveis

RETORNE APENAS UM JSON VÁLIDO (sem markdown, sem explicações):

{
  "marca": "string",
  "modelo": "string",
  "ean": "string ou null",
  "categoria": "string",
  "caracteristicas": ["string", "string", "string"],
  "cor": "string",
  "observacoes": "string"
}
```

**Salve e continue.**

---

## 📝 MÓDULO 3: Claude — Create a Message (Copywriting)

**Objetivo:** Gerar títulos e descrições otimizados para CADA marketplace

### Configuração:

1. **Clique no "+"** (após Módulo 2)

2. **Busque:** "Anthropic"

3. **Selecione:** "Create a Message"

4. **Configure:**
   ```
   Connection: Adicione sua ANTHROPIC_API_KEY
   
   Model: claude-3-5-sonnet-20241022
   
   System Prompt: (deixe vazio ou use o padrão)
   
   User Message: (COPIE EXATAMENTE - ver abaixo)
   
   Temperature: 0.7
   Max Tokens: 4000
   ```

### Prompt para Claude:

```
Você é um copywriter chefe de e-commerce brasileiro especializado em otimização para marketplaces.

Com base nesta ficha técnica de produto, gere títulos e descrições otimizados 
para CADA MARKETPLACE (específico, adaptado, não genérico).

FICHA TÉCNICA:
Marca: {{marca}}
Modelo: {{modelo}}
EAN: {{ean}}
Categoria: {{categoria}}
Características: {{caracteristicas}}
Cor: {{cor}}
Observações: {{observacoes}}

REQUISITOS ESPECÍFICOS POR MARKETPLACE:

**MERCADO LIVRE:**
- Título: máx 60 caracteres, sem pontuação, com keywords SEO
- Descrição: informativo, técnico, destaque diferenciais, máx 1000 chars

**SHOPEE:**
- Título: máx 120 caracteres, comece com [ORIGINAL] ou [PROMOÇÃO]
- Descrição: use emojis, hashtags relevantes, call-to-action, máx 1000 chars
- Tom: descontraído, amigável, foco em urgência

**AMAZON:**
- Título: estruturado "Marca + Categoria + Modelo", máx 150 caracteres
- Bullets: 3 frases impactantes focadas em BENEFÍCIO (não especificações técnicas puras)
  - Cada bullet máx 500 caracteres
  - Use power words: Premium, Exclusivo, Inovador, etc
- Tom: técnico mas focado em benefício para o cliente

**TIKTOK SHOP:**
- Título: máx 150 caracteres, foco em tendência, viral, curiosidade
- Descrição: Gen Z language, call-to-action de urgência ("Último em estoque!", "Tendência agora!")
- Tom: casual, jovem, com emojis, máx 1000 chars

RETORNE APENAS JSON VÁLIDO (sem markdown, sem explicações extras):

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
    "bullet_1": "string",
    "bullet_2": "string",
    "bullet_3": "string"
  },
  "tiktok": {
    "titulo": "string",
    "descricao": "string"
  }
}
```

**Mapeamento de variáveis:**
- Clique onde está `{{marca}}` e selecione `marca` do Módulo 2
- Faça o mesmo para: `modelo`, `ean`, `categoria`, `caracteristicas`, `cor`, `observacoes`

**Salve e continue.**

---

## 🎨 MÓDULO 4: OpenAI — Generate Image (DALL-E 3)

**Objetivo:** Gerar imagem de estúdio fotorrealista do produto

### ⚠️ AVISO IMPORTANTE

**CUSTO:** $0.080 por imagem  
**Recomendação:** Use este módulo apenas após testes, ou agende para rodar em horários específicos

### Configuração:

1. **Clique no "+"** (após Módulo 3)

2. **Busque:** "OpenAI"

3. **Selecione:** "Create an image" (ou "Generate Image")

4. **Configure:**
   ```
   Connection: Adicione sua OPENAI_API_KEY
   
   Model: dall-e-3
   
   Quality: hd (alta qualidade)
   
   Size: 1024x1024
   
   N (número de imagens): 1
   
   Prompt: (COPIE EXATAMENTE - ver abaixo)
   
   Style: vivid (mais realista)
   ```

### Prompt para DALL-E:

```
Create a PHOTOREALISTIC, 8K quality, professional studio product photography 
of a {{categoria}} {{modelo}} in {{cor}}.

REQUIREMENTS:
- Professional studio lighting setup (soft key light, fill light, rim light)
- Clean white or light marble background (NOT gradient, NOT colored)
- Sharp product focus, slight bokeh background
- Product CENTERED with empty space around it (padding 20%)
- NO text, NO logos, NO watermarks, NO branding visible
- Natural shadows, professional composition
- Minimal styling, focus on product
- Ready for e-commerce marketplace use
- 8K resolution quality

Style: High-end product photography, minimal, clean, professional
Reference: Apple product photography, luxury brand photography
```

**Mapeamento de variáveis:**
- Clique onde está `{{categoria}}`, `{{modelo}}`, `{{cor}}` e selecione do Módulo 2

**Salve e continue.**

---

## 🔌 MÓDULO 5: HTTP Request — Tiny API (Criar SKU)

**Objetivo:** Enviar todos os dados para Tiny ERP via API, criando SKU com campos customizados

### Configuração:

1. **Clique no "+"** (após Módulo 4)

2. **Busque:** "HTTP"

3. **Selecione:** "Make a request"

4. **Configure:**

   ```
   URL: https://tiny.com.br/api/v3/produtos/
   
   Method: POST
   
   Headers: Clique em "Add Header"
     Name: Authorization
     Value: Bearer {{TINY_ERP_API_KEY}}
   
     Name: Content-Type
     Value: application/json
   ```

5. **Body (JSON):** Cole o JSON abaixo e adapte as variáveis

### Body JSON para Tiny API:

```json
{
  "produto": {
    "nome": "{{marca}} {{modelo}} {{cor}}",
    "descricao_complementar": "Importado automaticamente por IA - ShopVivaliz Auto-Product",
    "sku": "AUTO-{{timestamp}}-{{random_id}}",
    "categoria_pai": "Importados",
    "estoque": {
      "quantidade": 1
    },
    "preco": {{custo_markup_150}},
    "peso_bruto_grama": {{peso_g}},
    "altura": {{altura_cm}},
    "largura": {{largura_cm}},
    "profundidade": {{comprimento_cm}},
    
    "campos_customizados": {
      "titulo_meli": "{{mercado_livre.titulo}}",
      "desc_meli": "{{mercado_livre.descricao}}",
      "titulo_shopee": "{{shopee.titulo}}",
      "desc_shopee": "{{shopee.descricao}}",
      "titulo_amazon": "{{amazon.titulo}}",
      "bullet_1": "{{amazon.bullet_1}}",
      "bullet_2": "{{amazon.bullet_2}}",
      "bullet_3": "{{amazon.bullet_3}}",
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
}
```

### Mapeamento de Variáveis no Body:

| Variável | Selecionar De | Como |
|----------|---------------|------|
| `{{marca}}` | Módulo 2 | campo: `marca` |
| `{{modelo}}` | Módulo 2 | campo: `modelo` |
| `{{cor}}` | Módulo 2 | campo: `cor` |
| `{{ean}}` | Módulo 2 | campo: `ean` |
| `{{peso_g}}` | Módulo 2 | campo: `peso` (se extraído) ou deixar vazio |
| `{{altura_cm}}` | Módulo 2 | campo: `altura` (se extraído) |
| `{{largura_cm}}` | Módulo 2 | campo: `largura` |
| `{{comprimento_cm}}` | Módulo 2 | campo: `comprimento` |
| `{{timestamp}}` | Function: timestamp | Make fornece automaticamente |
| `{{random_id}}` | Function: randomString | Make fornece automaticamente |
| `{{custo_markup_150}}` | Você calcula | (custo × 1.5) |
| `{{mercado_livre.titulo}}` | Módulo 3 | output → mercado_livre → titulo |
| `{{mercado_livre.descricao}}` | Módulo 3 | output → mercado_livre → descricao |
| ... (idem para shopee, amazon, tiktok) | Módulo 3 | output → ... |
| `{{dall_e_url}}` | Módulo 4 | campo: `url` ou `data[0].url` |

**Nota sobre preço:**
- Se você salva foto como `garrafa_neon_45.jpg` (custo R$ 45)
- Markup 50%: 45 × 1.5 = R$ 67.50
- Coloque `67.50` no field `preco`

**Salve e continue.**

---

## ✅ TESTE DO WORKFLOW COMPLETO

### Antes de Rodar:

1. **Verifique todas as conexões:**
   - [ ] Google Drive conectado
   - [ ] Gemini conectado
   - [ ] Claude conectado (API key)
   - [ ] OpenAI conectado (API key)
   - [ ] Todos os mapeamentos de variáveis corretos

2. **Prepare uma imagem de teste:**
   - Salve uma imagem em: `/Novos_Produtos/teste_produto_50.jpg`
   - (Use "50" como custo teste)

3. **Execute o cenário:**
   - Clique em "Run" (ou "▶ Run scenario")
   - Aguarde 3-5 minutos

### Verifique Resultado:

**No Tiny ERP:**
```
1. Vá em: Produtos > Listar Produtos
2. Procure por: "AUTO-" (SKUs criados automaticamente)
3. Verifique:
   ✅ Nome preenchido corretamente
   ✅ Campos customizados com conteúdo IA
   ✅ Imagem URL salva em url_bg_chat
   ✅ Status automação = "publicado"
```

**No Hub Olist (após 5-10 minutos):**
```
1. Vá em: Hub Olist > Histórico/Logs
2. Procure por evento de sincronização
3. Verifique se publicou em 4 marketplaces
```

**Nos Marketplaces (após 15-30 minutos):**
```
✅ Mercado Livre: Produto com título_meli + desc_meli
✅ Shopee: Produto com emojis + url_bg_chat como capa
✅ Amazon: Produto com 3 bullets estruturados
✅ TikTok Shop: Produto com conteúdo viral
```

---

## 🔧 TROUBLESHOOTING NO MAKE.COM

### Problema: Módulo não conecta a API

**Solução:**
1. Verifique se você copiou a chave inteira (sem espaços)
2. Teste a chave em Postman antes de adicionar no Make
3. Regere a chave no painel da API (se expirou)

---

### Problema: Gemini retorna erro de imagem

**Possíveis causas:**
- Imagem muito pequena (< 100KB)
- Imagem muito grande (> 4MB)
- Formato inválido (use JPEG/PNG)
- URL não acessível

**Solução:**
- Testar imagem diretamente em Google's Gemini: https://ai.google.dev/
- Verificar se webContentLink do Google Drive é acessível

---

### Problema: Claude retorna JSON inválido

**Solução:**
- Verificar output de Claude em "History" do Make
- Ajustar prompt para forçar output válido
- Adicionar: `RETORNE APENAS JSON VÁLIDO` ao fim do prompt

---

### Problema: DALL-E gera imagem com texto/logo

**Solução:**
- Aumentar prompt: adicionar "ABSOLUTELY NO TEXT" 2x
- Usar modelo melhor (dall-e-4 se disponível)
- Ajustar: "NO watermarks, NO branding, NO logos"

---

### Problema: Tiny API retorna 400 (bad request)

**Verificar:**
1. Body JSON está válido? (teste em https://jsonlint.com/)
2. Todos os campos obrigatórios preenchidos?
3. Tipos de dados corretos? (números não em string)
4. TINY_ERP_API_KEY está correta?

**Teste com curl:**
```bash
curl -X POST https://tiny.com.br/api/v3/produtos/ \
  -H "Authorization: Bearer YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"produto": {"nome": "TEST", "sku": "TEST123"}}'
```

---

## 📊 PRÓXIMOS PASSOS

Após o workflow estar funcionando:

1. **Documentar o processo:**
   - Print screens de cada módulo configurado
   - Salvar JSON de exemplo que funciona

2. **Otimizar:**
   - Reduzir prompts de IA para economizar tokens
   - Agendar DALL-E para apenas 1x por dia (economizar $)
   - Cachear respostas repetidas

3. **Monitorar:**
   - Verificar logs do Make a cada 6 horas
   - Documentar erros recorrentes
   - Ajustar prompts baseado em resultados

4. **Escalar:**
   - Após 50 produtos testados, aumentar frequência
   - Considerar plano pago do Make (operações ilimitadas)

---

## 📞 REFERÊNCIAS

- **Make.com Docs:** https://www.make.com/docs
- **Gemini API:** https://ai.google.dev/
- **Claude API:** https://docs.anthropic.com/
- **OpenAI DALL-E:** https://platform.openai.com/docs/guides/images
- **Tiny ERP API:** https://atendimento.tiny.com.br/hc/pt-br/articles

---

**Guia criado por:** Claude Code  
**Data:** 2026-07-09  
**Última atualização:** 2026-07-09  
**Status:** Pronto para produção
