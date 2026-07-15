# Workspace ShopVivaliz - Fase 1

## Objetivo

Organizar o projeto para entregas rapidas com GitHub, Codex, agentes, QA e releases cumulativos.

## Frentes implantadas

- Repositorio privado `fredmourao-ai/site-shopvivaliz`.
- `AGENTS.md` com regras operacionais.
- `.codex/config.toml` com contexto do projeto.
- Agentes Codex para Release, QA, Olist/Tiny e Frete/Checkout.
- Workflow GitHub Actions para PHP lint e varredura basica de segredos.

## Proximas rotinas obrigatorias

- Atualizador deve executar migrations e pos-update automaticamente.
- Sincronizacao Olist deve detectar pagina repetida e falhar quando `after_count` ficar travado.
- Self-test deve validar botao Comprar, CEP, frete, checkout, webhooks e OAuth.
- Nenhum segredo real deve ser commitado.

## Ambientes

- Dev: `https://dev.shopvivaliz.com.br`
- Repo: `fredmourao-ai/site-shopvivaliz`
