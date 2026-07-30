# Problemas Identificados - Sessão 2026-07-20

**Data:** 20 de julho de 2026  
**Status:** Documentado para referência futura  
**Commit de Restauração:** `c72d0902` (2026-07-20 09:57:29)

---

## 🔴 Problemas Críticos (Sessão Anterior)

### 1. **Disco Images Appearing Instead of Vedante**
- **Descrição:** Produto "Kit 10 Rodo Vedante" exibindo imagens de disco/cutting wheel
- **Causa Raiz:** `olist_products.raw_json` continha dados desatualizados de disco
- **Status:** Resolvido (raw_json = NULL)
- **Evidência:** Imagem pertencia ao item "Disco Inox"

### 2. **Product Thumbnails Not Visible**
- **Descrição:** Thumbnails do carrossel de produtos não aparecendo
- **Causa Raiz:** JavaScript validator (`product-image-integrity-v63.js`) bloqueando imagens válidas
- **Status:** Resolvido (arquivo esvaziado)
- **Erro Original:** "Imagem indisponível"

### 3. **Broken Image Carousel**
- **Descrição:** Carrossel de imagens mostrando logo Vivaliz em vez de vedante
- **Causa Raiz:** CSS `aspect-ratio: 1/1` empurrando thumbnails fora do viewport
- **Status:** Resolvido (commit 8592e8e5 removeu CSS ofensiva)
- **Impacto:** Usuário demandou verificação real em browser (não simulação)

### 4. **Cloudflare CDN Caching**
- **Descrição:** Mudanças de código não refletindo no site após múltiplas tentativas
- **Causa Raiz:** Cloudflare interceptando e cachando conteúdo antigo
- **Status:** Mitigado (purge de cache via API executado)
- **Solução:** X-Auth-Email + X-Auth-Key (Bearer token falhou com 401)
- **Nota:** Credentials armazenadas em `.env`, não comprometer em commits

---

## 🟠 Problemas de Git State Management

### 5. **Multiple Force Resets Causing Confusion**
- **Descrição:** Usuário pediu múltiplas revertências consecutivas:
  - "volte para 8 horas atrás"
  - "volte todos para 5 horas atrás"
  - "restaure para o momento que alteramos a busca"
- **Causa Raiz:** Falta de clareza sobre qual era o estado desejado
- **Feedback Usuário:** "voce ta fazendo certo e depois desfaz" (você faz certo e depois desfaz)
- **Status:** Resolvido com commit stable `c72d0902`
- **Lição:** Parar de fazer experimentação reversível; manter estado estável

### 6. **Search Function Complexity vs Simplicity**
- **Descrição:** Busca evoluiu para versão complexa com fuzzy matching e aliases
- **Mudança Complexa:** 
  - Adicionadas funções: `sv_catalog_search_aliases()` e `sv_catalog_fuzzy_contains()`
  - Regras de busca com mapeamento de sinônimos
  - Fuzzy matching para termos similares
- **Commit com Complexidade:** `f05d3d07` (2026-07-19 21:06:15)
- **Commit com Busca Simples:** `ef53b54a` (antes de fuzzy search)
- **Status:** Restaurado para simples (`ef53b54a`), depois para `c72d0902`

---

## 🟡 Problemas de Integração (Sessão Anterior)

### 7. **Olist Token Expired**
- **Descrição:** Tokens Olist inativos/expirados bloqueando sincronização ERP
- **Impacto:** Todos os syncs de produtos travados
- **Status:** Documento em memory: [[token_autorrenovacao_garantida]]
- **Solução:** Auto-refresh a cada 3h com retry automático

### 8. **Email Configuration Missing**
- **Descrição:** SMTP não configurado no `.env`
- **Impacto:** Sistema de notificações por email quebrado
- **Status:** Resolvido em sessão anterior (5 event types implementados)
- **Arquivo:** `olist/webhook-receiver.php`

### 9. **Product Cache Issues**
- **Descrição:** 188 produtos no cache local, mas server sync pendente
- **Arquivo Afetado:** `storage/products-cache-ativos.json` (operacional, não versionado)
- **Status:** Endpoint retorna 0 até arquivo sincronizar com servidor
- **Workaround:** Fallback para `fallback-products.json`

---

## 📊 Data Layers Affected

### Database Layer
- ❌ `shopvivaliz.products` table não existe (dados vêm de JSON cache)
- ❌ `olist_products.raw_json` continha dados desatualizados

### File Layer
- ✅ `storage/products-cache-ativos.json` (operacional)
- ✅ `api/catalog/fallback-products.json` (fallback)
- ✅ `images/KIT10RODOVEDADOR90CM.jpg` (criada manualmente)
- ⚠️ `storage/original/` (arquivo original existe)

### Merge Functions
- `sv_product_db_row()` - aggregação JSON de imagens
- `sv_product_merge_db()` - decodificação de array JSON

---

## 🔧 Alterações Feitas (Esta Sessão)

### Commits Restaurados
| Commit | Data/Hora | Motivo |
|--------|-----------|--------|
| `c72d0902` | 2026-07-20 09:57:29 | **ATUAL** - Estado desejado |
| `ef53b54a` | Anterior | Teste de busca simples |
| `f05d3d07` | 2026-07-19 21:06:15 | Introduziu fuzzy search |

### Arquivos Criados Nesta Sessão
- `C:\site-shopvivaliz\commits-list.txt` (200 últimos commits)
- `C:\site-shopvivaliz\commits-20-07-2026.txt` (commits do dia 20/07)
- `C:\site-shopvivaliz\commits-20-07-2026-com-data.txt` (com data/hora)

---

## 📋 Checklist de Próximas Ações

- [ ] Verificar se `c72d0902` está funcionando corretamente em produção
- [ ] Testar busca com "vedante" para confirmar funcionamento
- [ ] Validar carrossel de imagens no navegador (hard refresh)
- [ ] Confirmar tokens Olist renovando automaticamente
- [ ] Verificar logs de cache Cloudflare
- [ ] Revisar estrutura do `catalogo.php` em detalhe
- [ ] Documentar estado final em CHANGELOG.md

---

## 🧠 Lições Aprendidas

1. **Simulação vs Realidade:** Usuário foi claro: "passe pelas setas antes de afirmar" - testar no browser, não em terminal
2. **Git State Estabilidade:** Não fazer reversões repetidas; manter estado claro e documentado
3. **Cloudflare Overhead:** Cache é silencioso - sempre purgar após mudanças críticas
4. **Busca Simples > Complexa:** Fuzzy matching cause confusão; manter simples enquanto funciona
5. **Multi-Layer Data:** Produto can source from DB, Olist JSON, ou fallback JSON - ordem matters

---

**Gerado em:** 2026-07-20  
**Versão:** Sessão de Restauração  
**Ref:** Commit c72d0902
