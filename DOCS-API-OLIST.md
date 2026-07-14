# Documentação das APIs Olist/Tiny

## API V3 (OAuth - Expira)
**URL:** https://tiny.com.br/api-docs/api2
**Autenticação:** Bearer Token (OAuth 2.0)
**Validade:** 4 horas (precisa refresh_token)
**Refresh:** Automático via daemon-token-renewer.py a cada 3 horas

**Endpoints úteis:**
- GET `/public-api/v3/produtos` - Listar produtos (estrutura moderna)
- Filtro por status: `situacao=A` (apenas ativos)
- Paginação: `limit` e `offset`

---

## API V2 (Token Fixo - Não Expira)
**URL:** https://tiny.com.br/api-docs/api2
**Autenticação:** Token como query parameter
**Validade:** Permanente (não expira)
**Fallback:** Use quando V3 estiver fora

**Endpoints úteis:**
- GET `/api/v2/produtos.json?token=XXX&pagina=1&limite=100`
- Estrutura legada mas confiável
- Melhor para sincronização de fallback

---

## Configuração

### .env Necessário
```
# API V3 (OAuth)
OLIST_CLIENT_ID=tiny-api-...
OLIST_CLIENT_SECRET=NXJ48oBYIBolUTI9...
OLIST_ACCESS_TOKEN=eyJhbGciOiJSUzI1NiI...
OLIST_REFRESH_TOKEN=eyJhbGciOiJIUzI1NiI...

# API V2 (Integrador)
OLIST_INTEGRADOR_ID=31816
OLIST_INTEGRADOR_TOKEN=9fc7a699f6946099733fd...
```

---

## Webhooks

### Tipos de Evento
- `produto.criado` / `produto.atualizado` / `produto.alterado`
- `preco.alterado`
- `estoque.alterado`
- `tipo: "estoque"` (formato Olist v1)
- `tipo: "preco"` (formato Olist v1)
- `tipo: "produto"` (formato Olist v1)

### URLs Configuradas
- **Produtos:** https://dev.shopvivaliz.com.br/olist/webhook-receiver.php?event=product
- **Estoque:** https://dev.shopvivaliz.com.br/olist/webhook-receiver.php?event=stock
- **Preços:** https://dev.shopvivaliz.com.br/olist/webhook-receiver.php?event=price

### Fluxo
```
Alteração na Olist → Webhook POST → webhook-receiver.php
  → Valida tipo de evento
  → Executa sync-on-webhook.php
  → Atualiza storage/products-cache-ativos.json
  → API retorna dados em <10 segundos
```

---

## Scripts Locais

### daemon-sync-products.py
- Sincroniza a cada 5 minutos
- Usa API V3 (OAuth)
- Filtra `situacao=A` (ativos apenas)
- Salva em `storage/products-cache-ativos.json`

### daemon-token-renewer.py
- Renova OAuth token a cada 3 horas
- Usa refresh_token
- Atualiza .env automaticamente

### sync-via-api-v2.php
- Fallback quando V3 estiver fora
- Usa token fixo (não expira)
- Sincronização manual ou cron

### sync-on-webhook.php
- Disparado por webhooks
- Sincronização em tempo real (<10 seg)
- API V3

---

## Status Atual
- ✅ Webhooks: Configurados na Olist
- ⚠️ Tokens: **EXPIRADOS** - precisa regenerar
- ⚠️ Cache: Vazia (aguardando novos tokens)
- ✅ Daemons: Prontos para usar

---

## Próximos Passos
1. **Gerar novos tokens** (via OAuth ou painel Olist)
2. **Atualizar .env** com novas credenciais
3. **Testar webhooks** alterando estoque na Olist
4. **Verificar cache** em storage/products-cache-ativos.json
5. **Monitorar daemons** em logs/
