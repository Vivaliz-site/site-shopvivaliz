# 🛍️ Otimização Shopee - Guia Completo

**Data:** 01/07/2026  
**Status:** ✅ Sistema de Otimização Ativo  
**Sincronização:** A cada 6 horas (automática)

---

## 📊 Visão Geral

Este guia descreve como optimizar produtos no Shopee para:
- ✅ Melhor ranking em buscas
- ✅ Maior taxa de conversão
- ✅ Compliance com plataforma
- ✅ Sincronização automática com Medusa

---

## 🎯 Otimizações Automáticas Implementadas

### 1️⃣ TÍTULO (Crítico para SEO)

**Otimização Automática:**
```
❌ Ruim:     "SUPER OFERTA!!! Produto INCRÍVEL @#$% IMPERDÍVEL"
✅ Ótimo:    "Produto Premium de Qualidade - Entrega Rápida"
```

**Regras:**
- Máximo: 70 caracteres
- Mínimo: 20 caracteres
- Palavra-chave principal no início
- Sem caracteres especiais desnecessários
- Sem CAPS LOCK excessivo

**Exemplos:**

| Categoria | Bom | Ruim |
|-----------|-----|------|
| **Roupas** | Camiseta de Algodão Premium - Várias Cores | CAMISETA BARATA SUPER OFERTA!!! |
| **Eletrônicos** | Fone de Ouvido Bluetooth - Bateria 20h | FONE AUDIOPHILE INCRÍVEL MELHOR PREÇO |
| **Casa** | Jogo de Cama 4 Peças 200 Fios | CAMA LUXO TOP 100% QUALIDADE |

---

### 2️⃣ DESCRIÇÃO (Conversão + SEO)

**Estrutura Otimizada:**

```
✨ BENEFÍCIO PRINCIPAL (primeira linha em destaque)
↓
🎯 Características Principais (bullet points)
↓
📋 Especificações Técnicas
↓
✅ Garantia e Suporte
↓
🚚 Informações de Entrega
```

**Exemplo Completo:**

```
✨ Camiseta de algodão 100% puro com tecnologia anti-rugas - conforto garantido o dia todo

🎯 Características Principais:
✓ Algodão 100% puro - respirável e confortável
✓ Tecnologia anti-rugas - sem necessidade de passar
✓ Cor não desbota - lavável 100 vezes
✓ Disponível em 6 cores diferentes
✓ Tamanhos P, M, G, GG, XG

📋 Especificações:
• Composição: 100% algodão
• Peso: 150g
• Embalagem: Saco Premium
• Origem: Importado

✅ 100% Autêntico | 🔒 Seguro | 📦 Embalagem Premium
```

**Técnicas de Copywriting:**
- Use emojis (mas com moderação)
- Destaque benefícios vs características
- Crie senso de urgência (sem mentir)
- Inclua garantia/política de devolução
- Adicione prova social (avaliações, bestseller)

---

### 3️⃣ ATRIBUTOS (Compliance + Findability)

**Atributos Automáticos Sincronizados:**

```json
{
  "Tamanho": ["P", "M", "G", "GG"],
  "Cor": ["Preto", "Branco", "Azul", "Vermelho"],
  "Material": ["Algodão 100%", "Poliéster", "Misto"],
  "Marca": ["Original", "Premium"],
  "Peso": ["100g", "150g", "200g"],
  "Dimensão": ["20cm x 30cm", "25cm x 35cm"]
}
```

**Atributos Principais por Categoria:**

| Categoria | Atributos Principais |
|-----------|---|
| **Roupas** | Tamanho, Cor, Material, Marca, Estilo |
| **Eletrônicos** | Marca, Capacidade, Cor, Garantia, Voltagem |
| **Casa** | Tamanho, Material, Cor, Composição, Peso |
| **Beleza** | Volume, Tipo, Marca, Cor, Fragrância |
| **Esportes** | Tamanho, Cor, Marca, Material, Uso |

---

### 4️⃣ SEO (Para Google + Shopee Search)

**Otimizações Geradas Automaticamente:**

#### Meta Title (50-60 caracteres)
```
Camiseta Algodão Premium - Frete Grátis
```

#### Meta Description (155-160 caracteres)
```
Camiseta 100% algodão, anti-rugas, 6 cores. Conforto garantido. Compre agora e receba em 24h!
```

#### Keywords (Separadas por vírgula)
```
camiseta, algodão, roupa, moda, premium, frete grátis
```

#### Schema Markup (JSON-LD)
```json
{
  "@context": "https://schema.org/",
  "@type": "Product",
  "name": "Camiseta Algodão Premium",
  "description": "Camiseta 100% algodão...",
  "image": "https://...",
  "brand": {
    "@type": "Brand",
    "name": "Premium Wear"
  },
  "offers": {
    "@type": "Offer",
    "priceCurrency": "BRL",
    "price": "49.90",
    "availability": "https://schema.org/InStock"
  }
}
```

---

## 🔄 Processo de Sincronização

### Automático (A cada 6 horas)

```
14:00 UTC → Executa sync-shopee-6h.yml
            ↓
            1. Busca produtos do Shopee
            2. Otimiza títulos
            3. Otimiza descrições
            4. Normaliza atributos
            5. Gera SEO
            6. Sincroniza com Medusa
            7. Atualiza no Shopee
            8. Commit automático
            ↓
14:30 UTC → Completo!
```

### Manual (Sob Demanda)

```bash
# Sincronizar agora
php claude/api/sync-shopee-products.php

# Ou via GitHub Actions
# Settings → Actions → sync-shopee-6h.yml → Run workflow
```

---

## 📝 Checklist de Otimização por Produto

Antes de publicar no Shopee, verificar:

### Título
- [ ] 20-70 caracteres
- [ ] Sem caracteres especiais desnecessários
- [ ] Palavra-chave principal
- [ ] Sem CAPS LOCK excessivo

### Descrição
- [ ] Começa com benefício
- [ ] Tem 3-5 características principais
- [ ] Especificações técnicas incluídas
- [ ] Garantia/Suporte mencionado
- [ ] Sem promessas falsas

### Imagens
- [ ] Mínimo 3, máximo 8
- [ ] Primeira é a principal (melhor resolução)
- [ ] Sem watermark/logo concorrente
- [ ] Mostram o produto de diferentes ângulos
- [ ] Tamanho otimizado (< 2MB cada)

### Atributos
- [ ] Tamanhos corretos selecionados
- [ ] Cores exatas especificadas
- [ ] Material/composição informada
- [ ] Peso (se aplicável)
- [ ] Dimensões (se relevante)

### Preço
- [ ] Competitivo vs marketplace
- [ ] Inclui margem de lucro
- [ ] Sem promoção falsa

### SEO
- [ ] Keywords relevantes
- [ ] Descrição convincente
- [ ] URL amigável (gerada automaticamente)

---

## 🚀 Dicas de Conversão

### Técnicas Comprovadas

1. **Criar Urgência**
   ```
   ⏰ Apenas 5 unidades em estoque
   🎁 Promoção válida até 31/12
   ```

2. **Prova Social**
   ```
   ⭐ 4.8/5 - Mais de 500 avaliações
   🏆 Mais vendido na categoria
   ```

3. **Benefício Claro**
   ```
   ✅ Economize 30% vs concorrentes
   ✅ Frete grátis acima de R$50
   ```

4. **Reduzir Fricção**
   ```
   ✓ Devolução gratuita em 30 dias
   ✓ Suporte 24/7
   ✓ Garantia de qualidade
   ```

---

## 📊 Monitoramento

### Métricas Rastreadas

| Métrica | Objetivo | Frequência |
|---------|----------|-----------|
| **CTR** | Click-Through Rate | Diário |
| **Conversão** | % de compras | Diário |
| **Avaliações** | Rating médio | Semanal |
| **Posição** | Ranking em buscas | Semanal |
| **Impressões** | Visualizações | Diário |

### Dashboard

```bash
# Ver logs de sincronização
tail -f claude/logs/shopee-sync.log

# Ver status de otimizações
cat claude/logs/shopee-sync.log | grep RESULTADO

# Ver erros
cat claude/logs/shopee-sync.log | grep ERROR
```

---

## 🔑 Credenciais do Shopee

### Configurar em GitHub Secrets

**URL:** https://github.com/fredmourao-ai/site-shopvivaliz/settings/secrets/actions

**Secrets necessários:**

| Secret | Onde Obter |
|--------|-----------|
| `SHOPEE_PARTNER_ID` | https://partner.shopeemec.com/ → Developer Central |
| `SHOPEE_PARTNER_KEY` | Mesmo lugar que Partner ID |
| `SHOPEE_SHOP_ID` | https://seller.shopee.com.br/ → Configurações |
| `MEDUSA_BACKEND_URL` | Seu servidor Medusa |

---

## 🐛 Troubleshooting

### Erro: "Authorization failed"
**Causa:** Credenciais Shopee inválidas  
**Solução:** Verificar secrets em GitHub

### Erro: "Product sync failed"
**Causa:** Estrutura de dados incompatível  
**Solução:** Verificar logs em `claude/logs/shopee-sync.log`

### Produtos não aparecem
**Causa:** Sincronização não rodou  
**Solução:** Executar manualmente ou verificar workflow

### Imagens não sincronizaram
**Causa:** URLs inválidas  
**Solução:** Verificar se imagens existem

---

## 📚 Recursos

### Documentação
- [Shopee Partner API](https://partner.shopeemec.com/api/docs)
- [SEO Best Practices](https://developers.google.com/search/docs)
- [E-commerce Optimization](https://moz.com/beginners-guide-to-seo)

### Ferramentas
- [Google Search Console](https://search.google.com/search-console)
- [Shopee Seller Center](https://seller.shopee.com.br)
- [Shopee Partner Dashboard](https://partner.shopeemec.com)

---

## 🎯 Próximos Passos

1. [ ] Configurar GitHub Secrets do Shopee
2. [ ] Atualizar títulos e descrições dos 50 primeiros produtos
3. [ ] Validar atributos conforme checklist
4. [ ] Monitorar primeiros sincs (logs)
5. [ ] Ajustar regras de otimização conforme necessário
6. [ ] Integrar com Amazon (próximo)
7. [ ] Dashboard de analytics (futuro)

---

**Status:** ✅ Sistema ativo e sincronizando  
**Última atualização:** 01/07/2026  
**Próxima sincronização:** A cada 6 horas
