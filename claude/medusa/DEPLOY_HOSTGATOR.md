# 🚀 Deploy para HostGator

## Pré-requisitos

✅ Código testado localmente
✅ Medusa backend funcionando em localhost:9000
✅ Storefront funcionando em localhost:3000
✅ Webhooks testados
✅ SSH acesso ao HostGator

## 1️⃣ Preparar Produção

### No seu computador:

```powershell
cd claude/medusa

# Build backend
cd apps/backend
npm run build

# Build storefront
cd ../storefront
npm run build

cd ../..
```

### Confirmar builds criados:

```powershell
# Backend
ls apps/backend/dist

# Storefront
ls apps/storefront/.next
```

## 2️⃣ Conectar ao HostGator via SSH

```bash
ssh seu_usuario@seu_dominio.com.br
# ou
ssh seu_usuario@ip_do_servidor
```

## 3️⃣ Setup Node.js no Servidor

### Instalar Node.js 20+ (se não tiver):

```bash
# Instalar nvm
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.0/install.sh | bash

# Ativar nvm
export NVM_DIR="$HOME/.nvm"
source "$NVM_DIR/nvm.sh"

# Instalar Node.js
nvm install 20
nvm use 20

# Verificar
node --version
npm --version
```

## 4️⃣ Setup PostgreSQL

### Verificar se PostgreSQL está instalado:

```bash
psql --version
```

### Se não tiver, instalar:

```bash
# CentOS/RHEL
sudo yum install postgresql-server postgresql-contrib -y
sudo systemctl start postgresql

# Ubuntu/Debian
sudo apt-get install postgresql postgresql-contrib -y
sudo systemctl start postgresql
```

### Criar banco de dados:

```bash
sudo -u postgres psql

CREATE DATABASE shopvivaliz_medusa;
CREATE USER medusa WITH PASSWORD 'sua_senha_segura';
ALTER ROLE medusa SET client_encoding TO 'utf8';
GRANT ALL PRIVILEGES ON DATABASE shopvivaliz_medusa TO medusa;
\q
```

### Testar:

```bash
psql -h localhost -U medusa -d shopvivaliz_medusa
# Digitar senha
# Se conectar, sair com \q
```

## 5️⃣ Fazer Deploy do Código

### Clone ou pull no servidor:

```bash
cd /home/seu_usuario/public_html/

# Se não tem repo:
git clone https://github.com/fredmourao-ai/site-shopvivaliz.git .

# Se já tem:
git pull origin main
```

### Navegar para medusa:

```bash
cd claude/medusa
```

### Instalar dependências:

```bash
# npm (mais comum)
npm install --production

# Ou pnpm
pnpm install --prod
```

## 6️⃣ Configurar Variáveis de Ambiente

### Backend (.env):

```bash
cd apps/backend

cat > .env << 'EOF'
# Database
DATABASE_URL=postgresql://medusa:sua_senha_segura@localhost:5432/shopvivaliz_medusa
NODE_ENV=production

# Backend
MEDUSA_BACKEND_URL=https://dev.shopvivaliz.com.br/claude/medusa/backend

# Admin
ADMIN_CORS=https://dev.shopvivaliz.com.br
STORE_CORS=https://dev.shopvivaliz.com.br

# Auth
JWT_SECRET=gerar_com_openssl_rand_base64_32
COOKIE_SECRET=gerar_com_openssl_rand_base64_32

# Marketplace APIs
SHOPEE_API_KEY=suas_chaves_aqui
SHOPEE_API_SECRET=
AMAZON_ACCESS_KEY=
AMAZON_SECRET_KEY=
OLIST_CLIENT_ID=
OLIST_CLIENT_SECRET=

# EHA Webhooks
EHA_WEBHOOK_SECRET=gerar_com_openssl_rand_base64_32
MEDUSA_WEBHOOK_URL=https://dev.shopvivaliz.com.br/claude/api/medusa-webhook.php

# Logging
LOG_LEVEL=info
EOF
```

### Gerar secrets seguros:

```bash
openssl rand -base64 32
# Repetir 3x para JWT_SECRET, COOKIE_SECRET, EHA_WEBHOOK_SECRET
```

### Storefront (.env.local):

```bash
cd ../storefront

cat > .env.local << 'EOF'
NEXT_PUBLIC_MEDUSA_BACKEND_URL=https://dev.shopvivaliz.com.br/claude/medusa/backend
NEXT_PUBLIC_STORE_URL=https://dev.shopvivaliz.com.br
EOF
```

## 7️⃣ Rodar Migrations

```bash
cd apps/backend

npm run migrate
npm run seed
```

## 8️⃣ Setup PM2 (Process Manager)

### Instalar PM2:

```bash
npm install -g pm2
```

### Criar arquivo de configuração:

```bash
cd /home/seu_usuario/public_html/claude/medusa

cat > ecosystem.config.js << 'EOF'
module.exports = {
  apps: [
    {
      name: 'shopvivaliz-medusa-backend',
      script: 'apps/backend/dist/index.js',
      instances: 2,
      exec_mode: 'cluster',
      env: {
        NODE_ENV: 'production'
      },
      error_file: './logs/pm2-error.log',
      out_file: './logs/pm2-out.log',
      log_date_format: 'YYYY-MM-DD HH:mm:ss Z'
    },
    {
      name: 'shopvivaliz-storefront',
      script: 'apps/storefront/.next/standalone/server.js',
      instances: 1,
      env: {
        NODE_ENV: 'production'
      },
      error_file: './logs/pm2-error.log',
      out_file: './logs/pm2-out.log'
    }
  ]
};
EOF
```

### Iniciar com PM2:

```bash
pm2 start ecosystem.config.js

# Ver status
pm2 status

# Ver logs
pm2 logs

# Salvar para autostart
pm2 startup
pm2 save
```

## 9️⃣ Configurar Reverse Proxy (Apache)

### Backend (.htaccess):

```bash
cat > apps/backend/.htaccess << 'EOF'
<IfModule mod_rewrite.c>
  RewriteEngine On
  
  # Proxy para Node.js (porta 3001)
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule ^(.*)$ http://localhost:3001/$1 [P,QSA,L]
  
  # Headers
  RequestHeader set X-Forwarded-For "%{REMOTE_ADDR}s"
  RequestHeader set X-Forwarded-Proto "https"
</IfModule>
```

### Storefront (.htaccess):

```bash
cat > apps/storefront/.htaccess << 'EOF'
<IfModule mod_rewrite.c>
  RewriteEngine On
  
  # Proxy para Node.js (porta 3002)
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule ^(.*)$ http://localhost:3002/$1 [P,QSA,L]
  
  RequestHeader set X-Forwarded-For "%{REMOTE_ADDR}s"
  RequestHeader set X-Forwarded-Proto "https"
</IfModule>
```

## 🔟 Configurar Webhook do Medusa

### Registrar webhooks (via admin):

1. Acesso: https://dev.shopvivaliz.com.br/claude/medusa/backend/admin
2. Ir para Settings → Webhooks
3. Criar novo webhook:

```
Event: product.created
URL: https://dev.shopvivaliz.com.br/claude/api/medusa-webhook.php
Secret: [mesma do EHA_WEBHOOK_SECRET no .env]
```

4. Repetir para: product.updated, order.created

## 1️⃣1️⃣ SSL/HTTPS (via cPanel)

1. Abrir cPanel
2. Ir para: AutoSSL
3. Clicar: Install
4. Aguardar certificado ser instalado

## 1️⃣2️⃣ Testar Produção

### Verificar backend:

```bash
curl https://dev.shopvivaliz.com.br/claude/medusa/backend/health
# Resposta: {"status":"ok"}
```

### Verificar storefront:

```bash
curl https://dev.shopvivaliz.com.br/claude/medusa/storefront
```

### Verificar webhook:

```bash
curl -X POST https://dev.shopvivaliz.com.br/claude/api/medusa-webhook.php \
  -H "X-Medusa-Signature: test" \
  -d '{}'
# Deve retornar 401 (Unauthorized - expected)
```

## 🔍 Monitorar Logs

```bash
# Logs PM2
pm2 logs

# Logs webhook
tail -f /home/user/public_html/claude/logs/webhook-events.log

# Logs database
tail -f /var/log/postgresql/postgresql.log
```

## ✅ Checklist Final

- [ ] Node.js 20+ instalado no servidor
- [ ] PostgreSQL configurado
- [ ] Banco de dados criado e testado
- [ ] Código deployado
- [ ] .env configurado em produção
- [ ] Migrations rodadas
- [ ] PM2 iniciado
- [ ] Apache reverse proxy ativo
- [ ] SSL/HTTPS ativo
- [ ] Webhooks registrados
- [ ] Backend respondendo em 9000
- [ ] Storefront respondendo em 3000
- [ ] Frontend acessível via domínio
- [ ] Webhook testado

## 🚀 Deployment Automático (GitHub Actions)

Quando você fizer push para `main`, o workflow automático:

1. Roda testes
2. Faz build
3. Deploy via FTP para HostGator
4. Reinicia PM2

Ver: `.github/workflows/deploy.yml`

## 🆘 Suporte

Se algo falhar:
1. Ver logs: `pm2 logs`
2. Testar webhook: `curl https://...`
3. Verificar banco: `psql -U medusa -d shopvivaliz_medusa`
4. Checar ports: `netstat -tlnp | grep 3001`
