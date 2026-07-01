# ShopVivaliz MedusaJS - Estrutura Base

## 📦 Estrutura do Projeto

```
/claude/medusa/
├── backend/              # Backend MedusaJS (Node.js + Express)
│   ├── src/
│   ├── package.json
│   └── .env.example
│
└── storefront/           # Frontend Next.js
    ├── app/
    ├── package.json
    └── .env.example
```

## 🚀 Instalação do Backend

```bash
cd claude/medusa/backend

# Criar projeto MedusaJS
npx create-medusa-app@latest . --skip-git

# Instalar dependências
npm install

# Configurar banco de dados
npm run seed

# Iniciar servidor
npm run start
```

Backend estará em: `http://localhost:9000`
Admin em: `http://localhost:9000/admin`

## 🎨 Instalação do Storefront

```bash
cd claude/medusa/storefront

# Criar projeto Next.js com template Medusa
npx create-next-app@latest . --example medusa

# Instalar dependências
npm install

# Iniciar servidor
npm run dev
```

Storefront estará em: `http://localhost:8000`

## 🔗 Integração com EHA

- O `/claude/` continua com:
  - Homepage (`index.php`)
  - Dashboard (`dashboard/index.php`)
  - Catálogo (`catalogo/`)
  - Carrinho (`carrinho/`)
  - Checkout (`checkout/`)

- MedusaJS fornece:
  - API REST para produtos
  - Admin para gerenciar catálogo
  - Integração com marketplaces

## 📝 Próximos Passos

1. ✅ Setup do MedusaJS Backend
2. ✅ Setup do Next.js Storefront
3. ⏳ Integrar API do Medusa com `/claude/catalogo/`
4. ⏳ Migrar produtos para Medusa
5. ⏳ Conectar EHA com webhooks do Medusa
