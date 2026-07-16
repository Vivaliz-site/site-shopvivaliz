# ⚡ Setup Rápido (5 minutos)

## Opção 1: Supabase (Recomendado - Mais Rápido)

### Passo 1: Criar conta Supabase
1. Ir para: https://supabase.com
2. Clicar "Sign up"
3. Usar GitHub ou Google
4. Confirmar email

### Passo 2: Criar projeto
1. Clicar "New project"
2. Nome: `shopvivaliz-medusa`
3. Senha: `medusa123456` (salvar)
4. Region: **São Paulo (sp-1)** ou US East
5. Clicar "Create new project"
6. Aguardar 2-3 minutos

### Passo 3: Copiar Connection String
1. Ir para: Settings → Database → Connection Strings
2. Copiar URL em "URI" (a com `postgresql://`)
3. Substitua `[YOUR-PASSWORD]` pela senha criada

Exemplo:
```
postgresql://postgres:medusa123456@db.xxxxx.supabase.co:5432/postgres
```

### Passo 4: Colar no Backend
```bash
cd claude/medusa/apps/backend

# Editar .env
# Trocar DATABASE_URL pela URL do Supabase
```

---

## Opção 2: PostgreSQL Local (Alternativa)

Se quiser instalar localmente no Windows:

1. Download: https://www.postgresql.org/download/windows/
2. Executar installer
3. Lembrar senha do `postgres`
4. Próximo, próximo, finalizar
5. Abrir PowerShell:

```powershell
psql -U postgres

# No prompt SQL:
CREATE DATABASE shopvivaliz_medusa;
CREATE USER medusa WITH PASSWORD 'medusa123';
GRANT ALL PRIVILEGES ON DATABASE shopvivaliz_medusa TO medusa;
\q
```

Connection String:
```
postgresql://medusa:medusa123@localhost:5432/shopvivaliz_medusa
```

---

## Opção 3: Usar Banco Existente (Se tiver)

Se houver banco MySQL ou PostgreSQL já criado, use a connection string dele no `.env`

---

## Próximos Passos Após Banco Configurado

### 1. Atualizar .env

```bash
cd claude/medusa/apps/backend

# Abrir .env e trocar:
DATABASE_URL=postgresql://... [cole aqui]
```

### 2. Rodar Migrações

```bash
npm run migrate
npm run seed
```

Resposta esperada:
```
✓ Migrations completed
✓ Seeded data successfully
```

### 3. Iniciar Backend

```bash
npm run dev
```

Resposta esperada:
```
✓ Server listening on port 9000
```

Acesse admin: http://localhost:9000/admin

### 4. Em outro terminal - Iniciar Storefront

```bash
cd claude/medusa/apps/storefront
npm run dev
```

Resposta esperada:
```
✓ Ready in XXms
○ Localhost:3000
```

---

## Login no Admin

Após rodar `npm run seed`, credenciais:
- Email: `admin@medusajs.com`
- Password: `supersecret`

---

## Checklist

- [ ] Banco de dados criado (Supabase ou local)
- [ ] Connection string em `.env`
- [ ] `npm run migrate` rodou OK
- [ ] `npm run seed` rodou OK
- [ ] Backend rodando em 9000
- [ ] Storefront rodando em 3000
- [ ] Login no admin funcionando
- [ ] Consegue criar produto
- [ ] Consegue adicionar ao carrinho
- [ ] Consegue fazer checkout

---

## Tempo Estimado

- Supabase: **5 min**
- PostgreSQL local: **15 min**
- Setup Medusa: **5 min**
- Testes: **10 min**

**Total: ~20 minutos** para site completo funcionando

---

## Problemas Comuns

### "Database connection refused"
- Supabase: Aguarde 2-3 minutos para ativar
- Local: Verificar se PostgreSQL está rodando

### "Migrations failed"
- Certifique-se `.env` tem `DATABASE_URL` correto
- Limpar `node_modules` e reinstalar: `npm install`

### "Port 9000 already in use"
```powershell
# Matar processo
Get-Process | Where-Object {$_.name -eq "node"} | Stop-Process -Force
```

### Esqueceu senha do Supabase
- Criar novo projeto (leva 2 min)
- Ou clicar "Reset password" em Settings

---

## Suporte

Ver documentação em:
- `claude/medusa/QUICK_START.md`
- `claude/medusa/MONOREPO_SETUP.md`
- `claude/medusa/SETUP_BANCO_DADOS.md`
