# Integração - Sistema de Automação de E-commerce com IA

## Visão Geral

Sistema complementar ao Trio IA Autônomo que gerencia operações em nível de produto.

**Três Níveis de Automação:**

1. **Trio IA (Nível Macro)** → Gerencia tarefas de infraestrutura/features (existente)
2. **Automação de Produtos (Nível Micro)** ← Novo sistema (este arquivo)
3. **Marketplaces** → Shopee, TikTok, OLIST (integrações)

---

## Arquitetura Integrada

```
tasks-queue.json
    ↓
    ├─ Trio IA (infra/features)
    │  └─ ai-autonomous-executor.yml
    │
└─ Automação de Produtos (este sistema)
   └─ pipeline_orchestrator.py
      ├─ Priorização
      ├─ SEO (Shopee + TikTok)
      ├─ Imagens IA (OpenAI)
      ├─ A/B Test
      └─ Analytics
```

---

## Como Funciona

### 1. Trio IA (Existente)

Gerencia tarefas como:
- "Implementar checkout"
- "Adicionar sistema de wishlist"
- "Otimizar performance"

**Executa a cada 1 hora** via GitHub Actions

### 2. Automação de Produtos (Novo)

Gerencia produtos existentes:
- Priorizar o que vender
- Gerar SEO automático
- Criar imagens IA
- Testar automaticamente
- Aprender com dados

**Executa manualmente ou agendado**

---

## Como Executar

### Opção 1: Local (Desenvolvimento)

```bash
# Executar pipeline com amostra
python scripts/automation/pipeline_orchestrator.py

# Com dados reais
python scripts/automation/pipeline_orchestrator.py planilhas/shopee.xlsx

# Monitorar performance
python scripts/analytics/performance_tracker.py
```

### Opção 2: GitHub Actions (Produção)

Criar novo workflow `.github/workflows/product-automation.yml`:

```yaml
name: Automacao de Produtos

on:
  schedule:
    - cron: '0 */12 * * *'  # A cada 12h
  workflow_dispatch:

jobs:
  automation:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      
      - name: Setup Python
        uses: actions/setup-python@v5
        with:
          python-version: '3.11'
      
      - name: Install dependencies
        run: pip install -q openai openpyxl
      
      - name: Run automation pipeline
        env:
          OPENAI_API_KEY: ${{ secrets.OPENAI_API_KEY }}
          SHOPEE_ACCESS_TOKEN: ${{ secrets.SHOPEE_ACCESS_TOKEN }}
          TIKTOK_ACCESS_TOKEN: ${{ secrets.TIKTOK_ACCESS_TOKEN }}
        run: |
          python scripts/automation/pipeline_orchestrator.py planilhas/shopee.xlsx
      
      - name: Commit results
        run: |
          git config user.name "AI Automation"
          git config user.email "automation@shopvivaliz.com"
          git add logs/ storage/ || true
          git commit -m "data: Automacao de produtos" || true
          git push || true
```

---

## Fluxo de Dados

```
Planilha (shopee.xlsx)
    ↓
Carregar Produtos
    ↓
Priorização IA (score 0-100)
    ↓
Para cada produto TOP (50 max):
    ├─ Gerar SEO Shopee
    ├─ Gerar SEO TikTok
    ├─ Gerar 4 imagens IA
    ├─ Criar A/B test
    ├─ Selecionar melhor
    ├─ Registrar performance
    └─ Atualizar Shopee/TikTok
         ↓
    Performance Log (CSV)
         ↓
    Analytics + Insights
         ↓
    Recomendações para próxima execução
```

---

## Dados Gerados

### Logs

```
logs/
├── performance.csv         # Métricas por produto
├── prioritization.log      # Scores de priorização
├── ab_tests.jsonl          # Resultados de testes
└── automation.log          # Geral
```

### Storage

```
storage/
├── ia_images/
│   ├── 1_metadata.json     # Imagens do produto 1
│   ├── 1_image_1.jpg
│   ├── 1_image_2.jpg
│   ├── 1_image_3.jpg
│   └── 1_image_4.jpg
└── ...
```

---

## Integração com Trio IA

### Task: "Melhorar Priorizacao de Produtos"

```json
{
  "id": "product-priority-v2",
  "title": "Melhorar algoritmo de priorizacao",
  "description": "Adicionar machine learning para score de produtos",
  "status": "pending",
  "assigned_to": ["claude", "gemini"],
  "dependencies": ["product-automation"],
  "priority": "high"
}
```

Trio IA pode:
1. Analisar performance atual
2. Propor melhorias
3. Implementar novo algoritmo
4. Testar com pipeline_orchestrator.py

### Task: "Integrar com TikTok Shop"

Trio IA implementa a integração, automação de produtos a usa.

---

## Monitoramento

### Dashboard

Adicionar seção ao `admin/trio-dashboard.html`:

```html
<section id="product-automation">
  <h2>Automacao de Produtos</h2>
  <div class="metrics">
    <div>Produtos Processados: <span id="processed">0</span></div>
    <div>Score Medio SEO: <span id="seo-score">0</span>/100</div>
    <div>A/B Winner: <span id="ab-winner">-</span></div>
    <div>Conversion Rate: <span id="conv-rate">0</span>%</div>
  </div>
</section>
```

### Alerts

Se performance cair:
- Email ao usuário
- Notificação no Discord
- PR com recomendações automáticas

---

## Próximos Passos

### Curto Prazo
1. Configurar 25+ secrets (SECRETS_SETUP.md)
2. Testar com dados reais
3. Implementar marketplace integrations
4. Criar workflow GitHub Actions

### Médio Prazo
1. Machine learning para scores
2. Integração completa Shopee/TikTok APIs
3. Dashboard em tempo real
4. Auto-atualização de preços (respeitando regra de negócio)

### Longo Prazo
1. Previsão de demanda
2. Otimização automática de margins
3. Detecção de tendências
4. Estratégia multi-marketplace

---

## Documentação

### Ler Primeiro
1. **AI_AUTOMATION_SYSTEM.md** - Sistema completo
2. **SECRETS_SETUP.md** - Configuração de credenciais
3. **START_HERE.md** - Visão geral do projeto

### Documentação Técnica
- `scripts/priority/prioritizer.py` - Algoritmo de score
- `scripts/seo/seo_generator.py` - Geração de SEO
- `scripts/ia/image_generator.py` - Geração de imagens
- `scripts/abtest/ab_tester.py` - Testes A/B
- `scripts/analytics/performance_tracker.py` - Analytics

---

## Troubleshooting

### Pipeline não inicia
```bash
# Verificar imports
python -c "from scripts.automation import PipelineOrchestrator"

# Verificar diretórios
ls -la scripts/priority scripts/seo scripts/ia scripts/abtest
```

### Imagens não geram
```bash
# Verificar OPENAI_API_KEY
echo $OPENAI_API_KEY

# Testar API
python -c "import openai; print('OK')"
```

### A/B test vazio
- Simular dados: `ab_tester.simulate_test_data(test_id)`
- Em produção, dados reais de Shopee/TikTok

---

## Status

✅ Sistema criado e testado
✅ Documentação completa
⏳ Secrets a configurar
⏳ Marketplace integrations a implementar
⏳ GitHub Actions workflow a criar

---

**Integração com Trio IA = Automação Completa de E-commerce** 🚀
