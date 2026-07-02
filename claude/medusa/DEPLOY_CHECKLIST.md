# Checklist final de deploy — Medusa ShopVivaliz

> Complementa `DEPLOY-CHECKLIST.md` (log de validação técnica) e
> `DEPLOY_HOSTGATOR.md` (pré-requisitos/passo a passo do servidor). Este
> arquivo é a lista de "pronto para ir ao ar" a ser conferida antes do go-live.

## Validado em ambiente local/dev (2026-07-02)

- [x] Database conectando (Postgres local, `SELECT 1` OK)
- [x] Migrations + seed rodados sem erro (`npx medusa db:migrate` +
      `seed-shopvivaliz-test-data.ts`, 11 produtos no catálogo)
- [x] Build backend sem erros (`npm run build`, 4.2s backend / 22.5s admin)
- [x] Build storefront sem erros (`npm run build`, 125 páginas estáticas)
- [x] API rodando localmente (`GET /health` → 200 OK)
- [x] Storefront renderizando produto real com preço da API
      (`/br/products/camiseta-shopvivaliz` → R$69,90)
- [x] Publishable API key criada e vinculada ao Default Sales Channel
- [x] Módulo de pagamento Stripe/PIX validado com chave de teste (não é
      teste de cobrança real — ver bloqueio abaixo)
- [x] Script `sync-olist-products.php` (`OlistSync`) existe e falha
      corretamente sem credenciais reais (comportamento esperado)

## Bloqueios para produção (ação humana obrigatória)

- [ ] **Banco de dados de produção**: criar projeto Supabase (ou Neon/Railway)
      e configurar `DATABASE_URL` real. Este ambiente não tem acesso de rede
      a `supabase.com`/`stripe.com`/`paypal.com` (política de proxy da
      organização bloqueia esses domínios), então a criação de conta e
      geração de connection string precisa ser feita manualmente em
      https://supabase.com/dashboard.
- [ ] **Pagamentos reais**: gerar chaves de teste/produção reais em
      https://dashboard.stripe.com/test/apikeys e
      https://developer.paypal.com/dashboard/applications/sandbox
      (as chaves usadas nesta validação são a chave de exemplo pública da
      documentação oficial do Stripe, não uma conta real)
- [ ] **Webhooks registrados** nos gateways (Stripe `/webhooks/stripe`,
      PayPal) — requer as contas reais acima
- [ ] **GitHub Secrets configurados**: sem `gh` CLI nem ferramenta MCP de
      secrets disponível nesta sessão; comandos prontos em
      `GITHUB_SECRETS_TODO.md` para rodar manualmente
- [ ] **Credenciais Olist/Tiny rotacionadas**: um client secret real vazou
      em texto puro no histórico do git (ver alerta em
      `GITHUB_SECRETS_TODO.md`) — rotacionar no painel Tiny antes do go-live
- [ ] **SSL certificado obtido** (Let's Encrypt/AutoSSL) no host de produção
- [ ] **DNS apontando** para o host escolhido (VPS HostGator ou
      Railway/Render/Fly.io, conforme decisão em `DEPLOY_HOSTGATOR.md`)
- [ ] **Node.js/PM2 em produção** configurados e `deploy.sh` executado
- [ ] **Backup automático** do Postgres de produção configurado (Supabase
      tem backup diário no plano free com retenção de 7 dias; verificar se é
      suficiente ou contratar plano com PITR)

## Status

**BLOCKERS_PENDENTES** — infraestrutura de código e validação local completas;
faltam apenas ações que exigem credenciais/contas humanas (banco gerenciado,
gateways de pagamento, DNS/SSL do domínio de produção, rotação Olist).
Ver `deploy-status-2026-07-02.json` para o relatório machine-readable.
