# Google Ads Remote Review Handoff

Data: 2026-07-19

## Objetivo

Revisar a campanha ativa de Google Ads da ShopVivaliz sem alterar nada.

## Contexto

Neste PC, o Chrome conecta ao Codex, mas o Browser Use bloqueia `https://ads.google.com`
por politica do controlador. Nao tentar contornar por Edge, Computer Use, CDP ou outra
superficie alternativa nesta mesma sessao.

## O que coletar no PC que acessa Google Ads

- Nome da campanha ativa.
- Status da campanha.
- Orcamento diario.
- Estrategia de lance.
- Redes/canais habilitados.
- Localizacoes segmentadas.
- Idioma.
- Produtos/landing pages/final URLs.
- Palavras-chave principais e tipos de correspondencia.
- Palavras-chave negativas.
- Anuncios RSA: headlines e descriptions com limites.
- Conversoes configuradas e origem do evento.
- Ultimos dados visiveis: custo, impressoes, cliques, CTR, CPC medio, conversoes, valor de conversao.
- Alertas/recomendacoes sem salvar alteracoes.

## Regras

- Somente leitura.
- Nao alterar orcamento.
- Nao ativar, pausar ou excluir campanha.
- Nao salvar mudancas em anuncios, keywords, conversoes ou configuracoes.

## Caminho alternativo ja preparado neste repo

Quando houver credenciais reais no `.env`, rodar:

```powershell
python scripts\google_ads_review_campaigns.py
```

O MCP local `google-ads-readonly` tambem ja foi registrado em `.mcp.json`.
