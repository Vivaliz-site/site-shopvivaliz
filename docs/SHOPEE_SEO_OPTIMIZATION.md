# Otimizacao SEO Inteligente para Shopee

## Visao Geral

Este documento descreve os scripts disponíveis para otimizar títulos de produtos na plataforma Shopee, com foco em SEO (Search Engine Optimization) e melhoria de visibilidade.

## Scripts Disponíveis

### 1. `shopee_title_optimizer.py` (Recomendado - API)

**Uso:**
```bash
python scripts/shopee_title_optimizer.py --method api
```

**Requisitos:**
- Variáveis de ambiente configuradas:
  - `SHOPEE_PARTNER_ID`
  - `SHOPEE_PARTNER_KEY`
  - `SHOPEE_SHOP_ID`
  - `SHOPEE_ACCESS_TOKEN` ou `SHOPEE_REFRESH_TOKEN`
- Token de OpenAI: `OPENAI_API_KEY` (opcional, para SEO com IA)

**Vantagens:**
- Atualiza TODO o catálogo automaticamente
- Usa Shopee Partner API v2 (oficial)
- Gera títulos com otimizacoes SEO via IA
- Verifica cada alteracao apos aplicar
- Faz rollback automaticamente se falhar

**Desvantagens:**
- Requer credenciais Shopee Partner configuradas
- Requer tokens de API

**O que otimiza:**
- Adiciona palavras-chave de busca
- Inclui marca/modelo/material/tamanho/cor
- Limpa caracteres especiais
- Respeita limite maximo de 120 caracteres

---

### 2. `shopee_seo_browser_optimizer.py` (Fallback - Browser)

**Uso:**
```bash
# Instalar Selenium (uma vez)
pip install selenium

# Executar
python scripts/shopee_seo_browser_optimizer.py
```

**Requisitos:**
- Chrome/Chromium instalado
- Acesso manual a https://seller.shopee.com.br (login manual)
- Produtos publicados na loja

**Vantagens:**
- Nao requer configuracao de tokens/variaveis de ambiente
- Interface visual: revisar cada titulo antes de aplicar
- Score SEO (0-100) para cada titulo
- Sugestoes de melhoria automaticas
- Controle manual: usuario decide se aplica cada mudanca

**Desvantagens:**
- Mais lento (processamento manual)
- Limitado a primeiros 20 produtos (para nao demorar)
- Requer intervencao manual do usuario

**Fluxo:**
1. Script abre navegador Chrome automaticamente
2. Voce faz login manual no Shopee (tem 180 segundos)
3. Script analisa cada titulo e exibe:
   - Score SEO (0-100)
   - Problemas encontrados
   - Sugestoes de melhoria
4. Se score < 70, abre editor automaticamente
5. Voce pode aceitar a sugestao ou editar manualmente

---

## Metricas de SEO Analisadas

### Score SEO (0-100)
Baseado em:
- **Comprimento (30-110 chars):** ideal
- **Palavras-chave:** qualidade, novo, original, promocao, desconto, kit
- **Especificacoes:** tamanho, cor, material (se relevante)
- **Sem caracteres especiais:** limpeza de símbolos desnecessários

### Issues Comuns Encontrados
1. Titulo muito curto (< 10 caracteres)
2. Titulo muito longo (> 120 caracteres)
3. Falta palavras-chave de busca
4. Sem informacoes de tamanho/dimensoes (se relevante)

---

## Configuracao de Credenciais Shopee (Opcional)

Se quiser usar a API automaticamente, configure as variaveis de ambiente:

### Linux/Mac
Configure as variaveis de ambiente com seus valores reais (nao coloque no repositorio):
```bash
export SHOPEE_PARTNER_ID=<seu_id>
export SHOPEE_PARTNER_KEY=<sua_chave>
export SHOPEE_SHOP_ID=<seu_shop_id>
export SHOPEE_ACCESS_TOKEN=<seu_token>
export SHOPEE_REFRESH_TOKEN=<seu_refresh_token>
```

### Windows PowerShell
```powershell
$env:SHOPEE_PARTNER_ID = "<seu_id>"
$env:SHOPEE_SHOP_ID = "<seu_shop_id>"
```

### GitHub Actions / Produção
Configurar em: Settings > Secrets and variables > Actions
(Nao inclua exemplos de tokens no repositorio)

---

## Exemplos de Otimizacao

### Antes
```
Rodizio 50MM
```
**Score:** 45/100
**Problemas:** Muito curto, sem palavras-chave

### Depois (Sugestao)
```
Rodizio 50MM Soprano Premium - Novo - Qualidade
```
**Score:** 78/100
**Melhorias:** Adicionou marca (Soprano), "Premium", "Novo", "Qualidade"

---

## Fluxo Recomendado

### Opcao 1: Automacao Total (API + IA)
```bash
# Pre-requisito: configurar secrets Shopee + OPENAI_API_KEY
python scripts/shopee_title_optimizer.py --method api
# Resultado: TODO o catálogo atualizado em minutos
```

### Opcao 2: Revisar Manualmente (Browser)
```bash
# Nenhuma configuracao necessária
python scripts/shopee_seo_browser_optimizer.py
# Resultado: Revisar cada titulo, aprovar mudancas
```

### Opcao 3: Auto-fallback
```bash
# Tenta API primeiro, se falhar usa browser
python scripts/shopee_title_optimizer.py --method auto
```

---

## Monitoramento pos-Otimizacao

### Verificar Impacto
1. Acesse: https://seller.shopee.com.br/analytics
2. Monitore por 24-48 horas:
   - Impressoes de produto
   - Taxa de cliques
   - Conversoes

### Rollback (Desfazer Mudancas)
Se algo correr mal:
- Backups sao salvos automaticamente em: `storage/private/shopee-optimizer-backups/`
- Script de producao faz verificacao apos cada atualizacao

---

## Troubleshooting

### Erro: "SHOPEE_PARTNER_ID não configurado"
**Solucao:** Configure as variaveis de ambiente ou use `--method browser`

### Erro: "Selenium não instalado"
**Solucao:** `pip install selenium`

### Chrome não inicia
**Solucao:** Certifique-se que Chrome esta instalado
```bash
# Linux
sudo apt-get install chromium-browser

# Mac
brew install --cask google-chrome

# Windows
# Baixe de: https://www.google.com/chrome/
```

### Timeout no login (browser)
**Solucao:** Aumentar tempo em: `WebDriverWait(driver, 180)` (segundos)

---

## Notas de Seguranca

### Tokens e Credenciais
- NUNCA compartilhe `SHOPEE_PARTNER_KEY` ou access tokens
- Use variaveis de ambiente, NAO hardcode
- Tokens sao salvos em `storage/private/shopee-tokens.json` (nao versionado)

### Auditoria
- Todos os backups estao em `storage/private/`
- Logs de mudancas em `logs/shopee-optimizer/`

---

## Roadmap

- [ ] Integracao com Google Trends para keywords
- [ ] Teste A/B de titulos
- [ ] Analytics de performance por titulo
- [ ] Otimizacao de descricoes (nao so titulos)
- [ ] Sincronizacao cross-marketplace

---

**Criado:** 2026-08-13  
**Ultimo update:** 2026-08-13
