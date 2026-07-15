# 🗄️ Setup Banco de Dados - PostgreSQL

PostgreSQL é obrigatório para Medusa. Escolha uma opção abaixo:

## Opção 1️⃣: PostgreSQL Local (Windows)

### Passo 1: Download

1. Ir para: https://www.postgresql.org/download/windows/
2. Clicar em "Download the installer"
3. Escolher versão **14.x** ou **15.x**
4. Baixar .exe

### Passo 2: Instalar

1. Executar o .exe
2. Escolher caminho padrão
3. **IMPORTANTE:** Lembrar a senha do usuário `postgres`
   - Exemplo: `postgres123`
4. Porta padrão: **5432**
5. Locale: Portuguese (Brazil)
6. Finalizar

### Passo 3: Verificar

Abrir PowerShell e rodar:

```powershell
psql --version
# Resposta: psql (PostgreSQL) 14.x
```

Se não funcionar, adicionar ao PATH:
```powershell
$env:Path += ";C:\Program Files\PostgreSQL\14\bin"
psql --version
```

### Passo 4: Criar Banco

```powershell
psql -U postgres

# No prompt SQL, digitar:
CREATE DATABASE shopvivaliz_medusa;
CREATE USER medusa WITH PASSWORD 'password';
ALTER ROLE medusa SET client_encoding TO 'utf8';
ALTER ROLE medusa SET default_transaction_isolation TO 'read committed';
GRANT ALL PRIVILEGES ON DATABASE shopvivaliz_medusa TO medusa;
\q
```

### Passo 5: Testar

```powershell
psql -h localhost -U medusa -d shopvivaliz_medusa

# Digitar senha: password
# Se conectar, sair com \q
```

### Connection String

```
postgresql://medusa:password@localhost:5432/shopvivaliz_medusa
```

---

## Opção 2️⃣: PostgreSQL via Instalador (Mais Fácil)

Se preferir um setup visual, use:

**pgAdmin 4** (Interface gráfica)
- Download: https://www.pgadmin.org/download/pgadmin-4-windows/
- Instalar e executar
- Conectar ao servidor local
- Criar banco via GUI

---

## Opção 3️⃣: PostgreSQL em Nuvem (Sem Instalar)

Se não quiser instalar nada:

### ElephantSQL (Grátis até 20GB)

1. Ir para: https://www.elephantsql.com/
2. Clicar "Sign up"
3. Criar conta (usar Google)
4. Create new instance
5. Plan: **Tiny Turtle (Free)**
6. Region: **AWS / us-east-1**
7. Create
8. Copiar a URL de conexão

Exemplo:
```
postgresql://user:pass@host:5432/database
```

### Supabase (Grátis, melhor interface)

1. Ir para: https://supabase.com/
2. Sign up (GitHub ou Google)
3. New project
4. Region: São Paulo (sp-1) ou us-east-1
5. Conexão em Settings → Database

URL de conexão:
```
postgresql://postgres:password@host:5432/postgres
```

### Vercel/Serverless (Pago)

Se tiver budget, usar:
- AWS RDS (1 ano free tier)
- Google Cloud SQL (free tier)
- Azure Database (free tier)

---

## Como Usar em Medusa

Depois que tiver conexão, atualizar `.env`:

```bash
# LOCAL
DATABASE_URL=postgresql://medusa:password@localhost:5432/shopvivaliz_medusa

# OU NUVEM
DATABASE_URL=postgresql://user:pass@host.com:5432/database
```

Depois rodar:

```bash
cd apps/backend

npm run migrate
npm run seed
npm run dev
```

---

## ✅ Verificar se Funciona

```bash
# No backend
npm run migrate

# Resposta esperada:
# ✓ Migrations completed successfully

# Ver dados no banco
npm run seed

# Resposta esperada:
# ✓ Seeded data successfully
```

---

## 🆘 Problemas Comuns

### "psql: command not found"

PostgreSQL não está no PATH. Adicionar manualmente:

```powershell
$env:Path += ";C:\Program Files\PostgreSQL\14\bin"
psql --version
```

Ou colocar permanentemente em Windows:
1. Abrir "Environment Variables"
2. Adicionar: `C:\Program Files\PostgreSQL\14\bin`

### "password authentication failed"

Verificar:
- Senha correta no .env
- Usuário correto (postgres ou medusa)
- Banco correto (shopvivaliz_medusa)

### "could not connect to server"

PostgreSQL não está rodando:
- Windows: Services → postgresql → Start
- Docker: `docker start shopvivaliz-db`
- Nuvem: Verificar se servidor está online

### "database does not exist"

Criar banco:

```sql
CREATE DATABASE shopvivaliz_medusa;
```

---

## 📋 Checklist

- [ ] PostgreSQL instalado ou acessível
- [ ] Banco `shopvivaliz_medusa` criado
- [ ] Usuário `medusa` criado com password
- [ ] Connection string funciona: `psql -U medusa -d shopvivaliz_medusa`
- [ ] `.env` atualizado com DATABASE_URL correta
- [ ] `npm run migrate` rodou com sucesso
- [ ] `npm run seed` criou dados iniciais
- [ ] Pronto para rodar `npm run dev`

---

## Próximo Passo

Depois de confirmar banco funcionando:

```bash
cd claude/medusa/apps/backend

npm run dev

# Aguardar mensagem:
# ✓ Server listening on port 9000
```

Depois entrar em: http://localhost:9000/admin
