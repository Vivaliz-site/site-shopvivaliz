# 🚀 Setup MedusaJS no Servidor HostGator

## Pré-requisitos

- SSH access ao servidor HostGator
- Node.js 20+ (será instalado)
- npm/yarn (incluído com Node.js)
- Git (geralmente já está instalado)

## Passo 1: Instalação de Node.js no Servidor

### Conectar via SSH
```bash
ssh seu_usuario@seu_dominio.com.br
```

### Instalar Node.js (nvm recomendado)
```bash
# Baixar nvm
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.0/install.sh | bash

# Ativar nvm
export NVM_DIR="$HOME/.nvm"
source "$NVM_DIR/nvm.sh"

# Instalar Node.js 20
nvm install 20
nvm use 20

# Verificar
node --version  # v20.x.x
npm --version   # 10.x.x
```

## Passo 2: Setup do MedusaJS Backend

```bash
# Navegar para a pasta
cd /home/seu_usuario/public_html/claude/medusa/backend

# Criar projeto Medusa (sem git pois já temos)
npx create-medusa-app@latest . --skip-git

# Instalar dependências
npm install

# Configurar banco de dados PostgreSQL
# Editar .env com credenciais HostGator
```

## Passo 3: Configurar Variáveis de Ambiente

### Criar arquivo `.env`
```bash
cd /home/seu_usuario/public_html/claude/medusa/backend
cat > .env << 'EOF'
# Database
DATABASE_URL=postgresql://usuario:senha@localhost:5432/shopvivaliz_medusa
DATABASE_LOGGING=false

# Backend
MEDUSA_BACKEND_URL=https://seu_dominio.com.br/claude/medusa/backend
MEDUSA_ADMIN_BACKEND_URL=https://seu_dominio.com.br/claude/medusa/backend

# Admin
ADMIN_CORS=https://seu_dominio.com.br
STORE_CORS=https://seu_dominio.com.br

# Auth
JWT_SECRET=sua_chave_secreta_aqui
COOKIE_SECRET=outra_chave_secreta_aqui

# Marketplace APIs
SHOPEE_API_KEY=sua_chave_shopee
SHOPEE_API_SECRET=seu_secret_shopee
AMAZON_ACCESS_KEY=sua_chave_amazon
AMAZON_SECRET_KEY=seu_secret_amazon
OLIST_CLIENT_ID=seu_client_id_olist
OLIST_CLIENT_SECRET=seu_secret_olist

# EHA Integration
EHA_WEBHOOK_SECRET=eha_webhook_secret
MEDUSA_WEBHOOK_URL=https://seu_dominio.com.br/claude/api/medusa-webhook
EOF
```

## Passo 4: Setup do Banco de Dados PostgreSQL

### No HostGator (via cPanel)
1. Vá para cPanel → Databases → PostgreSQL
2. Crie um novo banco de dados
3. Crie um novo usuário PostgreSQL
4. Associe o usuário ao banco de dados com todas as permissões

### Ou via SSH
```bash
# Criar banco de dados
createdb shopvivaliz_medusa

# Criar usuário
createuser medusa_user

# Dar permissões
psql -c "ALTER USER medusa_user WITH PASSWORD 'sua_senha_aqui';"
psql -c "GRANT ALL PRIVILEGES ON DATABASE shopvivaliz_medusa TO medusa_user;"
```

## Passo 5: Rodando o MedusaJS

### Desenvolvimento
```bash
cd /home/seu_usuario/public_html/claude/medusa/backend

# Rodar migrations
npm run migrate

# Seed data inicial
npm run seed

# Iniciar servidor
npm run start
```

### Produção (PM2 recomendado)
```bash
# Instalar PM2 globalmente
npm install -g pm2

# Criar script de inicialização
cat > ecosystem.config.js << 'EOF'
module.exports = {
  apps: [{
    name: "shopvivaliz-medusa",
    script: "dist/index.js",
    env: {
      NODE_ENV: "production"
    },
    instances: 2,
    exec_mode: "cluster",
    watch: false,
    max_memory_restart: "500M"
  }]
};
EOF

# Iniciar com PM2
pm2 start ecosystem.config.js
pm2 startup
pm2 save
```

## Passo 6: Setup do Proxy Reverso (Nginx/Apache)

### Para Apache (HostGator usa isso)

Criar arquivo `.htaccess` em `/public_html/claude/medusa/backend/`:

```apache
<IfModule mod_rewrite.c>
  RewriteEngine On
  
  # Proxy para Node.js (porta 3000)
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule ^(.*)$ http://localhost:3000/$1 [P,QSA,L]
  
  # Preservar headers
  RequestHeader set X-Forwarded-For "%{REMOTE_ADDR}s"
  RequestHeader set X-Forwarded-Proto "https"
</IfModule>
```

### Configurar porta Node.js
```bash
# No .env
MEDUSA_BACKEND_PORT=3000

# Ou via comando
npm run start -- --port 3000
```

## Passo 7: Testar o Setup

```bash
# Verificar se está rodando
curl http://localhost:3000/health

# Acessar Admin
# https://seu_dominio.com.br/claude/medusa/backend/admin

# Criar admin user (primeira vez)
npm run seed
```

## Passo 8: Integração com EHA

### Configurar Webhooks no Medusa
```bash
# Webhook para novos produtos
POST /admin/webhooks
{
  "event": "product.created",
  "url": "https://seu_dominio.com.br/claude/api/medusa-webhook",
  "secret": "eha_webhook_secret"
}

# Webhook para pedidos
POST /admin/webhooks
{
  "event": "order.placed",
  "url": "https://seu_dominio.com.br/claude/api/medusa-webhook",
  "secret": "eha_webhook_secret"
}
```

### Criar endpoint webhook em `/claude/api/medusa-webhook.php`
```php
<?php
// Receber eventos do Medusa
$event = json_decode(file_get_contents('php://input'), true);

// Validar secret
$secret = $_ENV['EHA_WEBHOOK_SECRET'];
$hash = hash_hmac('sha256', json_encode($event), $secret);

if ($hash !== ($_SERVER['HTTP_X_MEDUSA_SIGNATURE'] ?? '')) {
    http_response_code(401);
    exit;
}

// Processar evento
switch ($event['type']) {
    case 'product.created':
        // EHA: Sincronizar com marketplaces
        break;
    case 'order.placed':
        // EHA: Notificar vendedor
        break;
}

http_response_code(200);
echo json_encode(['ok' => true]);
?>
```

## Passo 9: Monitorar com EHA

No dashboard (`/claude/dashboard/`), adicionar health check:
```php
$checks['medusa'] = [
    'url' => 'https://seu_dominio.com.br/claude/medusa/backend/health',
    'timeout' => 10
];
```

## Checklist de Deploy

- [ ] Node.js 20+ instalado
- [ ] PostgreSQL configurado
- [ ] MedusaJS instalado
- [ ] Variáveis de ambiente configuradas
- [ ] Banco de dados migrado
- [ ] PM2 configurado (produção)
- [ ] Proxy reverso ativo
- [ ] Webhooks configurados
- [ ] EHA integrando eventos
- [ ] Testes end-to-end passando

## Troubleshooting

### Porta já em uso
```bash
# Matar processo na porta 3000
lsof -ti:3000 | xargs kill -9

# Ou usar porta diferente
MEDUSA_BACKEND_PORT=3001 npm run start
```

### Permissão negada
```bash
# Verificar permissões
ls -la /home/seu_usuario/public_html/claude/medusa/backend/

# Corrigir se necessário
chmod -R 755 /home/seu_usuario/public_html/claude/medusa/backend/
chmod -R 755 /home/seu_usuario/public_html/claude/medusa/backend/uploads
```

### Banco de dados não conecta
```bash
# Verificar credenciais
psql -h localhost -U medusa_user -d shopvivaliz_medusa

# Ou testar via PHP
php -r "echo pg_connect('host=localhost user=medusa_user password=senha dbname=shopvivaliz_medusa') ? 'OK' : 'FAIL';"
```

## Próximas Etapas

1. ✅ Setup Node.js e MedusaJS
2. ⏳ Setup Next.js Storefront
3. ⏳ Integrar com APIs PHP existentes
4. ⏳ Sincronizar produtos com marketplaces
5. ⏳ Testes e validação

