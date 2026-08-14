# Otimizacao SEO Shopee - Guia Rapido

## Resumo

Criei 3 scripts para otimizar inteligentemente os titulos de produtos na Shopee:

### 1. **Demo** (Aprender como funciona) - COMECE AQUI
```bash
python scripts/shopee_seo_demo.py
```
✓ Sem login necessario  
✓ Mostra exemplos de titulos  
✓ Exibe scores SEO e sugestoes  

---

### 2. **Browser Optimizer** (Revisar manualmente)
```bash
python scripts/shopee_seo_browser_optimizer.py
```
✓ Interface visual no navegador  
✓ Revisar cada titulo antes de aplicar  
✓ Score SEO para cada produto  
✓ Sem configuracao de tokens  

**Fluxo:**
1. Script abre Chrome automaticamente
2. Faca login manual na Shopee (180 segundos)
3. Script analisa titulos com scores e sugestoes
4. Voce aprova ou rejeita cada mudanca

---

### 3. **API Optimizer** (Automacao total - MAIS RAPIDO)
```bash
# Requer configurar environment variables
export SHOPEE_PARTNER_ID=seu_id
export SHOPEE_PARTNER_KEY=sua_chave
export SHOPEE_SHOP_ID=seu_shop_id
export SHOPEE_ACCESS_TOKEN=seu_token
export SHOPEE_REFRESH_TOKEN=seu_refresh_token

# Executar
python scripts/shopee_title_optimizer.py --method api
```
✓ Atualiza TODO o catalogo automaticamente  
✓ Usa IA (GPT-4) para gerar titulos otimizados  
✓ Verifica cada alteracao apos aplicar  
✓ Faz backup automatico  
✓ Rollback se algo falhar  

---

## O que eh Otimizado

A otimizacao SEO para Shopee analisa e melhora:

### Metricas de Score (0-100)
- **Comprimento ideal:** 30-110 caracteres
- **Palavras-chave:** qualidade, novo, original, lacrado, premium
- **Especificacoes:** tamanho, cor, material, capacidade
- **Clareza:** sem caracteres especiais desnecessarios

### Exemplos

#### Antes (Score: 45/100)
```
Rodizio 50MM
```
Problemas: Muito curto, sem palavras-chave

#### Depois (Score: 90/100)
```
Rodizio 50MM Premium Soprano - Novo Lacrado Qualidade
```
Melhorias: Adicionou marca, "Premium", "Novo", "Qualidade"

---

## Fluxo Recomendado

### Opcao 1: Seguro (Manual)
```bash
1. python scripts/shopee_seo_demo.py
   -> Ver como funciona
   
2. python scripts/shopee_seo_browser_optimizer.py
   -> Revisar e aprovar cada mudanca
   
3. Monitorar resultados (24-48h)
   -> Ver impacto em impressoes/cliques
```

### Opcao 2: Rapido (Automatizado)
```bash
1. Configurar SHOPEE_PARTNER_ID, SHOPEE_PARTNER_KEY, etc
2. python scripts/shopee_title_optimizer.py --method api
   -> Atualiza TODO o catalogo em minutos
3. Monitorar resultados
```

### Opcao 3: Hibrido (Tenta API, fallback Browser)
```bash
python scripts/shopee_title_optimizer.py --method auto
```

---

## Metricas de Sucesso

Monitorar apos 24-48 horas:

1. **Analytics Shopee:** https://seller.shopee.com.br/analytics
   - Impressoes de produto
   - Taxa de cliques (CTR)
   - Conversoes

2. **Esperado:**
   - +15-30% impressoes (novos titulos com melhor SEO)
   - +5-10% cliques (titulos mais atrativos)
   - -5% com variacao de conversao (alguns clientes menos segmentados)

---

## Troubleshooting

### "Chrome nao encontrado"
```bash
# Windows: Baixar de https://www.google.com/chrome/
# Linux: sudo apt install chromium-browser
# Mac: brew install --cask google-chrome
```

### "Timeout no login (browser)"
- Aumento de tempo em `WebDriverWait(driver, 180)` para 300 segundos
- Certifique-se de estar conectado a internet

### "SHOPEE_PARTNER_ID nao configurado"
- Use `--method browser` para nao precisa de tokens
- Ou configure os environment variables

### "Selenium nao instalado"
```bash
pip install selenium
```

---

## Estrutura de Arquivos

```
scripts/
├── shopee_seo_demo.py                    [DEMO - comece aqui]
├── shopee_seo_browser_optimizer.py       [Browser interativo]
├── shopee_title_optimizer.py             [API automacao]
└── utils/shopee_client.py                [Cliente Shopee v2]

docs/
└── SHOPEE_SEO_OPTIMIZATION.md            [Documentacao completa]

logs/shopee-optimizer/                    [Logs de execucao]
storage/private/shopee-optimizer-backups/ [Backups automaticos]
```

---

## Roadmap

- [ ] Otimizacao de descricoes (nao apenas titulos)
- [ ] Teste A/B de titulos
- [ ] Integracao com Google Trends para keywords
- [ ] Analytics de performance por titulo
- [ ] Sincronizacao cross-marketplace (Olist, ML, TikTok)

---

## Proximos Passos

1. **Testar agora:**
   ```bash
   python scripts/shopee_seo_demo.py
   ```

2. **Revisar manualmente (recomendado):**
   ```bash
   python scripts/shopee_seo_browser_optimizer.py
   ```

3. **Documentacao completa:**
   ```bash
   cat docs/SHOPEE_SEO_OPTIMIZATION.md
   ```

---

**Criado:** 2026-08-13  
**Suporte:** Ver `docs/SHOPEE_SEO_OPTIMIZATION.md` para detalhes tecnicos
