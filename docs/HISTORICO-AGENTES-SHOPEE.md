# Histórico de Agentes Shopee — ShopVivaliz

**Repositório:** `fredmourao-ai/site-shopvivaliz`  
**Última atualização:** 2026-06-27  
**Branch de origem:** `claude/guth-portfolio-access-81jjq2`

> Documento de consulta para agentes. Descreve o que foi implementado, como usar, quais secrets são necessários e quais limitações existem.

---

## 1. Agentes implementados (v9.2.85)

### 1.1 ShopeeListingsExtractorAgent

| Campo | Valor |
|---|---|
| Arquivo | `agents/v9.2.85/app/ShopeeListingsExtractorAgent.php` |
| Script CLI | `agents/v9.2.85/scripts/fetch-shopee-listings.php` |
| Workflow | `.github/workflows/fetch-shopee-listings.yml` |
| Saída | `listings/shopee-listings-YYYYMMDD-HHmmss.json` |

**Função:** Busca todos os produtos do Tiny/Olist ERP via API v3 e salva em JSON.

**Campos extraídos por produto:**
- `id`, `sku`, `nome`, `situacao`, `preco`, `preco_promocional`
- `estoque`, `unidade`, `gtin`, `categoria`, `marca`, `imagens[]`, `qtd_variacoes`

**Como executar:**
```bash
# Via GitHub Actions (recomendado)
# Actions → Fetch Shopee Listings via Tiny API → Run workflow

# Local (com PHP 8.3+)
TINY_ACCESS_TOKEN=xxx php agents/v9.2.85/scripts/fetch-shopee-listings.php
OUTPUT_FILE=listings/meu-arquivo.json TINY_ACCESS_TOKEN=xxx php agents/v9.2.85/scripts/fetch-shopee-listings.php
```

---

### 1.2 ShopeeListingsOptimizationAgent

| Campo | Valor |
|---|---|
| Arquivo | `agents/v9.2.85/app/ShopeeListingsOptimizationAgent.php` |
| Script CLI | `agents/v9.2.85/scripts/optimize-shopee-listings.php` |
| Workflow | `.github/workflows/optimize-shopee-listings.yml` |
| Saída | `listings/optimization-report-YYYYMMDD-HHmmss.json` |

**Função:** Otimiza título, descrição, atributos e SEO de cada produto no Tiny via PUT. Nunca altera preços.

**Campos otimizados:** `nome`, `descricao`, `atributos`, `palavras_chave`  
**Campos protegidos (nunca tocados):** `preco`, `preco_promocional`, `preco_custo`

**Como executar:**
```bash
# Via GitHub Actions (recomendado)
# Actions → Optimize Shopee Listings via Tiny API → Run workflow

# Local
TINY_ACCESS_TOKEN=xxx ANTHROPIC_API_KEY=yyy php agents/v9.2.85/scripts/optimize-shopee-listings.php
```

---

## 2. Secrets necessários

### 2.1 Autenticação Tiny/Olist (ao menos um obrigatório)

| Secret | Descrição |
|---|---|
| `TINY_ACCESS_TOKEN` | Bearer token direto (mais simples) |
| `TINY_API_TOKEN` | Alternativa ao ACCESS_TOKEN |
| `ERP_API_TOKEN` | Alternativa genérica |
| `OLIST_ACCESS_TOKEN` | Token Olist equivalente |
| `TINY_CLIENT_ID` + `TINY_CLIENT_SECRET` + `TINY_REFRESH_TOKEN` | OAuth2 com refresh automático |
| `OLIST_CLIENT_ID` + `OLIST_CLIENT_SECRET` + `OLIST_REFRESH_TOKEN` | Alternativa OAuth2 Olist |

Os agentes testam cada opção na ordem acima e usam o primeiro disponível. Se nenhum estiver configurado, retornam `status=error` com lista dos nomes ausentes (sem expor valores).

### 2.2 IA para otimização (opcional)

| Secret | Provedor | Modelo usado |
|---|---|---|
| `ANTHROPIC_API_KEY` | Anthropic (primário) | `claude-haiku-4-5-20251001` |
| `OPENAI_API_KEY` | OpenAI (fallback) | `gpt-4o-mini` |

Se nenhuma chave de IA estiver disponível, o agente de otimização opera em **modo rule-based** (sem custo, sem chamada externa), aplicando regras estruturadas de título e descrição.

---

## 3. Endpoints Tiny utilizados

| Método | Endpoint | Uso |
|---|---|---|
| `GET` | `/public-api/v3/produtos?limit=100&offset=N` | Listagem paginada |
| `GET` | `/public-api/v3/produtos/{id}` | Detalhe completo |
| `PUT` | `/public-api/v3/produtos/{id}` | Aplicar otimização |
| `POST` | `https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token` | OAuth2 refresh |

Base URL da API: `https://api.tiny.com.br/public-api/v3`

---

## 4. Formato dos arquivos de saída

### listings/shopee-listings-*.json (extração)
```json
{
  "agent": "shopee_listings_extractor",
  "version": "9.2.85",
  "generated_at": "2026-06-27T03:00:00+00:00",
  "secrets_check": { "token_source": "TINY_ACCESS_TOKEN", "token_available": true },
  "status": "success",
  "total_products": 250,
  "products": [
    {
      "id": 123,
      "sku": "PROD-001",
      "nome": "Nome do Produto",
      "situacao": "A",
      "preco": 49.90,
      "preco_promocional": null,
      "estoque": 10,
      "unidade": "UN",
      "gtin": "7891234567890",
      "categoria": "Categoria",
      "marca": "Marca",
      "imagens": ["https://..."],
      "qtd_variacoes": 0
    }
  ],
  "errors": []
}
```

### listings/optimization-report-*.json (otimização)
```json
{
  "agent": "shopee_listings_optimization",
  "version": "9.2.85",
  "generated_at": "2026-06-27T03:00:00+00:00",
  "ai_provider": "anthropic",
  "status": "success",
  "total_products": 250,
  "optimized": 248,
  "skipped": 2,
  "errors": [],
  "log": [
    {
      "sku": "PROD-001",
      "id": 123,
      "titulo_antes": "Título antigo",
      "titulo_novo": "Marca Produto Modelo Atributo Principal Benefício",
      "descricao_antes": "desc antiga (80 chars)...",
      "descricao_nova": "desc nova (80 chars)...",
      "imagens_antes": 2,
      "imagens_depois": 2,
      "status": "optimized",
      "motivo": null
    }
  ]
}
```

---

## 5. Limitações e pontos de atenção

| Limitação | Detalhe |
|---|---|
| Imagens | O agente audita e alerta quantidade < 3, mas não adiciona novas imagens (requer URLs de origem). |
| Preços | Protegidos por design — nunca incluídos no payload de atualização. |
| Rate limit Tiny | 250ms entre GETs, 300ms entre PUTs. Máximo 50 páginas por execução. |
| OAuth2 | Se `TINY_REFRESH_TOKEN` estiver expirado, a extração falha com `status=error`. Renovar manualmente no ERP. |
| IA sem key | Cai automaticamente em rule-based. Títulos e descrições melhoram, mas sem criatividade/contexto de IA. |
| Issue #29 | `ANTHROPIC_API_KEY`, `OPENAI_API_KEY` e `GOOGLE_API_KEY` ainda podem estar ausentes nos secrets. |

---

## 6. Regras de segurança obrigatórias para agentes que estendam este trabalho

1. Nunca imprimir valores de secrets em logs, commits, issues ou saída padrão.
2. Validar secrets apenas por nome: `printenv | grep -E 'TINY|OLIST' | sed 's/=.*/=***MASKED***/'`
3. Nunca incluir `preco`, `preco_promocional` ou `preco_custo` em payloads de update.
4. Sempre implementar anti-loop na paginação (checar IDs repetidos e página vazia).
5. Sempre implementar delay entre chamadas à API Tiny (mínimo 200ms GET, 300ms PUT).
6. Manter relatório before/after para permitir rollback manual por SKU.
7. Consultar `docs/olist-tiny-erp-api-knowledge-v2.md` como fonte principal de regras de API.

---

## 7. Histórico de sessões

| Data | Branch | O que foi feito |
|---|---|---|
| 2026-06-27 | `claude/guth-portfolio-access-81jjq2` | Criação de `ShopeeListingsExtractorAgent`, `ShopeeListingsOptimizationAgent`, workflows `fetch-shopee-listings.yml` e `optimize-shopee-listings.yml`, release-notes `9.2.85-shopee-listings-extractor-optimizer.json` e este documento. |

---

## 8. Próximas ações sugeridas

- [ ] Configurar `TINY_ACCESS_TOKEN` ou `TINY_CLIENT_ID`+`SECRET`+`REFRESH_TOKEN` nos GitHub Secrets (se ainda não feito).
- [ ] Configurar `ANTHROPIC_API_KEY` nos GitHub Secrets para ativar otimização com IA (issue #29).
- [ ] Executar `fetch-shopee-listings.yml` para validar conectividade com a API Tiny.
- [ ] Executar `optimize-shopee-listings.yml` em modo manual para revisar o primeiro relatório.
- [ ] Criar agente de reposição de imagens (após ter URLs das imagens oficiais do ERP).
