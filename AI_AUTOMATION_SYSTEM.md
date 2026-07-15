# Sistema Completo de Automação de E-commerce com IA - ShopVivaliz

## 📋 Visão Geral

Sistema totalmente automático que:
- ✅ Decide o que vender (Priorização com IA)
- ✅ Cria conteúdo (SEO + Imagens IA)
- ✅ Publica (Shopee + TikTok)
- ✅ Aprende e Melhora (A/B Tests + Analytics)

---

## 🏗️ Arquitetura

### Estrutura de Diretórios

```
scripts/
├── automation/           # Orquestração do pipeline
│   └── pipeline_orchestrator.py
├── ia/                  # Geração de imagens com IA
│   └── image_generator.py
├── seo/                 # Geração de SEO automática
│   └── seo_generator.py
├── priority/            # Priorização de produtos
│   └── prioritizer.py
├── abtest/              # Testes A/B automáticos
│   └── ab_tester.py
├── analytics/           # Rastreamento de performance
│   └── performance_tracker.py
├── integrations/        # Integrações com marketplaces
├── utils/              # Utilitários
│   └── config.py
└── main.py             # Pipeline principal

storage/
├── ia_images/          # Imagens geradas pela IA
└── ...

logs/
├── performance.csv     # Métricas de performance
├── ab_tests.jsonl      # Resultados de A/B tests
└── prioritization.log  # Log de priorização
```

---

## 🚀 Como Funciona o Pipeline

### Fluxo Completo

```
1. PRIORIZAÇÃO (IA)
   └─ Carrega produtos da planilha
   └─ Calcula score 0-100 para cada
   └─ Ordena por prioridade

2. SEO INTELIGENTE
   ├─ Shopee: foco em KEYWORDS
   │  └─ Título otimizado + keywords
   │  └─ Descrição com SEO estruturado
   │
   └─ TikTok: foco em EMOCIONAL
      └─ Título emocional
      └─ Descrição com call-to-action

3. GERAÇÃO DE IMAGENS (IA)
   ├─ Gera 4 variantes por produto
   ├─ Baseado na imagem real
   └─ Salva metadata em JSON

4. A/B TESTING AUTOMÁTICO
   ├─ Testa 4 variantes de imagem
   ├─ Coleta: impressions, clicks, conversions
   ├─ Calcula CTR e conversion rate
   └─ Seleciona vencedor automaticamente

5. ATUALIZAÇÃO AUTOMÁTICA
   ├─ Shopee: título + descrição + melhor imagem
   ├─ TikTok: título + descrição + melhor imagem
   └─ NÃO altera preço

6. ANALYTICS & APRENDIZADO
   ├─ Registra performance de cada produto
   ├─ Calcula insights por marketplace
   ├─ Gera recomendações
   └─ Melhora automaticamente próximos produtos
```

---

## 📊 Módulos Principais

### 1. Priority Prioritizer (priority/prioritizer.py)

**Função**: Calcula score 0-100 para cada produto

**Fatores considerados**:
- Stock (15%) - produtos com estoque
- Preço (10%) - faixa ótima: R$10-R$200
- Categoria (10%) - eletrônicos, moda, beleza, casa
- Margem (15%) - produtos mais lucrativos
- Demanda (20%) - indicador de procura
- Tendência (15%) - produtos em alta
- Imagens (10%) - qualidade do material
- Descrição (5%) - qualidade do conteúdo

**Uso**:
```python
from priority.prioritizer import ProductPrioritizer

prioritizer = ProductPrioritizer()
prioritized = prioritizer.prioritize_products(products)
# Retorna: lista ordenada por score descending
```

---

### 2. SEO Generator (seo/seo_generator.py)

**Função**: Gera SEO automático e inteligente

**Shopee SEO** (Keywords):
- Título otimizado com keywords
- Descrição estruturada com keywords
- Score de qualidade SEO

**TikTok SEO** (Emocional):
- Título com apelo emocional
- Descrição com call-to-action
- Hashtags otimizadas
- Score baseado em engagement

**Uso**:
```python
from seo.seo_generator import SEOGenerator

seo = SEOGenerator()
shopee_seo = seo.generate_shopee_seo(product)
tiktok_seo = seo.generate_tiktok_seo(product)
# Retorna: {title, description, keywords, quality_score}
```

---

### 3. IA Image Generator (ia/image_generator.py)

**Função**: Gera 4 imagens IA por produto

**Variantes geradas**:
1. Imagem profissional (fundo branco, studio)
2. Imagem em uso prático (ambiente realista)
3. Closeup detalhe (texturas e acabamentos)
4. Destaque com efeito visual (cores vibrantes)

**Features**:
- Detecção automática de imagens ruins (baixo CTR)
- Regeneração automática se necessário
- Metadata em JSON com histórico

**Uso**:
```python
from ia.image_generator import IAImageGenerator

generator = IAImageGenerator()
images = generator.generate_product_images(product)
# Retorna: {product_id, images: [variant, url, status]}

# Detectar imagens ruins
bad = generator.detect_bad_images(image_urls)
# Retorna: lista de imagens com baixo CTR
```

---

### 4. A/B Tester (abtest/ab_tester.py)

**Função**: Testa automaticamente qual imagem converte melhor

**Fluxo**:
1. Cria teste com 4 variantes
2. Registra: impressions, clicks, conversions
3. Calcula: CTR (Click-Through Rate)
4. Seleciona vencedor automaticamente

**Significância Estatística**:
- Mínimo 7 dias de teste
- Mínimo 100 impressões
- Diferença de 15%+ no CTR

**Uso**:
```python
from abtest.ab_tester import ABTester

tester = ABTester()
test = tester.create_ab_test(product_id, images)
tester.record_impression(test_id, variant)
tester.record_click(test_id, variant)
tester.record_conversion(test_id, variant)

winner = tester.get_winner(test_id)
# Retorna: imagem com melhor performance
```

---

### 5. Performance Tracker (analytics/performance_tracker.py)

**Função**: Rastreia performance e gera insights

**Métricas coletadas**:
- SEO Score (0-100)
- Image Score (0-100)
- CTR (Click-Through Rate)
- Conversion Rate
- Impressões e Vendas

**Insights gerados**:
- SEO por marketplace (Shopee vs TikTok)
- Performance de imagens
- Taxa de conversão
- Recomendações automáticas

**Uso**:
```python
from analytics.performance_tracker import PerformanceTracker

tracker = PerformanceTracker()
tracker.log_product_performance(product_id, {
    'marketplace': 'shopee',
    'seo_score': 85,
    'image_score': 92,
    'ctr': 0.12,
    'conversion_rate': 0.05
})

report = tracker.generate_report()
# Retorna: insights + recomendações
```

---

## 🎯 Pipeline Orchestrator (automation/pipeline_orchestrator.py)

**Função**: Coordena todos os módulos em um fluxo automático

**Execução**:
```python
from automation.pipeline_orchestrator import PipelineOrchestrator

orchestrator = PipelineOrchestrator()
result = orchestrator.run_complete_pipeline('planilhas/shopee.xlsx')
```

**O que faz**:
1. Carrega produtos da planilha
2. Prioriza com IA (score 0-100)
3. Gera SEO Shopee e TikTok
4. Gera 4 imagens IA
5. Cria A/B test
6. Seleciona melhor imagem
7. Atualiza Shopee
8. Atualiza TikTok
9. Registra performance
10. Gera relatório com recomendações

**Saída**:
```
RELATORIO FINAL

Processados: 50
Falhados: 2
Tempo total: 245.3s

Performance Analytics:
- SEO Shopee: 85/100
- SEO TikTok: 78/100
- Imagens: 92/100
- Conversion rate: 0.05

Recomendacoes:
- Melhorar SEO para TikTok: aumentar apelo emocional
- Testar novas imagens para produtos com CTR baixo
```

---

## 🔧 Configuração

Ver `scripts/utils/config.py` para todas as configurações:

```python
# APIs
OPENAI_API_KEY = 'sk-...'
SHOPEE_ACCESS_TOKEN = '...'
TIKTOK_ACCESS_TOKEN = '...'

# Priorização
PRIORITY_CONFIG = {
    'min_score': 30,
    'max_products_per_run': 50,
}

# A/B Test
ABTEST_CONFIG = {
    'min_impressions': 100,
    'min_duration_days': 7,
    'significance_level': 0.05,
}
```

---

## 📈 Aprendizado Automático

### Como o sistema aprende:

1. **Coleta de Dados**:
   - Performance de cada produto registrada
   - A/B tests salvos em JSON

2. **Análise de Insights**:
   - SEO por marketplace
   - Imagens de melhor performance
   - Taxa de conversão por categoria

3. **Recomendações Automáticas**:
   - "Melhorar SEO para TikTok"
   - "Aumentar imagens de qualidade"
   - "Testar novos ângulos"

4. **Iteração Contínua**:
   - Próximos produtos usam aprendizado
   - Melhora constante sem intervenção

---

## ✅ Regras Críticas

### NÃO ALTERA PREÇO
```python
# PROIBIDO:
product['price'] = 99.90  # ❌ Nunca altere preço

# PERMITIDO:
product['seo'] = 'novo texto'  # ✅ Apenas conteúdo
product['images'] = ['novas imagens']  # ✅ Apenas imagens
```

### NÃO TRAVA EM ERRO
```python
try:
    result = process_product(product)
except Exception as e:
    # Log do erro
    failed_count += 1
    # Continua com próximo produto
```

### SEMPRE FALLBACK
```python
# Se IA falha:
if not ai_images:
    images = use_original_images()  # Fallback

# Se SEO falha:
if not seo_result:
    seo = generate_basic_seo()  # Fallback
```

---

## 🚀 Executando

```bash
# Pipeline completo
python scripts/automation/pipeline_orchestrator.py

# Com planilha específica
python scripts/automation/pipeline_orchestrator.py planilhas/produtos_novos.xlsx

# Via main.py (compatibilidade)
python scripts/main.py
```

---

## 📊 Logs e Dados

### Arquivos gerados:

1. **logs/performance.csv** - Métricas por produto
2. **logs/ab_tests.jsonl** - Resultados de A/B tests
3. **logs/prioritization.log** - Score de priorização
4. **storage/ia_images/** - Imagens geradas
5. **Relatório final** - Printed ao fim da execução

---

## 🔗 Integrações

Marketplace integrations em `scripts/integrations/`:

- `shopee.py` - Atualizar Shopee API
- `tiktok.py` - Atualizar TikTok API
- `utils.py` - Helpers compartilhados

---

## 🎓 Para Novos Agentes

Ao trabalhar neste sistema:

1. **Sempre respeite as regras críticas**
2. **Use o pipeline_orchestrator.py** para orquestração
3. **Consulte config.py** para configurações
4. **Salve logs em JSON** para análise
5. **Implemente fallbacks** para falhas de IA

---

**Sistema pronto para operação contínua e autônoma! 🚀**
