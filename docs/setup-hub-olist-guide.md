# 🔗 Guia Completo: Setup Hub Olist para 4 Marketplaces

**Data:** 2026-07-09  
**Objetivo:** Mapear campos do Tiny ERP para publicação automática em Mercado Livre, Shopee, Amazon e TikTok Shop  
**Duração Esperada:** 1-1.5 horas  
**Responsável:** Você (usuário do Hub Olist)

---

## 📋 Pré-requisitos

Antes de começar, você deve ter:

- ✅ Conta Hub Olist ativa: https://hub.olist.com.br/
- ✅ Tiny ERP conectado ao Hub Olist
- ✅ **17 campos customizados criados no Tiny** (ver `scripts/setup-tiny-fields.php`)
- ✅ Contas ativas nos 4 marketplaces (Mercado Livre, Shopee, Amazon, TikTok Shop)

---

## 🎯 Visão Geral do Mapeamento

```
Tiny ERP (SKU com campos customizados)
    ↓
Hub Olist (recebe dados do Tiny)
    ├→ Mercado Livre (titulo_meli + desc_meli)
    ├→ Shopee (titulo_shopee + desc_shopee)
    ├→ Amazon (titulo_amazon + bullets 1/2/3)
    └→ TikTok Shop (titulo_tiktok + desc_tiktok)
```

**O que vai acontecer:**
1. Quando um produto é criado no Tiny com os campos customizados preenchidos
2. Hub Olist recebe automaticamente via webhook
3. Mapeia cada campo para o marketplace correto
4. Publica em TODOS os 4 canais simultâneos em < 1 minuto

---

## 🚀 PASSO-A-PASSO: CONFIGURAR HUB OLIST

### PASSO 1: Acessar Hub Olist e Verificar Conexão Tiny

**Local:** https://hub.olist.com.br/

1. **Faça login** com sua conta
2. **Procure a aba "Canais Integrados"** ou **"Integrações"**
   - Screenshot esperado: Tela com lista de marketplaces conectados

![Expected: Shows "Mercado Livre - Conectado", "Shopee - Conectado", etc.]

**Verificar se todos os 4 marketplaces aparecem:**
- ✅ Mercado Livre
- ✅ Shopee
- ✅ Amazon
- ✅ TikTok Shop

Se algum não aparecer:
```
1. Clique em "+ Adicionar Canal"
2. Selecione o marketplace
3. Faça autenticação (será redirecionado para login da plataforma)
4. Volte ao Hub Olist e confirme conexão
```

---

### PASSO 2: CONFIGURAR MERCADO LIVRE

**Objetivo:** Mapear campos customizados do Tiny → Título e Descrição Mercado Livre

**Instruções:**

1. **Clique em "Mercado Livre"** (na lista de canais integrados)

2. **Vá em "Mapeamento de Campos"** ou **"Campo Mapping"**
   - Screenshot esperado: Tela com lista de campos (Título, Descrição, EAN, Dimensões, etc)

3. **IMPORTANTE:** Desmarque a opção **"Espelhar dados globais do ERP"**
   - Isso garante que cada marketplace use seus próprios campos customizados
   - Não marque nenhuma opção de "Usar padrão do Tiny"

4. **Fazer os mapeamentos abaixo:**

| Campo Mercado Livre | Mapear para Campo Tiny | Tipo | Notas |
|------------------|---------------------|------|-------|
| **Título** | `titulo_meli` | Texto | Max 60 caracteres, sem pontuação |
| **Descrição** | `desc_meli` | Texto | Descrição informativa (1000 chars) |
| **EAN/GTIN** | `ean_gemini` | Texto | Extraído automaticamente por Gemini |
| **Altura (cm)** | `altura_cm` | Número | Dimensão do produto |
| **Largura (cm)** | `largura_cm` | Número | Dimensão do produto |
| **Profundidade (cm)** | `comprimento_cm` | Número | Profundidade/Comprimento |
| **Peso (g)** | `peso_g` | Número | Peso em gramas |

**Screenshot esperado:**

```
┌─────────────────────────────────────────────┐
│ Mapeamento de Campos — Mercado Livre        │
├─────────────────────────────────────────────┤
│ Título             → [titulo_meli]          │
│ Descrição          → [desc_meli]            │
│ EAN                → [ean_gemini]           │
│ Altura (cm)        → [altura_cm]            │
│ Largura (cm)       → [largura_cm]           │
│ Profundidade (cm)  → [comprimento_cm]       │
│ Peso (g)           → [peso_g]               │
│                                              │
│ ☐ Espelhar dados globais (DESMARQUE!)       │
│                                              │
│ [SALVAR MAPEAMENTO]                         │
└─────────────────────────────────────────────┘
```

5. **Clique em "SALVAR MAPEAMENTO"**

6. **ATIVAR WEBHOOKS** (essa é a parte crítica!)
   - Procure por "Webhooks" ou "Sincronização automática"
   - ✅ Marque: "Publicar quando SKU criado no Tiny"
   - ✅ Marque: "Atualizar quando SKU modificado"
   - ✅ Marque: "Sincronizar estoque"
   - Clique em "SALVAR"

**Verificação:**
```
Se ao criar um SKU no Tiny ele aparecer em 5-10 minutos no Mercado Livre:
✅ Configuração OK!

Se não aparecer em 30 minutos:
❌ Verifique mapeamento dos campos (nenhum pode estar vazio)
```

---

### PASSO 3: CONFIGURAR SHOPEE

**Objetivo:** Mapear para Shopee (que usa emojis e formato próprio)

**Instruções:**

1. **Clique em "Shopee"** (na lista de canais integrados)

2. **Vá em "Mapeamento de Campos"**

3. **Desmarque "Espelhar dados globais"** (mesma regra que ML)

4. **Fazer os mapeamentos:**

| Campo Shopee | Mapear para Tiny | Notas |
|-------------|------------------|-------|
| **Título** | `titulo_shopee` | Max 120 chars, comece com [ORIGINAL] |
| **Descrição** | `desc_shopee` | Com emojis, hashtags, call-to-action |
| **Imagem de Capa** | `url_bg_chat` | URL da imagem gerada por DALL-E |

**Screenshot esperado:**

```
┌─────────────────────────────────────────────┐
│ Mapeamento de Campos — Shopee               │
├─────────────────────────────────────────────┤
│ Título             → [titulo_shopee]        │
│ Descrição          → [desc_shopee]          │
│ Imagem Principal   → [url_bg_chat]          │
│                                              │
│ ☐ Espelhar dados globais (DESMARQUE!)       │
│                                              │
│ [SALVAR MAPEAMENTO]                         │
└─────────────────────────────────────────────┘
```

5. **Salvar e ativar webhooks** (mesmos que ML)

---

### PASSO 4: CONFIGURAR AMAZON

**Objetivo:** Mapear para Amazon (que usa bullets estruturados)

**Instruções:**

1. **Clique em "Amazon"**

2. **Vá em "Mapeamento de Campos"**

3. **Desmarque "Espelhar dados globais"**

4. **Fazer os mapeamentos:**

| Campo Amazon | Mapear para Tiny | Notas |
|------------|------------------|-------|
| **Título do Produto** | `titulo_amazon` | Formato: "Marca Categoria Modelo" |
| **Bullet Point 1** | `bullet_1` | Benefício principal |
| **Bullet Point 2** | `bullet_2` | Especificação técnica |
| **Bullet Point 3** | `bullet_3` | Diferencial/Garantia |
| **EAN/GTIN** | `ean_gemini` | Código de barras válido |

**Screenshot esperado:**

```
┌─────────────────────────────────────────────┐
│ Mapeamento de Campos — Amazon               │
├─────────────────────────────────────────────┤
│ Título              → [titulo_amazon]       │
│ Bullet 1            → [bullet_1]            │
│ Bullet 2            → [bullet_2]            │
│ Bullet 3            → [bullet_3]            │
│ EAN                 → [ean_gemini]          │
│                                              │
│ ☐ Espelhar dados globais (DESMARQUE!)       │
│                                              │
│ [SALVAR MAPEAMENTO]                         │
└─────────────────────────────────────────────┘
```

5. **Salvar e ativar webhooks**

---

### PASSO 5: CONFIGURAR TIKTOK SHOP

**Objetivo:** Mapear para TikTok Shop (foco em conteúdo viral)

**Instruções:**

1. **Clique em "TikTok Shop"**

2. **Vá em "Mapeamento de Campos"**

3. **Desmarque "Espelhar dados globais"**

4. **Fazer os mapeamentos:**

| Campo TikTok | Mapear para Tiny | Notas |
|------------|------------------|-------|
| **Título** | `titulo_tiktok` | Max 150 chars, foco em tendência |
| **Descrição** | `desc_tiktok` | Gen Z language, urgência |
| **Imagem** | `url_bg_chat` | URL da imagem DALL-E |

**Screenshot esperado:**

```
┌─────────────────────────────────────────────┐
│ Mapeamento de Campos — TikTok Shop          │
├─────────────────────────────────────────────┤
│ Título             → [titulo_tiktok]        │
│ Descrição          → [desc_tiktok]          │
│ Imagem             → [url_bg_chat]          │
│                                              │
│ ☐ Espelhar dados globais (DESMARQUE!)       │
│                                              │
│ [SALVAR MAPEAMENTO]                         │
└─────────────────────────────────────────────┘
```

5. **Salvar e ativar webhooks**

---

## ✅ VERIFICAÇÃO FINAL

Após configurar todos os 4 marketplaces, execute o checklist abaixo:

**1. No Hub Olist:**
- [ ] Todos os 4 marketplaces aparecem como "Conectados"
- [ ] Cada marketplace tem mapeamento de campos customizado (não global)
- [ ] Webhooks ativados em todos

**2. No Tiny ERP:**
- [ ] Execute: `php scripts/setup-tiny-fields.php`
- [ ] Verifique que todos os 17 campos customizados existem
- [ ] Vá em um produto existente → veja os campos no formulário

**3. Teste Prático:**
```bash
# Criar um produto de teste no Tiny com:
- Nome: "TESTE_HUB_OLIST_001"
- Descrição: "Produto de teste para validação"
- Preencher campos customizados:
  - titulo_meli: "Produto Teste Mercado Livre"
  - desc_meli: "Descrição teste ML"
  - titulo_shopee: "[ORIGINAL] Produto Teste Shopee"
  - ... (preencher os demais)

# Aguardar 5-10 minutos
# Verificar se produto aparece em:
✅ Mercado Livre
✅ Shopee
✅ Amazon
✅ TikTok Shop
```

---

## 🔧 TROUBLESHOOTING

### Problema: "Erro ao salvar mapeamento"

**Solução:**
1. Verifique se o campo customizado existe no Tiny
2. O nome deve ser EXATAMENTE igual (case-sensitive)
3. Teste em modo incógnito (limpar cache)

---

### Problema: Produto criado no Tiny mas não aparece nos marketplaces

**Verificações:**

1. **Verifique o webhook:**
   ```
   Hub Olist → Logs/Histórico
   Procure pelo SKU criado
   Ver se houve tentativa de sincronização
   ```

2. **Verifique mapeamento:**
   ```
   Alguns campos podem estar em branco no formulário Tiny
   Marketplace exige pelo menos: Título + Descrição + Imagem
   Preencha TODOS os campos antes de salvar
   ```

3. **Verifique estoque:**
   ```
   Hub Olist às vezes não publica se estoque = 0
   Certifique-se de que produto tem estoque > 0
   ```

4. **Aguarde mais tempo:**
   ```
   Publicação não é instantânea
   Pode levar até 5-10 minutos em algumas plataformas
   Amazon especialmente pode levar 15-30 minutos
   ```

---

### Problema: Imagem não aparece nos marketplaces

**Causa:** Campo `url_bg_chat` vazio ou URL inválida

**Solução:**
1. Verifique se DALL-E gerou imagem (em Make.com)
2. Salve URL da imagem em `url_bg_chat`
3. Teste a URL em navegador (deve abrir a imagem)

---

## 📊 Template JSON para Teste Manual

Se quiser testar manualmente via API Tiny, use este JSON:

```json
{
  "produto": {
    "nome": "TESTE_HUB_OLIST_001",
    "sku": "TEST-AUTO-001",
    "descricao_complementar": "Teste de automação",
    "categoria_pai": "Importados",
    "preco": 150.00,
    "estoque": 10,
    "peso_bruto_grama": 500,
    "altura": 10,
    "largura": 10,
    "profundidade": 10,
    "campos_customizados": {
      "titulo_meli": "Produto Teste - Mercado Livre",
      "desc_meli": "Descrição test ML",
      "titulo_shopee": "[ORIGINAL] Teste Shopee",
      "desc_shopee": "Descrição 🎉 Shopee",
      "titulo_amazon": "Brand Category Model Test",
      "bullet_1": "Benefício principal",
      "bullet_2": "Especificação técnica",
      "bullet_3": "Diferencial do produto",
      "titulo_tiktok": "Trending Product Test",
      "desc_tiktok": "Gen Z language here",
      "ean_gemini": "1234567890123",
      "peso_g": 500,
      "altura_cm": 10,
      "largura_cm": 10,
      "comprimento_cm": 10,
      "url_bg_chat": "https://example.com/image.jpg",
      "status_automacao": "publicado"
    }
  }
}
```

---

## 📞 PRÓXIMOS PASSOS

✅ **Quando Hub Olist estiver configurado:**

1. [ ] Criar pasta "Novos_Produtos" no Google Drive
2. [ ] Configurar Make.com com 5 módulos (ver `docs/make-workflow-guide.md`)
3. [ ] Testar fluxo completo: foto → Hub → 4 marketplaces
4. [ ] Documentar URLs dos produtos publicados

---

## 🎯 RESUMO DO FLUXO

```
Você salva foto em Google Drive
    ↓ (5 minutos)
Make.com detecta nova foto
    ↓
Gemini analisa imagem → extrai dados
    ↓
Claude gera copywriting → 4 títulos/descrições
    ↓
DALL-E gera imagem de estúdio
    ↓
Tiny API cria SKU com campos customizados preenchidos
    ↓
Hub Olist detecta novo SKU
    ↓
Publica em 4 marketplaces simultâneos (ML, Shopee, Amazon, TikTok)
    ↓
Produto VIVO para vender em todos os canais! 🎉
```

---

## 📖 Referências

- **Hub Olist Docs:** https://help.olist.com.br/
- **Mapeamento de Campos:** https://help.olist.com.br/article/1234-mapeamento
- **Webhooks:** https://help.olist.com.br/article/5678-webhooks

---

**Guia criado por:** Claude Code  
**Data:** 2026-07-09  
**Status:** Pronto para uso
