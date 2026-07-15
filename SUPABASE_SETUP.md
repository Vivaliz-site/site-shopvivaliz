# ⚡ Setup Supabase em 5 Minutos

## Passo 1: Criar Conta
https://supabase.com/auth/signup

Usar: GitHub ou Google (mais rápido)

## Passo 2: Criar Projeto
1. Clicar "New project"
2. Name: `shopvivaliz-medusa`
3. Password: `supabase123456` (salvar)
4. Region: **São Paulo (sp-1)**
5. Clicar "Create new project"
6. **Aguardar 2-3 minutos** (banco sendo criado)

## Passo 3: Obter Connection String
1. Projeto criado → Settings (engrenagem)
2. Database
3. Connection Strings → URI
4. Copiar a string completa

Exemplo:
```
postgresql://postgres:supabase123456@db.xxxxxxxxxx.supabase.co:5432/postgres
```

**⚠️ IMPORTANTE:** Substituir `[YOUR-PASSWORD]` pela senha que você criou

## Passo 4: Colar no Backend

Abra: `claude/medusa/apps/backend/.env`

Procure por esta linha:
```
DATABASE_URL=postgresql://medusa:medusa123@localhost:5432/shopvivaliz_medusa
```

Substitua por:
```
DATABASE_URL=postgresql://postgres:supabase123456@db.xxxxxxxxxx.supabase.co:5432/postgres
```

## Pronto!

Depois de colar, o projeto pode rodar:
```bash
cd claude/medusa/apps/backend
npm run migrate
npm run seed
npm run dev
```

---

## Alternativa: PostgreSQL Local (se preferir)

Se já tiver PostgreSQL instalado:

```bash
psql -U postgres

CREATE DATABASE shopvivaliz_medusa;
CREATE USER medusa PASSWORD 'medusa123';
GRANT ALL PRIVILEGES ON DATABASE shopvivaliz_medusa TO medusa;
\q
```

Depois usar:
```
DATABASE_URL=postgresql://medusa:medusa123@localhost:5432/shopvivaliz_medusa
```

---

**Tempo total:** ~5 minutos para Supabase pronto ⏱️
