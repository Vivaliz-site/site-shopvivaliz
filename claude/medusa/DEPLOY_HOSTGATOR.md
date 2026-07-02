# Deploy em produção — HostGator + Medusa (ShopVivaliz)

## Contexto importante

O plano de hospedagem compartilhada HostGator usado hoje pelo site PHP
(`/claude/*.php`) **não roda Node.js persistente nem PostgreSQL** — só PHP via
CGI/FastCGI e MySQL. O backend MedusaJS (Node.js) e o storefront Next.js
**não cabem** nesse plano compartilhado. Duas opções:

1. **HostGator VPS/Cloud** (planos com acesso root/SSH) — suporta Node.js e
   PM2 normalmente. Os pré-requisitos abaixo assumem esse cenário.
2. **Host alternativo para o Node** (Railway, Render, Fly.io, VPS de outro
   provedor) mantendo o HostGator compartilhado só para o PHP legado. Esta é
   a recomendação em `claude/medusa/DEPLOY-CHECKLIST.md` por ser mais barata
   e simples de manter.

Se a decisão for usar HostGator VPS, siga os pré-requisitos abaixo.

## Pré-requisitos no servidor (HostGator VPS/Cloud)

- [ ] Node.js 18+ instalado (`node -v`) — HostGator VPS geralmente vem com
      cPanel "Setup Node.js App" ou permite instalar via NVM
- [ ] PM2 instalado globalmente (`npm install -g pm2`)
- [ ] Banco PostgreSQL **externo** (Supabase, Neon, Railway) — o HostGator
      não oferece Postgres gerenciado; ver seção 1 de `DEPLOY-CHECKLIST.md`
- [ ] Redis externo (opcional; Upstash tem tier gratuito) — sem `REDIS_URL`
      o Medusa usa event bus/locking em memória (não recomendado em produção
      com mais de uma instância, mas funcional para um único processo)
- [ ] SSL configurado (Let's Encrypt via `certbot` ou o "AutoSSL" do cPanel)
      para o domínio do backend/admin e do storefront
- [ ] Portas 9000 (backend) e 8000 (storefront) liberadas no firewall, ou
      um reverse proxy (Nginx/Apache) roteando `:443` para elas
- [ ] Variáveis de ambiente de produção configuradas (ver
      `apps/backend/.env.example` e `GITHUB_SECRETS_TODO.md`)
- [ ] DNS do domínio (ex. `api.shopvivaliz.com.br`, `shopvivaliz.com.br`)
      apontando para o IP do VPS

## Passo a passo

```bash
# 1. Clonar/atualizar o repositório no servidor
git clone <repo> shopvivaliz && cd shopvivaliz/claude/medusa
# ou: git pull origin main

# 2. Configurar .env de produção (NUNCA reaproveitar segredos de dev)
cp apps/backend/.env.example apps/backend/.env
# editar DATABASE_URL, JWT_SECRET, COOKIE_SECRET (openssl rand -base64 32),
# STORE_CORS/ADMIN_CORS/AUTH_CORS com os domínios reais, chaves Stripe/PayPal

# 3. Deploy (usa PM2 - ver deploy.sh)
./deploy.sh all

# 4. Configurar Nginx/Apache como reverse proxy com SSL apontando para
#    localhost:9000 (backend/admin) e localhost:8000 (storefront)

# 5. Criar usuário admin (uma vez)
cd apps/backend && npx medusa user -e admin@shopvivaliz.com.br -p "SENHA_FORTE"
```

## Nginx (exemplo de reverse proxy com SSL)

```nginx
server {
  server_name api.shopvivaliz.com.br;
  location / {
    proxy_pass http://127.0.0.1:9000;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
  }
  listen 443 ssl; # certbot preenche ssl_certificate/ssl_certificate_key
}

server {
  server_name shopvivaliz.com.br www.shopvivaliz.com.br;
  location / {
    proxy_pass http://127.0.0.1:8000;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
  }
  listen 443 ssl;
}
```

Ver `DEPLOY_CHECKLIST.md` para a lista de verificação completa antes de ir ao ar.
