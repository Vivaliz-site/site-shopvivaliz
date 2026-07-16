# 🤖 DOCUMENTAÇÃO PARA AGENTES - SHOPVIVALIZ

**Versão:** 2.0  
**Última Atualização:** 29/06/2026  
**Linguagem:** Português  
**Para:** Agentes IA, Desenvolvedores, Sistemas Automáticos

---

## 📌 VISÃO GERAL

ShopVivaliz é um **sistema automático de ecommerce com IA** que:

1. **Lê** produtos de uma planilha
2. **Prioriza** automaticamente (qual vender primeiro)
3. **Gera SEO** customizado por marketplace
4. **Cria imagens** com IA (4 variantes por produto)
5. **Faz A/B test** automático
6. **Publica** em Shopee e TikTok
7. **Analisa** performance
8. **Aprende** e melhora sozinho

---

## 🏗️ ARQUITETURA DO SISTEMA

### Diretórios Principais

```
site-shopvivaliz/
├── scripts/
│   ├── main.py                  # Pipeline principal (ENTRAR AQUI)
│   ├── main_advanced.py         # Pipeline avançado com IA
│   ├── import_shopee.py         # Lê planilha
│   ├── generate_ai_images.py    # Gera imagens
│   ├── seo_generator.py         # Gera SEO (NOVO)
│   ├── ab_test_images.py        # A/B Testing
│   ├── auto_optimize_images.py  # Auto-otimização
│   ├── upload_images.py         # Upload FTP
│   ├── generate_shopee_sheet.py # Gera planilha Shopee
│   ├── send_email.py            # Envia relatórios
│   ├── priority/                # Priorização (NOVO)
│   ├── seo/                     # SEO avançado (NOVO)
│   ├── abtest/                  # A/B Testing avançado (NOVO)
│   ├── analytics/               # Analytics (NOVO)
│   ├── automation/              # Automação (NOVO)
│   ├── integrations/            # Integrações (NOVO)
│   └── utils/                   # Utilitários
├── storage/
│   ├── raw/                     # Imagens originais
│   ├── processed/               # Imagens processadas
│   ├── ia_images/               # Imagens IA (NOVO)
│   ├── uploaded_urls.csv        # URLs geradas
│   └── sku_mapping.csv          # Mapeamento SKU
├── logs/
│   ├── priority_scores.json     # Scores de priorização
│   ├── seo_generated.json       # SEO gerado
│   ├── ab_test_report.txt       # Resultados A/B
│   ├── optimization_report.txt  # Otimizações
│   ├── pipeline_execution.json  # Execução
│   └── pipeline_execution_advanced.json  # Execução avançada
├── planilhas/
│   ├── shopee_import.xlsx       # Planilha para importar
│   └── tiktok_import.xlsx       # Planilha TikTok
├── .github/workflows/           # Automação GitHub Actions
│   ├── deploy.yml               # Deploy automático
│   ├── sync-olist.yml           # Sync Olist a cada 6h
│   ├── auto-validation.yml      # Validação a cada 30min
│   └── monitor-chat.yml         # Chat a cada 2min
├── admin/
│   ├── monitor-completo.php     # Painel web
│   ├── squad-chat.php           # Chat com agentes
│   └── diagnostico-banco.php    # Diagnóstico
└── docs/
    ├── SISTEMA_AUTOMACAO_COMPLETO.md (LEIA PRIMEIRO)
    ├── README_AGENTES.md (Este arquivo)
    ├── SETUP_SECRETS_GUIDE.md
    ├── INTEGRACAO_MARKETPLACES.md
    ├── PIPELINE_RELATORIO_FINAL.md
    └── STATUS_FINAL.txt
```

---

## 🚀 COMO EXECUTAR

### Opção 1: Pipeline Padrão

```bash
cd scripts/
python main.py
```

**O que faz:**
1. ✅ Importa produtos
2. ✅ Gera imagens IA
3. ✅ Faz upload FTP
4. ✅ Faz A/B Testing
5. ✅ Auto-otimização
6. ✅ Gera planilha Shopee
7. ✅ Envia email

**Tempo:** ~5-10 minutos (depende da quantidade de produtos)

### Opção 2: Pipeline Avançado (RECOMENDADO)

```bash
cd scripts/
python main_advanced.py
```

**Adicional:**
1. ✅ Prioriza produtos com IA
2. ✅ Gera SEO customizado por marketplace
3. ✅ Aprende com performance

**Tempo:** ~10-15 minutos

### Opção 3: Executar Etapas Individuais

```bash
# Priorizar produtos
python priority/priority_scorer.py

# Gerar SEO
python seo_generator.py

# Gerar imagens
python generate_ai_images.py

# A/B Testing
python ab_test_images.py

# Auto-otimizar
python auto_optimize_images.py

# Upload
python upload_images.py

# Relatórios
python send_email.py
```

---

## 🧠 ESTRUTURA DE DADOS

### Input: Planilha (XLSX)

```
Colunas esperadas:
├─ SKU / et_title_parent_sku
├─ Nome / et_title_product_name
├─ Categoria
├─ Descrição
├─ Atributos (cor, tamanho, material, etc)
├─ Preço
├─ Imagem (URL original do produto)
└─ Estoque
```

**Exemplo:**
```
SKU: JVAQAC44
Nome: Assento Almofadado Preto
Categoria: Casa e Decoração
Descrição: Assento confortável com espuma
Atributos: cor=Preto, tamanho=Único, material=Espuma
Preço: R$ 89.90
Imagem: https://... (URL da imagem real do produto)
```

### Output: Dados Processados

```
storage/processed/
├─ JVAQAC44/
│  ├─ 1.jpg (Fundo branco)
│  ├─ 2.jpg (Ângulo 45°)
│  ├─ 3.jpg (Lifestyle)
│  └─ 4.jpg (Close-up)
└─ [mais produtos...]

logs/
├─ ab_test_report.txt (qual imagem venceu)
├─ optimization_report.txt (imagens ruins detectadas)
├─ seo_generated.json (SEO por marketplace)
└─ pipeline_execution_advanced.json (tudo que aconteceu)

planilhas/
├─ shopee_import.xlsx (pronto para importar)
└─ tiktok_import.xlsx (pronto para importar)
```

---

## 🔌 INTEGRAÇÕES DISPONÍVEIS

### Marketplaces

#### Shopee
```
Endpoint: https://partner.test-stable.shopeemobile.com
Autenticação: SHOPEE_PARTNER_ID + SHOPEE_PARTNER_KEY
Função: Atualiza produto (título, descrição, imagem)
Preço: NÃO altera
```

**Como usar:**
```python
from integrations.shopee_api import ShopeeAPI

api = ShopeeAPI(partner_id, partner_key)
api.update_product(shop_id, item_id, title, description, image_url)
```

#### TikTok Shop
```
Endpoint: https://seller.tiktok.com/api
Autenticação: TIKTOK_CLIENT_ID + TIKTOK_CLIENT_SECRET
Função: Atualiza produto (título, descrição, imagem)
Preço: NÃO altera
```

**Como usar:**
```python
from integrations.tiktok_api import TikTokAPI

api = TikTokAPI(client_id, client_secret)
api.update_product(product_id, title, description, image_url)
```

### Storage

#### FTP
```
Host: FTP_SERVER
User: FTP_USERNAME
Pass: FTP_PASSWORD
Path: /public_html/dev/uploads/olist/
URL: https://dev.shopvivaliz.com.br/uploads/olist/
```

**Como usar:**
```python
from integrations.ftp_uploader import FTPUploader

uploader = FTPUploader(host, user, password)
url = uploader.upload_file('local/path/image.jpg', 'remote/path/image.jpg')
```

### Email

```
SMTP_HOST: smtp.gmail.com
SMTP_PORT: 587
SMTP_USER: seu-email@gmail.com
SMTP_PASS: app-password (não senha normal)
```

**Como usar:**
```python
from integrations.email_sender import EmailSender

sender = EmailSender(smtp_host, smtp_port, smtp_user, smtp_pass)
sender.send(from_email, to_email, subject, body)
```

---

## 📊 LOGS E MONITORAMENTO

### Arquivo Principal de Execução

`logs/pipeline_execution_advanced.json`

```json
{
  "timestamp": "2026-06-29T15:41:07",
  "steps": {
    "prioritize": {
      "status": "success",
      "products_count": 165
    },
    "seo": {
      "status": "success",
      "shopee_count": 165,
      "tiktok_count": 165
    },
    "images": { "status": "success" },
    "ab_test": { "status": "success" },
    "optimize": { "status": "success" },
    "upload": { "status": "warning" },
    "analytics": { "status": "success" }
  },
  "status": "completed"
}
```

### Verificar Status

```bash
# Ver último status
cat logs/pipeline_execution_advanced.json | jq .

# Ver scores de priorização
cat logs/priority_scores.json

# Ver SEO gerado
cat logs/seo_generated.json

# Ver A/B test results
cat logs/ab_test_report.txt

# Ver otimizações
cat logs/optimization_report.txt
```

---

## 🔑 VARIÁVEIS DE AMBIENTE NECESSÁRIAS

### IA/APIs
```bash
OPENAI_API_KEY=sk-...                    # Para gerar imagens
ANTHROPIC_API_KEY=sk-ant-...             # Opcional
```

### Marketplaces
```bash
SHOPEE_PARTNER_ID=1237032                # ID do partner
SHOPEE_PARTNER_KEY=shpk...               # Key do partner
TIKTOK_CLIENT_ID=7xxxxx                  # ID da app
TIKTOK_CLIENT_SECRET=xxxxx               # Secret da app
```

### Storage
```bash
FTP_SERVER=ftp.shopvivaliz.com.br       # Host FTP
FTP_USERNAME=usuario                     # Usuário FTP
FTP_PASSWORD=senha                       # Senha FTP
FTP_PORT=21                              # Porta (default 21)
FTP_REMOTE_DIR=/public_html              # Diretório remoto
```

### Email
```bash
EMAIL_FROM=noreply@shopvivaliz.com.br   # Email remetente
EMAIL_TO=admin@shopvivaliz.com.br       # Email destino
EMAIL_SMTP_HOST=smtp.gmail.com          # Host SMTP
EMAIL_SMTP_PORT=587                      # Porta SMTP
EMAIL_USER=seu-email@gmail.com          # Usuário
EMAIL_PASSWORD=app-password              # App password (Gmail)
```

### Banco de Dados (Opcional)
```bash
DB_HOST=localhost
DB_NAME=shopvivaliz
DB_USER=root
DB_PASSWORD=senha
```

---

## 🛠️ COMO ESTENDER O SISTEMA

### Adicionar Novo Marketplace

1. **Criar arquivo de integração:**
```python
# scripts/integrations/novo_marketplace_api.py

class NovoMarketplaceAPI:
    def __init__(self, api_key, api_secret):
        self.api_key = api_key
        self.api_secret = api_secret
    
    def update_product(self, product_id, title, description, image_url):
        # Implementar lógica de atualização
        pass
```

2. **Adicionar ao main.py:**
```python
# scripts/main.py

def step_8_update_novo_marketplace(seo_results):
    logger.info("Atualizando NovoMarketplace...")
    # Implementar
```

### Adicionar Novo Tipo de Imagem

1. **Adicionar prompt:**
```python
# scripts/ia/image_prompts.py

PROMPTS = {
    1: "fundo branco...",
    2: "ângulo 45°...",
    3: "lifestyle...",
    4: "close-up...",
    5: "nova variante..."  # NOVO
}
```

2. **Atualizar lógica:**
```python
# scripts/generate_ai_images.py

for variant_num in range(1, 6):  # De 4 para 5
    prompt = PROMPTS[variant_num]
    # Gerar imagem
```

### Adicionar Novo Critério de Priorização

1. **Estender Priority Scorer:**
```python
# scripts/priority/priority_scorer.py

def calculate_score(self, product):
    score = ... # score existente
    
    # Novo fator
    novo_fator = product.get('novo_atributo', 0) * 10
    score += novo_fator
    
    return min(100, score)
```

---

## 🐛 TROUBLESHOOTING

### Erro: "Imagem gerada muito pequena"

**Problema:** Imagens com resolução < 300x300px

**Solução:**
```bash
# Aumentar resolução mínima
# scripts/auto_optimize_images.py, linha ~50

MIN_WIDTH = 500  # De 300 para 500
MIN_HEIGHT = 500  # De 300 para 500
```

### Erro: "FTP connection refused"

**Problema:** Conexão FTP falha

**Solução:**
```bash
# Verificar credenciais
echo $FTP_SERVER $FTP_USERNAME

# Testar conexão
curl -u usuario:senha ftp://ftp.servidor.com/

# Verificar firewall
ping ftp.servidor.com
```

### Erro: "Shopee API 401 Unauthorized"

**Problema:** Credenciais inválidas

**Solução:**
```bash
# Verificar secrets
gh secret list | grep SHOPEE

# Regenerar credenciais em partner.shopee.com.br
# Atualizar GitHub Secrets
```

### Erro: "OpenAI rate limit exceeded"

**Problema:** Muitas requisições à OpenAI

**Solução:**
```bash
# Adicionar delay entre requisições
# scripts/generate_ai_images.py

time.sleep(2)  # Entre cada geração

# Ou aumentar timeout
openai.timeout = 60  # De 30 para 60 segundos
```

---

## 📞 CONTATOS E RECURSOS

### Documentação Interna
- `SISTEMA_AUTOMACAO_COMPLETO.md` - Visão técnica completa
- `INTEGRACAO_MARKETPLACES.md` - Como integrar Shopee/TikTok
- `SETUP_SECRETS_GUIDE.md` - Como configurar credenciais

### Documentação Externa
- Shopee Partner: https://partner.shopee.com.br/docs
- TikTok Shop: https://seller.tiktok.com/docs
- OpenAI API: https://platform.openai.com/docs

### GitHub
- Repositório: https://github.com/fredmourao-ai/site-shopvivaliz
- Issues: Para reportar bugs
- Discussions: Para dúvidas

---

## ✅ CHECKLIST PARA NOVOS AGENTES

- [ ] Clonar repositório
- [ ] Instalar dependências: `pip install -r requirements.txt`
- [ ] Configurar variáveis de ambiente
- [ ] Testar conexões: `python scripts/verify_secrets.py`
- [ ] Executar pipeline: `python scripts/main.py`
- [ ] Verificar logs: `cat logs/pipeline_execution_advanced.json`
- [ ] Acessar painel: https://dev.shopvivaliz.com.br/admin/monitor/

---

## 🎯 OBJETIVO DO SISTEMA

✅ **Automação Completa:** Nenhuma ação manual necessária  
✅ **Inteligência:** Sistema aprende com dados  
✅ **Escalabilidade:** Funciona com 10 ou 10.000 produtos  
✅ **Confiabilidade:** Tratamento de erros e fallbacks  
✅ **Transparência:** Logs detalhados de tudo  

---

**Pronto para ser usado por qualquer agente IA!** 🤖

Para perguntas ou sugestões, consulte a documentação ou abra uma issue no GitHub.
