# 🚀 ShopVivaliz Medusa - Production Deployment Checklist

**Projeto:** ShopVivaliz E-commerce  
**Framework:** MedusaJS v2.0 + Next.js 14  
**Database:** PostgreSQL (Supabase)  
**Host:** HostGator  
**Data:** 01/07/2026

---

## ✅ PRÉ-DEPLOYMENT (Local)

- [x] Backend compilando sem erros
- [x] Storefront compilando sem erros
- [x] Dependencies resolvidas (--legacy-peer-deps)
- [x] .env configurado (development)
- [x] test-checkout.html funcional
- [x] Mock server testado
- [ ] npm install completo (em progresso)
- [ ] npm run build (backend)
- [ ] npm run build (storefront)

---

## ✅ DATABASE SETUP

### Supabase (Recomendado)
- [ ] Criar conta em supabase.com
- [ ] Novo projeto: "shopvivaliz-prod"
- [ ] Copiar connection string
- [ ] Adicionar em .env: `DATABASE_URL=postgresql://...`
- [ ] Rodar: `npm run migrate:latest`
- [ ] Rodar: `npm run seed`

### Alternativa: PostgreSQL Local
- [ ] PostgreSQL 14+ instalado
- [ ] Criar usuário: `medusa`
- [ ] Criar database: `shopvivaliz_medusa`
- [ ] Testar: `psql -c "SELECT 1"`

---

## ✅ ENVIRONMENT VARIABLES

### Backend (.env)
- [x] DATABASE_URL
- [x] NODE_ENV=development
- [x] MEDUSA_BACKEND_URL
- [x] JWT_SECRET
- [x] COOKIE_SECRET
- [x] ADMIN_CORS
- [x] STORE_CORS

### Payment Gateways (Teste)
- [x] STRIPE_API_KEY (sk_test_...)
- [x] STRIPE_PUBLIC_KEY (pk_test_...)
- [x] PAYPAL_CLIENT_ID (sandbox)
- [x] PAGARME_API_KEY

### Marketplace APIs
- [x] OLIST_CLIENT_ID
- [x] OLIST_CLIENT_SECRET
- [x] SHOPEE_API_KEY
- [x] AMAZON_ACCESS_KEY

### EHA Integration
- [x] EHA_WEBHOOK_SECRET
- [x] MEDUSA_WEBHOOK_URL
- [x] EHA_WEBHOOK_ENABLED=true

---

## ✅ BUILD & TESTING

### Backend Build
- [ ] npm run build (deve gerar dist/)
- [ ] Sem erros ou warnings críticos
- [ ] Tamanho do bundle aceitável

### Storefront Build
- [ ] npm run build (deve gerar .next/)
- [ ] Sem erros ou warnings críticos
- [ ] Performance aceitável

### Local Testing
- [ ] Backend rodando em localhost:9000
- [ ] GET /health respondendo
- [ ] Storefront rodando em localhost:3000
- [ ] Home page carregando
- [ ] Catálogo mostrando produtos
- [ ] Carrinho funcionando
- [ ] Checkout funcional

---

## ✅ PRODUCTION CONFIGURATION

### HostGator Setup
- [ ] SSH acesso configurado
- [ ] Node.js 18+ instalado (via nvm)
- [ ] PM2 instalado globalmente
- [ ] Repositório clonado

### Environment (Production)
- [ ] DATABASE_URL apontando para Supabase
- [ ] NODE_ENV=production
- [ ] MEDUSA_BACKEND_URL=https://api.shopvivaliz.com.br
- [ ] Frontend URL=https://shopvivaliz.com.br

### SSL/TLS
- [ ] Certificado Let's Encrypt obtido
- [ ] Apache/Nginx com HTTPS configurado
- [ ] Redirecionamento HTTP→HTTPS

### Reverse Proxy
- [ ] Apache VirtualHost configurado
- [ ] ProxyPass para backend (9000)
- [ ] ProxyPass para storefront (3000)
- [ ] Headers CORS ajustados

---

## ✅ DEPLOYMENT

### Backend Production
```bash
npm install --legacy-peer-deps
npm run build
npm run migrate:latest
pm2 start dist/index.js --name medusa-api
pm2 save
pm2 startup
```

### Storefront Production
```bash
npm install
npm run build
pm2 start npm --name medusa-web -- start
pm2 save
```

### Monitoramento
- [ ] PM2 Plus conectado
- [ ] Logs configurados
- [ ] Alertas configurados
- [ ] Health checks automáticos

---

## ✅ INTEGRATIONS

### Payment Gateways
- [ ] Stripe webhook registrado
- [ ] PayPal webhook registrado
- [ ] Pagar.me webhook registrado
- [ ] PIX automático ativado

### Marketplace Sync
- [ ] Olist webhook registrado
- [ ] GitHub Actions workflow ativo
- [ ] Sync automático a cada 6h
- [ ] Logs de sincronização

### GitHub Secrets
- [ ] DATABASE_URL
- [ ] STRIPE_API_KEY
- [ ] PAYPAL_CLIENT_ID
- [ ] OLIST_CLIENT_ID
- [ ] SHOPEE_API_KEY
- [ ] AMAZON_ACCESS_KEY

---

## ✅ AUTONOMOUS AGENTS

### Cloud Agents
- [x] ShopVivaliz Autonomo (hourly)
- [x] Medusa Completion (hourly)
- [x] Full Deployment (every 2h)

### Monitoring
- [ ] Agentes validando health
- [ ] Logs sendo capturados
- [ ] Relatórios automáticos

---

## ✅ BACKUP & DISASTER RECOVERY

### Database Backup
- [ ] Backup diário Supabase
- [ ] Backup local (via script)
- [ ] Retenção: 30 dias

### Application Backup
- [ ] Git repositório atualizado
- [ ] Release tags criadas
- [ ] Rollback plan documentado

---

## ✅ MONITORING & ALERTING

### Uptime Monitoring
- [ ] Healthcheck endpoint: `/health`
- [ ] Monitoramento a cada 5 min
- [ ] Alert se DOWN > 5 min

### Performance Monitoring
- [ ] PM2 Plus dashboard
- [ ] CPU/Memory limits
- [ ] Response time tracking
- [ ] Error rate monitoring

### Logging
- [ ] CloudWatch ou similar
- [ ] Aggregação de logs
- [ ] Retention: 30 days
- [ ] Search enabled

---

## ✅ FINAL VALIDATION

- [ ] Home page carregando em prod
- [ ] Catálogo acessível
- [ ] Checkout funcional
- [ ] Pagamento em teste processando
- [ ] Marketplace sincronizando
- [ ] Webhooks recebendo eventos
- [ ] Agentes rodando autonomamente
- [ ] Logs sendo capturados

---

## ✅ GO-LIVE CHECKLIST

- [ ] Todos os checkboxes preenchidos
- [ ] Testes em produção aprovados
- [ ] Time notificado
- [ ] Rollback plan pronto
- [ ] On-call person designado
- [ ] Monitoramento ativo

---

## 📞 Contacts & Resources

**Documentação:**
- `/claude/medusa/DEPLOY_HOSTGATOR.md`
- `/claude/medusa/DEPLOY_PRODUCTION.sh`
- `/claude/medusa/setup-payment-gateways.md`

**Links Importantes:**
- Supabase: https://supabase.com/dashboard
- Stripe: https://dashboard.stripe.com
- GitHub: https://github.com/fredmourao-ai/site-shopvivaliz
- Routines: https://claude.ai/code/routines

**Contacts:**
- Email: fredmourao@gmail.com
- Support: Check GitHub Issues

---

## 🎯 Success Criteria

✅ Site acessível em shopvivaliz.com.br  
✅ HTTPS ativo (certificado válido)  
✅ Checkout funcional (teste e produção)  
✅ Pagamentos processando  
✅ Marketplaces sincronizando  
✅ Agentes rodando 24/7  
✅ Uptime > 99.8%  
✅ Performance < 2s page load  

---

**Last Updated:** 01/07/2026 15:32 UTC  
**Status:** 🔄 IN PROGRESS  
**Next Review:** After production deployment
