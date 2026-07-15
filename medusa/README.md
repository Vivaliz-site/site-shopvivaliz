# MedusaJS Workspace

Espaço reservado para a nova base de e-commerce do ShopVivaliz.

## Objetivo

Centralizar o backend headless da loja com:

- catálogo
- produtos
- estoque
- pedidos
- canais de venda
- integrações com ERP e marketplaces

## Stack alvo

- MedusaJS
- PostgreSQL
- Redis
- Next.js Commerce

## Status

Inicialização planejada.

Enquanto a migração acontece, o site atual permanece como camada legada e continuará operando.

## Setup local esperado

Depois do scaffold oficial, o fluxo desejado será:

```bash
cd medusa
npm install
npm run dev
```

Se o scaffold completo for gerado dentro de `medusa/`, a estrutura deve seguir o padrão:

- `apps/backend`
- `apps/storefront`
- `package.json` na raiz do workspace

