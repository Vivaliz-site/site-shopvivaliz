# 🚀 Quick Start - ShopVivaliz Medusa

## Pré-requisitos Instalados

✅ Node.js 24.18.0
✅ npm 11.16.0
✅ Monorepo instalado em `claude/medusa/`
✅ Webhook listener criado em `claude/api/medusa-webhook.php`

## Falta Instalar

⏳ PostgreSQL (banco de dados)
⏳ Configurar banco de dados
⏳ Rodar migrations
⏳ Testar localmente

## 1️⃣ PostgreSQL Setup

### No Windows com PostgreSQL já instalado:

```powershell
# Conectar com psql
psql -U postgres

# SQL para criar banco e usuário:
CREATE DATABASE shopvivaliz_medusa;
CREATE USER medusa WITH PASSWORD 'password';
ALTER ROLE medusa SET client_encoding TO 'utf8';
ALTER ROLE medusa SET default_transaction_isolation TO 'read committed';
ALTER ROLE medusa SET default_transaction_deferrable TO on;
ALTER ROLE medusa SET default_statistics_target TO 100;
GRANT ALL PRIVILEGES ON DATABASE shopvivaliz_medusa TO medusa;
\q
```

### Testar conexão:

```powershell
psql -h localhost -U medusa -d shopvivaliz_medusa
# Digitar senha: password
# Se conectar OK, sair com \q
```

## 2️⃣ Rodar Backend Localmente

### Terminal 1: Iniciar Medusa Backend

```powershell
cd c:\Users\user\site-shopvivaliz\claude\medusa\apps\backend

# Rodar migrations (primeira vez)
npm run migrate

# Seed dados iniciais (primeira vez)
npm run seed

# Iniciar servidor de desenvolvimento
npm run dev
```

Aguardar até ver:
```
✓ Server listening on port 9000
✓ Admin available at http://localhost:9000/admin
```

### Acessar Admin:

1. Abrir browser: http://localhost:9000/admin
2. Login com credenciais do seed (check console)
3. Ir para Products
4. Clicar "Create Product"
5. Preencher título e descrição
6. Clicar Save

Quando salvar, Medusa dispara webhook para:
`POST http://localhost/claude/api/medusa-webhook.php`

### Verificar webhook recebido:

```powershell
# Em outro terminal:
tail -f c:\Users\user\site-shopvivaliz\claude\logs\webhook-events.log
```

## 3️⃣ Rodar Storefront (Next.js)

### Terminal 2: Iniciar Storefront

```powershell
cd c:\Users\user\site-shopvivaliz\claude\medusa\apps\storefront

npm run dev
```

Aguardar até ver:
```
✓ Ready in Xs
○ Localhost:3000
```

### Acessar Storefront:

1. Abrir browser: http://localhost:3000
2. Ver produtos criados no admin
3. Testar adicionar ao carrinho
4. Testar checkout

## 4️⃣ Rodar Ambos em Paralelo

Se preferir rodar tudo de uma vez:

```powershell
cd c:\Users\user\site-shopvivaliz\claude\medusa

# Com pnpm (se tiver instalado)
pnpm dev

# Ou manualmente em dois terminais conforme acima
```

## 📊 Dashboard EHA

Monitorar em: `http://localhost/claude/dashboard/`

Mostra:
- ✅ Eventos recebidos de webhooks
- ✅ Produtos sincronizados
- ✅ Erros de integração
- ✅ Performance

## 🔍 Testando Fluxo Completo

### 1. Backend rodando?

```powershell
curl http://localhost:9000/health
# Resposta: {"status":"ok"}
```

### 2. Criar produto via API:

```powershell
# 1. Login
$loginResponse = curl -X POST http://localhost:9000/admin/auth/login `
  -H "Content-Type: application/json" `
  -d '{"email":"admin@example.com","password":"password"}' | ConvertFrom-Json

$token = $loginResponse.access_token

# 2. Criar produto
curl -X POST http://localhost:9000/admin/products `
  -H "Authorization: Bearer $token" `
  -H "Content-Type: application/json" `
  -d '{
    "title":"Test Product",
    "description":"Test Description",
    "handle":"test-product",
    "is_giftcard":false
  }'
```

### 3. Webhook foi recebido?

```powershell
Get-Content c:\Users\user\site-shopvivaliz\claude\logs\webhook-events.log -Tail 1
# Ver JSON do último evento
```

### 4. Storefront mostra produto?

```
http://localhost:3000/catalog
```

## 🐛 Troubleshooting

### "Port 9000 already in use"
```powershell
# Matar processo
Get-Process | Where-Object {$_.name -like "*node*"} | Stop-Process -Force
# Ou mudar porta em .env
```

### "Database connection refused"
```powershell
# Verificar PostgreSQL está rodando
Get-Service postgresql-x64-* | Start-Service

# Testar conexão
psql -h localhost -U medusa -d shopvivaliz_medusa
```

### "Webhook not received"
- Verificar arquivo `/claude/api/medusa-webhook.php` existe
- Verificar secret em `.env` é igual em ambos lados
- Verificar logs: `tail -f /claude/logs/webhook-events.log`
- Ver console do backend para erros

### "Storefront mostra erro"
- Verificar backend está rodando em 9000
- Verificar .env do storefront tem URL correta
- Ver console do browser (F12)

## 📝 Próximas Etapas

1. ✅ Medusa monorepo instalado
2. ✅ Webhook listener criado
3. ⏳ PostgreSQL local configurado
4. ⏳ Rodar backend e testar
5. ⏳ Rodar storefront e testar
6. ⏳ Testar webhook criando produto
7. ⏳ Deploy para HostGator (produção)

## 📖 Documentação

- `MONOREPO_SETUP.md` — Estrutura e scripts
- `INTEGRACAO_EHA.md` — Webhooks e EHA
- `SETUP_SERVIDOR.md` — Deployment HostGator
- `COMO_AJUDAR_IA.md` — Feedback e feedback rápido

## 🎯 Meta

Entregar projeto funcionando com:
- ✅ Catálogo de produtos
- ✅ Carrinho de compras
- ✅ Checkout
- ✅ EHA validando e sincronizando 24/7
- ✅ Deploy automático
