# Agente executor de atualizacao - ShopVivaliz

## Objetivo

Executar atualizacoes cumulativas do ShopVivaliz de forma controlada, validando aplicacao, migrations, pos-update, self-test e monitor externo.

## Agente

`.codex/agents/shopvivaliz-update-executor.toml`

## Workflow

`.github/workflows/execute-update.yml`

## Script

`tools/execute-update.php`

## Secrets necessarios no GitHub

Configure em `Settings > Secrets and variables > Actions`:

- `SHOPVIVALIZ_UPDATE_URL`
- `SHOPVIVALIZ_UPDATE_TOKEN`

Valor padrao recomendado para `SHOPVIVALIZ_UPDATE_URL`:

```txt
https://dev.shopvivaliz.com.br/installer/update.php
```

Nao commitar tokens reais.

## Fluxo automatico

1. Recebe ZIP cumulativo.
2. Envia ZIP para `/installer/update.php`.
3. Chama `?finalize=1` quando necessario.
4. Consulta `/installer/update-applied-check.php`.
5. Consulta `/installer/self-test.php`.
6. Consulta `/installer/auto-routines.php?expected=200&limit=50`.
7. Falha se a versao aplicada nao bater com a esperada.
8. Salva relatorio JSON.

## Regra critica

O agente nunca deve esconder falha parcial. Se a atualizacao aplicar arquivos mas falhar em migration, self-test, auto-routines ou versao esperada, o resultado deve ser `ok=false`.
